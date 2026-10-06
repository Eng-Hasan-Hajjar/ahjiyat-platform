<?php

namespace App\Services\Competitive\Rewards;

use App\Events\CompetitiveRewardGranted;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Competitive\CompetitiveException;
use App\Services\Economy\CurrencyWalletService;
use App\Services\OperationalAuditService;
use App\Services\Progression\XpService;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * توزيع جوائز الأحداث التنافسية (E18-B). المصدر الوحيد: حدث **معتمَد نهائيًا** + مراكزه النهائية (final_rank، تُكتب مرة واحدة بالمنفِّذ ولا تتغير). الجائزة لا
 * تحدد الفائز ولا تغيّر نقاطًا أو مراكز: تقرأ فقط. لا شيء من العميل: لا rank ولا reward ولا amount.
 *
 * - مؤهَّل = نتيجة **صحيحة** (is_correct) بمركز نهائي. قاعدة مركز تحوي مركزه، وإلا قاعدة المشاركة إن وُجدت. جائزة واحدة لكل لاعب (UNIQUE بالقاعدة).
 * - لا منطق اقتصادي هنا: العملة بـCurrencyWalletService::creditPending (معلّقة بفترة الحجز المعتادة، بمفتاح دلالي)، XP بـXpService::grantXp (بمفتاح دلالي)،
 *   العنصر بـInventoryService::grant، الامتياز بـEntitlementService::grant. لا increment/decrement ولا تعديل رصيد مباشر. المرجع = الحدث (وسم المصدر).
 * - الذرية: ادّعاء الصف بـUPDATE شرطي (status='pending') داخل معاملة واحدة مع التسليم: عاملان على الصف نفسه ← واحد فقط ينجح ولو بقراءة قديمة؛
 *   وفشل التسليم يتراجع بالكامل (فلا نصف منحة) ثم يُسجَّل failed بدل أن يُسقط الحدث. العنصر/الامتياز غير Idempotent بنفسيهما، فحمايتهما هذه المعاملة.
 * - الدفعات: لا تحميل لكل المشاركين؛ كل دفعة ≤ chunk_size ومؤشرها معرّف النتيجة. لا معاملة ضخمة واحدة.
 * - إعادة المحاولة صريحة (الفاشل فقط، بسلطة وحماية معدّل)؛ الممنوح لا يُعاد منحه أبدًا.
 */
class CompetitiveRewardDistributionService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected XpService $xp,
        protected InventoryService $inventory,
        protected EntitlementService $entitlements,
        protected OperationalAuditService $audit,
    ) {}

    public function chunkSize(): int
    {
        return max(1, (int) config('competitive.rewards.chunk_size', 100));
    }

    /** معتمَد نهائيًا فقط: الملغاة والجارية وبلا اعتماد لا توزَّع. */
    public function isDistributable(CompetitiveEvent $event): bool
    {
        return $event->status === CompetitiveEvent::STATUS_COMPLETED && $event->finalized_at !== null;
    }

    /** @return Collection<int, CompetitiveRewardRule> */
    public function activeRules(CompetitiveEvent $event): Collection
    {
        return CompetitiveRewardRule::query()->where('competitive_event_id', $event->getKey())->where('is_active', true)
            ->with(['currency', 'storeItem'])->orderBy('sort_order')->orderBy('min_rank')->get();
    }

    /** القاعدة المنطبقة على نتيجة: مركز أولًا ثم المشاركة. نتيجة غير صحيحة أو بلا مركز نهائي: لا قاعدة. */
    public function ruleFor(Collection $rules, CompetitiveEventResult $result): ?CompetitiveRewardRule
    {
        if (! $result->is_correct || $result->final_rank === null) {
            return null;
        }

        return $rules->first(fn (CompetitiveRewardRule $r) => $r->appliesTo($result->final_rank))
            ?? $rules->firstWhere('kind', CompetitiveRewardRule::KIND_PARTICIPATION);
    }

    /**
     * يعالج دفعة واحدة من النتائج الصحيحة بعد المؤشر.
     *
     * @return array{count: int, last_id: int, done: bool, granted: int, failed: int}
     */
    public function distributeChunk(CompetitiveEvent $event, int $afterResultId = 0, ?int $size = null): array
    {
        $size ??= $this->chunkSize();
        $stats = ['count' => 0, 'last_id' => $afterResultId, 'done' => true, 'granted' => 0, 'failed' => 0];

        if (! $this->isDistributable($event) || ($rules = $this->activeRules($event))->isEmpty()) {
            return $stats;
        }

        $results = CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())
            ->where('is_correct', true)->whereNotNull('final_rank')->where('id', '>', $afterResultId)->orderBy('id')->limit($size)->get();

        foreach ($results as $result) {
            if (($rule = $this->ruleFor($rules, $result)) === null) {
                continue;
            }

            $grant = $this->plan($event, $result, $rule);

            if ($grant->status === CompetitiveRewardGrant::STATUS_PENDING) {
                match ($this->process($grant)) {
                    'granted' => $stats['granted']++,
                    'failed' => $stats['failed']++,
                    default => null,
                };
            }
        }

        $stats['count'] = $results->count();
        $stats['last_id'] = (int) ($results->last()?->getKey() ?? $afterResultId);
        $stats['done'] = $results->count() < $size;

        return $stats;
    }

    /** يخطّط الصف (pending) بلقطة الجائزة؛ insertOrIgnore على UNIQUE(event, user): لا ازدواج ولا تأثير لتكرار التشغيل. */
    public function plan(CompetitiveEvent $event, CompetitiveEventResult $result, CompetitiveRewardRule $rule): CompetitiveRewardGrant
    {
        DB::table('competitive_reward_grants')->insertOrIgnore([[
            'competitive_event_id' => $event->getKey(),
            'user_id' => $result->user_id,
            'competitive_event_result_id' => $result->getKey(),
            'competitive_reward_rule_id' => $rule->getKey(),
            'status' => CompetitiveRewardGrant::STATUS_PENDING,
            'final_rank' => $result->final_rank,
            'reward_type' => $rule->reward_type,
            'currency_id' => $rule->currency_id,
            'store_item_id' => $rule->store_item_id,
            'amount' => $rule->amount,
            'reward_label' => Str::limit($rule->rewardLabel(), 150, ''),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        return CompetitiveRewardGrant::query()->where('competitive_event_id', $event->getKey())->where('user_id', $result->user_id)->firstOrFail();
    }

    /** @return 'granted'|'skipped'|'failed' */
    public function process(CompetitiveRewardGrant $grant): string
    {
        try {
            $claimed = DB::transaction(function () use ($grant) {
                // ادّعاء ذري: الوحيد الذي ينتقل pending → granted ينفّذ التسليم (عامل آخر أو صف ممنوح: affected = 0).
                $affected = CompetitiveRewardGrant::query()->whereKey($grant->getKey())->where('status', CompetitiveRewardGrant::STATUS_PENDING)
                    ->update(['status' => CompetitiveRewardGrant::STATUS_GRANTED, 'granted_at' => now(), 'failure_reason' => null, 'attempts' => DB::raw('attempts + 1')]);

                if ($affected !== 1) {
                    return false;
                }

                $this->deliver($grant->fresh());

                return true;
            });
        } catch (\Throwable $e) {
            // تراجعت المعاملة (لا نصف منحة): نسجّل الفشل خارجها بسبب مقتضب، فيبقى الحدث وبقية اللاعبين سليمين وقابلين لإعادة المحاولة.
            CompetitiveRewardGrant::query()->whereKey($grant->getKey())->where('status', CompetitiveRewardGrant::STATUS_PENDING)
                ->update(['status' => CompetitiveRewardGrant::STATUS_FAILED, 'failure_reason' => Str::limit($e->getMessage(), 240, ''), 'attempts' => DB::raw('attempts + 1')]);
            report($e);

            return 'failed';
        }

        if (! $claimed) {
            return 'skipped';
        }

        try {
            event(new CompetitiveRewardGranted($grant->getKey())); // بعد اكتمال المنح فقط؛ فشل الإشعار لا يمسّ الجائزة
        } catch (\Throwable $e) {
            report($e);
        }

        return 'granted';
    }

    /** التسليم عبر الخدمات الرسمية فقط. أي نقص بالتعريف يرمي استثناء (لا نجاح كاذب). */
    protected function deliver(CompetitiveRewardGrant $grant): void
    {
        $event = CompetitiveEvent::query()->findOrFail($grant->competitive_event_id);
        $user = User::query()->findOrFail($grant->user_id);
        $key = "competitive-reward:{$grant->competitive_event_id}:{$grant->user_id}";
        $reason = 'competitive_event_reward';

        match ($grant->reward_type) {
            CompetitiveRewardRule::TYPE_CURRENCY => $this->wallets->creditPending($user, Currency::query()->findOrFail($grant->currency_id), $grant->amount, $reason, $event, $key),
            CompetitiveRewardRule::TYPE_XP => $this->xp->grantXp($user, $grant->amount, XpTransaction::TYPE_COMPETITIVE_REWARD, $reason, $event, $key),
            CompetitiveRewardRule::TYPE_STORE_ITEM => $this->deliverItem($user, StoreItem::query()->findOrFail($grant->store_item_id), $grant->amount, $reason, $event),
            default => throw new \RuntimeException('نوع جائزة غير مدعوم.'),
        };
    }

    protected function deliverItem(User $user, StoreItem $item, int $quantity, string $reason, CompetitiveEvent $event): void
    {
        match ($item->fulfillment_type) {
            StoreItem::FULFILLMENT_INVENTORY => $this->inventory->grant($user, $item, $quantity, $reason, $event),
            StoreItem::FULFILLMENT_ENTITLEMENT => $this->entitlements->grant($user, $item),
            default => throw new \RuntimeException('لا يمكن منح عنصر يدوي التسليم كجائزة تلقائية.'),
        };
    }

    /** @return array{eligible: int, pending: int, granted: int, failed: int} */
    public function counts(CompetitiveEvent $event): array
    {
        $by = CompetitiveRewardGrant::query()->where('competitive_event_id', $event->getKey())->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'eligible' => $this->eligibleCount($event),
            'pending' => (int) ($by[CompetitiveRewardGrant::STATUS_PENDING] ?? 0),
            'granted' => (int) ($by[CompetitiveRewardGrant::STATUS_GRANTED] ?? 0),
            'failed' => (int) ($by[CompetitiveRewardGrant::STATUS_FAILED] ?? 0),
        ];
    }

    /** عدد اللاعبين المؤهَّلين حسب القواعد الفعّالة (نطاقات المراكز المنفصلة + المشاركة لمن خارجها). لا توزيع هنا. */
    public function eligibleCount(CompetitiveEvent $event): int
    {
        $rules = $this->activeRules($event);

        if ($rules->isEmpty() || $event->status === CompetitiveEvent::STATUS_CANCELLED) {
            return 0;
        }

        $correct = fn () => CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())->where('is_correct', true)->whereNotNull('final_rank');
        $ranked = $rules->where('kind', CompetitiveRewardRule::KIND_RANK)->sum(fn ($r) => $correct()->whereBetween('final_rank', [$r->min_rank, $r->max_rank])->count());

        return $ranked + ($rules->contains('kind', CompetitiveRewardRule::KIND_PARTICIPATION) ? $correct()->count() - $ranked : 0);
    }

    /**
     * إعادة محاولة الفاشل فقط (إجراء صريح بتفويض وحماية معدّل). الممنوح لا يُمسّ. تدقيق واحد للعملية (لا تكرار لقيود الدفتر).
     *
     * @return array{requested: int, granted: int, failed: int}
     */
    public function retryFailed(CompetitiveEvent $event, User $actor): array
    {
        abort_unless($actor->can('retryRewards', $event), 403);

        $limiter = "competitive-rewards-retry:{$event->getKey()}";

        if (RateLimiter::tooManyAttempts($limiter, (int) config('competitive.rewards.retry_per_10_minutes', 5))) {
            throw new CompetitiveException('محاولات إعادة كثيرة. حاول بعد قليل.');
        }

        RateLimiter::hit($limiter, 600);

        $stats = ['requested' => 0, 'granted' => 0, 'failed' => 0];

        CompetitiveRewardGrant::query()->where('competitive_event_id', $event->getKey())->where('status', CompetitiveRewardGrant::STATUS_FAILED)
            ->chunkById($this->chunkSize(), function ($grants) use (&$stats) {
                foreach ($grants as $grant) {
                    $reset = CompetitiveRewardGrant::query()->whereKey($grant->getKey())->where('status', CompetitiveRewardGrant::STATUS_FAILED)
                        ->update(['status' => CompetitiveRewardGrant::STATUS_PENDING]);

                    if ($reset !== 1) {
                        continue;
                    }

                    $stats['requested']++;

                    match ($this->process($grant->fresh())) {
                        'granted' => $stats['granted']++,
                        'failed' => $stats['failed']++,
                        default => null,
                    };
                }
            });

        $this->audit->log('competitive_rewards_retry', $event, $stats, $actor);

        return $stats;
    }
}

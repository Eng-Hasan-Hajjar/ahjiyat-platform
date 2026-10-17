<?php

namespace App\Services\Sponsorship;

use App\GameEngine\Support\AttemptContext;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipRewardBonus;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Facades\DB;

/**
 * E30 - **البوابة المركزية الوحيدة** لتعزيز المكافآت الممولة بالرعاية. لا مضاعف رعاية في أي متحكم أو نموذج أو خدمة أخرى: PuzzleAttemptService وCompetitiveRewardDistributionService
 * يستدعيان هذه الخدمة فقط (نقطتان). الصيغة (أعداد صحيحة، لا floats):
 *
 *     steps     = floor(confirmed_funding / funding_step)
 *     boost_bps = min(steps × boost_per_step_bps, max_boost_bps, hard_max_bps)        (100 bps = 1%)
 *     bonus     = floor(base × boost_bps / 10000)
 *
 * قواعد صلبة:
 *  - **محتوى رسمي فقط**: أحجية مجتمع (origin=community)، وسلاسل المبدعين، ومسارات المجتمع لا تمرّ هنا أصلًا (CommunityPlayService لا يلمس الاقتصاد)، وهذه الخدمة ترفض أحجية المجتمع صراحة.
 *  - الأساس سليم: المكافأة الأساسية تُمنح كما هي بمنطقها القائم؛ المكافأة الإضافية معاملة **منفصلة** بدفتر المحفظة (CurrencyWalletService::creditPending، معلّقة بفترة الحجز المعتادة)، لا تعديل رصيد مباشر.
 *  - الجواهر فقط (العملة المكتسبة الافتراضية). XP والمهام والسلسلة والمستويات لا تُعزَّز.
 *  - منصة/موسم/حملة: التمويل المؤكَّد لحظة المنح (حيّ من قاعدة البيانات، لا كاش)، ولمنح مستقبلية فقط؛ لا أثر رجعي لتمويل أو استرداد لاحق على ما مُنح.
 *    سقف الكسب اليومي يُحترم (المكافأة تُقصّ إلى المتبقي)، وتقييم الاحتيال يعمل بعد الإيداع كالمعتاد.
 *  - المنافسات: لقطة مقفلة قبل البدء (EventRewardPlanService) ⇒ نسبة ثابتة؛ بلا لقطة = بلا تعزيز (فشل مغلق). المكافأة الإضافية تتبع أصلها (لا سقف يومي، فالجائزة الأساسية نفسها خارجه).
 *  - الدعم لا يمنح الداعم أي أفضلية لعب أو مكافأة شخصية: الحساب من تمويل البرنامج لا من هوية اللاعب.
 *  - أي فشل هنا يُلتقط ويُبلَّغ (report) ويُعاد 0: لا يتعطل حل الأحجية ولا توزيع الجوائز بسبب الرعاية. المنح idempotent بمفتاح مصدر فريد.
 */
class SponsorshipRewardService
{
    public function __construct(protected CurrencyWalletService $wallets, protected CurrencyRegistry $currencies) {}

    public function enabled(): bool
    {
        return (bool) config('sponsorship.enabled', true) && (bool) config('sponsorship.reward.enabled', true);
    }

    /** نقاط التعزيز الأساسية لتمويل وخطة معطاة. صفر حين تنقص الخطة أو لا تمويل. */
    public static function computeBps(int $funding, int $step, int $perStep, int $max): int
    {
        if ($funding <= 0 || $step <= 0 || $perStep <= 0 || $max <= 0) {
            return 0;
        }

        $hard = (int) config('sponsorship.reward.hard_max_bps', 5000);

        return max(0, min(intdiv($funding, $step) * $perStep, $max, $hard));
    }

    public static function bonusFor(int $base, int $bps): int
    {
        return $base <= 0 || $bps <= 0 ? 0 : intdiv($base * $bps, (int) config('sponsorship.reward.bps_denominator', 10000));
    }

    /**
     * أعلى تعزيز منطبق على حلّ أحجية رسمية بسياق معيّن (بلا تراكم بين البرامج: الأعلى فقط، وعند التساوي الأصغر معرّفًا). null = لا تعزيز.
     * السياقات التنافسية (تحدي صديق/منافسة/فريق) وأي سياق مجهول ⇒ null (فشل مغلق).
     *
     * @return array{program_id: int, bps: int, funding: int}|null
     */
    public function resolveForSolve(AttemptContext $context): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $campaignId = null;

        if ($context->type === AttemptContext::TYPE_CAMPAIGN_STEP && $context->id !== null) {
            $campaignId = DB::table('campaign_steps as st')
                ->join('campaign_gates as g', 'g.id', '=', 'st.campaign_gate_id')->join('campaign_stages as sg', 'sg.id', '=', 'g.campaign_stage_id')
                ->where('st.id', $context->id)->value('sg.campaign_id');

            if ($campaignId === null) {
                return null;
            }
        } elseif ($context->isPresent()) {
            return null;
        }

        $programs = DB::table('sponsorship_programs as p')
            ->where('p.status', SponsorshipProgram::STATUS_ACTIVE)->where('p.reward_boost_enabled', true)->where('p.funding_step_minor', '>', 0)
            ->where(fn ($q) => $q->whereNull('p.starts_at')->orWhere('p.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('p.ends_at')->orWhere('p.ends_at', '>=', now()))
            ->where(function ($w) use ($campaignId) {
                $w->where('p.target_type', SponsorshipProgram::TARGET_PLATFORM);

                if ($campaignId !== null) {
                    $w->orWhere(fn ($c) => $c->where('p.target_type', SponsorshipProgram::TARGET_CAMPAIGN)->where('p.target_id', $campaignId))
                        ->orWhere(fn ($s) => $s->where('p.target_type', SponsorshipProgram::TARGET_SEASON)
                            ->whereIn('p.target_id', fn ($q) => $q->select('id')->from('seasons')->where('campaign_id', $campaignId)->where('is_published', true)));
                }
            })
            ->select('p.id', 'p.funding_step_minor', 'p.boost_per_step_bps', 'p.max_boost_bps')
            ->selectSub(fn ($q) => $q->from('sponsorship_contributions as c')->whereColumn('c.sponsorship_program_id', 'p.id')
                ->where('c.status', 'confirmed')->selectRaw('COALESCE(SUM(c.accounting_amount_minor), 0)'), 'funding')
            ->orderBy('p.id')->get();

        $best = null;

        foreach ($programs as $program) {
            $bps = self::computeBps((int) $program->funding, (int) $program->funding_step_minor, (int) $program->boost_per_step_bps, (int) $program->max_boost_bps);

            if ($bps > 0 && ($best === null || $bps > $best['bps'])) {
                $best = ['program_id' => (int) $program->id, 'bps' => $bps, 'funding' => (int) $program->funding];
            }
        }

        return $best;
    }

    /**
     * مكافأة إضافية لحلّ أحجية رسمية. تُستدعى من PuzzleAttemptService داخل معاملة المحاولة بعد منح الأساس، والمحفظة مقفلة أصلًا.
     * تعيد ما مُنح فعلًا (0 غالبًا). لا ترمي أبدًا.
     */
    public function applyToSolve(User $user, Puzzle $puzzle, AttemptContext $context, Currency $currency, int $baseReward, int $attemptId): int
    {
        try {
            if ($baseReward <= 0 || $attemptId <= 0 || $puzzle->isCommunity() || ! $currency->is($this->currencies->defaultEarnedCurrency())) {
                return 0;
            }

            $boost = $this->resolveForSolve($context);

            if ($boost === null) {
                return 0;
            }

            $calculated = self::bonusFor($baseReward, $boost['bps']);
            $allowance = max(0, (int) config('gems.daily_earn_cap') - $this->wallets->dailyEarnedToday($user, $currency));
            $bonus = min($calculated, $allowance);

            if ($bonus <= 0) {
                return 0;
            }

            return $this->credit($user, $currency, $boost['program_id'], 'solve:'.$attemptId, $context->type, $context->id, $baseReward, $boost['bps'], $calculated, $bonus, $boost['funding']);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * لقطة تعزيز جائزة منافسة عند التخطيط (بعد اعتماد المنافسة): من الخطة المقفلة قبل البدء حصرًا. جوائز العملة بالجواهر الافتراضية فقط.
     *
     * @return array{sponsor_program_id: ?int, sponsor_bonus_bps: int, sponsor_bonus_amount: int}
     */
    public function competitiveSnapshot(CompetitiveEvent $event, CompetitiveRewardRule $rule): array
    {
        $none = ['sponsor_program_id' => null, 'sponsor_bonus_bps' => 0, 'sponsor_bonus_amount' => 0];

        try {
            if (! $this->enabled() || $rule->reward_type !== CompetitiveRewardRule::TYPE_CURRENCY || (int) $rule->currency_id !== (int) $this->currencies->defaultEarnedCurrency()->id) {
                return $none;
            }

            $plan = SponsorshipEventRewardPlan::query()->where('competitive_event_id', $event->getKey())->first();

            if ($plan === null || $plan->sponsorship_program_id === null || (int) $plan->boost_bps <= 0) {
                return $none;
            }

            $bonus = self::bonusFor((int) $rule->amount, (int) $plan->boost_bps);

            return $bonus > 0 ? ['sponsor_program_id' => (int) $plan->sponsorship_program_id, 'sponsor_bonus_bps' => (int) $plan->boost_bps, 'sponsor_bonus_amount' => $bonus] : $none;
        } catch (\Throwable $e) {
            report($e);

            return $none;
        }
    }

    /**
     * يمنح المكافأة الإضافية المخزَّنة بلقطة الجائزة (معاملة منفصلة بمفتاح فريد). يُستدعى من deliver() بعد منح الأساس؛ فشله معزول بنقطة حفظ ولا يُبطل الأساس.
     */
    public function grantCompetitiveBonus(CompetitiveRewardGrant $grant, User $user, Currency $currency): int
    {
        try {
            $bonus = (int) $grant->sponsor_bonus_amount;

            if ($bonus <= 0 || $grant->sponsor_program_id === null || (int) $grant->sponsor_bonus_bps <= 0 || ! $this->enabled()) {
                return 0;
            }

            return $this->credit($user, $currency, (int) $grant->sponsor_program_id, 'competitive:'.$grant->competitive_event_id.':'.$grant->user_id,
                AttemptContext::TYPE_COMPETITIVE_EVENT, (int) $grant->competitive_event_id, (int) $grant->amount, (int) $grant->sponsor_bonus_bps, $bonus, $bonus,
                (int) SponsorshipEventRewardPlan::query()->where('competitive_event_id', $grant->competitive_event_id)->value('funding_snapshot_minor'));
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** إيداع المكافأة + سجلّها في معاملة (نقطة حفظ عند التداخل) بمفتاح مصدر فريد: إعادة الاستدعاء لا تمنح ثانية. */
    protected function credit(User $user, Currency $currency, int $programId, string $sourceKey, ?string $contextType, ?int $contextId, int $base, int $bps, int $calculated, int $bonus, int $funding): int
    {
        $key = 'sponsor-bonus:'.$sourceKey;

        return DB::transaction(function () use ($user, $currency, $programId, $key, $contextType, $contextId, $base, $bps, $calculated, $bonus, $funding) {
            $existing = SponsorshipRewardBonus::query()->where('source_key', $key)->first();

            if ($existing !== null) {
                return 0;
            }

            $program = SponsorshipProgram::query()->findOrFail($programId);
            $tx = $this->wallets->creditPending($user, $currency, $bonus, 'sponsor_bonus:'.$programId, $program, $key);

            $row = new SponsorshipRewardBonus;
            $row->forceFill([
                'source_key' => $key,
                'sponsorship_program_id' => $programId,
                'user_id' => $user->id,
                'context_type' => $contextType,
                'context_id' => $contextId,
                'currency_id' => $currency->id,
                'base_amount' => $base,
                'boost_bps' => $bps,
                'calculated_bonus_amount' => $calculated,
                'bonus_amount' => $bonus,
                'final_amount' => $base + $bonus,
                'funding_snapshot_minor' => $funding,
                'currency_transaction_id' => $tx->id,
            ])->save();

            return $bonus;
        });
    }
}

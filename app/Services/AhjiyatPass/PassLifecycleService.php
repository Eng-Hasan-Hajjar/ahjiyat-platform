<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Services\AhjiyatPass\Concerns\AuthorizesPass;
use App\Services\OperationalAuditService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E40 - دورة حياة المسار (السلطة الوحيدة): جدولة/نشر، إلغاء الجدولة، إنهاء مبكر، أرشفة، مزامنة الحالة المخزَّنة مع التواريخ، والبحث عن «المسار الحالي».
 *
 * الحقيقة للتواريخ: effectiveStatus() يشتقّ الحالة من الفترة نصف المفتوحة [starts_at, ends_at)؛ المخزَّن يُزامنه sync() (الأمر المجدول) وهذه العمليات.
 * مسار فعّال واحد: يُفرض بمدقّق التداخل عند النشر + unique(live_slot) في القاعدة. المسار مستقل تمامًا عن الموسم.
 * بعد النشر تُقفل الحقول الحرجة (AhjiyatPass::LOCKED_FIELDS) والشرائح والمهام والمكافآت. لا حذف لمسار نُشر.
 */
class PassLifecycleService
{
    use AuthorizesPass;

    public function __construct(
        protected OperationalAuditService $audit,
        protected PassDefinitionCache $cache,
        protected PassReadinessChecker $readiness,
    ) {}

    public function current(): ?AhjiyatPass
    {
        return (bool) config('ahjiyat_pass.enabled', true) ? $this->cache->current() : null;
    }

    /** مسار مرئي للاعبين بمعرّفه العام (فعّال/منتهٍ/مؤرشف). المسودة والمجدول = null. */
    public function findVisible(string $publicId): ?AhjiyatPass
    {
        $pass = AhjiyatPass::query()->where('public_id', $publicId)->whereNotNull('locked_at')->first();

        return $pass !== null && $pass->isPubliclyVisible() ? $pass : null;
    }

    /** المسارات التي ما زالت نافذة استلامها مفتوحة (الفعّال أولًا ثم الأحدث انتهاءً). @return Collection<int, AhjiyatPass> */
    public function claimWindowPasses(): Collection
    {
        return AhjiyatPass::query()
            ->whereNotNull('locked_at')
            ->whereIn('status', [AhjiyatPass::STATUS_SCHEDULED, AhjiyatPass::STATUS_ACTIVE, AhjiyatPass::STATUS_ENDED])
            ->where('starts_at', '<=', now())
            ->where('claim_ends_at', '>', now())
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * ينشر المسودة (جدولة): يتحقق من الجاهزية كاملة ثم يقفل ويحدد الحالة المجدولة؛ يُفعَّل فورًا إن كان وقت بدايته قد حلّ.
     *
     * @return list<string> لا شيء (للتوافق) — يرمي PassException عند عدم الجاهزية مع قائمة details.
     */
    public function schedule(User $actor, AhjiyatPass $pass): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.publish');

        try {
            $published = Cache::lock('ahjiyat_pass:publish', 15)->block(5, function () use ($actor, $pass) {
                return DB::transaction(function () use ($actor, $pass) {
                    $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

                    if ($locked->status !== AhjiyatPass::STATUS_DRAFT) {
                        throw new PassException('not_draft', 'يمكن نشر المسودة فقط.');
                    }

                    $problems = $this->readiness->problems($locked);

                    if ($problems !== []) {
                        throw new PassException('not_ready', 'المسار غير جاهز للنشر: '.$problems[0], $problems);
                    }

                    $tiers = (int) DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $locked->getKey())->count();

                    $locked->forceFill([
                        'status' => AhjiyatPass::STATUS_SCHEDULED,
                        'locked_at' => now(),
                        'published_at' => now(),
                        'published_by' => $actor->getKey(),
                        'total_tiers' => $tiers,
                    ])->save();

                    $this->audit->log('ahjiyat_pass_scheduled', $locked, ['public_id' => $locked->public_id, 'starts_at' => $locked->starts_at?->toIso8601String(), 'ends_at' => $locked->ends_at?->toIso8601String(), 'tiers' => $tiers], $actor);

                    return $locked;
                });
            });
        } catch (LockTimeoutException) {
            throw new PassException('busy', 'عملية نشر أخرى قيد التنفيذ، أعد المحاولة.');
        }

        $this->cache->flush();
        $this->syncOne($published);

        return $published->refresh();
    }

    /** يعيد المجدول (لم يبدأ، بلا أي تقدّم أو شراء) إلى مسودة قابلة للتعديل. */
    public function unschedule(User $actor, AhjiyatPass $pass): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.publish');

        $updated = DB::transaction(function () use ($actor, $pass) {
            $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AhjiyatPass::STATUS_SCHEDULED || now()->gte($locked->starts_at)) {
                throw new PassException('not_unschedulable', 'يمكن إلغاء جدولة مسار مجدول لم يبدأ فقط.');
            }

            if (UserPassProgress::query()->where('ahjiyat_pass_id', $locked->getKey())->exists()
                || DB::table('digital_purchases')->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)->where('product_id', $locked->getKey())->exists()) {
                throw new PassException('has_activity', 'لا يمكن إلغاء الجدولة: يوجد تقدّم لاعبين أو مشتريات.');
            }

            $locked->forceFill(['status' => AhjiyatPass::STATUS_DRAFT, 'locked_at' => null, 'published_at' => null, 'published_by' => null])->save();
            $this->audit->log('ahjiyat_pass_unscheduled', $locked, ['public_id' => $locked->public_id], $actor);

            return $locked;
        });

        $this->cache->flush();

        return $updated;
    }

    /** إنهاء مبكر لمسار فعّال: يتوقف كسب النقاط وبيع المميز فورًا؛ تبدأ مهلة الاستلام من الآن. لا حذف لأي تقدّم أو شراء أو مطالبة. */
    public function end(User $actor, AhjiyatPass $pass, ?string $reason = null): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.publish');

        $updated = DB::transaction(function () use ($actor, $pass, $reason) {
            $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->effectiveStatus() !== AhjiyatPass::STATUS_ACTIVE) {
                throw new PassException('not_active', 'يمكن إنهاء المسار الفعّال فقط.');
            }

            $now = now();

            $locked->unlocking(['ends_at', 'claim_ends_at'], function (AhjiyatPass $p) use ($now): void {
                $p->forceFill([
                    'status' => AhjiyatPass::STATUS_ENDED,
                    'ends_at' => $now,
                    'claim_ends_at' => $now->copy()->addDays((int) $p->claim_grace_days),
                    'ended_at' => $now,
                ])->save();
            });

            $this->audit->log('ahjiyat_pass_ended', $locked, ['public_id' => $locked->public_id, 'reason' => $reason, 'early' => true], $actor);

            return $locked;
        });

        $this->cache->flush();

        return $updated;
    }

    /** يؤرشف مسارًا انتهت مهلة استلامه ولا مشتريات مفتوحة له. */
    public function archive(User $actor, AhjiyatPass $pass): AhjiyatPass
    {
        $this->authorizePass($actor, 'passes.publish');

        $updated = DB::transaction(function () use ($actor, $pass) {
            $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->effectiveStatus() !== AhjiyatPass::STATUS_ENDED) {
                throw new PassException('not_ended', 'يمكن أرشفة المسار المنتهي فقط.');
            }

            if (now()->lt($locked->claim_ends_at)) {
                throw new PassException('claim_window_open', 'مهلة استلام المكافآت ما زالت مفتوحة.');
            }

            $open = DB::table('digital_purchases')
                ->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)->where('product_id', $locked->getKey())
                ->whereIn('status', DigitalPurchase::OPEN_STATUSES)->exists();

            if ($open) {
                throw new PassException('open_purchases', 'توجد طلبات شراء مفتوحة لهذا المسار؛ أنهِ مراجعتها أولًا.');
            }

            // المخزَّن قد يكون ما زال active/scheduled إن لم يعمل المزامنة؛ نُسوّيه عبر ended أولًا.
            if ($locked->status !== AhjiyatPass::STATUS_ENDED) {
                $locked->forceFill(['status' => AhjiyatPass::STATUS_ENDED, 'ended_at' => $locked->ended_at ?? $locked->ends_at])->save();
            }

            $locked->forceFill(['status' => AhjiyatPass::STATUS_ARCHIVED, 'archived_at' => now()])->save();
            $this->audit->log('ahjiyat_pass_archived', $locked, ['public_id' => $locked->public_id], $actor);

            return $locked;
        });

        $this->cache->flush();

        return $updated;
    }

    /** يحذف مسودة لم تُنشر قط (وشرائحها/مهامها/مكافآتها تتتالى). المنشور لا يُحذف أبدًا. */
    public function deleteDraft(User $actor, AhjiyatPass $pass): void
    {
        $this->authorizePass($actor, 'passes.manage');

        DB::transaction(function () use ($actor, $pass): void {
            $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AhjiyatPass::STATUS_DRAFT || $locked->locked_at !== null || $locked->published_at !== null) {
                throw new PassException('not_draft', 'يُحذف مسار المسودة فقط؛ المسار المنشور يُؤرشف.');
            }

            $info = ['public_id' => $locked->public_id, 'name' => $locked->name];
            $banner = $locked->banner_path;

            DB::table('ahjiyat_pass_tier_rewards')->where('ahjiyat_pass_id', $locked->getKey())->delete();
            DB::table('ahjiyat_pass_missions')->where('ahjiyat_pass_id', $locked->getKey())->delete();
            DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $locked->getKey())->delete();
            $locked->delete();

            $this->audit->log('ahjiyat_pass_draft_deleted', null, $info, $actor);

            if (filled($banner)) {
                app(PassMedia::class)->delete($banner);
            }
        });

        $this->cache->flush();
    }

    /**
     * يزامن الحالة المخزَّنة مع التواريخ (للأمر المجدول). الترتيب مهم: الإنهاء قبل التفعيل كي لا يتصادم unique(live_slot).
     *
     * @return array{ended: int, activated: int}
     */
    public function sync(): array
    {
        $ended = 0;
        $activated = 0;

        AhjiyatPass::query()
            ->whereNotNull('locked_at')
            ->whereIn('status', [AhjiyatPass::STATUS_ACTIVE, AhjiyatPass::STATUS_SCHEDULED])
            ->where('ends_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(function (AhjiyatPass $pass) use (&$ended): void {
                $ended += $this->transition($pass, AhjiyatPass::STATUS_ENDED) ? 1 : 0;
            });

        AhjiyatPass::query()
            ->whereNotNull('locked_at')
            ->where('status', AhjiyatPass::STATUS_SCHEDULED)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get()
            ->each(function (AhjiyatPass $pass) use (&$activated): void {
                $activated += $this->transition($pass, AhjiyatPass::STATUS_ACTIVE) ? 1 : 0;
            });

        if ($ended + $activated > 0) {
            $this->cache->flush();
        }

        return ['ended' => $ended, 'activated' => $activated];
    }

    protected function syncOne(AhjiyatPass $pass): void
    {
        $effective = $pass->effectiveStatus();

        if ($effective === AhjiyatPass::STATUS_ACTIVE && $pass->status === AhjiyatPass::STATUS_SCHEDULED) {
            $this->transition($pass, AhjiyatPass::STATUS_ACTIVE);
            $this->cache->flush();
        }
    }

    protected function transition(AhjiyatPass $pass, string $to): bool
    {
        try {
            return DB::transaction(function () use ($pass, $to): bool {
                $locked = AhjiyatPass::query()->whereKey($pass->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status === $to || ! in_array($to, AhjiyatPass::TRANSITIONS[$locked->status] ?? [], true)) {
                    return false;
                }

                if ($to === AhjiyatPass::STATUS_ACTIVE) {
                    // نتأكد أن الوقت ما زال ضمن النافذة (قد يتغيّر بين القراءة والقفل).
                    if (now()->lt($locked->starts_at) || now()->gte($locked->ends_at)) {
                        return false;
                    }

                    $locked->forceFill(['status' => AhjiyatPass::STATUS_ACTIVE])->save();
                } else {
                    $locked->forceFill(['status' => AhjiyatPass::STATUS_ENDED, 'ended_at' => $locked->ended_at ?? $locked->ends_at])->save();
                }

                $this->audit->log('ahjiyat_pass_'.($to === AhjiyatPass::STATUS_ACTIVE ? 'activated' : 'ended'), $locked, ['public_id' => $locked->public_id, 'by' => 'schedule'], null);

                return true;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // مسار آخر ما زال active مخزّنًا (تصادم live_slot): لا نفعّل الآن؛ المزامنة التالية تنهي الأول ثم تُفعّل هذا.
            return false;
        }
    }
}

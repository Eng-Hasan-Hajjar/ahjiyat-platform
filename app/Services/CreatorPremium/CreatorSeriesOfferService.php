<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorMoney;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Creators\CreatorEligibility;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E43 - دورة حياة عرض بيع السلسلة (مسودة ← نشط ⇄ موقوف ← مسحوب) وتغيير السعر المستقبلي. المبدع صاحب السلسلة وحده يتحكم بعرضه؛ الإدارة توقف/تستأنف إداريًا بصلاحية creator-premium.manage.
 *
 *  - العملة **لا تأتي من العميل**: تُشتق من حساب أرباح المبدع (الخيار A) وتُتحقق عند الإنشاء والتفعيل ثم عند الشراء والتحقق. لا تحويل ولا سعر صرف.
 *  - لا يستطيع المبدع تفعيل التسييل لنفسه: الحساب يفعّله مدير مالي (E39). غيابه أو إيقافه ⇒ لا مسودة ولا تفعيل ولا بيع.
 *  - مفتاح الإيقاف CREATOR_PREMIUM_ENABLED: مُعطَّلًا يرفض التفعيل/الاستئناف؛ التعطيل لا يلمس العروض القائمة (يبقى القفل والوصول) ولا يمنع الإيقاف/السحب/تغيير الوصف.
 *  - إيقاف المبدع لا يستأنفه المبدع إن أوقفته الإدارة (pause_reason ≠ creator).
 *  - الأقفال: حساب أرباح المبدع ← صف العرض (ترتيب ثابت، نفسه في الشراء)؛ فلا يتقاطع تفعيل/إيقاف/تغيير سعر مع بدء شراء. السباق على «عرض حيّ واحد» تحسمه القاعدة (unique live_series_id).
 *  - تغيير السعر يسري على المشتريات المستقبلية فقط: لقطة DigitalPurchase والبيع المباشر ثابتان، والمالكون يحتفظون بالوصول.
 *  - كل تغيير يُدقَّق (OperationalAuditService). لا حذف أبدًا.
 */
class CreatorSeriesOfferService
{
    public function __construct(
        protected CreatorPremiumPolicy $policy,
        protected CreatorEligibility $eligibility,
        protected CreatorSeriesVisibilityService $visibility,
        protected OperationalAuditService $audit,
    ) {}

    // ───────────────────────── القراءة ─────────────────────────

    /** العرض الحيّ (مسودة/نشط/موقوف) لسلسلة، أو null. */
    public function liveOffer(CreatorSeries|int $series): ?CreatorSeriesSaleOffer
    {
        return CreatorSeriesSaleOffer::query()->where('live_series_id', $series instanceof CreatorSeries ? $series->getKey() : $series)->first();
    }

    /**
     * سبب عدم قدرة هذا العرض على البيع الآن (بالعربية)، أو null إن كان قابلًا للبيع. مشتق حيًّا من الحالة الراهنة؛ لا يكتب.
     * يغطي: العرض نشط، الميزة مفعَّلة، السلسلة ظاهرة (الإشراف/النشر/عناصرها)، المبدع معتمَد وغير مجمَّد (ضمن الظهور)، التسييل مفعَّل، العملة تطابق الحساب، والسعر صالح.
     */
    public function sellBlocker(CreatorSeriesSaleOffer $offer, ?CreatorEarningAccount $account = null): ?string
    {
        if (! $this->policy->enabled()) {
            return 'سوق السلاسل المميزة غير متاح حاليًا.';
        }

        if (! $offer->isActive()) {
            return $offer->isPaused() ? 'البيع موقوف مؤقتًا لهذه السلسلة.' : 'هذه السلسلة غير معروضة للبيع.';
        }

        $series = $offer->relationLoaded('series') ? $offer->series : CreatorSeries::query()->find($offer->creator_series_id);

        if ($series === null || ! $this->visibility->isVisible($series)) {
            return 'السلسلة غير متاحة حاليًا.';
        }

        $account ??= CreatorEarningAccount::query()->where('creator_profile_id', $offer->creator_profile_id)->first();

        if ($account === null || ! $account->isEnabled()) {
            return 'البيع غير متاح حاليًا لهذا المبدع.';
        }

        if ($account->currency !== $offer->currency) {
            return 'تعذّر إتمام الشراء حاليًا (إعداد العملة). تواصل مع الدعم.';
        }

        if ($this->policy->priceProblem((int) $offer->price_minor) !== null || ! $this->policy->currencyAllowed((string) $offer->currency)) {
            return 'سعر السلسلة غير مضبوط بشكل صحيح. تواصل مع الدعم.';
        }

        return null;
    }

    // ───────────────────────── المبدع ─────────────────────────

    /** @throws CreatorPremiumException */
    public function createDraft(User $user, string $seriesPublicId, string $priceText): CreatorSeriesSaleOffer
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = CreatorSeries::query()->where('creator_profile_id', $profile->id)->where('public_id', $seriesPublicId)->firstOrFail();

        try {
            return DB::transaction(function () use ($profile, $series, $priceText, $user) {
                $account = $this->lockedAccount($profile);
                $series = CreatorSeries::query()->whereKey($series->getKey())->lockForUpdate()->firstOrFail();

                if (! $series->isPublished() || $series->isArchived()) {
                    throw new CreatorPremiumException('series_not_published', 'يمكن تحويل السلاسل المنشورة وغير المؤرشفة فقط إلى سلاسل مميزة.');
                }

                if ($this->liveOffer($series) !== null) {
                    throw new CreatorPremiumException('offer_exists', 'لهذه السلسلة عرض قائم بالفعل؛ عدّله أو اسحبه أولًا.');
                }

                $minor = $this->parsePrice($priceText, $account);

                $offer = new CreatorSeriesSaleOffer;
                $offer->forceFill([
                    'creator_series_id' => $series->getKey(),
                    'creator_profile_id' => $profile->getKey(),
                    'status' => CreatorSeriesSaleOffer::STATUS_DRAFT,
                    'price_minor' => $minor,
                    'currency' => $account->currency,
                ])->save();

                $this->audit->log('creator_premium_offer_created', $offer, ['series' => $series->public_id, 'price_minor' => $minor, 'currency' => $account->currency], $user);

                return $offer;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CreatorPremiumException('offer_exists', 'لهذه السلسلة عرض قائم بالفعل؛ عدّله أو اسحبه أولًا.');
        }
    }

    /** تغيير السعر (مسودة/نشط/موقوف): يسري على المشتريات المستقبلية فقط. */
    public function updatePrice(User $user, string $offerPublicId, string $priceText): CreatorSeriesSaleOffer
    {
        $profile = $this->eligibility->studioProfile($user);
        $offer = $this->ownedOffer($profile, $offerPublicId);

        return DB::transaction(function () use ($user, $profile, $offer, $priceText) {
            $account = $this->lockedAccount($profile);
            $locked = $this->lockOffer($offer);

            if ($locked->isRetired()) {
                throw new CreatorPremiumException('offer_retired', 'هذا العرض مسحوب ولا يُعدَّل.');
            }

            if ($account->currency !== $locked->currency && $locked->first_activated_at !== null) {
                throw new CreatorPremiumException('currency_mismatch', 'عملة العرض لا تطابق عملة حساب أرباحك؛ تواصل مع الدعم.');
            }

            $minor = $this->parsePrice($priceText, $account);
            $old = (int) $locked->price_minor;

            if ($minor === $old) {
                return $locked;
            }

            $attributes = ['price_minor' => $minor];

            if ($locked->first_activated_at === null) {
                $attributes['currency'] = $account->currency; // مسودة لم تُفعَّل: العملة تتبع الحساب الحالي.
            } else {
                $attributes['price_changed_at'] = now();
            }

            $locked->forceFill($attributes)->save();
            $this->audit->log('creator_premium_offer_price_changed', $locked, ['old_minor' => $old, 'new_minor' => $minor, 'currency' => $locked->currency, 'applies_to' => 'future_purchases'], $user);

            return $locked;
        });
    }

    /** تفعيل (من مسودة) أو استئناف (من موقوف أوقفه المبدع نفسه). @throws CreatorPremiumException */
    public function activate(User $user, string $offerPublicId): CreatorSeriesSaleOffer
    {
        $profile = $this->eligibility->studioProfile($user);
        $offer = $this->ownedOffer($profile, $offerPublicId);

        return $this->activateLocked($offer, $user, $profile, false);
    }

    public function pause(User $user, string $offerPublicId): CreatorSeriesSaleOffer
    {
        $profile = $this->eligibility->studioProfile($user);
        $offer = $this->ownedOffer($profile, $offerPublicId);

        return $this->pauseLocked($offer, $user, CreatorSeriesSaleOffer::PAUSE_CREATOR, null);
    }

    /** سحب العرض (الرجوع إلى مجاني). المشتريات والوصول وتاريخ المبيعات والأرباح تبقى كلها؛ المحتوى يتبع الظهور العادي للسلسلة. */
    public function retire(User $user, string $offerPublicId): CreatorSeriesSaleOffer
    {
        $profile = $this->eligibility->studioProfile($user);
        $offer = $this->ownedOffer($profile, $offerPublicId);

        return DB::transaction(function () use ($user, $offer) {
            $locked = $this->lockOffer($offer);

            if ($locked->isRetired()) {
                return $locked;
            }

            $locked->forceFill(['status' => CreatorSeriesSaleOffer::STATUS_RETIRED, 'retired_at' => now()])->save();
            $this->audit->log('creator_premium_offer_retired', $locked, ['series' => $locked->series()->value('public_id'), 'first_activated' => $locked->first_activated_at?->toIso8601String()], $user);

            return $locked;
        });
    }

    // ───────────────────────── الإدارة ─────────────────────────

    public function pauseByAdmin(User $admin, string $offerPublicId, string $note): CreatorSeriesSaleOffer
    {
        $this->authorize($admin, 'creator-premium.manage');
        $note = $this->cleanNote($note);
        $offer = CreatorSeriesSaleOffer::query()->where('public_id', $offerPublicId)->firstOrFail();

        return $this->pauseLocked($offer, $admin, CreatorSeriesSaleOffer::PAUSE_ADMIN, $note);
    }

    public function resumeByAdmin(User $admin, string $offerPublicId, string $note): CreatorSeriesSaleOffer
    {
        $this->authorize($admin, 'creator-premium.manage');
        $note = $this->cleanNote($note);
        $offer = CreatorSeriesSaleOffer::query()->where('public_id', $offerPublicId)->firstOrFail();

        return $this->activateLocked($offer, $admin, null, true, $note);
    }

    // ───────────────────────── داخلي ─────────────────────────

    protected function activateLocked(CreatorSeriesSaleOffer $offer, User $actor, ?CreatorProfile $profile, bool $byAdmin, ?string $note = null): CreatorSeriesSaleOffer
    {
        $this->policy->assertEnabled();

        return DB::transaction(function () use ($offer, $actor, $profile, $byAdmin, $note) {
            $profileId = (int) $offer->creator_profile_id;
            $account = CreatorEarningAccount::query()->where('creator_profile_id', $profileId)->lockForUpdate()->first();
            $locked = $this->lockOffer($offer);

            if ($locked->isActive()) {
                return $locked; // idempotent
            }

            if ($locked->isRetired()) {
                throw new CreatorPremiumException('offer_retired', 'هذا العرض مسحوب؛ أنشئ عرضًا جديدًا.');
            }

            if (! $byAdmin && $locked->isPaused() && $locked->pause_reason !== null && $locked->pause_reason !== CreatorSeriesSaleOffer::PAUSE_CREATOR) {
                throw new CreatorPremiumException('paused_by_admin', 'أوقفت الإدارة بيع هذه السلسلة؛ لا يمكنك استئنافه بنفسك.');
            }

            if ($account === null || ! $account->isEnabled()) {
                throw new CreatorPremiumException('monetization_required', 'التسييل غير مفعَّل لحسابك؛ لا يمكن بيع سلاسل مميزة. تواصل مع الإدارة.');
            }

            if ($account->currency !== $locked->currency) {
                throw new CreatorPremiumException('currency_mismatch', 'عملة العرض لا تطابق عملة حساب أرباحك؛ تواصل مع الدعم.');
            }

            if ($this->policy->priceProblem((int) $locked->price_minor) !== null || ! $this->policy->currencyAllowed((string) $locked->currency)) {
                throw new CreatorPremiumException('price_invalid', 'سعر العرض أو عملته غير صالحين؛ عدّل السعر ثم فعّل.');
            }

            $series = CreatorSeries::query()->whereKey($locked->creator_series_id)->lockForUpdate()->firstOrFail();

            if (! $this->visibility->isVisible($series)) {
                throw new CreatorPremiumException('series_not_visible', 'لا تظهر السلسلة للعامة حاليًا (غير منشورة أو مخفية أو مؤرشفة أو مبدعها مقيَّد)؛ لا يمكن تفعيل البيع.');
            }

            $wasPaused = $locked->isPaused();

            $locked->forceFill([
                'status' => CreatorSeriesSaleOffer::STATUS_ACTIVE,
                'activated_at' => now(),
                'first_activated_at' => $locked->first_activated_at ?? now(),
                'paused_at' => null,
                'paused_by' => null,
                'pause_reason' => null,
            ])->save();

            $this->audit->log($wasPaused ? 'creator_premium_offer_resumed' : 'creator_premium_offer_activated', $locked, [
                'series' => $series->public_id, 'price_minor' => (int) $locked->price_minor, 'currency' => $locked->currency, 'by_admin' => $byAdmin, 'note' => $note,
            ], $actor);

            return $locked;
        });
    }

    protected function pauseLocked(CreatorSeriesSaleOffer $offer, User $actor, string $reason, ?string $note): CreatorSeriesSaleOffer
    {
        return DB::transaction(function () use ($offer, $actor, $reason, $note) {
            $locked = $this->lockOffer($offer);

            if ($locked->isPaused()) {
                return $locked; // idempotent (السبب الأول يبقى)
            }

            if (! $locked->isActive()) {
                throw new CreatorPremiumException('invalid_state', 'يمكن إيقاف العروض النشطة فقط.');
            }

            $locked->forceFill([
                'status' => CreatorSeriesSaleOffer::STATUS_PAUSED,
                'paused_at' => now(),
                'paused_by' => $actor->getKey(),
                'pause_reason' => $reason,
            ])->save();

            $this->audit->log('creator_premium_offer_paused', $locked, ['series' => $locked->series()->value('public_id'), 'reason' => $reason, 'note' => $note], $actor);

            return $locked;
        });
    }

    /** يقفل حساب أرباح المبدع (يجب أن يكون مفعَّلًا). داخل معاملة. @throws CreatorPremiumException */
    protected function lockedAccount(CreatorProfile $profile): CreatorEarningAccount
    {
        $account = CreatorEarningAccount::query()->where('creator_profile_id', $profile->getKey())->lockForUpdate()->first();

        if ($account === null || ! $account->isEnabled()) {
            throw new CreatorPremiumException('monetization_required', 'التسييل غير مفعَّل لحسابك؛ لا يمكن بيع سلاسل مميزة. تواصل مع الإدارة.');
        }

        return $account;
    }

    protected function lockOffer(CreatorSeriesSaleOffer $offer): CreatorSeriesSaleOffer
    {
        return CreatorSeriesSaleOffer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function ownedOffer(CreatorProfile $profile, string $publicId): CreatorSeriesSaleOffer
    {
        return CreatorSeriesSaleOffer::query()->where('creator_profile_id', $profile->getKey())->where('public_id', $publicId)->firstOrFail();
    }

    protected function parsePrice(string $text, CreatorEarningAccount $account): int
    {
        try {
            $minor = CreatorMoney::parse($text, $account->currency);
        } catch (CreatorFinanceException $e) {
            throw new CreatorPremiumException('price_invalid', $e->getMessage());
        }

        if (($problem = $this->policy->priceProblem($minor)) !== null) {
            throw new CreatorPremiumException('price_invalid', $problem);
        }

        if (($problem = $this->policy->currencyProblem($account, $account->currency)) !== null) {
            throw new CreatorPremiumException('currency_invalid', $problem);
        }

        return $minor;
    }

    protected function cleanNote(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) < 5 || mb_strlen($note) > (int) config('creator_premium.limits.note_max', 300)) {
            throw new CreatorPremiumException('note_required', 'اكتب ملاحظة واضحة (5 أحرف على الأقل).');
        }

        return $note;
    }

    protected function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new CreatorPremiumException('forbidden', 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }
}

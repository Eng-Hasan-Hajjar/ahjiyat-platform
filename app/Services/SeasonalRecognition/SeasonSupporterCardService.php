<?php

namespace App\Services\SeasonalRecognition;

use App\Models\SeasonSupportPointEntry;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonSupporterStat;
use App\Models\SponsorshipPayment;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipMoney;
use App\Services\SeasonLifecycleService;
use App\Models\Season;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - بطاقات الداعم الموسمية فوق **دفع E32/E30 الحقيقي** (لا محرك دفع ثانٍ): طلب بطاقة ⇒ SponsorshipPayment ⇒ تحقق مالي ⇒ SponsorshipContribution لبرنامج رعاية الموسم.
 *
 *  - الخادم سلطة السعر: المبلغ والعملة من الباقة (لا من العميل)، ويُجمَّدان في لقطة البطاقة. رفع إثبات الدفع **لا يمنح شيئًا**؛ التحقق وحده يفعّل البطاقة ويمنح نقاطها.
 *  - المستوى = أعلى بطاقة نشطة (فضي < ذهبي < ماسي)؛ **كل** بطاقة نشطة تساهم بنقاط لقطتها هي. الاسترداد يعكس نقاط تلك البطاقة مرة واحدة ويعيد حساب المستوى.
 *  - رد فعل الدفع (verified / refunded / rejected|cancelled|expired) يصل من مراقب SponsorshipPayment بعد الـcommit، والتفعيل/العكس متكرر الأمان؛ المطابقة تكشف أي تأخر.
 *  - الاستثناءات المالية لا تُبتلع: فشل طلب بطاقة يُلغي طلب الدفع اليتيم. تعطيل المفتاح يوقف **طلبات** البطاقات الجديدة فقط؛ دفع حقيقي سبق يُفعَّل ولا يُمحى.
 *  - ترتيب الأقفال: بطاقة ⇒ إحصاء الداعم (ثم لا شيء بعدهما).
 */
class SeasonSupporterCardService
{
    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonLifecycleService $lifecycle,
        protected SponsorshipPaymentService $payments,
        protected SeasonSupportPointService $points,
        protected SeasonalRecognitionNotifier $notifier,
        protected SeasonalRecognitionBroadcaster $broadcaster,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * يطلب بطاقة: ينشئ طلب دفع E32 بسعر الباقة الموثوق ثم صف البطاقة (pending). نقر مزدوج بنفس requestKey ⇒ البطاقة نفسها.
     *
     * @throws SeasonalRecognitionException
     */
    public function request(User $user, SeasonSupporterPackage $package, bool $anonymous = false, ?string $displayName = null, ?string $requestKey = null): SeasonSupporterCard
    {
        $season = Season::query()->findOrFail($package->season_id);
        $setting = $this->settings->settingFor($season);

        if (! $this->settings->supportersActive($season, $setting)) {
            throw new SeasonalRecognitionException('supporters_disabled', 'تقدير الداعمين غير مُفعَّل لهذا الموسم.');
        }

        if ($setting->supporters_finalized_at !== null || ! $this->lifecycle->isLive($season)) {
            throw new SeasonalRecognitionException('season_not_live', 'بطاقات الداعم تُطلب خلال الموسم الجاري فقط.');
        }

        if (! $package->is_active) {
            throw new SeasonalRecognitionException('package_inactive', 'هذه الباقة غير متاحة الآن.');
        }

        if ($user->is_frozen) {
            throw new SeasonalRecognitionException('frozen', 'حسابك مقيَّد؛ لا يمكن طلب بطاقة.');
        }

        if (! $user->hasVerifiedEmail()) {
            throw new SeasonalRecognitionException('unverified', 'وثّق بريدك الإلكتروني أولًا.');
        }

        $open = SeasonSupporterCard::query()->where('user_id', $user->id)->where('season_id', $season->id)->whereIn('status', SeasonSupporterCard::OPEN_STATUSES)->count();

        if ($open >= max(1, (int) config('seasonal_recognition.supporters.max_open_cards_per_user', 3))) {
            throw new SeasonalRecognitionException('too_many_open', 'لديك طلبات بطاقات مفتوحة كثيرة؛ أكمل أو ألغِ بعضها أولًا.');
        }

        // السعر من الخادم فقط: الوحدة الصغرى ⇒ نص بالوحدة الرئيسية بحسب أس العملة.
        $payment = $this->payments->createRequest(
            $user,
            $package->program,
            $this->majorUnits((int) $package->price_minor, (string) $package->currency),
            (string) $package->currency,
            $anonymous,
            $displayName,
            null,
            $requestKey,
        );

        $existing = SeasonSupporterCard::query()->where('sponsorship_payment_id', $payment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $card = new SeasonSupporterCard;
            $card->forceFill([
                'season_id' => $season->id,
                'package_id' => $package->id,
                'user_id' => $user->id,
                'sponsorship_payment_id' => $payment->id,
                'tier_key' => $package->tier_key,
                'price_minor' => $package->price_minor,
                'currency' => $package->currency,
                'support_points' => $package->support_points,
                'name_style_key' => $package->name_style_key,
                'social_mention_eligible' => $package->social_mention_eligible,
                'is_anonymous' => $anonymous,
                'status' => SeasonSupporterCard::STATUS_PENDING,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return SeasonSupporterCard::query()->where('sponsorship_payment_id', $payment->id)->firstOrFail();
        } catch (Throwable $e) {
            report($e);

            try {
                $this->payments->cancelByOwner($user, $payment); // لا طلب دفع يتيم بلا بطاقة.
            } catch (Throwable $inner) {
                report($inner);
            }

            throw new SeasonalRecognitionException('card_create_failed', 'تعذّر إنشاء الطلب الآن، أعد المحاولة.');
        }

        $this->audit->log('seasonal_supporter_card_requested', $card, ['season_id' => $season->id, 'tier' => $card->tier_key, 'payment' => $payment->public_id], $user);

        return $card;
    }

    /**
     * تفعيل بطاقة بعد تحقق الدفع (متكرر الأمان). يتحقق من تطابق المبلغ/العملة/المالك مع اللقطة قبل أي منح.
     *
     * @return SeasonSupporterCard|null البطاقة المفعَّلة الآن، أو null إن لا بطاقة/سبق تفعيلها/لم تتطابق.
     */
    public function activateForPayment(int $paymentId): ?SeasonSupporterCard
    {
        $card = DB::transaction(function () use ($paymentId): ?SeasonSupporterCard {
            /** @var SeasonSupporterCard|null $card */
            $card = SeasonSupporterCard::query()->where('sponsorship_payment_id', $paymentId)->lockForUpdate()->first();

            if ($card === null || $card->status !== SeasonSupporterCard::STATUS_PENDING) {
                return null;
            }

            $payment = SponsorshipPayment::query()->find($paymentId);

            if ($payment === null
                || $payment->status !== SponsorshipPayment::STATUS_VERIFIED
                || $payment->sponsorship_contribution_id === null
                || (int) $payment->amount_minor !== (int) $card->price_minor
                || (string) $payment->currency !== (string) $card->currency
                || (int) $payment->user_id !== (int) $card->user_id) {
                $this->audit->log('seasonal_supporter_card_mismatch', $card, ['payment_id' => $paymentId], null);

                return null; // تبقى معلّقة وتظهر في المطابقة؛ لا منح من دفع لا يطابق لقطتها.
            }

            // بعد إنهاء ترتيب الداعمين لا نُنشئ صف إحصاء جديدًا (لا نقاط تُمنح؛ البطاقة نفسها تُفعَّل لأن الدفع محقَّق فعلًا).
            if (! $this->points->supportersFinalized((int) $card->season_id)) {
                $this->points->lockStat((int) $card->season_id, (int) $card->user_id);
            }

            $card->forceFill(['status' => SeasonSupporterCard::STATUS_ACTIVE, 'activated_at' => now()])->save();

            $this->points->grant((int) $card->season_id, (int) $card->user_id, SeasonSupportPointEntry::SOURCE_SUPPORTER_CARD, (int) $card->id, (int) $card->support_points, ! $card->is_anonymous);
            $this->refreshStat((int) $card->season_id, (int) $card->user_id);

            $this->audit->log('seasonal_supporter_card_activated', $card, ['season_id' => $card->season_id, 'tier' => $card->tier_key, 'points' => $card->support_points], null);

            return $card;
        });

        if ($card !== null) {
            $this->afterChange((int) $card->season_id);
            $this->safely(fn () => $this->notifier->supporterCardVerified($card, Season::query()->findOrFail($card->season_id)));
        }

        return $card;
    }

    /** رفض/إلغاء/انتهاء الطلب قبل التحقق ⇒ البطاقة المعلّقة تصير void (لا منح). متكرر الأمان. */
    public function voidForPayment(int $paymentId): bool
    {
        $changed = DB::transaction(function () use ($paymentId): bool {
            $card = SeasonSupporterCard::query()->where('sponsorship_payment_id', $paymentId)->lockForUpdate()->first();

            if ($card === null || $card->status !== SeasonSupporterCard::STATUS_PENDING) {
                return false;
            }

            $card->forceFill(['status' => SeasonSupporterCard::STATUS_VOID])->save();
            $this->audit->log('seasonal_supporter_card_voided', $card, ['season_id' => $card->season_id], null);

            return true;
        });

        return $changed;
    }

    /** استرداد الدفع المحقَّق ⇒ البطاقة refunded ونقاطها تُعكس مرة واحدة ويُعاد حساب المستوى. متكرر الأمان. */
    public function refundForPayment(int $paymentId): bool
    {
        $seasonId = DB::transaction(function () use ($paymentId): ?int {
            $card = SeasonSupporterCard::query()->where('sponsorship_payment_id', $paymentId)->lockForUpdate()->first();

            if ($card === null || ! in_array($card->status, [SeasonSupporterCard::STATUS_ACTIVE, SeasonSupporterCard::STATUS_PENDING], true)) {
                return null;
            }

            $wasActive = $card->status === SeasonSupporterCard::STATUS_ACTIVE;

            if ($wasActive) {
                $this->points->lockStat((int) $card->season_id, (int) $card->user_id);
                $card->forceFill(['status' => SeasonSupporterCard::STATUS_REFUNDED, 'refunded_at' => now()])->save();
                $this->points->reverse(SeasonSupportPointEntry::SOURCE_SUPPORTER_CARD, (int) $card->id);
                $this->refreshStat((int) $card->season_id, (int) $card->user_id);
            } else {
                // معلّقة لم تُفعَّل قط (فشل ما بعد التحقق): لا منح لتُعكس؛ تُغلق دون أثر على النقاط.
                $card->forceFill(['status' => SeasonSupporterCard::STATUS_VOID])->save();
            }

            $this->audit->log('seasonal_supporter_card_refunded', $card, ['season_id' => $card->season_id, 'was_active' => $wasActive], null);

            return (int) $card->season_id;
        });

        if ($seasonId !== null) {
            $this->afterChange($seasonId);

            return true;
        }

        return false;
    }

    /**
     * يعيد اشتقاق مستوى الداعم الحالي ووسم الطابور الاجتماعي من بطاقاته النشطة (تحت قفل صف الإحصاء الذي أخذه المستدعي).
     * وسم eligible وحده يُنشأ/يُسحب آليًا؛ queued/published/skipped قرارات إدارية لا تُمسّ.
     */
    public function refreshStat(int $seasonId, int $userId): void
    {
        $stat = SeasonSupporterStat::query()->where('season_id', $seasonId)->where('user_id', $userId)->lockForUpdate()->first();

        if ($stat === null) {
            return;
        }

        $active = SeasonSupporterCard::query()->where('season_id', $seasonId)->where('user_id', $userId)->where('status', SeasonSupporterCard::STATUS_ACTIVE)
            ->get(['tier_key', 'social_mention_eligible', 'is_anonymous']);

        $top = $active->sortByDesc(fn (SeasonSupporterCard $c) => SeasonSupporterPackage::rankOf($c->tier_key))->first();
        $eligible = $active->contains(fn (SeasonSupporterCard $c) => $c->social_mention_eligible && ! $c->is_anonymous);

        $changes = ['card_tier_key' => $top?->tier_key];

        if ($eligible && $stat->social_status === null) {
            $changes['social_status'] = SeasonSupporterStat::SOCIAL_ELIGIBLE;
            $changes['social_updated_at'] = now();
        } elseif (! $eligible && $stat->social_status === SeasonSupporterStat::SOCIAL_ELIGIBLE) {
            $changes['social_status'] = null;
            $changes['social_updated_at'] = now();
        }

        $stat->forceFill($changes)->save();
    }

    protected function afterChange(int $seasonId): void
    {
        SeasonalRecognitionCache::bump($seasonId, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
        $this->broadcaster->supportersUpdated($seasonId);
    }

    /** الوحدة الصغرى ⇒ نص بالوحدة الرئيسية بحسب أس العملة (يقبله PaymentRules::parseAmount). */
    protected function majorUnits(int $minor, string $currency): string
    {
        $exponent = SponsorshipMoney::exponent($currency);

        if ($exponent <= 0) {
            return (string) $minor;
        }

        $base = 10 ** $exponent;

        return intdiv($minor, $base).'.'.str_pad((string) ($minor % $base), $exponent, '0', STR_PAD_LEFT);
    }

    protected function safely(\Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

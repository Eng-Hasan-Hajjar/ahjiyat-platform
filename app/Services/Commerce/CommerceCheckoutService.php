<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\OperationalAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E41 - بدء/استئناف جلسة الدفع المستضافة لشراء قائم. **الخادم وحده** يحدد المبلغ والعملة والمستخدم والمنتج (من لقطة DigitalPurchase)؛ العميل لا يرسل شيئًا منها ولا رابط عودة.
 *
 *  - لا يتجاوز قيود E40: الطلب نفسه (open_key/request_key) أنشأته الخدمة المالكة؛ هنا نضيف الجلسة فقط.
 *  - إعادة الطلب لنفس الطلب المفتوح: تُعاد الجلسة القائمة (reuse) ما دامت صالحة. جلسة منتهية/فاشلة ⇒ **استبدال صريح مدقَّق** بجيل جديد (حد أقصى)، وبعد التأكد (إن أمكن) أن القديمة لم تُدفع.
 *  - مفتاح idempotency للمزوّد ثابت: `commerce:chk:{public_id}` ثم `:{generation}` عند الاستبدال — لا مفاتيح عشوائية لكل محاولة، فإعادة المحاولة بعد انهيار التطبيق تعيد الجلسة نفسها.
 *  - نداء المزوّد خارج أي معاملة/قفل (لا قفل أثناء الشبكة)؛ فشله لا يمسّ الشراء (يبقى سليمًا) ويُدقَّق برمز آمن ويُرمى خطأ قابل لإعادة المحاولة.
 *  - عنوان الصفحة من المزوّد فقط ويمرّ بـCheckoutUrlGuard عند التخزين وعند إعادة التوجيه (لا إعادة توجيه مفتوحة). عودة المتصفح ليست دليل دفع (انظر مسار العودة).
 *  - تعطيل المزوّد أو مفتاح التجارة ⇒ لا جلسة جديدة ولا استئناف؛ لا يوقف webhook ولا الاسترداد.
 */
class CommerceCheckoutService
{
    public function __construct(
        protected CommercePaymentProviderRegistry $registry,
        protected DigitalPurchaseService $purchases,
        protected CommerceSyncService $sync,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * @return array{url: string, session_id: string, reused: bool}
     *
     * @throws CommerceException
     */
    public function start(User $user, DigitalPurchase $purchase): array
    {
        if ((int) $purchase->user_id !== (int) $user->getKey()) {
            throw new CommerceException('forbidden', 'لا تملك صلاحية على هذا الطلب.');
        }

        if ($user->is_frozen) {
            throw new CommerceException('frozen', 'حسابك مقيَّد؛ لا يمكن إتمام الدفع.');
        }

        if (! (bool) config('commerce.enabled', true)) {
            throw new CommerceException('disabled', 'الدفع الإلكتروني غير متاح حاليًا.');
        }

        $provider = $this->registry->get($purchase->provider);

        if (! $provider->supportsRedirect()) {
            throw new CommerceException('not_hosted', 'هذا الطلب لا يستخدم صفحة دفع خارجية.');
        }

        if (! $provider->isEnabled()) {
            throw new CommerceException('provider_disabled', 'طريقة الدفع غير متاحة حاليًا.');
        }

        $purchase = $purchase->fresh();

        $this->assertStartable($purchase);

        if ($this->usable($purchase)) {
            return ['url' => (string) $purchase->checkout_url, 'session_id' => (string) $purchase->provider_session_id, 'reused' => true];
        }

        $generation = (int) $purchase->checkout_generation;
        $replacing = $purchase->provider_session_id !== null;

        if ($replacing) {
            $generation++;

            if ($generation > (int) config('commerce.checkout.max_generations', 3)) {
                throw new CommerceException('too_many_sessions', 'تجاوزت عدد محاولات الدفع المسموحة لهذا الطلب. ابدأ طلبًا جديدًا أو تواصل مع الدعم.');
            }

            $this->assertOldSessionNotPaid($provider, $purchase);
        }

        $key = $this->idempotencyKey($purchase, $generation);
        $returnUrl = route('commerce.checkout.return', ['order' => $purchase->public_id]);
        $expiresAt = CarbonImmutable::now()->addMinutes(max(5, (int) config('commerce.checkout.session_expiry_minutes', 60)));

        try {
            $init = $provider->createCheckout(new CommerceCheckoutRequest($purchase, $key, $returnUrl, $returnUrl, $expiresAt));
        } catch (CommerceException $e) {
            $this->checkoutFailed($purchase, $e->reason);

            throw new CommerceException($e->reason, $e->getMessage(), $e->retryable);
        } catch (Throwable $e) {
            report($e);
            $this->checkoutFailed($purchase, 'provider_error');

            throw new CommerceException('provider_error', 'تعذّر الاتصال بخدمة الدفع الآن. لم يتغير طلبك؛ أعد المحاولة بعد لحظات.', true);
        }

        if (preg_match('/^[A-Za-z0-9_\-:.]{1,128}$/', $init->sessionId) !== 1 || ! CheckoutUrlGuard::allows($provider->key(), $init->url)) {
            $this->checkoutFailed($purchase, 'invalid_checkout');

            throw new CommerceException('invalid_checkout', 'تعذّر تجهيز صفحة الدفع الآن. أعد المحاولة لاحقًا أو تواصل مع الدعم.', true);
        }

        return $this->persist($purchase, $init, $generation, $replacing);
    }

    /** عنوان إعادة التوجيه الآمن لطلب فيه جلسة صالحة (يُعاد فحصه بالحارس عند كل استعمال). null إن لم يكن صالحًا. */
    public function safeRedirectUrl(DigitalPurchase $purchase): ?string
    {
        $url = (string) $purchase->checkout_url;

        return ($url !== '' && CheckoutUrlGuard::allows($purchase->provider, $url)) ? $url : null;
    }

    public function idempotencyKey(DigitalPurchase $purchase, int $generation): string
    {
        return 'commerce:chk:'.$purchase->public_id.($generation > 0 ? ':'.$generation : '');
    }

    // ───────────────────────── داخلي ─────────────────────────

    /** @throws CommerceException */
    protected function assertStartable(DigitalPurchase $purchase): void
    {
        if ($purchase->status === DigitalPurchase::STATUS_VERIFIED) {
            throw new CommerceException('already_owned', 'تم تأكيد هذا الطلب بالفعل.');
        }

        if ($purchase->status !== DigitalPurchase::STATUS_AWAITING) {
            throw new CommerceException('invalid_state', 'لا يمكن متابعة الدفع لهذا الطلب في حالته الحالية.');
        }

        if ($purchase->expires_at !== null && $purchase->expires_at->lte(now())) {
            throw new CommerceException('expired', 'انتهت صلاحية هذا الطلب. ابدأ طلبًا جديدًا.');
        }

        if ($purchase->providerState() === CommerceProviderState::Refunded) {
            throw new CommerceException('payment_refunded', 'سبق استرداد دفعة هذا الطلب. ألغِه وابدأ طلبًا جديدًا.');
        }

        if ($purchase->needsReview() || $purchase->providerState() === CommerceProviderState::Paid) {
            throw new CommerceException('already_paid', 'استلمنا دفعتك وهي قيد التحقق. لا حاجة لدفع جديد.');
        }
    }

    protected function usable(DigitalPurchase $purchase): bool
    {
        if ($purchase->provider_session_id === null || blank($purchase->checkout_url) || $purchase->checkout_expires_at === null) {
            return false;
        }

        $state = $purchase->providerState();

        if ($state !== null && $state !== CommerceProviderState::Pending) {
            return false; // فاشلة/ملغاة عند المزوّد ⇒ استبدال صريح لا إعادة استعمال.
        }

        return $purchase->checkout_expires_at->gt(now()->addSeconds(30)) && $this->safeRedirectUrl($purchase) !== null;
    }

    /** قبل استبدال جلسة: إن كان المزوّد يستعلم فنتأكد أن القديمة لم تُدفع (ازدواج خصم). تعذّر الاستعلام ⇒ لا استبدال. */
    protected function assertOldSessionNotPaid(CommercePaymentProvider $provider, DigitalPurchase $purchase): void
    {
        if (! $provider->supportsStatusQuery()) {
            return;
        }

        try {
            $status = $provider->fetchStatus($purchase);
        } catch (Throwable) {
            throw new CommerceException('provider_unavailable', 'تعذّر التحقق من حالة دفعتك السابقة الآن. أعد المحاولة بعد لحظات.', true);
        }

        if ($status !== null && in_array($status->state, [CommerceProviderState::Paid, CommerceProviderState::Refunded], true)) {
            $this->sync->sync($purchase);

            throw new CommerceException('already_paid', 'استلمنا دفعتك وهي قيد التحقق. لا حاجة لدفع جديد.');
        }
    }

    /** @return array{url: string, session_id: string, reused: bool} */
    protected function persist(DigitalPurchase $purchase, CommerceCheckoutInit $init, int $generation, bool $replacing): array
    {
        try {
            return DB::transaction(function () use ($purchase, $init, $generation, $replacing) {
                $locked = $this->purchases->lock($purchase);

                if ($locked->status !== DigitalPurchase::STATUS_AWAITING || $locked->needsReview()) {
                    throw new CommerceException('invalid_state', 'لا يمكن متابعة الدفع لهذا الطلب في حالته الحالية.');
                }

                // سباق: عملية متزامنة سبقتنا بجلسة صالحة (مفتاح idempotency الثابت يعطي الجلسة نفسها غالبًا) ⇒ نعتمد الفائزة.
                if ($locked->provider_session_id !== null && (int) $locked->checkout_generation >= $generation && $this->usable($locked)) {
                    return ['url' => (string) $locked->checkout_url, 'session_id' => (string) $locked->provider_session_id, 'reused' => true];
                }

                $attributes = [
                    'provider_session_id' => $init->sessionId,
                    'checkout_url' => $init->url,
                    'checkout_created_at' => now(),
                    'checkout_expires_at' => $init->expiresAt,
                    'checkout_generation' => $generation,
                ];

                // جلسة جديدة = دورة مزوّد جديدة تبدأ pending (الاستبدال مسموح بالنموذج فقط من pending/failed/cancelled).
                if ($locked->provider_status === null || $replacing) {
                    $attributes['provider_status'] = CommerceProviderState::Pending->value;
                }

                $locked->forceFill($attributes)->save();

                $this->purchases->event($locked, $replacing ? 'checkout_replaced' : 'checkout_created', $locked->status, $locked->status, null, 'g'.$generation);
                $this->audit->log('commerce_checkout_created', $locked, ['reference' => $locked->reference_code, 'provider' => $locked->provider, 'generation' => $generation, 'replaced' => $replacing]);

                return ['url' => $init->url, 'session_id' => $init->sessionId, 'reused' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // مرجع جلسة المزوّد يخص طلبًا آخر: لا نعتمده (تعارض).
            $this->checkoutFailed($purchase, 'session_conflict');

            throw new CommerceException('provider_error', 'تعذّر تجهيز صفحة الدفع الآن. أعد المحاولة لاحقًا أو تواصل مع الدعم.', true);
        }
    }

    protected function checkoutFailed(DigitalPurchase $purchase, string $reason): void
    {
        Log::warning('commerce.checkout_failed', ['provider' => $purchase->provider, 'reason' => mb_substr($reason, 0, 48)]);

        try {
            $this->audit->log('commerce_checkout_failed', $purchase, ['reference' => $purchase->reference_code, 'provider' => $purchase->provider, 'reason' => mb_substr($reason, 0, 48)]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E41 - إشعارات التجارة (E15، فئة social). تُستدعى **بعد** نجاح الـcommit، ويعزل فشلها (try/catch + report): لا ترجع حالة دفع ولا تُفشل مالًا.
 * مفاتيح idempotency دلالية ⇒ webhook مكرَّر أو إعادة تشغيل لا ترسل إشعارًا ثانيًا. لا مبالغ ولا مراجع مزوّد ولا ملاحظات داخلية في النص. المجمَّد لا يُشعَر.
 * «تم التفعيل» يستخدم إشعار E40 الموجود (pass_premium_unlocked) عبر معالج المنتج بالمفتاح الدلالي نفسه، فلا إشعارين لتفعيل واحد من مساري التحقق.
 */
class CommerceNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher, protected CommerceProductRegistry $products) {}

    public function verified(DigitalPurchase $purchase): void
    {
        try {
            $this->products->for($purchase->product_type)->notifyVerified($purchase);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(DigitalPurchase $purchase): void
    {
        $this->send($purchase, NotificationType::CommerceFailed, 'commerce_failed:'.$purchase->id.':'.(int) $purchase->checkout_generation);
    }

    public function actionRequired(DigitalPurchase $purchase): void
    {
        $this->send($purchase, NotificationType::CommerceActionRequired, 'commerce_action:'.$purchase->id.':'.($purchase->review_flagged_at?->getTimestamp() ?? 0));
    }

    public function refunded(DigitalPurchase $purchase): void
    {
        $this->send($purchase, NotificationType::CommerceRefunded, 'commerce_refunded:'.$purchase->id);
    }

    protected function send(DigitalPurchase $purchase, NotificationType $type, string $key): bool
    {
        if (! (bool) config('commerce.notifications.enabled', true)) {
            return false;
        }

        try {
            $user = User::query()->find($purchase->user_id);

            if ($user === null || $user->is_frozen) {
                return false;
            }

            return $this->dispatcher->dispatch($user, $type, ['pass' => $purchase->product_name], $key, ['digital_purchase_id' => $purchase->id], [], 'commerce.purchases.index') === DispatchResult::Created;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}

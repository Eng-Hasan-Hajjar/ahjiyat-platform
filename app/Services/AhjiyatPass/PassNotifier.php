<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E40 - إشعارات المسار (E15، فئة social الاختيارية). تُستدعى **بعد** نجاح الـcommit ويعزل فشلها (try/catch + report): لا تُرجع حالة ولا تُفشل المال أو التقدّم.
 * محافظة عمدًا: لا إشعار على نقطة/مهمة/شريحة/بدء المسار (لا بث جماعي)؛ فقط: تفعيل المميز، رفض الطلب، وتنبيه واحد لإغلاق مهلة الاستلام لمن لديه ما يستلمه.
 * لا مبالغ ولا مراجع تحويل ولا ملاحظات داخلية في النص. مفاتيح idempotency دلالية فلا إشعار مكرَّر. الحساب المجمَّد لا يُشعَر.
 */
class PassNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function premiumUnlocked(DigitalPurchase $purchase): void
    {
        $this->send((int) $purchase->user_id, NotificationType::PassPremiumUnlocked, 'pass_premium:'.$purchase->id, ['pass' => $purchase->product_name], ['digital_purchase_id' => $purchase->id]);
    }

    public function purchaseRejected(DigitalPurchase $purchase): void
    {
        $this->send((int) $purchase->user_id, NotificationType::PassPurchaseRejected, 'pass_purchase_rejected:'.$purchase->id, [], ['digital_purchase_id' => $purchase->id]);
    }

    public function claimWindowClosing(User $user, AhjiyatPass $pass): bool
    {
        return $this->send((int) $user->id, NotificationType::PassClaimWindowClosing, 'pass_claim_window:'.$pass->id.':'.$user->id, ['pass' => $pass->name], ['ahjiyat_pass_id' => $pass->id]);
    }

    /** @param array<string, mixed> $params @param array<string, scalar> $refs */
    protected function send(int $userId, NotificationType $type, string $key, array $params, array $refs): bool
    {
        if (! (bool) config('ahjiyat_pass.notifications.enabled', true)) {
            return false;
        }

        try {
            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return false;
            }

            $result = $this->dispatcher->dispatch($user, $type, $params, $key, $refs, [], 'pass.index');

            return $result === \App\Services\Notifications\DispatchResult::Created;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}

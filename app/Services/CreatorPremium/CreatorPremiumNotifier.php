<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorDirectSale;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E43 - إشعارات السوق المميز (E15، فئة social الاختيارية). تُستدعى **بعد** نجاح الـcommit ويعزل فشلها (try/catch + report): لا تُرجع حالة ولا تُفشل المال أو الوصول.
 * ثلاثة إشعارات محافظة فقط: «فُتحت السلسلة» للمشتري، «بيع جديد» و«عُدّلت حصة بعد استرداد» للمبدع. استرداد المشتري يستعمل إشعار E41 القائم (CommerceRefunded) فلا إشعارين لحدث واحد.
 * لا هوية مشترٍ ولا مبالغ ولا مراجع دفع ولا ملاحظات داخلية في النص. مفاتيح idempotency دلالية (تكرار الـwebhook لا يرسل ثانيًا). المجمَّد لا يُشعَر.
 */
class CreatorPremiumNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function accessGranted(DigitalPurchase $purchase): bool
    {
        return $this->send(
            (int) $purchase->user_id,
            NotificationType::PremiumSeriesAccessGranted,
            'premium_series_access:'.$purchase->getKey(),
            ['title' => (string) $purchase->product_name],
            ['digital_purchase_id' => $purchase->getKey()],
            ['series' => (string) $purchase->product_public_id],
            'series.show',
        );
    }

    public function saleRecorded(CreatorDirectSale $sale): bool
    {
        return $this->send(
            (int) $sale->user_id,
            NotificationType::CreatorPremiumSale,
            'creator_premium_sale:'.$sale->getKey(),
            ['title' => (string) $sale->series_title],
            ['creator_direct_sale_id' => $sale->getKey()],
        );
    }

    public function saleAdjusted(CreatorDirectSale $sale): bool
    {
        return $this->send(
            (int) $sale->user_id,
            NotificationType::CreatorPremiumSaleAdjusted,
            'creator_premium_sale_adjusted:'.$sale->getKey(),
            ['title' => (string) $sale->series_title],
            ['creator_direct_sale_id' => $sale->getKey()],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, scalar>  $refs
     * @param  array<string, scalar>  $actionParams
     */
    protected function send(int $userId, NotificationType $type, string $key, array $params, array $refs, array $actionParams = [], ?string $route = null): bool
    {
        if (! (bool) config('creator_premium.notifications.enabled', true)) {
            return false;
        }

        try {
            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return false;
            }

            return $this->dispatcher->dispatch($user, $type, $params, $key, $refs, $actionParams, $route) === DispatchResult::Created;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}

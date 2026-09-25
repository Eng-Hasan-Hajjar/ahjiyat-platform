<?php

namespace App\Services\Store;

use App\Models\CurrencyTransaction;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Facades\DB;

class StorePurchaseService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected InventoryService $inventory,
        protected EntitlementService $entitlements,
    ) {}

    public function purchase(User $user, StoreItem $item, StoreItemPrice $price, string $requestKey): StorePurchase
    {
        if ($existing = StorePurchase::where('request_key', $requestKey)->first()) {
            $this->assertRequestConsistent($existing, $user, $item, $price);

            return $existing;
        }

        return DB::transaction(function () use ($user, $item, $price, $requestKey) {
            $lockedItem = StoreItem::where('id', $item->id)->lockForUpdate()->first();

            $this->assertPurchasable($lockedItem, $price);
            $this->assertStockAvailable($lockedItem);
            $this->assertWithinPerUserLimit($lockedItem, $user);
            $this->assertNoDuplicateEntitlement($lockedItem, $user);

            $currency = $price->currency;

            $purchase = StorePurchase::create([
                'user_id' => $user->id,
                'store_item_id' => $lockedItem->id,
                'store_item_price_id' => $price->id,
                'currency_id' => $currency->id,
                'price_amount' => $price->amount,
                'status' => StorePurchase::STATUS_PENDING_FULFILLMENT,
                'request_key' => $requestKey,
                'item_snapshot' => [
                    'name' => $lockedItem->name,
                    'sku' => $lockedItem->sku,
                    'item_type' => $lockedItem->item_type,
                    'fulfillment_type' => $lockedItem->fulfillment_type,
                    'grant_quantity' => $lockedItem->grant_quantity,
                    'entitlement_key' => $lockedItem->entitlement_key,
                    'entitlement_duration_days' => $lockedItem->entitlement_duration_days,
                    'currency_code' => $currency->code,
                    'currency_name' => $currency->name,
                ],
                'fulfillment_type' => $lockedItem->fulfillment_type,
            ]);

            $this->wallets->debitAvailable(
                $user,
                $currency,
                $price->amount,
                "store_purchase:{$lockedItem->sku}",
                $purchase,
                CurrencyTransaction::TYPE_SPEND,
            );

            $this->fulfillAutomatically($purchase, $lockedItem, $user);

            return $purchase->fresh();
        });
    }

    protected function fulfillAutomatically(StorePurchase $purchase, StoreItem $item, User $user): void
    {
        match ($item->fulfillment_type) {
            StoreItem::FULFILLMENT_INVENTORY => $this->fulfillInventory($purchase, $item, $user),
            StoreItem::FULFILLMENT_ENTITLEMENT => $this->fulfillEntitlement($purchase, $item, $user),
            default => null,
        };
    }

    protected function fulfillInventory(StorePurchase $purchase, StoreItem $item, User $user): void
    {
        $this->inventory->grant($user, $item, $item->grant_quantity, "store_purchase:{$purchase->id}", $purchase);
        $purchase->update(['status' => StorePurchase::STATUS_FULFILLED, 'fulfilled_at' => now()]);
    }

    protected function fulfillEntitlement(StorePurchase $purchase, StoreItem $item, User $user): void
    {
        $this->entitlements->grant($user, $item, $purchase);
        $purchase->update(['status' => StorePurchase::STATUS_FULFILLED, 'fulfilled_at' => now()]);
    }

    public function fulfillManually(StorePurchase $purchase, User $admin, ?string $adminNote = null, ?string $userMessage = null): StorePurchase
    {
        return DB::transaction(function () use ($purchase, $admin, $adminNote, $userMessage) {
            $locked = StorePurchase::where('id', $purchase->id)->lockForUpdate()->first();

            if ($locked->status !== StorePurchase::STATUS_PENDING_FULFILLMENT) {
                throw new \RuntimeException('لا يمكن إنجاز هذا الطلب - حالته الحالية لا تسمح بذلك.');
            }

            $locked->update([
                'status' => StorePurchase::STATUS_FULFILLED,
                'fulfilled_by' => $admin->id,
                'fulfilled_at' => now(),
                'admin_note' => $adminNote,
                'user_message' => $userMessage,
            ]);

            return $locked->fresh();
        });
    }

    public function refund(StorePurchase $purchase, User $admin, string $reason): StorePurchase
    {
        return DB::transaction(function () use ($purchase, $admin, $reason) {
            $locked = StorePurchase::where('id', $purchase->id)->lockForUpdate()->first();

            if (! $locked->isRefundable()) {
                throw new \RuntimeException('لا يمكن استرجاع هذا الطلب - إما مُسترجَع مسبقاً، أو مُسلَّم يدوياً بالفعل ويتطلب حلاً إدارياً مباشراً.');
            }

            $this->wallets->refund(
                $locked->user,
                $locked->currency,
                $locked->price_amount,
                "store_refund:{$locked->id}",
                $locked,
                CurrencyTransaction::TYPE_REFUND,
            );

            $this->reverseFulfillment($locked, $admin, $reason);

            $locked->update([
                'status' => StorePurchase::STATUS_REFUNDED,
                'refunded_by' => $admin->id,
                'refunded_at' => now(),
                'admin_note' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    protected function reverseFulfillment(StorePurchase $purchase, User $admin, string $reason): void
    {
        if ($purchase->fulfillment_type === StoreItem::FULFILLMENT_INVENTORY) {
            $grantQuantity = $purchase->item_snapshot['grant_quantity'] ?? 1;
            $this->inventory->revoke($purchase->user, $purchase->item, $grantQuantity, "store_refund:{$purchase->id}", $purchase);
        }

        if ($purchase->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT) {
            $entitlement = $purchase->entitlement;

            if ($entitlement) {
                $this->entitlements->revoke($entitlement, $admin, "استرجاع شراء #{$purchase->id}: {$reason}");
            }
        }
    }

    protected function assertPurchasable(StoreItem $item, StoreItemPrice $price): void
    {
        if (! $item->isPurchasable()) {
            throw new \RuntimeException('العنصر غير متاح للشراء حالياً.');
        }

        if ($price->store_item_id !== $item->id) {
            throw new \RuntimeException('خيار السعر هذا لا ينتمي لهذا العنصر.');
        }

        if (! $price->is_active) {
            throw new \RuntimeException('خيار السعر هذا لم يعد فعالاً.');
        }
    }

    protected function assertStockAvailable(StoreItem $item): void
    {
        if ($item->isSoldOut()) {
            throw new \RuntimeException('نفدت الكمية المتاحة من هذا العنصر.');
        }
    }

    protected function assertWithinPerUserLimit(StoreItem $item, User $user): void
    {
        if ($item->per_user_limit === null) {
            return;
        }

        $existingCount = StorePurchase::where('user_id', $user->id)
            ->where('store_item_id', $item->id)
            ->whereIn('status', [StorePurchase::STATUS_PENDING_FULFILLMENT, StorePurchase::STATUS_FULFILLED])
            ->count();

        if ($existingCount >= $item->per_user_limit) {
            throw new \RuntimeException('وصلت للحد المسموح به من عمليات الشراء لهذا العنصر.');
        }
    }

    protected function assertNoDuplicateEntitlement(StoreItem $item, User $user): void
    {
        if ($item->fulfillment_type !== StoreItem::FULFILLMENT_ENTITLEMENT || blank($item->entitlement_key)) {
            return;
        }

        if ($this->entitlements->hasActive($user, $item->entitlement_key)) {
            throw new \RuntimeException('تمتلك هذا الامتياز بالفعل.');
        }
    }

    protected function assertRequestConsistent(StorePurchase $existing, User $user, StoreItem $item, StoreItemPrice $price): void
    {
        $matches = $existing->user_id === $user->id
            && $existing->store_item_id === $item->id
            && $existing->store_item_price_id === $price->id;

        if (! $matches) {
            throw new \RuntimeException('مفتاح الطلب هذا مُستخدَم مسبقًا لعملية شراء مختلفة تمامًا - تعارض حقيقي.');
        }
    }
}
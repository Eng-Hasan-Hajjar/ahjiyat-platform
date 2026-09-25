<?php

namespace App\Services\Store;

use App\Models\InventoryTransaction;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserInventoryItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function grant(User $user, StoreItem $item, int $quantity, string $reason, ?Model $reference = null): InventoryTransaction
    {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use ($user, $item, $quantity, $reason, $reference) {
            $inventoryItem = $this->lockedInventoryItem($user, $item);
            $inventoryItem->increment('quantity', $quantity);

            return InventoryTransaction::create([
                'user_id' => $user->id,
                'store_item_id' => $item->id,
                'quantity' => $quantity,
                'type' => InventoryTransaction::TYPE_GRANT,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    public function revoke(User $user, StoreItem $item, int $quantity, string $reason, ?Model $reference = null): ?InventoryTransaction
    {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use ($user, $item, $quantity, $reason, $reference) {
            $inventoryItem = $this->lockedInventoryItem($user, $item);

            $effectiveQuantity = min($quantity, $inventoryItem->quantity);

            if ($effectiveQuantity <= 0) {
                return null;
            }

            $inventoryItem->decrement('quantity', $effectiveQuantity);

            return InventoryTransaction::create([
                'user_id' => $user->id,
                'store_item_id' => $item->id,
                'quantity' => -$effectiveQuantity,
                'type' => InventoryTransaction::TYPE_REVOKE,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    public function quantityFor(User $user, StoreItem $item): int
    {
        return UserInventoryItem::where('user_id', $user->id)->where('store_item_id', $item->id)->value('quantity') ?? 0;
    }

    protected function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('الكمية يجب أن تكون أكبر من صفر.');
        }
    }

    protected function lockedInventoryItem(User $user, StoreItem $item): UserInventoryItem
    {
        return UserInventoryItem::query()
            ->where('user_id', $user->id)
            ->where('store_item_id', $item->id)
            ->lockForUpdate()
            ->firstOrCreate(['user_id' => $user->id, 'store_item_id' => $item->id]);
    }
}
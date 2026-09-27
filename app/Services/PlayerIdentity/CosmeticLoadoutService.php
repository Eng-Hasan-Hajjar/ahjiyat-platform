<?php

namespace App\Services\PlayerIdentity;

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use App\Models\UserInventoryItem;
use Illuminate\Support\Facades\DB;

class CosmeticLoadoutService
{
    public function equip(User $user, StoreItem $item): UserCosmeticLoadout
    {
        $this->assertCanEquip($user, $item);

        return DB::transaction(function () use ($user, $item) {
            return UserCosmeticLoadout::updateOrCreate(
                ['user_id' => $user->id, 'slot' => $item->cosmetic_slot],
                ['store_item_id' => $item->id, 'equipped_at' => now()],
            );
        });
    }

    public function unequip(User $user, string $slot): void
    {
        UserCosmeticLoadout::where('user_id', $user->id)->where('slot', $slot)->delete();
    }

    public function unequipItemIfNecessary(User $user, StoreItem $item): void
    {
        UserCosmeticLoadout::where('user_id', $user->id)->where('store_item_id', $item->id)->delete();
    }

    public function loadoutFor(User $user): array
    {
        $rows = $user->cosmeticLoadouts()->with('storeItem')->get()->keyBy('slot');

        $result = [];
        foreach (StoreItem::COSMETIC_SLOTS as $slot) {
            $result[$slot] = $rows->get($slot)?->storeItem;
        }

        return $result;
    }

    public function equippedForSlot(User $user, string $slot): ?StoreItem
    {
        return $user->cosmeticLoadouts()->where('slot', $slot)->first()?->storeItem;
    }

    public function canEquip(User $user, StoreItem $item): bool
    {
        try {
            $this->assertCanEquip($user, $item);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    protected function assertCanEquip(User $user, StoreItem $item): void
    {
        if ($item->item_type !== StoreItem::TYPE_COSMETIC) {
            throw new \RuntimeException('هذا العنصر ليس عنصرًا تجميليًا قابلًا للتجهيز.');
        }

        if ($item->fulfillment_type !== StoreItem::FULFILLMENT_INVENTORY) {
            throw new \RuntimeException('هذا العنصر التجميلي غير مُعَدٍّ للتسليم كمخزون - لا يمكن تجهيزه.');
        }

        if (blank($item->cosmetic_slot)) {
            throw new \RuntimeException('هذا العنصر التجميلي قديم ولا يملك فتحة (Slot) محدَّدة بعد - يحتاج تحديث الإدارة أولًا.');
        }

        $owned = UserInventoryItem::where('user_id', $user->id)->where('store_item_id', $item->id)->value('quantity') ?? 0;

        if ($owned <= 0) {
            throw new \RuntimeException('لا تمتلك هذا العنصر التجميلي.');
        }
    }
}
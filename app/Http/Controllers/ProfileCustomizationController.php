<?php

namespace App\Http\Controllers;

use App\Models\StoreItem;
use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileCustomizationController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected InventoryService $inventory,
    ) {}

    public function edit()
    {
        $user = Auth::user();

        $ownedCosmeticItemIds = $user->inventoryItems()
            ->where('quantity', '>', 0)
            ->whereHas('item', fn ($q) => $q->where('item_type', StoreItem::TYPE_COSMETIC))
            ->pluck('store_item_id');

        $ownedCosmetics = StoreItem::whereIn('id', $ownedCosmeticItemIds)->get()->groupBy('cosmetic_slot');

        $currentLoadout = $this->loadouts->loadoutFor($user);

        return view('profile.customize', [
            'slots' => StoreItem::COSMETIC_SLOTS,
            'ownedCosmetics' => $ownedCosmetics,
            'currentLoadout' => $currentLoadout,
        ]);
    }

    public function equip(Request $request, StoreItem $item)
    {
        try {
            $this->loadouts->equip($request->user(), $item);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تم تجهيز العنصر بنجاح.');
    }

    public function unequip(Request $request, string $slot)
    {
        if (! in_array($slot, StoreItem::COSMETIC_SLOTS, true)) {
            abort(404);
        }

        $this->loadouts->unequip($request->user(), $slot);

        return back()->with('success', 'تمت إزالة العنصر.');
    }

    public function updateVisibility(Request $request)
    {
        $data = $request->validate([
            'profile_visibility' => ['required', 'string', 'in:'.implode(',', User::VISIBILITIES)],
        ]);

        $request->user()->update(['profile_visibility' => $data['profile_visibility']]);

        return back()->with('success', 'تم تحديث خصوصية الملف الشخصي.');
    }
}
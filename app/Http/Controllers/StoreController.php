<?php

namespace App\Http\Controllers;

use App\Models\CurrencyPack;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function __construct(protected StorePurchaseService $purchases) {}

    public function index()
    {
        $packs = CurrencyPack::with('currency')
            ->whereHas('currency', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (CurrencyPack $pack) => $pack->isCurrentlyAvailable())
            ->sortBy('sort_order');

        $items = StoreItem::with(['activePrices.currency'])
            ->where('is_active', true)
            ->get()
            ->filter(fn (StoreItem $item) => $item->isPubliclyVisible())
            ->sortBy('sort_order');

        return view('store.index', compact('packs', 'items'));
    }

    public function show(StoreItem $item)
    {
        abort_unless($item->isPubliclyVisible(), 404);

        $item->load('activePrices.currency');

        $ownedQuantity = null;
        $hasEntitlement = null;

        if (auth()->check()) {
            if ($item->fulfillment_type === StoreItem::FULFILLMENT_INVENTORY) {
                $ownedQuantity = app(InventoryService::class)->quantityFor(auth()->user(), $item);
            }

            if ($item->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT && filled($item->entitlement_key)) {
                $hasEntitlement = app(EntitlementService::class)->hasActive(auth()->user(), $item->entitlement_key);
            }
        }

        $requestKey = (string) Str::uuid();

        return view('store.show', compact('item', 'ownedQuantity', 'hasEntitlement', 'requestKey'));
    }

    public function purchase(Request $request, StoreItem $item)
    {
        $data = $request->validate([
            'price_id' => ['required', 'integer'],
            'request_key' => ['required', 'string', 'max:100'],
        ]);

        $price = StoreItemPrice::where('id', $data['price_id'])->where('store_item_id', $item->id)->firstOrFail();

        try {
            $this->purchases->purchase($request->user(), $item, $price, $data['request_key']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.index')->with('success', 'تم الشراء بنجاح.');
    }
}
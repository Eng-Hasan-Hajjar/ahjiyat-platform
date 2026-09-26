<?php

use App\Models\CurrencyTransaction;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->purchases = app(StorePurchaseService::class);
    $this->wallets = app(CurrencyWalletService::class);
});

test('refunding a purchase credits back the exact original currency and amount', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 150]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $this->purchases->refund($purchase, $admin, 'اختبار');

        expect($this->wallets->balanceFor($user, $price->currency)->available_balance)->toBe(1000)
        ->and($purchase->fresh()->status)->toBe(StorePurchase::STATUS_REFUNDED)
        ->and(CurrencyTransaction::where('reference_type', StorePurchase::class)
            ->where('reference_id', $purchase->id)->where('type', CurrencyTransaction::TYPE_REFUND)->exists())->toBeTrue();
});

test('a double refund is rejected', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 100]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());
    $this->purchases->refund($purchase, $admin, 'أول مرة');

    expect(fn () => $this->purchases->refund($purchase->fresh(), $admin, 'ثاني مرة'))
        ->toThrow(RuntimeException::class);
});

test('refunding an inventory purchase reverses the exact granted quantity without going negative', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->create(['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 4]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $this->purchases->refund($purchase, $admin, 'اختبار');

    expect(app(InventoryService::class)->quantityFor($user, $item))->toBe(0);
});

test('refunding an entitlement purchase revokes the entitlement while keeping its history', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip')->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $this->purchases->refund($purchase, $admin, 'اختبار');

    expect(app(EntitlementService::class)->hasActive($user, 'season.vip'))->toBeFalse()
        ->and(\App\Models\UserEntitlement::where('store_purchase_id', $purchase->id)->first()->revoked_at)->not->toBeNull();
});

test('a manual purchase already fulfilled cannot be automatically refunded - fail-safe', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());
    $this->purchases->fulfillManually($purchase, $admin);

    expect(fn () => $this->purchases->refund($purchase->fresh(), $admin, 'اختبار'))
        ->toThrow(RuntimeException::class);
});

test('a pending manual purchase can still be refunded normally', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $this->purchases->refund($purchase, $admin, 'اختبار');

    expect($purchase->fresh()->status)->toBe(StorePurchase::STATUS_REFUNDED);
});

test('changing the price after purchase does not affect the historical purchase or its refund amount', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 100]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $price->update(['amount' => 999]);

    expect($purchase->fresh()->price_amount)->toBe(100);

    $this->purchases->refund($purchase->fresh(), $admin, 'اختبار');

    expect($this->wallets->balanceFor($user, $price->currency)->available_balance)->toBe(1000);
});

test('renaming the item after purchase does not change the historical snapshot', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['name' => 'الاسم الأصلي']);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $item->update(['name' => 'اسم جديد تمامًا']);

    expect($purchase->fresh()->item_snapshot['name'])->toBe('الاسم الأصلي');
});
<?php

use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->purchases = app(StorePurchaseService::class);
});

test('purchasing a manual item leaves it pending_fulfillment with no inventory or entitlement granted', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 100, 'test');

    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect($purchase->status)->toBe(StorePurchase::STATUS_PENDING_FULFILLMENT)
        ->and(\App\Models\UserInventoryItem::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(\App\Models\UserEntitlement::where('user_id', $user->id)->exists())->toBeFalse();
});

test('an authorized admin can fulfill a manual purchase exactly once', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $this->purchases->fulfillManually($purchase, $admin, 'تم الشحن', 'وصلتك جائزتك');

    expect($purchase->fresh()->status)->toBe(StorePurchase::STATUS_FULFILLED)
        ->and($purchase->fresh()->fulfilled_by)->toBe($admin->id);

    expect(fn () => $this->purchases->fulfillManually($purchase->fresh(), $admin, null, null))
        ->toThrow(RuntimeException::class);
});

test('an unauthorized user gets 403 on the fulfill action route-level authorization', function () {
    $user = User::factory()->create();
    $unauthorized = User::factory()->create();
    $unauthorized->assignRole('player');
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 100, 'test');
    $purchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect($unauthorized->can('fulfill', $purchase))->toBeFalse();
});
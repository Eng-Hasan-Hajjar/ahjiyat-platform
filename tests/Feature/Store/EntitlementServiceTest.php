<?php

use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\EntitlementService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->entitlements = app(EntitlementService::class);
});

test('purchasing an entitlement item grants an active entitlement with the correct key', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip')->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 100, 'test');

    app(StorePurchaseService::class)->purchase($user, $item, $price, (string) Str::uuid());

    expect($this->entitlements->hasActive($user, 'season.vip'))->toBeTrue();
});

test('a permanent entitlement already active cannot be purchased a second time', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip')->create(['entitlement_duration_days' => null]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 1000, 'test');

    app(StorePurchaseService::class)->purchase($user, $item, $price, (string) Str::uuid());

    expect(fn () => app(StorePurchaseService::class)->purchase($user, $item, $price->fresh(), (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});

test('a temporary entitlement computes expires_at from the duration in days', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->entitlement('event.access')->create(['entitlement_duration_days' => 30]);

    $entitlement = $this->entitlements->grant($user, $item);

    expect($entitlement->expires_at)->not->toBeNull()
        ->and($entitlement->expires_at->diffInDays(now(), absolute: true))->toBeGreaterThanOrEqual(29)
        ->and($entitlement->expires_at->diffInDays(now(), absolute: true))->toBeLessThanOrEqual(30);
});

test('revoking an entitlement makes hasActive false while keeping its history row', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip')->create();
    $entitlement = $this->entitlements->grant($user, $item);
    $admin = User::factory()->create();

    $this->entitlements->revoke($entitlement, $admin, 'اختبار');

    expect($this->entitlements->hasActive($user, 'season.vip'))->toBeFalse()
        ->and($entitlement->fresh()->revoked_at)->not->toBeNull()
        ->and(\App\Models\UserEntitlement::find($entitlement->id))->not->toBeNull();
});
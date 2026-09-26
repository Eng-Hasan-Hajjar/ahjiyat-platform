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
    $this->wallets = app(CurrencyWalletService::class);
});

test('per_user_limit blocks a purchase once reached, but does not affect other users', function () {
    $item = StoreItem::factory()->create(['per_user_limit' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $this->wallets->creditAvailable($userA, $price->currency, 100, 'test');
    $this->wallets->creditAvailable($userB, $price->currency, 100, 'test');

    $this->purchases->purchase($userA, $item, $price, (string) Str::uuid());

    expect(fn () => $this->purchases->purchase($userA, $item, $price->fresh(), (string) Str::uuid()))
        ->toThrow(RuntimeException::class);

    $this->purchases->purchase($userB, $item, $price->fresh(), (string) Str::uuid());

    expect(StorePurchase::where('user_id', $userA->id)->count())->toBe(1)
        ->and(StorePurchase::where('user_id', $userB->id)->count())->toBe(1);
});

test('with no per_user_limit set, a user can purchase the same item repeatedly', function () {
    $item = StoreItem::factory()->create(['per_user_limit' => null]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $user = User::factory()->create();
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');

    $this->purchases->purchase($user, $item, $price->fresh(), (string) Str::uuid());
    $this->purchases->purchase($user, $item, $price->fresh(), (string) Str::uuid());

    expect(StorePurchase::where('user_id', $user->id)->count())->toBe(2);
});
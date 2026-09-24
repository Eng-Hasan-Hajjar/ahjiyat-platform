<?php

use App\Models\Currency;
use App\Models\CurrencyPack;

test('a pack total amount is base plus bonus, always derived not stored', function () {
    $pack = CurrencyPack::factory()->create(['base_amount' => 500, 'bonus_amount' => 100]);

    expect($pack->totalAmount())->toBe(600);
});

test('a pack tied to an inactive currency is not currently available', function () {
    $currency = Currency::factory()->create(['is_active' => false, 'is_purchasable' => true]);
    $pack = CurrencyPack::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    expect($pack->isCurrentlyAvailable())->toBeFalse();
});

test('an inactive pack is never available even if the currency is active', function () {
    $currency = Currency::factory()->create(['is_active' => true, 'is_purchasable' => true]);
    $pack = CurrencyPack::factory()->create(['currency_id' => $currency->id, 'is_active' => false]);

    expect($pack->isCurrentlyAvailable())->toBeFalse();
});

test('the store page only lists currently-available packs', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $activeCurrency = Currency::factory()->create(['is_active' => true, 'is_purchasable' => true]);
    $inactiveCurrency = Currency::factory()->create(['is_active' => false, 'is_purchasable' => true]);

    CurrencyPack::factory()->create(['currency_id' => $activeCurrency->id, 'name' => 'حزمة ظاهرة', 'is_active' => true]);
    CurrencyPack::factory()->create(['currency_id' => $inactiveCurrency->id, 'name' => 'حزمة مخفية', 'is_active' => true]);

    $response = $this->get('/store');

    $response->assertSee('حزمة ظاهرة')->assertDontSee('حزمة مخفية');
});

test('the store page has no purchase/checkout form - display only', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    CurrencyPack::factory()->create(['is_active' => true]);

    $response = $this->get('/store');

    $response->assertDontSee('<form', false);
});
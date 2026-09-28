<?php

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('a public profile response never leaks the email address anywhere in the HTML', function () {
    $player = User::factory()->create([
        'profile_visibility' => User::VISIBILITY_PUBLIC,
        'email' => 'super-secret-address@example.com',
    ]);

    $this->get(route('players.show', $player))
        ->assertOk()
        ->assertDontSee('super-secret-address@example.com', false)
        ->assertDontSee('@example.com', false);
});

test('a public profile response never leaks role names, permissions, or admin-only fields', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $player->assignRole('administrator');

    $response = $this->get(route('players.show', $player));

    $response->assertOk()
        ->assertDontSee('administrator', false)
        ->assertDontSee('wallet.adjust', false)
        ->assertDontSee('economy.currencies', false);
});

test('a public profile response never leaks currency balance or any wallet figure', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    app(\App\Services\Economy\CurrencyWalletService::class)->creditAvailable(
        $player,
        app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency(),
        123456,
        'test'
    );

    $this->get(route('players.show', $player))->assertOk()->assertDontSee('123456', false);
});

test('a public profile response never leaks a fraud flag reason or resolved status', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    \App\Models\FraudFlag::create(['user_id' => $player->id, 'reason' => 'نشاط مشبوه للغاية', 'severity' => 'high', 'resolved' => false]);

    $this->get(route('players.show', $player))->assertOk()->assertDontSee('نشاط مشبوه للغاية');
});

test('a public profile response never leaks the frozen reason for a frozen user', function () {
    $player = User::factory()->create([
        'profile_visibility' => User::VISIBILITY_PUBLIC,
        'is_frozen' => true,
        'frozen_reason' => 'سبب تجميد داخلي سري',
    ]);

    $this->get(route('players.show', $player))->assertOk()->assertDontSee('سبب تجميد داخلي سري');
});

test('a public profile response never includes the internal sequential id in a way that substitutes for public_id', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);

    $url = route('players.show', $player);

    expect($url)->toContain($player->public_id)
        ->and($url)->not->toContain('/players/'.$player->id.'/')
        ->and($url)->not->toEndWith('/players/'.$player->id);
});
<?php

use App\Models\GemTransaction;
use App\Models\User;
use App\Services\Analytics\EconomyAnalyticsService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('issued gems are calculated from the transaction ledger, not wallet balances', function () {
    $user = User::factory()->create();
    GemTransaction::create(['user_id' => $user->id, 'amount' => 100, 'type' => GemTransaction::TYPE_EARN_PENDING, 'reason' => 'test', 'created_at' => now()]);
    GemTransaction::create(['user_id' => $user->id, 'amount' => 50, 'type' => GemTransaction::TYPE_EARN_PENDING, 'reason' => 'test', 'created_at' => now()]);

    $overview = app(EconomyAnalyticsService::class)->gemsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['issued_in_period'])->toBe(150);
});

test('spent gems reflect redeem-type transactions correctly', function () {
    $user = User::factory()->create();
    GemTransaction::create(['user_id' => $user->id, 'amount' => -80, 'type' => GemTransaction::TYPE_REDEEM, 'reason' => 'test', 'created_at' => now()]);

    $overview = app(EconomyAnalyticsService::class)->gemsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['spent_in_period'])->toBe(80);
});

test('manual adjustments are tracked as a separate category from earned/spent gems', function () {
    $user = User::factory()->create();
    GemTransaction::create(['user_id' => $user->id, 'amount' => 200, 'type' => GemTransaction::TYPE_EARN_PENDING, 'reason' => 'test', 'created_at' => now()]);
    GemTransaction::create(['user_id' => $user->id, 'amount' => 30, 'type' => GemTransaction::TYPE_ADMIN_ADJUSTMENT, 'reason' => 'تعويض', 'created_at' => now()]);

    $overview = app(EconomyAnalyticsService::class)->gemsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['issued_in_period'])->toBe(200)
        ->and($overview['manual_adjustments_in_period'])->toBe(30);
});

test('total gems in wallets reflects current balances, not issuance history', function () {
    $user = User::factory()->create();
    \App\Models\Wallet::updateOrCreate(['user_id' => $user->id], ['available_balance' => 40, 'pending_balance' => 10]);

    $overview = app(EconomyAnalyticsService::class)->gemsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['total_in_wallets'])->toBe(50);
});

test('wallet balance distribution buckets are mutually exclusive and cover all wallets', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();
    \App\Models\Wallet::updateOrCreate(['user_id' => $u1->id], ['available_balance' => 0]);
    \App\Models\Wallet::updateOrCreate(['user_id' => $u2->id], ['available_balance' => 50]);
    \App\Models\Wallet::updateOrCreate(['user_id' => $u3->id], ['available_balance' => 600]);

    $distribution = app(EconomyAnalyticsService::class)->walletBalanceDistribution();

    expect(array_sum($distribution))->toBe(3)
        ->and($distribution['0'])->toBe(1)
        ->and($distribution['1-100'])->toBe(1)
        ->and($distribution['500+'])->toBe(1);
});
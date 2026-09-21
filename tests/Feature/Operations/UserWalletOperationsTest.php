<?php

use App\Models\GemTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GemWalletService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('wallet balance is only visible to a role with users.view_wallet', function () {
    $withPermission = User::factory()->create();
    $withPermission->assignRole('administrator');

    $withoutPermission = User::factory()->create();
    $withoutPermission->assignRole('support');

    expect($withPermission->can('users.view_wallet'))->toBeTrue()
        ->and($withoutPermission->can('users.view_wallet'))->toBeFalse();
});

test('a manual gem adjustment requires wallet.adjust permission', function () {
    $withPermission = User::factory()->create();
    $withPermission->assignRole('administrator');

    $withoutPermission = User::factory()->create();
    $withoutPermission->assignRole('content-manager');

    expect($withPermission->can('wallet.adjust'))->toBeTrue()
        ->and($withoutPermission->can('wallet.adjust'))->toBeFalse();
});

test('a manual gem adjustment creates a real GemTransaction and updates the balance through the service', function () {
    $target = User::factory()->create();
    Wallet::factory()->create(['user_id' => $target->id, 'available_balance' => 100]);

    app(GemWalletService::class)->adjustAvailable($target, 50, 'تعويض دعم فني');

    expect($target->wallet->fresh()->available_balance)->toBe(150)
        ->and(GemTransaction::where('user_id', $target->id)->where('type', GemTransaction::TYPE_ADMIN_ADJUSTMENT)->where('amount', 50)->exists())->toBeTrue();
});

test('a manual gem deduction works and creates a negative-amount transaction', function () {
    $target = User::factory()->create();
    Wallet::factory()->create(['user_id' => $target->id, 'available_balance' => 100]);

    app(GemWalletService::class)->adjustAvailable($target, -30, 'تصحيح رصيد');

    expect($target->wallet->fresh()->available_balance)->toBe(70)
        ->and(GemTransaction::where('user_id', $target->id)->where('amount', -30)->exists())->toBeTrue();
});

test('a manual adjustment that would push the balance negative is prevented', function () {
    $target = User::factory()->create();
    Wallet::factory()->create(['user_id' => $target->id, 'available_balance' => 20]);

    expect(fn () => app(GemWalletService::class)->adjustAvailable($target, -50, 'خصم كبير'))
        ->toThrow(\RuntimeException::class);

    expect($target->wallet->fresh()->available_balance)->toBe(20);
});

test('a zero-amount adjustment is rejected', function () {
    $target = User::factory()->create();
    Wallet::factory()->create(['user_id' => $target->id, 'available_balance' => 20]);

    expect(fn () => app(GemWalletService::class)->adjustAvailable($target, 0, 'بلا قيمة'))
        ->toThrow(\InvalidArgumentException::class);
});
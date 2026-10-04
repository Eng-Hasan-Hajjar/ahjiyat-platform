<?php

use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;

test('E14.1-Q/36/37: AdminUserSeeder and UserSeeder create wallets with the default earned currency, idempotently', function () {
    $this->seed(\Database\Seeders\AdminUserSeeder::class);
    $this->seed(\Database\Seeders\UserSeeder::class);

    $default = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $users = User::count();
    $wallets = Wallet::count();

    expect($users)->toBeGreaterThan(1)
        ->and($wallets)->toBe($users)
        ->and(Wallet::whereNull('currency_id')->count())->toBe(0)
        ->and(Wallet::where('currency_id', '!=', $default->id)->count())->toBe(0);

    // إعادة التشغيل: لا مستخدمين مكررين، لا محافظ مكررة، لا محفظة بلا عملة.
    $this->seed(\Database\Seeders\AdminUserSeeder::class);
    $this->seed(\Database\Seeders\UserSeeder::class);

    expect(User::count())->toBe($users)
        ->and(Wallet::count())->toBe($wallets)
        ->and(Wallet::whereNull('currency_id')->count())->toBe(0);
});

test('a seeded user can open the wallet page (the original crash: wallet without currency)', function () {
    $this->seed(\Database\Seeders\AdminUserSeeder::class);

    $this->actingAs(User::where('email', 'admin@ahjiyat.app')->first())->get(route('wallet.index'))->assertOk();
});

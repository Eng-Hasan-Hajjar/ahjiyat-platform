<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency_id' => fn () => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id,
            'pending_balance' => 0,
            'available_balance' => 0,
            'lifetime_earned' => 0,
            'lifetime_redeemed' => 0,
        ];
    }
}
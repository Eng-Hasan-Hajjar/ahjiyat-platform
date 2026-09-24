<?php

namespace Database\Factories;

use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class RedemptionRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency_id' => fn () => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id,
            'gems_amount' => $this->faker->numberBetween(10, 500),
            'reward_description' => $this->faker->sentence(4),
            'status' => RedemptionRequest::STATUS_PENDING,
        ];
    }
}
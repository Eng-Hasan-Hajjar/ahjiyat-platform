<?php

namespace Database\Factories;

use App\Models\RedemptionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RedemptionRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gems_amount' => $this->faker->numberBetween(10, 500),
            'reward_description' => $this->faker->sentence(4),
            'status' => RedemptionRequest::STATUS_PENDING,
        ];
    }
}
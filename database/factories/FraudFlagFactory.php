<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FraudFlagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reason' => $this->faker->randomElement(['ip_shared', 'abnormal_earn_rate']),
            'severity' => $this->faker->randomElement(['low', 'medium', 'high']),
            'details' => $this->faker->sentence(),
            'resolved' => false,
        ];
    }
}
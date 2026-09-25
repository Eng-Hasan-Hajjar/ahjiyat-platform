<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserEntitlementFactory extends Factory
{
    protected $model = UserEntitlement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => 'test.entitlement.'.$this->faker->unique()->word(),
            'starts_at' => now(),
        ];
    }
}
<?php

namespace Database\Factories;

use App\Models\PlayerStreak;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlayerStreakFactory extends Factory
{
    protected $model = PlayerStreak::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'current_streak' => 0,
            'longest_streak' => 0,
        ];
    }
}

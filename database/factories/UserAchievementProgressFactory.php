<?php

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievementProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserAchievementProgressFactory extends Factory
{
    protected $model = UserAchievementProgress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'achievement_id' => Achievement::factory(),
            'current_value' => 0,
        ];
    }

    public function unlocked(): static
    {
        return $this->state(fn () => ['unlocked_at' => now()]);
    }
}
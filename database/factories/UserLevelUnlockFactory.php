<?php

namespace Database\Factories;

use App\Models\LevelDefinition;
use App\Models\User;
use App\Models\UserLevelUnlock;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserLevelUnlockFactory extends Factory
{
    protected $model = UserLevelUnlock::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'level_definition_id' => LevelDefinition::factory(),
            'unlocked_at' => now(),
        ];
    }
}
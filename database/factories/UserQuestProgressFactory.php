<?php

namespace Database\Factories;

use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserQuestProgressFactory extends Factory
{
    protected $model = UserQuestProgress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quest_definition_id' => QuestDefinition::factory(),
            'period_type' => QuestDefinition::PERIOD_DAILY,
            'period_key' => 'daily:'.now()->format('Y-m-d'),
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'current_value' => 0,
            'target_value_snapshot' => 1,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['completed_at' => now()]);
    }

    public function rewarded(): static
    {
        return $this->state(fn () => ['completed_at' => now(), 'reward_granted_at' => now()]);
    }
}

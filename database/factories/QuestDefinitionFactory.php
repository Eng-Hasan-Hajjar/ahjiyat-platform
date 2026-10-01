<?php

namespace Database\Factories;

use App\Models\QuestDefinition;
use App\Services\Engagement\QuestEvaluatorRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestDefinitionFactory extends Factory
{
    protected $model = QuestDefinition::class;

    public function definition(): array
    {
        $name = 'مهمة '.$this->faker->unique()->word();

        return [
            'internal_key' => 'test_quest_'.str()->slug($name).'_'.$this->faker->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'description' => 'وصف تجريبي.',
            'period_type' => QuestDefinition::PERIOD_DAILY,
            'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
            'target_value' => 1,
            'xp_reward' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function daily(int $target = 1): static
    {
        return $this->state(fn () => [
            'period_type' => QuestDefinition::PERIOD_DAILY,
            'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
            'target_value' => $target,
        ]);
    }

    public function weekly(int $target = 1): static
    {
        return $this->state(fn () => [
            'period_type' => QuestDefinition::PERIOD_WEEKLY,
            'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
            'target_value' => $target,
        ]);
    }

    public function puzzlesSolvedInCategory(int $categoryId, int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY,
            'target_value' => $target,
            'scope_type' => 'puzzle_category',
            'scope_id' => $categoryId,
        ]);
    }

    public function campaignStepsCompleted(int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => QuestEvaluatorRegistry::CAMPAIGN_STEPS_COMPLETED,
            'target_value' => $target,
        ]);
    }

    public function qualificationsEarned(int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => QuestEvaluatorRegistry::QUALIFICATIONS_EARNED,
            'target_value' => $target,
        ]);
    }

    public function withXpReward(int $amount): static
    {
        return $this->state(fn () => ['xp_reward' => $amount]);
    }
}

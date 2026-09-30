<?php

namespace Database\Factories;

use App\Models\Achievement;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    public function definition(): array
    {
        $name = 'إنجاز '.$this->faker->unique()->word();

        return [
            'internal_key' => 'test_'.str()->slug($name).'_'.$this->faker->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'description' => 'وصف تجريبي.',
            'category' => Achievement::CATEGORY_GENERAL,
            'condition_type' => AchievementEvaluatorRegistry::PUZZLES_SOLVED_TOTAL,
            'target_value' => 1,
            'xp_reward' => 0,
            'is_active' => true,
            'is_hidden' => false,
            'sort_order' => 0,
        ];
    }

    public function puzzlesSolvedTotal(int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => AchievementEvaluatorRegistry::PUZZLES_SOLVED_TOTAL,
            'target_value' => $target,
        ]);
    }

    public function puzzlesSolvedInCategory(int $categoryId, int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => AchievementEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY,
            'target_value' => $target,
            'scope_type' => 'puzzle_category',
            'scope_id' => $categoryId,
        ]);
    }

    public function qualificationsEarned(int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => AchievementEvaluatorRegistry::QUALIFICATIONS_EARNED_TOTAL,
            'target_value' => $target,
        ]);
    }

    public function campaignStepsCompleted(int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => AchievementEvaluatorRegistry::CAMPAIGN_STEPS_COMPLETED_TOTAL,
            'target_value' => $target,
        ]);
    }

    public function campaignsCompleted(int $target = 1): static
    {
        return $this->state(fn () => [
            'condition_type' => AchievementEvaluatorRegistry::CAMPAIGNS_COMPLETED_TOTAL,
            'target_value' => $target,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_hidden' => true]);
    }

    public function withXpReward(int $amount): static
    {
        return $this->state(fn () => ['xp_reward' => $amount]);
    }
}
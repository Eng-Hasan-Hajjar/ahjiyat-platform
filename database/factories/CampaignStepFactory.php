<?php

namespace Database\Factories;

use App\Models\CampaignGate;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignStepFactory extends Factory
{
    protected $model = CampaignStep::class;

    public function definition(): array
    {
        return [
            'campaign_gate_id' => CampaignGate::factory(),
            'kind' => CampaignStep::KIND_NARRATIVE,
            'sort_order' => 1,
            'title' => fake()->sentence(2),
            'reward_mode' => CampaignStep::REWARD_MODE_INHERIT,
        ];
    }

    /** خطوة من نوع puzzle جاهزة لإعادة الاستخدام بـC2-C8 - تربط puzzle_id تلقائياً. */
    public function puzzle(): static
    {
        return $this->state(fn () => [
            'kind' => CampaignStep::KIND_PUZZLE,
            'puzzle_id' => Puzzle::factory(),
        ]);
    }
}
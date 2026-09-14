<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignStage;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignStageFactory extends Factory
{
    protected $model = CampaignStage::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'title' => fake()->sentence(2),
            'sort_order' => 1,
        ];
    }
}
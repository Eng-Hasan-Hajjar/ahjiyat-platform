<?php

namespace Database\Factories;

use App\Models\CampaignGate;
use App\Models\CampaignStage;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignGateFactory extends Factory
{
    protected $model = CampaignGate::class;

    public function definition(): array
    {
        return [
            'campaign_stage_id' => CampaignStage::factory(),
            'title' => fake()->sentence(2),
            'sort_order' => 1,
        ];
    }
}
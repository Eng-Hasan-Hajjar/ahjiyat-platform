<?php

namespace Database\Factories;

use App\Models\CampaignGate;
use App\Models\CampaignGateQualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignGateQualificationFactory extends Factory
{
    protected $model = CampaignGateQualification::class;

    public function definition(): array
    {
        return [
            'campaign_gate_id' => CampaignGate::factory(),
            'user_id' => User::factory(),
            'qualified_at' => now(),
        ];
    }
}
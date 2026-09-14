<?php

namespace Database\Factories;

use App\Models\CampaignStep;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserCampaignProgressFactory extends Factory
{
    protected $model = UserCampaignProgress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'campaign_step_id' => CampaignStep::factory(),
            'started_at' => now(),
        ];
    }
}
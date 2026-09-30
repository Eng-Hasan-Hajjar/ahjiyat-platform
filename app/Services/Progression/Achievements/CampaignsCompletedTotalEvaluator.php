<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\Campaign;
use App\Models\User;
use App\Services\CampaignProgressService;
use App\Services\Progression\AchievementEvaluatorRegistry;

class CampaignsCompletedTotalEvaluator implements AchievementEvaluator
{
    public function __construct(protected CampaignProgressService $progress) {}

    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::CAMPAIGNS_COMPLETED_TOTAL;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        $campaigns = Campaign::with('stages.gates.steps')->get();

        return $campaigns->filter(fn (Campaign $campaign) => $this->progress->isCampaignCompleted($user, $campaign))->count();
    }
}
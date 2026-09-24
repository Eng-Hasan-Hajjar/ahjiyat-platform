<?php

namespace App\Services;

use App\GameEngine\Support\AttemptContext;
use App\GameEngine\Support\RewardDirective;
use App\Models\CampaignStep;
use App\Models\Puzzle;

class AttemptRewardResolver
{
    public function resolve(Puzzle $puzzle, AttemptContext $context): RewardDirective
    {
        if (! $context->isPresent() || $context->type !== AttemptContext::TYPE_CAMPAIGN_STEP) {
            return RewardDirective::useDefault();
        }

        $step = CampaignStep::find($context->id);

        if ($step === null || $step->kind !== CampaignStep::KIND_PUZZLE || $step->puzzle_id !== $puzzle->id) {
            return RewardDirective::useDefault();
        }

        return match ($step->reward_mode) {
            CampaignStep::REWARD_MODE_OVERRIDE => RewardDirective::fixed(
                (int) $step->reward_override_amount,
                $step->rewardCurrency,
            ),
            CampaignStep::REWARD_MODE_NONE => RewardDirective::none(),
            default => RewardDirective::useDefault(),
        };
    }
}
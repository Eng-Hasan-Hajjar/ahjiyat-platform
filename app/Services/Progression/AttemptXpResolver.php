<?php

namespace App\Services\Progression;

use App\GameEngine\Support\AttemptContext;
use App\GameEngine\Support\XpDirective;
use App\Models\CampaignStep;
use App\Models\Puzzle;

class AttemptXpResolver
{
    public function resolve(Puzzle $puzzle, AttemptContext $context): XpDirective
    {
        if (! $context->isPresent() || $context->type !== AttemptContext::TYPE_CAMPAIGN_STEP) {
            return XpDirective::useDefault();
        }

        $step = CampaignStep::find($context->id);

        if ($step === null || $step->kind !== CampaignStep::KIND_PUZZLE || $step->puzzle_id !== $puzzle->id) {
            return XpDirective::useDefault();
        }

        return match ($step->xp_mode) {
            CampaignStep::XP_MODE_OVERRIDE => XpDirective::fixed((int) $step->xp_override_amount),
            CampaignStep::XP_MODE_NONE => XpDirective::none(),
            default => XpDirective::useDefault(),
        };
    }
}
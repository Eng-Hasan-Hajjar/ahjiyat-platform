<?php

namespace App\Services;

use App\GameEngine\Support\AttemptContext;
use App\GameEngine\Support\RewardDirective;
use App\Models\CampaignStep;
use App\Models\Puzzle;

/**
 * يقرر Server-side ماذا تفعل عملية حل معينة بالمكافأة. Standalone
 * (Context غائبة) تتبع السلوك الافتراضي دائماً - صفر تغيير على سلوكها
 * القديم. Campaign Step تُحل حسب reward_mode المخزَّنة على CampaignStep
 * نفسها (inherit/override/none) - أبداً لا يُقرأ أي شيء من العميل.
 *
 * حماية C5.7: إن أشار Context لخطوة، لكن تلك الخطوة مربوطة بأحجية مختلفة
 * عن الأحجية المُحلولة فعلياً (Context Mismatch) - Fail-safe للسلوك
 * الافتراضي (لا Override "مسروق" أبداً).
 */
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
            CampaignStep::REWARD_MODE_OVERRIDE => RewardDirective::fixed((int) $step->reward_override_amount),
            CampaignStep::REWARD_MODE_NONE => RewardDirective::none(),
            default => RewardDirective::useDefault(), // inherit، أو أي قيمة غير متوقعة - أمان بالافتراضي
        };
    }
}
<?php

namespace App\Services\Progression;

use App\GameEngine\Support\AttemptContext;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\XpTransaction;
use Illuminate\Support\Facades\Log;

class GameplayProgressionService
{
    public function __construct(
        protected XpService $xp,
        protected AttemptXpResolver $xpResolver,
        protected AchievementService $achievements,
    ) {}

    public function afterPuzzleSolved(User $user, PuzzleAttempt $attempt, Puzzle $puzzle, AttemptContext $context): void
    {
        try {
            $directive = $this->xpResolver->resolve($puzzle, $context);

            $amount = $directive->useDefault
                ? ($puzzle->xp_reward ?? (int) config('progression.default_puzzle_xp'))
                : $directive->amount;

            if ($amount > 0) {
                $type = $context->isPresent() ? XpTransaction::TYPE_CAMPAIGN_STEP : XpTransaction::TYPE_PUZZLE_SOLVE;

                $this->xp->grantXp($user, $amount, $type, "puzzle:{$puzzle->id}", $attempt, "puzzle-solve:{$attempt->id}");
            }
        } catch (\Throwable $e) {
            Log::error('فشل منح XP لحل أحجية', ['attempt_id' => $attempt->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $this->achievements->evaluateForEvent('puzzle_solved', $user);

        if ($context->isPresent() && $context->type === AttemptContext::TYPE_CAMPAIGN_STEP) {
            $this->achievements->evaluateForEvent('campaign_step_completed', $user);
        }
    }

    public function afterCampaignStepCompleted(User $user, CampaignStep $step, UserCampaignProgress $progress): void
    {
        try {
            $amount = match ($step->xp_mode) {
                CampaignStep::XP_MODE_OVERRIDE => (int) $step->xp_override_amount,
                default => 0,
            };

            if ($amount > 0) {
                $this->xp->grantXp(
                    $user,
                    $amount,
                    XpTransaction::TYPE_CAMPAIGN_STEP,
                    "campaign_step:{$step->id}",
                    $progress,
                    "campaign-step-complete:{$progress->id}",
                );
            }
        } catch (\Throwable $e) {
            Log::error('فشل منح XP لإكمال خطوة حملة', ['progress_id' => $progress->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $this->achievements->evaluateForEvent('campaign_step_completed', $user);
    }

    public function afterQualificationEarned(User $user): void
    {
        $this->achievements->evaluateForEvent('qualification_earned', $user);
    }
}
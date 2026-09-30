<?php

namespace App\Services;

use App\Models\CampaignGate;
use App\Models\CampaignStep;
use App\Models\User;
use App\Services\Progression\GameplayProgressionService;
use App\Services\Qualification\FirstNQualificationRule;
use App\Services\Qualification\QualificationRule;

class QualificationService
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected GameplayProgressionService $progression,
    ) {}

    public function ruleFor(CampaignGate $gate): ?QualificationRule
    {
        return match ($gate->qualification_rule) {
            'first_n' => app(FirstNQualificationRule::class),
            default => null,
        };
    }

    public function afterStepCompletion(User $user, CampaignStep $step): void
    {
        $gate = $step->gate;
        $rule = $this->ruleFor($gate);

        if ($rule === null) {
            return;
        }

        if (! $this->progress->isGateCompleted($user, $gate)) {
            return;
        }

        $rule->qualify($user, $gate);

        $this->progression->afterQualificationEarned($user);
    }

    public function isUserQualified(User $user, CampaignGate $gate): bool
    {
        $rule = $this->ruleFor($gate);

        return $rule === null || $rule->isQualified($user, $gate);
    }
}
<?php

namespace App\Services;

use App\GameEngine\Support\AttemptContext;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignGateQualification;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\GameSession;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Support\Collection;

/**
 * الخدمة الوحيدة المسؤولة عن اشتقاق حالة الحملة بالكامل - Store Facts,
 * Derive States. لا Writes هون إطلاقاً، ولا استدعاء لأي خدمة أخرى (Reward
 * Resolver/QualificationService تعتمدان عليها هي، لا العكس - لتفادي أي
 * Dependency دائري).
 */
class CampaignProgressService
{
    public const STATE_COMPLETED = 'completed';

    public const STATE_LOCKED = 'locked';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_AVAILABLE = 'available';

    public const STATE_NOT_QUALIFIED = 'not_qualified';

    // ===================================================================
    // Campaign Availability
    // ===================================================================

    public function isCampaignAvailable(Campaign $campaign): bool
    {
        if (! $campaign->is_active) {
            return false;
        }

        if ($campaign->starts_at !== null && $campaign->starts_at->isFuture()) {
            return false;
        }

        if ($campaign->ends_at !== null && $campaign->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    // ===================================================================
    // Completion (مصدر الحقيقة يختلف حسب Kind - أبداً لا يُخزَّن مرتين)
    // ===================================================================

    public function isStepCompleted(User $user, CampaignStep $step): bool
    {
        return match ($step->kind) {
            CampaignStep::KIND_NARRATIVE => $this->isNarrativeStepCompleted($user, $step),
            CampaignStep::KIND_PUZZLE => $this->isPuzzleStepCompleted($user, $step),
            default => false,
        };
    }

    protected function isNarrativeStepCompleted(User $user, CampaignStep $step): bool
    {
        return UserCampaignProgress::where('user_id', $user->id)
            ->where('campaign_step_id', $step->id)
            ->whereNotNull('completed_at')
            ->exists();
    }

    protected function isPuzzleStepCompleted(User $user, CampaignStep $step): bool
    {
        if ($step->puzzle_id === null) {
            return false;
        }

        return $user->hasSolvedPuzzle($step->puzzle, $this->contextFor($step));
    }

    // ===================================================================
    // In-Progress (فقط عندما لا تكون مكتملة بعد)
    // ===================================================================

    public function isStepInProgress(User $user, CampaignStep $step): bool
    {
        if ($this->isStepCompleted($user, $step)) {
            return false;
        }

        return match ($step->kind) {
            CampaignStep::KIND_NARRATIVE => UserCampaignProgress::where('user_id', $user->id)
                ->where('campaign_step_id', $step->id)
                ->whereNotNull('started_at')
                ->whereNull('completed_at')
                ->exists(),
            CampaignStep::KIND_PUZZLE => $this->isPuzzleStepInProgress($user, $step),
            default => false,
        };
    }

    protected function isPuzzleStepInProgress(User $user, CampaignStep $step): bool
    {
        if ($step->puzzle_id === null) {
            return false;
        }

        $context = $this->contextFor($step);

        $hasAttempt = PuzzleAttempt::where('user_id', $user->id)
            ->where('puzzle_id', $step->puzzle_id)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->exists();

        if ($hasAttempt) {
            return true;
        }

        return GameSession::where('user_id', $user->id)
            ->where('puzzle_id', $step->puzzle_id)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->where('status', GameSession::STATUS_ACTIVE)
            ->get()
            ->contains(fn (GameSession $session) => ! $session->isExpired());
    }

    // ===================================================================
    // Unlock (Linear Progression)
    // ===================================================================

    public function isStepUnlocked(User $user, CampaignStep $step): bool
    {
        if (! $this->isGateUnlocked($user, $step->gate)) {
            return false;
        }

        return $this->precedingSiblings($step->gate->steps, $step)
            ->every(fn (CampaignStep $previous) => $this->isStepCompleted($user, $previous));
    }

    public function isGateUnlocked(User $user, CampaignGate $gate): bool
    {
        if (! $this->isStageUnlocked($user, $gate->stage)) {
            return false;
        }

        // C6: بوابة سابقة "مكتملة" لا تكفي وحدها إن كانت تملك Qualification Rule -
        // يجب أيضاً أن يكون المستخدم مؤهَّلاً فعلياً ضمنها (completed != qualified).
        // فحص مباشر هون (بدل حقن QualificationService) لتفادي أي اعتماد دائري:
        // QualificationService نفسها تعتمد على CampaignProgressService، لا العكس.
        return $this->precedingSiblings($gate->stage->gates, $gate)
            ->every(fn (CampaignGate $previous) => $this->isGateCompleted($user, $previous)
                && $this->isGateQualificationSatisfied($user, $previous));
    }

    /**
     * qualification_rule=null (الافتراضي لأي بوابة عادية - C6.9) = تأهّل
     * غير مشروط، لا صف Qualification مطلوب إطلاقاً. غير ذلك، يُشترط وجود
     * صف CampaignGateQualification فعلي لهذا المستخدم بالذات.
     */
    protected function isGateQualificationSatisfied(User $user, CampaignGate $gate): bool
    {
        if ($gate->qualification_rule === null) {
            return true;
        }

        return CampaignGateQualification::where('campaign_gate_id', $gate->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isStageUnlocked(User $user, CampaignStage $stage): bool
    {
        if (! $this->isCampaignAvailable($stage->campaign)) {
            return false;
        }

        return $this->precedingSiblings($stage->campaign->stages, $stage)
            ->every(fn (CampaignStage $previous) => $this->isStageCompleted($user, $previous));
    }

    // ===================================================================
    // Container Completion (Derived فقط - Empty Container أبداً لا تُعتبر مكتملة)
    // ===================================================================

    public function isGateCompleted(User $user, CampaignGate $gate): bool
    {
        $steps = $gate->steps;

        if ($steps->isEmpty()) {
            return false;
        }

        return $steps->every(fn (CampaignStep $step) => $this->isStepCompleted($user, $step));
    }

    public function isStageCompleted(User $user, CampaignStage $stage): bool
    {
        $gates = $stage->gates;

        if ($gates->isEmpty()) {
            return false;
        }

        return $gates->every(fn (CampaignGate $gate) => $this->isGateCompleted($user, $gate));
    }

    public function isCampaignCompleted(User $user, Campaign $campaign): bool
    {
        $stages = $campaign->stages;

        if ($stages->isEmpty()) {
            return false;
        }

        return $stages->every(fn (CampaignStage $stage) => $this->isStageCompleted($user, $stage));
    }

    // ===================================================================
    // Derived State
    // ===================================================================

    /** @return self::STATE_* */
    public function stepState(User $user, CampaignStep $step): string
    {
        if ($this->isStepCompleted($user, $step)) {
            return self::STATE_COMPLETED;
        }

        if (! $this->isStepUnlocked($user, $step)) {
            return self::STATE_LOCKED;
        }

        if ($this->isStepInProgress($user, $step)) {
            return self::STATE_IN_PROGRESS;
        }

        return self::STATE_AVAILABLE;
    }

    /**
     * حالة على مستوى Gate تحديداً - تميّز "أكملها لكن لم يتأهّل" (C6) عن
     * "أكملها" ببساطة. لا "in_progress" مفاهيمياً على مستوى Gate نفسها -
     * ذلك مفهوم خاص بالـSteps الداخلية فقط.
     *
     * @return self::STATE_*
     */
    public function gateState(User $user, CampaignGate $gate): string
    {
        if ($this->isGateCompleted($user, $gate)) {
            if ($gate->qualification_rule !== null && ! $this->isGateQualificationSatisfied($user, $gate)) {
                return self::STATE_NOT_QUALIFIED;
            }

            return self::STATE_COMPLETED;
        }

        if (! $this->isGateUnlocked($user, $gate)) {
            return self::STATE_LOCKED;
        }

        return self::STATE_AVAILABLE;
    }

    /**
     * "المهمة الحالية" لعرض الواجهة (C8) - أول خطوة غير مكتملة بترتيب
     * العرض الطبيعي. Convenience للعرض فقط (ليست حرجة أمنياً كـprecedingSiblings)،
     * لذا تستخدم sort_order وحده دون Tie-break صارم بالـid.
     */
    public function currentStepFor(User $user, Campaign $campaign): ?CampaignStep
    {
        foreach ($campaign->stages->sortBy('sort_order') as $stage) {
            foreach ($stage->gates->sortBy('sort_order') as $gate) {
                foreach ($gate->steps->sortBy('sort_order') as $step) {
                    if (! $this->isStepCompleted($user, $step)) {
                        return $step;
                    }
                }
            }
        }

        return null;
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    protected function contextFor(CampaignStep $step): AttemptContext
    {
        return AttemptContext::campaignStep($step->id);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Collection<int, TModel>  $siblings
     * @param  TModel  $current
     * @return Collection<int, TModel>
     */
    protected function precedingSiblings(Collection $siblings, $current): Collection
    {
        return $siblings->filter(function ($sibling) use ($current) {
            if ($sibling->is($current)) {
                return false;
            }

            if ($sibling->sort_order !== $current->sort_order) {
                return $sibling->sort_order < $current->sort_order;
            }

            return $sibling->id < $current->id;
        });
    }
}
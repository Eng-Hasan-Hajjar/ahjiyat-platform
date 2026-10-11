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

class CampaignProgressService
{
    public const STATE_COMPLETED = 'completed';

    public const STATE_LOCKED = 'locked';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_AVAILABLE = 'available';

    public const STATE_NOT_QUALIFIED = 'not_qualified';

    /**
     * E24: لقطة قراءة لتقدّم مستخدم واحد داخل حملة واحدة. تُبنى صراحةً بـpreloadFor() من صفحات **القراءة** فقط (الموسم/الحملة/الرئيسية)؛
     * كل مسارات الكتابة (الإكمال/المحاولة/الجلسة) لا تستدعيها فتبقى تقرأ القاعدة مباشرةً كما كانت. اللقطة تحمل نفس المصادر الأصلية بالضبط
     * (UserCampaignProgress وPuzzleAttempt وGameSession وCampaignGateQualification) بخمسة استعلامات مجمَّعة بدل استعلام لكل خطوة.
     *
     * @var array{user: int, completed: array<int,bool>, started: array<int,bool>, solved: array<string,bool>, attempted: array<string,bool>, sessions: array<int,list<GameSession>>, ranks: array<int,int|null>}|null
     */
    protected ?array $snapshot = null;

    /**
     * يحمّل الهرم (إن لزم)، ويربط العلاقات العكسية (stage->campaign / gate->stage / step->gate) بالكائنات المحمَّلة نفسها بدل أن يعيد Eloquent
     * تحميل الحملة وهرمها كاملًا لكل استدعاء (كان السبب الجذري لـ500+ استعلام بصفحة الموسم)، ثم يبني لقطة التقدّم.
     */
    public function preloadFor(User $user, Campaign $campaign): void
    {
        $campaign->loadMissing('stages.gates.steps');

        $stepIds = [];
        $gateIds = [];

        foreach ($campaign->stages as $stage) {
            $stage->setRelation('campaign', $campaign);

            foreach ($stage->gates as $gate) {
                $gate->setRelation('stage', $stage);
                $gateIds[] = $gate->id;

                foreach ($gate->steps as $step) {
                    $step->setRelation('gate', $gate);
                    $stepIds[] = $step->id;
                }
            }
        }

        $snapshot = ['user' => (int) $user->id, 'completed' => [], 'started' => [], 'solved' => [], 'attempted' => [], 'sessions' => [], 'ranks' => []];

        if ($stepIds !== []) {
            foreach (UserCampaignProgress::where('user_id', $user->id)->whereIn('campaign_step_id', $stepIds)->get(['campaign_step_id', 'started_at', 'completed_at']) as $row) {
                $id = (int) $row->campaign_step_id;
                // نفس شرطي exists الأصليين: «مكتمل» = completed_at غير فارغ؛ «بدأ ولم يكتمل» = started_at غير فارغ وcompleted_at فارغ (لصف واحد على الأقل).
                if ($row->completed_at !== null) {
                    $snapshot['completed'][$id] = true;
                }
                if ($row->started_at !== null && $row->completed_at === null) {
                    $snapshot['started'][$id] = true;
                }
            }

            $contextType = AttemptContext::TYPE_CAMPAIGN_STEP;

            foreach (PuzzleAttempt::where('user_id', $user->id)->where('context_type', $contextType)->whereIn('context_id', $stepIds)->distinct()->get(['puzzle_id', 'context_id', 'is_correct']) as $row) {
                $key = ((int) $row->puzzle_id).':'.((int) $row->context_id);
                $snapshot['attempted'][$key] = true;
                if ($row->is_correct) {
                    $snapshot['solved'][$key] = true;
                }
            }

            foreach (GameSession::where('user_id', $user->id)->where('context_type', $contextType)->whereIn('context_id', $stepIds)->where('status', GameSession::STATUS_ACTIVE)->get() as $session) {
                $snapshot['sessions'][(int) $session->puzzle_id.':'.(int) $session->context_id][] = $session;
            }
        }

        if ($gateIds !== []) {
            foreach (CampaignGateQualification::where('user_id', $user->id)->whereIn('campaign_gate_id', $gateIds)->get(['campaign_gate_id', 'rank']) as $row) {
                $snapshot['ranks'][(int) $row->campaign_gate_id] = $row->rank;
            }
        }

        $this->snapshot = $snapshot;
    }

    /** يُسقط اللقطة (يعيد القراءة المباشرة). يُستدعى قبل أي كتابة تقع بعد preloadFor على المثيل نفسه. */
    public function forgetSnapshot(): void
    {
        $this->snapshot = null;
    }

    /** @return array<string,mixed>|null اللقطة إن كانت لهذا المستخدم، وإلا null (قراءة مباشرة). */
    protected function snapshotFor(User $user): ?array
    {
        return $this->snapshot !== null && $this->snapshot['user'] === (int) $user->id ? $this->snapshot : null;
    }

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
    // Completion
    // ===================================================================

    public function isStepCompleted(User $user, CampaignStep $step): bool
    {
        return match ($step->kind) {
            // narrative وreflection (D2) يتشاركان نفس مصدر الحقيقة بالضبط:
            // UserCampaignProgress.completed_at - لا PuzzleAttempt لأي منهما.
            CampaignStep::KIND_NARRATIVE, CampaignStep::KIND_REFLECTION => $this->isProgressStepCompleted($user, $step),
            CampaignStep::KIND_PUZZLE => $this->isPuzzleStepCompleted($user, $step),
            default => false,
        };
    }

    protected function isProgressStepCompleted(User $user, CampaignStep $step): bool
    {
        if (($snapshot = $this->snapshotFor($user)) !== null) {
            return isset($snapshot['completed'][(int) $step->id]);
        }

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

        if (($snapshot = $this->snapshotFor($user)) !== null) {
            return isset($snapshot['solved'][((int) $step->puzzle_id).':'.((int) $step->id)]);
        }

        return $user->hasSolvedPuzzle($step->puzzle, $this->contextFor($step));
    }

    // ===================================================================
    // In-Progress
    // ===================================================================

    public function isStepInProgress(User $user, CampaignStep $step): bool
    {
        if ($this->isStepCompleted($user, $step)) {
            return false;
        }

        return match ($step->kind) {
            // narrative تُظهر in_progress إن بدأها المستخدم (markStarted) بدون
            // إكمال. reflection تصميم أبسط عمداً (D2): إجراء واحد (Submit) =
            // إكمال مباشر - لا حالة "بدأ لكن لم يُرسل" مُتتبَّعة، فتبقى Available
            // حتى الإرسال، بلا تعقيد إضافي غير مطلوب صراحة بالمواصفة.
            CampaignStep::KIND_NARRATIVE => ($snapshot = $this->snapshotFor($user)) !== null
                ? isset($snapshot['started'][(int) $step->id])
                : UserCampaignProgress::where('user_id', $user->id)
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

        if (($snapshot = $this->snapshotFor($user)) !== null) {
            $key = ((int) $step->puzzle_id).':'.((int) $step->id);

            if (isset($snapshot['attempted'][$key])) {
                return true;
            }

            return collect($snapshot['sessions'][$key] ?? [])->contains(fn (GameSession $session) => ! $session->isExpired());
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
    // Unlock
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

        return $this->precedingSiblings($gate->stage->gates, $gate)
            ->every(fn (CampaignGate $previous) => $this->isGateCompleted($user, $previous)
                && $this->isGateQualificationSatisfied($user, $previous));
    }

    protected function isGateQualificationSatisfied(User $user, CampaignGate $gate): bool
    {
        if ($gate->qualification_rule === null) {
            return true;
        }

        if (($snapshot = $this->snapshotFor($user)) !== null) {
            return array_key_exists((int) $gate->id, $snapshot['ranks']);
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
    // Container Completion
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

    /** @return self::STATE_* */
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




        /**
     * الرقم التسلسلي لخطوة عبر كامل الحملة (E2) - نفس منطق العدّ المستخدم
     * بخريطة القصة بالضبط، مُستخرَج هون كمصدر وحيد يشاركه Story Map وصفحة
     * الخطوة (Step Shell) بلا ازدواجية. Convenience للعرض - $campaign يجب
     * أن تكون مُحمَّلة مسبقاً (stages.gates.steps) لتفادي N+1.
     */
    public function missionNumberFor(Campaign $campaign, CampaignStep $step): int
    {
        $number = 0;

        foreach ($campaign->stages->sortBy('sort_order') as $stage) {
            foreach ($stage->gates->sortBy('sort_order') as $gate) {
                foreach ($gate->steps->sortBy('sort_order') as $s) {
                    $number++;

                    if ($s->is($step)) {
                        return $number;
                    }
                }
            }
        }

        return $number;
    }

    /** ترتيب المستخدم إن كان مؤهَّلاً فعلياً بهذه البوابة (First-N)، أو null. */
    public function qualifiedRankFor(User $user, CampaignGate $gate): ?int
    {
        if (($snapshot = $this->snapshotFor($user)) !== null) {
            $rank = $snapshot['ranks'][(int) $gate->id] ?? null;

            return $rank === null ? null : (int) $rank;
        }

        return CampaignGateQualification::where('campaign_gate_id', $gate->id)
            ->where('user_id', $user->id)
            ->value('rank');
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
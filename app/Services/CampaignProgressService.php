<?php

namespace App\Services;

use App\GameEngine\Support\AttemptContext;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\GameSession;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Support\Collection;

/**
 * الخدمة الوحيدة المسؤولة عن اشتقاق حالة الحملة بالكامل - Store Facts,
 * Derive States. لا Writes هون إطلاقاً (Phase C2 قراءة بحتة)، ولا استدعاء
 * لأي خدمة أخرى (Reward/Qualification تأتي لاحقاً بخدماتها الخاصة).
 *
 * قرار معماري: خدمة واحدة لا اثنتان (Access + Progress). فحص "هل هذه
 * البوابة مفتوحة؟" يحتاج بالضرورة معرفة "هل البوابات السابقة مكتملة؟" -
 * فصلهما كان سيفرض على إحداهما حقن الأخرى لكل دالة تقريباً، أي حد فاصل
 * مصطنع بلا فائدة حقيقية. تبقى هذه الصنف صغيرة النطاق عمداً: لا شيء هون
 * يكتب لقاعدة البيانات، ولا شيء يستدعي GemWalletService أو
 * FraudDetectionService أو أي منطق مكافأة/تأهّل - تلك خدمات منفصلة قادمة
 * (C5/C6) بمسؤولياتها الخاصة تماماً.
 */
class CampaignProgressService
{
    public const STATE_COMPLETED = 'completed';

    public const STATE_LOCKED = 'locked';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_AVAILABLE = 'available';

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
            // Kind غير مدعوم حالياً - نتعامل معه بأمان صريح: لا نعتبره
            // مكتملاً أبداً (لا نفتح ما بعده بالخطأ)، ولا نرمي استثناء يكسر
            // عرض الحملة كاملة بسبب خطوة واحدة غير معروفة.
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
            return false; // بيانات غير سليمة (خطوة puzzle بلا Puzzle مرتبطة) - أبداً لا تُعتبر مكتملة
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

        // جلسات نشطة قليلة جداً عملياً لكل (user, puzzle, context) - GameSessionService
        // يعيد استخدام الجلسة النشطة بدل تكرارها، فهذا استعلام صغير مستهدَف
        // وليس مسحاً واسعاً. isExpired() منطق محسوب على الـModel نفسه، فنجلب
        // الصفوف القليلة ونفحصها بالـPHP بدل تكرار حساب انتهاء الصلاحية بـSQL خام.
        return GameSession::where('user_id', $user->id)
            ->where('puzzle_id', $step->puzzle_id)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->where('status', GameSession::STATUS_ACTIVE)
            ->get()
            ->contains(fn (GameSession $session) => ! $session->isExpired());
    }

    // ===================================================================
    // Unlock (Linear Progression - يفحص كل الأسلاف/الأشقاء السابقين، لا
    // العنصر السابق مباشرة فقط - أكثر أماناً عند إعادة الترتيب لاحقاً)
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

        // Qualification (qualification_rule/config) مقصود تجاهله بالكامل هون -
        // Phase C2 Linear بحتة. سيدخل في C6 كطبقة إضافية فوق isGateUnlocked،
        // لا بديلاً عنها.
        return $this->precedingSiblings($gate->stage->gates, $gate)
            ->every(fn (CampaignGate $previous) => $this->isGateCompleted($user, $previous));
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
    // Derived Step State (ترتيب أولوية ثابت: completed > locked > in_progress > available)
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

    // ===================================================================
    // Helpers
    // ===================================================================

    protected function contextFor(CampaignStep $step): AttemptContext
    {
        return AttemptContext::campaignStep($step->id);
    }

    /**
     * كل عناصر $siblings التي تسبق $current وفق ترتيب مستقر (sort_order ثم
     * id) - وليس فقط العنصر السابق مباشرة. مصمَّمة للاستفادة من علاقات
     * مُحمَّلة مسبقاً (Eager Loaded) عند توفرها: الوصول لخاصية العلاقة
     * (->gates وليس ->gates()->get()) لا يُعيد الاستعلام إن كانت محمَّلة
     * سلفاً، ويحمّلها مرة واحدة ويُخزّنها إن لم تكن كذلك.
     *
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
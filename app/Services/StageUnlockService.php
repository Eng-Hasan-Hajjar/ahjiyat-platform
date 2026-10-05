<?php

namespace App\Services;

use App\Events\StageUnlockedForUser;
use App\GameEngine\Support\AttemptContext;
use App\Models\Campaign;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * يكتشف انتقال "مرحلة صارت مفتوحة لهذا المستخدم لأول مرة" بعد إكمال خطوة حقيقية، ويسجّله بسجل dedup ثم يُطلق
 * StageUnlockedForUser. لا يغيّر أي قاعدة فتح/تقدّم: الفتح يبقى مشتقًا بـCampaignProgressService::isStageUnlocked
 * (يُستدعى كما هو - لا نسخة ثانية من المنطق) وهذا الجدول مجرد سجل إعلان.
 *
 * - المرحلة الأولى (بلا سابقات) مفتوحة للجميع بإتاحة الحملة، فلا تُسجَّل ولا تُعلَن (CampaignBecameAvailable يغطيها).
 * - مرشَّحو الإعلان: المراحل اللاحقة لمرحلة الخطوة المُكمَلة فقط، المفتوحة الآن وغير المسجَّلة.
 * - لا إعلان عن إعادة إكمال: يُعلَن فقط إن كان إكمال الخطوة حديثًا (RECENT_COMPLETION_SECONDS)؛ غير ذلك يُسجَّل بصمت. هذا ما
 *   يمنع إشعارًا تاريخيًا في الفترة بين الترحيل وتشغيل campaigns:backfill-stage-unlocks (بلا أي Feature Flag).
 * - insertOrIgnore ذري: لا يُطلَق الحدث إلا إن أُنشئ صف فعلًا، وبعد commit.
 */
class StageUnlockService
{
    /** إكمال أقدم من هذه النافذة يُعدّ إعادة/تاريخيًا: يُسجَّل الفتح بصمت ولا يُعلَن. */
    public const RECENT_COMPLETION_SECONDS = 120;

    public function __construct(protected CampaignProgressService $progress) {}

    /** نقطة دخول مسار التقدّم: لا تكسر التقدّم الأساسي أبدًا (حتى لو فشل إنشاء الخدمة أو الجدول غير موجود بعد). */
    public static function recordSafely(User $user, CampaignStep $step): void
    {
        try {
            app(self::class)->recordAfterStepCompletion($user, $step);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return int عدد سجلات الفتح الجديدة (المعلَنة وغير المعلَنة) */
    public function recordAfterStepCompletion(User $user, CampaignStep $step): int
    {
        $ownStageId = $step->gate?->campaign_stage_id;
        $campaignId = $ownStageId === null ? null : CampaignStage::query()->whereKey($ownStageId)->value('campaign_id');
        $campaign = $campaignId === null ? null : $this->loadCampaign((int) $campaignId);
        $ownStage = $campaign?->stages->firstWhere('id', $ownStageId);

        if ($campaign === null || $ownStage === null) {
            return 0;
        }

        $announce = $this->completedRecently($user, $step);
        $recorded = 0;

        foreach ($this->newlyUnlockedStages($user, $campaign, $ownStage) as $stage) {
            if (! $this->insertUnlock($user, $stage)) {
                continue;
            }

            $recorded++;

            if ($announce) {
                $this->announceAfterCommit($user->getKey(), $campaign->getKey(), $stage->getKey());
            }
        }

        return $recorded;
    }

    /** تعبئة رجعية صامتة: يسجّل كل مرحلة (غير أولى) مفتوحة الآن لهذا المستخدم - بلا أحداث ولا إشعارات. */
    public function backfill(User $user, Campaign $campaign): int
    {
        $recorded = 0;

        foreach ($this->newlyUnlockedStages($user, $campaign, null) as $stage) {
            $recorded += $this->insertUnlock($user, $stage) ? 1 : 0;
        }

        return $recorded;
    }

    public function loadCampaign(int $campaignId): ?Campaign
    {
        return Campaign::query()->with('stages.gates.steps')->find($campaignId);
    }

    /** المراحل (غير الأولى) المفتوحة الآن لهذا المستخدم وغير المسجَّلة؛ مع $after: اللاحقة لتلك المرحلة فقط. */
    protected function newlyUnlockedStages(User $user, Campaign $campaign, ?CampaignStage $after)
    {
        $stages = $campaign->stages;

        $recorded = DB::table('user_stage_unlocks')
            ->where('user_id', $user->getKey())
            ->whereIn('campaign_stage_id', $stages->pluck('id'))
            ->pluck('campaign_stage_id')
            ->all();

        return $stages
            ->sortBy(fn (CampaignStage $s) => [$s->sort_order, $s->id])
            ->filter(function (CampaignStage $stage) use ($user, $campaign, $after, $recorded) {
                if (in_array($stage->id, $recorded, true)) {
                    return false;
                }

                if (! $this->hasPrecedingStage($campaign, $stage)) {
                    return false; // المرحلة الأولى: مفتوحة افتراضيًا
                }

                if ($after !== null && ! $this->precedes($after, $stage)) {
                    return false;
                }

                $stage->setRelation('campaign', $campaign);

                return $this->progress->isStageUnlocked($user, $stage);
            })
            ->values();
    }

    /** نفس ترتيب CampaignProgressService::precedingSiblings: sort_order ثم id. */
    protected function precedes(CampaignStage $a, CampaignStage $b): bool
    {
        return $a->sort_order !== $b->sort_order ? $a->sort_order < $b->sort_order : $a->id < $b->id;
    }

    protected function hasPrecedingStage(Campaign $campaign, CampaignStage $stage): bool
    {
        return $campaign->stages->contains(fn (CampaignStage $other) => $other->id !== $stage->id && $this->precedes($other, $stage));
    }

    protected function insertUnlock(User $user, CampaignStage $stage): bool
    {
        $now = now();

        return DB::table('user_stage_unlocks')->insertOrIgnore([[
            'user_id' => $user->getKey(),
            'campaign_stage_id' => $stage->getKey(),
            'unlocked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]]) === 1;
    }

    protected function announceAfterCommit(int $userId, int $campaignId, int $stageId): void
    {
        DB::afterCommit(function () use ($userId, $campaignId, $stageId) {
            try {
                event(new StageUnlockedForUser($userId, $campaignId, $stageId));
            } catch (\Throwable $e) {
                report($e); // الحدث ثانوي: فشله لا يمسّ التقدّم ولا سجل الفتح.
            }
        });
    }

    /** لحظة إكمال الخطوة (لأول مرة) حديثة؟ السرد/التأمل: completed_at. الأحجية: أول محاولة صحيحة بسياق الخطوة. */
    protected function completedRecently(User $user, CampaignStep $step): bool
    {
        $at = match ($step->kind) {
            CampaignStep::KIND_NARRATIVE, CampaignStep::KIND_REFLECTION => UserCampaignProgress::query()
                ->where('user_id', $user->getKey())->where('campaign_step_id', $step->getKey())->value('completed_at'),
            CampaignStep::KIND_PUZZLE => $step->puzzle_id === null ? null : PuzzleAttempt::query()
                ->where('user_id', $user->getKey())->where('puzzle_id', $step->puzzle_id)->where('is_correct', true)
                ->where('context_type', AttemptContext::TYPE_CAMPAIGN_STEP)->where('context_id', $step->getKey())->min('created_at'),
            default => null,
        };

        return $at !== null && Carbon::parse($at)->greaterThanOrEqualTo(now()->subSeconds(self::RECENT_COMPLETION_SECONDS));
    }
}

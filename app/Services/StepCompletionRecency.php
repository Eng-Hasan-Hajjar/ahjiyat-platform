<?php

namespace App\Services;

use App\GameEngine\Support\AttemptContext;
use App\Models\CampaignStep;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Carbon\Carbon;

/**
 * تعريف واحد لـ"إكمال الخطوة حديث": تستعمله خدمات الإعلان (فتح المرحلة، إكمال الحملة) لتقرّر هل الانتقال ناتج عن تقدّم فعلي
 * الآن (يُعلَن) أم عن إعادة/تاريخ (يُسجَّل بصمت). هذا ما يمنع إشعارًا تاريخيًا في الفترة بين الترحيل وتشغيل أمر التعبئة
 * الرجعية، بلا أي Feature Flag.
 */
final class StepCompletionRecency
{
    /** إكمال أقدم من هذه النافذة يُعدّ إعادة/تاريخيًا. */
    public const WINDOW_SECONDS = 120;

    /** لحظة إكمال الخطوة (لأول مرة) حديثة؟ السرد/التأمل: completed_at. الأحجية: أول محاولة صحيحة بسياق الخطوة. */
    public static function isRecent(User $user, CampaignStep $step): bool
    {
        $at = match ($step->kind) {
            CampaignStep::KIND_NARRATIVE, CampaignStep::KIND_REFLECTION => UserCampaignProgress::query()
                ->where('user_id', $user->getKey())->where('campaign_step_id', $step->getKey())->value('completed_at'),
            CampaignStep::KIND_PUZZLE => $step->puzzle_id === null ? null : PuzzleAttempt::query()
                ->where('user_id', $user->getKey())->where('puzzle_id', $step->puzzle_id)->where('is_correct', true)
                ->where('context_type', AttemptContext::TYPE_CAMPAIGN_STEP)->where('context_id', $step->getKey())->min('created_at'),
            default => null,
        };

        return $at !== null && Carbon::parse($at)->greaterThanOrEqualTo(now()->subSeconds(self::WINDOW_SECONDS));
    }
}

<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\CommunityReputationSnapshot;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\ReputationRecognitionContext;

/**
 * E37 - شروط الاعتراف المجتمعي كإنجازات (لا محرك إنجازات ثانٍ). المصدر: القيم المحسوبة للتوّ من لقطة السمعة (سياق التقييم)، وإلا آخر لقطة محفوظة.
 * التقدّم تصاعدي (max في AchievementService): كسر مستوى لاحقًا لا يسحب اعترافًا سبق نيله؛ أي مكافأة ممنوعة بحارس الإنجازات (اعتراف لا اقتصاد).
 */
class CommunityRecognitionEvaluator implements AchievementEvaluator
{
    public function __construct(protected ReputationRecognitionContext $context, protected CommunityReputationService $reputation) {}

    public function supports(Achievement $achievement): bool
    {
        return in_array($achievement->condition_type, AchievementEvaluatorRegistry::COMMUNITY_TYPES, true);
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        $values = $this->context->get((int) $user->id);

        if ($values === null) {
            $snapshot = CommunityReputationSnapshot::query()->where('user_id', $user->id)->first();

            if ($snapshot === null) {
                return 0;
            }

            $active = $user->creatorProfile?->isApproved() === true && ! $user->is_frozen;
            $values = $this->reputation->recognitionValues($snapshot->getAttributes(), $active);
        }

        return (int) ($values[$achievement->condition_type] ?? 0);
    }
}

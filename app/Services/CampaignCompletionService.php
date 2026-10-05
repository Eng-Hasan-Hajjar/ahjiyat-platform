<?php

namespace App\Services;

use App\Events\CampaignCompletedForUser;
use App\Models\Campaign;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * يسجّل "أول إكمال" لحملة لمستخدم بعد إكمال خطوة حقيقية ويُطلق CampaignCompletedForUser. لا يغيّر أي قاعدة: الإكمال يبقى
 * مشتقًا بـCampaignProgressService::isCampaignCompleted (يُستدعى كما هو - لا نسخة ثانية من التعريف) والجدول سجل إعلان فقط.
 *
 * - الحملة/المرحلة/البوابة الفارغة غير مكتملة (هذا تعريف المصدر الحالي نفسه)، فلا تُسجَّل.
 * - تاريخي: تعطيل الحملة أو تعديل الإدارة لمحتواها لاحقًا لا يمسح السجل ولا يعيد الإعلان.
 * - لا إعلان عن إعادة إكمال: يُعلَن فقط إن كان إكمال الخطوة حديثًا (StepCompletionRecency)؛ غير ذلك يُسجَّل بصمت. هذا ما
 *   يمنع إشعارًا تاريخيًا قبل تشغيل campaigns:backfill-completions.
 * - insertOrIgnore ذري: لا يُطلَق الحدث إلا إن أُنشئ صف فعلًا، وبعد commit.
 */
class CampaignCompletionService
{
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

    /** @return bool true إن سُجّل أول إكمال بهذا الاستدعاء */
    public function recordAfterStepCompletion(User $user, CampaignStep $step): bool
    {
        $stageId = $step->gate?->campaign_stage_id;
        $campaignId = $stageId === null ? null : CampaignStage::query()->whereKey($stageId)->value('campaign_id');

        if ($campaignId === null || $this->isRecorded($user->getKey(), (int) $campaignId)) {
            return false; // سُجّل سابقًا: لا نعيد حساب الاشتقاق أصلًا
        }

        $campaign = $this->loadCampaign((int) $campaignId);

        if ($campaign === null || ! $this->progress->isCampaignCompleted($user, $campaign)) {
            return false;
        }

        $announce = StepCompletionRecency::isRecent($user, $step);

        if (! $this->insertCompletion($user, $campaign)) {
            return false;
        }

        if ($announce) {
            $this->announceAfterCommit($user->getKey(), $campaign->getKey());
        }

        return true;
    }

    /** تعبئة رجعية صامتة: يسجّل الإكمال إن كانت الحملة مكتملة الآن لهذا المستخدم - بلا أحداث ولا إشعارات. */
    public function backfill(User $user, Campaign $campaign): bool
    {
        return ! $this->isRecorded($user->getKey(), $campaign->getKey())
            && $this->progress->isCampaignCompleted($user, $campaign)
            && $this->insertCompletion($user, $campaign);
    }

    public function loadCampaign(int $campaignId): ?Campaign
    {
        return Campaign::query()->with('stages.gates.steps')->find($campaignId);
    }

    protected function isRecorded(int $userId, int $campaignId): bool
    {
        return DB::table('user_campaign_completions')->where('user_id', $userId)->where('campaign_id', $campaignId)->exists();
    }

    protected function insertCompletion(User $user, Campaign $campaign): bool
    {
        $now = now();

        return DB::table('user_campaign_completions')->insertOrIgnore([[
            'user_id' => $user->getKey(),
            'campaign_id' => $campaign->getKey(),
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]]) === 1;
    }

    protected function announceAfterCommit(int $userId, int $campaignId): void
    {
        DB::afterCommit(function () use ($userId, $campaignId) {
            try {
                event(new CampaignCompletedForUser($userId, $campaignId));
            } catch (\Throwable $e) {
                report($e); // الحدث ثانوي: فشله لا يمسّ التقدّم ولا سجل الإكمال.
            }
        });
    }
}

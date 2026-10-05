<?php

namespace App\Services;

use App\Events\SeasonStarted;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * المصدر الحقيقي الوحيد لسؤال: "هل الموسم مباشر الآن؟" وللانتقال "لم يبدأ ← بدأ".
 *
 * التعريف لا يُكرَّر: الموسم مباشر = منشور + حملته متاحة بتعريف CampaignProgressService::isCampaignAvailable
 * نفسه الذي يعتمده اللعب وصفحة المواسم (مفعَّلة، starts_at ليس بالمستقبل، ends_at ليس بالماضي).
 *
 * الانتقال ذري: UPDATE ... WHERE went_live_at IS NULL. لا يُطلَق SeasonStarted إلا إذا تأثّر صف فعلًا
 * بهذا الاستدعاء (فسباق استدعاءين أو إعادة المحاولة أو التشغيل المتكرر = حدث واحد على الأكثر)، وبعد commit.
 * went_live_at تُكتَب مرة واحدة: إيقاف/تفعيل لاحق لا يعيد الحدث أبدًا.
 *
 * يُستدعى من: خطافات الحفظ (Season.is_published، Campaign.is_active/starts_at/ends_at) + الأمر المجدول
 * seasons:sync-live-state لالتقاط البدء الناتج عن مرور الوقت (لا يكتب شيئًا بقاعدة البيانات حينها).
 */
class SeasonLifecycleService
{
    public function __construct(protected CampaignProgressService $campaigns) {}

    public function isLive(Season $season): bool
    {
        $campaign = $season->campaign;

        return $season->is_published && $campaign !== null && $this->campaigns->isCampaignAvailable($campaign);
    }

    /** @return bool true إن حدث الانتقال فعلًا بهذا الاستدعاء تحديدًا. */
    public function syncLiveState(Season|int $season): bool
    {
        $fresh = Season::query()->with('campaign')->find($season instanceof Season ? $season->getKey() : $season);

        if ($fresh === null || $fresh->went_live_at !== null || ! $this->isLive($fresh)) {
            return false;
        }

        $affected = Season::query()->whereKey($fresh->getKey())->whereNull('went_live_at')->update(['went_live_at' => now()]);

        if ($affected !== 1) {
            return false; // سبقنا استدعاء آخر
        }

        $id = $fresh->getKey();

        DB::afterCommit(function () use ($id) {
            try {
                $started = Season::query()->with('campaign')->find($id);

                if ($started !== null) {
                    event(new SeasonStarted($started));
                }
            } catch (\Throwable $e) {
                report($e); // الحدث ثانوي: فشله لا يمسّ حفظ الموسم/الحملة ولا went_live_at.
            }
        });

        return true;
    }

    /**
     * نقطة دخول الخطافات: لا تكسر أي حفظ إداري أبدًا - حتى لو فشل إنشاء الخدمة نفسه من الحاوية أو لم يُشغَّل
     * الترحيل بعد. المجدوِل (seasons:sync-live-state) شبكة الأمان لما فات الخطاف.
     */
    public static function syncFromHook(Season|int $season): void
    {
        try {
            app(self::class)->syncLiveState($season);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\Services;

use App\Events\CampaignBecameAvailable;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * المصدر الوحيد لمزامنة الانتقال "غير متاحة ← متاحة لأول مرة". تعريف "متاحة" لا يُكرَّر ولا يُوازى:
 * CampaignProgressService::isCampaignAvailable نفسه (مفعَّلة، starts_at ليس بالمستقبل، ends_at ليس بالماضي) - وهو
 * عام لكل المستخدمين (لا شرط خاص بمستخدم على مستوى الحملة؛ المراحل/البوابات/الخطوات اللاحقة تُفتح لكل مستخدم بتقدّمه).
 *
 * الانتقال ذري: UPDATE ... WHERE became_available_at IS NULL؛ لا يُطلَق CampaignBecameAvailable إلا إن تأثّر صف فعلًا
 * بهذا الاستدعاء، وبعد commit. became_available_at تُكتَب مرة واحدة: إيقاف/تفعيل لاحق لا يعيد الحدث (سياسة:
 * first-ever availability).
 *
 * يُستدعى من: خطاف حفظ Campaign (إنشاؤها متاحة، أو تغيّر is_active/starts_at/ends_at) + الأمر المجدول
 * campaigns:sync-availability لالتقاط الإتاحة الناتجة عن مرور الوقت (starts_at) - ولا يكتب شيئًا بقاعدة البيانات حينها.
 */
class CampaignLifecycleService
{
    public function __construct(protected CampaignProgressService $campaigns) {}

    public function isAvailable(Campaign $campaign): bool
    {
        return $this->campaigns->isCampaignAvailable($campaign);
    }

    /** @return bool true إن حدث الانتقال فعلًا بهذا الاستدعاء تحديدًا. */
    public function syncAvailability(Campaign|int $campaign): bool
    {
        $fresh = Campaign::query()->find($campaign instanceof Campaign ? $campaign->getKey() : $campaign);

        if ($fresh === null || $fresh->became_available_at !== null || ! $this->isAvailable($fresh)) {
            return false;
        }

        $affected = Campaign::query()->whereKey($fresh->getKey())->whereNull('became_available_at')->update(['became_available_at' => now()]);

        if ($affected !== 1) {
            return false; // سبقنا استدعاء آخر
        }

        $id = $fresh->getKey();

        DB::afterCommit(function () use ($id) {
            try {
                $available = Campaign::query()->find($id);

                if ($available !== null) {
                    event(new CampaignBecameAvailable($available));
                }
            } catch (\Throwable $e) {
                report($e); // الحدث ثانوي: فشله لا يمسّ حفظ الحملة ولا became_available_at.
            }
        });

        return true;
    }

    /** نقطة دخول الخطافات: لا تكسر أي حفظ إداري أبدًا (حتى لو فشل إنشاء الخدمة أو لم يُشغَّل الترحيل بعد). */
    public static function syncFromHook(Campaign|int $campaign): void
    {
        try {
            app(self::class)->syncAvailability($campaign);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

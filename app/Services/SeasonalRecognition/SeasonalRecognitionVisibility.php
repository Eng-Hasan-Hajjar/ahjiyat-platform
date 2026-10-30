<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use App\Services\SeasonLifecycleService;

/**
 * E44 - متى تظهر صفحات التقدير الموسمي وأقسامها؟ مصدر واحد لقرار الظهور (الصفحات + مركز الموسم + الملفات).
 *
 *  - الموسم نفسه ظاهر للعامة (منشور وحان موعد إعلانه) أو المستخدم يملك صلاحية العرض الإدارية.
 *  - الدوري/التقدير ظاهر حين يكون مفعّلًا (مفتاح المنصة ∧ مفتاح الموسم) **أو** له تاريخ يُعرض للقراءة (إنهاء أو كتّاب/بطاقات): تعطيل المفتاح يوقف الإجراءات الجديدة ولا يخفي ولا يمحو تاريخًا.
 * قراءة فقط؛ لا كتابة.
 */
class SeasonalRecognitionVisibility
{
    public function __construct(
        protected SeasonLifecycleService $lifecycle,
        protected SeasonRecognitionSettingsService $settings,
    ) {}

    public function seasonVisible(Season $season, ?User $viewer = null): bool
    {
        return $this->lifecycle->isPubliclyVisible($season) || ($viewer !== null && $viewer->can('seasonal-recognition.view'));
    }

    public function writersAvailable(Season $season, ?User $viewer = null): bool
    {
        if (! $this->seasonVisible($season, $viewer)) {
            return false;
        }

        $setting = $this->settings->settingFor($season);

        if ($setting === null) {
            return false;
        }

        if ($this->settings->writersActive($season, $setting) || $setting->writers_finalized_at !== null) {
            return true;
        }

        return SeasonWriterEntry::query()->where('season_id', $season->id)->exists();
    }

    public function supportersAvailable(Season $season, ?User $viewer = null): bool
    {
        if (! $this->seasonVisible($season, $viewer)) {
            return false;
        }

        $setting = $this->settings->settingFor($season);

        if ($setting === null) {
            return false;
        }

        if ($this->settings->supportersActive($season, $setting) || $setting->supporters_finalized_at !== null) {
            return true;
        }

        return SeasonSupporterCard::query()->where('season_id', $season->id)->exists();
    }

    /** الإعدادات لمن يريد تلميحًا سريعًا بلا استعلام ثانٍ (قد تكون null). */
    public function setting(Season $season): ?SeasonRecognitionSetting
    {
        return $this->settings->settingFor($season);
    }
}

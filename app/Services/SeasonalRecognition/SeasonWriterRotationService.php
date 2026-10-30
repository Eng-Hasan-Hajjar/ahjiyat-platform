<?php

namespace App\Services\SeasonalRecognition;

use App\Models\CreatorProfile;
use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterEntry;
use App\Services\SeasonLifecycleService;

/**
 * E44 - تدوير الكتّاب بين المواسم: من وُسم «غير مؤهَّل للموسم التالي» (ineligible_next_season) في دوري موسم لا يُختار في قائمة **الموسم التالي المباشر** فقط.
 *
 * - يُفرض في خدمة القائمة على الخادم (إخفاؤه من قائمة منسدلة لا يكفي) ولا يمتد لأبعد من الموسم التالي: لا حظر منصّة ولا تعليق ملف مبدع ولا إخفاء محتوى.
 * - الموسم السابق: المؤشر الصريح previous_season_id إن ضُبط، وإلا **السابق الزمني المباشر** (أكبر بداية فعلية أصغر من بداية هذا الموسم). موسم سابق لم يُنهَ دوريه لا يستبعد أحدًا.
 * - **آلية استقطاب كتّاب بدلاء/جدد غير مبنية هنا عمدًا** (قرار منتج: لها شرح منفصل). نقطة التمديد الوحيدة: excludedCreatorProfileIds() ثم اختيار القائمة يدويًا من الإدارة.
 */
class SeasonWriterRotationService
{
    public function __construct(protected SeasonLifecycleService $lifecycle, protected SeasonRecognitionSettingsService $settings) {}

    public function previousSeasonFor(Season $season): ?Season
    {
        $explicit = SeasonRecognitionSetting::query()->where('season_id', $season->id)->value('previous_season_id');

        if ($explicit !== null) {
            return Season::query()->find($explicit);
        }

        $start = $this->lifecycle->windowStart($season);

        if ($start === null) {
            return null;
        }

        return Season::query()
            ->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.*')
            ->where('seasons.id', '!=', $season->id)
            ->whereRaw('COALESCE(seasons.starts_at, campaigns.starts_at) < ?', [$start])
            ->orderByRaw('COALESCE(seasons.starts_at, campaigns.starts_at) DESC')
            ->orderByDesc('seasons.id')
            ->first();
    }

    /** @return list<int> ملفات المبدعين المستبعدة من قائمة هذا الموسم. */
    public function excludedCreatorProfileIds(Season $season): array
    {
        $previous = $this->previousSeasonFor($season);

        if ($previous === null) {
            return [];
        }

        $previousSetting = $this->settings->settingFor($previous);

        if ($previousSetting === null || $previousSetting->writers_finalized_at === null) {
            return [];
        }

        return SeasonWriterEntry::query()
            ->where('season_id', $previous->id)
            ->where('outcome', SeasonWriterEntry::OUTCOME_INELIGIBLE_NEXT)
            ->pluck('creator_profile_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isExcluded(Season $season, CreatorProfile $profile): bool
    {
        return in_array((int) $profile->id, $this->excludedCreatorProfileIds($season), true);
    }
}

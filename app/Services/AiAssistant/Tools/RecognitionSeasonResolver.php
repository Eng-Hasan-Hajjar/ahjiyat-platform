<?php

namespace App\Services\AiAssistant\Tools;

use App\Models\Season;
use App\Services\SeasonalRecognition\SeasonalRecognitionVisibility;
use Carbon\CarbonImmutable;

/**
 * E45 - يحدّد موسم الاعتراف الموسمي (دوري الكتّاب / داعمو الموسم) الذي يقصده السؤال: المعرّف الصريح إن وُجد، وإلا أول موسم جارٍ/قادم متاح له الميزة،
 * وإلا أحدث موسم منتهٍ ظاهر ومتاح. الظهور العلني من SeasonalRecognitionVisibility وحده (بلا امتياز مشاهد).
 */
final class RecognitionSeasonResolver
{
    public const WRITERS = 'writers';

    public const SUPPORTERS = 'supporters';

    public function __construct(
        private readonly SeasonPresenter $seasons,
        private readonly SeasonalRecognitionVisibility $visibility,
    ) {}

    public function resolve(?string $slug, CarbonImmutable $now, string $kind): ?Season
    {
        if ($slug !== null) {
            $season = $this->seasons->visibleBySlug($slug, $now);

            return $season !== null && $this->available($season, $kind) ? $season : null;
        }

        foreach ($this->seasons->active($now, 12) as $season) {
            if ($this->available($season, $kind)) {
                return $season;
            }
        }

        $past = Season::query()->publiclyVisible($now)->ended($now)->with('campaign')
            ->orderByRaw('COALESCE(seasons.ends_at, campaigns.ends_at) DESC')->orderByDesc('seasons.id')->limit(6)->get();

        foreach ($past as $season) {
            if ($this->available($season, $kind)) {
                return $season;
            }
        }

        return null;
    }

    public function available(Season $season, string $kind): bool
    {
        return $kind === self::WRITERS
            ? $this->visibility->writersAvailable($season, null)
            : $this->visibility->supportersAvailable($season, null);
    }
}

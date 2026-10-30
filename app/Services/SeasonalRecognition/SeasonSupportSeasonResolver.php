<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Services\SeasonLifecycleService;

/**
 * E44 - أي موسم يستقبل نقاط دعم المتجر العامة؟ **قاعدة الاستبعاد من الغموض**: موسم واحد بالضبط «مؤهَّل» (مفتاح المنصة ∧ supporters_enabled ∧ حيّ الآن ∧ لم تُنهَ لقطة داعميه).
 *
 *  - صفر مواسم مؤهَّلة ⇒ لا نقاط (والشراء ينجح كالمعتاد).
 *  - أكثر من موسم ⇒ **لا تخمين**: لا منح، والمستدعي يسجّل مخالفة مطابقة وتنبيهًا إداريًا، ويصحّح المشرف يدويًا.
 * بطاقات الداعم لا تمرّ من هنا: موسمها معروف صراحةً من الباقة.
 */
class SeasonSupportSeasonResolver
{
    public const NONE = 'none';

    public const ONE = 'one';

    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{status: string, season: ?Season, ids: list<int>}
     */
    public function resolve(): array
    {
        if (! $this->settings->supportersGloballyEnabled()) {
            return ['status' => self::NONE, 'season' => null, 'ids' => []];
        }

        $ids = SeasonRecognitionSetting::query()
            ->where('supporters_enabled', true)
            ->whereNull('supporters_finalized_at')
            ->pluck('season_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return ['status' => self::NONE, 'season' => null, 'ids' => []];
        }

        $live = Season::query()->whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn (Season $season) => $this->lifecycle->isLive($season))
            ->values();

        return match ($live->count()) {
            0 => ['status' => self::NONE, 'season' => null, 'ids' => []],
            1 => ['status' => self::ONE, 'season' => $live->first(), 'ids' => [(int) $live->first()->id]],
            default => ['status' => self::AMBIGUOUS, 'season' => null, 'ids' => $live->pluck('id')->map(fn ($id) => (int) $id)->all()],
        };
    }

    /** الموسم الحيّ الوحيد المؤهَّل أو null (للعرض: الأسلوب والشارة). */
    public function currentSeason(): ?Season
    {
        $r = $this->resolve();

        return $r['status'] === self::ONE ? $r['season'] : null;
    }
}

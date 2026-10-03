<?php

namespace App\Services\Advertising;

use App\Models\AdPlacement;
use App\Services\Advertising\Providers\AdRenderResult;
use App\Services\PlatformSettingsService;
use Illuminate\Http\Request;

/**
 * نقطة الدخول الموحَّدة الوحيدة لعرض إعلان. الفحص الأول دائمًا: هل الإعلانات
 * مُفعَّلة عالميًا؟ إن لا: NoAd فورًا - صفر استعلام، صفر مُزوِّد، صفر تتبُّع.
 * ميزانية الصفحة مُقيَّدة بالطلب (Request-Scoped) عبر attributes الطلب نفسه.
 */
class AdServingService
{
    public function __construct(
        protected PlatformSettingsService $settings,
        protected AdProviderRegistry $providers,
        protected Request $request,
    ) {}

    public function serve(string $placementInternalKey, bool $isMobile): AdRenderResult
    {
        if (! $this->settings->get('advertising', 'ads_enabled', false)) {
            return AdRenderResult::none();
        }

        if (! AdPlacementRegistry::isKnown($placementInternalKey)) {
            return AdRenderResult::none();
        }

        if (! $this->pageBudgetAllows($isMobile)) {
            return AdRenderResult::none();
        }

        $placement = AdPlacement::where('internal_key', $placementInternalKey)->where('is_active', true)->first();

        if ($placement === null) {
            return AdRenderResult::none();
        }

        if (($isMobile && ! $placement->mobile_enabled) || (! $isMobile && ! $placement->desktop_enabled)) {
            return AdRenderResult::none();
        }

        $result = AdRenderResult::none();

        if ($this->settings->get('advertising', 'direct_sponsors_enabled', false)) {
            $result = $this->providers->direct()->selectFor($placementInternalKey);
        }

        if (! $result->hasAd && $this->settings->get('advertising', 'external_ads_enabled', false)) {
            $external = $this->providers->external();

            if ($external->isConfigured()) {
                $result = $external->selectFor($placementInternalKey);
            }
        }

        if ($result->hasAd) {
            $this->incrementPageBudget();
        }

        return $result;
    }

    protected function pageBudgetAllows(bool $isMobile): bool
    {
        $max = $isMobile
            ? $this->settings->get('advertising', 'max_ads_mobile', 1)
            : $this->settings->get('advertising', 'max_ads_desktop', 2);

        return $this->currentPageCount() < $max;
    }

    protected function currentPageCount(): int
    {
        return (int) $this->request->attributes->get('ads_rendered_count', 0);
    }

    protected function incrementPageBudget(): void
    {
        $this->request->attributes->set('ads_rendered_count', $this->currentPageCount() + 1);
    }
}
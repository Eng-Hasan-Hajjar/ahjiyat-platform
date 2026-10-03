<?php

namespace App\Services\Advertising;

use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Support\AnalyticsCache;
use Illuminate\Support\Facades\Cache;

/**
 * تحليلات الراعي المباشر فقط - إجمالي بحت، صفر Drill-down فردي.
 * إعادة استخدام AnalyticsCache الحالية حصرًا.
 */
class AdvertisingAnalyticsService
{
    public function overview(): array
    {
        return Cache::remember(AnalyticsCache::key('advertising:overview'), now()->addMinutes(15), function () {
            $impressions = AdImpression::count();
            $clicks = AdClick::count();

            return [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            ];
        });
    }

    public function byPlacement(): array
    {
        return Cache::remember(AnalyticsCache::key('advertising:by_placement'), now()->addMinutes(15), function () {
            return AdImpression::query()
                ->selectRaw('ad_placement_id, count(*) as impressions')
                ->groupBy('ad_placement_id')
                ->get()
                ->map(fn ($row) => [
                    'placement_id' => $row->ad_placement_id,
                    'placement_name' => AdPlacement::find($row->ad_placement_id)?->name,
                    'impressions' => $row->impressions,
                    'clicks' => AdClick::where('ad_placement_id', $row->ad_placement_id)->count(),
                ])
                ->all();
        });
    }

    public function byCampaign(): array
    {
        return Cache::remember(AnalyticsCache::key('advertising:by_campaign'), now()->addMinutes(15), function () {
            return AdImpression::query()
                ->selectRaw('sponsor_campaign_id, count(*) as impressions')
                ->groupBy('sponsor_campaign_id')
                ->get()
                ->map(fn ($row) => [
                    'campaign_id' => $row->sponsor_campaign_id,
                    'impressions' => $row->impressions,
                    'clicks' => AdClick::where('sponsor_campaign_id', $row->sponsor_campaign_id)->count(),
                ])
                ->all();
        });
    }
}
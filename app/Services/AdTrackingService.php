<?php

namespace App\Services\Advertising;

use App\Models\AdClick;
use App\Models\AdImpression;
use Illuminate\Support\Facades\Log;

/**
 * E14 (بند 586/607-610): مسؤولية واحدة - تسجيل Impression/Click للراعي
 * المباشر فقط (المزوِّد الخارجي له تقاريره المستقلة). فشل التسجيل لا يكسر
 * الصفحة ولا يمنع التحويل إطلاقًا - يُسجَّل بصمت بالـLog فقط. صفر user_id،
 * صفر IP، صفر User Agent - إجمالي بحت بتصميم الجدول نفسه.
 */
class AdTrackingService
{
    public function recordImpression(int $placementId, int $campaignId, int $creativeId): void
    {
        try {
            AdImpression::create([
                'ad_placement_id' => $placementId,
                'sponsor_campaign_id' => $campaignId,
                'sponsor_creative_id' => $creativeId,
                'rendered_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('فشل تسجيل Impression إعلاني - الصفحة تستمر بشكل طبيعي', ['error' => $e->getMessage()]);
        }
    }

    public function recordClick(int $placementId, int $campaignId, int $creativeId): void
    {
        try {
            AdClick::create([
                'ad_placement_id' => $placementId,
                'sponsor_campaign_id' => $campaignId,
                'sponsor_creative_id' => $creativeId,
                'clicked_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('فشل تسجيل Click إعلاني - التحويل يستمر بشكل طبيعي', ['error' => $e->getMessage()]);
        }
    }
}

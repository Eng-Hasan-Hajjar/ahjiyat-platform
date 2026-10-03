<?php

namespace App\Services\Advertising\Providers;

use App\Models\AdPlacement;
use App\Models\SponsorCampaign;

/**
 * المُزوِّد الداخلي الحقيقي - يختار حملة مُعتمَدة ومُجدوَلة حاليًا فعليًا لهذا
 * الموضع. استراتيجية حتمية بسيطة: أولوية تنازلية، ثم الأقدم إنشاءً عند التساوي.
 */
class DirectSponsorProvider implements AdProviderContract
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function selectFor(string $placementInternalKey): AdRenderResult
    {
        $placement = AdPlacement::where('internal_key', $placementInternalKey)
            ->where('is_active', true)
            ->first();

        if ($placement === null) {
            return AdRenderResult::none();
        }

        $campaign = SponsorCampaign::query()
            ->where('status', SponsorCampaign::STATUS_APPROVED)
            ->whereHas('placements', fn ($q) => $q->where('ad_placements.id', $placement->id))
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get()
            ->first(fn (SponsorCampaign $c) => $c->isCurrentlyWithinSchedule());

        if ($campaign === null) {
            return AdRenderResult::none();
        }

        $creative = $campaign->activeCreatives()->first();

        if ($creative === null) {
            return AdRenderResult::none();
        }

        return AdRenderResult::direct(
            placementId: $placement->id,
            campaignId: $campaign->id,
            creativeId: $creative->id,
            title: $creative->title,
            body: $creative->body,
            ctaLabel: $creative->cta_label,
            imagePath: $creative->image_path,
            altText: $creative->alt_text,
            clickUrl: route('ads.click', $creative->id),
        );
    }
}
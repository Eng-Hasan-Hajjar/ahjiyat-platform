<?php

use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\Advertising\AdvertisingAnalyticsService;

beforeEach(function () {
    $this->analytics = app(AdvertisingAnalyticsService::class);
    $this->placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $this->campaign = SponsorCampaign::factory()->approved()->create();
});

test('overview computes impressions, clicks, and CTR correctly', function () {
    AdImpression::create(['ad_placement_id' => $this->placement->id, 'sponsor_campaign_id' => $this->campaign->id, 'sponsor_creative_id' => 1, 'rendered_at' => now()]);
    AdImpression::create(['ad_placement_id' => $this->placement->id, 'sponsor_campaign_id' => $this->campaign->id, 'sponsor_creative_id' => 1, 'rendered_at' => now()]);
    AdClick::create(['ad_placement_id' => $this->placement->id, 'sponsor_campaign_id' => $this->campaign->id, 'sponsor_creative_id' => 1, 'clicked_at' => now()]);

    $overview = $this->analytics->overview();

    expect($overview['impressions'])->toBe(2)
        ->and($overview['clicks'])->toBe(1)
        ->and($overview['ctr'])->toBe(50.0);
});

test('CTR is zero (not a division error) when there are zero impressions', function () {
    $overview = $this->analytics->overview();

    expect($overview['impressions'])->toBe(0)->and($overview['ctr'])->toBe(0.0);
});

test('analytics payload contains no user identity or PII fields', function () {
    AdImpression::create(['ad_placement_id' => $this->placement->id, 'sponsor_campaign_id' => $this->campaign->id, 'sponsor_creative_id' => 1, 'rendered_at' => now()]);

    $byCampaign = $this->analytics->byCampaign();
    $json = json_encode($byCampaign);

    expect($json)->not->toContain('email')->not->toContain('user_id')->not->toContain('ip_address');
});

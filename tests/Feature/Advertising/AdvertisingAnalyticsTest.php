<?php

use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\Advertising\AdvertisingAnalyticsService;

beforeEach(function () {
    $this->analytics = app(AdvertisingAnalyticsService::class);
    $this->placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $this->campaign = SponsorCampaign::factory()->approved()->create();
    // مادة حقيقية: جداول التتبُّع لها مفاتيح أجنبية فعلية، فلا يصح رقم وهمي.
    $this->creative = SponsorCreative::factory()->for($this->campaign, 'campaign')->create();
});

function e14Impression(object $t): void
{
    AdImpression::create([
        'ad_placement_id' => $t->placement->id,
        'sponsor_campaign_id' => $t->campaign->id,
        'sponsor_creative_id' => $t->creative->id,
        'rendered_at' => now(),
    ]);
}

function e14Click(object $t): void
{
    AdClick::create([
        'ad_placement_id' => $t->placement->id,
        'sponsor_campaign_id' => $t->campaign->id,
        'sponsor_creative_id' => $t->creative->id,
        'clicked_at' => now(),
    ]);
}

test('overview computes impressions, clicks, and CTR correctly', function () {
    e14Impression($this);
    e14Impression($this);
    e14Click($this);

    $overview = $this->analytics->overview();

    expect($overview['impressions'])->toBe(2)
        ->and($overview['clicks'])->toBe(1)
        ->and($overview['ctr'])->toBe(50.0);
});

test('CTR is zero (not a division error) when there are zero impressions', function () {
    $overview = $this->analytics->overview();

    expect($overview['impressions'])->toBe(0)->and($overview['ctr'])->toBe(0.0);
});

test('by-campaign and by-placement groupings count correctly', function () {
    e14Impression($this);
    e14Impression($this);
    e14Click($this);

    $byCampaign = $this->analytics->byCampaign();
    $byPlacement = $this->analytics->byPlacement();

    expect($byCampaign[0]['campaign_id'])->toBe($this->campaign->id)
        ->and($byCampaign[0]['impressions'])->toBe(2)
        ->and($byCampaign[0]['clicks'])->toBe(1)
        ->and($byPlacement[0]['placement_id'])->toBe($this->placement->id)
        ->and($byPlacement[0]['impressions'])->toBe(2);
});

test('analytics payload contains no user identity or PII fields', function () {
    e14Impression($this);

    $json = json_encode([$this->analytics->byCampaign(), $this->analytics->byPlacement(), $this->analytics->overview()]);

    expect($json)->not->toContain('email')->not->toContain('user_id')->not->toContain('ip_address');
});
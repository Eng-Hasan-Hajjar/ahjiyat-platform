<?php

use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\Advertising\AdServingService;
use App\Services\PlatformSettingsService;

beforeEach(function () {
    $this->settings = app(PlatformSettingsService::class);
    $this->serving = app(AdServingService::class);
});

function e14ApprovedCampaign(string $placementKey): SponsorCampaign
{
    $placement = AdPlacement::factory()->known($placementKey)->create();
    $campaign = SponsorCampaign::factory()->approved()->create(['priority' => 10]);
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    return $campaign;
}

test('global kill switch off: no ad regardless of everything else being eligible', function () {
    $this->settings->set('advertising', 'ads_enabled', false);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    e14ApprovedCampaign(AdPlacementRegistry::HOME_INLINE_PRIMARY);

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

test('direct sponsors disabled: an approved campaign still does not render', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', false);
    e14ApprovedCampaign(AdPlacementRegistry::HOME_INLINE_PRIMARY);

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

test('external disabled and no direct campaign: no ad, no exception', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->settings->set('advertising', 'external_ads_enabled', false);
    AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

test('external enabled but missing Google config: no external ad, no exception (item 638)', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', false);
    $this->settings->set('advertising', 'external_ads_enabled', true);
    AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();

    $result = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false);

    expect($result->hasAd)->toBeFalse();
});

test('direct eligible campaign is selected over attempting external fallback', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->settings->set('advertising', 'external_ads_enabled', true);
    e14ApprovedCampaign(AdPlacementRegistry::HOME_INLINE_PRIMARY);

    $result = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false);

    expect($result->hasAd)->toBeTrue()->and($result->providerType)->toBe('direct');
});

// ===== Page budget (item 639) =====
test('desktop page budget of 2 across two placements: both render when under budget', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->settings->set('advertising', 'max_ads_desktop', 2);
    e14ApprovedCampaign(AdPlacementRegistry::HOME_INLINE_PRIMARY);
    e14ApprovedCampaign(AdPlacementRegistry::LEADERBOARD_SIDEBAR);

    $first = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false);
    $second = $this->serving->serve(AdPlacementRegistry::LEADERBOARD_SIDEBAR, false);

    expect($first->hasAd)->toBeTrue()->and($second->hasAd)->toBeTrue();
});

test('desktop page budget of 1: the second slot on the same request does not render', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->settings->set('advertising', 'max_ads_desktop', 1);
    e14ApprovedCampaign(AdPlacementRegistry::HOME_INLINE_PRIMARY);
    e14ApprovedCampaign(AdPlacementRegistry::LEADERBOARD_SIDEBAR);

    $first = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false);
    $second = $this->serving->serve(AdPlacementRegistry::LEADERBOARD_SIDEBAR, false);

    expect($first->hasAd)->toBeTrue()->and($second->hasAd)->toBeFalse();
});

test('mobile budget of 1 is enforced independently when isMobile=true', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->settings->set('advertising', 'max_ads_mobile', 1);
    e14ApprovedCampaign(AdPlacementRegistry::HOME_INLINE_PRIMARY);
    e14ApprovedCampaign(AdPlacementRegistry::LEADERBOARD_SIDEBAR);

    $first = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, true);
    $second = $this->serving->serve(AdPlacementRegistry::LEADERBOARD_SIDEBAR, true);

    expect($first->hasAd)->toBeTrue()->and($second->hasAd)->toBeFalse();
});

test('desktop_enabled=false on a placement means no ad on desktop even if globally eligible', function () {
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create(['desktop_enabled' => false]);
    $campaign = SponsorCampaign::factory()->approved()->create();
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

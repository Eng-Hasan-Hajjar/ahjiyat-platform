<?php

use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\Advertising\AdServingService;
use App\Services\Advertising\AdTrackingService;
use App\Services\PlatformSettingsService;

beforeEach(function () {
    $settings = app(PlatformSettingsService::class);
    $settings->set('advertising', 'ads_enabled', true);
    $settings->set('advertising', 'direct_sponsors_enabled', true);

    $this->placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $this->campaign = SponsorCampaign::factory()->approved()->create();
    $this->campaign->placements()->attach($this->placement->id);
    $this->creative = SponsorCreative::factory()->for($this->campaign, 'campaign')
        ->create(['destination_url' => 'https://real-destination.example.com/landing']);
});

test('a real direct render creates exactly one impression record', function () {
    $this->get(route('home'))->assertOk();

    expect(AdImpression::count())->toBe(1)
        ->and(AdImpression::first()->sponsor_campaign_id)->toBe($this->campaign->id);
});

test('impression tracking failure never breaks the page (item 644)', function () {
    $tracking = Mockery::mock(AdTrackingService::class);
    $tracking->shouldReceive('recordImpression')->andThrow(new \RuntimeException('DB down'));
    $this->app->instance(AdTrackingService::class, $tracking);

    $this->get(route('home'))->assertOk();
});

test('clicking the tracking route records a click and redirects to the stored URL - not a client-supplied one', function () {
    $response = $this->get(route('ads.click', $this->creative));

    $response->assertRedirect('https://real-destination.example.com/landing');
    expect(AdClick::count())->toBe(1);
});

test('the click route only accepts an identifier - no url query parameter is honored', function () {
    $response = $this->get(route('ads.click', $this->creative).'?url=https://evil.example.com');

    $response->assertRedirect('https://real-destination.example.com/landing');
});

test('click tracking failure still allows a safe redirect to proceed (item 643)', function () {
    $tracking = Mockery::mock(AdTrackingService::class);
    $tracking->shouldReceive('recordClick')->andThrow(new \RuntimeException('DB down'));
    $this->app->instance(AdTrackingService::class, $tracking);

    $response = $this->get(route('ads.click', $this->creative));

    $response->assertRedirect('https://real-destination.example.com/landing');
});

test('an unsafe destination URL is never redirected to, even if it slipped past review (security > analytics, item 435)', function () {
    $this->creative->forceFill(['destination_url' => 'javascript:alert(1)'])->saveQuietly();

    $this->get(route('ads.click', $this->creative))->assertNotFound();
    expect(AdClick::count())->toBe(0);
});

// ===== XSS (item 645) =====
test('sponsor creative text is rendered escaped, never executed', function () {
    $this->creative->update(['title' => '<script>alert(1)</script>']);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('<script>alert(1)</script>', false);
    $response->assertSee('&lt;script&gt;', false);
});

// ===== No raw HTML field exists at all (item 646) =====
test('SponsorCreative model has no raw html/script/iframe fillable field', function () {
    $fillable = (new \App\Models\SponsorCreative())->getFillable();

    expect($fillable)->not->toContain('html')
        ->and($fillable)->not->toContain('script')
        ->and($fillable)->not->toContain('embed_code')
        ->and($fillable)->not->toContain('iframe_html');
});

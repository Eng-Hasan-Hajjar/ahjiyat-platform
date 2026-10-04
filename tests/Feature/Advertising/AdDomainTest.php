<?php

use App\Exceptions\AdInvariantViolation;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\User;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\Advertising\AdServingService;
use App\Services\Advertising\SponsorCampaignService;
use App\Services\Advertising\UrlSafetyGuard;
use App\Services\PlatformSettingsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->settings = app(PlatformSettingsService::class);
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->serving = app(AdServingService::class);
    $this->campaigns = app(SponsorCampaignService::class);
    $this->admin = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

// ===== Registry =====
test('an unknown placement key never renders - no crash, no ad', function () {
    $result = $this->serving->serve('totally_unknown_placement', false);
    expect($result->hasAd)->toBeFalse();
});

test('a disabled placement never renders even with an approved campaign', function () {
    AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->inactive()->create();
    $result = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false);
    expect($result->hasAd)->toBeFalse();
});

// ===== URL safety =====
test('unsafe URL schemes are rejected by the domain guard', function () {
    expect(UrlSafetyGuard::isSafe('javascript:alert(1)'))->toBeFalse()
        ->and(UrlSafetyGuard::isSafe('data:text/html,hi'))->toBeFalse()
        ->and(UrlSafetyGuard::isSafe('file:///etc/passwd'))->toBeFalse()
        ->and(UrlSafetyGuard::isSafe('http://example.com'))->toBeFalse() // https فقط
        ->and(UrlSafetyGuard::isSafe('not a url'))->toBeFalse()
        ->and(UrlSafetyGuard::isSafe('https://example.com/landing'))->toBeTrue();
});

// ===== Campaign state transitions =====
test('a draft campaign cannot be approved directly - must go through pending_review', function () {
    $campaign = SponsorCampaign::factory()->create();

    expect(fn () => $this->campaigns->approve($campaign, $this->admin))->toThrow(AdInvariantViolation::class);
});

test('submitting for review requires at least one active creative', function () {
    $campaign = SponsorCampaign::factory()->create();

    expect(fn () => $this->campaigns->submitForReview($campaign))->toThrow(AdInvariantViolation::class);
});

test('full lifecycle: draft -> pending_review -> approved -> paused -> approved', function () {
    $campaign = SponsorCampaign::factory()->create();
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();
    $campaign->placements()->attach(AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create()->id);

    $this->campaigns->submitForReview($campaign);
    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);

    $this->campaigns->approve($campaign, $this->admin);
    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED)
        ->and($campaign->fresh()->approved_by)->toBe($this->admin->id);

    $this->campaigns->pause($campaign, $this->admin);
    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PAUSED);

    $this->campaigns->resume($campaign, $this->admin);
    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED);
});

test('rejecting a campaign requires a note and records it', function () {
    $campaign = SponsorCampaign::factory()->pendingReview()->create();
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    $this->campaigns->reject($campaign, $this->admin, 'صفحة الهبوط غير آمنة');

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_REJECTED)
        ->and($campaign->fresh()->review_note)->toBe('صفحة الهبوط غير آمنة');
});

// ===== Sensitive edit re-review (domain-level, item 339-341) =====
test('changing a creative destination_url on an approved campaign resets it to pending_review', function () {
    $campaign = SponsorCampaign::factory()->approved()->create();
    $creative = SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly(['destination_url' => 'https://old.example.com']);

    $this->campaigns->updateCreative($creative, ['destination_url' => 'https://new.example.com'], $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW)
        ->and($campaign->fresh()->approved_by)->toBeNull();
});

test('changing a creative title on an approved campaign resets it to pending_review', function () {
    $campaign = SponsorCampaign::factory()->approved()->create();
    $creative = SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly(['title' => 'قديم']);

    $this->campaigns->updateCreative($creative, ['title' => 'جديد تمامًا'], $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});

test('changing only priority on an approved campaign does NOT reset review', function () {
    $campaign = SponsorCampaign::factory()->approved()->create(['priority' => 5]);

    $this->campaigns->updateCampaignMeta($campaign, ['priority' => 50], $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED)
        ->and($campaign->fresh()->priority)->toBe(50);
});

test('changing sponsor_name on an approved campaign resets review', function () {
    $campaign = SponsorCampaign::factory()->approved()->create(['sponsor_name' => 'الراعي القديم']);

    $this->campaigns->updateCampaignMeta($campaign, ['sponsor_name' => 'راعٍ مختلف كليًا'], $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});

test('an unsafe destination URL cannot pass approval even if it slipped into a creative', function () {
    $campaign = SponsorCampaign::factory()->pendingReview()->create();
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();
    $campaign->placements()->attach(AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create()->id);
    // محاكاة فساد بقاعدة البيانات خارج النموذج (الحارس يرفض هذا الرابط عند الإدخال الآن) - approve() تفحص من جديد.
    $campaign->creatives()->first()->forceFill(['destination_url' => 'javascript:alert(1)'])->saveQuietly();

    expect(fn () => $this->campaigns->approve($campaign, $this->admin))->toThrow(AdInvariantViolation::class);
});

// ===== Same-day boundaries (lesson from E13.1, item 632) =====
test('a campaign starting later today is not servable now, but becomes servable after its start time', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->approved()->create(['starts_at' => Carbon::parse('2026-10-05 18:00:00', 'UTC')]);
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-05 19:00:00', 'UTC'));
    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeTrue();
});

test('a campaign ending earlier today is no longer servable after its end time, same day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->approved()->create(['ends_at' => Carbon::parse('2026-10-05 12:00:00', 'UTC')]);
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-10-05 18:00:00', 'UTC'));
    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

// ===== Priority selection =====
test('two eligible campaigns for the same placement - the higher priority one is selected', function () {
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();

    $low = SponsorCampaign::factory()->approved()->create(['priority' => 1]);
    $low->placements()->attach($placement->id);
    SponsorCreative::factory()->for($low, 'campaign')->createQuietly(['title' => 'منخفضة الأولوية']);

    $high = SponsorCampaign::factory()->approved()->create(['priority' => 99]);
    $high->placements()->attach($placement->id);
    SponsorCreative::factory()->for($high, 'campaign')->createQuietly(['title' => 'عالية الأولوية']);

    $result = $this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false);

    expect($result->title)->toBe('عالية الأولوية');
});

test('a rejected campaign never renders even when its schedule and priority would otherwise qualify', function () {
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->rejected()->create(['priority' => 99]);
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

test('a paused campaign never renders', function () {
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->paused()->create();
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect($this->serving->serve(AdPlacementRegistry::HOME_INLINE_PRIMARY, false)->hasAd)->toBeFalse();
});

<?php

use App\Models\Campaign;
use App\Models\Season;
use App\Services\SeasonReadinessService;

test('SeasonResource file upload directories are generic - no hardcoded Aseel path', function () {
    $source = file_get_contents(app_path('Filament/Resources/SeasonResource.php'));

    expect($source)->not->toContain('seasons/aseel')
        ->and($source)->toContain('seasons/branding');
});

test('CampaignResource cover image upload has explicit type/size validation', function () {
    $source = file_get_contents(app_path('Filament/Resources/CampaignResource.php'));

    expect($source)->toContain('acceptedFileTypes')
        ->and($source)->toContain('maxSize');
});

test('a season logo/banner path can be set and read back regardless of the campaign slug', function () {
    $campaign = Campaign::factory()->create(['slug' => 'some-other-campaign-not-aseel']);
    $season = Season::factory()->create([
        'campaign_id' => $campaign->id,
        'logo_image' => 'seasons/branding/logo-xyz.png',
        'banner_image' => 'seasons/branding/banner-abc.png',
    ]);

    expect($season->fresh()->logo_image)->toBe('seasons/branding/logo-xyz.png')
        ->and($season->fresh()->banner_image)->toBe('seasons/branding/banner-abc.png');
});

test('SeasonReadinessService still correctly evaluates a season after the authoring changes', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);

    $result = app(SeasonReadinessService::class)->check($season);

    expect($result['ready'])->toBeFalse();
});

test('the campaign relationship on Season still resolves correctly for the title display placeholder', function () {
    $campaign = Campaign::factory()->create(['title' => 'حملة تجريبية للعرض']);
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);

    expect($season->fresh()->campaign->title)->toBe('حملة تجريبية للعرض');
});
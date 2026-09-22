<?php

use App\Models\RedemptionRequest;
use App\Services\Analytics\EconomyAnalyticsService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('pending, approved, and rejected counts are correct', function () {
    RedemptionRequest::factory()->count(2)->create(['status' => RedemptionRequest::STATUS_PENDING, 'created_at' => now()]);
    RedemptionRequest::factory()->count(3)->create(['status' => RedemptionRequest::STATUS_APPROVED, 'created_at' => now()]);
    RedemptionRequest::factory()->count(1)->create(['status' => RedemptionRequest::STATUS_REJECTED, 'created_at' => now()]);

    $overview = app(EconomyAnalyticsService::class)->redemptionsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['pending'])->toBe(2)
        ->and($overview['approved'])->toBe(3)
        ->and($overview['rejected'])->toBe(1)
        ->and($overview['total_requests'])->toBe(6);
});

test('the date filter is respected - requests outside the period are excluded', function () {
    RedemptionRequest::factory()->create(['status' => RedemptionRequest::STATUS_PENDING, 'created_at' => now()->subDays(60)]);

    $overview = app(EconomyAnalyticsService::class)->redemptionsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['total_requests'])->toBe(0);
});

test('approval rate excludes pending requests from its denominator', function () {
    RedemptionRequest::factory()->count(5)->create(['status' => RedemptionRequest::STATUS_PENDING, 'created_at' => now()]);
    RedemptionRequest::factory()->count(3)->create(['status' => RedemptionRequest::STATUS_APPROVED, 'created_at' => now()]);
    RedemptionRequest::factory()->count(1)->create(['status' => RedemptionRequest::STATUS_REJECTED, 'created_at' => now()]);

    $overview = app(EconomyAnalyticsService::class)->redemptionsOverview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['approval_rate'])->toBe(75.0);
});

test('no requests in the period does not cause a division-by-zero error', function () {
    $overview = app(EconomyAnalyticsService::class)->redemptionsOverview(AnalyticsPeriod::fromPreset('today'));

    expect($overview['approval_rate'])->toBe(0.0)
        ->and($overview['avg_processing_hours'])->toBeNull();
});

test('a request is not counted twice across two overlapping analytics calls', function () {
    RedemptionRequest::factory()->create(['status' => RedemptionRequest::STATUS_APPROVED, 'created_at' => now()]);

    $service = app(EconomyAnalyticsService::class);
    $period = AnalyticsPeriod::fromPreset('last_30_days');

    expect($service->redemptionsOverview($period)['approved'])->toBe($service->redemptionsOverview($period)['approved']);
});
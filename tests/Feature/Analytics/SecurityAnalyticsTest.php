<?php

use App\Models\FraudFlag;
use App\Models\User;
use App\Services\Analytics\SecurityAnalyticsService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('open and resolved fraud flag counts are correct', function () {
    FraudFlag::factory()->count(3)->create(['resolved' => false]);
    FraudFlag::factory()->count(2)->create(['resolved' => true]);

    $overview = app(SecurityAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['open_fraud_flags'])->toBe(3)
        ->and($overview['resolved_fraud_flags'])->toBe(2);
});

test('frozen user count is correct', function () {
    User::factory()->count(2)->create(['is_frozen' => true]);
    User::factory()->create(['is_frozen' => false]);

    $overview = app(SecurityAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    expect($overview['frozen_users'])->toBe(2);
});

test('unauthorized users are denied security analytics via the page, not just hidden in the UI', function () {
    $contentManager = User::factory()->create();
    $contentManager->assignRole('content-manager');

    $this->actingAs($contentManager);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();

    expect($page->securityAnalytics())->toBeNull();
});

test('the security analytics response never includes a raw IP address or device fingerprint', function () {
    $overview = app(SecurityAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    $serialized = json_encode($overview);

    expect($serialized)->not->toContain('ip_address')
        ->and($serialized)->not->toContain('device_hash');
});

test('admin actions breakdown reflects operational audit log entries', function () {
    $actor = User::factory()->create();
    \App\Models\OperationalAuditLog::create(['actor_user_id' => $actor->id, 'action' => 'user_frozen', 'created_at' => now()]);
    \App\Models\OperationalAuditLog::create(['actor_user_id' => $actor->id, 'action' => 'user_frozen', 'created_at' => now()]);

    $overview = app(SecurityAnalyticsService::class)->overview(AnalyticsPeriod::fromPreset('last_30_days'));

    $frozenAction = collect($overview['admin_actions_in_period'])->firstWhere('action', 'تجميد حساب');

    expect($frozenAction['count'])->toBe(2);
});
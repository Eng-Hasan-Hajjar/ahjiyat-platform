<?php

use App\Models\SponsorCampaign;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('an administrator can access both advertising admin pages', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/ad-placements')->assertOk();
    $this->actingAs($admin)->get('/admin/sponsor-campaigns')->assertOk();
});

test('a content-manager can create and view campaigns but cannot review/approve', function () {
    $cm = User::factory()->create();
    $cm->assignRole('content-manager');

    expect($cm->can('ads.campaigns.create'))->toBeTrue()
        ->and($cm->can('ads.campaigns.view'))->toBeTrue()
        ->and($cm->can('ads.campaigns.review'))->toBeFalse(); // بند 598: منع الاعتماد الذاتي
});

test('a support user can view campaigns and analytics but cannot create or review', function () {
    $support = User::factory()->create();
    $support->assignRole('support');

    expect($support->can('ads.campaigns.view'))->toBeTrue()
        ->and($support->can('ads.analytics.view'))->toBeTrue()
        ->and($support->can('ads.campaigns.create'))->toBeFalse()
        ->and($support->can('ads.campaigns.review'))->toBeFalse();
});

test('a moderator has zero advertising permissions by default', function () {
    $mod = User::factory()->create();
    $mod->assignRole('moderator');

    expect($mod->can('ads.campaigns.view'))->toBeFalse()
        ->and($mod->can('ads.placements.manage'))->toBeFalse();
});

test('a player has zero advertising admin permissions', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    expect($player->can('ads.campaigns.view'))->toBeFalse()
        ->and($player->can('ads.settings.manage'))->toBeFalse();
});

test('direct URL access to the sponsor campaigns admin page returns 403 for an unauthorized role', function () {
    $player = User::factory()->create();
    $player->assignRole('player');

    $this->actingAs($player)->get('/admin/sponsor-campaigns')->assertForbidden();
});

test('a user without ads.campaigns.review cannot approve a campaign via the service - authorization is checked at the policy level', function () {
    $cm = User::factory()->create();
    $cm->assignRole('content-manager');
    $campaign = SponsorCampaign::factory()->pendingReview()->create();

    expect($cm->can('review', $campaign))->toBeFalse();
});

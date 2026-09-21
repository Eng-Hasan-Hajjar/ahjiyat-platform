<?php

use App\Models\FraudFlag;
use App\Models\RedemptionRequest;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('operations.dashboard_view permission is required to access the Operations Center', function () {
    $authorized = User::factory()->create();
    $authorized->assignRole('administrator');

    $unauthorized = User::factory()->create();
    $unauthorized->assignRole('content-manager');

    $this->actingAs($authorized)->get(\App\Filament\Pages\OperationsCenter::getUrl())->assertOk();

    $response = $this->actingAs($unauthorized)->get(\App\Filament\Pages\OperationsCenter::getUrl());
    expect($response->status())->toBe(403);
});

test('pending redemptions and open fraud counts are accurate', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    RedemptionRequest::factory()->count(3)->create(['status' => RedemptionRequest::STATUS_PENDING]);
    RedemptionRequest::factory()->count(2)->create(['status' => RedemptionRequest::STATUS_APPROVED]);
    FraudFlag::factory()->count(2)->create(['resolved' => false]);
    FraudFlag::factory()->count(4)->create(['resolved' => true]);

    $this->actingAs($admin);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\OperationsCenter::class)->instance();

    expect($page->getPendingRedemptionsCount())->toBe(3)
        ->and($page->getOpenFraudFlagsCount())->toBe(2);
});

test('a widget is hidden entirely (returns null, not zero) when the viewer lacks the underlying permission', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $this->actingAs($moderator);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\OperationsCenter::class)->instance();

    expect($page->getPendingRedemptionsCount())->toBeNull()
        ->and($page->getOpenFraudFlagsCount())->not->toBeNull();
});

test('frozen users count is only shown to a role with users.view', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    User::factory()->create(['is_frozen' => true]);

    $this->actingAs($admin);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\OperationsCenter::class)->instance();

    expect($page->getFrozenUsersCount())->toBeGreaterThanOrEqual(1);
});
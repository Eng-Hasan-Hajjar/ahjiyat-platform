<?php

use App\Models\FraudFlag;
use App\Models\User;
use App\Services\FraudDetectionService;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('fraud.view and fraud.resolve are enforced via the policy', function () {
    $resolver = User::factory()->create();
    $resolver->assignRole('moderator');

    $viewer = User::factory()->create();
    $viewer->assignRole('content-manager');

    $flag = FraudFlag::factory()->create();

    expect(Gate::forUser($resolver)->allows('viewAny', FraudFlag::class))->toBeTrue()
        ->and(Gate::forUser($resolver)->allows('resolve', $flag))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('viewAny', FraudFlag::class))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('resolve', $flag))->toBeFalse();
});

test('resolving a fraud flag changes its state exactly once and records who/when/why', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $flag = FraudFlag::factory()->create(['resolved' => false]);

    app(FraudDetectionService::class)->resolve($flag, $admin, 'تمت المراجعة يدوياً - لا خطر فعلي');

    $flag->refresh();
    expect($flag->resolved)->toBeTrue()
        ->and($flag->resolved_by)->toBe($admin->id)
        ->and($flag->resolved_at)->not->toBeNull()
        ->and($flag->resolution_note)->toBe('تمت المراجعة يدوياً - لا خطر فعلي');
});

test('resolving an already-resolved fraud flag is rejected', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $flag = FraudFlag::factory()->create(['resolved' => true]);

    expect(fn () => app(FraudDetectionService::class)->resolve($flag, $admin, 'محاولة ثانية'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('an unauthorized user gets denied when trying to resolve a fraud flag', function () {
    $unauthorized = User::factory()->create();
    $unauthorized->assignRole('player');

    $flag = FraudFlag::factory()->create();

    expect(Gate::forUser($unauthorized)->allows('resolve', $flag))->toBeFalse();
});

test('resolving a fraud flag never freezes the user account automatically', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $targetUser = User::factory()->create(['is_frozen' => false]);
    $flag = FraudFlag::factory()->create(['user_id' => $targetUser->id, 'resolved' => false]);

    app(FraudDetectionService::class)->resolve($flag, $admin, 'لا خطر');

    expect($targetUser->fresh()->is_frozen)->toBeFalse();
});
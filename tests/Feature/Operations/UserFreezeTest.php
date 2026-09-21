<?php

use App\Models\User;
use App\Services\UserAccountService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('an authorized admin can freeze a normal user with a required reason', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $target = User::factory()->create();

    app(UserAccountService::class)->freeze($target, $admin, 'نشاط مشبوه بالحساب');

    $target->refresh();
    expect($target->is_frozen)->toBeTrue()
        ->and($target->frozen_reason)->toBe('نشاط مشبوه بالحساب')
        ->and($target->frozen_by)->toBe($admin->id)
        ->and($target->frozen_at)->not->toBeNull();
});

test('an admin without users.freeze permission cannot freeze a user', function () {
    $limitedAdmin = User::factory()->create();
    $limitedAdmin->assignRole('support');

    $target = User::factory()->create();

    $this->actingAs($limitedAdmin);

    expect(\Illuminate\Support\Facades\Gate::forUser($limitedAdmin)->allows('freeze', $target))->toBeFalse();
});

test('an authorized admin can unfreeze a user and the previous reason is cleared', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $target = User::factory()->create(['is_frozen' => true, 'frozen_reason' => 'سبب سابق']);

    app(UserAccountService::class)->unfreeze($target, $admin);

    $target->refresh();
    expect($target->is_frozen)->toBeFalse()
        ->and($target->frozen_reason)->toBeNull()
        ->and($target->frozen_by)->toBeNull();
});

test('freeze and unfreeze are both recorded in the operational audit log', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $target = User::factory()->create();

    app(UserAccountService::class)->freeze($target, $admin, 'سبب الاختبار');
    app(UserAccountService::class)->unfreeze($target, $admin);

    expect(\App\Models\OperationalAuditLog::where('action', 'user_frozen')->where('subject_id', $target->id)->exists())->toBeTrue()
        ->and(\App\Models\OperationalAuditLog::where('action', 'user_unfrozen')->where('subject_id', $target->id)->exists())->toBeTrue();
});

test('a normal administrator cannot freeze a super admin account', function () {
    $normalAdmin = User::factory()->create();
    $normalAdmin->assignRole('administrator');

    $superAdminUser = User::factory()->create();
    $superAdminUser->assignRole('super-admin');

    expect(fn () => app(UserAccountService::class)->freeze($superAdminUser, $normalAdmin, 'محاولة'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('the last super admin cannot be frozen even by another super admin', function () {
    $onlySuperAdmin = User::factory()->create();
    $onlySuperAdmin->assignRole('super-admin');

    $actorSuperAdmin = User::factory()->create();
    $actorSuperAdmin->assignRole('super-admin');

    app(UserAccountService::class)->freeze($onlySuperAdmin, $actorSuperAdmin, 'اختبار');
    expect($onlySuperAdmin->fresh()->is_frozen)->toBeTrue();

    $anotherAdmin = User::factory()->create();
    $anotherAdmin->assignRole('administrator');

    expect(fn () => app(UserAccountService::class)->freeze($actorSuperAdmin, $anotherAdmin, 'اختبار 2'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('an admin cannot freeze their own account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    expect(fn () => app(UserAccountService::class)->freeze($admin, $admin, 'محاولة تجميد نفسي'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
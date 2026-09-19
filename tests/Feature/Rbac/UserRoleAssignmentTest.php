<?php

use App\Filament\Resources\UserResource\RoleAssignmentSaver;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    // إصلاح: Cache الصلاحيات الداخلية بحزمة Spatie قد تبقى من حالة سابقة
    // ضمن نفس عملية الاختبارات - أي givePermissionTo()/syncPermissions()
    // بالاسم النصي مباشرة (لا عبر نماذج فعلية) قد تفشل زوراً بخطأ
    // PermissionDoesNotExist رغم وجود الصلاحية فعلياً بقاعدة البيانات.
    // مسح صريح هنا يضمن حالة نظيفة قبل كل اختبار.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

test('assigning a role via RoleAssignmentSaver grants the target user its permissions', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    $contentManagerRole = Role::where('name', 'content-manager')->firstOrFail();

    $this->actingAs($actor);
    app(RoleAssignmentSaver::class)->save($target, [$contentManagerRole->id]);

    expect($target->fresh()->hasRole('content-manager'))->toBeTrue()
        ->and($target->fresh()->can('seasons.update'))->toBeTrue();
});

test('a newly registered user automatically receives the player role', function () {
    $this->post(route('register'), [
        'name' => 'لاعب جديد',
        'email' => 'new-player@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $user = User::where('email', 'new-player@example.com')->firstOrFail();

    expect($user->hasRole('player'))->toBeTrue()
        ->and($user->can('admin.access'))->toBeFalse();
});

test('the registration request cannot self-assign a role - only whitelisted fields are ever used', function () {
    $this->post(route('register'), [
        'name' => 'محاولة اختراق',
        'email' => 'hacker@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
        'roles' => ['super-admin'],
        'is_admin' => true,
    ]);

    $user = User::where('email', 'hacker@example.com')->firstOrFail();

    expect($user->hasRole('super-admin'))->toBeFalse()
        ->and($user->hasRole('player'))->toBeTrue()
        ->and($user->role)->not->toBe('admin');
});

test('removing all roles from a user removes all role-based permissions', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    $target->assignRole('content-manager');
    expect($target->can('seasons.view'))->toBeTrue();

    $this->actingAs($actor);
    app(RoleAssignmentSaver::class)->save($target, []);

    expect($target->fresh()->can('seasons.view'))->toBeFalse()
        ->and($target->fresh()->roles)->toHaveCount(0);
});

test('a user can hold more than one role simultaneously', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();
    $moderator = Role::where('name', 'moderator')->firstOrFail();
    $support = Role::where('name', 'support')->firstOrFail();

    $this->actingAs($actor);
    app(RoleAssignmentSaver::class)->save($target, [$moderator->id, $support->id]);

    expect($target->fresh()->roles)->toHaveCount(2)
        ->and($target->fresh()->hasRole('moderator'))->toBeTrue()
        ->and($target->fresh()->hasRole('support'))->toBeTrue();
});
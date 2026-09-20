<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationSafetyService;
use Illuminate\Support\Facades\Gate;





beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    // إصلاح: Cache الصلاحيات الداخلية بحزمة Spatie قد تبقى من حالة سابقة
    // ضمن نفس عملية الاختبارات - أي givePermissionTo()/syncPermissions()
    // بالاسم النصي مباشرة (لا عبر نماذج فعلية) قد تفشل زوراً بخطأ
    // PermissionDoesNotExist رغم وجود الصلاحية فعلياً بقاعدة البيانات.
    // مسح صريح هنا يضمن حالة نظيفة قبل كل اختبار.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

test('a user with roles.create can create a new role', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'granter', 'guard_name' => 'web']);
    $permission = \Spatie\Permission\Models\Permission::where('name', 'roles.create')->where('guard_name', 'web')->firstOrFail();
    $role->givePermissionTo($permission);
    $user->assignRole('granter');

    expect(Gate::forUser($user)->allows('create', Role::class))->toBeTrue();
});

test('a user with roles.update can update a role, without it enforced there is no access', function () {
    $withPermission = User::factory()->create();
    $role = Role::create(['name' => 'updater', 'guard_name' => 'web']);
    $permission = \Spatie\Permission\Models\Permission::where('name', 'roles.update')->where('guard_name', 'web')->firstOrFail();
    $role->givePermissionTo($permission);
    $withPermission->assignRole('updater');

    $withoutPermission = User::factory()->create();
    $withoutPermission->assignRole('player');

    $target = Role::create(['name' => 'target-role', 'guard_name' => 'web']);

    expect(Gate::forUser($withPermission)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($withoutPermission)->allows('update', $target))->toBeFalse();
});

test('a user without roles.create cannot create a new role', function () {
    $user = User::factory()->create();
    $user->assignRole('player');

    expect(Gate::forUser($user)->allows('create', Role::class))->toBeFalse();
});



test('a system role (player) cannot be deleted', function () {
    $playerRole = Role::where('name', 'player')->firstOrFail();

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanDeleteRole($playerRole))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a role that still has users assigned cannot be deleted without detaching them first', function () {
    $role = Role::create(['name' => 'occupied-role', 'guard_name' => 'web', 'is_system' => false]);
    $user = User::factory()->create();
    $user->assignRole('occupied-role');

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanDeleteRole($role))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a custom role with no users and not marked system can be deleted freely', function () {
    $role = Role::create(['name' => 'freely-deletable', 'guard_name' => 'web', 'is_system' => false]);

    app(AuthorizationSafetyService::class)->assertCanDeleteRole($role);

    expect(true)->toBeTrue();
});

test('role metadata (Arabic label, description, color, sort order) is stored and retrieved correctly', function () {
    $role = Role::create([
        'name' => 'metadata-role', 'guard_name' => 'web',
        'label_ar' => 'دور تجريبي', 'description' => 'وصف تجريبي', 'color' => '#8b5cf6', 'sort_order' => 5,
    ]);

    expect($role->fresh()->displayLabel())->toBe('دور تجريبي')
        ->and($role->fresh()->description)->toBe('وصف تجريبي')
        ->and($role->fresh()->sort_order)->toBe(5);
});
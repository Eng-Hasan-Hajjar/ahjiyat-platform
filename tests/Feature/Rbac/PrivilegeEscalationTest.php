<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationSafetyService;
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    // إصلاح: Cache الصلاحيات الداخلية بحزمة Spatie قد تبقى من حالة سابقة
    // ضمن نفس عملية الاختبارات - أي givePermissionTo()/syncPermissions()
    // بالاسم النصي مباشرة (لا عبر نماذج فعلية) قد تفشل زوراً بخطأ
    // PermissionDoesNotExist رغم وجود الصلاحية فعلياً بقاعدة البيانات.
    // مسح صريح هنا يضمن حالة نظيفة قبل كل اختبار.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

test('a non-super-admin cannot grant the super-admin role to another user', function () {
    $normalAdmin = User::factory()->create();
    $normalAdmin->assignRole('administrator');

    $target = User::factory()->create();

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanSyncRoles($normalAdmin, $target, ['super-admin']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a super admin can grant the super-admin role to another user', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $target = User::factory()->create();

    app(AuthorizationSafetyService::class)->assertCanSyncRoles($superAdmin, $target, ['super-admin']);

    expect(true)->toBeTrue();
});

test('a user cannot assign a role containing a permission they do not personally hold', function () {
    $limitedActor = User::factory()->create();
    $limitedActor->assignRole('support');
    $limitedActor->givePermissionTo('roles.assign');

    $target = User::factory()->create();

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanSyncRoles($limitedActor, $target, ['content-manager']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a user can assign a role whose permissions are fully covered by their own', function () {
    $actor = User::factory()->create();
    $actor->assignRole('administrator');

    $target = User::factory()->create();

    app(AuthorizationSafetyService::class)->assertCanSyncRoles($actor, $target, ['content-manager']);

    expect(true)->toBeTrue();
});

test('a non-super-admin cannot modify permissions of a super-admin user', function () {
    $normalAdmin = User::factory()->create();
    $normalAdmin->assignRole('administrator');

    $superAdminUser = User::factory()->create();
    $superAdminUser->assignRole('super-admin');

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanModifyUserAuthorization($normalAdmin, $superAdminUser))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a non-super-admin cannot edit the super-admin role permissions at all', function () {
    $normalAdmin = User::factory()->create();
    $normalAdmin->assignRole('administrator');

    $superAdminRole = Role::where('name', 'super-admin')->firstOrFail();

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanSyncRolePermissions($normalAdmin, $superAdminRole, ['puzzles.view']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a non-super-admin cannot grant a role-level permission they do not hold themselves', function () {
    $limitedActor = User::factory()->create();
    $limitedActor->assignRole('support');
    $limitedActor->givePermissionTo('roles.update');

    $targetRole = Role::create(['name' => 'some-role', 'guard_name' => 'web']);

    expect(fn () => app(AuthorizationSafetyService::class)->assertCanSyncRolePermissions($limitedActor, $targetRole, ['seasons.delete']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
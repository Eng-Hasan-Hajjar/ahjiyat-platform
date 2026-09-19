<?php

use App\Console\Commands\MigrateLegacyRoles;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    // إصلاح: Cache الصلاحيات الداخلية بحزمة Spatie قد تبقى من حالة سابقة
    // ضمن نفس عملية الاختبارات - أي givePermissionTo()/syncPermissions()
    // بالاسم النصي مباشرة (لا عبر نماذج فعلية) قد تفشل زوراً بخطأ
    // PermissionDoesNotExist رغم وجود الصلاحية فعلياً بقاعدة البيانات.
    // مسح صريح هنا يضمن حالة نظيفة قبل كل اختبار.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

test('permissions:sync creates every permission defined in the registry', function () {
    $registryCount = collect(config('permissions'))->sum(fn ($m) => count($m['permissions']));

    expect(Permission::count())->toBe($registryCount);
});

test('permissions:sync is idempotent - running it twice creates no duplicates', function () {
    Artisan::call('permissions:sync');
    $before = Permission::count();

    Artisan::call('permissions:sync');

    expect(Permission::count())->toBe($before);
});

test('a role can be given permissions and a user inherits them through the role', function () {
    $role = Role::create(['name' => 'test-role', 'guard_name' => 'web', 'label_ar' => 'دور تجريبي']);
    $role->givePermissionTo('puzzles.view');

    $user = User::factory()->create();
    $user->assignRole('test-role');

    expect($user->can('puzzles.view'))->toBeTrue()
        ->and($user->can('puzzles.delete'))->toBeFalse();
});

test('direct permissions and role permissions merge into the effective permission set', function () {
    $role = Role::create(['name' => 'merge-role', 'guard_name' => 'web']);
    $role->givePermissionTo('puzzles.view');

    $user = User::factory()->create();
    $user->assignRole('merge-role');
    $user->givePermissionTo('seasons.view');

    expect($user->can('puzzles.view'))->toBeTrue()
        ->and($user->can('seasons.view'))->toBeTrue()
        ->and($user->getAllPermissions()->pluck('name'))->toContain('puzzles.view', 'seasons.view');
});

test('the permission cache is invalidated after a role permission change', function () {
    $role = Role::create(['name' => 'cache-role', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('cache-role');

    expect($user->can('puzzles.view'))->toBeFalse();

    $role->givePermissionTo('puzzles.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->fresh()->can('puzzles.view'))->toBeTrue();
});

test('an existing legacy admin retains full administrative access after migration', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Artisan::call('rbac:migrate-legacy-roles');

    $admin->refresh();
    expect($admin->hasRole('super-admin') || $admin->hasRole('administrator'))->toBeTrue()
        ->and($admin->can('admin.access'))->toBeTrue();
});

test('an existing legacy plain user becomes a player with no admin access', function () {
    $user = User::factory()->create(['role' => 'user']);

    Artisan::call('rbac:migrate-legacy-roles');

    expect($user->fresh()->hasRole('player'))->toBeTrue()
        ->and($user->fresh()->can('admin.access'))->toBeFalse();
});

test('re-running the legacy migration does not duplicate or change an already-migrated user role', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Artisan::call('rbac:migrate-legacy-roles');
    $rolesBefore = $admin->fresh()->roles->pluck('name')->sort()->values()->all();

    Artisan::call('rbac:migrate-legacy-roles');

    expect($admin->fresh()->roles->pluck('name')->sort()->values()->all())->toBe($rolesBefore);
});
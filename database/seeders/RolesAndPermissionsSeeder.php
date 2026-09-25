<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        collect(config('permissions', []))
            ->flatMap(fn($module) => array_keys($module['permissions'] ?? []))
            ->unique()
            ->each(fn($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->role('super-admin', 'المدير الأعلى', 'وصول كامل غير مقيَّد لكل أجزاء المنصة - محمي، لا يمكن حذفه.', true, '#f59e0b', 0, []);
        $this->role('administrator', 'مدير عام', 'وصول كامل لكل الوحدات الوظيفية.', true, '#8b5cf6', 1, $this->allPermissionNames());

        $this->role('content-manager', 'مدير محتوى', 'إدارة المواسم والحملات والأحجيات فقط.', true, '#22d3ee', 2, [
            'admin.access',
            'seasons.view',
            'seasons.create',
            'seasons.update',
            'seasons.delete',
            'seasons.publish',
            'seasons.preview',
            'campaigns.view',
            'campaigns.create',
            'campaigns.update',
            'campaigns.delete',
            'puzzles.view',
            'puzzles.create',
            'puzzles.update',
            'puzzles.delete',
            'puzzles.publish',
            'puzzles.manage_categories',
        ]);

        $this->role('moderator', 'مشرف', 'مراقبة المستخدمين ومكافحة الاحتيال والتحديات.', true, '#fb7185', 3, [
            'admin.access',
            'users.view',
            'fraud.view',
            'fraud.resolve',
            'challenges.view',
            'challenges.update',
        ]);

        $this->role('support', 'دعم فني', 'عرض المستخدمين وطلبات الاستبدال فقط.', true, '#34d399', 4, [
            'admin.access',
            'users.view',
            'redemptions.view',
        ]);

        $this->role('player', 'لاعب', 'الدور الافتراضي لأي مستخدم جديد - بلا وصول للوحة الإدارة.', true, '#64748b', 99, []);

        $this->grantIfMissing('administrator', [
            'users.freeze',
            'users.unfreeze',
            'users.view_security',
            'users.view_wallet',
            'users.view_activity',
            'users.manage_roles',
            'wallet.adjust',
            'security.sessions_view',
            'security.sessions_revoke',
            'operations.dashboard_view',
            'analytics.view',
            'analytics.users',
            'analytics.puzzles',
            'analytics.campaigns',
            'analytics.financial',
            'analytics.security',
            'reports.export',
            'economy.currencies.view',
            'economy.currencies.create',
            'economy.currencies.update',
            'economy.currencies.deactivate',
            'economy.packs.view',
            'economy.packs.manage',
            'economy.transactions.view',
            'store.items.view',
            'store.items.create',
            'store.items.update',
            'store.items.deactivate',
            'store.prices.manage',
            'store.purchases.view',
            'store.purchases.fulfill',
            'store.purchases.refund',
            'store.inventory.view',
            'store.entitlements.view',

        ]);

        $this->grantIfMissing('moderator', [
            'users.view_security',
            'users.view_activity',
            'operations.dashboard_view',
            'analytics.view',
            'analytics.security',
        ]);

        $this->grantIfMissing('support', [
            'users.view_activity',
            'store.purchases.view',
            'store.purchases.fulfill',
            'store.inventory.view',
        ]);

        $this->grantIfMissing('content-manager', [
            'analytics.view',
            'analytics.puzzles',
            'analytics.campaigns',
            'economy.currencies.view',
            'store.items.view',
            'store.items.create',
            'store.items.update',
            'store.prices.manage',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        \Illuminate\Support\Facades\Artisan::call('rbac:migrate-legacy-roles');
    }

    protected function allPermissionNames(): array
    {
        return Permission::query()->where('guard_name', 'web')->pluck('name')->all();
    }

    protected function role(string $name, string $labelAr, string $description, bool $isSystem, string $color, int $sortOrder, array $permissionNames): void
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['label_ar' => $labelAr, 'description' => $description, 'is_system' => $isSystem, 'color' => $color, 'sort_order' => $sortOrder],
        );

        if ($role->wasRecentlyCreated && !empty($permissionNames)) {
            $models = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($models);
        }
    }

    protected function grantIfMissing(string $roleName, array $permissionNames): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (!$role) {
            return;
        }

        $models = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissionNames)->get();
        $role->givePermissionTo($models);
    }
}
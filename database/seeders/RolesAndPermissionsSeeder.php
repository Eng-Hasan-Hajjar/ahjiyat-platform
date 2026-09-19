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
        collect(config('permissions', []))
            ->flatMap(fn ($module) => array_keys($module['permissions'] ?? []))
            ->unique()
            ->each(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        $this->role('super-admin', 'المدير الأعلى', 'وصول كامل غير مقيَّد لكل أجزاء المنصة - محمي، لا يمكن حذفه.', true, '#f59e0b', 0, []);

        $all = Permission::pluck('name')->all();
        $this->role('administrator', 'مدير عام', 'وصول كامل لكل الوحدات الوظيفية.', true, '#8b5cf6', 1, $all);

        $this->role('content-manager', 'مدير محتوى', 'إدارة المواسم والحملات والأحجيات فقط.', true, '#22d3ee', 2, [
            'admin.access',
            'seasons.view', 'seasons.create', 'seasons.update', 'seasons.delete', 'seasons.publish', 'seasons.preview',
            'campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.delete',
            'puzzles.view', 'puzzles.create', 'puzzles.update', 'puzzles.delete', 'puzzles.publish', 'puzzles.manage_categories',
        ]);

        $this->role('moderator', 'مشرف', 'مراقبة المستخدمين ومكافحة الاحتيال والتحديات.', true, '#fb7185', 3, [
            'admin.access', 'users.view', 'fraud.view', 'fraud.resolve', 'challenges.view', 'challenges.update',
        ]);

        $this->role('support', 'دعم فني', 'عرض المستخدمين وطلبات الاستبدال فقط.', true, '#34d399', 4, [
            'admin.access', 'users.view', 'redemptions.view',
        ]);

        $this->role('player', 'لاعب', 'الدور الافتراضي لأي مستخدم جديد - بلا وصول للوحة الإدارة.', true, '#64748b', 99, []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // تثبيت أولي (Fresh installs فقط): يستدعي نفس أمر الترحيل الآمن
        // (rbac:migrate-legacy-roles) بدل تكرار منطقه - يضمن وجود Super
        // Admin واحد على الأقل بعد db:seed مباشرة، بلا خطوة يدوية إضافية.
        \Illuminate\Support\Facades\Artisan::call('rbac:migrate-legacy-roles');
    }

    protected function role(string $name, string $labelAr, string $description, bool $isSystem, string $color, int $sortOrder, array $permissions): void
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['label_ar' => $labelAr, 'description' => $description, 'is_system' => $isSystem, 'color' => $color, 'sort_order' => $sortOrder],
        );

        if ($role->wasRecentlyCreated && ! empty($permissions)) {
            $role->syncPermissions($permissions);
        }
    }
}
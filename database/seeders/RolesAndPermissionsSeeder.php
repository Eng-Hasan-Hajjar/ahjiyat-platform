<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent بالكامل: تُنشئ الأدوار الافتراضية وصلاحياتها فقط إن كانت
 * ناقصة. درس Aseel Seeder (E3) مُطبَّق هنا حرفياً: firstOrCreate() للدور
 * نفسه، وsyncPermissions() تُستدعى فقط عند إنشاء الدور لأول مرة.
 *
 * إصلاح حرج (جولة ثانية): مسح الـCache وحده لم يكفِ - syncPermissions()
 * بالأسماء النصية تعتمد داخلياً على Permission::findByName() التي تقرأ من
 * نفس تلك الـCache الداخلية، وقد تبقى عرضة لحالة سباق ضمن بيئة الاختبارات
 * (Transaction Rollback بين الاختبارات لا يُفرِّغ Cache الحزمة الداخلية
 * بالضرورة بنفس لحظة استئناف الاختبار التالي). الحل القاطع: تجاوز البحث
 * بالاسم كلياً - جلب نماذج Permission الفعلية بـQuery مباشرة طازجة، ثم
 * تمريرها كـCollection من الكائنات لـsyncPermissions() بدل الأسماء
 * النصية - هذا المسار لا يمر بآلية الـCache الداخلية إطلاقاً.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        collect(config('permissions', []))
            ->flatMap(fn ($module) => array_keys($module['permissions'] ?? []))
            ->unique()
            ->each(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->role('super-admin', 'المدير الأعلى', 'وصول كامل غير مقيَّد لكل أجزاء المنصة - محمي، لا يمكن حذفه.', true, '#f59e0b', 0, []);
        $this->role('administrator', 'مدير عام', 'وصول كامل لكل الوحدات الوظيفية.', true, '#8b5cf6', 1, $this->allPermissionNames());

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

        \Illuminate\Support\Facades\Artisan::call('rbac:migrate-legacy-roles');
    }

    /** Query مباشرة طازجة - لا اعتماد على أي Cache حزمة داخلية. */
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

        if ($role->wasRecentlyCreated && ! empty($permissionNames)) {
            // نماذج فعلية بـQuery طازجة مباشرة - يتجاوز Permission::findByName()
            // الداخلية بالحزمة كلياً، فلا يتأثر بأي حالة سباق بالـCache إطلاقاً.
            $models = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($models);
        }
    }
}
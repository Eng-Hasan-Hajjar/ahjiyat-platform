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

        $this->grantIfMissing('administrator', ['creators.view', 'creators.review', 'creators.manage']);

        // E40: مسار الأحجيات للمدير العام فقط افتراضيًا؛ الأدوار المخصصة (محتوى/مالية) تُفوَّض من «إدارة الوصول».
        $this->grantIfMissing('administrator', [
            'passes.view', 'passes.manage', 'passes.manage-rewards', 'passes.manage-missions', 'passes.publish',
            'passes.view-purchases', 'passes.verify-purchases', 'passes.refund-purchases',
        ]);

        $this->grantIfMissing('moderator', ['creators.view', 'creators.review']);

        // E30: فصل مالي. المدير العام يملك كل شيء؛ مدير المحتوى يعرض ويدير الملفات والبرامج فقط (لا تأكيد/استرداد ولا خطة مكافآت)؛ الدعم والمشرف بلا صلاحيات رعاية.
        $this->grantIfMissing('administrator', ['sponsorships.view', 'sponsorships.manage', 'sponsorships.confirm', 'sponsorships.reward_plan']);
        $this->grantIfMissing('content-manager', ['sponsorships.view', 'sponsorships.manage']);

        // E33: المدير العام يملك الكل؛ مدير المحتوى يعرض ويدير الحملات؛ المشرف يعرض ويراجع الإحالات المشبوهة (لا يدير الحملات).
        $this->grantIfMissing('administrator', ['referrals.view', 'referrals.manage', 'referrals.review']);
        $this->grantIfMissing('content-manager', ['referrals.view', 'referrals.manage']);
        $this->grantIfMissing('moderator', ['referrals.view', 'referrals.review']);

        // E34: المدير العام يملك الكل؛ مدير المحتوى يعرض ويدير المجموعات؛ المشرف يعرض التحليلات فقط.
        $this->grantIfMissing('administrator', ['liveops.view', 'liveops.manage']);
        $this->grantIfMissing('content-manager', ['liveops.view', 'liveops.manage']);
        $this->grantIfMissing('moderator', ['liveops.view']);

        // E35: المدير العام يملك الكل؛ المشرف يعرض ويحسم بلاغات المجموعات ويخفيها/يستعيدها (لا يدير الأرفف)؛ مدير المحتوى يدير الأرفف التحريرية ويعرض المجموعات (لا يحسم بلاغاتها).
        $this->grantIfMissing('administrator', ['collections.view', 'collections.moderate', 'editorial.view', 'editorial.manage']);
        $this->grantIfMissing('moderator', ['collections.view', 'collections.moderate']);
        $this->grantIfMissing('content-manager', ['collections.view', 'editorial.view', 'editorial.manage']);

        // E36: المدير العام والمشرف فقط يعرضان النقاشات ويشرفان عليها (إخفاء/استعادة/قفل/بلاغات). لا مدير محتوى ولا دعم ولا مالك محتوى.
        $this->grantIfMissing('administrator', ['discussions.view', 'discussions.moderate']);
        $this->grantIfMissing('moderator', ['discussions.view', 'discussions.moderate']);

        // E37: المدير العام يملك الكل؛ مدير المحتوى يعرض مركز السمعة ويختار «مبدعًا مختارًا» (تحريري)؛ المشرف يعرض فقط (لا يغيّر الاعتراف ولا الإبراز). لا دعم ولا مبدع.
        $this->grantIfMissing('administrator', ['reputation.view', 'reputation.manage', 'reputation.feature']);
        $this->grantIfMissing('content-manager', ['reputation.view', 'reputation.feature']);
        $this->grantIfMissing('moderator', ['reputation.view']);

        // E38: المدير العام يملك كل صلاحيات الثقة والسلامة؛ المشرف يعاين ويدير الحالات ويشرف على المحتوى ويراجع الاعتراضات، **دون** تجميد حسابات أو تعليق مبدعين أو إشراف أعلى
        // (الإجراءات الشديدة تبقى للمدير العام). لا مدير محتوى ولا دعم: لا تصل إليهم هوية المبلِّغين ولا ملفات السلامة.
        $this->grantIfMissing('administrator', [
            'trust-safety.view', 'trust-safety.manage-cases', 'trust-safety.moderate-content',
            'trust-safety.enforce-account', 'trust-safety.review-appeals', 'trust-safety.supervise',
        ]);
        $this->grantIfMissing('moderator', [
            'trust-safety.view', 'trust-safety.manage-cases', 'trust-safety.moderate-content', 'trust-safety.review-appeals',
        ]);

        // E39: مالية المبدعين للمدير العام فقط افتراضيًا (لا مشرف ولا مدير محتوى ولا دعم). تفويض أدوار مالية مخصصة يتم من «إدارة الوصول».
        $this->grantIfMissing('administrator', [
            'creator-finance.view', 'creator-finance.manage-periods', 'creator-finance.finalize', 'creator-finance.manage-monetization',
            'creator-finance.review-payouts', 'creator-finance.settle-payouts', 'creator-finance.adjust', 'creator-finance.manage-holds',
        ]);

        // E43: سوق المبدعين المميز للمدير العام فقط افتراضيًا (تفويض أدوار مخصصة من «إدارة الوصول»). المشرف يعاين بصلاحية creators.review ولا يملك إجراءات مالية.
        $this->grantIfMissing('administrator', ['creator-premium.view', 'creator-premium.manage', 'creator-premium.refund']);

        // E44: المدير العام يملك الصلاحيات الست؛ المشرف يعاين اللوحة ويصوّت كطاقم فقط (لا يدير القائمة ولا يدقق هويات الناخبين ولا ينهي ولا يقرّر جوائز). تفويض أدوار أخرى من «إدارة الوصول».
        $this->grantIfMissing('administrator', [
            'seasonal-recognition.view', 'seasonal-recognition.manage', 'seasonal-recognition.vote',
            'seasonal-recognition.audit', 'seasonal-recognition.finalize', 'seasonal-recognition.award',
        ]);
        $this->grantIfMissing('moderator', ['seasonal-recognition.view', 'seasonal-recognition.vote']);

        // E45: تطوّر AI للمدير العام فقط افتراضيًا (لوحة الاستخدام ومقالات المعرفة ونشرها). لا مشرف ولا دعم: لا يصل أحد منهما لهذه اللوحة إلا بتفويض صريح من «إدارة الوصول». لا صلاحية تقرأ محادثات المستخدمين.
        $this->grantIfMissing('administrator', ['ai-assistant.view', 'ai-assistant.knowledge.manage', 'ai-assistant.knowledge.publish']);

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
            'progression.users.view',
            'engagement.users.view',
            'ads.campaigns.view',
            'ads.analytics.view',
        ]);

        /**
         * E14 (بند 598 - قرار موثَّق): content-manager يملك create/update/pause
         * لكن عمدًا بلا ads.campaigns.review - هذا يمنع الاعتماد الذاتي
         * (Self-Approval) بشكل طبيعي عبر فصل الأدوار القائم أصلًا (المراجعة
         * تبقى حصرية لـadministrator، صاحب allPermissionNames() الكاملة)،
         * دون كسر أي سير عمل فعلي بنموذج التوظيف الحالي الصغير بالمشروع.
         */
        $this->grantIfMissing('content-manager', [
            'ads.campaigns.view',
            'ads.campaigns.create',
            'ads.campaigns.update',
            'ads.campaigns.pause',
            'ads.analytics.view',
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
            'progression.achievements.view',
            'progression.achievements.create',
            'progression.achievements.update',
            'progression.levels.view',
            'progression.levels.create',
            'progression.levels.update',
            'engagement.quests.view',
            'engagement.quests.create',
            'engagement.quests.update',
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
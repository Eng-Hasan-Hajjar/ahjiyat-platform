<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class MigrateLegacyRoles extends Command
{
    protected $signature = 'rbac:migrate-legacy-roles';

    protected $description = 'ترحيل آمن لمستخدمي users.role القديم (admin/user) إلى أدوار RBAC الجديدة (Spatie)';

    public function handle(): int
    {
        if (Role::count() === 0) {
            $this->error('لا توجد أدوار بعد بالنظام - شغّل أولاً: php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $hasSuperAdmin = User::role('super-admin')->exists();

        $unmigratedAdmins = User::where('role', 'admin')->whereDoesntHave('roles')->orderBy('id')->get();
        $unmigratedOthers = User::where('role', '!=', 'admin')->whereDoesntHave('roles')->get();

        $promoted = 0;
        $migrated = 0;

        if (! $hasSuperAdmin && $unmigratedAdmins->isNotEmpty()) {
            $bootstrap = $unmigratedAdmins->shift();
            $bootstrap->assignRole('super-admin');
            $promoted++;
            $this->warn("تمت ترقية {$bootstrap->email} (ID {$bootstrap->id}) إلى \"المدير الأعلى\" تلقائياً - كان أقدم Admin قديم ولا يوجد مدير أعلى بالنظام بعد.");
        }

        foreach ($unmigratedAdmins as $admin) {
            $admin->assignRole('administrator');
            $migrated++;
            $this->line("  {$admin->email} → مدير عام");
        }

        foreach ($unmigratedOthers as $user) {
            $user->assignRole('player');
            $migrated++;
        }

        $this->info("اكتمل الترحيل - {$migrated} مستخدماً حصلوا على دور مطابق".($promoted ? "، و{$promoted} تمت ترقيته لمدير أعلى (Bootstrap)." : '.'));

        if (! User::role('super-admin')->exists()) {
            $this->error('⚠️  تحذير: لا يوجد أي "مدير أعلى" بالنظام بعد هذا الترحيل! عيّن واحداً يدوياً فوراً من لوحة الإدارة أو Tinker.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
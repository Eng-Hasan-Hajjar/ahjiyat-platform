<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--prune : احذف الصلاحيات الموجودة بقاعدة البيانات وغير المُعرَّفة بالـRegistry - يطلب تأكيداً}';

    protected $description = 'مزامنة صلاحيات النظام من config/permissions.php مع قاعدة البيانات (إنشاء الناقص فقط، آمن لإعادة التشغيل)';

    public function handle(): int
    {
        $registryNames = collect(config('permissions', []))
            ->flatMap(fn ($module) => array_keys($module['permissions'] ?? []))
            ->unique()
            ->values();

        $created = 0;

        foreach ($registryNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($permission->wasRecentlyCreated) {
                $created++;
                $this->line("  + أُنشئت: {$name}");
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("تمت المزامنة - {$created} صلاحية جديدة أُنشئت من أصل {$registryNames->count()} بالسجل.");

        if ($this->option('prune')) {
            $unknown = Permission::whereNotIn('name', $registryNames)->get();

            if ($unknown->isEmpty()) {
                $this->info('لا صلاحيات غير معروفة لحذفها.');

                return self::SUCCESS;
            }

            $this->warn('الصلاحيات التالية غير موجودة بالـRegistry الحالي:');
            $unknown->each(fn ($p) => $this->line("  - {$p->name}"));

            if ($this->confirm('هل تريد حذفها فعلياً؟ (سيُزال ارتباطها بأي دور/مستخدم تلقائياً)', false)) {
                $unknown->each->delete();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $this->info('تم الحذف.');
            } else {
                $this->line('تم الإلغاء - لا تغيير.');
            }
        }

        return self::SUCCESS;
    }
}
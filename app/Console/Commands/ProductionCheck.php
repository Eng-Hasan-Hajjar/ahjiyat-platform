<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductionCheck extends Command
{
    protected $signature = 'production:check';

    protected $description = 'فحوصات جاهزية الإنتاج (Read-Only بالكامل) - Exit code صفر إذا لا مشاكل حرجة، غير صفر خلاف ذلك';

    protected int $criticalFailures = 0;

    protected int $warnings = 0;

    public function handle(): int
    {
        $this->info('== فحص جاهزية الإنتاج (Read-Only) ==');
        $this->newLine();

        $this->checkEnvironment();
        $this->checkAppKey();
        $this->checkDatabase();
        $this->checkStorage();
        $this->checkCache();
        $this->checkQueue();
        $this->checkMail();
        $this->checkPermissionRegistry();
        $this->checkBuildManifest();
        $this->checkMigrationStatus();

        $this->newLine();
        $this->info("النتيجة: {$this->criticalFailures} فشل حرج، {$this->warnings} تحذير.");

        return $this->criticalFailures > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function pass(string $label): void
    {
        $this->line("  <fg=green>PASS</> {$label}");
    }

    protected function warn2(string $label): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>WARN</> {$label}");
    }

    protected function failCheck(string $label): void
    {
        $this->criticalFailures++;
        $this->line("  <fg=red>FAIL</> {$label}");
    }

    protected function checkEnvironment(): void
    {
        $this->comment('البيئة');

        if (app()->environment('production')) {
            $this->pass('APP_ENV = production');
        } else {
            $this->warn2('APP_ENV ليست production حالياً (بيئة: '.app()->environment().')');
        }

        if (config('app.debug')) {
            $this->failCheck('APP_DEBUG مفعَّلة - يجب أن تكون false بالإنتاج (تُظهر Stack Traces للعامة)');
        } else {
            $this->pass('APP_DEBUG معطَّلة');
        }

        $url = (string) config('app.url');
        if (str_starts_with($url, 'https://')) {
            $this->pass('APP_URL يستخدم HTTPS');
        } else {
            $this->warn2('APP_URL لا يبدأ بـhttps:// - تأكد من ضبطه بالإنتاج الفعلي');
        }
    }

    protected function checkAppKey(): void
    {
        $this->comment('مفتاح التطبيق');

        if (filled(config('app.key'))) {
            $this->pass('APP_KEY موجود');
        } else {
            $this->failCheck('APP_KEY غير مضبوط - التطبيق لن يعمل بأمان (Session/Cookies/Encryption)');
        }
    }

    protected function checkDatabase(): void
    {
        $this->comment('قاعدة البيانات');

        try {
            DB::connection()->getPdo();
            $this->pass('الاتصال بقاعدة البيانات ناجح ('.config('database.default').')');
        } catch (Throwable $e) {
            $this->failCheck('تعذَّر الاتصال بقاعدة البيانات - راجع سجلات الخادم للتفاصيل (لن تُعرَض هنا)');
        }
    }

    protected function checkStorage(): void
    {
        $this->comment('التخزين');

        if (is_writable(storage_path())) {
            $this->pass('مجلد storage قابل للكتابة');
        } else {
            $this->failCheck('مجلد storage غير قابل للكتابة - راجع صلاحيات الملفات');
        }

        if (is_writable(base_path('bootstrap/cache'))) {
            $this->pass('مجلد bootstrap/cache قابل للكتابة');
        } else {
            $this->failCheck('مجلد bootstrap/cache غير قابل للكتابة - راجع صلاحيات الملفات');
        }

        if (File::exists(public_path('storage')) || is_link(public_path('storage'))) {
            $this->pass('رابط public/storage موجود');
        } else {
            $this->warn2('رابط public/storage غير موجود - شغّل php artisan storage:link (لا تعتمد Windows Junction بالإنتاج)');
        }

        try {
            Storage::disk('public')->put('__production_check_tmp.txt', 'ok');
            Storage::disk('public')->delete('__production_check_tmp.txt');
            $this->pass('قرص public قابل للكتابة فعلياً');
        } catch (Throwable $e) {
            $this->failCheck('قرص public غير قابل للكتابة فعلياً - سيفشل رفع أي ملف');
        }
    }

    protected function checkCache(): void
    {
        $this->comment('الكاش');

        try {
            Cache::put('__production_check_tmp', true, 5);
            Cache::forget('__production_check_tmp');
            $this->pass('Cache Store ('.config('cache.default').') يعمل فعلياً');
        } catch (Throwable $e) {
            $this->failCheck('Cache Store لا يعمل - سيؤثر على التحليلات والإعدادات والصلاحيات');
        }
    }

    protected function checkQueue(): void
    {
        $this->comment('الطوابير');

        $connection = config('queue.default');
        $this->line("  <fg=cyan>INFO</> QUEUE_CONNECTION = {$connection}");

        if ($connection === 'database') {
            if (DB::getSchemaBuilder()->hasTable('jobs') && DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->pass('جدولا jobs/failed_jobs موجودان');
            } else {
                $this->failCheck('QUEUE_CONNECTION=database لكن جدولي jobs/failed_jobs غير موجودين - شغّل migrate');
            }
        }

        if ($connection === 'sync') {
            $this->warn2('QUEUE_CONNECTION=sync - كل عملية تُنفَّذ فوراً بلا طابور حقيقي (مقبول حالياً - لا Jobs مُوَحَّدة (ShouldQueue) بالمشروع بعد)');
        }
    }

    protected function checkMail(): void
    {
        $this->comment('البريد الإلكتروني');

        $mailer = config('mail.default');
        $this->line("  <fg=cyan>INFO</> MAIL_MAILER = {$mailer}");

        if ($mailer === 'log') {
            $this->warn2('MAIL_MAILER=log - لن يصل أي بريد فعلي (توثيق بريد/استرجاع كلمة سر) - اضبط SMTP حقيقي بالإنتاج');
        } else {
            if (filled(config('mail.from.address'))) {
                $this->pass('MAIL_FROM_ADDRESS مضبوط');
            } else {
                $this->warn2('MAIL_FROM_ADDRESS غير مضبوط');
            }
        }
    }

    protected function checkPermissionRegistry(): void
    {
        $this->comment('سجل الصلاحيات');

        if (File::exists(config_path('permission.php'))) {
            $this->pass('config/permission.php (حزمة Spatie) موجود');
        } else {
            $this->failCheck('config/permission.php غير موجود - إعدادات Spatie الأساسية مفقودة');
        }

        if (File::exists(config_path('permissions.php'))) {
            $this->pass('config/permissions.php (سجلّنا الخاص) موجود');
        } else {
            $this->failCheck('config/permissions.php غير موجود - permissions:sync لن يعمل');
        }
    }

    protected function checkBuildManifest(): void
    {
        $this->comment('أصول الواجهة (Vite)');

        if (File::exists(public_path('build/manifest.json'))) {
            $this->pass('public/build/manifest.json موجود - الأصول مبنية');
        } else {
            $this->failCheck('public/build/manifest.json غير موجود - شغّل npm ci && npm run build قبل النشر');
        }
    }

    protected function checkMigrationStatus(): void
    {
        $this->comment('حالة الترحيلات (فحص فقط - بلا تنفيذ)');

        try {
            $ran = DB::table('migrations')->count();
            $files = collect(File::files(database_path('migrations')))->count();

            if ($ran >= $files) {
                $this->pass("كل الترحيلات مُنفَّذة ({$ran}/{$files})");
            } else {
                $this->warn2("ترحيلات غير مُنفَّذة بعد ({$ran}/{$files}) - شغّل php artisan migrate --force بعد نسخ احتياطي");
            }
        } catch (Throwable $e) {
            $this->failCheck('تعذَّر قراءة جدول migrations');
        }
    }
}
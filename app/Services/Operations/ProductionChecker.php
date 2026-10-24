<?php

namespace App\Services\Operations;

use App\Services\Database\DatabaseFactsEvaluator;
use App\Services\Database\DatabaseRuntimeInspector;
use App\Support\ReleaseInfo;
use App\Support\SchedulerHeartbeat;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * E25 - عقد بيئة الإنتاج. فحوصات قراءة فقط (لا كتابة قاعدة/كاش/تخزين، لا تنفيذ ترحيل، لا إعادة تشغيل) وسريعة.
 *
 * مبدأ الشدّة:
 *  - FAIL «حاجب» دائمًا (مهما كانت البيئة): APP_DEBUG مفعّل، APP_KEY مفقود/غير صالح، القاعدة لا تتصل، مجلدات الكتابة/الكاش/القرص غير قابلة للكتابة، أصول Vite غير مبنية، ملفات سجل الصلاحيات ناقصة، Redis مُعدّ ولا يتصل.
 *  - ما عدا ذلك يصير FAIL بالإنتاج فقط (APP_ENV=production) ويبقى WARN خارجه، كي لا تُكسَر بيئات التطوير والاختبار.
 *  - لا قيمة سرية تُطبع أبدًا: الرسائل تحمل أسماء الإعدادات فقط، ولا يدخل نص استثناء في أي رسالة. (والأوامر تمرّر الناتج على SecretRedactor كطبقة أمان ثانية.)
 */
final class ProductionChecker
{
    private CheckReport $report;

    private bool $production = false;

    /** يُحدَّد بخطوة القاعدة؛ تتجنّب الخطوات التالية تكرار FAIL حين تكون القاعدة نفسها معطّلة. */
    private bool $databaseReachable = true;

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly MigrationState $migrations,
        private readonly QueueSignals $queue,
    ) {}

    public function run(?bool $production = null): CheckReport
    {
        $this->production = $production ?? (app()->environment('production') || config('app.env') === 'production');
        $this->report = new CheckReport($this->production);
        $this->databaseReachable = true;

        $steps = [
            'environment', 'appKey', 'release', 'database', 'migrations', 'cache', 'session', 'redis', 'queue', 'scheduler',
            'mail', 'broadcasting', 'security', 'logging', 'storage', 'build', 'optimization', 'permissions', 'accounts', 'commerce',
        ];

        foreach ($steps as $step) {
            try {
                $this->{$step}();
            } catch (Throwable) {
                // لا نكشف نص الاستثناء؛ فشل الفحص نفسه يعني أننا لا نستطيع الجزم بالسلامة.
                $this->fail('النظام', 'check:'.$step, 'تعذّر إكمال فحص ('.$step.') - راجع سجلات التطبيق');
            }
        }

        return $this->report;
    }

    // ───────────────────────────── البيئة ─────────────────────────────

    private function environment(): void
    {
        $g = 'البيئة';

        $this->production
            ? $this->pass($g, 'APP_ENV', 'APP_ENV = production')
            : $this->warn($g, 'APP_ENV', 'APP_ENV ليست production حالياً (بيئة: '.config('app.env').')');

        config('app.debug')
            ? $this->fail($g, 'APP_DEBUG', 'APP_DEBUG مفعَّلة - يجب أن تكون false بالإنتاج (تُظهر Stack Traces والإعدادات للعامة)')
            : $this->pass($g, 'APP_DEBUG', 'APP_DEBUG معطَّلة');

        $url = (string) config('app.url');
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            $this->prodFail($g, 'APP_URL', 'APP_URL غير صالح أو فارغ');
        } elseif ($scheme !== 'https') {
            $this->prodFail($g, 'APP_URL', 'APP_URL لا يبدأ بـhttps:// - بالإنتاج HTTPS فقط (روابط البريد والكوكيز تعتمد عليه)');
        } else {
            $this->pass($g, 'APP_URL', 'APP_URL يستخدم HTTPS');
        }

        if ($host !== '' && (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local'))) {
            $this->prodFail($g, 'APP_URL', 'APP_URL يشير إلى مضيف محلي - اضبطه على نطاق الإنتاج الفعلي');
        }
    }

    private function appKey(): void
    {
        $g = 'مفتاح التطبيق';
        $key = (string) config('app.key');

        if ($key === '') {
            $this->fail($g, 'APP_KEY', 'APP_KEY غير مضبوط - التطبيق لن يعمل بأمان (Session/Cookies/Encryption)');
        } elseif (! $this->keyIsValid($key, (string) config('app.cipher', 'AES-256-CBC'))) {
            $this->fail($g, 'APP_KEY', 'APP_KEY موجود لكن صيغته/طوله لا يطابق خوارزمية التشفير المضبوطة');
        } else {
            $this->pass($g, 'APP_KEY', 'APP_KEY موجود وصالح');
        }

        $previous = array_filter((array) config('app.previous_keys', []));
        if ($previous !== []) {
            $this->info($g, 'APP_PREVIOUS_KEYS', 'APP_PREVIOUS_KEYS مضبوط ('.count($previous).' مفتاح) - فترة تدوير المفتاح؛ أزله بعد انتهاء صلاحية الجلسات/البيانات المشفرة القديمة');
        }
    }

    private function keyIsValid(string $key, string $cipher): bool
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($raw) && Encrypter::supported($raw, $cipher);
    }

    private function release(): void
    {
        $g = 'الإصدار';
        $configured = config('operations.release.id');

        if (ReleaseInfo::isConfigured()) {
            $this->pass($g, 'APP_RELEASE', 'معرّف الإصدار مضبوط ('.ReleaseInfo::short().')');
        } elseif (is_string($configured) && trim($configured) !== '') {
            $this->warn($g, 'APP_RELEASE', 'قيمة APP_RELEASE/RELEASE_SHA غير آمنة (المسموح [A-Za-z0-9._-] حتى 64 خانة) فتُتجاهل');
        } elseif ($this->production) {
            $this->warn($g, 'APP_RELEASE', 'APP_RELEASE غير مضبوط - اضبطه عند كل نشر (SHA أو وسم) كي يظهر بـ/ready والسجلات');
        } else {
            $this->info($g, 'APP_RELEASE', 'APP_RELEASE غير مضبوط (اختياري محليًا)');
        }
    }

    // ───────────────────────────── قاعدة البيانات والترحيلات ─────────────────────────────

    private function database(): void
    {
        $g = 'قاعدة البيانات';
        $name = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            $this->pass($g, 'DB_CONNECTION', 'الاتصال بقاعدة البيانات ناجح ('.$name.')');
        } catch (Throwable) {
            $this->databaseReachable = false;
            $this->fail($g, 'DB_CONNECTION', 'تعذَّر الاتصال بقاعدة البيانات - راجع سجلات الخادم للتفاصيل (لن تُعرَض هنا)');

            return;
        }

        $driver = (string) config("database.connections.{$name}.driver");

        if ($driver === 'sqlite') {
            // E31: SQLite غير معتمدة للإنتاج في هذا الملف التشغيلي. التجاوز الواعي الوحيد: OPS_ALLOW_SQLITE_PRODUCTION=true (يبقى تحذيرًا ظاهرًا).
            if ((bool) config('operations.allow_sqlite_production', false)) {
                $this->warn($g, 'DB_CONNECTION', 'SQLite مسموحة صراحةً عبر OPS_ALLOW_SQLITE_PRODUCTION (تجاوز واعٍ) - غير موصى بها للإنتاج (كتابة متزامنة محدودة، لا نسخ نقطي)');
            } else {
                $this->prodFail($g, 'DB_CONNECTION', 'SQLite is not an approved production database for this deployment profile - SQLite غير معتمدة للإنتاج: استعمل MySQL 8.0.16+ (docs/mysql-production-e31.md)');
            }

            return;
        }

        // E31: حقائق الخادم الفعلية (نسخة/وضع صارم/ترميز/محرك) بلا كشف مضيف ولا بيانات اعتماد.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (app(DatabaseFactsEvaluator::class)->evaluate(app(DatabaseRuntimeInspector::class)->inspect($name)) as $result) {
                match ($result['level']) {
                    'pass' => $this->pass($g, $result['key'], $result['message']),
                    'prodfail' => $this->prodFail($g, $result['key'], $result['message']),
                    'fail' => $this->fail($g, $result['key'], $result['message']),
                    default => $this->warn($g, $result['key'], $result['message']),
                };
            }
        }

        if ((string) config("database.connections.{$name}.password") === '') {
            $this->prodFail($g, 'DB_PASSWORD', 'DB_PASSWORD فارغة - لا تستعمل قاعدة بلا كلمة مرور بالإنتاج');
        }

        if (strtolower((string) config("database.connections.{$name}.username")) === 'root') {
            $this->warn($g, 'DB_USERNAME', 'DB_USERNAME=root - أنشئ مستخدمًا بصلاحيات التطبيق فقط (بلا DROP DATABASE/GRANT)');
        }
    }

    private function migrations(): void
    {
        $g = 'الترحيلات (فحص فقط - بلا تنفيذ)';

        if (! $this->databaseReachable) {
            $this->warn($g, 'migrations', 'تعذّر فحص الترحيلات لأن القاعدة غير متاحة (عطل القاعدة مُبلَّغ عنه أعلاه)');

            return;
        }

        $state = $this->migrations->inspect();

        if (! $state['repository']) {
            $this->fail($g, 'migrations', 'تعذَّر قراءة جدول migrations - لم تُهيَّأ القاعدة بعد؟ (php artisan migrate --force بعد نسخ احتياطي)');

            return;
        }

        $pending = count($state['pending']);

        if ($pending === 0) {
            $this->pass($g, 'migrations', "كل الترحيلات مُنفَّذة ({$state['ran']}/{$state['files']})");
        } else {
            $this->warn($g, 'migrations', "ترحيلات غير مُنفَّذة بعد ({$state['ran']}/{$state['files']}، المعلّق {$pending}) - شغّل php artisan migrate --force بعد نسخ احتياطي (انظر release:preflight)");
        }

        if ($state['orphans'] !== []) {
            $this->warn($g, 'migrations', 'القاعدة أحدث من الكود: '.count($state['orphans']).' ترحيل منفَّذ بلا ملف بالإصدار الحالي (كود أقدم فوق قاعدة أحدث) - لا تُجرِ rollback؛ اتبع «التراجع» في docs/release-engineering.md');
        }
    }

    // ───────────────────────────── الكاش والجلسات والـRedis ─────────────────────────────

    private function cache(): void
    {
        $g = 'الكاش';
        $driver = (string) config('cache.default');

        try {
            Cache::store()->get('ops:check:probe'); // قراءة فقط: يكشف عطل الاتصال دون أي كتابة
            $this->pass($g, 'CACHE_STORE', 'Cache Store ('.$driver.') يعمل فعلياً (قراءة)');
        } catch (Throwable) {
            $this->fail($g, 'CACHE_STORE', 'Cache Store لا يعمل - سيؤثر على التحليلات والإعدادات والصلاحيات والمجدول');

            return;
        }

        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            $this->prodFail($g, 'CACHE_STORE', 'مخزن الكاش لا يدعم الأقفال - المجدول (withoutOverlapping/onOneServer) يحتاجها');
        } else {
            $this->pass($g, 'CACHE_STORE', 'مخزن الكاش يدعم الأقفال (يحتاجها المجدول)');
        }

        if ($driver === 'array' || $store instanceof NullStore) {
            $this->prodFail($g, 'CACHE_STORE', 'CACHE_STORE='.$driver.' لا يدوم بين الطلبات - استعمل redis أو database');
        } elseif ($driver === 'file' && $this->production) {
            $this->warn($g, 'CACHE_STORE', 'CACHE_STORE=file مقبول لخادم واحد فقط؛ تعدد الخوادم (onOneServer/الأقفال) يتطلب كاشًا مشتركًا: redis أو database');
        }
    }

    private function session(): void
    {
        $g = 'الجلسات والكوكيز';
        $driver = (string) config('session.driver');

        if (in_array($driver, ['array', 'null'], true)) {
            $this->prodFail($g, 'SESSION_DRIVER', 'SESSION_DRIVER='.$driver.' لا يحتفظ بالجلسات - استعمل database أو redis');
        } elseif ($driver === 'file' && $this->production) {
            $this->info($g, 'SESSION_DRIVER', 'SESSION_DRIVER=file مناسب لخادم واحد؛ تعدد الخوادم يتطلب database أو redis');
        } elseif ($driver === 'database') {
            match ($this->hasTable(config('session.connection'), (string) config('session.table', 'sessions'))) {
                true => $this->pass($g, 'SESSION_DRIVER', 'SESSION_DRIVER=database وجدول الجلسات موجود'),
                false => $this->fail($g, 'SESSION_DRIVER', 'SESSION_DRIVER=database لكن جدول الجلسات غير موجود - شغّل migrate'),
                null => $this->warn($g, 'SESSION_DRIVER', 'تعذّر التحقق من جدول الجلسات (القاعدة غير متاحة)'),
            };
        } else {
            $this->pass($g, 'SESSION_DRIVER', 'SESSION_DRIVER='.$driver);
        }

        if (config('session.secure') === true) {
            $this->pass($g, 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE=true');
        } elseif ($this->production) {
            $this->fail($g, 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE ليست true - كوكي الجلسة قد تُرسَل عبر HTTP بالإنتاج (اضبطها true)');
        } else {
            $this->info($g, 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE غير مفعّلة (مقبول محليًا؛ إلزامية بالإنتاج)');
        }

        config('session.http_only') === true
            ? $this->pass($g, 'SESSION_HTTP_ONLY', 'SESSION_HTTP_ONLY=true')
            : $this->prodFail($g, 'SESSION_HTTP_ONLY', 'SESSION_HTTP_ONLY ليست true - الكوكي مكشوفة لجافاسكربت (XSS)');

        $sameSite = strtolower((string) config('session.same_site'));
        in_array($sameSite, ['lax', 'strict'], true)
            ? $this->pass($g, 'SESSION_SAME_SITE', 'SESSION_SAME_SITE='.$sameSite)
            : $this->warn($g, 'SESSION_SAME_SITE', 'SESSION_SAME_SITE ليست lax/strict - تضعف الحماية من CSRF (اتركها lax ما لم يلزم غير ذلك)');
    }

    private function redis(): void
    {
        $g = 'Redis';
        $connections = $this->redisConnections();

        if ($connections === []) {
            $this->info($g, 'REDIS_HOST', 'Redis غير مستخدم حاليًا (الكاش/الجلسات/الطابور لا تعتمد عليه)');

            return;
        }

        foreach ($connections as $name) {
            try {
                Redis::connection($name)->ping();
                $this->pass($g, 'REDIS_HOST', "اتصال Redis ({$name}) ناجح");
            } catch (Throwable) {
                $this->fail($g, 'REDIS_HOST', "تعذَّر الاتصال بـRedis ({$name}) رغم أنه مُعدّ للاستعمال - راجع REDIS_HOST/REDIS_PORT/REDIS_PASSWORD (لن تُعرَض هنا)");
            }
        }

        $host = (string) config('database.redis.default.host');
        $local = in_array($host, ['127.0.0.1', 'localhost', '::1', ''], true);
        $password = config('database.redis.default.password');

        if (! $local && ($password === null || $password === '' || $password === 'null')) {
            $this->prodFail($g, 'REDIS_PASSWORD', 'Redis غير محلي ولا كلمة مرور له - اضبط REDIS_PASSWORD وقيّد الشبكة');
        }
    }

    /** @return list<string> */
    private function redisConnections(): array
    {
        $names = [];

        $store = (string) config('cache.default');
        if (config("cache.stores.{$store}.driver") === 'redis') {
            $names[] = (string) config("cache.stores.{$store}.connection", 'default');
        }

        if (config('session.driver') === 'redis') {
            $names[] = (string) (config('session.connection') ?: 'default');
        }

        $queue = (string) config('queue.default');
        if (config("queue.connections.{$queue}.driver") === 'redis') {
            $names[] = (string) config("queue.connections.{$queue}.connection", 'default');
        }

        if (config('reverb.servers.reverb.scaling.enabled')) {
            $names[] = 'default';
        }

        return array_values(array_unique($names));
    }

    // ───────────────────────────── الطوابير والمجدول ─────────────────────────────

    private function queue(): void
    {
        $g = 'الطوابير';
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        $this->info($g, 'QUEUE_CONNECTION', "QUEUE_CONNECTION = {$connection}");

        if ($connection === 'sync') {
            $this->warn($g, 'QUEUE_CONNECTION', 'QUEUE_CONNECTION=sync - كل مهمة (بث/إشعارات) تُنفَّذ داخل الطلب نفسه بلا إعادة محاولة ولا حدود BoundedRetries؛ بالإنتاج استعمل database أو redis مع عامل queue:work');
        } elseif ($connection === 'null' || $driver === 'null') {
            $this->prodFail($g, 'QUEUE_CONNECTION', 'QUEUE_CONNECTION=null يُسقط المهام بصمت');
        } else {
            $this->pass($g, 'QUEUE_CONNECTION', 'مشغّل الطابور '.$driver.' مضبوط');
        }

        $failedDriver = (string) config('queue.failed.driver');
        if (in_array($failedDriver, ['database', 'database-uuids'], true)) {
            match ($this->hasTable(config('queue.failed.database'), (string) config('queue.failed.table', 'failed_jobs'))) {
                true => $this->pass($g, 'QUEUE_FAILED_DRIVER', 'جدول المهام الفاشلة موجود'),
                false => $this->fail($g, 'QUEUE_FAILED_DRIVER', 'جدول failed_jobs غير موجود - شغّل migrate'),
                null => $this->warn($g, 'QUEUE_FAILED_DRIVER', 'تعذّر التحقق من جدول المهام الفاشلة (القاعدة غير متاحة)'),
            };
        } else {
            $this->prodFail($g, 'QUEUE_FAILED_DRIVER', 'QUEUE_FAILED_DRIVER='.($failedDriver ?: 'null').' - المهام الفاشلة لن تُسجَّل ولن تُسترجَع');
        }

        if ($driver === 'database' || $driver === 'redis') {
            if ($driver === 'database') {
                match ($this->hasTable(config("queue.connections.{$connection}.connection"), (string) config("queue.connections.{$connection}.table", 'jobs'))) {
                    true => $this->pass($g, 'QUEUE_CONNECTION', 'جدول jobs موجود'),
                    false => $this->fail($g, 'QUEUE_CONNECTION', 'QUEUE_CONNECTION=database لكن جدول jobs غير موجود - شغّل migrate'),
                    null => $this->warn($g, 'QUEUE_CONNECTION', 'تعذّر التحقق من جدول jobs (القاعدة غير متاحة)'),
                };
            }

            $setting = $driver === 'database' ? 'DB_QUEUE_RETRY_AFTER' : 'REDIS_QUEUE_RETRY_AFTER';
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 90);
            $timeout = (int) config('operations.queue.job_timeout_seconds', 80);

            $retryAfter > $timeout
                ? $this->pass($g, $setting, "retry_after ({$retryAfter}ث) أكبر من أقصى timeout للمهام ({$timeout}ث)")
                : $this->prodFail($g, $setting, "retry_after ({$retryAfter}ث) ليس أكبر من أقصى timeout للمهام ({$timeout}ث) - قد تُلتقَط مهمة ما زالت تعمل فتُنفَّذ مرتين");
        }

        $snapshot = $this->queue->snapshot();
        foreach ($this->queue->warnings($snapshot) as $warning) {
            $this->warn($g, 'queue', $warning);
        }
    }

    private function scheduler(): void
    {
        $g = 'المجدول';
        $status = $this->heartbeat->status();
        $age = $this->heartbeat->ageSeconds();

        match ($status) {
            SchedulerHeartbeat::OK => $this->pass($g, 'scheduler', "نبض المجدول حديث (منذ {$age}ث)"),
            SchedulerHeartbeat::STALE => $this->warn($g, 'scheduler', 'نبض المجدول متأخر (منذ '.intdiv((int) $age, 60).' د) - تحقق من cron: * * * * * php artisan schedule:run'),
            SchedulerHeartbeat::DOWN => $this->warn($g, 'scheduler', 'المجدول متوقف على الأرجح (آخر نبضة منذ '.intdiv((int) $age, 60).' د) - تحقق من cron/خدمة المجدول'),
            default => $this->production
                ? $this->warn($g, 'scheduler', 'لا نبضة مجدول مسجَّلة بعد - طبيعي مباشرة بعد أول نشر؛ تأكد من cron: * * * * * php artisan schedule:run ثم أعد الفحص بعد دقيقتين')
                : $this->info($g, 'scheduler', 'لا نبضة مجدول (المجدول لا يعمل محليًا عادةً)'),
        };

        if (config('performance.scheduler.one_server', true) && in_array(config('cache.default'), ['file', 'array'], true) && $this->production) {
            $this->warn($g, 'PERF_SCHEDULER_ONE_SERVER', 'onOneServer مفعّل لكن الكاش محلي لكل خادم - لن يمنع تشغيل المهمة على أكثر من خادم؛ استعمل كاشًا مشتركًا أو مجدولًا على خادم واحد');
        }
    }

    // ───────────────────────────── البريد والبث ─────────────────────────────

    private function mail(): void
    {
        $g = 'البريد الإلكتروني';
        $mailer = (string) config('mail.default');

        $this->info($g, 'MAIL_MAILER', "MAIL_MAILER = {$mailer}");

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->prodFail($g, 'MAIL_MAILER', "MAIL_MAILER={$mailer} - لن يصل أي بريد فعلي (توثيق البريد/استرجاع كلمة السر مطلوبان لاستبدال الجواهر) - اضبط SMTP حقيقيًا");

            return;
        }

        $from = strtolower((string) config('mail.from.address'));

        if ($from === '') {
            $this->warn($g, 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS غير مضبوط');
        } elseif (str_ends_with($from, '@example.com')) {
            $this->prodFail($g, 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS ما زال القيمة الافتراضية (example.com) - اضبط عنوان نطاقك (SPF/DKIM)');
        } else {
            $this->pass($g, 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS مضبوط');
        }

        if ($mailer === 'smtp' && in_array(strtolower((string) config('mail.mailers.smtp.host')), ['127.0.0.1', 'localhost', ''], true)) {
            $this->warn($g, 'MAIL_HOST', 'MAIL_HOST محلي مع MAIL_MAILER=smtp - تأكد من وجود خادم بريد فعلي على الجهاز');
        }
    }

    private function broadcasting(): void
    {
        $g = 'البث الفوري (Reverb)';
        $default = (string) config('broadcasting.default');

        if ($default !== 'reverb') {
            $this->warn($g, 'BROADCAST_CONNECTION', "BROADCAST_CONNECTION={$default} - لا تحديث لحظي للدردشة والإشعارات (الحفظ يعمل بلا تغيير)");

            return;
        }

        $broadcast = (array) config('broadcasting.connections.reverb', []);
        $app = (array) config('reverb.apps.apps.0', []);
        $missing = array_filter(['REVERB_APP_ID' => $broadcast['app_id'] ?? null, 'REVERB_APP_KEY' => $broadcast['key'] ?? null, 'REVERB_APP_SECRET' => $broadcast['secret'] ?? null], fn ($v) => ! filled($v));

        if ($missing !== []) {
            $this->prodFail($g, implode('/', array_keys($missing)), 'بيانات Reverb ناقصة: '.implode(', ', array_keys($missing)));

            return;
        }

        $this->pass($g, 'REVERB_APP_ID', 'REVERB_APP_ID/KEY/SECRET مضبوطة');

        $secret = (string) ($broadcast['secret'] ?? '');
        if (strlen($secret) < 16 || in_array(strtolower($secret), ['secret', 'change-me', 'changeme', 'reverb-secret', 'password'], true)) {
            $this->prodFail($g, 'REVERB_APP_SECRET', 'REVERB_APP_SECRET قصير أو افتراضي - ولّد قيمة عشوائية (≥ 32 خانة)');
        }

        $scheme = (string) ($app['options']['scheme'] ?? config('reverb.apps.apps.0.options.scheme', ''));
        $scheme === 'https'
            ? $this->pass($g, 'REVERB_SCHEME', 'REVERB_SCHEME=https')
            : $this->prodFail($g, 'REVERB_SCHEME', 'REVERB_SCHEME ليست https - WebSocket غير مشفّر (wss مطلوب بالإنتاج)');

        $host = strtolower((string) config('reverb.servers.reverb.hostname'));
        if (in_array($host, ['', 'localhost', '127.0.0.1'], true)) {
            $this->prodFail($g, 'REVERB_HOST', 'REVERB_HOST محلي/فارغ - اضبط اسم النطاق العام لخادم Reverb');
        }

        $origins = (array) ($app['allowed_origins'] ?? ['*']);
        if (in_array('*', $origins, true)) {
            $this->warn($g, 'REVERB_ALLOWED_ORIGINS', 'REVERB_ALLOWED_ORIGINS تسمح بكل الأصول (*) - ضيّقها لنطاق موقعك (قنوات الدردشة الخاصة محمية بالمصادقة لكن التقييد أفضل)');
        } else {
            $this->pass($g, 'REVERB_ALLOWED_ORIGINS', 'REVERB_ALLOWED_ORIGINS مقيّدة ('.count($origins).' أصل)');
        }

        if (! config('reverb.servers.reverb.scaling.enabled') && $this->production) {
            $this->info($g, 'REVERB_SCALING_ENABLED', 'REVERB_SCALING_ENABLED=false - مناسب لخادم Reverb واحد؛ عدة خوادم تتطلب Redis وتفعيله (docs/scaling-architecture.md)');
        }
    }

    // ───────────────────────────── الأمان والسجلات ─────────────────────────────

    private function security(): void
    {
        $g = 'الأمان';
        $proxies = config('trustedproxy.proxies');
        $https = str_starts_with(strtolower((string) config('app.url')), 'https://');

        if ($proxies === '*') {
            $this->warn($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES=* يثق بكل وكيل مباشر - آمن فقط إن كان التطبيق لا يُستقبَل إلا من الموازن (جدار ناري)؛ فضّل قائمة CIDR');
        } elseif (is_array($proxies) && $proxies !== []) {
            $this->pass($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES مقيّدة ('.count($proxies).' عنوان/شبكة)');
        } elseif ($this->production && $https) {
            $this->warn($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES غير مضبوط - خلف موازن/Cloudflare سيرى التطبيق عنوان الموازن بدل اللاعب (تتبع الجهاز، معدّلات IP، اكتشاف https)؛ اتركه فارغًا فقط إن استقبل الخادم المتصفح مباشرة');
        } else {
            $this->info($g, 'TRUSTED_PROXIES', 'TRUSTED_PROXIES غير مضبوط (سلوك افتراضي: لا وكلاء موثوقون)');
        }

        if (file_exists(public_path('.env')) || file_exists(public_path('.git'))) {
            $this->fail($g, 'public/', 'ملف .env أو مجلد .git داخل public/ - سيُخدَّم للعامة! أزله فورًا');
        }

        if (file_exists(public_path('hot'))) {
            $this->prodFail($g, 'VITE', 'ملف public/hot موجود (خادم Vite التطويري) - احذفه وابنِ الأصول: npm run build');
        }

        $this->checkEnvFilePermissions($g);

        if ($this->production) {
            foreach (['Laravel\\Telescope\\Telescope', 'Barryvdh\\Debugbar\\ServiceProvider', 'Spatie\\LaravelIgnition\\IgnitionServiceProvider'] as $class) {
                if (class_exists($class)) {
                    $this->warn($g, 'composer --no-dev', 'أداة تطوير/تشخيص مثبّتة ('.class_basename($class).') - ثبّت التبعيات بـcomposer install --no-dev وتأكد أنها معطلة بالإنتاج');
                }
            }

            if (class_exists('Pest\\TestSuite')) {
                $this->warn($g, 'composer --no-dev', 'تبعيات التطوير مثبّتة (Pest) - استعمل composer install --no-dev --prefer-dist --optimize-autoloader');
            }
        }

        $this->checkDiagnosticRoutes($g);
    }

    private function checkEnvFilePermissions(string $group): void
    {
        $env = base_path('.env');

        if (! $this->production || DIRECTORY_SEPARATOR !== '/' || ! is_file($env)) {
            return;
        }

        $perms = @fileperms($env);

        if ($perms !== false && ($perms & 0007) !== 0) {
            $this->warn($group, '.env', 'ملف .env قابل للقراءة من أي مستخدم على الخادم - قيّد الصلاحيات (chmod 640 ومالك التطبيق فقط)');
        } else {
            $this->pass($group, '.env', 'صلاحيات ملف .env مقيّدة (أو لا ملف: متغيرات بيئة العملية)');
        }
    }

    private function checkDiagnosticRoutes(string $group): void
    {
        $bad = [];

        foreach (Route::getRoutes() as $route) {
            if (preg_match('#^(_ignition|_debugbar|telescope|horizon|pulse|log-viewer|phpinfo|debug|tinker|adminer|phpmyadmin)(/|$)#i', ltrim($route->uri(), '/')) === 1) {
                $bad[] = ltrim($route->uri(), '/');
            }
        }

        $bad === []
            ? $this->pass($group, 'routes', 'لا مسارات تشخيص/إدارة عامة (ignition/telescope/horizon/debug…)')
            : $this->prodFail($group, 'routes', 'مسارات تشخيص عامة موجودة: '.implode(', ', array_slice(array_unique($bad), 0, 5)));
    }

    private function logging(): void
    {
        $g = 'السجلات';
        $default = (string) config('logging.default');
        $channels = $default === 'stack' ? (array) config('logging.channels.stack.channels', []) : [$default];

        if ($channels === []) {
            $this->warn($g, 'LOG_CHANNEL', 'لا قناة سجل فعّالة');

            return;
        }

        foreach ($channels as $channel) {
            $driver = (string) config("logging.channels.{$channel}.driver");
            $level = strtolower((string) config("logging.channels.{$channel}.level", 'debug'));

            if ($driver === 'single') {
                $this->production
                    ? $this->warn($g, 'LOG_STACK', 'قناة single تكتب ملفًا واحدًا بلا تدوير ولا حد حجم (قد يملأ القرص) - استعمل LOG_STACK=daily مع LOG_DAILY_DAYS أو logrotate')
                    : $this->info($g, 'LOG_STACK', 'قناة single (مقبول محليًا؛ بالإنتاج استعمل daily)');
            } elseif ($driver === 'daily') {
                $days = (int) config("logging.channels.{$channel}.days", 0);
                $days > 0
                    ? $this->pass($g, 'LOG_DAILY_DAYS', "قناة daily بتدوير يومي واحتفاظ {$days} يومًا")
                    : $this->warn($g, 'LOG_DAILY_DAYS', 'قناة daily بلا حد أيام الاحتفاظ - اضبط LOG_DAILY_DAYS');
            }

            if ($this->production && $level === 'debug' && in_array($driver, ['single', 'daily', 'stderr', 'errorlog', 'syslog'], true)) {
                $this->warn($g, 'LOG_LEVEL', 'LOG_LEVEL=debug بالإنتاج - ضجيج وحجم كبير وخطر تسجيل تفاصيل زائدة؛ استعمل info أو warning');
            }
        }
    }

    // ───────────────────────────── التخزين والأصول والتحسين ─────────────────────────────

    private function storage(): void
    {
        $g = 'التخزين';

        is_writable(storage_path())
            ? $this->pass($g, 'storage/', 'مجلد storage قابل للكتابة')
            : $this->fail($g, 'storage/', 'مجلد storage غير قابل للكتابة - راجع صلاحيات الملفات');

        is_writable(base_path('bootstrap/cache'))
            ? $this->pass($g, 'bootstrap/cache', 'مجلد bootstrap/cache قابل للكتابة')
            : $this->fail($g, 'bootstrap/cache', 'مجلد bootstrap/cache غير قابل للكتابة - راجع صلاحيات الملفات');

        if (File::exists(public_path('storage')) || is_link(public_path('storage'))) {
            $this->pass($g, 'public/storage', 'رابط public/storage موجود');
        } else {
            $this->warn($g, 'public/storage', 'رابط public/storage غير موجود - شغّل php artisan storage:link (لا تعتمد Windows Junction بالإنتاج)');
        }

        $disk = config('filesystems.disks.public', []);
        if (($disk['driver'] ?? 'local') === 'local') {
            $root = (string) ($disk['root'] ?? storage_path('app/public'));
            (is_dir($root) && is_writable($root))
                ? $this->pass($g, 'FILESYSTEM_DISK', 'قرص public قابل للكتابة (فحص صلاحيات فقط، بلا كتابة)')
                : $this->fail($g, 'FILESYSTEM_DISK', 'قرص public غير قابل للكتابة - سيفشل رفع أي ملف');
        } else {
            $this->info($g, 'FILESYSTEM_DISK', 'قرص public على مشغّل غير محلي - تحقق من بيانات الاعتماد خارج هذا الفحص');
        }

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free !== false && $total !== false && $total > 0) {
            $pct = round($free / $total * 100, 1);
            $gb = round($free / 1024 ** 3, 1);

            if ($pct < 5 || $free < 500 * 1024 ** 2) {
                $this->prodFail($g, 'disk', "مساحة القرص حرجة: متبقٍ {$pct}% ({$gb} GB) - امتلاء القرص يوقف الكتابة والسجلات والجلسات");
            } elseif ($pct < 15) {
                $this->warn($g, 'disk', "مساحة القرص منخفضة: متبقٍ {$pct}% ({$gb} GB)");
            } else {
                $this->pass($g, 'disk', "مساحة القرص كافية: متبقٍ {$pct}% ({$gb} GB)");
            }
        }
    }

    private function build(): void
    {
        $g = 'أصول الواجهة (Vite)';

        File::exists(public_path('build/manifest.json'))
            ? $this->pass($g, 'public/build', 'public/build/manifest.json موجود - الأصول مبنية')
            : $this->fail($g, 'public/build', 'public/build/manifest.json غير موجود - شغّل npm ci && npm run build قبل النشر');
    }

    private function optimization(): void
    {
        if (! $this->production) {
            return;
        }

        $g = 'التحسين (optimize)';
        $app = app();

        $missing = array_keys(array_filter([
            'config' => ! $app->configurationIsCached(),
            'routes' => ! $app->routesAreCached(),
            'events' => ! $app->eventsAreCached(),
        ]));

        $missing === []
            ? $this->pass($g, 'optimize', 'كاش config/routes/events مبني')
            : $this->warn($g, 'optimize', 'كاش غير مبني: '.implode(', ', $missing).' - php artisan optimize (يحسّن زمن كل طلب)');
    }

    // ───────────────────────────── الصلاحيات والحسابات ─────────────────────────────

    private function permissions(): void
    {
        $g = 'سجل الصلاحيات';

        File::exists(config_path('permission.php'))
            ? $this->pass($g, 'config/permission.php', 'config/permission.php (حزمة Spatie) موجود')
            : $this->fail($g, 'config/permission.php', 'config/permission.php غير موجود - إعدادات Spatie الأساسية مفقودة');

        File::exists(config_path('permissions.php'))
            ? $this->pass($g, 'config/permissions.php', 'config/permissions.php (سجلّنا الخاص) موجود')
            : $this->fail($g, 'config/permissions.php', 'config/permissions.php غير موجود - permissions:sync لن يعمل');

        try {
            $inDb = DB::table('permissions')->count();
            $roles = DB::table('roles')->count();
        } catch (Throwable) {
            $this->prodFail($g, 'permissions', 'جداول الصلاحيات/الأدوار غير موجودة - شغّل migrate');

            return;
        }

        $registry = collect((array) config('permissions', []))->flatMap(fn ($module) => array_keys($module['permissions'] ?? []))->unique()->values();

        if ($inDb === 0 || $roles === 0) {
            $this->prodFail($g, 'permissions', 'لا صلاحيات/أدوار بالقاعدة - شغّل permissions:sync ثم db:seed --class="Database\\Seeders\\RolesAndPermissionsSeeder" --force');

            return;
        }

        $present = DB::table('permissions')->whereIn('name', $registry->all())->count();
        $present >= $registry->count()
            ? $this->pass($g, 'permissions', "كل صلاحيات السجل ({$registry->count()}) موجودة بالقاعدة")
            : $this->warn($g, 'permissions', ($registry->count() - $present).' صلاحية من السجل غير موجودة بالقاعدة - شغّل permissions:sync ثم RolesAndPermissionsSeeder');

        $superAdmins = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super-admin')
            ->count();

        $superAdmins > 0
            ? $this->pass($g, 'super-admin', 'يوجد مدير أعلى (super-admin) واحد على الأقل')
            : $this->warn($g, 'super-admin', 'لا مدير أعلى بعد - اتبع bootstrap الأول: docs/first-production-deploy.md (AdminUserSeeder ثم rbac:migrate-legacy-roles)');
    }

    private function accounts(): void
    {
        $g = 'حسابات الاختبار المعروفة';
        $suffix = (string) config('operations.known_test_accounts.email_suffix', '@ahjiyat.test');
        $defaultAdmin = (string) config('operations.known_test_accounts.default_admin_email', 'admin@ahjiyat.app');

        try {
            $testUsers = DB::table('users')->where('email', 'like', '%'.$suffix)->count();
            $admin = DB::table('users')->where('email', $defaultAdmin)->first(['password']);
        } catch (Throwable) {
            return; // لا جدول مستخدمين بعد: تغطيه فحوص الترحيلات
        }

        $testUsers === 0
            ? $this->pass($g, 'users', "لا حسابات {$suffix} (بيانات DemoQa/UserSeeder) بالقاعدة")
            : $this->prodFail($g, 'users', "{$testUsers} حساب تجريبي ({$suffix}) بكلمة مرور معروفة بالقاعدة - لا تشغّل db:seed الكامل بالإنتاج؛ احذف هذه الحسابات");

        if ($admin === null) {
            return;
        }

        foreach ((array) config('operations.known_test_accounts.default_admin_passwords', []) as $known) {
            try {
                if (Hash::check((string) $known, (string) $admin->password)) {
                    $this->prodFail($g, 'ADMIN_BOOTSTRAP_PASSWORD', 'حساب المدير الافتراضي ما زال بكلمة المرور الافتراضية المعروفة - غيّرها فورًا');

                    return;
                }
            } catch (Throwable) {
                // خوارزمية تجزئة مختلفة: ليست الافتراضية بالتأكيد
            }
        }

        $this->pass($g, 'ADMIN_BOOTSTRAP_PASSWORD', 'حساب المدير الافتراضي لا يستعمل كلمة المرور الافتراضية');
    }

    // ───────────────────────────── مساعدات ─────────────────────────────

    /** true/false وجود الجدول، أو null إن تعذّر الوصول للقاعدة (يُبلَّغ عنه بفحص القاعدة نفسه). */
    // ───────────────────────────── التجارة (E41) ─────────────────────────────

    /**
     * مزوّد المحاكاة (sandbox) ليس بوابة دفع: يُرفض بالكود في الإنتاج مهما كان الضبط، لكن بقاء علمه/افتراضه في بيئة إنتاج خطأ تشغيلي يجب أن يظهر صراحة قبل النشر.
     * لا تُقرأ ولا تُعرض أي أسرار مزوّد هنا (حضور/غياب فقط).
     */
    private function commerce(): void
    {
        $g = 'التجارة';
        $flag = config('commerce.providers.sandbox.enabled');
        $sandboxFlag = $flag !== null && $flag !== '' && filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        $default = (string) config('commerce.default_provider', 'manual');
        $fallback = (string) config('commerce.fallback_provider', 'manual');

        if ($this->production && ($sandboxFlag || $default === 'sandbox' || $fallback === 'sandbox')) {
            $this->fail($g, 'COMMERCE_SANDBOX', 'مزوّد المحاكاة (sandbox) مفعَّل أو معيَّن افتراضيًا/بديلًا في بيئة إنتاج - يُرفض بالكود لكنه خطأ ضبط: أزل COMMERCE_SANDBOX_ENABLED واجعل COMMERCE_DEFAULT_PROVIDER=manual أو مزوّدًا حقيقيًا');
        } elseif ($default === 'sandbox' || $fallback === 'sandbox') {
            $this->warn($g, 'COMMERCE_DEFAULT_PROVIDER', 'المزوّد الافتراضي/البديل هو sandbox (محاكاة) - مقبول للتطوير والاختبار فقط');
        } else {
            $this->pass($g, 'COMMERCE_DEFAULT_PROVIDER', 'المزوّد الافتراضي ('.$default.') ليس مزوّد محاكاة');
        }

        $webhooks = config('commerce.webhooks.enabled');

        if ($this->production && $webhooks !== null && $webhooks !== '' && filter_var($webhooks, FILTER_VALIDATE_BOOLEAN)) {
            $live = collect(app(\App\Services\Commerce\CommercePaymentProviderRegistry::class)->all())
                ->filter(fn ($provider) => $provider->supportsWebhook() && $provider->acceptsWebhooks())->isNotEmpty();

            $live
                ? $this->pass($g, 'COMMERCE_WEBHOOKS_ENABLED', 'استقبال webhook التجارة مفعَّل ويوجد مزوّد يستقبله')
                : $this->warn($g, 'COMMERCE_WEBHOOKS_ENABLED', 'استقبال webhook التجارة مفعَّل ولا يوجد مزوّد يستقبله - عطّله حتى يُضبط مزوّد حقيقي');
        } else {
            $this->info($g, 'COMMERCE_WEBHOOKS_ENABLED', 'استقبال webhook التجارة مغلق افتراضيًا خارج بيئات التطوير');
        }
    }

    private function hasTable(?string $connection, string $table): ?bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            return null;
        }
    }

    private function add(string $status, string $group, string $setting, string $message): void
    {
        $this->report->add(new CheckResult($status, $group, $setting, $message));
    }

    private function pass(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::PASS, $group, $setting, $message);
    }

    private function warn(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::WARN, $group, $setting, $message);
    }

    private function fail(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::FAIL, $group, $setting, $message);
    }

    private function info(string $group, string $setting, string $message): void
    {
        $this->add(CheckResult::INFO, $group, $setting, $message);
    }

    /** مخالفة تحجب بالإنتاج فقط وتبقى تحذيرًا خارجه. */
    private function prodFail(string $group, string $setting, string $message): void
    {
        $this->add($this->production ? CheckResult::FAIL : CheckResult::WARN, $group, $setting, $message);
    }
}

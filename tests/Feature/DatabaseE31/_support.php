<?php

use App\Services\Database\SqliteToMysqlTransfer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| E31 - مساعدات مشتركة. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| الاختبارات «المحمولة» تعمل على أي سائق (SQLite سريع، وMySQL في مصفوفة التكافؤ)؛ اختبارات MySQL فقط تتخطى بوضوح على غيره (e31_requires_mysql).
| لا شبكة ولا أسرار. كل ملف SQLite مؤقت يُنشأ داخل مجلد النظام المؤقت ويُحذف في النهاية.
*/

require_once __DIR__.'/../SponsorshipE30/_support.php';

if (! function_exists('e31_driver')) {
    function e31_driver(): string
    {
        return DB::connection()->getDriverName();
    }

    function e31_is_mysql(): bool
    {
        return in_array(e31_driver(), ['mysql', 'mariadb'], true);
    }

    /** يتخطى الاختبار الجاري بوضوح إن لم يكن السائق MySQL/MariaDB (يُستدعى أول سطر في اختبار خاص بـMySQL). */
    function e31_requires_mysql(): void
    {
        if (! e31_is_mysql()) {
            test()->markTestSkipped('E31: يتطلب MySQL/MariaDB (السائق الحالي: '.e31_driver().'). شغّله بـphpunit.mysql.xml.dist - انظر docs/mysql-production-e31.md.');
        }
    }

    function e31_tmp_dir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'e31-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /** يحذف ملفات SQLite المؤقتة لهذه العملية (يُستدعى في afterAll/finally). */
    function e31_cleanup(): void
    {
        foreach ((array) glob(e31_tmp_dir().DIRECTORY_SEPARATOR.'*') as $file) {
            @unlink($file);
        }

        @rmdir(e31_tmp_dir());
    }

    /** ملف SQLite جديد مُرحَّل بالكامل (php artisan migrate) ومسجَّل كاتصال e31_{label}. يُرجع المسار. */
    function e31_new_sqlite(string $label): string
    {
        $path = e31_tmp_dir().DIRECTORY_SEPARATOR.$label.'-'.bin2hex(random_bytes(3)).'.sqlite';
        touch($path);
        $connection = 'e31_'.$label;
        config(['database.connections.'.$connection => array_merge((array) config('database.connections.sqlite'), [
            'driver' => 'sqlite', 'database' => $path, 'url' => null, 'foreign_key_constraints' => true,
        ])]);
        DB::purge($connection);
        Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
        DB::purge($connection);

        return $path;
    }

    /** ينفّذ $fn والاتصال الافتراضي = $connection (لتعبئة ملف المصدر بالخدمات الرسمية)، ثم يعيد الافتراضي. */
    function e31_with_default(string $connection, Closure $fn): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');

        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($previous);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
        }
    }

    /**
     * يعبّئ اتصال المصدر ببيانات واقعية عبر الخدمات الرسمية: مستخدمون وأدوار، أحجيات ومحاولات، محافظ ودفتر عملات، مبدع ومحتوى مشفَّر،
     * رعاية مالية (مساهمات مؤكدة + استرداد)، نص عربي وإيموجي، وصفوف في الجداول المؤقتة (يجب أن تُستثنى).
     *
     * @return array<string, mixed> أرقام مرجعية للتحقق
     */
    function e31_seed_world(string $connection): array
    {
        return e31_with_default($connection, function () {
            test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

            $admin = e30_admin();
            $player = e16User(['name' => 'لاعب عربي 🎮 ألِف']);
            $wallets = app(\App\Services\Economy\CurrencyWalletService::class);
            $currency = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency();
            $wallets->creditPending($player, $currency, 120, 'e31_seed', null, 'e31-seed-key-1');
            $wallets->releasePending($player, $currency, 70, 'e31_release');

            $supporter = e30_supporter(['display_name' => 'داعم الجواهر 💎']);
            $program = e30_program();
            e30_confirmed($supporter, $program, '12.50');
            $second = e30_confirmed($supporter, $program, '40');
            e30_refund($second);

            $creator = e28_creator();
            e28_category();
            e28_published($creator, 'text', ['title' => 'لغز مشفّر للنقل']);

            $puzzle = e27_puzzle('أحجية النقل');
            e27_attempt($player, $puzzle, true);

            // جداول مؤقتة يجب ألا تُنقل
            DB::table('sessions')->insert(['id' => 'e31-session-'.bin2hex(random_bytes(3)), 'user_id' => $player->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'e31', 'last_activity' => time()]);
            DB::table('cache')->insert(['key' => 'e31:cache', 'value' => 'x', 'expiration' => time() + 600]);
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

            return ['player_id' => $player->id, 'admin_id' => $admin->id, 'supporter_id' => $supporter->id, 'program_id' => $program->id, 'puzzle_id' => $puzzle->id];
        });
    }

    /** خدمة نقل تقبل هدف SQLite (للاختبارات المحمولة فقط؛ الأمر الحقيقي يشترط mysql/mariadb). */
    function e31_service(array $over = []): SqliteToMysqlTransfer
    {
        return new SqliteToMysqlTransfer($over + ['target_drivers' => ['mysql', 'mariadb', 'sqlite']] + (array) config('db_transfer'));
    }

    /** يربط الخدمة بملف مصدر ومسمّى اتصال هدف SQLite مُسجَّل مسبقًا. */
    function e31_connected(string $sourcePath, string $targetConnection, array $over = []): SqliteToMysqlTransfer
    {
        $service = e31_service($over);
        $service->connect($sourcePath, $targetConnection);

        return $service;
    }
}

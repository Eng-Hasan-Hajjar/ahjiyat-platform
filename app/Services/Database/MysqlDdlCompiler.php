<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use Throwable;

/**
 * E31: يولّد جمل DDL التي كانت ستُنفَّذ على MySQL/MariaDB لو شُغِّلت كل الترحيلات من الصفر، **بلا خادم** وبلا أي كتابة.
 *
 * الطريقة: اتصال وهمي بسائق mysql (أو mariadb) يُنفَّذ عليه up() لكل ترحيل داخل وضع pretend (Laravel يسجّل الجمل ولا ينفّذها).
 * لا يُفتح أي اتصال شبكي: يُحقَن PDO داخلي خامل (SQLite في الذاكرة) كي لا تطلبه أجزاء Laravel التي تسأل عن نسخة الخادم.
 * ما يعتمد على قراءة حالة القاعدة (hasTable/hasColumn/select) يرى قاعدة فارغة في هذا الوضع، وهو المطلوب لمحاكاة الترحيل من الصفر.
 *
 * النتيجة تُغذّي MysqlDdlLinter (فحص أسماء الفهارس، أنواع المفاتيح الأجنبية، حذف فهرس يحتاجه مفتاح...) وأمر db:parity --static.
 */
final class MysqlDdlCompiler
{
    private const CONNECTION = 'e31_ddl_static';

    /**
     * @return array{statements: list<array{migration: string, sql: string}>, errors: array<string, string>, migrations: int}
     */
    public function compile(?string $migrationsPath = null, string $driver = 'mysql'): array
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \InvalidArgumentException('Only the mysql and mariadb drivers can be compiled.');
        }

        $path = $migrationsPath ?? database_path('migrations');
        $files = app('migrator')->getMigrationFiles($path);

        $base = (array) config("database.connections.{$driver}");
        config(['database.connections.'.self::CONNECTION => array_merge($base, [
            'host' => '127.0.0.1', 'port' => '3306', 'database' => 'e31_ddl_static', 'username' => 'static', 'password' => '',
            'unix_socket' => '', 'url' => null, 'options' => [],
        ])]);

        $previousDefault = DB::getDefaultConnection();
        $previousCache = [config('cache.default'), config('permission.cache.store')];
        // بعض الترحيلات (Spatie) تمسح مفتاحًا من الكاش في آخرها؛ مخزن الذاكرة لا يلمس أي قاعدة.
        config(['cache.default' => 'array', 'permission.cache.store' => 'array']);

        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);
        $inert = new PDO('sqlite::memory:');
        $connection->setPdo($inert);
        $connection->setReadPdo($inert);

        $statements = [];
        $errors = [];

        try {
            DB::setDefaultConnection(self::CONNECTION);
            Facade::clearResolvedInstance('db.schema');

            foreach ($files as $name => $file) {
                try {
                    $migration = $this->resolve($name, $file);

                    $queries = $connection->pretend(function () use ($migration) {
                        $migration->up();
                    });

                    foreach ($queries as $query) {
                        $statements[] = ['migration' => (string) $name, 'sql' => (string) $query['query']];
                    }
                } catch (Throwable $e) {
                    $errors[(string) $name] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
                }
            }
        } finally {
            DB::setDefaultConnection($previousDefault);
            Facade::clearResolvedInstance('db.schema');
            config(['cache.default' => $previousCache[0], 'permission.cache.store' => $previousCache[1]]);
            DB::purge(self::CONNECTION);
            config(['database.connections.'.self::CONNECTION => null]);
        }

        return ['statements' => $statements, 'errors' => $errors, 'migrations' => count($files)];
    }

    private function resolve(string $name, string $file): object
    {
        static $required = [];

        // getRequire هو ما يستعمله مُرحِّل لارافل نفسه لتحميل ملفات الترحيل (ملفات المشروع المحلية فقط، لا مدخلات طلب).
        $result = $required[$file] ??= app(\Illuminate\Filesystem\Filesystem::class)->getRequire($file);

        if (is_object($result)) {
            return clone $result;
        }

        // ترحيل بصنف مسمّى (النمط القديم): الاسم بعد البادئة الزمنية الأربعة.
        $class = \Illuminate\Support\Str::studly(implode('_', array_slice(explode('_', $name), 4)));

        return new $class;
    }
}

<?php

namespace App\Services\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * E31: ينقل **كل بيانات التطبيق** من قاعدة SQLite إلى قاعدة MySQL/MariaDB فارغة المخطط (بعد php artisan migrate عليها).
 *
 * المبادئ (تُثبَّت باختبارات DatabaseE31):
 *  - **قيم خام فقط**: قراءة/كتابة عبر منشئ الاستعلامات، لا Eloquent ولا أحداث نماذج ولا خدمات مكافآت/إشعارات/عدادات، لا تجزئة ولا فك تشفير:
 *    القيم المشفّرة (APP_KEY نفسه) والـJSON تُنسخ كما هي نصًّا.
 *  - **المعرّفات تُحفظ** (المفاتيح الأساسية تُدرج صراحةً) ثم يُضبط AUTO_INCREMENT فوق max(id) (وفوق sqlite_sequence إن كان أعلى).
 *  - **دفعات محدودة** (500..2000 صف، مع سقف للمتغيرات والبايتات)، كل دفعة معاملة مستقلة؛ لا معاملة عملاقة. المصدر يُقرأ بمفتاح rowid بلا OFFSET.
 *  - **المصدر للقراءة فقط**: PRAGMA query_only=ON. الأمر الخارجي يثبت بصمة الملف قبل/بعد.
 *  - **المفاتيح الأجنبية**: تُعطَّل على اتصال الهدف فقط داخل try/finally، تُنسخ الجداول بترتيب اعتماد الأمهات أولًا، ثم تُتحقَّق الأيتام لكل مفتاح أجنبي.
 *  - **لا دمج**: الهدف يجب أن يكون فارغ البيانات (عدا الصفوف المرجعية التي زرعتها الترحيلات نفسها، وتُستبدل بصفوف المصدر بشروط صارمة).
 *  - **لا أسرار في أي مخرج**: الأرقام والأسماء وبصمات مجزّأة فقط؛ رسائل الأخطاء تُنقّى (انظر TransferException/sanitize).
 */
final class SqliteToMysqlTransfer
{
    public const SOURCE = 'e31_transfer_source';

    public const TARGET = 'e31_transfer_target';

    private Connection $source;

    private Connection $target;

    /** @var array<string, mixed> */
    private array $config;

    /** @param  array<string, mixed>|null  $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('db_transfer', []);
    }

    // ───────────────────────────── الاتصالات ─────────────────────────────

    /**
     * @param  array<string, mixed>  $targetOverrides
     */
    public function connect(string $sourcePath, string $targetConnection, array $targetOverrides = []): void
    {
        $sqlite = (array) config('database.connections.sqlite');
        $mysql = (array) config("database.connections.{$targetConnection}");

        config([
            'database.connections.'.self::SOURCE => array_merge($sqlite, [
                'driver' => 'sqlite', 'database' => $sourcePath, 'url' => null, 'foreign_key_constraints' => false,
                'busy_timeout' => null, 'journal_mode' => null, 'synchronous' => null, 'prefix' => '',
            ]),
            'database.connections.'.self::TARGET => array_merge($mysql, $targetOverrides, [
                'strict' => true, 'prefix' => '',
            ] + ($targetOverrides !== [] ? ['url' => null] : [])),
        ]);

        DB::purge(self::SOURCE);
        DB::purge(self::TARGET);

        $this->source = DB::connection(self::SOURCE);
        $this->target = DB::connection(self::TARGET);
        $this->source->statement('PRAGMA query_only = ON'); // قراءة فقط على مستوى SQLite نفسه
    }

    public function disconnect(): void
    {
        DB::purge(self::SOURCE);
        DB::purge(self::TARGET);
        config(['database.connections.'.self::SOURCE => null, 'database.connections.'.self::TARGET => null]);
    }

    public function source(): Connection
    {
        return $this->source;
    }

    public function target(): Connection
    {
        return $this->target;
    }

    // ───────────────────────────── التخطيط (قراءة فقط) ─────────────────────────────

    /**
     * @param  list<string>  $extraExclude
     * @return array{ok: bool, problems: list<string>, warnings: list<string>, tables: array<string, array<string, mixed>>, order: list<string>, target_facts: array<string, mixed>, source_rows: int, planned_rows: int}
     */
    public function plan(array $extraExclude = []): array
    {
        $problems = [];
        $warnings = [];
        $srcSchema = Schema::connection(self::SOURCE);
        $tgtSchema = Schema::connection(self::TARGET);

        $srcNames = $this->tableNames($srcSchema);
        $tgtNames = $this->tableNames($tgtSchema);
        $excluded = array_values(array_unique(array_merge((array) ($this->config['exclude'] ?? []), $extraExclude)));
        $reference = (array) ($this->config['reference_tables'] ?? []);

        $facts = app(DatabaseRuntimeInspector::class)->inspect(self::TARGET);

        if (! in_array($facts['driver'] ?? '', $this->allowedTargetDrivers(), true) || ($facts['reachable'] ?? false) !== true) {
            $problems[] = 'الهدف غير متاح أو ليس MySQL/MariaDB';
        } elseif ($this->isMysql()) {
            foreach ((new DatabaseFactsEvaluator)->evaluate($facts) as $result) {
                if (in_array($result['level'], ['fail', 'prodfail'], true) && in_array($result['key'], ['DB_CHARSET', 'DB_STRICT', 'DB_ENGINE', 'DB_VERSION'], true)) {
                    $problems[] = 'الهدف: '.$result['message'];
                }
            }
        }

        $this->checkMigrations($problems, $warnings);

        $tables = [];
        $planned = 0;
        $sourceRows = 0;

        foreach ($srcNames as $table) {
            if (in_array($table, $excluded, true)) {
                $tables[$table] = ['status' => 'excluded', 'source_rows' => $this->count($this->source, $table), 'target_rows' => 0];

                continue;
            }

            if (! in_array($table, $tgtNames, true)) {
                $problems[] = "الجدول {$table} موجود في المصدر وغير موجود في الهدف (شغّل php artisan migrate على الهدف أولًا)";

                continue;
            }

            $meta = $this->compareColumns($table, $srcSchema, $tgtSchema, $problems, $warnings);
            $srcCount = $this->count($this->source, $table);
            $tgtCount = $this->count($this->target, $table);
            $status = $srcCount === 0 ? 'empty' : 'copy';
            $seeded = 0;

            if ($tgtCount > 0) {
                if (isset($reference[$table])) {
                    $check = $this->referenceCheck($table, (string) $reference[$table]);

                    if ($check === null) {
                        $status = $srcCount === 0 ? 'reference-empty' : 'reference';
                        $seeded = $tgtCount;
                    } else {
                        $problems[] = "الجدول المرجعي {$table}: {$check}";
                    }
                } else {
                    $problems[] = "الجدول {$table} في الهدف غير فارغ ({$tgtCount} صف): لا دمج في E31 - استعمل --reset-target بتأكيد صريح أو قاعدة هدف جديدة";
                }
            }

            $this->preflightValues($table, $meta, $problems);
            $this->preflightUniqueCollisions($table, $meta, $problems);

            $tables[$table] = ['status' => $status, 'source_rows' => $srcCount, 'target_rows' => $tgtCount, 'seeded_rows' => $seeded] + $meta;
            $planned += $srcCount;
            $sourceRows += $srcCount;
        }

        foreach ($tgtNames as $table) {
            if (! in_array($table, $srcNames, true) && ! in_array($table, $excluded, true) && $table !== 'migrations') {
                $warnings[] = "الجدول {$table} موجود في الهدف فقط (مخطط أحدث من المصدر): يبقى فارغًا";
            }
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'warnings' => $warnings,
            'tables' => $tables,
            'order' => $this->dependencyOrder(array_keys(array_filter($tables, static fn (array $t): bool => ! in_array($t['status'], ['excluded'], true))), $tgtSchema),
            'target_facts' => $facts,
            'source_rows' => $sourceRows,
            'planned_rows' => $planned,
        ];
    }

    // ───────────────────────────── التنفيذ ─────────────────────────────

    /**
     * يحذف (TRUNCATE) جداول التطبيق في الهدف. وضع تعافٍ صريح فقط (يقرّر الأمر الخارجي تأكيده)، ولا يلمس migrations.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string> الجداول التي أُفرغت
     */
    public function resetTarget(array $plan): array
    {
        $cleared = [];

        try {
            $this->setForeignKeyChecks(false);

            foreach ($plan['tables'] as $table => $meta) {
                if ($meta['status'] === 'excluded' || $meta['target_rows'] === 0) {
                    continue;
                }

                $this->isMysql()
                    ? $this->target->statement('TRUNCATE TABLE '.$this->target->getQueryGrammar()->wrapTable($table))
                    : $this->target->table($table)->delete();
                $cleared[] = $table;
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        return $cleared;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  (callable(string, int, int): void)|null  $progress  (table, copied, total)
     * @return array{copied: array<string, int>, auto_increment: array<string, array<string, int>>}
     */
    public function execute(array $plan, ?callable $progress = null): array
    {
        if (! $plan['ok']) {
            throw new TransferException('الخطة غير سليمة: لا نسخ قبل معالجة المشكلات.');
        }

        $copied = [];
        $autoIncrement = [];

        try {
            foreach ($plan['order'] as $table) {
                $meta = $plan['tables'][$table];

                if (! in_array($meta['status'], ['copy', 'reference', 'reference-empty', 'empty'], true)) {
                    continue;
                }

                // الاتصال قد يُعاد فتحه بين الجداول الطويلة؛ إعادة الضبط idempotent.
                $this->setForeignKeyChecks(false);

                if (in_array($meta['status'], ['reference', 'reference-empty'], true)) {
                    $this->target->table($table)->delete(); // صفوف الترحيلات المرجعية فقط (تحقّق منها plan())، يحلّ محلها المصدر
                }

                $copied[$table] = $meta['source_rows'] === 0 ? 0 : $this->copyTable($table, $meta, $progress);

                if ($copied[$table] > 0 && ($fixed = $this->fixAutoIncrement($table, $meta)) !== null) {
                    $autoIncrement[$table] = $fixed;
                }
            }
        } finally {
            $this->setForeignKeyChecks(true);
        }

        if (! $this->foreignKeyChecksEnabled()) {
            throw new TransferException('تعذّر التأكد من إعادة تفعيل FOREIGN_KEY_CHECKS على الهدف - لا تستعمل هذه القاعدة قبل فحصها.');
        }

        return ['copied' => $copied, 'auto_increment' => $autoIncrement];
    }

    // ───────────────────────────── التحقق بعد النقل ─────────────────────────────

    /**
     * @param  array<string, mixed>  $plan
     * @param  (callable(string, string): void)|null  $progress  (stage, table)
     * @return array<string, mixed>
     */
    public function validate(array $plan, bool $fingerprints = true, ?callable $progress = null): array
    {
        $tables = [];
        $rowMismatches = 0;
        $fpMismatches = 0;
        $migrated = 0;
        $rows = 0;
        $skipped = 0;
        $tgtSchema = Schema::connection(self::TARGET);

        foreach ($plan['tables'] as $table => $meta) {
            if ($meta['status'] === 'excluded') {
                $skipped++;
                $tables[$table] = ['status' => 'excluded', 'source_rows' => $meta['source_rows'], 'target_rows' => null, 'row_match' => null, 'digest' => null, 'digest_match' => null];

                continue;
            }

            $progress && $progress('rows', $table);
            $tgtCount = $this->count($this->target, $table);
            $match = $tgtCount === $meta['source_rows'];
            $rowMismatches += $match ? 0 : 1;
            $row = ['status' => $meta['status'], 'source_rows' => $meta['source_rows'], 'target_rows' => $tgtCount, 'row_match' => $match, 'digest' => null, 'digest_match' => null];

            if ($fingerprints && $meta['source_rows'] > 0) {
                $progress && $progress('fingerprint', $table);
                $a = $this->digest($this->source, $table, $meta, true);
                $b = $this->digest($this->target, $table, $meta, false);
                $row['digest'] = $b['digest'];
                $row['digest_match'] = $a['digest'] === $b['digest'] && $a['rows'] === $b['rows'];
                $fpMismatches += $row['digest_match'] ? 0 : 1;
            }

            $migrated++;
            $rows += $tgtCount;
            $tables[$table] = $row;
        }

        $progress && $progress('orphans', '*');
        $orphans = $this->orphans($plan, $tgtSchema);
        $integrity = array_sum(array_column($orphans, 'count'));

        $progress && $progress('auto_increment', '*');
        $autoIncrement = $this->autoIncrementReport($plan);
        $aiIssues = count(array_filter($autoIncrement, static fn (array $r): bool => ! $r['ok']));

        $progress && $progress('financial', '*');
        $financial = $this->financial($plan);
        $financialMismatches = count(array_filter($financial, static fn (array $r): bool => ! $r['match']));

        $pass = $rowMismatches === 0 && $fpMismatches === 0 && $integrity === 0 && $aiIssues === 0 && $financialMismatches === 0;

        return [
            'tables' => $tables,
            'totals' => [
                'tables_migrated' => $migrated, 'rows_migrated' => $rows, 'skipped_ephemeral' => $skipped,
                'row_count_mismatches' => $rowMismatches, 'integrity_violations' => $integrity, 'fingerprint_mismatches' => $fpMismatches,
                'auto_increment_issues' => $aiIssues, 'financial_mismatches' => $financialMismatches,
            ],
            'orphans' => $orphans,
            'auto_increment' => $autoIncrement,
            'financial' => $financial,
            'fingerprints_checked' => $fingerprints,
            'result' => $pass ? 'PASS' : 'FAIL',
        ];
    }

    // ───────────────────────────── النسخ ─────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     * @param  (callable(string, int, int): void)|null  $progress
     */
    private function copyTable(string $table, array $meta, ?callable $progress): int
    {
        $columns = $meta['shared_columns'];
        $json = array_flip($meta['json_columns']);
        $maxRows = $this->batchRows(count($columns));
        $maxBytes = max(65536, (int) ($this->config['max_batch_bytes'] ?? 1_000_000));
        $last = 0;
        $total = 0;
        $select = array_merge([$this->source->raw('rowid as "__e31_rid"')], $columns);

        while (true) {
            $chunk = $this->source->table($table)->select($select)->whereRaw('rowid > ?', [$last])->orderByRaw('rowid')->limit($maxRows)->get();

            if ($chunk->isEmpty()) {
                break;
            }

            $batch = [];
            $rids = [];
            $bytes = 0;

            foreach ($chunk as $record) {
                $row = (array) $record;
                $rid = (int) $row['__e31_rid'];
                unset($row['__e31_rid']);
                $last = $rid;

                foreach ($json as $column => $_) {
                    if ($row[$column] !== null && ! $this->validJson((string) $row[$column])) {
                        throw new TransferException("الجدول {$table}: العمود {$column} يحوي JSON غير صالح في الصف rowid={$rid} (لن يقبله MySQL) - صحّح البيانات في المصدر.");
                    }
                }

                $batch[] = $row;
                $rids[] = $rid;
                $bytes += $this->approxBytes($row);

                if (count($batch) >= $maxRows || $bytes >= $maxBytes) {
                    $this->insertBatch($table, $batch, $rids);
                    $total += count($batch);
                    $batch = [];
                    $rids = [];
                    $bytes = 0;
                }
            }

            if ($batch !== []) {
                $this->insertBatch($table, $batch, $rids);
                $total += count($batch);
            }

            $progress && $progress($table, $total, (int) $meta['source_rows']);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @param  list<int>  $rids
     */
    private function insertBatch(string $table, array $batch, array $rids): void
    {
        try {
            $this->target->transaction(fn () => $this->target->table($table)->insert($batch));
        } catch (Throwable $e) {
            throw $this->diagnose($table, $batch, $rids, $e);
        }
    }

    /**
     * عند فشل دفعة: يحدّد الصف المسبب بإدراج صف-صف داخل معاملة تُلغى دائمًا، ويُرجع خطأ بلا أي قيمة من البيانات.
     *
     * @param  list<array<string, mixed>>  $batch
     * @param  list<int>  $rids
     */
    private function diagnose(string $table, array $batch, array $rids, Throwable $original): TransferException
    {
        $info = self::sanitize($original);

        try {
            $this->target->beginTransaction();

            try {
                foreach ($batch as $i => $row) {
                    try {
                        $this->target->table($table)->insert([$row]);
                    } catch (Throwable $e) {
                        $info = self::sanitize($e);
                        $id = $row['id'] ?? $rids[$i];

                        return new TransferException("الجدول {$table}: رُفض الصف id={$id} - {$info['category']} (MySQL {$info['code']})".($info['column'] !== null ? "، العمود {$info['column']}" : '').($info['key'] !== null ? "، الفهرس {$info['key']}" : '').'.');
                    }
                }
            } finally {
                $this->target->rollBack();
            }
        } catch (Throwable) {
            // فشل التشخيص نفسه: نكتفي بالتصنيف الأول.
        }

        return new TransferException("الجدول {$table}: رُفضت دفعة من ".count($batch)." صفًّا - {$info['category']} (MySQL {$info['code']}).");
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, int>|null
     */
    private function fixAutoIncrement(string $table, array $meta): ?array
    {
        $column = $meta['auto_increment_column'] ?? null;

        if ($column === null) {
            return null;
        }

        $maxId = (int) $this->target->table($table)->max($column);
        $sequence = 0;

        try {
            $sequence = (int) ($this->source->table('sqlite_sequence')->where('name', $table)->value('seq') ?? 0);
        } catch (Throwable) {
            // لا جدول sqlite_sequence (لا AUTOINCREMENT): المعرّف التالي يكفيه max(id).
        }

        $wanted = max($maxId, $sequence) + 1;
        $current = $this->currentAutoIncrement($table);

        if ($current < $wanted && $this->isMysql()) {
            $this->target->statement('ALTER TABLE '.$this->target->getQueryGrammar()->wrapTable($table).' AUTO_INCREMENT = '.$wanted);
            $current = $wanted;
        }

        return ['max_id' => $maxId, 'sqlite_sequence' => $sequence, 'next' => $current];
    }

    // ───────────────────────────── مقارنة وفحوص ─────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     * @return array{rows: int, digest: string}
     */
    private function digest(Connection $conn, string $table, array $meta, bool $isSource): array
    {
        $columns = $meta['shared_columns'];
        sort($columns);
        $classes = $meta['classes'];
        $json = array_flip($meta['json_columns']);
        $lanes = [0, 0, 0, 0];
        $count = 0;
        $size = 2000;

        if ($isSource) {
            $last = 0;
            $select = array_merge([$conn->raw('rowid as "__e31_rid"')], $columns);

            while (true) {
                $chunk = $conn->table($table)->select($select)->whereRaw('rowid > ?', [$last])->orderByRaw('rowid')->limit($size)->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $record) {
                    $row = (array) $record;
                    $last = (int) $row['__e31_rid'];
                    unset($row['__e31_rid']);
                    $this->accumulate($lanes, $columns, $row, $classes, $json);
                    $count++;
                }
            }
        } else {
            $primary = (array) ($meta['target_primary'] ?? []);
            $keyset = count($primary) === 1 && ($classes[$primary[0]] ?? '') === 'int' ? $primary[0] : null;
            $offset = 0;
            $lastKey = null;

            while (true) {
                $q = $conn->table($table)->select($columns);

                if ($keyset !== null) {
                    $q->select(array_values(array_unique(array_merge([$keyset], $columns))));
                    if ($lastKey !== null) {
                        $q->where($keyset, '>', $lastKey);
                    }

                    $q->orderBy($keyset)->limit($size);
                } else {
                    foreach (($primary !== [] ? $primary : $columns) as $orderColumn) {
                        $q->orderBy($orderColumn);
                    }
                    $q->offset($offset)->limit($size);
                }

                $chunk = $q->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $record) {
                    $row = (array) $record;

                    if ($keyset !== null) {
                        $lastKey = $row[$keyset];
                    }

                    $this->accumulate($lanes, $columns, $row, $classes, $json);
                    $count++;
                }

                $offset += $size;
            }
        }

        return ['rows' => $count, 'digest' => substr(hash('sha256', implode('.', $lanes).'#'.$count), 0, 24)];
    }

    /**
     * @param  list<int>  $lanes
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $classes
     * @param  array<string, int>  $json
     */
    private function accumulate(array &$lanes, array $columns, array $row, array $classes, array $json): void
    {
        $payload = '';

        foreach ($columns as $column) {
            $value = self::canonical($row[$column] ?? null, $classes[$column] ?? 'string', isset($json[$column]));
            $payload .= $column.'='.strlen($value).':'.$value."\n";
        }

        $parts = unpack('N4', substr(hash('sha256', $payload, true), 0, 16));

        foreach ([0, 1, 2, 3] as $i) {
            $lanes[$i] += $parts[$i + 1];
        }
    }

    /** تمثيل قانوني موحّد للقيمة كي تتطابق البصمة رغم اختلاف تمثيل SQLite وMySQL (int مقابل string، ترتيب مفاتيح JSON...). */
    public static function canonical(mixed $value, string $class, bool $isJson = false): string
    {
        if ($value === null) {
            return "\0null";
        }

        if ($isJson) {
            $decoded = json_decode((string) $value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode(self::sortKeys($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '';
            }
        }

        return match ($class) {
            'int', 'bool', 'decimal', 'float' => self::number($value),
            'binary' => 'b64:'.base64_encode((string) $value),
            default => (string) $value,
        };
    }

    public static function number(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        $s = is_float($value) ? sprintf('%.10F', $value) : trim((string) $value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            return $s;
        }

        $int = ltrim($m[2], '0');
        $int = $int === '' ? '0' : $int;
        $frac = isset($m[3]) ? rtrim($m[3], '0') : '';
        $out = $int.($frac !== '' ? '.'.$frac : '');

        return ($m[1] === '-' && $out !== '0' ? '-' : '').$out;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'sortKeys'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{table: string, fk: string, count: int}>
     */
    private function orphans(array $plan, SchemaBuilder $tgtSchema): array
    {
        $result = [];

        foreach ($plan['tables'] as $table => $meta) {
            if ($meta['status'] === 'excluded') {
                continue;
            }

            foreach ($tgtSchema->getForeignKeys($table) as $fk) {
                $refTable = (string) $fk['foreign_table'];
                $q = $this->target->table("{$table} as c")->leftJoin("{$refTable} as p", function ($join) use ($fk) {
                    foreach ($fk['columns'] as $i => $column) {
                        $join->on("c.{$column}", '=', 'p.'.$fk['foreign_columns'][$i]);
                    }
                })->whereNull('p.'.$fk['foreign_columns'][0]);

                foreach ($fk['columns'] as $column) {
                    $q->whereNotNull("c.{$column}");
                }

                $count = (int) $q->count();

                if ($count > 0) {
                    $result[] = ['table' => $table, 'fk' => implode(',', $fk['columns']).'→'.$refTable, 'count' => $count];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array{ok: bool, max_id: int, next: int|null}>
     */
    private function autoIncrementReport(array $plan): array
    {
        $report = [];

        foreach ($plan['tables'] as $table => $meta) {
            $column = $meta['auto_increment_column'] ?? null;

            if ($meta['status'] === 'excluded' || $column === null || $meta['source_rows'] === 0) {
                continue;
            }

            $maxId = (int) $this->target->table($table)->max($column);
            $next = $this->currentAutoIncrement($table);
            $report[$table] = ['ok' => $next > $maxId, 'max_id' => $maxId, 'next' => $next];
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{table: string, column: string, source: string, target: string, match: bool}>
     */
    private function financial(array $plan): array
    {
        $out = [];

        foreach ((array) ($this->config['financial_sums'] ?? []) as $table => $columns) {
            if (! isset($plan['tables'][$table]) || $plan['tables'][$table]['status'] === 'excluded') {
                continue;
            }

            foreach ($columns as $column) {
                if (! in_array($column, $plan['tables'][$table]['shared_columns'], true)) {
                    continue;
                }

                $a = self::number($this->source->table($table)->sum($column) ?? 0);
                $b = self::number($this->target->table($table)->sum($column) ?? 0);
                $out[] = ['table' => $table, 'column' => $column, 'source' => $a, 'target' => $b, 'match' => $a === $b];
            }
        }

        return $out;
    }

    // ───────────────────────────── تحضير وفحوص ما قبل النقل ─────────────────────────────

    /**
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function compareColumns(string $table, SchemaBuilder $srcSchema, SchemaBuilder $tgtSchema, array &$problems, array &$warnings): array
    {
        $src = [];
        foreach ($srcSchema->getColumns($table) as $column) {
            $src[(string) $column['name']] = $column;
        }

        $tgt = [];
        foreach ($tgtSchema->getColumns($table) as $column) {
            $tgt[(string) $column['name']] = $column;
        }

        foreach (array_diff(array_keys($src), array_keys($tgt)) as $missing) {
            $problems[] = "الجدول {$table}: العمود {$missing} موجود في المصدر وغير موجود في الهدف (فقدان بيانات) - طابق مخطط الهدف مع المصدر";
        }

        foreach (array_diff(array_keys($tgt), array_keys($src)) as $extra) {
            $column = $tgt[$extra];

            if (! $column['nullable'] && $column['default'] === null && ! $column['auto_increment'] && empty($column['generation'])) {
                $problems[] = "الجدول {$table}: العمود {$extra} إلزامي في الهدف بلا قيمة افتراضية وغير موجود في المصدر";
            } else {
                $warnings[] = "الجدول {$table}: العمود {$extra} موجود في الهدف فقط ويأخذ قيمته الافتراضية";
            }
        }

        $shared = array_values(array_filter(array_keys($tgt), static fn (string $name): bool => isset($src[$name])));
        $classes = [];
        $json = [];
        $auto = null;

        foreach ($shared as $name) {
            $type = strtolower((string) $tgt[$name]['type']);
            $classes[$name] = SchemaSnapshot::typeClass($type);

            if (($tgt[$name]['type_name'] ?? '') === 'json' || $type === 'json') {
                $json[] = $name;
            }

            if (! empty($tgt[$name]['auto_increment'])) {
                $auto = $name;
            }
        }

        $primary = [];
        $unique = [];

        foreach ($tgtSchema->getIndexes($table) as $index) {
            if (! empty($index['primary'])) {
                $primary = array_values(array_map('strval', $index['columns']));
            } elseif (! empty($index['unique'])) {
                $unique[] = array_values(array_map('strval', $index['columns']));
            }
        }

        return [
            'shared_columns' => $shared, 'classes' => $classes, 'json_columns' => $json, 'auto_increment_column' => $auto,
            'target_primary' => $primary, 'target_unique' => $unique, 'target_types' => array_map(static fn (array $c): string => strtolower((string) $c['type']), $tgt),
        ];
    }

    /** تعبير SQLite لطول نص: المعرّف يأتي من مخطط الهدف (لا من طلب) ويُقتبس بقواعد الاتصال نفسه. */
    private function lengthExpression(string $column): string
    {
        return 'length('.$this->source->getQueryGrammar()->wrap($column).')';
    }

    private function jsonValidExpression(string $column): string
    {
        return 'json_valid('.$this->source->getQueryGrammar()->wrap($column).')';
    }

    /**
     * فحوص قراءة فقط على المصدر لقيم يقبلها SQLite ويرفضها MySQL الصارم (أعداد فقط في الرسائل - لا قيم).
     *
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $problems
     */
    private function preflightValues(string $table, array $meta, array &$problems): void
    {
        foreach ($meta['shared_columns'] as $column) {
            $type = $meta['target_types'][$column] ?? '';

            try {
                if (str_contains($type, 'unsigned') && SchemaSnapshot::typeClass($type) === 'int') {
                    $n = $this->source->table($table)->where($column, '<', 0)->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف بقيمة سالبة في العمود UNSIGNED {$column} (يرفضها MySQL)";
                }

                if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) === 1) {
                    $n = $this->source->table($table)->whereRaw($this->lengthExpression($column).' > ?', [(int) $m[1]])->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} أطول من {$m[1]} محرفًا (يرفضها MySQL الصارم)";
                }

                if (in_array($column, $meta['json_columns'], true)) {
                    $n = $this->source->table($table)->whereNotNull($column)->whereRaw($this->jsonValidExpression($column).' = 0')->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} بـJSON غير صالح (يرفضه MySQL)";
                }

                if (in_array($type, ['timestamp', 'datetime', 'date'], true) || str_starts_with($type, 'timestamp(') || str_starts_with($type, 'datetime(')) {
                    $n = $this->source->table($table)->where($column, 'like', '0000-00-00%')->count();
                    $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} بتاريخ صفري (0000-00-00) يرفضه MySQL";

                    if (str_starts_with($type, 'timestamp')) {
                        $n = $this->source->table($table)->whereNotNull($column)->where(fn ($q) => $q->where($column, '<', '1970-01-01 00:00:01')->orWhere($column, '>', '2038-01-19 03:14:07'))->where($column, 'not like', '0000-00-00%')->count();
                        $n > 0 && $problems[] = "الجدول {$table}: {$n} صف/صفوف في {$column} خارج نطاق TIMESTAMP (1970..2038) - غيّر العمود إلى DATETIME أو صحّح البيانات";
                    }
                }
            } catch (Throwable) {
                // دالة غير متاحة في هذا البناء من SQLite (مثل json_valid): نتجاوز هذا الفحص ويلتقطه فحص النسخ نفسه.
            }
        }
    }

    /**
     * utf8mb4_unicode_ci غير حساس لحالة الأحرف (وللتشكيل/اللكنات) بينما SQLite حساس: صفّان مختلفان هناك (A@x.com وa@x.com) يتصادمان على فهرس فريد هنا.
     * نكشف تصادم **الحالة اللاتينية** مسبقًا (lower() في SQLite)؛ وبقية الحالات (لكنات/تشكيل) تُلتقط وقت النسخ برمز 1062 بلا قيم.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $problems
     */
    private function preflightUniqueCollisions(string $table, array $meta, array &$problems): void
    {
        $grammar = $this->source->getQueryGrammar();

        foreach ($meta['target_unique'] ?? [] as $columns) {
            $strings = array_filter($columns, static fn (string $c): bool => ($meta['classes'][$c] ?? '') === 'string');

            if ($strings === [] || array_diff($columns, $meta['shared_columns']) !== []) {
                continue;
            }

            $group = implode(', ', array_map(static fn (string $c): string => in_array($c, $strings, true) ? 'lower('.$grammar->wrap($c).')' : $grammar->wrap($c), $columns));
            $notNull = implode(' and ', array_map(static fn (string $c): string => $grammar->wrap($c).' is not null', $columns));

            try {
                $row = $this->source->selectOne('select count(*) as n from (select 1 from '.$grammar->wrapTable($table)." where {$notNull} group by {$group} having count(*) > 1) as d");
            } catch (Throwable) {
                continue;
            }

            if ((int) ($row->n ?? 0) > 0) {
                $problems[] = "الجدول {$table}: {$row->n} مجموعة/مجموعات مكررة بتجاهل حالة الأحرف على الفهرس الفريد (".implode(',', $columns).') - ستتصادم في MySQL (تجميع غير حساس للحالة)؛ وحِّدها في المصدر أولًا';
            }
        }
    }

    /** @return string|null null = الصفوف المرجعية سليمة، وإلا سبب الرفض. */
    private function referenceCheck(string $table, string $naturalKey): ?string
    {
        if ($this->count($this->target, $table) > 500) {
            return 'عدد صفوف الهدف كبير جدًا لجدول مرجعي زرعته الترحيلات';
        }

        try {
            $targetRows = $this->target->table($table)->select(['id', $naturalKey])->get();
            $byKey = $this->source->table($table)->pluck('id', $naturalKey)->all();
            $byId = $this->source->table($table)->pluck($naturalKey, 'id')->all();
        } catch (Throwable) {
            return 'تعذّر مقارنة الصفوف المرجعية بين المصدر والهدف';
        }

        foreach ($targetRows as $row) {
            $key = (string) $row->{$naturalKey};
            $id = (int) $row->id;

            if (isset($byKey[$key])) {
                if ((int) $byKey[$key] !== $id) {
                    return "تعارض معرّف: المفتاح الطبيعي {$naturalKey} نفسه بمعرّف مختلف بين المصدر والهدف";
                }
            } elseif (isset($byId[$id])) {
                return "تعارض معرّف: المعرّف {$id} مستعمل بمفتاح طبيعي مختلف في المصدر";
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    private function checkMigrations(array &$problems, array &$warnings): void
    {
        try {
            $src = $this->source->table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            $warnings[] = 'جدول migrations غير موجود في المصدر: لا يمكن مقارنة نسخة المخطط';

            return;
        }

        try {
            $tgt = $this->target->table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            $problems[] = 'جدول migrations غير موجود في الهدف: شغّل php artisan migrate على قاعدة MySQL أولًا';

            return;
        }

        $missing = count(array_diff($src, $tgt));
        $newer = count(array_diff($tgt, $src));

        if ($missing > 0) {
            $problems[] = "الهدف ينقصه {$missing} ترحيل/ترحيلات منفَّذة في المصدر: شغّل php artisan migrate على الهدف بالكود الحالي";
        }

        if ($newer > 0) {
            $warnings[] = "الهدف عليه {$newer} ترحيل/ترحيلات إضافية لم تُنفَّذ على المصدر (مخطط أحدث): أعمدتها الجديدة تأخذ قيمها الافتراضية";
        }
    }

    // ───────────────────────────── أدوات ─────────────────────────────

    /**
     * ترتيب الجداول: الأمهات قبل الأبناء (من مفاتيح الهدف الأجنبية)، تُكسَر الدورات/الذاتية بالترتيب الأبجدي.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function dependencyOrder(array $tables, SchemaBuilder $tgtSchema): array
    {
        sort($tables);
        $set = array_flip($tables);
        $deps = [];

        foreach ($tables as $table) {
            $deps[$table] = [];

            foreach ($tgtSchema->getForeignKeys($table) as $fk) {
                $parent = (string) $fk['foreign_table'];

                if ($parent !== $table && isset($set[$parent])) {
                    $deps[$table][$parent] = true;
                }
            }
        }

        $ordered = [];
        $remaining = $deps;

        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, static fn (array $d): bool => array_diff_key($d, array_flip($ordered)) === []));

            if ($ready === []) {
                $ready = [array_key_first($remaining)]; // دورة: نكسرها (المفاتيح الأجنبية معطّلة أثناء النسخ على أي حال)
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }

    /** @return list<string> */
    private function allowedTargetDrivers(): array
    {
        return (array) ($this->config['target_drivers'] ?? ['mysql', 'mariadb']);
    }

    private function isMysql(): bool
    {
        return in_array($this->target->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function setForeignKeyChecks(bool $on): void
    {
        $this->isMysql()
            ? $this->target->statement('SET FOREIGN_KEY_CHECKS='.($on ? '1' : '0'))
            : $this->target->statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF'));
    }

    private function foreignKeyChecksEnabled(): bool
    {
        if ($this->isMysql()) {
            return (int) ($this->target->selectOne('select @@foreign_key_checks as fk')->fk ?? 0) === 1;
        }

        return (int) ((array) $this->target->selectOne('PRAGMA foreign_keys'))['foreign_keys'] === 1;
    }

    /** @return list<string> */
    private function tableNames(SchemaBuilder $schema): array
    {
        $names = [];

        foreach ($schema->getTables() as $table) {
            $name = (string) $table['name'];

            if (! str_starts_with($name, 'sqlite_')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    private function count(Connection $conn, string $table): int
    {
        return (int) $conn->table($table)->count();
    }

    private function currentAutoIncrement(string $table): int
    {
        if (! $this->isMysql()) {
            // SQLite (يُستعمل في الاختبارات فقط): sqlite_sequence يتبع أعلى معرّف أُدرج صراحةً.
            try {
                return (int) ($this->target->table('sqlite_sequence')->where('name', $table)->value('seq') ?? 0) + 1;
            } catch (Throwable) {
                return 0;
            }
        }

        try {
            $this->target->statement('SET SESSION information_schema_stats_expiry = 0'); // MySQL 8: بلا ذلك قد تُرجع information_schema قيمة قديمة مخزّنة
        } catch (Throwable) {
            // MariaDB لا تعرف هذا المتغير ولا تحتاجه.
        }

        $row = $this->target->selectOne('select auto_increment as ai from information_schema.tables where table_schema = database() and table_name = ?', [$table]);

        return (int) ($row->ai ?? 0);
    }

    private function batchRows(int $columnCount): int
    {
        $size = max(500, min(2000, (int) ($this->config['batch_size'] ?? 1000)));

        return max(1, min($size, intdiv(60000, max(1, $columnCount))));
    }

    /** @param  array<string, mixed>  $row */
    private function approxBytes(array $row): int
    {
        $bytes = 0;

        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) + 4 : 12;
        }

        return $bytes;
    }

    private function validJson(string $value): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * يحوّل أي استثناء قاعدة إلى وصف آمن: رمز MySQL وتصنيف واسم العمود/الفهرس فقط. لا قيم ولا نص الخادم.
     *
     * @return array{code: int|string, category: string, column: string|null, key: string|null}
     */
    public static function sanitize(Throwable $e): array
    {
        $previous = $e->getPrevious() ?? $e;
        $info = $previous instanceof \PDOException ? $previous->errorInfo : null;
        $code = $info[1] ?? ($previous->getCode() ?: 0);
        $message = $previous->getMessage();

        $category = match ((int) $code) {
            1062 => 'تكرار في فهرس فريد (قد يكون تصادم تجميع utf8mb4_unicode_ci غير الحساس للحالة)',
            1048 => 'قيمة NULL في عمود NOT NULL',
            1406 => 'قيمة أطول من طول العمود',
            1366 => 'قيمة غير صالحة للعمود (ترميز أو نوع)',
            1292 => 'قيمة تاريخ/رقم غير صالحة',
            1264 => 'قيمة خارج نطاق العمود الرقمي (مثل سالب في UNSIGNED)',
            1265 => 'بيانات مقتطعة',
            3140 => 'JSON غير صالح',
            1452, 1451 => 'انتهاك مفتاح أجنبي',
            1153, 2006 => 'تجاوز max_allowed_packet أو انقطاع الاتصال',
            default => 'رفض من الخادم',
        };

        return [
            'code' => $code,
            'category' => $category,
            'column' => preg_match("/column '([A-Za-z0-9_]+)'/i", $message, $m) === 1 ? $m[1] : null,
            'key' => preg_match("/for key '([A-Za-z0-9_.]+)'/i", $message, $k) === 1 ? $k[1] : null,
        ];
    }
}

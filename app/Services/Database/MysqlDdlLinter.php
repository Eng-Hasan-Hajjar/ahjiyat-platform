<?php

namespace App\Services\Database;

/**
 * E31: يحلّل جمل DDL الخاصة بـMySQL/MariaDB (من MysqlDdlCompiler أو من نص جاهز) ويطبّق قواعد الأخطاء التي تظهر على MySQL فقط ولا تظهر على SQLite:
 *
 *  fail  identifier_too_long        اسم جدول/عمود/فهرس/مفتاح أجنبي/قيد > 64 محرفًا (الخطأ 1059)
 *  fail  index_needed_by_fk         حذف فهرس هو الوحيد الذي يخدم مفتاحًا أجنبيًا (الخطأ 1553)
 *  fail  fk_type_mismatch           نوع/إشارة عمود المفتاح الأجنبي لا يطابق العمود المُشار إليه (الخطأ 3780/150)
 *  fail  fk_target_missing          مفتاح أجنبي يشير إلى جدول/عمود غير موجود وقت إنشائه
 *  fail  index_too_long             مجموع بايتات الفهرس > 3072 (DYNAMIC) في utf8mb4
 *  fail  index_on_lob               فهرس على TEXT/BLOB/JSON بلا طول بادئة
 *  fail  lob_literal_default        قيمة افتراضية حرفية على TEXT/BLOB/JSON (مرفوضة؛ يلزم تعبير بين قوسين)
 *  fail  row_size_too_large         مجموع أعظم أحجام الأعمدة (غير LOB) > 65535 بايت
 *  fail  float_money_column         عمود مالي بنوع float/double (يجب أعداد صحيحة بوحدات صغرى أو DECIMAL)
 *  fail  fk_set_null_not_null       ON DELETE SET NULL على عمود NOT NULL
 *  warn  drop_missing_index         حذف فهرس غير مسجّل في النموذج
 *  info  redundant_index            فهرس غير فريد هو بادئة لفهرس آخر (تكلفة كتابة بلا فائدة قراءة؛ لا يُحذف بلا EXPLAIN)
 *  info  reserved_word_column       عمود باسم كلمة محجوزة في MySQL 8 (آمن مع منشئ الاستعلامات؛ يلزم تأهيله/اقتباسه في أي SQL خام)
 *  info  timestamp_summary          عدد أعمدة TIMESTAMP (نطاقها 1970..2038) وNOT NULL منها بلا قيمة افتراضية
 *  info  check_constraint           قيد CHECK (يُفرَض فعليًا من MySQL 8.0.16)
 *  info  unparsed_statement         جملة لم يفهمها المحلل (تُحصى ولا تُخفى)
 *
 * المحلل يبني نموذج المخطط بالترتيب نفسه الذي تُنفَّذ به الجمل، لذلك يلتقط أخطاء الترتيب (حذف فهرس قبل إضافة بديله) لا أخطاء الشكل فقط.
 * يحاكي أيضًا قاعدة InnoDB: إنشاء مفتاح أجنبي بلا فهرس يخلق فهرسًا ضمنيًا باسم القيد، ويُحذف ضمنيًا حين يظهر فهرس يغطي المفتاح.
 */
final class MysqlDdlLinter
{
    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const INFO = 'info';

    /** كلمات MySQL 8.0 المحجوزة التي قد تكون أسماء أعمدة شائعة (المرجع الكامل: وثائق MySQL "Keywords and Reserved Words"). */
    private const RESERVED = [
        'rank', 'key', 'keys', 'group', 'groups', 'order', 'read', 'write', 'row', 'rows', 'range', 'system', 'usage', 'condition', 'lock',
        'index', 'primary', 'unique', 'check', 'default', 'desc', 'asc', 'limit', 'match', 'option', 'schema', 'table', 'trigger', 'window',
        'lead', 'lag', 'function', 'interval', 'release', 'repeat', 'replace', 'select', 'update', 'delete', 'insert', 'values', 'when', 'where',
        'dense_rank', 'cume_dist', 'ntile', 'first_value', 'last_value', 'nth_value', 'row_number', 'percent_rank', 'empty', 'except', 'lateral',
    ];

    /** أسماء أعمدة تعني مالًا أو رصيدًا؛ لا يجوز أن تكون float/double. */
    private const MONEY_PATTERN = '/(^|_)(amount|balance|price|total|minor|bps|reward|funding|gems|cost|fee)(_|$)/';

    private const MAX_INDEX_BYTES = 3072;

    private const MAX_ROW_BYTES = 65535;

    private const MAX_IDENTIFIER = 64;

    /** علامة الاقتباس (backtick) كثابت: تبقي سلاسل الشيفرة خالية من الباكتيك الحرفي فلا يلتبس بها تدقيق E26 الساكن لـ«أوامر الصدفة». */
    private const BT = "\x60";

    /** @var array<string, array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, fks: list<array<string, mixed>>, created_in: string}> */
    private array $tables = [];

    /** @var list<array{level: string, code: string, table: string, detail: string, migration: string}> */
    private array $findings = [];

    private int $unparsed = 0;

    /**
     * @param  list<array{migration: string, sql: string}>  $statements
     * @return array{tables: array<string, mixed>, findings: list<array<string, string>>, summary: array<string, int>}
     */
    public function analyze(array $statements): array
    {
        $this->tables = [];
        $this->findings = [];
        $this->unparsed = 0;

        foreach ($statements as $statement) {
            $this->apply($statement['migration'], trim((string) $statement['sql']));
        }

        $this->finalChecks();

        $counts = ['fail' => 0, 'warn' => 0, 'info' => 0];

        foreach ($this->findings as $finding) {
            $counts[$finding['level']]++;
        }

        return [
            'tables' => $this->tables,
            'findings' => $this->findings,
            'summary' => $counts + ['tables' => count($this->tables), 'statements' => count($statements), 'unparsed' => $this->unparsed],
        ];
    }

    // ───────────────────────────── تطبيق الجمل ─────────────────────────────

    private function apply(string $migration, string $sql): void
    {
        if ($sql === '' || preg_match('/^(select|insert|update|delete|set|show)\b/i', $sql)) {
            return;
        }

        if (preg_match('/^create table\s+`(\w+)`\s*\((.*)\)\s*(default character set.*|engine.*)?$/is', $sql, $m)) {
            $this->createTable($migration, $m[1], $m[2]);

            return;
        }

        if (preg_match('/^rename table\s+`(\w+)`\s+to\s+`(\w+)`/i', $sql, $m)) {
            if (isset($this->tables[$m[1]])) {
                $this->tables[$m[2]] = $this->tables[$m[1]];
                unset($this->tables[$m[1]]);
            }

            return;
        }

        if (preg_match('/^drop table(?: if exists)?\s+`(\w+)`/i', $sql, $m)) {
            unset($this->tables[$m[1]]);

            return;
        }

        if (preg_match('/^alter table\s+`?(\w+)`?\s+add constraint\s+`?(\w+)`?\s+check\b/i', $sql, $m)) {
            $this->checkIdentifier($m[2], $m[1], $migration, 'قيد CHECK');
            $this->add(self::INFO, 'check_constraint', $m[1], "قيد CHECK {$m[2]} (يُفرَض من MySQL 8.0.16 وMariaDB 10.2.1)", $migration);

            return;
        }

        if (preg_match('/^alter table\s+`(\w+)`\s+(.*)$/is', $sql, $m)) {
            $this->alterTable($migration, $m[1], $m[2]);

            return;
        }

        $this->unparsed++;
        $this->add(self::INFO, 'unparsed_statement', '-', mb_substr($sql, 0, 90), $migration);
    }

    private function createTable(string $migration, string $table, string $body): void
    {
        $this->tables[$table] = ['columns' => [], 'indexes' => [], 'fks' => [], 'created_in' => $migration];
        $this->checkIdentifier($table, $table, $migration, 'اسم جدول');

        foreach ($this->splitTop($body) as $definition) {
            if (preg_match('/^primary key\s*\((.*)\)$/is', $definition, $pk)) {
                $this->addIndex($table, 'PRIMARY', true, $pk[1], $migration);

                continue;
            }

            $this->addColumn($migration, $table, $definition);
        }
    }

    private function alterTable(string $migration, string $table, string $rest): void
    {
        if (! isset($this->tables[$table])) {
            $this->unparsed++;
            $this->add(self::INFO, 'unparsed_statement', $table, 'ALTER على جدول غير معروف للمحلل', $migration);

            return;
        }

        foreach ($this->splitTop($rest) as $part) {
            if (preg_match('/^add\s+`(\w+)`\s+(.*)$/is', $part, $m)) {
                $this->addColumn($migration, $table, self::BT.$m[1].self::BT.' '.$m[2]);
            } elseif (preg_match('/^add\s+(unique|index|fulltext|spatial)(?:\s+(?:index|key))?\s+`(\w+)`\s*\((.*)\)/is', $part, $m)) {
                $this->addIndex($table, $m[2], strtolower($m[1]) === 'unique', $m[3], $migration);
            } elseif (preg_match('/^add primary key\s*\((.*)\)/is', $part, $m)) {
                $this->addIndex($table, 'PRIMARY', true, $m[1], $migration);
            } elseif (preg_match('/^add constraint\s+`(\w+)`\s+foreign key\s*\((.*?)\)\s*references\s+`(\w+)`\s*\((.*?)\)(.*)$/is', $part, $m)) {
                $this->addForeignKey($table, $m[1], $m[2], $m[3], $m[4], $m[5], $migration);
            } elseif (preg_match('/^drop index\s+`(\w+)`/i', $part, $m)) {
                $this->dropIndex($table, $m[1], $migration);
            } elseif (preg_match('/^drop foreign key\s+`(\w+)`/i', $part, $m)) {
                $this->tables[$table]['fks'] = array_values(array_filter($this->tables[$table]['fks'], fn ($fk) => $fk['name'] !== $m[1]));
            } elseif (preg_match('/^drop(?: column)?\s+`(\w+)`/i', $part, $m)) {
                unset($this->tables[$table]['columns'][$m[1]]);
            } elseif (preg_match('/^(?:modify|change)(?: column)?\s+`(\w+)`(?:\s+`(\w+)`)?\s+(.*)$/is', $part, $m)) {
                $name = $m[2] !== '' ? $m[2] : $m[1];
                unset($this->tables[$table]['columns'][$m[1]]);
                $this->addColumn($migration, $table, self::BT.$name.self::BT.' '.$m[3]);
            } else {
                $this->unparsed++;
                $this->add(self::INFO, 'unparsed_statement', $table, mb_substr($part, 0, 90), $migration);
            }
        }
    }

    private function addColumn(string $migration, string $table, string $definition): void
    {
        if (! preg_match('/^`(\w+)`\s+([a-z]+(?:\s*\([^)]*\))?)(\s+unsigned)?(.*)$/is', $definition, $m)) {
            $this->unparsed++;
            $this->add(self::INFO, 'unparsed_statement', $table, 'تعريف عمود: '.mb_substr($definition, 0, 80), $migration);

            return;
        }

        [, $name, $type, $unsigned, $rest] = $m + [4 => ''];
        $type = strtolower(preg_replace('/\s+/', '', $type) ?? $type);
        $isNotNull = stripos($rest, 'not null') !== false;
        $default = preg_match('/\bdefault\s+(.+?)(?:\s+(?:on update|comment|after|first|auto_increment|primary|unique|collate|character)\b|$)/is', $rest, $d) === 1 ? trim($d[1]) : null;

        $this->tables[$table]['columns'][$name] = [
            'type' => $type.($unsigned !== '' ? ' unsigned' : ''),
            'base' => $this->baseType($type),
            'null' => ! $isNotNull,
            'default' => $default,
            'auto_increment' => stripos($rest, 'auto_increment') !== false,
            'migration' => $migration,
        ];

        $this->checkIdentifier($name, $table, $migration, 'اسم عمود');

        // Laravel (id()) يولّد المفتاح الأساسي في تعريف العمود نفسه: «... auto_increment primary key».
        if (preg_match('/\bprimary key\b/i', $rest) === 1) {
            $this->addIndex($table, 'PRIMARY', true, self::BT.$name.self::BT, $migration);
        }
    }

    private function addIndex(string $table, string $name, bool $unique, string $columnList, string $migration): void
    {
        preg_match_all('/`(\w+)`(?:\((\d+)\))?/', $columnList, $cols, PREG_SET_ORDER);
        $columns = [];
        $prefix = [];

        foreach ($cols as $c) {
            $columns[] = $c[1];

            if (isset($c[2]) && $c[2] !== '') {
                $prefix[$c[1]] = (int) $c[2];
            }
        }

        $this->tables[$table]['indexes'][$name] = ['unique' => $unique, 'columns' => $columns, 'prefix' => $prefix, 'implicit' => false, 'migration' => $migration];

        if ($name !== 'PRIMARY') {
            $this->checkIdentifier($name, $table, $migration, 'اسم فهرس');
        }

        $this->dropCoveredImplicitIndexes($table);
    }

    private function addForeignKey(string $table, string $name, string $cols, string $ref, string $refCols, string $tail, string $migration): void
    {
        preg_match_all('/`(\w+)`/', $cols, $a);
        preg_match_all('/`(\w+)`/', $refCols, $b);
        $columns = $a[1];

        $onDelete = preg_match('/on delete\s+(cascade|restrict|set null|no action|set default)/i', $tail, $d) === 1 ? strtolower($d[1]) : 'no action';
        $onUpdate = preg_match('/on update\s+(cascade|restrict|set null|no action|set default)/i', $tail, $u) === 1 ? strtolower($u[1]) : 'no action';

        $this->tables[$table]['fks'][] = ['name' => $name, 'columns' => $columns, 'ref' => $ref, 'ref_columns' => $b[1], 'on_delete' => $onDelete, 'on_update' => $onUpdate, 'migration' => $migration];
        $this->checkIdentifier($name, $table, $migration, 'اسم مفتاح أجنبي');

        // InnoDB: لا فهرس يخدم المفتاح ← فهرس ضمني باسم القيد.
        if (! $this->covered($table, $columns)) {
            $this->tables[$table]['indexes'][$name] = ['unique' => false, 'columns' => $columns, 'prefix' => [], 'implicit' => true, 'migration' => $migration];
        }

        // فحوص وقت الإنشاء: المرجع موجود، الأنواع متطابقة، SET NULL يستلزم عمودًا يقبل NULL.
        foreach ($columns as $i => $column) {
            $refColumn = $b[1][$i] ?? 'id';
            $local = $this->tables[$table]['columns'][$column] ?? null;
            $remote = $this->tables[$ref]['columns'][$refColumn] ?? null;

            if ($remote === null || $local === null) {
                $this->add(self::FAIL, 'fk_target_missing', $table, "{$name}: {$column} → {$ref}.{$refColumn} غير موجود", $migration);

                continue;
            }

            if ($local['type'] !== $remote['type']) {
                $this->add(self::FAIL, 'fk_type_mismatch', $table, "{$name}: {$column} ({$local['type']}) ≠ {$ref}.{$refColumn} ({$remote['type']})", $migration);
            }

            if ($onDelete === 'set null' && ! $local['null']) {
                $this->add(self::FAIL, 'fk_set_null_not_null', $table, "{$name}: ON DELETE SET NULL على عمود NOT NULL ({$column})", $migration);
            }
        }
    }

    private function dropIndex(string $table, string $name, string $migration): void
    {
        if (! isset($this->tables[$table]['indexes'][$name])) {
            $this->add(self::WARN, 'drop_missing_index', $table, "حذف فهرس غير معروف للنموذج: {$name}", $migration);

            return;
        }

        unset($this->tables[$table]['indexes'][$name]);

        foreach ($this->tables[$table]['fks'] as $fk) {
            if (! $this->covered($table, $fk['columns'])) {
                $this->add(self::FAIL, 'index_needed_by_fk', $table, "حذف الفهرس {$name} يترك المفتاح الأجنبي {$fk['name']} بلا فهرس (MySQL خطأ 1553)", $migration);
            }
        }
    }

    // ───────────────────────────── فحوص نهائية ─────────────────────────────

    private function finalChecks(): void
    {
        $timestamps = 0;
        $strictNoDefault = 0;

        foreach ($this->tables as $table => $model) {
            $rowBytes = 0;

            foreach ($model['columns'] as $name => $column) {
                $rowBytes += $this->columnBytes($column['type'], $column['base']);

                if ($this->isLob($column['base']) && $column['default'] !== null && ! str_starts_with($column['default'], '(')) {
                    $this->add(self::FAIL, 'lob_literal_default', $table, "{$name} ({$column['base']}) له قيمة افتراضية حرفية", $model['created_in']);
                }

                if (in_array($column['base'], ['float', 'double', 'real'], true) && preg_match(self::MONEY_PATTERN, $name) === 1) {
                    $this->add(self::FAIL, 'float_money_column', $table, "{$name} ({$column['type']}) عمود مالي بنوع عشري عائم", $column['migration']);
                }

                if (in_array($name, self::RESERVED, true)) {
                    $this->add(self::INFO, 'reserved_word_column', $table, "العمود {$name} كلمة محجوزة في MySQL 8: اقتبسه أو أهِّله باسم الجدول في أي SQL خام", $column['migration']);
                }

                if ($column['base'] === 'timestamp') {
                    $timestamps++;

                    if (! $column['null'] && $column['default'] === null) {
                        $strictNoDefault++;
                    }
                }
            }

            if ($rowBytes > self::MAX_ROW_BYTES) {
                $this->add(self::FAIL, 'row_size_too_large', $table, "مجموع أحجام الأعمدة المقدَّر {$rowBytes} بايت > ".self::MAX_ROW_BYTES, $model['created_in']);
            }

            foreach ($model['indexes'] as $indexName => $index) {
                $bytes = 0;

                foreach ($index['columns'] as $columnName) {
                    $column = $model['columns'][$columnName] ?? null;

                    if ($column === null) {
                        continue;
                    }

                    $prefix = $index['prefix'][$columnName] ?? null;

                    if ($this->isLob($column['base']) && $prefix === null) {
                        $this->add(self::FAIL, 'index_on_lob', $table, "الفهرس {$indexName} على {$columnName} ({$column['base']}) بلا طول بادئة", $index['migration']);

                        continue;
                    }

                    $bytes += $prefix !== null ? $prefix * 4 : $this->indexBytes($column['type'], $column['base']);
                }

                if ($bytes > self::MAX_INDEX_BYTES) {
                    $this->add(self::FAIL, 'index_too_long', $table, "الفهرس {$indexName} يبلغ {$bytes} بايت > ".self::MAX_INDEX_BYTES, $index['migration']);
                }
            }

            $this->redundantIndexes($table, $model['indexes']);
        }

        $this->add(self::INFO, 'timestamp_summary', '-', "{$timestamps} عمود TIMESTAMP (نطاق MySQL: 1970-01-01..2038-01-19)، منها {$strictNoDefault} NOT NULL بلا قيمة افتراضية (يتطلب explicit_defaults_for_timestamp=ON: افتراضي MySQL 8)", '-');
    }

    /** @param array<string, array<string, mixed>> $indexes */
    private function redundantIndexes(string $table, array $indexes): void
    {
        foreach ($indexes as $name => $index) {
            if ($index['unique'] || $index['implicit'] || $name === 'PRIMARY') {
                continue;
            }

            foreach ($indexes as $otherName => $other) {
                if ($otherName === $name) {
                    continue;
                }

                $same = $index['columns'] === $other['columns'];
                $prefixOf = ! $same && array_slice($other['columns'], 0, count($index['columns'])) === $index['columns'];

                if ($prefixOf || ($same && strcmp($name, (string) $otherName) > 0)) {
                    $this->add(self::INFO, 'redundant_index', $table, "{$name}(".implode(',', $index['columns']).") مغطّى بـ{$otherName}(".implode(',', $other['columns']).')', (string) $index['migration']);

                    break;
                }
            }
        }
    }

    // ───────────────────────────── أدوات ─────────────────────────────

    private function covered(string $table, array $columns): bool
    {
        foreach ($this->tables[$table]['indexes'] ?? [] as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function dropCoveredImplicitIndexes(string $table): void
    {
        foreach ($this->tables[$table]['indexes'] as $name => $index) {
            if (! $index['implicit']) {
                continue;
            }

            foreach ($this->tables[$table]['indexes'] as $otherName => $other) {
                if ($otherName !== $name && ! $other['implicit'] && array_slice($other['columns'], 0, count($index['columns'])) === $index['columns']) {
                    unset($this->tables[$table]['indexes'][$name]);

                    break;
                }
            }
        }
    }

    private function checkIdentifier(string $identifier, string $table, string $migration, string $what): void
    {
        if (strlen($identifier) > self::MAX_IDENTIFIER) {
            $this->add(self::FAIL, 'identifier_too_long', $table, "{$what} يبلغ ".strlen($identifier).' محرفًا > '.self::MAX_IDENTIFIER.": {$identifier}", $migration);
        }
    }

    private function baseType(string $type): string
    {
        return preg_match('/^([a-z]+)/', $type, $m) === 1 ? $m[1] : $type;
    }

    private function isLob(string $base): bool
    {
        return in_array($base, ['text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'json'], true);
    }

    private function indexBytes(string $type, string $base): int
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1] * 4;
        }

        return $this->fixedBytes($base);
    }

    private function columnBytes(string $type, string $base): int
    {
        if (preg_match('/^varchar\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1] * 4 + ((int) $m[1] > 255 ? 2 : 1);
        }

        if (preg_match('/^char\((\d+)\)/', $type, $m) === 1) {
            return (int) $m[1] * 4;
        }

        return $this->isLob($base) ? 12 : $this->fixedBytes($base);
    }

    private function fixedBytes(string $base): int
    {
        return match ($base) {
            'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int', 'integer', 'float' => 4, 'bigint', 'double', 'real', 'datetime' => 8,
            'timestamp' => 4, 'date' => 3, 'time' => 3, 'year' => 1, 'enum', 'set' => 2, 'decimal', 'numeric' => 16, default => 8,
        };
    }

    /** @return list<string> */
    private function splitTop(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quote !== null) {
                $current .= $ch;

                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $current .= $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function add(string $level, string $code, string $table, string $detail, string $migration): void
    {
        $this->findings[] = ['level' => $level, 'code' => $code, 'table' => $table, 'detail' => $detail, 'migration' => $migration];
    }
}

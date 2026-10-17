<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\Schema;

/**
 * E31: لقطة «منطقية» للمخطط قابلة للمقارنة بين محركين مختلفين (SQLite وMySQL) دون اشتراط تطابق التمثيل الفيزيائي.
 *
 * الشكل الموحّد لكل جدول:
 *  columns: [اسم => [class, nullable, unsigned, type]]   (class: int|bool|decimal|float|string|text|datetime|date|time|binary|other)
 *  primary: list<string>
 *  unique:  list<list<string>>      (مجموعات أعمدة فريدة غير المفتاح الأساسي)
 *  indexes: list<list<string>>      (فهارس غير فريدة صريحة؛ الضمنية للمفاتيح الأجنبية لا تُعدّ)
 *  fks:     list<{columns, ref, ref_columns, on_delete}>
 *
 * مصدران: اتصال حيّ (Laravel Schema builder، يعمل على كل السائقين) أو نموذج MysqlDdlLinter (بلا خادم).
 */
final class SchemaSnapshot
{
    /** @return array<string, array<string, mixed>> */
    public function fromConnection(string $connection): array
    {
        $schema = Schema::connection($connection);
        $snapshot = [];

        foreach ($schema->getTables() as $table) {
            $name = (string) $table['name'];

            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $columns = [];

            foreach ($schema->getColumns($name) as $column) {
                $type = strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
                $columns[(string) $column['name']] = [
                    'class' => self::typeClass($type !== '' ? $type : strtolower((string) $column['type_name'])),
                    'nullable' => (bool) $column['nullable'],
                    'unsigned' => str_contains($type, 'unsigned'),
                    'type' => $type,
                ];
            }

            $primary = [];
            $unique = [];
            $indexes = [];

            foreach ($schema->getIndexes($name) as $index) {
                $cols = array_values(array_map('strval', $index['columns']));

                if (! empty($index['primary'])) {
                    $primary = $cols;
                } elseif (! empty($index['unique'])) {
                    $unique[] = $cols;
                } else {
                    $indexes[] = $cols;
                }
            }

            $fks = [];

            foreach ($schema->getForeignKeys($name) as $fk) {
                $fks[] = [
                    'columns' => array_values(array_map('strval', $fk['columns'])),
                    'ref' => (string) $fk['foreign_table'],
                    'ref_columns' => array_values(array_map('strval', $fk['foreign_columns'])),
                    'on_delete' => self::deleteRule((string) ($fk['on_delete'] ?? '')),
                ];
            }

            $snapshot[$name] = compact('columns', 'primary', 'unique', 'indexes', 'fks');
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables  مخرج MysqlDdlLinter::analyze()['tables']
     * @return array<string, array<string, mixed>>
     */
    public function fromLinterModel(array $tables): array
    {
        $snapshot = [];

        foreach ($tables as $name => $model) {
            $columns = [];

            foreach ($model['columns'] as $column => $meta) {
                $columns[$column] = [
                    'class' => self::typeClass((string) $meta['type']),
                    'nullable' => (bool) $meta['null'],
                    'unsigned' => str_contains((string) $meta['type'], 'unsigned'),
                    'type' => (string) $meta['type'],
                ];
            }

            $primary = [];
            $unique = [];
            $indexes = [];

            foreach ($model['indexes'] as $indexName => $index) {
                if ($indexName === 'PRIMARY') {
                    $primary = $index['columns'];
                } elseif ($index['unique']) {
                    $unique[] = $index['columns'];
                } elseif (! $index['implicit']) {
                    $indexes[] = $index['columns'];
                }
            }

            $fks = [];

            foreach ($model['fks'] as $fk) {
                $fks[] = ['columns' => $fk['columns'], 'ref' => $fk['ref'], 'ref_columns' => $fk['ref_columns'], 'on_delete' => self::deleteRule($fk['on_delete'])];
            }

            $snapshot[$name] = compact('columns', 'primary', 'unique', 'indexes', 'fks');
        }

        ksort($snapshot);

        return $snapshot;
    }

    /** يحوّل نوع العمود (من أي سائق) إلى فئة منطقية. SQLite ديناميكية الأنواع: نقارن النية لا الشكل الفيزيائي. */
    public static function typeClass(string $type): string
    {
        $type = strtolower(trim($type));

        if ($type === 'tinyint(1)' || $type === 'boolean' || $type === 'bool') {
            return 'bool';
        }

        $base = preg_match('/^([a-z]+)/', $type, $m) === 1 ? $m[1] : $type;

        return match (true) {
            in_array($base, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year'], true) => 'int',
            in_array($base, ['decimal', 'numeric'], true) => 'decimal',
            in_array($base, ['float', 'double', 'real'], true) => 'float',
            in_array($base, ['varchar', 'char', 'enum', 'set', 'string', 'nvarchar', 'nchar', 'uuid', 'ulid'], true) => 'string',
            in_array($base, ['text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb'], true) => 'text',
            in_array($base, ['datetime', 'timestamp', 'datetimetz', 'timestamptz'], true) => 'datetime',
            $base === 'date' => 'date',
            $base === 'time' => 'time',
            in_array($base, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true) => 'binary',
            default => 'other:'.$base,
        };
    }

    /** NO ACTION وRESTRICT متكافئان في InnoDB؛ نوحّد الصيغة (حروف صغيرة). */
    public static function deleteRule(string $rule): string
    {
        $rule = strtolower(trim($rule));

        return match ($rule) {
            '', 'no action', 'restrict' => 'restrict',
            default => $rule,
        };
    }
}

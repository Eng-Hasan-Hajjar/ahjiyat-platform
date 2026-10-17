<?php

namespace App\Services\Database;

/**
 * E31: يقارن لقطتين منطقيتين (SchemaSnapshot) ويُرجع فروقًا مصنّفة. قراءة فقط ولا يلمس أي قاعدة.
 *
 * «expected» = ما يجب أن يكون (نموذج MySQL الناتج من الترحيلات، أو قاعدة المصدر)، و«actual» = ما هو موجود (القاعدة المفحوصة).
 * المستويات:
 *  material  فشل جوهري يوقف النقل/النشر (يجعل db:parity يخرج بـ1): جدول/عمود/قيد فريد/مفتاح أجنبي ناقص، اختلاف فئة نوع، عمود صار NOT NULL.
 *  warn      فرق يستحق نظرة ولا يمنع (فهرس غير فريد ناقص، عمود/جدول زائد، NOT NULL أصبح يقبل NULL، اختلاف string/text).
 *  info      فروق متوقعة بين المحركات (unsigned غير مفروض على SQLite، فهرس تغطيه فهارس أخرى...).
 */
final class SchemaParity
{
    public const MATERIAL = 'material';

    public const WARN = 'warn';

    public const INFO = 'info';

    /**
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @param  list<string>  $ignoreTables
     * @return array{findings: list<array{level: string, code: string, table: string, detail: string}>, summary: array<string, int>}
     */
    public function compare(array $expected, array $actual, array $ignoreTables = ['migrations']): array
    {
        $findings = [];
        $add = function (string $level, string $code, string $table, string $detail) use (&$findings): void {
            $findings[] = ['level' => $level, 'code' => $code, 'table' => $table, 'detail' => $detail];
        };

        foreach ($ignoreTables as $ignored) {
            unset($expected[$ignored], $actual[$ignored]);
        }

        foreach ($expected as $table => $exp) {
            if (! isset($actual[$table])) {
                $add(self::MATERIAL, 'table_missing', $table, 'الجدول غير موجود في القاعدة المفحوصة');

                continue;
            }

            $this->compareTable($table, $exp, $actual[$table], $add);
        }

        foreach (array_keys($actual) as $table) {
            if (! isset($expected[$table])) {
                $add(self::WARN, 'table_extra', $table, 'جدول زائد غير موجود في المرجع');
            }
        }

        $counts = [self::MATERIAL => 0, self::WARN => 0, self::INFO => 0];

        foreach ($findings as $finding) {
            $counts[$finding['level']]++;
        }

        return ['findings' => $findings, 'summary' => $counts + ['tables_expected' => count($expected), 'tables_actual' => count($actual)]];
    }

    /**
     * @param  array<string, mixed>  $exp
     * @param  array<string, mixed>  $act
     */
    private function compareTable(string $table, array $exp, array $act, \Closure $add): void
    {
        $unsignedLost = 0;

        foreach ($exp['columns'] as $name => $col) {
            $a = $act['columns'][$name] ?? null;

            if ($a === null) {
                $add(self::MATERIAL, 'column_missing', $table, "العمود {$name} غير موجود");

                continue;
            }

            if ($col['class'] !== $a['class']) {
                $level = in_array([$col['class'], $a['class']], [['string', 'text'], ['text', 'string']], true) ? self::WARN : self::MATERIAL;
                $add($level, 'type_class_mismatch', $table, "العمود {$name}: المتوقع {$col['class']} ({$col['type']}) والموجود {$a['class']} ({$a['type']})");
            }

            if ($col['nullable'] === false && $a['nullable'] === true) {
                $add(self::WARN, 'nullable_relaxed', $table, "العمود {$name} يقبل NULL بينما المتوقع NOT NULL");
            } elseif ($col['nullable'] === true && $a['nullable'] === false) {
                $add(self::MATERIAL, 'nullable_tightened', $table, "العمود {$name} صار NOT NULL بينما المتوقع يقبل NULL (النقل/الكتابة قد يفشل)");
            }

            if ($col['unsigned'] && ! $a['unsigned']) {
                $unsignedLost++;
            }
        }

        foreach (array_keys($act['columns']) as $name) {
            if (! isset($exp['columns'][$name])) {
                $add(self::WARN, 'column_extra', $table, "عمود زائد غير موجود في المرجع: {$name}");
            }
        }

        if ($unsignedLost > 0) {
            $add(self::INFO, 'unsigned_not_enforced', $table, "{$unsignedLost} عمود/أعمدة UNSIGNED في المرجع لا يفرضها المحرك المفحوص (متوقع على SQLite)");
        }

        if ($exp['primary'] !== [] && $act['primary'] !== [] && $exp['primary'] !== $act['primary']) {
            $add(self::MATERIAL, 'primary_mismatch', $table, 'المفتاح الأساسي مختلف: ('.implode(',', $exp['primary']).') مقابل ('.implode(',', $act['primary']).')');
        } elseif ($exp['primary'] !== [] && $act['primary'] === []) {
            $add(self::WARN, 'primary_unreported', $table, 'المحرك المفحوص لم يُبلغ عن مفتاح أساسي');
        }

        $actualUnique = array_merge($act['unique'], $act['primary'] !== [] ? [$act['primary']] : []);

        foreach ($exp['unique'] as $columns) {
            if (! in_array($columns, $actualUnique, true)) {
                $add(self::MATERIAL, 'unique_missing', $table, 'قيد فريد ناقص على ('.implode(',', $columns).')');
            }
        }

        $allActual = array_merge($act['indexes'], $act['unique'], $act['primary'] !== [] ? [$act['primary']] : []);

        foreach ($exp['indexes'] as $columns) {
            if (! $this->covered($columns, $allActual)) {
                $add(self::WARN, 'index_missing', $table, 'فهرس ناقص على ('.implode(',', $columns).')');
            }
        }

        $actualFks = [];

        foreach ($act['fks'] as $fk) {
            $actualFks[implode(',', $fk['columns']).'>'.$fk['ref'].'('.implode(',', $fk['ref_columns']).')'] = $fk;
        }

        foreach ($exp['fks'] as $fk) {
            $key = implode(',', $fk['columns']).'>'.$fk['ref'].'('.implode(',', $fk['ref_columns']).')';

            if (! isset($actualFks[$key])) {
                $add(self::MATERIAL, 'fk_missing', $table, "مفتاح أجنبي ناقص: {$key}");
            } elseif ($actualFks[$key]['on_delete'] !== $fk['on_delete']) {
                $add(self::MATERIAL, 'fk_delete_rule_mismatch', $table, "قاعدة الحذف للمفتاح {$key}: المتوقع {$fk['on_delete']} والموجود {$actualFks[$key]['on_delete']}");
            }
        }
    }

    /**
     * فهرس «مغطّى» إن وُجد فهرس فعلي يبدأ بالأعمدة نفسها (بادئة أيسر).
     *
     * @param  list<string>  $columns
     * @param  list<list<string>>  $actualIndexes
     */
    private function covered(array $columns, array $actualIndexes): bool
    {
        foreach ($actualIndexes as $index) {
            if (array_slice($index, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E31: حقائق الخادم الفعلية للقاعدة المتصلة (قراءة فقط). تغذّي production:check وdb:parity --runtime وأمر النقل.
 *
 * لا تُرجع أبدًا مضيفًا أو منفذًا أو اسم مستخدم أو كلمة مرور أو اسم قاعدة: أرقام ونصوص إعدادات الخادم فقط (النسخة، sql_mode، الترميز...).
 * على SQLite (أو أي سائق آخر) تُرجع الحقائق الأساسية فقط بلا استعلامات خاصة بـMySQL.
 */
class DatabaseRuntimeInspector
{
    private const VARIABLES = [
        'version', 'sql_mode', 'character_set_database', 'collation_database', 'character_set_server', 'transaction_isolation', 'tx_isolation',
        'time_zone', 'system_time_zone', 'max_allowed_packet', 'explicit_defaults_for_timestamp', 'foreign_key_checks', 'innodb_lock_wait_timeout',
        'default_storage_engine', 'lower_case_table_names',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return ['driver' => $driver, 'reachable' => $this->reachable($db), 'flavor' => $driver];
        }

        $facts = ['driver' => $driver, 'reachable' => true];

        try {
            $placeholders = implode(',', array_fill(0, count(self::VARIABLES), '?'));
            $rows = $db->select("show variables where Variable_name in ({$placeholders})", self::VARIABLES);
            $vars = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $vars[strtolower((string) ($row['Variable_name'] ?? $row['variable_name'] ?? ''))] = (string) ($row['Value'] ?? $row['value'] ?? '');
            }
        } catch (Throwable) {
            return ['driver' => $driver, 'reachable' => false, 'flavor' => $driver];
        }

        $version = $vars['version'] ?? '';
        $flavor = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m);

        $facts += [
            'flavor' => $flavor,
            'version_number' => isset($m[0]) ? $m[0] : null,
            'version_major_minor' => isset($m[1]) ? $m[1].'.'.$m[2] : null,
            'sql_mode' => $vars['sql_mode'] ?? '',
            'strict' => (bool) preg_match('/STRICT_(TRANS|ALL)_TABLES/', $vars['sql_mode'] ?? ''),
            'only_full_group_by' => str_contains($vars['sql_mode'] ?? '', 'ONLY_FULL_GROUP_BY'),
            'db_charset' => $vars['character_set_database'] ?? null,
            'db_collation' => $vars['collation_database'] ?? null,
            'isolation' => $vars['transaction_isolation'] ?? $vars['tx_isolation'] ?? null,
            'time_zone' => $vars['time_zone'] ?? null,
            'max_allowed_packet' => isset($vars['max_allowed_packet']) ? (int) $vars['max_allowed_packet'] : null,
            'explicit_defaults_for_timestamp' => isset($vars['explicit_defaults_for_timestamp']) ? in_array(strtoupper($vars['explicit_defaults_for_timestamp']), ['ON', '1'], true) : null,
            'lock_wait_timeout' => isset($vars['innodb_lock_wait_timeout']) ? (int) $vars['innodb_lock_wait_timeout'] : null,
            'default_engine' => $vars['default_storage_engine'] ?? null,
            'lower_case_table_names' => isset($vars['lower_case_table_names']) ? (int) $vars['lower_case_table_names'] : null,
        ];

        try {
            $summary = $db->selectOne(
                "select count(*) as tables_total,
                        sum(case when engine <> 'InnoDB' then 1 else 0 end) as non_innodb,
                        sum(case when table_collation not like 'utf8mb4%' then 1 else 0 end) as non_utf8mb4
                   from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"
            );
            $facts += [
                'tables_total' => (int) ($summary->tables_total ?? 0),
                'non_innodb_tables' => (int) ($summary->non_innodb ?? 0),
                'non_utf8mb4_tables' => (int) ($summary->non_utf8mb4 ?? 0),
            ];
        } catch (Throwable) {
            // information_schema غير متاح لهذا المستخدم: نترك الحقائق الجدولية غير معروفة (لا نخمّن).
        }

        return $facts;
    }

    private function reachable(\Illuminate\Database\Connection $db): bool
    {
        try {
            $db->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

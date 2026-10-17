<?php

namespace App\Services\Database;

/**
 * E31: يحوّل حقائق الخادم (DatabaseRuntimeInspector) إلى نتائج فحص. دالة نقية بلا I/O، لذا تُختبر بلا قاعدة MySQL.
 *
 * عقد النسخ المدعومة (docs/mysql-production-e31.md):
 *  - MySQL ≥ 8.0.16 (أول نسخة تفرض قيود CHECK؛ الاستهداف: 8.0 LTS و8.4 LTS). هذا هو المحرك المُتحقَّق منه.
 *  - MariaDB ≥ 10.6: «أفضل جهد»، لم يُتحقَّق منه في E31 (يلزم explicit_defaults_for_timestamp=ON قبل 10.10).
 *
 * المستوى: pass | warn | fail | prodfail (prodfail = FAIL بالإنتاج وWARN خارجه، مثل ProductionChecker::prodFail).
 */
final class DatabaseFactsEvaluator
{
    public const MYSQL_MIN = '8.0.16';

    public const MARIADB_MIN = '10.6.0';

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array{level: string, key: string, message: string}>
     */
    public function evaluate(array $facts): array
    {
        $driver = (string) ($facts['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [];
        }

        $out = [];
        $flavor = (string) ($facts['flavor'] ?? $driver);
        $number = $facts['version_number'] ?? null;
        $label = $flavor === 'mariadb' ? 'MariaDB' : 'MySQL';

        if ($number === null) {
            $out[] = $this->r('warn', 'DB_VERSION', 'تعذّر تحديد نسخة خادم القاعدة');
        } elseif ($flavor === 'mysql') {
            $out[] = version_compare((string) $number, self::MYSQL_MIN, '>=')
                ? $this->r('pass', 'DB_VERSION', "{$label} {$facts['version_major_minor']} ضمن النسخ المدعومة (الحد الأدنى ".self::MYSQL_MIN.')')
                : $this->r('prodfail', 'DB_VERSION', "نسخة {$label} أقدم من الحد الأدنى المدعوم ".self::MYSQL_MIN.' (قيود CHECK لا تُفرض قبلها)');
        } else {
            if (version_compare((string) $number, self::MARIADB_MIN, '<')) {
                $out[] = $this->r('prodfail', 'DB_VERSION', 'نسخة MariaDB أقدم من الحد الأدنى '.self::MARIADB_MIN);
            } else {
                $out[] = $this->r('warn', 'DB_VERSION', 'MariaDB مدعومة «بأفضل جهد» فقط ولم يُتحقَّق منها في E31 - الموصى به MySQL ≥ '.self::MYSQL_MIN);
            }
        }

        if (array_key_exists('strict', $facts)) {
            $out[] = $facts['strict']
                ? $this->r('pass', 'DB_STRICT', 'الوضع الصارم (STRICT_TRANS_TABLES) مفعّل: لا اقتطاع صامت للبيانات')
                : $this->r('prodfail', 'DB_STRICT', 'الوضع الصارم غير مفعّل في جلسة التطبيق (database.connections.*.strict=true مطلوب): خطر اقتطاع بيانات صامت');
        }

        $charset = (string) ($facts['db_charset'] ?? '');

        if ($charset !== '') {
            $out[] = str_starts_with($charset, 'utf8mb4')
                ? $this->r('pass', 'DB_CHARSET', 'ترميز القاعدة utf8mb4 (عربي + إيموجي سليم)')
                : $this->r('prodfail', 'DB_CHARSET', 'ترميز القاعدة ليس utf8mb4: النص العربي/الإيموجي قد يتلف - أعد إنشاء القاعدة بـCHARACTER SET utf8mb4');
        }

        if (isset($facts['non_innodb_tables'])) {
            $out[] = (int) $facts['non_innodb_tables'] === 0
                ? $this->r('pass', 'DB_ENGINE', 'كل الجداول InnoDB (معاملات + مفاتيح أجنبية)')
                : $this->r('prodfail', 'DB_ENGINE', (int) $facts['non_innodb_tables'].' جدول/جداول ليست InnoDB: لا معاملات ولا مفاتيح أجنبية فعلية عليها');
        }

        if (isset($facts['non_utf8mb4_tables']) && (int) $facts['non_utf8mb4_tables'] > 0) {
            $out[] = $this->r('warn', 'DB_COLLATION', (int) $facts['non_utf8mb4_tables'].' جدول/جداول بتجميع غير utf8mb4');
        }

        if (($facts['explicit_defaults_for_timestamp'] ?? true) === false) {
            $out[] = $this->r('warn', 'DB_TIMESTAMP', 'explicit_defaults_for_timestamp=OFF: أعمدة TIMESTAMP NOT NULL تتصرف بقيم افتراضية ضمنية (الافتراضي ON في MySQL 8)');
        }

        $tz = $facts['time_zone'] ?? null;

        if (is_string($tz) && ! in_array(strtoupper($tz), ['+00:00', 'UTC'], true)) {
            $out[] = $this->r('warn', 'DB_TIMEZONE', 'منطقة جلسة القاعدة ليست UTC (+00:00): اضبط DB_TIMEZONE=+00:00 كي تتطابق مع app.timezone');
        }

        $packet = $facts['max_allowed_packet'] ?? null;

        if (is_int($packet) && $packet < 4 * 1024 * 1024) {
            $out[] = $this->r('warn', 'DB_PACKET', 'max_allowed_packet أقل من 4MB: صفوف كبيرة (محتوى مبدعين/دردشة) قد تُرفض');
        }

        return $out;
    }

    /** @return array{level: string, key: string, message: string} */
    private function r(string $level, string $key, string $message): array
    {
        return ['level' => $level, 'key' => $key, 'message' => $message];
    }
}

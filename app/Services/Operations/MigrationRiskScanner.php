<?php

namespace App\Services\Operations;

/**
 * E25 - ماسح مخاطر ترحيل (heuristic ساكن، للقراءة فقط) لتطبيق سياسة docs/release-engineering.md على الترحيلات المعلّقة قبل النشر.
 * يفحص جسم up() فقط (down() يحتوي حذفًا بطبيعته). نتيجته تحذير للمراجعة البشرية وليس حكمًا: ترحيل «خطر» قد يكون صحيحًا بعد مراجعة وقياس على staging.
 */
final class MigrationRiskScanner
{
    /** رمز الخطر => [نمط، شرح عربي قصير] */
    private const RULES = [
        'drop_column' => ['/->(dropColumn|dropColumns)\s*\(/', 'حذف عمود (غير متوافق مع الإصدار السابق للكود)'],
        'rename_column' => ['/->(renameColumn|renameIndex)\s*\(|Schema::rename\s*\(/', 'إعادة تسمية عمود/جدول (يكسر الإصدار السابق فورًا)'],
        'change_type' => ['/->change\s*\(\s*\)/', 'تغيير نوع/خصائص عمود (قد يعيد بناء الجدول ويقفله)'],
        'drop_table' => ['/Schema::(dropIfExists|drop|dropAllTables|dropAllViews)\s*\(/', 'حذف جدول'],
        'drop_constraint' => ['/->(dropIndex|dropUnique|dropPrimary|dropForeign|dropFullText|dropSpatialIndex)\s*\(/', 'حذف فهرس/قيد'],
        'add_index' => ['/->(index|unique|fullText|spatialIndex)\s*\(/', 'إضافة فهرس (قِس زمنه على نسخة staging بحجم الإنتاج؛ قد يقفل الجدول)'],
        'bulk_data' => ['/DB::table\([^;]*->(update|delete|truncate)\s*\(|DB::(statement|unprepared)\s*\(\s*[\'"]\s*(UPDATE|DELETE|TRUNCATE)\b/is', 'تعديل/حذف بيانات داخل الترحيل (استعمل أمر Artisan مع chunkById بدلًا منه)'],
        'raw_ddl' => ['/DB::(statement|unprepared)\s*\(\s*[\'"]\s*(ALTER|DROP|CREATE\s+(UNIQUE\s+)?INDEX)\b/i', 'تعليمة DDL خام (تحقق من القفل والتوافق مع الإصدار السابق)'],
        'eloquent_in_migration' => ['/\b(?!Schema\b|DB\b)[A-Z][A-Za-z]+::(query|where|create|update|delete|truncate|chunk|each|all)\b/', 'استعمال نموذج Eloquent داخل الترحيل (يتغير مع الكود؛ فضّل DB::table أو أمرًا منفصلًا)'],
    ];

    /** @return list<string> رموز المخاطر الموجودة بجسم up() */
    public function scan(string $source): array
    {
        $up = $this->upBody($source);
        $found = [];

        foreach (self::RULES as $code => [$pattern]) {
            if (preg_match($pattern, $up) === 1) {
                $found[] = $code;
            }
        }

        return $found;
    }

    public function describe(string $code): string
    {
        return self::RULES[$code][1] ?? $code;
    }

    /** رموز تعني تغييرًا قد يكسر الكود الحالي أو يخسر بيانات (أشدّ من مجرد «قِس الزمن»). */
    public function isBreaking(string $code): bool
    {
        return in_array($code, ['drop_column', 'rename_column', 'change_type', 'drop_table', 'bulk_data'], true);
    }

    private function upBody(string $source): string
    {
        $start = preg_match('/function\s+up\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : null;

        if ($start === null) {
            return '';
        }

        $end = preg_match('/function\s+down\s*\(/', $source, $d, PREG_OFFSET_CAPTURE, $start) === 1 ? $d[0][1] : strlen($source);

        return substr($source, $start, $end - $start);
    }
}

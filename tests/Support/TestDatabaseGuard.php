<?php

namespace Tests\Support;

use RuntimeException;

/**
 * حاجز أمان: اختبارات RefreshDatabase تبدأ بـmigrate:fresh فتمسح القاعدة التي
 * تعمل عليها. مع config:cache يتجاهل Laravel إعدادات phpunit.xml ويستخدم قاعدة
 * .env الفعلية، فتُمسَح قاعدة التطوير. هذا الصنف يرفض التشغيل قبل أي ترحيل
 * ما لم تكن القاعدة المحلولة قاعدة اختبار صريحة. لا يحذف شيئًا أبدًا.
 */
final class TestDatabaseGuard
{
    public static function isAllowed(string $driver, ?string $database): bool
    {
        if ($database === null || trim($database) === '') {
            return false;
        }

        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return true;
            }

            return strtolower(basename(str_replace('\\', '/', $database))) === 'testing.sqlite';
        }

        // مخدّمات أخرى: اسم القاعدة نفسه يجب أن يدل صراحةً على الاختبار (xxx_test / xxx_testing / testing).
        return (bool) preg_match('/(^|_)(test|testing)$/i', $database);
    }

    public static function assertSafe(string $driver, ?string $database, bool $configCached): void
    {
        if ($configCached) {
            throw new RuntimeException(
                'إعدادات التطبيق مخزَّنة (config:cache): الاختبارات ستعمل على قاعدتك الفعلية وتمسحها. نفّذ: php artisan config:clear'
            );
        }

        if (! self::isAllowed($driver, $database)) {
            throw new RuntimeException(
                'قاعدة بيانات الاختبار غير آمنة ('.($database ?: 'فارغة').'): يُسمَح فقط بـdatabase/testing.sqlite أو :memory: '
                .'أو قاعدة اسمها ينتهي بـ_test/_testing. لن يُنفَّذ أي ترحيل.'
            );
        }
    }
}

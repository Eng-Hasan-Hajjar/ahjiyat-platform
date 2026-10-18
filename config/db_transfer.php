<?php

/*
|--------------------------------------------------------------------------
| E31 - نقل البيانات من SQLite إلى MySQL (php artisan db:transfer-sqlite-to-mysql)
|--------------------------------------------------------------------------
| أداة محلية/تجريبية لمرة واحدة، تُرفض عند APP_ENV=production. كل القيم اختيارية؛ الأمر يعمل بلا أي منها (يستعمل اتصال mysql الحالي).
| لا أسرار هنا: كلمات المرور تأتي من البيئة فقط ولا تُطبع أبدًا.
|
| المصدر: ملف SQLite يُمرَّر بـ--source أو MIGRATION_SQLITE_DATABASE (مسار مطلق أو نسبي لجذر المشروع). الافتراضي: قاعدة اتصال sqlite الحالية.
|        تنبيه: إن بدّلت DB_CONNECTION=mysql وبقي DB_DATABASE باسم قاعدة MySQL فلن يجد الأمر الملف - مرِّره صراحةً.
| الهدف: اتصال mysql (DB_*) أو أي اتصال مسمّى mysql/mariadb عبر --target. تجاوزات اختيارية بلا تكرار الإعداد: MIGRATION_MYSQL_{HOST,PORT,DATABASE,USERNAME,PASSWORD}.
*/

return [

    'target_connection' => env('MIGRATION_TARGET_CONNECTION', 'mysql'),

    'sqlite_database' => env('MIGRATION_SQLITE_DATABASE'),

    /*
    | تجاوزات إعداد الهدف (تُهمَل القيمة الفارغة). تسمح ببقاء DB_* في .env على SQLite أثناء مرحلة النقل.
    */
    'target_overrides' => array_filter([
        'host' => env('MIGRATION_MYSQL_HOST'),
        'port' => env('MIGRATION_MYSQL_PORT'),
        'database' => env('MIGRATION_MYSQL_DATABASE'),
        'username' => env('MIGRATION_MYSQL_USERNAME'),
        'password' => env('MIGRATION_MYSQL_PASSWORD'),
    ], static fn ($value) => $value !== null && $value !== ''),

    /*
    | حجم الدفعة (صفوف لكل INSERT). يُقيَّد داخل [500, 2000]؛ ويُخفَّض تلقائيًا كي لا يتجاوز عدد المتغيرات 60000 (حد MySQL 65535)
    | ولا حجم الدفعة التقريبي max_batch_bytes (حماية من max_allowed_packet عند صفوف كبيرة).
    */
    'batch_size' => (int) env('MIGRATION_BATCH_SIZE', 1000),
    'max_batch_bytes' => (int) env('MIGRATION_MAX_BATCH_BYTES', 1_000_000),

    /*
    | الجداول المؤقتة التشغيلية: لا تُنقل (جلسات المستخدمين تُنهى عند التحويل؛ الكاش يُعاد بناؤه؛ طابور قاعدة البيانات الحيّ لا يُنقل
    | كي لا تُنفَّذ مهام قديمة مرتين؛ failed_jobs تُترك للنظافة؛ رموز إعادة تعيين كلمة المرور قصيرة العمر).
    | جدول migrations لا يُنقل أبدًا (هدفه يُبنى بـphp artisan migrate).
    */
    'exclude' => [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ],

    /*
    | جداول تُملأ بصفوف مرجعية بواسطة الترحيلات نفسها (currencies عبر seed_system_currencies، supporter_tiers عبر create_sponsorship_tables).
    | الهدف بعد migrate لا يكون «فارغًا» فيها. الأمر يقبل ذلك فقط إذا كان كل صف في الهدف موجودًا في المصدر بالمفتاح الطبيعي نفسه وبالمعرّف نفسه،
    | ثم يحذف تلك الصفوف المرجعية ويستبدلها بصفوف المصدر (المصدر هو الحقيقة). أي تعارض معرّف يوقف الأمر. الصيغة: جدول => عمود المفتاح الطبيعي.
    */
    'reference_tables' => [
        'currencies' => 'internal_key',
        'supporter_tiers' => 'slug',
    ],

    /*
    | فحوص المال التي تُقارَن مجاميعها بالأعداد الصحيحة بين المصدر والهدف (وحدات صغرى؛ لا float). جدول => أعمدة.
    */
    'financial_sums' => [
        'wallets' => ['pending_balance', 'available_balance', 'lifetime_earned', 'lifetime_redeemed'],
        'currency_transactions' => ['amount'],
        'redemption_requests' => ['gems_amount'],
        'store_purchases' => ['price_amount'],
        'currency_packs' => ['base_amount', 'bonus_amount', 'price_minor'],
        'sponsorship_contributions' => ['original_amount_minor', 'accounting_amount_minor'],
        'sponsorship_payments' => ['amount_minor', 'accounting_amount_minor'],
        'sponsorship_reward_bonuses' => ['base_amount', 'calculated_bonus_amount', 'bonus_amount', 'final_amount', 'funding_snapshot_minor'],
        'competitive_reward_grants' => ['amount'],
        'xp_transactions' => ['amount'],
    ],
];

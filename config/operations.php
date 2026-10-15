<?php

/*
|--------------------------------------------------------------------------
| E25 - Production operations knobs
|--------------------------------------------------------------------------
| كل القيم اختيارية ولها افتراضات آمنة لا تغيّر سلوك المنتج. لا أسرار هنا؛ القيم الحساسة (إن وُجدت) تأتي من البيئة فقط ولا تُطبع أبدًا.
| الاستعمال: production:check، release:preflight، release:smoke، /ready، مركز العمليات.
*/

return [

    /*
    | هوية الإصدار (اختيارية). تُضبط عند النشر بقيمة SHA الالتزام أو وسم الإصدار، مثل: APP_RELEASE=b983867
    | تُعرض في /ready ومركز العمليات وتُضاف لسياق كل سجل. القيمة غير الآمنة (غير [A-Za-z0-9._-]{1,64}) تُتجاهل.
    | RELEASE_SHA بديل لنفس الغرض (توافقًا مع أنظمة CI التي تعرّفه تلقائيًا).
    */
    'release' => [
        'id' => env('APP_RELEASE', env('RELEASE_SHA')),
    ],

    /*
    | الأوامر الهدّامة بالإنتاج (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة افتراضيًا عند APP_ENV=production (AppServiceProvider).
    | الاستثناء الواعي لعملية واحدة فقط: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true في بيئة تلك العملية، ثم يُزال. لا تضعه في .env الدائم.
    */
    'allow_destructive_commands' => (bool) env('OPS_ALLOW_DESTRUCTIVE_COMMANDS', false),

    /*
    | نبض المجدول: مهمة صغيرة كل دقيقة (routes/console.php) تكتب الطابع الزمني في الكاش الافتراضي.
    | الحالة تُحسب من عمر آخر نبضة: ok < stale_after_seconds <= stale < down_after_seconds <= down. لا نبضة قط = unknown (نشر جديد).
    | /ready لا يفشل بسبب المجدول إلا إن فعّلت ready_fails_on_down صراحةً (افتراضيًا معلوماتي فقط).
    */
    'scheduler' => [
        'heartbeat_key' => 'ops:scheduler:heartbeat',
        'heartbeat_ttl_seconds' => 86400,
        'stale_after_seconds' => (int) env('OPS_SCHEDULER_STALE_SECONDS', 300),
        'down_after_seconds' => (int) env('OPS_SCHEDULER_DOWN_SECONDS', 900),
        'ready_fails_on_down' => (bool) env('OPS_READY_FAILS_ON_SCHEDULER', false),
    ],

    /*
    | الطابور: أقصى timeout لأي مهمة (يطابق App\Jobs\Concerns\BoundedRetries). retry_after لازم أن يتجاوزه.
    | تنبيه تراكم: عمر أقدم مهمة منتظرة (ثوانٍ) وعدد المهام الفاشلة، لمركز العمليات.
    */
    'queue' => [
        'job_timeout_seconds' => 80,
        'backlog_warn_age_seconds' => (int) env('OPS_QUEUE_BACKLOG_WARN_SECONDS', 900),
        'failed_jobs_warn' => (int) env('OPS_FAILED_JOBS_WARN', 1),
        'signals_cache_seconds' => 30,
    ],

    /*
    | فحص الدخان بعد النشر (php artisan release:smoke): قراءة فقط (GET) ومهل صغيرة. المسارات كلها عامة (200 للضيف).
    | {profile} يُستبدل بمعرّف لاعب عام عند تمريره بالخيار --profile (اختياري).
    */
    'smoke' => [
        'timeout_seconds' => 5,
        'connect_timeout_seconds' => 3,
        'slow_ms' => 2000,
        'routes' => ['/up', '/ready', '/', '/login', '/puzzles', '/competitions', '/store', '/players/{profile}'],
    ],

    /*
    | تهيئة أول مدير بالإنتاج (AdminUserSeeder): كلمة مرور إلزامية من البيئة بالإنتاج (لا كلمة افتراضية معروفة).
    | احذف المتغيرين من البيئة بعد أول تشغيل. لا تُطبع كلمة المرور أبدًا.
    */
    'admin_bootstrap' => [
        'email' => env('ADMIN_BOOTSTRAP_EMAIL'),
        'password' => env('ADMIN_BOOTSTRAP_PASSWORD'),
        'min_password_length' => 12,
    ],

    /*
    | حسابات اختبار معروفة يجب ألا توجد بقاعدة الإنتاج (production:check).
    */
    'known_test_accounts' => [
        'email_suffix' => '@ahjiyat.test',
        'default_admin_email' => 'admin@ahjiyat.app',
        'default_admin_passwords' => ['change-me-now'],
    ],
];

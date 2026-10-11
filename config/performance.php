<?php

/*
|--------------------------------------------------------------------------
| E24 - Performance / Scalability / Observability knobs
|--------------------------------------------------------------------------
| كل القيم هنا اختيارية ولها افتراضات آمنة تحفظ سلوك المنتج كما هو. لا شيء هنا يغيّر الجواهر/الجوائز/XP/الصلاحيات.
| الكاش هنا للعرض فقط (انظر docs/performance-e24.md): لا يُخزَّن أي شيء يقرّر صلاحية أو رصيدًا أو مكافأة.
*/

return [

    /*
    | تتبّع الجهاز (FraudDetectionService::recordSighting) كان يكتب صفًا (update) + يعدّ بصمات الـIP في **كل** طلب.
    | الآن: مرة واحدة كل N ثانية لكل (مستخدم، IP، جهاز). 0 = السلوك القديم (كل طلب). عند فشل الكاش يُسجَّل كما كان (fail-open).
    */
    'device_sighting' => [
        'throttle_seconds' => (int) env('PERF_DEVICE_SIGHTING_THROTTLE', 60),
    ],

    /*
    | المجدول: onOneServer() لكل مهمة (انظر routes/console.php). يحتاج كاشًا مشتركًا بين الخوادم (Redis أو قاعدة البيانات)؛ على خادم واحد لا أثر له.
    */
    'scheduler' => [
        'one_server' => (bool) env('PERF_SCHEDULER_ONE_SERVER', true),
    ],

    /*
    | مدد الكاش (ثوانٍ). كلها قصيرة ومحدودة (لا forever) ولها إبطال صريح عند التعديل حين يوجد مصدر تعديل.
    */
    'cache' => [
        // كتالوج مستويات اللاعب (جدول صغير يُعدَّل نادرًا من الإدارة). يُبطَل فورًا عند أي حفظ/حذف لـ LevelDefinition.
        'level_catalog_ttl' => (int) env('PERF_LEVEL_CATALOG_TTL', 3600),
        // روابط الهوية البصرية (الشعار/الأيقونة…): نتيجة فحص وجود الملف على القرص. تُبطَل عند حفظ الإعدادات.
        'branding_urls_ttl' => (int) env('PERF_BRANDING_URLS_TTL', 600),
        // أفضل N في لوحة الصدارة العامة (عرض فقط؛ ترتيبي الشخصي يُحسب مباشرةً).
        'leaderboard_ttl' => (int) env('PERF_LEADERBOARD_TTL', 60),
    ],

    /*
    | المراقبة (Observability).
    */
    'observability' => [
        // معرّف طلب (X-Request-ID) يُمرَّر إلى الاستجابة والسجل؛ يُقبل من الوكيل الأمامي فقط إن كان بصيغة آمنة.
        'request_id' => (bool) env('PERF_REQUEST_ID', true),
        // سجل الطلبات البطيئة (بلا أجسام/كوكيز/رؤوس). 0 = معطّل.
        'slow_request_ms' => (int) env('PERF_SLOW_REQUEST_MS', 1500),
        // نسبة أخذ العيّنات من الطلبات البطيئة (0..1) لتفادي إغراق السجل تحت الحِمل.
        'slow_request_sample' => (float) env('PERF_SLOW_REQUEST_SAMPLE', 1.0),
        // مهلة فحص الجاهزية لكل تابع (ms): تجاوزها = غير جاهز (بطيء).
        'readiness_timeout_ms' => (int) env('PERF_READINESS_TIMEOUT_MS', 1500),
        // رمز اختياري لـ GET /ready (الرأس X-Health-Token). فارغ = بلا رمز (الفحص لا يُرجع أسرارًا).
        'readiness_token' => env('PERF_READINESS_TOKEN'),
    ],

    /*
    | بيانات الأداء الاصطناعية (php artisan performance:seed) - لا تعمل إلا محليًا/اختباريًا.
    */
    'seed' => [
        'profiles' => [
            'small' => ['users' => 1000, 'attempts_per_user' => 3, 'chat_messages' => 2000],
            'medium' => ['users' => 25000, 'attempts_per_user' => 5, 'chat_messages' => 50000],
            'large' => ['users' => 250000, 'attempts_per_user' => 6, 'chat_messages' => 500000],
        ],
    ],
];

<?php

/*
| E41 - تنسيق التجارة الاستهلاكية والدفع الإلكتروني (المشتريات الرقمية: المسار المميز اليوم).
|
|  DigitalPurchase هو **سلطة** الأعمال؛ المزوّد الخارجي آلية دفع فقط ولا يصير سلطة أبدًا. هذا الملف ضبط للنشر والبيئة: **لا أسرار** هنا ولا بالمستودع.
|  أسرار أي مزوّد حيّ مستقبلي (مفاتيح API / سر توقيع webhook) تُقرأ من متغيرات البيئة أو مدير الأسرار فقط — لا قاعدة بيانات ولا إعدادات Filament ولا سجلات.
|  لا مزوّد حيّ منفَّذ في E41: المزوّدان المسجَّلان هما «manual» (E40) و«sandbox» (محاكاة محلية للاختبار فقط، يُرفض في الإنتاج بالكود).
*/
return [
    // مفتاح إيقاف شامل لميزات الدفع الإلكتروني (بدء checkout مستضاف). false ⇒ لا جلسات دفع جديدة. المزوّد اليدوي (E40) يتبع مفتاح المسار نفسه ولا يتأثر.
    'enabled' => (bool) env('COMMERCE_ENABLED', true),

    // المزوّد الافتراضي للمشتريات الجديدة + بديل (يدوي) حين لا يكون الافتراضي مفعَّلًا أو لا يقبل العملة. الشراء يجمّد مزوّده وقت الإنشاء (لقطة).
    'default_provider' => (string) env('COMMERCE_DEFAULT_PROVIDER', 'manual'),
    'fallback_provider' => (string) env('COMMERCE_FALLBACK_PROVIDER', 'manual'),

    // العملات المسموحة عامة للتجارة (تُقاطَع مع عملات المزوّد وعملات المسار). بلا تحويل عملات. ISO من 3 حروف.
    'allowed_currencies' => array_values(array_filter(array_map(
        static fn (string $c): string => strtoupper(trim($c)),
        explode(',', (string) env('COMMERCE_ALLOWED_CURRENCIES', 'USD,EUR,TRY'))
    ))),

    /*
    | سجل المزوّدين. المفتاح فريد ويُحفظ في digital_purchases.provider.
    |  enabled         : يسمح بإنشاء checkout/طلبات **جديدة**. تعطيله لا يوقف webhook ولا الاسترداد ولا المطابقة لما أُنشئ سابقًا (AV).
    |  accept_webhooks : يستقبل webhook لعمليات قائمة (مستقل عن enabled عمدًا كي لا تضيع دفعة وصلت بعد إيقاف المزوّد).
    |  class           : يُحلّ من الحاوية (يمكن استبداله بالاختبارات/مستقبلًا).
    */
    'providers' => [
        'manual' => [
            'class' => \App\Services\Commerce\ManualCommercePaymentProvider::class,
        ],

        // محاكاة محلية للاختبار فقط (ليست بوابة دفع حقيقية). تُرفض في الإنتاج بالكود مهما كانت القيم؛ تُفعَّل افتراضيًا في local/testing/CI فقط.
        'sandbox' => [
            'class' => \App\Services\Commerce\SandboxCommercePaymentProvider::class,
            'enabled' => env('COMMERCE_SANDBOX_ENABLED'),
            'accept_webhooks' => (bool) env('COMMERCE_SANDBOX_ACCEPT_WEBHOOKS', true),
            // سر توقيع **محاكاة** ثابت وغير سري (قيمة تطوير معروفة). لا يعمل خارج local/testing/CI.
            'webhook_secret' => (string) env('COMMERCE_SANDBOX_WEBHOOK_SECRET', 'sandbox-not-a-secret'),
            'currencies' => ['USD', 'EUR', 'TRY'],
            // سلوك الاسترداد المحاكى: succeeded | pending | failed (للاختبار الحتمي).
            'refund_mode' => (string) env('COMMERCE_SANDBOX_REFUND_MODE', 'succeeded'),
            'label' => 'دفع تجريبي (محاكاة)',
        ],
    ],

    // معالجات المنتجات (نوع المنتج ⇒ صنف ينفّذ CommerceProductHandler). نواة التجارة لا تعرف المسار المميز مباشرة.
    'products' => [
        'ahjiyat_pass_premium' => \App\Services\AhjiyatPass\PassPremiumProductHandler::class,
        // E43: وصول دائم لسلسلة مبدع مميزة (شراء مرة واحدة؛ حصة المبدع تُقيَّد في دفتر E39 داخل معاملة التحقق نفسها).
        'creator_series_access' => \App\Services\CreatorPremium\CreatorSeriesAccessProductHandler::class,
    ],

    'checkout' => [
        // عمر جلسة الدفع المستضافة عند المزوّد (دقائق). بعدها تُستبدل بجلسة جديدة صريحة ومدقَّقة (provider idempotency key بالجيل) ما دام الطلب لم ينتهِ.
        'session_expiry_minutes' => (int) env('COMMERCE_SESSION_EXPIRY_MINUTES', 60),
        // عمر الطلب «بانتظار الدفع» لمزوّد مستضاف (ساعات). المزوّد اليدوي يتبع ahjiyat_pass.purchase.expiry_hours.
        'purchase_expiry_hours' => (int) env('COMMERCE_PURCHASE_EXPIRY_HOURS', 24),
        // أقصى عدد لاستبدال الجلسة لطلب واحد (يمنع حلقة جلسات).
        'max_generations' => 3,
        // مضيفو صفحات الدفع المسموحة لكل مزوّد (حماية من إعادة توجيه مفتوحة): المضيف المطلوب يجب أن يطابق أحدها. الواجهة لا تُعيد توجيه لغيرها أبدًا.
        // sandbox يُحلّ من مسارنا المحلي ولا يحتاج مضيفًا خارجيًا (يُقبل مضيف التطبيق نفسه فقط).
        'allowed_hosts' => [],
        // وقت (ثوانٍ) يُعدّ فيه طلب الاسترداد الجاري «قيد التنفيذ» فلا يُكرَّر من نقرة ثانية.
        'refund_inflight_seconds' => 60,
    ],

    'webhooks' => [
        // ملاحظة: القيم الرقمية تقبل «فارغ» في .env (أسطر .env.example تُترك فارغة لتجتاز فحص E25) وتعود حينها للافتراضي الآمن، لا إلى 0.
        // مفتاح عام لاستقبال webhook التجارة (مغلق افتراضيًا خارج local/testing: لا مزوّد حيّ منفَّذ الآن).
        'enabled' => env('COMMERCE_WEBHOOKS_ENABLED'),
        'max_body_bytes' => (int) (env('COMMERCE_WEBHOOK_MAX_BODY_BYTES') ?: 65_536),
        // نافذة تحمّل الطابع الزمني (ثوانٍ) للتوقيع؛ أقدم/أحدث ⇒ رفض (replay).
        'replay_tolerance_seconds' => (int) (env('COMMERCE_WEBHOOK_REPLAY_TOLERANCE') ?: 300),
        'rate_per_minute' => (int) (env('COMMERCE_WEBHOOK_RATE_PER_MINUTE') ?: 600),
        // عدّاد فشل التوقيع (للصحة التشغيلية فقط؛ لا يُكتب بقاعدة البيانات).
        'failure_counter_ttl_minutes' => 1440,
    ],

    'refunds' => [
        // الاسترداد الكامل فقط في E41 (لا استرداد جزئي).
        'partial_supported' => false,
        'note_min' => 5,
    ],

    'reconcile' => [
        'max_findings' => (int) env('COMMERCE_RECONCILE_MAX_FINDINGS', 200),
        'default_limit' => 500,
        'max_limit' => 5000,
        // طلب مفتوح عند مزوّد مستضاف يُعدّ «قديمًا» بعد هذه الدقائق من انتهاء الجلسة بلا نتيجة.
        'stale_checkout_minutes' => 120,
        // حدود المزامنة المجدولة (استعلام حالة المزوّد): دفعة صغيرة ومحدودة بالزمن، لا استطلاع لا نهائي.
        'sync_batch' => 50,
        'sync_min_age_minutes' => 5,
        'sync_max_age_hours' => 72,
    ],

    'notifications' => [
        'enabled' => (bool) env('COMMERCE_NOTIFICATIONS_ENABLED', true),
    ],

    'status' => [
        // استطلاع حالة الشراء من المتصفح: حدّ أدنى للفاصل (ثوانٍ)، وأقصى عدد محاولات قبل التوقف. الخادم يطبّق حدّ معدّل مسمّى أيضًا.
        'poll_min_seconds' => 5,
        'poll_max_attempts' => 24,
        'rate_per_minute' => 20,
    ],

    'limits' => [
        'checkout_per_minute' => 6,
        'checkout_per_hour' => 30,
        'history_per_page' => 15,
        'events_per_page' => 25,
        'admin_events_per_page' => 25,
    ],

    'analytics' => [
        'cache_seconds' => 0, // لا كاش لحالات الدفع؛ التحليلات تجميعية وتقرأ قاعدة البيانات مباشرة (مقيَّدة بنافذة زمنية).
        'window_days' => 30,
    ],
];

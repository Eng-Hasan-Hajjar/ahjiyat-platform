<?php

/*
| E32 - دفع الرعاية وعمليات الدفع. كل القيم قابلة للضبط بالبيئة أو هنا؛ **لا أسرار ولا بيانات بنكية ولا مفاتيح مزوّد** في هذا الملف أو في .env.example.
| لا بطاقات ولا SDK ولا بوابة حيّة: المزوّد الوحيد العامل هو «manual» (تحويل يدوي + إثبات خاص + تحقق مالي). المزوّدون الإلكترونيون المستقبليون
| (Stripe/PayPal/PayTabs/HyperPay/Tap/MyFatoorah/بوابة بنكية) يتصلون عبر عقد PaymentProvider دون المساس بنموذج SponsorshipContribution (docs/sponsorship-payments-e32.md).
| تفاصيل التحويل العلنية (المستفيد/الآيبان…) تُدار من إعدادات المنصة (مجموعة payments) لا من هنا.
*/
return [
    // مفتاح إيقاف شامل: false ⇒ لا طلبات دعم جديدة (الطلبات القائمة تبقى قابلة للمراجعة والاسترداد من الإدارة).
    'enabled' => (bool) env('PAYMENTS_ENABLED', true),

    'default_provider' => (string) env('PAYMENTS_DEFAULT_PROVIDER', 'manual'),

    // سجل المزوّدين (المفتاح فريد). كل مزوّد: enabled (نشر/بيئة) + class (يُحلّ من حاوية الخدمات فيمكن استبداله/حقنه).
    // إضافة مزوّد مستقبلًا = سطر هنا + صنف ينفّذ PaymentProvider. المزوّدون غير المفعّلين هنا يرفضهم السجل كأنهم غير موجودين.
    'providers' => [
        'manual' => [
            'enabled' => (bool) env('PAYMENTS_MANUAL_ENABLED', true),
            'class' => \App\Services\Payments\ManualPaymentProvider::class,
        ],
    ],

    // العملات المسموحة للدفع (تُقاطَع دائمًا مع config/sponsorship.php: لا تُقبل عملة غير معرّفة هناك). قائمة مفصولة بفواصل.
    'allowed_currencies' => array_values(array_filter(array_map(
        static fn (string $c): string => strtoupper(trim($c)),
        explode(',', (string) env('PAYMENTS_ALLOWED_CURRENCIES', 'USD,EUR,TRY'))
    ))),

    'checkout' => [
        // حدّ أدنى بالوحدة الصغرى (100 = 1.00). الحدّ الأعلى = SPONSORSHIP_MAX_AMOUNT_MINOR (E30) ما لم يُضبط أقل هنا.
        'min_amount_minor' => (int) env('PAYMENTS_MIN_AMOUNT_MINOR', 100),
        'max_amount_minor' => env('PAYMENTS_MAX_AMOUNT_MINOR') !== null && env('PAYMENTS_MAX_AMOUNT_MINOR') !== '' ? (int) env('PAYMENTS_MAX_AMOUNT_MINOR') : null,
        // مبالغ مقترحة بالوحدة الرئيسية (للراحة فقط - الخادم يتحقق دائمًا من المبلغ المُرسَل).
        'suggested_amounts' => [10, 25, 50, 100, 250],
        'allow_custom_amount' => (bool) env('PAYMENTS_ALLOW_CUSTOM_AMOUNT', true),
        // سقف الطلبات المفتوحة (بانتظار الدفع/قيد التحقق) لكل مستخدم: يمنع إغراق الطابور.
        'max_open_requests_per_user' => (int) env('PAYMENTS_MAX_OPEN_REQUESTS', 5),
        'note_max' => 255,
        'display_name_max' => 80,
    ],

    'request' => [
        // صلاحية طلب «بانتظار الدفع». المقدَّم/المحقَّق لا ينتهي أبدًا آليًا.
        'expiry_hours' => (int) env('PAYMENTS_REQUEST_EXPIRY_HOURS', 48),
        'reference_prefix' => 'SUP',
        'reference_length' => 8,
        // أبجدية مرجع التحويل بلا أحرف ملتبسة (0/O/1/I).
        'reference_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'expire_batch' => 200,
    ],

    // إثبات التحويل: ملف **خاص** على قرص غير علني، يُقدَّم عبر متحكم مخوَّل فقط. JPEG/PNG/WEBP/PDF بنوع المحتوى الفعلي؛ لا SVG ولا HTML.
    'proof' => [
        'disk' => (string) env('PAYMENTS_PROOF_DISK', 'local'),
        'directory' => 'payment-proofs',
        'max_kb' => (int) env('PAYMENTS_PROOF_MAX_KB', 5120),
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'],
        'max_pixels' => 40_000_000,
    ],

    // الاحتفاظ: لا يُحذف سجل مالي. الإثبات (بيانات شخصية/مالية) يمكن تقليمه بعد مدة من الحالة النهائية بأمر صريح (payments:prune-proofs --dry-run أولًا).
    'retention' => [
        'proof_days_after_final' => (int) env('PAYMENTS_PROOF_RETENTION_DAYS', 365),
        // لا يُقلَّم إثبات طلب مُسترد/مرفوض حديثًا (نافذة نزاع) مهما كانت المدة أعلاه.
        'dispute_window_days' => (int) env('PAYMENTS_DISPUTE_WINDOW_DAYS', 90),
        'prune_batch' => 200,
    ],

    // بنية Webhook المستقبلية. لا مزوّد إلكتروني حيّ ⇒ المسار مغلق افتراضيًا ويرفض كل مزوّد غير معروف/غير مفعّل/لا يدعمه.
    'webhooks' => [
        'enabled' => (bool) env('PAYMENTS_WEBHOOKS_ENABLED', false),
        'max_body_bytes' => 65536,
    ],

    'reconcile' => [
        'max_findings' => 200,
    ],

    'analytics' => [
        'cache_ttl' => 120,
        'window_days' => 30,
    ],

    'notifications' => [
        'enabled' => (bool) env('PAYMENTS_NOTIFICATIONS_ENABLED', true),
    ],
];

<?php

/*
| E30 (A) - الرعاة والداعمون وتمويل المكافآت. كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار ولا بيانات دفع.
| لا بوابة دفع: المساهمات تُسجَّل وتُؤكَّد يدويًا من الإدارة. المبالغ أعداد صحيحة بالوحدة الصغرى (cents/kuruş) بلا floats.
| لا سعر صرف حيّ: المدير يدخل المبلغ الأصلي وعملته ثم المبلغ المعادل بعملة المحاسبة عند التأكيد (يُدقَّق).
*/
return [
    'enabled' => (bool) env('SPONSORSHIP_ENABLED', true),

    // عملة المحاسبة الموحَّدة التي تُقاس بها كل المجاميع والمستويات والتعزيز.
    'accounting_currency' => strtoupper((string) env('SPONSORSHIP_ACCOUNTING_CURRENCY', 'USD')),

    // قائمة العملات المسموحة لتسجيل المساهمة (المفتاح ISO-4217) وأسّ الوحدة الصغرى. أي عملة خارجها ترفضها الخدمة.
    'currencies' => [
        'USD' => ['symbol' => '$', 'exponent' => 2, 'label' => 'دولار أمريكي'],
        'EUR' => ['symbol' => '€', 'exponent' => 2, 'label' => 'يورو'],
        'TRY' => ['symbol' => '₺', 'exponent' => 2, 'label' => 'ليرة تركية'],
        'SAR' => ['symbol' => 'ر.س', 'exponent' => 2, 'label' => 'ريال سعودي'],
        'AED' => ['symbol' => 'د.إ', 'exponent' => 2, 'label' => 'درهم إماراتي'],
        'EGP' => ['symbol' => 'ج.م', 'exponent' => 2, 'label' => 'جنيه مصري'],
    ],

    // سقف المبلغ الواحد بالوحدة الصغرى (حماية من أخطاء الإدخال): 10 ملايين وحدة رئيسية.
    'max_amount_minor' => (int) env('SPONSORSHIP_MAX_AMOUNT_MINOR', 1_000_000_000),

    'profile' => [
        'name_max' => 80,
        'description_max' => 600,
        'url_max' => 255,
        'reference_max' => 120,
        'notes_max' => 1000,
    ],

    // شعارات الداعمين: JPEG/PNG/WEBP فقط بنوع المحتوى الفعلي (لا SVG). اسم عشوائي يولّده المخدّم.
    'logo' => [
        'disk' => env('SPONSORSHIP_LOGO_DISK', 'public'),
        'directory' => 'supporters',
        'max_kb' => (int) env('SPONSORSHIP_LOGO_MAX_KB', 1024),
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    ],

    'public' => [
        'page_size' => 12,
        'top_limit' => 20,
        'recent_limit' => 12,
        'organizations_limit' => 12,
        // عمق الجلب من الكاش قبل إعادة فحص الظهور الحيّ (هامش لمن قد يُخفون بعد تخزين اللقطة).
        'overfetch' => 10,
        'cache_ttl' => (int) env('SPONSORSHIP_CACHE_TTL', 120),
        'display_ttl' => (int) env('SPONSORSHIP_DISPLAY_TTL', 60),
        'supporting_sponsors_limit' => 8,
    ],

    // تعزيز المكافآت الرسمية. الصيغة: steps = floor(funding / step)؛ boost_bps = min(steps * per_step, max).
    // الأرقام بالنقاط الأساسية (bps): 100 = 1%. السقف الصلب أدناه لا يتجاوزه أي برنامج مهما ضُبط.
    'reward' => [
        'enabled' => (bool) env('SPONSORSHIP_REWARD_BOOST_ENABLED', true),
        'bps_denominator' => 10000,
        'hard_max_bps' => (int) env('SPONSORSHIP_HARD_MAX_BOOST_BPS', 5000),
        'default_step_minor' => 10000,
        'default_per_step_bps' => 50,
        'default_max_bps' => 500,
    ],

    // قفل خطة المنافسة قبل بدئها: يقفلها الأمر المجدول لكل منافسة تبدأ خلال هذه المدة (دقائق) وما زالت قابلة للتعديل.
    'events' => [
        'lock_lead_minutes' => (int) env('SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES', 60),
    ],

    'analytics' => [
        'cache_ttl' => 300,
    ],

    'notifications' => [
        'enabled' => (bool) env('SPONSORSHIP_NOTIFICATIONS_ENABLED', true),
    ],
];

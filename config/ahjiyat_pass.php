<?php

/*
| E40 - مسار الأحجيات (Ahjiyat Pass): مسار تقدّم دوري مجاني + مميز.
|
| الإعدادات التجارية الفعلية (الاسم، التواريخ، السعر، المهام، المكافآت) تُدار من لوحة الإدارة ومخزَّنة في قاعدة البيانات؛ هذا الملف يحمل **حدودًا وقيمًا افتراضية فقط**.
| متغيّر بيئة واحد فقط (مفتاح نشر): AHJIYAT_PASS_ENABLED. لا أسرار ولا بيانات بنكية ولا مفاتيح مزوّد هنا أو في .env.example.
|
| لا يوجد هنا ما يمسّ الاقتصاد التنافسي: المسار نقاطه خاصة به (Pass Points) لا XP ولا جواهر ولا نقاط موسم، والمميز لا يمنح أي أفضلية تنافسية (docs/ahjiyat-pass-e40.md).
*/
return [
    // مفتاح إيقاف شامل: false ⇒ تختفي صفحات اللاعب وشراء المميز وتوقف نقاط جديدة؛ البيانات والمشتريات والمطالبات التاريخية تبقى كما هي وللإدارة مراجعتها.
    'enabled' => (bool) env('AHJIYAT_PASS_ENABLED', true),

    // اسم العرض الافتراضي (يُغيَّر لكل مسار من الإدارة دون تعديل الكود).
    'default_name' => 'مسار الأحجيات',

    'lifecycle' => [
        'default_duration_days' => 30,
        'min_duration_days' => 7,
        'max_duration_days' => 120,
        // مهلة المطالبة بما كُسب بعد نهاية المسار (لا نقاط جديدة بعد ends_at). تُحدَّد لكل مسار وقت البناء ضمن هذه الحدود ثم تُقفل مع النشر.
        'claim_grace_days' => 5,
        'claim_grace_max_days' => 14,
    ],

    'limits' => [
        'max_tiers' => 100,
        'max_missions' => 40,
        // أقصى عدد مرات يُحتسب فيها إكمال المهمة نفسها (فترات يومية/أسبوعية متعاقبة) لكل لاعب في المسار الواحد: يحدّ زراعة النقاط.
        'max_completions_cap' => 14,
        'max_points_per_mission' => 1000,
        'max_required_points' => 1_000_000,
        'max_reward_quantity' => 5,
    ],

    // قيم افتراضية مقترحة في نماذج الإدارة فقط (الإدارة تضبط قيمة كل مهمة؛ لا قيمة ثابتة في المنطق).
    'default_points' => ['easy' => 50, 'medium' => 100, 'hard' => 200],

    // توزيع مقترح لمهام الأسبوع (إرشادي للإدارة والمعاينة؛ لا يُفرض).
    'suggested_weekly_mix' => ['easy' => 3, 'medium' => 2, 'hard' => 1],

    'rewards' => [
        // أنواع عناصر المتجر المسموحة كمكافآت مسار (تجميلية/وصول/رقمية فقط). المستهلكات والفيزيائية مرفوضة: لا قيمة اقتصادية قابلة للتحويل ولا شحن.
        'allowed_item_types' => ['cosmetic', 'access', 'digital'],
        // مكافأة عملة (جواهر) **غير مدعومة** في E40 عمدًا (لا حلقة جواهر مقابل شراء). تفعيلها مستقبلًا قرار اقتصادي مستقل.
        'currency_rewards_supported' => false,
        // عنصر معروض للبيع بالعملات (له سعر نشط) لا يصلح مكافأة حصرية: false افتراضيًا. العناصر الحصرية تُنشأ بلا أسعار ويُخفيها المتجر العام تلقائيًا (docs).
        'allow_sellable_items' => false,
        // «استلام الكل»: سقف المكافآت في عملية واحدة (مقيَّد ومجزّأ).
        'collect_all_max' => 60,
    ],

    'premium' => [
        // الحدود بالوحدة الصغرى (100 = 1.00). السعر يحدّده الخادم من تعريف المسار؛ لا يقرؤه من العميل أبدًا.
        'min_price_minor' => 99,
        'max_price_minor' => 50_000,
        // قائمة بيضاء إضافية للعملات (تُقاطَع مع config/sponsorship.php و config/payments.php). لا تحويل عملات.
        'allowed_currencies' => ['USD', 'EUR', 'TRY'],
        // مزوّد الدفع (عقد CommercePaymentProvider). اليدوي هو الوحيد المنفَّذ؛ لا بوابة حيّة في E40.
        'provider' => 'manual',
    ],

    'purchase' => [
        // صلاحية طلب «بانتظار الدفع». المقدَّم/المحقَّق لا ينتهي آليًا.
        'expiry_hours' => 48,
        'reference_prefix' => 'PASS',
        'reference_length' => 8,
        'reference_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'expire_batch' => 200,
        'payer_note_max' => 255,
        'transfer_reference_max' => 120,
        'decision_message_max' => 300,
        // إثبات التحويل اختياري هنا (يتبع إعداد المنصة payments.proof_required إن وُجد)؛ التخزين خاص دائمًا وبقواعد E32 (config/payments.php: proof).
        'proof_enabled' => true,
    ],

    'cache' => [
        // تعريف المسار الحالي + مساره (الشرائح والمكافآت والمهام) فقط. لا تُخزَّن أبدًا نقاط اللاعب ولا مطالباته ولا ملكيته للمميز.
        'definition_seconds' => 300,
        'analytics_seconds' => 120,
    ],

    'theme' => [
        // قائمة بيضاء مغلقة؛ لا HTML ولا CSS ولا JS حرّ. القيم رموز تُترجَم لفئات CSS ثابتة بالواجهة.
        'accents' => ['amber', 'emerald', 'sky', 'violet', 'rose', 'teal'],
        'backgrounds' => ['aurora', 'dusk', 'forest', 'ocean', 'ember'],
        'tagline_max' => 90,
    ],

    'banner' => [
        // بانر علني (صورة فقط): نوع المحتوى الفعلي + أبعاد + اسم عشوائي من الخادم. لا SVG.
        'disk' => 'public',
        'directory' => 'ahjiyat-pass',
        'max_kb' => 2048,
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'max_pixels' => 16_000_000,
        'min_width' => 600,
    ],

    'notifications' => [
        'enabled' => true,
        // تنبيه «مهلة استلام مكافآتك تقترب» يُرسل مرة واحدة لكل لاعب/مسار، لمن لديه مكافآت قابلة للاستلام فقط، وقبل إغلاق المهلة بهذه الساعات. بلا لغة ضغط.
        'claim_window_reminder_hours' => 48,
        'reminder_batch' => 200,
    ],

    'reconcile' => [
        'max_findings' => 200,
        'chunk' => 500,
    ],

    'page' => [
        // سقف صفوف سجل نقاطي الأخيرة المعروضة على الصفحة.
        'recent_points_limit' => 8,
        'archive_per_page' => 12,
    ],

    'admin' => [
        'purchases_per_page' => 25,
    ],
];

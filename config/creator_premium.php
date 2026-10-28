<?php

/*
| E43 - سوق المبدعين المميز: وصول دائم (شراء مرة واحدة) إلى سلسلة مبدع مميزة، بإيراد بيع مباشر للمبدع.
|
| السلطات (لا تُدمج): CreatorSeries = المحتوى، E41 Commerce/DigitalPurchase = الدفع الاستهلاكي، E39 = دفتر المبدع وأرصدته ودفعاته، E38 = الإشراف.
| قواعد ثابتة (ليست إعدادات):
|  - كل المبالغ أعداد صحيحة بالوحدة الصغرى وبنقاط أساس؛ لا floats. عملة العرض = عملة حساب أرباح المبدع (الخيار A) ولا تحويل عملات ولا سعر صرف حيّ في أي مكان.
|  - الشراء مرة واحدة: لا اشتراك ولا تجديد آلي. لا يمنح الشراء نقاطًا ولا خبرة ولا ميزة تنافسية ولا سمعة ولا مكافأة إحالة.
|  - لا أسرار هنا: أسرار مزوّد الدفع تبقى في بيئة E41.
|
| مفتاح الإيقاف CREATOR_PREMIUM_ENABLED (الافتراضي false: آمن حتى يتوفر مزوّد دفع استهلاكي حيّ). مُعطَّلًا: لا تفعيل عرض جديد ولا checkout جديد؛ أما سجل المشتريات والوصول
| القائم والاسترداد والمصالحة وعمليات المالية الإدارية فتبقى كلها متاحة.
*/
return [
    'enabled' => (bool) env('CREATOR_PREMIUM_ENABLED', false),

    'sales' => [
        // قسمة الإيراد بنقاط الأساس: creator_bps + platform_bps = 10000 دائمًا (platform مشتقة فلا يختل المجموع).
        // حصة المبدع = ⌊الإجمالي × creator_bps ÷ 10000⌋ والباقي كله للمنصة (قاعدة الباقي: creator_floor) فمجموع الحصتين = الإجمالي تمامًا.
        'creator_share_bps' => (int) (env('CREATOR_PREMIUM_CREATOR_SHARE_BPS') ?: 7000),
        'platform_share_bps' => 10000 - (int) (env('CREATOR_PREMIUM_CREATOR_SHARE_BPS') ?: 7000),
        'rounding_rule' => 'creator_floor',

        // حدّا السعر (وحدة صغرى). 1.00 – 1,000.00 افتراضيًا؛ تُقاطَع مع عملات التجارة E41 وعملة حساب المبدع.
        'min_price_minor' => (int) (env('CREATOR_PREMIUM_MIN_PRICE_MINOR') ?: 100),
        'max_price_minor' => (int) (env('CREATOR_PREMIUM_MAX_PRICE_MINOR') ?: 100_000),
    ],

    'refund' => [
        'note_min' => 5,
        'note_max' => 1000,
        // أسباب الاسترداد (مجموعة مغلقة).
        'reasons' => [
            'user_request' => 'طلب المشتري',
            'duplicate' => 'دفع مكرر',
            'payment_issue' => 'مشكلة في الدفع',
            'content_issue' => 'مشكلة في محتوى السلسلة',
            'fraud' => 'اشتباه احتيال',
            'error' => 'خطأ إداري',
            'other' => 'سبب آخر',
        ],
    ],

    'limits' => [
        // طفرات المبدع على العروض (تفعيل/إيقاف/سعر) لكل مبدع.
        'offer_changes_per_minute' => 10,
        'offer_changes_per_hour' => 60,
        'transactions_per_page' => 15,
        'admin_per_page' => 25,
        'note_max' => 300,
    ],

    'analytics' => [
        'window_days' => 30,
        'top_series' => 5,
    ],

    'reconcile' => [
        'max_findings' => 200,
        'default_limit' => 500,
        'max_limit' => 5000,
    ],

    'notifications' => [
        'enabled' => true,
    ],
];

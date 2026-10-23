<?php

/*
| E39 - تسييل المبدعين: توزيع حصص الإيرادات، دفتر الأرباح، كشوف الحساب، وعمليات الدفع اليدوي.
|
| قواعد ثابتة (ليست إعدادات):
|  - كل المبالغ أعداد صحيحة بالوحدة الصغرى (cents/kuruş)، بلا floats، وبعملة محاسبة واحدة (نفس عملة الرعاية في config/sponsorship.php).
|  - لا حقل يخص الشهرة أو السمعة أو الدعم المالي أو عدد المتابعين في حساب الحصص: الوحدة المؤهَّلة = (لاعب، عمل) إجابة صحيحة فريدة داخل الفترة.
|  - لا تخزين لوجهة تحويل أو بيانات بنكية أو اعتماد أي مزوّد: الدفع يدوي خارج المنصة ويُسجَّل بمرجع خارجي فقط.
|  - لا جدولة تلقائية لإقفال الفترات: الإقفال قرار بشري صريح (واجهة الإدارة أو الأمر مع تأكيد).
|
| لا أسرار هنا؛ القيم قابلة للضبط بالبيئة.
*/
return [
    // مفتاح تعطيل شامل للميزة (يُعيد 404 لصفحات المبدع ويرفض طلبات الدفع). القراءة الإدارية والمصالحة تبقى متاحة.
    'enabled' => (bool) env('CREATOR_MONETIZATION_ENABLED', true),

    // عملة المحاسبة: null = عملة الرعاية المحاسبية (SponsorshipMoney::accountingCurrency)، وإلا رمز من سجل العملات المسموحة.
    'accounting_currency' => env('CREATOR_MONETIZATION_CURRENCY') !== null ? strtoupper((string) env('CREATOR_MONETIZATION_CURRENCY')) : null,

    'periods' => [
        'label_max' => 120,
        // حدّ أعلى لمجمّع الفترة بالوحدة الصغرى (حماية من أخطاء الإدخال): 1,000,000.00 وحدة رئيسية افتراضيًا.
        'max_pool_minor' => (int) env('CREATOR_MONETIZATION_MAX_POOL_MINOR', 100_000_000),
        // أقل مدة للفترة بالساعات وأقصاها بالأيام.
        'min_hours' => 24,
        'max_days' => 93,
        // ساعات يجب أن تمرّ بعد انتهاء الفترة قبل الإقفال (يسمح باستقرار المحاولات المتأخرة).
        'finalize_delay_hours' => (int) env('CREATOR_MONETIZATION_FINALIZE_DELAY_HOURS', 1),
        'note_max' => 500,
    ],

    // قواعد الحساب (تُلتقط لقطةً داخل الفترة عند الإقفال فلا يغيّرها تعديل الإعداد لاحقًا).
    'allocation' => [
        'formula_version' => 1,
        // أقصى عدد وحدات يُحسب للاعب واحد لدى مبدع واحد داخل الفترة (يمنع حشد لاعب واحد للحصة).
        'per_user_per_creator_cap' => (int) env('CREATOR_MONETIZATION_USER_CAP', 3),
        // حدّ أدنى للتأهل للحصة: وحدات محسوبة (بعد السقف) ولاعبون فريدون.
        'min_units_per_creator' => (int) env('CREATOR_MONETIZATION_MIN_UNITS', 5),
        'min_unique_players_per_creator' => (int) env('CREATOR_MONETIZATION_MIN_PLAYERS', 3),
        // حماية حسابية من تجاوز الأعداد الصحيحة (مجموع الوحدات الأقصى المسموح بتوزيعه).
        'max_total_units' => 2_000_000_000,
        'chunk' => 500,
    ],

    'payout' => [
        // أدنى مبلغ لطلب دفعة (وحدة صغرى). 25.00 افتراضيًا.
        'min_amount_minor' => (int) env('CREATOR_MONETIZATION_MIN_PAYOUT_MINOR', 2500),
        'max_amount_minor' => (int) env('CREATOR_MONETIZATION_MAX_PAYOUT_MINOR', 100_000_000),
        // تسميات وسائل الدفع (مجرد وسم؛ لا وجهة ولا بيانات حساب تُخزَّن).
        'methods' => [
            'bank_transfer' => 'تحويل بنكي',
            'e_wallet' => 'محفظة إلكترونية',
            'cash_pickup' => 'استلام نقدي',
            'other' => 'وسيلة أخرى (يُتفق عليها مع الدعم)',
        ],
        'reference_min' => 4,
        'reference_max' => 120,
        'note_max' => 500,
        'message_max' => 300,
        // true = لا يجوز لمن اعتمد الدفعة أن يسجّل صرفها (فصل المهام). مُوصى به عند وجود أكثر من مسؤول مالي.
        'require_separate_settler' => (bool) env('CREATOR_MONETIZATION_SEPARATE_SETTLER', false),
        'provider' => 'manual',
    ],

    'adjustments' => [
        'max_minor' => (int) env('CREATOR_MONETIZATION_MAX_ADJUSTMENT_MINOR', 10_000_000),
        'note_min' => 10,
        'note_max' => 500,
        // رموز السبب المنظَّمة (قائمة مغلقة).
        'reasons' => [
            'correction' => 'تصحيح خطأ حسابي',
            'goodwill' => 'تعويض معتمد',
            'clawback' => 'استرداد مبلغ دُفع أو حُسب بالخطأ',
            'dispute' => 'تسوية نزاع',
            'other' => 'سبب آخر (موثَّق بالملاحظة)',
        ],
    ],

    'holds' => [
        'note_min' => 8,
        'note_max' => 500,
        'reasons' => [
            'investigation' => 'تحقيق جارٍ',
            'moderation_case' => 'مرتبط بحالة إشراف',
            'payout_dispute' => 'نزاع على دفعة',
            'compliance_review' => 'مراجعة امتثال',
            'other' => 'سبب آخر (موثَّق بالملاحظة)',
        ],
    ],

    'notifications' => [
        'enabled' => (bool) env('CREATOR_MONETIZATION_NOTIFICATIONS', true),
    ],

    'analytics' => [
        'recent_periods' => 6,
    ],

    'reconcile' => [
        'default_limit' => 500,
    ],

    'list' => [
        'per_page' => 20,
    ],
];

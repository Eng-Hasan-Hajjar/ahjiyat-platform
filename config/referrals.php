<?php

/*
|--------------------------------------------------------------------------
| E33 - الإحالات ونمو المنصة
|--------------------------------------------------------------------------
| كل قيمة هنا افتراضية آمنة. المكافآت **معطّلة افتراضيًا** (REFERRALS_REWARDS_ENABLED=false): الإحالة تُحتسب وتظهر تحليلاتها بلا أي جواهر.
| تفعيل المكافآت يتطلب (1) مفتاحًا عامًا هنا و(2) حملة نشطة بمبالغ أكبر من صفر. لا مال نقدي ولا مستويات (MLM) ولا مكافآت على التسجيل وحده.
*/

return [
    // مفتاح الميزة كلها: false يوقف /r/{code} و?ref= والصفحة والتأهيل (الحسابات والإحالات المسجَّلة تبقى كما هي).
    'enabled' => (bool) env('REFERRALS_ENABLED', true),

    'code' => [
        // 6 محارف من أبجدية بلا حروف علة (تمنع كلمات مسيئة) وبلا محارف ملتبسة (0/1/I/O). 27^6 ≈ 387 مليون احتمال.
        'length' => 6,
        'alphabet' => 'BCDFGHJKLMNPQRSTVWXZ2346789',
        'max_collision_retries' => 8,
        // أقصى عدد مرات تدوير الرمز لكل مستخدم خلال 24 ساعة (يمنع إغراق جدول الرموز).
        'max_rotations_per_day' => 3,
    ],

    'attribution' => [
        // مدة صلاحية النية (الجلسة + الكوكي الأول-الطرف المشفّر). تُقصّ دائمًا إلى [7, 30] يومًا.
        'ttl_days' => (int) env('REFERRALS_TTL_DAYS', 14),
        'cookie' => 'ahj_ref',
        // الوجهات المسموحة بعد /r/{code} (مفتاح => اسم المسار). لا URL يأتي من العميل أبدًا (لا open redirect).
        'landings' => ['register' => 'register', 'home' => 'home', 'puzzles' => 'puzzles.index', 'discover' => 'discover.index'],
        'default_landing' => 'register',
    ],

    'qualification' => [
        // عمر الحساب الأدنى (بالساعات) قبل التأهيل؛ الحملة قد تتجاوزه ضمن [0, 720].
        'min_account_age_hours' => (int) env('REFERRALS_MIN_ACCOUNT_AGE_HOURS', 1),
        // مفتاح القاعدة => عدد الحلول الرسمية الصحيحة (أحجيات مختلفة) المطلوبة. التسجيل وحده لا يؤهّل أبدًا.
        'rules' => [
            'first_official_solve' => 1,
            'three_official_solves' => 3,
            'five_official_solves' => 5,
        ],
        'default_rule' => 'first_official_solve',
    ],

    'rewards' => [
        // false افتراضيًا: لا جواهر إحالة حتى يقرر المشرف.
        'enabled' => (bool) env('REFERRALS_REWARDS_ENABLED', false),
        // أقصى مبلغ مسموح لكل جانب (حماية من خطأ إدخال في لوحة الإدارة).
        'max_gems_per_side' => 500,
        // السقوف الافتراضية لكل محيل (عدد الإحالات المكافَأة). الحملة قد تضع أقل منها؛ لا يمكنها تجاوز الحد الأعلى المطلق.
        'caps' => ['daily' => 5, 'monthly' => 30, 'lifetime' => 100],
        'absolute_caps' => ['daily' => 50, 'monthly' => 300, 'lifetime' => 1000],
    ],

    'fraud' => [
        // إشارات فقط: لا حظر ولا تجميد. التعليق (pending_review) عند إشارة قوية أو عند هذا العدد من الإشارات الضعيفة معًا.
        'hold_weak_threshold' => 2,
        'referrer_burst_per_hour' => 6,
        'referrer_qualified_per_day' => 10,
        'fast_action_seconds' => 20,
        'sighting_lookback_days' => 30,
    ],

    'analytics' => [
        // النقرات تقريبية (فريدة بالساعة لكل زائر بلا تخزين IP أو UA). لا دقة وهمية.
        'click_dedupe_seconds' => 3600,
        'share_counter_days' => 8,
        'cache_seconds' => 120,
        'campaign_cache_seconds' => 300,
        'top_referrers' => 10,
    ],

    'limits' => [
        // الحدود بالدقيقة لكل IP/مستخدم (تُسجَّل في AppServiceProvider).
        'redirect_per_minute' => 60,
        'dashboard_per_minute' => 30,
        'share_per_minute' => 30,
        'campaign_action_per_minute' => 30,
    ],

    'notifications' => ['enabled' => (bool) env('REFERRALS_NOTIFICATIONS', true)],

    // ما يُشارَك: نوع => ['route' => اسم المسار]. بناء الروابط من المسارات المسماة فقط (لا host من الطلب).
    'share_kinds' => ['puzzle', 'community_puzzle', 'series', 'collection', 'creator', 'season', 'campaign', 'competition', 'supporters', 'platform'],
    'share_targets' => ['native', 'copy', 'whatsapp', 'telegram'],
];

<?php

/*
| E42 - تطوّر الموسم: منسّق التجربة الموسمية.
|
| هذا الملف **حدود وقوائم بيضاء فقط**. لا متغيّرات بيئة ولا أسرار ولا اقتصاد: الموسم لا يملك عملة ولا محفظة ولا نقاطًا ولا لوحة صدارة ولا منتجًا ماليًا.
| الحملات/المنافسات/المهام/المسار تبقى سلطة بياناتها وتقدّمها ومكافآتها (docs/season-evolution-e42.md).
*/
return [
    // مسارات حرفية تسبق /seasons/{season:slug}: موسم بهذا المعرّف يصير غير قابل للوصول، لذا يُرفض عند الحفظ.
    'reserved_slugs' => ['archive'],

    'theme' => [
        // قائمة بيضاء مغلقة؛ لا CSS ولا JS ولا HTML حرّ. القيم رموز تُترجَم لقيم ثابتة من الخادم (SeasonTheme).
        'presets' => ['generic', 'aseel'],
        'accents' => ['amethyst', 'gold', 'emerald', 'sky', 'rose', 'teal'],
        'tagline_max' => 120,
    ],

    'limits' => [
        'phases_per_season' => 12,
        'extra_campaigns' => 6,
        'competitions' => 12,
        'mission_pools' => 6,
        'announcements_per_season' => 60,
        'announcement_title_max' => 160,
        'announcement_body_max' => 2000,
        'phase_name_max' => 120,
        'phase_description_max' => 600,
        'label_max' => 120,
    ],

    'hub' => [
        // أقصى عدد إعلانات ظاهرة في مركز الموسم.
        'announcements_visible' => 5,
        // أقصى عناصر الجدول الزمني في المركز (الأقرب أولًا).
        'calendar_items' => 12,
        // مدة كاش الجدول الزمني العام للموسم (ثوانٍ). لا يُخزَّن فيه أي شيء خاص بمستخدم. 0 = بلا كاش.
        'cache_seconds' => 60,
    ],

    'index' => [
        // المواسم المنتهية المعروضة على صفحة الفهرس (الباقي بالأرشيف المُصفَّح).
        'past_preview' => 6,
        'archive_per_page' => 12,
    ],

    'notifications' => [
        // مرحلة بدأت قبل أكثر من هذه المدة تُعلَّم «معالجة» بصمت دون إشعار (تفادي إغراق عند التفعيل المتأخر أو التعبئة الرجعية).
        'phase_notice_max_age_hours' => 24,
        // الجمهور المسموح لإعلان يطلب الإدارة إشعاره صراحةً.
        'audiences' => ['participants', 'all'],
    ],
];

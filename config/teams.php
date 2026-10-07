<?php

/**
 * E19 - ثوابت الفرق (غير قابلة للتعديل من لوحة الإدارة عمدًا). الفريق ليس مصدر عملة ولا XP ولا مكافأة ولا أفضلية: لا ثابت اقتصادي هنا.
 */
return [
    'name_min' => 3,
    'name_max' => 40,
    'description_max' => 500,

    // سقف مطلق لعدد الأعضاء (max_members الفارغ = هذا السقف). السعة تُفحص ذريًا بـUPDATE شرطي.
    'max_members_cap' => 50,

    'invitation_ttl_days' => 7,
    'invite_cooldown_minutes' => 10,        // بعد رفض/إلغاء دعوة: لا إعادة دعوة نفس المستخدم من نفس الفريق قبل هذه المدة (بالكاش)
    'max_pending_requests_per_user' => 5,

    // ترتيب الفرق بالأحداث: مجموع أفضل N نتيجة صحيحة لأعضاء الفريق (لقطة التسجيل). السقف N يمنع أفضلية الحجم.
    'ranking_top_n' => 3,

    'directory_per_page' => 12,
    'invite_search_per_page' => 8,

    // معرّفات محجوزة لمسارات حرفية/حساسة (تعارض مع /teams/{slug}).
    'reserved_slugs' => ['create', 'leaderboard', 'mine', 'invitations', 'challenges', 'new', 'manage', 'search', 'settings', 'admin', 'login', 'register', 'api', 'logout', 'teams'],

    // E20: تحدّيات الفرق (غير متزامنة). النقاط بنفس صيغة E19 (أفضل ranking_top_n نتيجة صحيحة): لا صيغة ثانية.
    'challenges' => [
        'acceptance_hours' => 72,          // مهلة قبول التحدي المعلّق
        'play_hours' => 48,                // مهلة اللعب بعد القبول (يُقفل الروستر عند القبول)
        'roster_min' => 1,
        'roster_max' => 5,
        'min_valid_results' => 1,          // فريق بأقل من هذا عدد نتائج صحيحة يُحتسب بلا نقاط
        'per_page' => 10,
        'puzzle_search_per_page' => 8,
    ],

    // E20: بطولات الفرق. نقاط بحسب **ترتيب** الفريق بكل حدث معتمَد (لا جمع درجات أحجيات مختلفة). تُؤخذ لقطة منها عند النشر فلا يغيّر تعديلها لاحقًا تاريخًا.
    'championships' => [
        'points' => [1 => 10, 2 => 7, 3 => 5, 4 => 3, 5 => 2],
        'per_page' => 12,
        'standings_limit' => 100,
    ],
];

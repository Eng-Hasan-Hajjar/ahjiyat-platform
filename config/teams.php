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
    'reserved_slugs' => ['create', 'leaderboard', 'mine', 'invitations', 'new', 'manage', 'search', 'settings', 'admin', 'login', 'register', 'api', 'logout', 'teams'],
];

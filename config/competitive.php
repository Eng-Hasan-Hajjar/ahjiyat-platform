<?php

/**
 * E17 - ثوابت المنافسة (غير قابلة للتعديل من لوحة الإدارة عمدًا: العدالة لا تُضبط بإعداد).
 *
 * صيغة النقاط (CompetitiveScoringService): خاطئة = 0؛ صحيحة = score_base + مكافأة سرعة تتناقص خطيًا من score_speed_max إلى 0
 * عندما تصل المدة المقيسة بالسيرفر إلى سقف الأحجية (time_limit_seconds أو default_time_cap_seconds). عدد صحيح، حتمية، الأعلى أفضل.
 */
return [
    'challenge_ttl_hours' => 48,          // مهلة قبول التحدي ثم مهلة اللعب بعد القبول
    'score_base' => 1000,
    'score_speed_max' => 1000,
    'default_time_cap_seconds' => 600,    // سقف المكافأة السرعية لأحجية بلا time_limit_seconds

    'puzzle_search_per_page' => 8,        // اختيار أحجية لتحدٍّ (بحث + ترقيم: لا قائمة آلاف)
    'history_per_page' => 10,
    'leaderboard_per_page' => 20,
    'events_per_section' => 12,
];

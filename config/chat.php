<?php

/**
 * E21: الدردشة النصية (مباشرة DIRECT / فريق TEAM / عامة GLOBAL). كل الحدود هنا (لا أرقام موزَّعة بالكود).
 */
return [
    'message_min_length' => 1,
    'message_max_length' => 2000,           // محارف (نص عادي، يُهرَّب دائمًا عند العرض)
    'edit_window_minutes' => 15,            // بعدها الرسالة للقراءة فقط
    'page_size' => 40,                      // رسائل الصفحة الأولى (مؤشر cursor بالمعرّف)
    'max_page_size' => 60,
    'unread_cap' => 99,                     // العدّاد لا يتجاوز هذا (تفاديًا لعدّ تاريخ ضخم)

    'global' => [
        'slug' => 'global',
        'duplicate_window_seconds' => 60,   // نفس النص من نفس المستخدم بهذه النافذة مرفوض
    ],

    // مدد كتم الدردشة العامة (مفاتيح مغلقة بالدقائق: لا قيمة اعتباطية)
    'mute_durations' => ['10m' => 10, '1h' => 60, '24h' => 1440, '7d' => 10080],

    'report_categories' => ['spam', 'harassment', 'inappropriate', 'scam', 'other'],
    'report_details_max' => 500,
    'moderation_reason_max' => 200,
];

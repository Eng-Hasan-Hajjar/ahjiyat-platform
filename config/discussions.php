<?php

/*
| E36 - النقاشات والتعليقات والردود والإعجابات والإشراف على أحجيات المجتمع وسلاسل المبدعين والمجموعات العامة.
|
| قواعد مغلقة (لا تُضبط بأي إعداد هنا):
|  - النقاش **غير الدردشة**: لا ChatThread/ChatMessage ولا بثّ لحظي. نقاش دائم غير متزامن مرتبط بكيان عام واحد.
|  - **صفر اقتصاد**: لا جواهر ولا XP ولا مهام ولا سلسلة ولا إنجازات ولا إحالات ولا منافسة للتعليق أو الرد أو الإعجاب أو البلاغ.
|  - نص عادي فقط: لا HTML ولا Markdown ولا روابط قابلة للنقر ولا @إشارات ولا مرفقات.
|  - مالك المحتوى لا يملك حذف/إخفاء تعليق: الإشراف سلطة المنصة وحدها (discussions.moderate).
|  - الإخفاء الإشرافي والحظر والتجميد وإخفاء الموضوع تسبق أي كاش (لا كاش للنقاش أصلًا: القراءة حيّة).
| كل القيم تُضبط بالبيئة (DISCUSSIONS_*) ولا تحوي أسرارًا. التفاصيل: docs/community-discussions-e36.md
*/
return [

    // مفتاح إيقاف شامل (الصفحات العامة تُخفي القسم، والكتابة ترفض). الافتراضي مفعّل.
    'enabled' => (bool) env('DISCUSSIONS_ENABLED', true),

    'body' => [
        // السقف الأعلى الصلب 1000 (عمود VARCHAR(1000))؛ ضبط أكبر منه يُقصّ إليه في الخدمة.
        'min' => (int) env('DISCUSSIONS_BODY_MIN', 2),
        'max' => (int) env('DISCUSSIONS_BODY_MAX', 1000),
    ],

    // نافذة تعديل التعليق (دقائق منذ إنشائه). صفر = لا تعديل إطلاقًا.
    'edit_window_minutes' => (int) env('DISCUSSIONS_EDIT_WINDOW_MINUTES', 15),

    // رفض النص المطابق المتكرر من المستخدم نفسه على النقاش نفسه خلال هذه الثواني (ضد الإغراق). صفر = معطَّل.
    'duplicate_window_seconds' => (int) env('DISCUSSIONS_DUPLICATE_WINDOW_SECONDS', 120),

    'page' => [
        'roots_per_page' => (int) env('DISCUSSIONS_ROOTS_PER_PAGE', 20),
        // الردود المعروضة تحت كل تعليق جذري في الصفحة الرئيسية؛ الباقي بصفحة ردود مرقَّمة.
        'replies_preview' => (int) env('DISCUSSIONS_REPLIES_PREVIEW', 3),
        'replies_per_page' => (int) env('DISCUSSIONS_REPLIES_PER_PAGE', 20),
    ],

    'notifications' => [
        // إشعار مالك المحتوى بتعليق جديد: إشعار واحد كحدّ أقصى لكل نقاش في هذه النافذة (دقائق) فلا يغرقه نقاش نشط.
        'owner_window_minutes' => (int) env('DISCUSSIONS_OWNER_NOTIFY_WINDOW_MINUTES', 360),
        // إشعار كاتب التعليق بالرد عليه: إشعار واحد كحدّ أقصى لكل تعليق جذري في هذه النافذة (دقائق).
        'reply_window_minutes' => (int) env('DISCUSSIONS_REPLY_NOTIFY_WINDOW_MINUTES', 60),
    ],

    // حدود المعدّل (بمفتاح المستخدم لا الـIP). المحدِّدات المسماة في AppServiceProvider.
    'limits' => [
        'comment_per_minute' => (int) env('DISCUSSIONS_COMMENT_PER_MINUTE', 6),
        'comment_per_hour' => (int) env('DISCUSSIONS_COMMENT_PER_HOUR', 60),
        'reply_per_minute' => (int) env('DISCUSSIONS_REPLY_PER_MINUTE', 10),
        'reply_per_hour' => (int) env('DISCUSSIONS_REPLY_PER_HOUR', 90),
        'edit_per_minute' => (int) env('DISCUSSIONS_EDIT_PER_MINUTE', 10),
        'like_per_minute' => (int) env('DISCUSSIONS_LIKE_PER_MINUTE', 30),
        'like_per_hour' => (int) env('DISCUSSIONS_LIKE_PER_HOUR', 300),
        'report_per_minute' => (int) env('DISCUSSIONS_REPORT_PER_MINUTE', 5),
        'report_per_hour' => (int) env('DISCUSSIONS_REPORT_PER_HOUR', 20),
        'read_per_minute' => (int) env('DISCUSSIONS_READ_PER_MINUTE', 120),
    ],

    'moderation' => [
        'note_min' => 3,
        'note_max' => 255,
        'page_size' => 20,
    ],

    'analytics' => [
        'cache_seconds' => (int) env('DISCUSSIONS_ANALYTICS_CACHE_SECONDS', 300),
        'days' => 14,
    ],
];

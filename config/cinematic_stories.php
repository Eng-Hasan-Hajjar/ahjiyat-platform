<?php

/*
| CIN-1 - السلاسل السينمائية التفاعلية.
|
| العلم مغلق افتراضيًا: مغلقًا تُرجع كل المسارات 404 وتختفي صفحات الإدارة، ولا يُحذف أي بيانات ولا يتأثر نظام السلاسل التقليدي (creator_series_*).
| كل الحدود هنا قابلة للضبط بلا تعديل كود. لا شيء هنا يمسّ الاقتصاد (الجواهر/الخبرة) ولا المدفوعات.
*/
return [

    'enabled' => (bool) env('CINEMATIC_STORIES_ENABLED', false),

    // حدود البنية (سقوف معقولة قابلة للضبط، لا حدًّا صلبًا في الكود): تُفحص عند الإنشاء وعند فحص الجاهزية.
    'limits' => [
        'max_stages' => 12,
        'max_chapters_per_stage' => 12,
        'max_scenes_per_chapter' => 40,
        'max_scenes_per_revision' => 300,
        'max_text_length' => 4000,
        'max_config_bytes' => 65536,
    ],

    // الوسائط: تُخزَّن على قرص خاص (غير مخدوم مباشرة) وتُقدَّم فقط عبر مسار محروس. الامتداد لا يُوثَق به أبدًا.
    'media' => [
        'disk' => env('CINEMATIC_MEDIA_DISK', 'local'),
        'directory' => 'cinematic',
        // مدة صلاحية رابط التشغيل الموقَّع (ثوانٍ). الرابط وحده لا يكفي: يُفحص تسجيل الدخول والملكية عند كل طلب.
        'link_ttl_seconds' => 900,

        'video' => [
            'max_bytes' => 200 * 1024 * 1024,
            'max_duration_seconds' => 600,
            'max_width' => 1920,
            'max_height' => 1080,
            'video_codecs' => ['avc1', 'avc3'],   // H.264
            'audio_codecs' => ['mp4a'],           // AAC
            'brands' => ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash'],
            // moov قبل mdat يسمح بالبدء السريع وبطلبات Range؛ الاختلاف يُعلَّم تحذيرًا افتراضيًا ويُرفض إن فُعِّل هذا الخيار.
            'require_faststart' => false,
        ],

        'poster' => [
            'max_bytes' => 5 * 1024 * 1024,
            'max_width' => 3840,
            'max_height' => 2160,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        ],

        'subtitle' => [
            'max_bytes' => 512 * 1024,
        ],

        'audio' => [
            'max_bytes' => 25 * 1024 * 1024,
            'mimes' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a'],
        ],
    ],

    // اللعب: كل القرارات على الخادم. لا يوجد أي مسار يمنح جواهر أو خبرة أو رصيدًا من هنا.
    'play' => [
        // مشاهدة فيديو غير قابل للتخطي لا تُسجَّل مكتملة قبل مرور هذه النسبة من مدته فعليًا على ساعة الخادم منذ بدء المشهد (ليست إثبات أمان، بل منع النقر الأعمى).
        'min_watch_ratio' => 0.5,
        'min_watch_cap_seconds' => 120,
        // لمشاهد الأحجية/التفاعل: الحد الأقصى للمحاولات إن لم يحدده المشهد.
        'default_max_attempts' => 12,
    ],
];

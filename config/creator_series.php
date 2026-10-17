<?php

/*
| E30 (B) - سلاسل المبدعين: قوائم أحجيات مجتمع مرتّبة. محتوى مجتمع بلا أي عائد اقتصادي (لا جواهر/XP/مهام/سلسلة/صدارة/حملات)،
| ولا يتأثر أبدًا بتعزيز الرعاية. القيم قابلة للضبط بالبيئة.
*/
return [
    'enabled' => (bool) env('CREATOR_SERIES_ENABLED', true),

    'limits' => [
        'min_items' => (int) env('CREATOR_SERIES_MIN_ITEMS', 2),
        'max_items' => (int) env('CREATOR_SERIES_MAX_ITEMS', 25),
        'title_min' => 3,
        'title_max' => 100,
        'description_max' => 600,
        'slug_max' => 80,
        // سقف عدد السلاسل غير المؤرشفة للمبدع الواحد (حماية من الإغراق).
        'max_per_creator' => (int) env('CREATOR_SERIES_MAX_PER_CREATOR', 50),
        // سقف السلاسل قيد المراجعة للمبدع الواحد (مثل سقف أحجياته) كي لا تُغرَق قائمة المراجعة.
        'max_pending' => (int) env('CREATOR_SERIES_MAX_PENDING', 5),
        'note_min' => 5,
        'note_max' => 500,
    ],

    // حقول سلطة/اقتصاد/ظهور لا يحدّدها المبدع أبدًا؛ وجودها بالطلب يُرفض صراحة (لا يُتجاهل).
    'forbidden_fields' => [
        'status', 'creator_profile_id', 'slug', 'public_id', 'latest_revision_id', 'published_revision_id', 'published_title', 'published_items_count', 'published_at',
        'first_published_at', 'archived_at', 'hidden_at', 'hidden_by', 'hidden_reason', 'hidden_note', 'reviewed_by', 'reviewed_at', 'review_note', 'cover_path', 'is_visible',
        'gem_reward', 'xp_reward', 'reward', 'reward_currency_id', 'boost', 'sponsor_id', 'supporter_id', 'sponsorship_program_id', 'is_sponsored', 'is_official',
        'campaign_id', 'season_id', 'competitive_event_id', 'challenge_id',
    ],

    'page_size' => 12,
    'studio_page_size' => 15,
    'moderation_page_size' => 20,

    // غلاف السلسلة اختياري ويستعمل خدمة وسائط المبدعين نفسها (JPEG/PNG/WEBP فقط).
    'cover' => ['enabled' => true],

    'discovery' => [
        'section_limit' => 6,
        'per_creator_cap' => 2,
        'pool' => 30,
        // كاش معرّفات «أحدث السلاسل» فقط (الظهور يُفحص حيًّا بكل عرض؛ الكاش ليس سلطة).
        'cache_ttl' => (int) env('CREATOR_SERIES_DISCOVERY_TTL', 120),
    ],

    'analytics' => [
        'cache_ttl' => (int) env('CREATOR_SERIES_ANALYTICS_TTL', 600),
    ],

    'notifications' => [
        'on_new_series' => (bool) env('CREATOR_SERIES_NOTIFY_FOLLOWERS', true),
        // إشعار المتابعين بنسخة جديدة معتمدة لسلسلة نُشرت سابقًا (مثل engagement.notifications.on_revision).
        'on_revision' => (bool) env('CREATOR_SERIES_NOTIFY_ON_REVISION', true),
    ],
];

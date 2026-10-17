<?php

/*
| E27 - البحث العام والاكتشاف والرائج والتوصيات.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. السائق الحالي database (بلا محرك بحث خارجي)؛ التبديل لمحرك آخر = تنفيذ جديد لـ SearchBackend (docs/search-discovery-e27.md).
*/
return [
    // database = LIKE/تطبيع على قاعدة البيانات الحالية. أي قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع).
    'driver' => env('SEARCH_DRIVER', 'database'),

    // طول المصطلح بعد التطبيع (حروف لا بايتات). اللاعبون لهم حدّ أدنى أعلى للضيوف (يقلّل مسح الأسماء بالتخمين).
    'min_length' => (int) env('SEARCH_MIN_LENGTH', 2),
    'min_length_guest_players' => (int) env('SEARCH_MIN_LENGTH_GUEST_PLAYERS', 3),
    'max_length' => 64,
    'max_tokens' => 5,

    // النتائج: صفحة البحث الكاملة وتبويب النوع الواحد (ترقيم بسيط، بلا COUNT) وسقف عمق الصفحات.
    'all_per_type' => 5,
    'page_size' => 12,
    'max_page' => 5,
    'guest_max_page' => 3,

    // الاقتراحات الفورية (autocomplete): سقف لكل نوع وسقف كلي.
    'suggest_per_type' => 3,
    'suggest_total' => 9,

    // ترتيب الأنواع الافتراضي في النتائج (المجموعة التي فيها تطابق تام تتقدّم).
    'types' => ['puzzles', 'community', 'creator_series', 'campaigns', 'seasons', 'competitions', 'championships', 'teams', 'players'],

    // المطابقة الجزئية (بالوسط/بأي كلمة + طيّ الحروف العربية بـREPLACE) مسح كامل للجدول؛ لذا لا تُفعَّل إلا حين يكون أكبر معرّف بالجدول ≤ هذا الحدّ.
    // فوقه: بحث ببادئة العمود فقط (يستعمل الفهرس). هذا هو مؤشر الانتقال لمحرك بحث/FULLTEXT (انظر المستند).
    'partial_match_max_id' => (int) env('SEARCH_PARTIAL_MAX_ID', 10000),
    'partial_match_max_id_by_type' => [
        // 'players' => 20000,
    ],
    'partial_probe_ttl' => 600,

    // مراتب الصلة (الأصغر أعلى): تطابق تام ثم بادئة ثم بادئة كلمة ثم جزئي. لا تغيّرها دون تحديث الاختبارات.
    'rank_tiers' => ['exact' => 0, 'prefix' => 1, 'word_prefix' => 2, 'partial' => 3],

    'trending' => [
        'window_days' => (int) env('SEARCH_TRENDING_WINDOW_DAYS', 7),
        'cache_ttl' => (int) env('SEARCH_TRENDING_TTL', 300),
        // اللاعبون المميَّزون (لا عدد المحاولات): أوزان تراكمية لآخر يوم / 3 أيام / كامل النافذة، + وزن إضافي لمن حلّوا الأحجية.
        'recency_weights' => ['d1' => 4, 'd3' => 2, 'd7' => 1],
        'solver_weight' => 2,
        'min_unique_players' => (int) env('SEARCH_TRENDING_MIN_PLAYERS', 2),
        'limit' => 8,
        'pool' => 40,
        'binary_search_steps' => 40,
    ],

    'recommendations' => [
        'limit' => 8,
        'pool' => 60,
        'history_cap' => 500,
        'exploration_share' => 0.25,
        'new_content_days' => 14,
        'weights' => ['category' => 5.0, 'difficulty' => 2.0, 'popularity' => 1.5, 'novelty' => 1.0],
    ],

    'discover' => [
        'section_limit' => 8,
        'season_limit' => 4,
        'campaign_limit' => 6,
        'event_limit' => 6,
        'team_limit' => 6,
    ],
];

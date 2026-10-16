<?php

/*
| E28 - منظومة المبدعين ونشر أحجيات المجتمع.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. لا مكافآت اقتصادية لأحجيات المجتمع بأي إعداد (انظر docs/creator-ecosystem-e28.md).
*/
return [

    /*
    | قائمة السماح بأنواع الأحجيات التي يستطيع المبدع إنشاءها. كل مفتاح يربط بتعريف المحرك القائم (game_type) ونوع الأحجية الكلاسيكي (type).
    | المبدع لا يختار renderer ولا validation_type ولا score_mode: GameTypeRegistry::defaultsFor() يشتقّها سيرفريًا من game_type.
    | أنواع تحتاج GameSession أو محرر نقاط (spot_difference) مؤجَّلة عمدًا: انظر 'deferred' والمستند.
    */
    'kinds' => [
        'text' => ['label' => 'جواب كتابي', 'type' => 'text', 'game_type' => null, 'media' => false],
        'multiple_choice' => ['label' => 'اختيار من متعدد', 'type' => 'multiple_choice', 'game_type' => null, 'media' => false],
        'image' => ['label' => 'أحجية بصورة', 'type' => 'image', 'game_type' => null, 'media' => true],
        'sequence' => ['label' => 'ترتيب تسلسل', 'type' => 'text', 'game_type' => 'sequence', 'media' => false],
        'memory' => ['label' => 'بطاقات الذاكرة', 'type' => 'text', 'game_type' => 'memory', 'media' => false],
    ],

    'deferred' => [
        'spot_difference' => 'يحتاج GameSession ومحرر نقاط الفروق وصورتين مرتبطتين؛ مؤجَّل إلى مرحلة لاحقة.',
    ],

    'difficulties' => ['easy', 'medium', 'hard'],

    /*
    | حقول سلطة ممنوعة على المبدع مهما كانت: وجودها بالطلب (حتى فارغة) يُرفض بخطأ تحقق صريح بدل تجاهلها بصمت (يكشف محاولات التلاعب ويثبتها الاختبارات).
    | كلها إما اقتصاد/تقدّم/ربط رسمي (مكافآت، يومية، حملات، منافسات) أو مشتقّات المحرك السيرفرية (renderer/validation_type/score_mode/game_type) أو حقول الحالة والملكية.
    */
    'forbidden_fields' => [
        'gem_reward', 'xp_reward', 'reward_currency_id', 'is_daily_puzzle', 'daily_puzzle_date', 'is_active',
        'campaign_id', 'campaign_step_id', 'campaign_gate_id', 'season_id', 'competition_id', 'competitive_event_id', 'challenge_id',
        'renderer', 'validation_type', 'score_mode', 'game_type', 'game_config', 'solution_data', 'answer_hash', 'answer_raw',
        'max_attempts', 'time_limit_seconds', 'origin', 'type', 'image_path', 'status', 'creator_profile_id', 'puzzle_id',
        'hidden_at', 'archived_at', 'is_visible', 'published_at', 'reviewed_by', 'review_note',
    ],

    // حدود المحتوى (حروف). النصوص تُخزَّن نصًا عاديًا وتُعرض بـBlade المهرَّب؛ ووسوم HTML مرفوضة عند الإدخال أصلًا.
    'limits' => [
        'title_min' => 3,
        'title_max' => 100,
        'prompt_min' => 10,
        'prompt_max' => 600,
        'hint_max' => 200,
        'answer_max' => 100,
        'bio_min' => 20,
        'bio_max' => 600,
        'reason_max' => 600,
        'choice_max' => 80,
        'choices_min' => 2,
        'choices_max' => 6,
        'sequence_min' => 3,
        'sequence_max' => 8,
        'sequence_item_max' => 80,
        'memory_min' => 3,
        'memory_max' => 12,
        'memory_face_max' => 24,
        'note_min' => 5,
        'note_max' => 500,
        'report_details_max' => 500,
    ],

    // ثوابت سيرفرية لأحجية المجتمع (لا يتحكم بها المبدع): بلا مكافأة، بلا مؤقّت، عدد محاولات ثابت.
    'puzzle' => [
        'max_attempts' => (int) env('CREATOR_PUZZLE_MAX_ATTEMPTS', 3),
        'gem_reward' => 0,
        'xp_reward' => 0,
    ],

    // سقوف لكل مبدع تمنع الإغراق (حدود أخرى بالمحدِّدات المسمّاة في AppServiceProvider).
    'caps' => [
        'works' => (int) env('CREATOR_MAX_WORKS', 100),
        'open_drafts' => (int) env('CREATOR_MAX_OPEN_DRAFTS', 20),
        'pending_review' => (int) env('CREATOR_MAX_PENDING', 5),
    ],

    'media' => [
        'disk' => 'public',
        'directory' => 'community/puzzles',
        'max_kb' => (int) env('CREATOR_IMAGE_MAX_KB', 4096),
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'max_dimension' => 4096,
        'min_dimension' => 100,
    ],

    'page_size' => 12,
    'moderation_page_size' => 20,

    'analytics' => [
        'cache_ttl' => (int) env('CREATOR_ANALYTICS_TTL', 120),
        'recent_days' => 7,
        'recent_limit' => 10,
    ],

    // E27: أحجيات المجتمع بالاكتشاف. سقف أحجيات المبدع الواحد بالقسم والتوصيات (تنوّع المبدعين).
    'discovery' => [
        'section_limit' => 8,
        'per_creator_cap' => 2,
        'reco_limit' => 4,
        'reco_pool' => 40,
        'trending_min_players' => (int) env('CREATOR_TRENDING_MIN_PLAYERS', 2),
    ],
];

<?php

/*
| E35 - المجموعات العامة، الأمناء المجتمعيون، الأرفف التحريرية والقوائم القابلة للمشاركة.
|
| قواعد مغلقة (لا تُضبط بأي إعداد هنا):
|  - لا محرك مجموعات ثانٍ: UserCollection (E29) تتطوّر إلى private|unlisted|public، والعناصر أعمال مبدعين (CreatorPuzzle) ظاهرة فقط.
|  - **صفر اقتصاد**: لا جواهر ولا XP ولا مهام ولا سلسلة ولا إنجازات للنشر أو الحفظ أو الإبراز أو البلاغ. لا ترتيب مدفوع.
|  - الإخفاء الإشرافي والحظر والتجميد وخصوصية المالك تسبق أي كاش (يُفحص الظهور حيًّا بكل قراءة).
|  - لا تتبّع مشاهدات للمجموعات ولا هوية لمن حفظ.
| كل القيم تُضبط بالبيئة (COLLECTIONS_*) ولا تحوي أسرارًا. التفاصيل: docs/public-collections-editorial-e35.md
*/
return [

    'publish' => [
        // أقل عدد أعمال ظاهرة لجعل المجموعة «عامة» (قابلة للظهور بالبحث والاكتشاف). غير المدرجة بلا حدّ أدنى (رابط خاص لمن يشاركه المالك).
        'min_visible_items_public' => (int) env('COLLECTIONS_PUBLISH_MIN_ITEMS', 1),
    ],

    // الصفحة العامة: عدد الأعمال بكل صفحة.
    'public_page' => [
        'items_per_page' => (int) env('COLLECTIONS_PUBLIC_ITEMS_PER_PAGE', 12),
    ],

    'saves' => [
        // سقف ما يحفظه المستخدم الواحد: يحدّ صفحة «المحفوظات» ويمنع الإغراق. تعديله لا يمسّ المحفوظ.
        'max_per_user' => (int) env('COLLECTIONS_MAX_SAVES', 300),
        'per_page' => 12,
    ],

    'search' => [
        'min_visible_items' => (int) env('COLLECTIONS_SEARCH_MIN_ITEMS', 1),
    ],

    /*
    | قسم «مجموعات من المجتمع» في الاكتشاف.
    |
    |   score = w_saves * ln(1 + saves_count) + w_recency * max(0, 1 - أيام_منذ_آخر_تحديث / recent_days) + w_items * min(عدد_الأعمال_الظاهرة, 10) / 10
    |
    | saves_count = عدد حافظين **مميَّزين** (قيد فريد مستخدم/مجموعة) فلا تضخيم بتكرار الحفظ. لا مشاهدات خام. المجموعة يجب أن تكون عامة ∧ غير مخفية ∧ مالكها مؤهل ∧ ملفه عام
    | ∧ فيها ≥ min_visible_items عملًا ظاهرًا. سقف لكل أمين (per_owner_cap) فلا يحتكر القسم أمين واحد. الكاش يحمل معرّفات+درجات فقط والظهور يُعاد فحصه حيًّا بكل طلب.
    */
    'discover' => [
        'min_visible_items' => (int) env('COLLECTIONS_DISCOVER_MIN_ITEMS', 3),
        'per_owner_cap' => (int) env('COLLECTIONS_DISCOVER_PER_OWNER', 1),
        'section_limit' => (int) env('COLLECTIONS_DISCOVER_LIMIT', 8),
        'pool' => 60,
        'cache_ttl' => (int) env('COLLECTIONS_DISCOVER_CACHE_TTL', 300),
        'recent_days' => 30,
        'weights' => ['saves' => 3.0, 'recency' => 1.5, 'items' => 1.0],
    ],

    // قسم «مجموعات» بملف اللاعب العام.
    'profile' => [
        'section_limit' => (int) env('COLLECTIONS_PROFILE_LIMIT', 6),
    ],

    'reports' => [
        'moderation_page_size' => 20,
        'note_min' => 3,
        'note_max' => 255,
    ],

    // حدود المعدّل (لكل مستخدم/دقيقة). المحدِّدات المسماة في AppServiceProvider.
    'limits' => [
        'publish_per_minute' => (int) env('COLLECTIONS_PUBLISH_PER_MINUTE', 10),
        'save_per_minute' => (int) env('COLLECTIONS_SAVE_PER_MINUTE', 30),
        'report_per_minute' => (int) env('COLLECTIONS_REPORT_PER_MINUTE', 5),
        'reorder_per_minute' => (int) env('COLLECTIONS_REORDER_PER_MINUTE', 20),
        'read_per_minute' => (int) env('COLLECTIONS_READ_PER_MINUTE', 120),
    ],

    'analytics' => [
        'cache_seconds' => (int) env('COLLECTIONS_ANALYTICS_CACHE_SECONDS', 300),
        'top_limit' => 10,
    ],

    /*
    | الأرفف التحريرية (فريق المنصة فقط). المواضع المسموحة: discover + home. الحدّ الأقصى للعناصر مسقوف هنا مهما أدخل المشرف.
    */
    'shelves' => [
        'cache_seconds' => (int) env('COLLECTIONS_SHELVES_CACHE_SECONDS', 60),
        'max_items_cap' => 24,
        'default_max_items' => 8,
        'max_per_placement' => (int) env('COLLECTIONS_SHELVES_PER_PLACEMENT', 3),
    ],
];

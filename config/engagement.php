<?php

/*
| E29 - التفاعل المجتمعي: التقييمات، المفضلة، متابعة المبدعين، خلاصة المتابَعين، المجموعات الشخصية، والأعلى تقييمًا.
|
| قاعدة مغلقة: التفاعل **بلا سلطة اقتصادية**. لا جواهر ولا XP ولا مهام ولا سلسلة ولا إنجازات ولا ترتيب مدفوع بأي إعداد هنا.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. التفاصيل: docs/community-engagement-e29.md
*/
return [

    'ratings' => [
        'min' => 1,
        'max' => 5,

        // أقل عدد تقييمات لإظهار «المتوسط» علنًا. تحته يُعرض العدد فقط (عيّنة صغيرة لا تُسوَّق كـ«5.0 ★»).
        'display_min' => (int) env('ENGAGEMENT_RATING_DISPLAY_MIN', 3),
    ],

    /*
    | الأعلى تقييمًا: درجة انكماش بايزية بسيطة وقابلة للشرح، لا «المتوسط الخام» أبدًا:
    |
    |   score = (ratings_sum + prior_mean * prior_weight) / (ratings_count + prior_weight)
    |
    | prior_mean   = متوسط افتراضي «محايد» نسحب نحوه الأعمال قليلة التقييم.
    | prior_weight = كم «تقييمًا وهميًا» بمتوسط prior_mean نضيفه لكل عمل. كلما كبر صار الوصول للقمة يتطلب تقييمات أكثر.
    | min_ratings  = لا يدخل الترتيب عملٌ بأقل من هذا العدد مهما كان متوسطه.
    | مثال: خمسة تقييمات 5 → 4.25، بينما 500 تقييم بمتوسط 4.8 → 4.79: الكثرة الموثوقة تتفوق على العيّنة الصغيرة.
    */
    'top_rated' => [
        'min_ratings' => (int) env('ENGAGEMENT_TOP_MIN_RATINGS', 5),
        'prior_mean' => (float) env('ENGAGEMENT_TOP_PRIOR_MEAN', 3.5),
        'prior_weight' => (float) env('ENGAGEMENT_TOP_PRIOR_WEIGHT', 5),
        'pool' => 40,                                                   // أقصى عدد معرّفات تُخزَّن بالكاش
        'cache_ttl' => (int) env('ENGAGEMENT_TOP_CACHE_TTL', 300),      // معرّفات + درجات فقط؛ الظهور يُفحص حيًّا بكل طلب
    ],

    // ملخّص جودة المبدع بصفحته العامة: لا يظهر المتوسط إلا ببلوغ هذا العدد الإجمالي من التقييمات على أعماله الظاهرة.
    'creator_quality' => [
        'min_ratings' => (int) env('ENGAGEMENT_CREATOR_MIN_RATINGS', 5),
    ],

    'follows' => [
        // سقف لما يتابعه المستخدم الواحد: يحدّ استعلام الخلاصة ويمنع الإغراق. تعديله لا يمسّ البيانات القائمة.
        'max_per_user' => (int) env('ENGAGEMENT_MAX_FOLLOWS', 500),
    ],

    'collections' => [
        'max_per_user' => (int) env('ENGAGEMENT_MAX_COLLECTIONS', 20),
        'max_items' => (int) env('ENGAGEMENT_MAX_COLLECTION_ITEMS', 100),
        'title_min' => 2,
        'title_max' => 60,
        'description_max' => 300,
    ],

    'pagination' => [
        'favorites' => 12,
        'following' => 12,
        'collections' => 12,
        'collection_items' => 12,
    ],

    /*
    | إشعار «نشر جديد» لمتابعي المبدع. فئة social الموجودة (تفضيل social_enabled) بلا نظام تفضيلات ثانٍ.
    | on_revision: هل تُشعَر المتابعون أيضًا باعتماد نسخة جديدة لعمل منشور سابقًا (لا فقط بأول نشر).
    | الإرسال دفعات بمؤشر id عبر الطابور (حجم الدفعة من player_notifications.chunk_size)؛ لا حلقة واحدة على كل المتابعين.
    */
    'notifications' => [
        'on_revision' => (bool) env('ENGAGEMENT_NOTIFY_ON_REVISION', true),
    ],

    // تكامل الاكتشاف (E27/E28). الإشارات ضعيفة ولا تحلّ محل الاستكشاف: مجمَّع القراءة نفسه، وسقف أعمال المبدع نفسه.
    'discovery' => [
        'following_limit' => 4,
        'top_rated_limit' => 8,
        'affinity_min_rating' => 4,     // تقييم المشاهد ≥ هذا يعدّ إشارة تفضيل لتصنيف العمل
    ],
];

<?php

/*
| E34 - مركز العمليات الحيّة والاحتفاظ باللاعبين (LiveOps & Retention).
|
| قواعد مغلقة (لا تُضبط بأي إعداد هنا):
|  - لا محرك مهام/سلسلة/إنجازات ثانٍ: الإسناد يحدّد **الظهور** فقط، والتقدّم والمكافأة يبقيان في QuestService/ProgressionRewardService.
|  - لا ضغط نفسي ولا استعجال مصطنع ولا عقوبة على غياب يوم، ولا حماية سلسلة مدفوعة، ولا استرداد لمكافأة سبق اكتسابها.
|  - أحجيات المجتمع وسلاسل المبدعين صفرية الاقتصاد؛ نقرات الإحالة والدعم والدفع لا تُحسب تقدّمًا.
|  - مكافأة العودة (Comeback) بجواهر غير موجودة أصلًا: لا مفتاح لها هنا عمدًا.
| كل القيم أدناه تُضبط بالبيئة (LIVEOPS_*) ولا تحوي أسرارًا. التفاصيل: docs/liveops-retention-e34.md
*/
return [

    /*
    | مجموعات المهام (Mission Pools) والتدوير الحتمي لكل مستخدم.
    | الاختيار حتمي: sha1(user_id | period_key | pool_id) + sha1(seed | quest_id) - بلا random() ثم بلا أي حالة خفية.
    */
    'pools' => [
        // كاش تعريفات المجموعات/العضوية المشتركة (لا يحوي بيانات مستخدم). يُفرَّغ فور أي تعديل إداري.
        'cache_seconds' => (int) env('LIVEOPS_POOL_CACHE_SECONDS', 300),

        // قواعد الأهلية المغلقة (MissionEligibilityRule)
        'new_player_days' => (int) env('LIVEOPS_NEW_PLAYER_DAYS', 14),
        'experienced_level' => (int) env('LIVEOPS_EXPERIENCED_LEVEL', 5),

        // التخصيص قائم على قواعد شفافة فقط (فئات رسمية حديثة + حملة/موسم + تقدّم غير مكتمل)، لا ML ولا بيانات خاصة.
        'personalization' => (bool) env('LIVEOPS_PERSONALIZATION', true),
        'recent_category_days' => (int) env('LIVEOPS_RECENT_CATEGORY_DAYS', 14),

        // سقف أمان لعدد المهام التي تسندها مجموعة واحدة لمستخدم واحد في فترة واحدة.
        'max_selection' => 10,
    ],

    /*
    | تقويم الأحداث (/events): لقطة عامة مشتركة (مصفوفات بسيطة) تُحسب حالتها وقت القراءة.
    */
    'calendar' => [
        'cache_seconds' => (int) env('LIVEOPS_CALENDAR_CACHE_SECONDS', 60),
        'ending_soon_hours' => (int) env('LIVEOPS_ENDING_SOON_HOURS', 24),
        'upcoming_days' => (int) env('LIVEOPS_UPCOMING_DAYS', 60),
        'per_source_limit' => (int) env('LIVEOPS_CALENDAR_SOURCE_LIMIT', 30),
        'agenda_max' => (int) env('LIVEOPS_AGENDA_MAX', 5),
    ],

    /*
    | معالم السلسلة اليومية (أيام). تُنفَّذ كإنجازات (Achievement) من نوع streak_longest_days بلا محرك جديد؛
    | المصدر الموثوق longest_streak في PlayerStreak. القيم هنا للقراءة/البذر/العرض؛ المنح عبر نظام الإنجازات.
    */
    'streak_milestones' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('LIVEOPS_STREAK_MILESTONES', '3,7,14,30,60,100'))),
        fn (int $d) => $d > 0,
    )),

    /*
    | العودة (Comeback): غياب عن **لعب رسمي موثوق** (لا تسجيل دخول) بمقدار min_inactive_days يومًا أو أكثر.
    | تزول الحالة تلقائيًا عند أول حل رسمي صحيح (last_active_date يتحدّث). بلا مكافأة جواهر افتراضيًا.
    */
    'comeback' => [
        'min_inactive_days' => (int) env('LIVEOPS_COMEBACK_MIN_DAYS', 7),
        // بعد هذا الحد لا نرسل إشعارًا (اللاعب غائب منذ زمن طويل؛ لا إلحاح). البطاقة داخل المنصة تبقى.
        'notify_max_days' => (int) env('LIVEOPS_COMEBACK_NOTIFY_MAX_DAYS', 45),
    ],

    /*
    | إشعارات LiveOps: تعيد استخدام E15 كاملًا (Dispatcher + تفضيلات الفئة). لا فئة جديدة ولا استعادة إلحاحية.
    | الافتراضات متحفظة: العودة والمهام الأسبوعية متوقفتان؛ تنبيه انتهاء المهمة وبدء الحدث مفعّلان.
    */
    'notifications' => [
        'mission_expiring' => (bool) env('LIVEOPS_NOTIFY_MISSION_EXPIRING', true),
        'weekly_missions_available' => (bool) env('LIVEOPS_NOTIFY_WEEKLY_MISSIONS', false),
        'event_starting_soon' => (bool) env('LIVEOPS_NOTIFY_EVENT_STARTING', true),
        'comeback_available' => (bool) env('LIVEOPS_NOTIFY_COMEBACK', false),

        // سقف يومي لإشعارات LiveOps لكل مستخدم (فوق ميزانية E15 للتذكيرات؛ الأقل يسود).
        'daily_cap' => (int) env('LIVEOPS_NOTIFY_DAILY_CAP', 1),
        'chunk_size' => (int) env('LIVEOPS_NOTIFY_CHUNK', 200),

        // تنبيه "تنتهي مهمتك اليوم": يُرسل فقط للمهام الجارية غير المكتملة، وفي هذه الساعات الأخيرة من اليوم (بتوقيت المنصة).
        'mission_expiring_hours_before_end' => (int) env('LIVEOPS_MISSION_EXPIRING_HOURS', 4),
        'event_starting_window_hours' => (int) env('LIVEOPS_EVENT_STARTING_HOURS', 24),
    ],

    /*
    | ميزانية الأمان للجدولة: تحذيرات (لا تمنع إجراءً إداريًا صالحًا) وحدّ صلب واحد لعدد المهام الفعّالة المتزامنة.
    | لا نموذج مالي مخترَع: المقارنة بمجموع جواهر المكافآت المُعلَنة فقط. الرعاية لا تُطبَّق على المهام تلقائيًا.
    */
    'budget' => [
        'max_concurrent_missions_warn' => (int) env('LIVEOPS_MAX_CONCURRENT_WARN', 12),
        'max_concurrent_missions_hard' => (int) env('LIVEOPS_MAX_CONCURRENT_HARD', 40),
        'daily_gems_warn' => (int) env('LIVEOPS_DAILY_GEMS_WARN', 150),
        'weekly_gems_warn' => (int) env('LIVEOPS_WEEKLY_GEMS_WARN', 600),
    ],

    // حدّ معدّل إجراءات LiveOps الإدارية لكل مشرف (صلاحية liveops.manage + تدقيق + تفريغ كاش).
    'limits' => [
        'action_per_minute' => (int) env('LIVEOPS_ACTION_PER_MINUTE', 30),
    ],

    /*
    | تحليلات الاحتفاظ: مُجمَّعة فقط، على اللعب الرسمي الصحيح (لا الدخول)، بلا SDK خارجي.
    */
    'analytics' => [
        'cache_seconds' => (int) env('LIVEOPS_ANALYTICS_CACHE_SECONDS', 300),
        'window_days' => (int) env('LIVEOPS_ANALYTICS_WINDOW_DAYS', 30),
        'cohort_min_size' => (int) env('LIVEOPS_COHORT_MIN_SIZE', 5),
    ],
];

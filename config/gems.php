<?php

// كل قيم اقتصاد الجواهر مركزية هنا بدل ما تكون متفرقة بالكود،
// عشان تقدروا تعدلوها بسهولة من .env بدون لمس أي منطق برمجي.
return [

    // كم جوهرة تعادل وحدة استبدال داخلية واحدة
    'per_redemption_unit' => (int) env('GEMS_PER_REDEMPTION_UNIT', 1000),

    // أقل عدد جواهر يسمح بطلب استبدال عنده
    'min_redemption' => (int) env('GEMS_MIN_REDEMPTION', 10000),

    // كم يوم تبقى الجوهرة "معلقة" قبل ما تصير "متاحة" للاستبدال
    'pending_hold_days' => (int) env('GEMS_PENDING_HOLD_DAYS', 5),

    // سقف الجواهر اللي يقدر المستخدم يكسبها خلال 24 ساعة (مكافحة احتيال)
    'daily_earn_cap' => (int) env('GEMS_DAILY_EARN_CAP', 200),

    // جواهر كل مستوى صعوبة لغز
    'rewards' => [
        'easy' => 5,
        'medium' => 10,
        'hard' => 20,
        'daily' => 15,
        'weekly_challenge' => 50,
    ],

    // كلفة فتح التلميح بالجواهر (مصرف/sink لمنع تضخم الرصيد)
    'hint_cost' => 3,

    // بعد كم يوم من التسجيل + كم لغز محلول يصير المستخدم مؤهل لأول استبدال
    'eligibility' => [
        'min_account_age_days' => 3,
        'min_puzzles_solved' => 5,
    ],
];

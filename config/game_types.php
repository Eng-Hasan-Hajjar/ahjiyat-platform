<?php

// تسجيل مركزي لكل مكوّنات محرك الألعاب - إضافة نوع لعبة جديد لاحقاً يعني
// إضافة سطر هون (وربما صنف Validator/ScoreCalculator جديد إذا ما كان
// موجود نوع تحقق مشابه أصلاً)، بدون تعديل أي كود موجود.
return [

    // كل validation_type - القيمة صنف ينفّذ App\GameEngine\Contracts\GameValidator
    'validators' => [
        'exact_string' => \App\GameEngine\Validators\ExactStringValidator::class,
        'sequence_match' => \App\GameEngine\Validators\SequenceMatchValidator::class,
    ],

    // كل score_mode - القيمة صنف ينفّذ App\GameEngine\Contracts\ScoreCalculator
    'scorers' => [
        'flat' => \App\GameEngine\Scoring\FlatScoreCalculator::class,
    ],

    // تعريف كل game_type جديد (الأنواع الكلاسيكية الثلاثة القديمة لا تحتاج
    // تسجيل هون - game_type يبقى فارغاً لهم وتُطبَّق الافتراضيات تلقائياً)
    'game_types' => [
        'sequence' => [
            'label' => 'ترتيب تسلسل (Sequence)',
            'validation_type' => 'sequence_match',
            'score_mode' => 'flat',
            'renderer' => 'games.sequence',
        ],
    ],

];
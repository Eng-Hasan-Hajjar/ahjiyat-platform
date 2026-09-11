<?php

// تسجيل مركزي لكل مكوّنات محرك الألعاب - إضافة نوع لعبة جديد لاحقاً يعني
// إضافة سطر هون (وربما صنف Validator/ScoreCalculator جديد إذا ما كان
// موجود نوع تحقق مشابه أصلاً)، بدون تعديل أي كود موجود.
return [

    'validators' => [
        'exact_string' => \App\GameEngine\Validators\ExactStringValidator::class,
        'sequence_match' => \App\GameEngine\Validators\SequenceMatchValidator::class,
        'memory_match' => \App\GameEngine\Validators\MemoryMatchValidator::class,
    ],

    'scorers' => [
        'flat' => \App\GameEngine\Scoring\FlatScoreCalculator::class,
    ],

    'game_types' => [
        'sequence' => [
            'label' => 'ترتيب تسلسل (Sequence)',
            'validation_type' => 'sequence_match',
            'score_mode' => 'flat',
            'renderer' => 'games.sequence',
        ],
        'memory' => [
            'label' => 'بطاقات الذاكرة (Memory)',
            'validation_type' => 'memory_match',
            'score_mode' => 'flat',
            'renderer' => 'games.memory',
        ],
    ],

];
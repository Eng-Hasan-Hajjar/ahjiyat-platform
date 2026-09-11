<?php

// تسجيل مركزي لكل أنواع الألعاب المدعومة - إضافة نوع جديد لاحقاً تعني
// إنشاء صنف Definition واحد (وربما Validator إن لم يوجد نوع تحقق مشابه)
// + سطر تسجيل هون، بدون تعديل أي كود موجود (GameTypeRegistry، PuzzleResource...).
return [

    // المفتاح '' يمثّل الأنواع الكلاسيكية الثلاثة (game_type = null بقاعدة البيانات)
    'definitions' => [
        '' => \App\GameEngine\Definitions\LegacyGameTypeDefinition::class,
        'sequence' => \App\GameEngine\Definitions\SequenceGameTypeDefinition::class,
        'memory' => \App\GameEngine\Definitions\MemoryGameTypeDefinition::class,
    ],

    // خاص بواجهة Filament تحديداً - أي واجهة تأليف مستقبلية (Sponsor Portal...)
    // تسجّل خريطتها الخاصة بشكل منفصل، بدون أن يعتمد محرك الألعاب نفسه عليها.
    'filament_schemas' => [
        'sequence' => \App\Filament\GameTypeAuthoring\SequenceFilamentSchema::class,
        'memory' => \App\Filament\GameTypeAuthoring\MemoryFilamentSchema::class,
    ],

];
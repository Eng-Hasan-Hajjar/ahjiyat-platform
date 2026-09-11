<?php

namespace App\Filament\GameTypeAuthoring;

/**
 * يجمع حقول التأليف الخاصة بكل نوع لعبة مسجَّل، دون أن يعرف PuzzleResource
 * شيئًا عن أي نوع بالاسم. إضافة نوع لعبة جديد لاحقاً = صنف Schema جديد +
 * سطر تسجيل بـ config/game_types.php - صفر تعديل على PuzzleResource.
 */
class FilamentGameTypeSchemaRegistry
{
    /** @return array<int, \Filament\Forms\Components\Component> */
    public function allFields(): array
    {
        return collect(config('game_types.filament_schemas', []))
            ->flatMap(fn (string $class) => app($class)->fields())
            ->all();
    }
}
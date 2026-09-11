<?php

namespace App\Filament\GameTypeAuthoring;

use App\Filament\GameTypeAuthoring\Contracts\FilamentGameTypeSchema;
use Filament\Forms;
use Filament\Forms\Get;

class SequenceFilamentSchema implements FilamentGameTypeSchema
{
    public function fields(): array
    {
        return [
            Forms\Components\Repeater::make('game_config.items')
                ->label('عناصر التسلسل (رتّبها من الأعلى للأسفل بالترتيب الصحيح)')
                ->simple(Forms\Components\TextInput::make('item')->required())
                ->visible(fn (Get $get) => $get('game_type') === 'sequence')
                ->minItems(2)
                ->columnSpanFull(),
        ];
    }
}
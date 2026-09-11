<?php

namespace App\Filament\GameTypeAuthoring;

use App\Filament\GameTypeAuthoring\Contracts\FilamentGameTypeSchema;
use Filament\Forms;
use Filament\Forms\Get;

class MemoryFilamentSchema implements FilamentGameTypeSchema
{
    public function fields(): array
    {
        return [
            Forms\Components\Repeater::make('game_config.faces')
                ->label('عناصر أزواج الذاكرة (كل عنصر مرة واحدة فقط - سيتكرر تلقائياً بطاقتين)')
                ->simple(Forms\Components\TextInput::make('face')->required())
                ->visible(fn (Get $get) => $get('game_type') === 'memory')
                ->minItems(3)
                ->columnSpanFull(),
        ];
    }
}
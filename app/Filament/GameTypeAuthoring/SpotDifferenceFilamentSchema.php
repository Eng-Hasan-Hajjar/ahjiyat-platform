<?php

namespace App\Filament\GameTypeAuthoring;

use App\Filament\GameTypeAuthoring\Contracts\FilamentGameTypeSchema;
use Filament\Forms;
use Filament\Forms\Get;

class SpotDifferenceFilamentSchema implements FilamentGameTypeSchema
{
    public function fields(): array
    {
        $visible = fn (Get $get) => $get('game_type') === 'spot_difference';

        return [
            Forms\Components\FileUpload::make('game_config.image_before')
                ->label('الصورة الأولى')
                ->image()
                ->directory('puzzles/spot-difference')
                ->visible($visible)
                ->required($visible)
                ->columnSpan(1),

            Forms\Components\FileUpload::make('game_config.image_after')
                ->label('الصورة الثانية (فيها الفروق)')
                ->image()
                ->directory('puzzles/spot-difference')
                ->visible($visible)
                ->required($visible)
                ->columnSpan(1),

            Forms\Components\Repeater::make('solution_data.hotspots')
                ->label('مواقع الفروق الصحيحة (إحداثيات نسبية 0 إلى 1 - لا تظهر للاعب أبداً)')
                ->schema([
                    Forms\Components\TextInput::make('x')
                        ->label('X (يمين/يسار)')
                        ->numeric()
                        ->minValue(0)->maxValue(1)->step(0.01)
                        ->required(),
                    Forms\Components\TextInput::make('y')
                        ->label('Y (فوق/تحت)')
                        ->numeric()
                        ->minValue(0)->maxValue(1)->step(0.01)
                        ->required(),
                    Forms\Components\TextInput::make('radius')
                        ->label('نصف قطر التسامح')
                        ->numeric()
                        ->minValue(0.01)->maxValue(0.5)->step(0.01)
                        ->default(0.05)
                        ->required(),
                ])
                ->columns(3)
                ->visible($visible)
                ->minItems(1)
                ->columnSpanFull(),
        ];
    }
}
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChallengeResource\Pages;
use App\Models\Challenge;
use App\Models\Puzzle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChallengeResource extends Resource
{
    protected static ?string $model = Challenge::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'التحديات والبطولات';

    protected static ?string $modelLabel = 'تحدي';

    protected static ?string $pluralModelLabel = 'التحديات والبطولات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required(),
            Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),
            Forms\Components\Select::make('type')->label('النوع')
                ->options(['weekly' => 'تحدي أسبوعي', 'tournament' => 'بطولة'])->required(),
            Forms\Components\DateTimePicker::make('starts_at')->label('يبدأ في')->required(),
            Forms\Components\DateTimePicker::make('ends_at')->label('ينتهي في')->required(),
            Forms\Components\TextInput::make('bonus_gem_pool')->label('مجموع جواهر المكافأة')->numeric()->default(0),
            Forms\Components\Select::make('puzzles')->label('الأحجيات المرتبطة')
                ->relationship('puzzles', 'title')->multiple()->preload()->searchable(),
            Forms\Components\Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('type')->label('النوع'),
                Tables\Columns\TextColumn::make('starts_at')->label('البداية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('ends_at')->label('النهاية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('participants_count')->label('المشاركون')->counts('participants'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChallenges::route('/'),
            'create' => Pages\CreateChallenge::route('/create'),
            'edit' => Pages\EditChallenge::route('/{record}/edit'),
        ];
    }
}

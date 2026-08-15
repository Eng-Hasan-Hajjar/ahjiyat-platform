<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PuzzleResource\Pages;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PuzzleResource extends Resource
{
    protected static ?string $model = Puzzle::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'الأحجيات';

    protected static ?string $modelLabel = 'أحجية';

    protected static ?string $pluralModelLabel = 'الأحجيات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('puzzle_category_id')
                ->label('التصنيف')
                ->options(PuzzleCategory::pluck('name', 'id'))
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),

            Forms\Components\Select::make('type')
                ->label('نوع الأحجية')
                ->options([
                    'text' => 'نصية',
                    'image' => 'صورة',
                    'multiple_choice' => 'اختيار من متعدد',
                ])
                ->live()
                ->required(),

            Forms\Components\Select::make('difficulty')
                ->label('مستوى الصعوبة')
                ->options(['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'])
                ->live()
                ->afterStateUpdated(fn (Get $get, $state, Forms\Set $set) => $set('gem_reward', config("gems.rewards.$state", 5)))
                ->required(),

            Forms\Components\Textarea::make('prompt')->label('نص السؤال')->required()->columnSpanFull(),

            Forms\Components\FileUpload::make('image_path')
                ->label('صورة الأحجية')
                ->image()
                ->directory('puzzles')
                ->visible(fn (Get $get) => $get('type') === 'image'),

            Forms\Components\Repeater::make('choices')
                ->label('الخيارات')
                ->simple(Forms\Components\TextInput::make('choice')->required())
                ->visible(fn (Get $get) => $get('type') === 'multiple_choice')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('answer_raw')
                ->label('الإجابة الصحيحة')
                ->helperText('لا تُخزَّن بشكل صريح - تُحوَّل تلقائياً إلى بصمة (hash) عند الحفظ.')
                ->required(fn (string $context) => $context === 'create')
                ->dehydrated(fn ($state) => filled($state)),

            Forms\Components\Textarea::make('hint')->label('التلميح (اختياري)')->columnSpanFull(),

            Forms\Components\TextInput::make('max_attempts')->label('عدد المحاولات المسموح')->numeric()->default(3)->required(),
            Forms\Components\TextInput::make('time_limit_seconds')->label('مؤقت زمني (ثانية، اختياري)')->numeric(),
            Forms\Components\TextInput::make('gem_reward')->label('مكافأة الجواهر')->numeric()->required(),

            Forms\Components\Toggle::make('is_daily_puzzle')->label('أحجية اليوم')->live(),
            Forms\Components\DatePicker::make('daily_puzzle_date')
                ->label('تاريخ أحجية اليوم')
                ->visible(fn (Get $get) => $get('is_daily_puzzle')),

            Forms\Components\Toggle::make('is_active')->label('مفعّلة')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('التصنيف'),
                Tables\Columns\BadgeColumn::make('difficulty')
                    ->label('الصعوبة')
                    ->colors(['success' => 'easy', 'warning' => 'medium', 'danger' => 'hard']),
                Tables\Columns\TextColumn::make('gem_reward')->label('الجواهر'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّلة')->boolean(),
                Tables\Columns\TextColumn::make('attempts_count')->label('عدد المحاولات')->counts('attempts'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('puzzle_category_id')
                    ->label('التصنيف')
                    ->options(PuzzleCategory::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->label('الصعوبة')
                    ->options(['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب']),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPuzzles::route('/'),
            'create' => Pages\CreatePuzzle::route('/create'),
            'edit' => Pages\EditPuzzle::route('/{record}/edit'),
        ];
    }
}

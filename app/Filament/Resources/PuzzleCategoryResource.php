<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PuzzleCategoryResource\Pages;
use App\Models\PuzzleCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PuzzleCategoryResource extends Resource
{
    protected static ?string $model = PuzzleCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'التصنيفات';

    protected static ?string $modelLabel = 'تصنيف';

    protected static ?string $pluralModelLabel = 'التصنيفات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required(),
            Forms\Components\TextInput::make('slug')->label('المعرّف (slug)')->required()->unique(ignoreRecord: true),
            Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),
            Forms\Components\TextInput::make('icon')->label('أيقونة'),
            Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('puzzles_count')->label('عدد الأحجيات')->counts('puzzles'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّل')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('الترتيب')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()->before(fn (Tables\Actions\DeleteAction $action, PuzzleCategory $record) => static::guardDelete($action, $record))]);
    }

    /** E28: حذف تصنيف يحوي أحجيات مجتمع منشورة يمحو سجل النسخ؛ نمنعه برسالة بدل خطأ قيد بقاعدة البيانات. */
    public static function guardDelete(\Filament\Actions\MountableAction $action, PuzzleCategory $record): void
    {
        if ($record->puzzles()->where('origin', \App\Models\Puzzle::ORIGIN_COMMUNITY)->exists()) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title('لا يمكن حذف هذا التصنيف')
                ->body('يحوي أحجيات مجتمع منشورة ضمن سجل المبدعين. عطّل التصنيف بدل حذفه.')
                ->send();

            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPuzzleCategories::route('/'),
            'create' => Pages\CreatePuzzleCategory::route('/create'),
            'edit' => Pages\EditPuzzleCategory::route('/{record}/edit'),
        ];
    }
}

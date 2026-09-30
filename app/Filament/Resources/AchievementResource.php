<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AchievementResource\Pages;
use App\Models\Achievement;
use App\Models\Currency;
use App\Models\StoreItem;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AchievementResource extends Resource
{
    protected static ?string $model = Achievement::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'الإنجازات';

    protected static ?string $modelLabel = 'إنجاز';

    protected static ?string $pluralModelLabel = 'الإنجازات';

    public static function form(Form $form): Form
    {
        $registry = app(AchievementEvaluatorRegistry::class);

        return $form->schema([
            Forms\Components\Section::make('الهوية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('internal_key')
                        ->label('المفتاح الداخلي')
                        ->required()->maxLength(100)->unique(ignoreRecord: true)
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false)
                        ->helperText(fn (?Achievement $record) => ($record?->isUsed() ?? false)
                            ? 'لا يمكن تغييره - بدأ مستخدمون فعليًا بتحقيق هذا الإنجاز.'
                            : 'مثال: first_puzzle، puzzles_10 - لا يظهر للاعب.')
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\TextInput::make('name')->label('الاسم')->required(),
                    Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),
                    Forms\Components\Select::make('category')
                        ->label('التصنيف (للعرض فقط)')
                        ->options([
                            Achievement::CATEGORY_GENERAL => 'عام',
                            Achievement::CATEGORY_PUZZLES => 'أحجيات',
                            Achievement::CATEGORY_CAMPAIGNS => 'حملات',
                            Achievement::CATEGORY_QUALIFICATIONS => 'تأهلات',
                            Achievement::CATEGORY_MASTERY => 'إتقان',
                        ])
                        ->default(Achievement::CATEGORY_GENERAL)
                        ->native(false),
                ]),

            Forms\Components\Section::make('شرط الإنجاز')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('condition_type')
                        ->label('نوع الشرط')
                        ->options($registry->options())
                        ->required()
                        ->live()
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false)
                        ->helperText(fn (?Achievement $record) => ($record?->isUsed() ?? false)
                            ? 'لا يمكن تغييره - بدأ مستخدمون فعليًا بتحقيق هذا الإنجاز.'
                            : null),
                    Forms\Components\TextInput::make('target_value')
                        ->label('القيمة المستهدفة')
                        ->numeric()->minValue(1)->required()
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('النطاق')
                ->visible(fn (Get $get) => $get('condition_type') === AchievementEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY)
                ->schema([
                    Forms\Components\Hidden::make('scope_type')->default('puzzle_category'),
                    Forms\Components\Select::make('scope_id')
                        ->label('تصنيف الأحجية')
                        ->options(fn () => \App\Models\PuzzleCategory::query()->pluck('name', 'id'))
                        ->required()
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false)
                        ->native(false),
                ]),

            Forms\Components\Section::make('المكافآت')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('xp_reward')
                        ->label('مكافأة XP (اختياري)')
                        ->numeric()->minValue(0)->default(0)
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false),
                    Forms\Components\Select::make('reward_currency_id')
                        ->label('مكافأة عملة (اختياري)')
                        ->options(fn () => Currency::where('is_earnable', true)->pluck('name', 'id'))
                        ->live()
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false)
                        ->native(false)
                        ->helperText('العملات القابلة للكسب فقط.'),
                    Forms\Components\TextInput::make('reward_currency_amount')
                        ->label('قيمة العملة')
                        ->numeric()->minValue(1)
                        ->visible(fn (Get $get) => filled($get('reward_currency_id')))
                        ->required(fn (Get $get) => filled($get('reward_currency_id')))
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false),
                    Forms\Components\Select::make('reward_store_item_id')
                        ->label('مكافأة عنصر متجر (اختياري)')
                        ->options(fn () => StoreItem::whereIn('fulfillment_type', [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT])
                            ->get()
                            ->mapWithKeys(fn (StoreItem $item) => [$item->id => "{$item->name} ({$item->fulfillment_type})"]))
                        ->live()
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false)
                        ->native(false)
                        ->helperText('لا تظهر عناصر التسليم اليدوي - لا يمكن منحها تلقائيًا.'),
                    Forms\Components\TextInput::make('reward_item_quantity')
                        ->label('الكمية')
                        ->numeric()->minValue(1)->default(1)
                        ->visible(fn (Get $get) => filled($get('reward_store_item_id')))
                        ->disabled(fn (?Achievement $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('العرض والظهور')
                ->columns(3)
                ->schema([
                    Forms\Components\FileUpload::make('icon_path')
                        ->label('أيقونة (اختياري)')
                        ->image()->disk('public')->directory('achievements')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048),
                    Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
                    Forms\Components\Toggle::make('is_hidden')->label('إنجاز سري (مخفي قبل الفتح)'),
                    Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('progress'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()
                    ->description(fn (Achievement $r) => $r->internal_key),
                Tables\Columns\TextColumn::make('category')->label('التصنيف')->badge(),
                Tables\Columns\TextColumn::make('condition_type')->label('الشرط')
                    ->formatStateUsing(fn ($state) => app(AchievementEvaluatorRegistry::class)->options()[$state] ?? $state)
                    ->wrap(),
                Tables\Columns\TextColumn::make('target_value')->label('الهدف'),
                Tables\Columns\TextColumn::make('progress_count')->label('مستخدمون شرعوا')
                    ->state(fn (Achievement $r) => $r->progress_count),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
                Tables\Columns\IconColumn::make('is_hidden')->label('سري')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),
                Tables\Filters\TernaryFilter::make('is_hidden')->label('سري'),
                Tables\Filters\SelectFilter::make('category')->label('التصنيف')->options([
                    Achievement::CATEGORY_GENERAL => 'عام',
                    Achievement::CATEGORY_PUZZLES => 'أحجيات',
                    Achievement::CATEGORY_CAMPAIGNS => 'حملات',
                    Achievement::CATEGORY_QUALIFICATIONS => 'تأهلات',
                    Achievement::CATEGORY_MASTERY => 'إتقان',
                ]),
                Tables\Filters\SelectFilter::make('condition_type')->label('نوع الشرط')
                    ->options(fn () => app(AchievementEvaluatorRegistry::class)->options()),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (Achievement $record) => ! $record->isUsed())->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAchievements::route('/'),
            'create' => Pages\CreateAchievement::route('/create'),
            'edit' => Pages\EditAchievement::route('/{record}/edit'),
        ];
    }
}
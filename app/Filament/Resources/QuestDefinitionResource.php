<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuestDefinitionResource\Pages;
use App\Models\Currency;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Services\Engagement\QuestEvaluatorRegistry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class QuestDefinitionResource extends Resource
{
    protected static ?string $model = QuestDefinition::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'المهام';

    protected static ?string $modelLabel = 'مهمة';

    protected static ?string $pluralModelLabel = 'المهام';

    public static function form(Form $form): Form
    {
        $registry = app(QuestEvaluatorRegistry::class);

        return $form->schema([
            Forms\Components\Section::make('الهوية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('internal_key')
                        ->label('المفتاح الداخلي')
                        ->required()->maxLength(100)->unique(ignoreRecord: true)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false)
                        ->helperText(fn (?QuestDefinition $record) => ($record?->isUsed() ?? false)
                            ? 'لا يمكن تغييره - بدأ لاعبون فعليًا بإحراز تقدُّم بهذه المهمة.'
                            : 'مثال: daily_solve_3، weekly_solve_10 - لا يظهر للاعب.')
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\TextInput::make('name')->label('الاسم')->required(),
                    Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('الفترة')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('period_type')
                        ->label('نوع الفترة')
                        ->options([
                            QuestDefinition::PERIOD_DAILY => 'يومية',
                            QuestDefinition::PERIOD_WEEKLY => 'أسبوعية',
                        ])
                        ->required()->native(false)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('شرط المهمة')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('condition_type')
                        ->label('نوع الشرط')
                        ->options($registry->options())
                        ->required()->live()->native(false)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false)
                        ->helperText(fn (?QuestDefinition $record) => ($record?->isUsed() ?? false)
                            ? 'لا يمكن تغييره - بدأ لاعبون فعليًا بإحراز تقدُّم بهذه المهمة.'
                            : null),
                    Forms\Components\TextInput::make('target_value')
                        ->label('القيمة المستهدفة')
                        ->numeric()->minValue(1)->required()
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('النطاق')
                ->visible(fn (Get $get) => $get('condition_type') === QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY)
                ->schema([
                    Forms\Components\Hidden::make('scope_type')->default('puzzle_category'),
                    Forms\Components\Select::make('scope_id')
                        ->label('تصنيف الأحجية')
                        ->options(fn () => \App\Models\PuzzleCategory::query()->pluck('name', 'id'))
                        ->required()->native(false)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('المكافآت')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('xp_reward')
                        ->label('مكافأة XP (اختياري)')
                        ->numeric()->minValue(0)->default(0)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false),
                    Forms\Components\Select::make('reward_currency_id')
                        ->label('مكافأة عملة (اختياري)')
                        ->options(fn () => Currency::where('is_earnable', true)->pluck('name', 'id'))
                        ->live()->native(false)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false)
                        ->helperText('العملات القابلة للكسب فقط - تدخل دورة Pending→Available نفسها المُتَّبعة بكل مكافآت اللعب.'),
                    Forms\Components\TextInput::make('reward_currency_amount')
                        ->label('قيمة العملة')
                        ->numeric()->minValue(1)
                        ->visible(fn (Get $get) => filled($get('reward_currency_id')))
                        ->required(fn (Get $get) => filled($get('reward_currency_id')))
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false),
                    Forms\Components\Select::make('reward_store_item_id')
                        ->label('مكافأة عنصر متجر (اختياري)')
                        ->options(fn () => StoreItem::whereIn('fulfillment_type', [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT])
                            ->get()
                            ->mapWithKeys(fn (StoreItem $item) => [$item->id => "{$item->name} ({$item->fulfillment_type})"]))
                        ->live()->native(false)
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false)
                        ->helperText('لا تظهر عناصر التسليم اليدوي - لا يمكن منحها تلقائيًا.'),
                    Forms\Components\TextInput::make('reward_item_quantity')
                        ->label('الكمية')
                        ->numeric()->minValue(1)->default(1)
                        ->visible(fn (Get $get) => filled($get('reward_store_item_id')))
                        ->disabled(fn (?QuestDefinition $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('نافذة التفعيل (اختياري)')
                ->columns(2)
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ في'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي في'),
                    Forms\Components\Placeholder::make('helper')
                        ->label('')
                        ->columnSpanFull()
                        ->content('إذا "تبدأ في" محدَّدة: لا يُحتسَب أي نشاط قبلها. إذا تُركت فارغة: النشاط السابق بالفترة الحالية قد يُحتسَب فور تفعيل المهمة (Deterministic أكثر - لا فجوة تُخفي نشاطًا حقيقيًا).'),
                ]),

            Forms\Components\Section::make('العرض')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_active')->label('نشطة')->default(true),
                    Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()
                    ->description(fn (QuestDefinition $r) => $r->internal_key),
                Tables\Columns\TextColumn::make('period_type')->label('الفترة')->badge()
                    ->formatStateUsing(fn ($state) => $state === QuestDefinition::PERIOD_DAILY ? 'يومية' : 'أسبوعية'),
                Tables\Columns\TextColumn::make('condition_type')->label('الشرط')
                    ->formatStateUsing(fn ($state) => app(QuestEvaluatorRegistry::class)->options()[$state] ?? $state)
                    ->wrap(),
                Tables\Columns\TextColumn::make('target_value')->label('الهدف'),
                Tables\Columns\IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period_type')->label('الفترة')->options([
                    QuestDefinition::PERIOD_DAILY => 'يومية',
                    QuestDefinition::PERIOD_WEEKLY => 'أسبوعية',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('نشطة'),
                Tables\Filters\SelectFilter::make('condition_type')->label('نوع الشرط')
                    ->options(fn () => app(QuestEvaluatorRegistry::class)->options()),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (QuestDefinition $record) => ! $record->isUsed())->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestDefinitions::route('/'),
            'create' => Pages\CreateQuestDefinition::route('/create'),
            'edit' => Pages\EditQuestDefinition::route('/{record}/edit'),
        ];
    }
}

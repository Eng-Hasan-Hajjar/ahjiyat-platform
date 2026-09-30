<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LevelDefinitionResource\Pages;
use App\Models\Currency;
use App\Models\LevelDefinition;
use App\Models\StoreItem;
use App\Services\Store\StoreItemInvariantGuard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LevelDefinitionResource extends Resource
{
    protected static ?string $model = LevelDefinition::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationGroup = 'التقدُّم';

    protected static ?string $navigationLabel = 'المستويات';

    protected static ?string $modelLabel = 'مستوى';

    protected static ?string $pluralModelLabel = 'المستويات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الهوية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('level_number')
                        ->label('رقم المستوى')
                        ->numeric()->minValue(1)->required()->unique(ignoreRecord: true)
                        ->disabled(fn (?LevelDefinition $record) => $record?->isUsed() ?? false)
                        ->helperText(fn (?LevelDefinition $record) => ($record?->isUsed() ?? false)
                            ? 'لا يمكن تغييره - وصل مستخدمون إلى هذا المستوى فعليًا.'
                            : null),
                    Forms\Components\TextInput::make('name')->label('الاسم')->required(),
                    Forms\Components\Textarea::make('description')->label('الوصف (اختياري)')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('عتبة XP')
                ->schema([
                    Forms\Components\TextInput::make('xp_required_total')
                        ->label('إجمالي XP المطلوب (تراكمي)')
                        ->numeric()->minValue(0)->required()
                        ->disabled(fn (?LevelDefinition $record) => $record?->isUsed() ?? false)
                        ->helperText('المستوى الأول يجب أن يكون صفرًا دائمًا. كل مستوى لاحق يجب أن يكون أكبر من سابقه.'),
                ]),

            Forms\Components\Section::make('المكافآت (اختياري - لا XP هنا إطلاقًا)')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('reward_currency_id')
                        ->label('مكافأة عملة')
                        ->options(fn () => Currency::where('is_earnable', true)->pluck('name', 'id'))
                        ->live()
                        ->disabled(fn (?LevelDefinition $record) => $record?->isUsed() ?? false)
                        ->native(false),
                    Forms\Components\TextInput::make('reward_currency_amount')
                        ->label('قيمة العملة')
                        ->numeric()->minValue(1)
                        ->visible(fn (Get $get) => filled($get('reward_currency_id')))
                        ->required(fn (Get $get) => filled($get('reward_currency_id')))
                        ->disabled(fn (?LevelDefinition $record) => $record?->isUsed() ?? false),
                    Forms\Components\Select::make('reward_store_item_id')
                        ->label('مكافأة عنصر متجر')
                        ->options(fn () => StoreItem::whereIn('fulfillment_type', [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT])
                            ->get()
                            ->mapWithKeys(fn (StoreItem $item) => [$item->id => "{$item->name} ({$item->fulfillment_type})"]))
                        ->live()
                        ->disabled(fn (?LevelDefinition $record) => $record?->isUsed() ?? false)
                        ->native(false)
                        ->helperText('لا تظهر عناصر التسليم اليدوي.'),
                    Forms\Components\TextInput::make('reward_item_quantity')
                        ->label('الكمية')
                        ->numeric()->minValue(1)->default(1)
                        ->visible(fn (Get $get) => filled($get('reward_store_item_id')))
                        ->disabled(fn (?LevelDefinition $record) => $record?->isUsed() ?? false),
                ]),

            Forms\Components\Section::make('المظهر')
                ->columns(3)
                ->schema([
                    Forms\Components\FileUpload::make('icon_path')
                        ->label('أيقونة (اختياري)')
                        ->image()->disk('public')->directory('levels')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048),
                    Forms\Components\ColorPicker::make('color')
                        ->label('لون (اختياري)')
                        ->rule('regex:'.StoreItemInvariantGuard::COLOR_PATTERN),
                    Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
                    Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('unlocks'))
            ->columns([
                Tables\Columns\TextColumn::make('level_number')->label('الرقم')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('الاسم'),
                Tables\Columns\TextColumn::make('xp_required_total')->label('عتبة XP')->sortable(),
                Tables\Columns\TextColumn::make('unlocks_count')->label('مستخدمون وصلوا')
                    ->state(fn (LevelDefinition $r) => $r->unlocks_count),
                Tables\Columns\TextColumn::make('reward_currency_amount')->label('مكافأة عملة')
                    ->state(fn (LevelDefinition $r) => $r->reward_currency_amount ? "{$r->reward_currency_amount} ({$r->rewardCurrency?->code})" : '—'),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->defaultSort('level_number')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (LevelDefinition $record) => ! $record->isUsed())->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLevelDefinitions::route('/'),
            'create' => Pages\CreateLevelDefinition::route('/create'),
            'edit' => Pages\EditLevelDefinition::route('/{record}/edit'),
        ];
    }
}
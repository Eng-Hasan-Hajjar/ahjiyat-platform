<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CurrencyResource\Pages;
use App\Models\Currency;
use App\Services\Economy\CurrencyRegistry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'الاقتصاد';

    protected static ?string $navigationLabel = 'العملات';

    protected static ?string $modelLabel = 'عملة';

    protected static ?string $pluralModelLabel = 'العملات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الهوية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('internal_key')
                        ->label('المفتاح الداخلي (ثابت)')
                        ->required()
                        ->maxLength(60)
                        ->rule('alpha_dash')
                        ->disabled(fn (?Currency $record) => $record?->isProtected() ?? false)
                        ->dehydrated(fn (?Currency $record) => ! ($record?->isProtected() ?? false))
                        ->helperText('يُقفَل تلقائياً بعد أول استخدام فعلي (محفظة أو معاملة) - لا يمكن تغييره لاحقاً.')
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('code')->label('الرمز')->required()->maxLength(10)->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\TextInput::make('name')->label('الاسم المعروض')->required()->maxLength(255),
                    Forms\Components\TextInput::make('short_name')->label('اسم مختصر (اختياري)')->maxLength(20),
                    Forms\Components\Textarea::make('description')->label('وصف (اختياري)')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('النوع')
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('نوع العملة')
                        ->options([
                            Currency::TYPE_STANDARD => 'أساسية (Standard/Earned)',
                            Currency::TYPE_PREMIUM => 'مميَّزة (Premium)',
                            Currency::TYPE_EVENT => 'فعالية/موسم (Event)',
                        ])
                        ->required()
                        ->disabled(fn (?Currency $record) => $record?->isProtected() ?? false)
                        ->helperText('تغيير النوع بعد وجود معاملات فعلية محظور - أنشئ عملة جديدة بدلاً من ذلك.'),
                ]),

            Forms\Components\Section::make('السياسات')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_active')->label('نشطة')->default(true),
                    Forms\Components\Toggle::make('is_earnable')->label('قابلة للكسب من اللعب'),
                    Forms\Components\Toggle::make('is_spendable')->label('قابلة للصرف'),
                    Forms\Components\Toggle::make('is_purchasable')->label('قابلة للشراء (متجر)'),
                    Forms\Components\Toggle::make('is_redeemable')
                        ->label('قابلة للاستبدال')
                        ->helperText('قابلة للشراء لا تعني تلقائياً قابلة للاستبدال - عملة مميَّزة عادة لا تُستبدَل.'),
                ]),

            Forms\Components\Section::make('النطاق (اختياري)')
                ->description('اربط العملة بموسم أو حملة محدَّدة - اتركه فارغاً لعملة عامة.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('scope_type')
                        ->label('نوع النطاق')
                        ->options(['season' => 'موسم رسمي', 'campaign' => 'حملة'])
                        ->live()
                        ->native(false),
                    Forms\Components\Select::make('scope_id')
                        ->label('العنصر المرتبط')
                        ->options(function (Get $get) {
                            return match ($get('scope_type')) {
                                'season' => \App\Models\Season::query()->pluck('code', 'id'),
                                'campaign' => \App\Models\Campaign::query()->pluck('title', 'id'),
                                default => [],
                            };
                        })
                        ->visible(fn (Get $get) => filled($get('scope_type')))
                        ->native(false),
                ]),

            Forms\Components\Section::make('الجدول الزمني (اختياري)')
                ->columns(3)
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')->label('تصبح سارية من'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('يتوقف الكسب/الصرف من'),
                    Forms\Components\DateTimePicker::make('expires_at')->label('تاريخ انتهاء الأرصدة (اختياري)'),
                ]),

            Forms\Components\Section::make('التصميم')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('icon_path')
                        ->label('أيقونة العملة')
                        ->image()
                        ->disk('public')
                        ->directory('currencies')
                        ->acceptedFileTypes(['image/png', 'image/webp'])
                        ->maxSize(1024),
                    Forms\Components\ColorPicker::make('color')->label('لون العملة'),
                    Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['wallets', 'transactions']))
            ->columns([
                Tables\Columns\ImageColumn::make('icon_path')->label('')->circular()->defaultImageUrl(url('/images/currency-placeholder.png')),
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()->description(fn (Currency $r) => $r->code),
                Tables\Columns\BadgeColumn::make('type')->label('النوع')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        Currency::TYPE_STANDARD => 'أساسية', Currency::TYPE_PREMIUM => 'مميَّزة', Currency::TYPE_EVENT => 'فعالية', default => $state,
                    })
                    ->colors(['primary' => Currency::TYPE_STANDARD, 'warning' => Currency::TYPE_PREMIUM, 'success' => Currency::TYPE_EVENT]),
                Tables\Columns\IconColumn::make('is_active')->label('نشطة')->boolean(),
                Tables\Columns\IconColumn::make('is_earnable')->label('تُكتسَب')->boolean(),
                Tables\Columns\IconColumn::make('is_purchasable')->label('تُشترى')->boolean(),
                Tables\Columns\IconColumn::make('is_redeemable')->label('تُستبدَل')->boolean(),
                Tables\Columns\TextColumn::make('scope_type')->label('النطاق')->formatStateUsing(fn ($state) => match ($state) {
                    'season' => 'موسم', 'campaign' => 'حملة', default => 'عام',
                }),
                Tables\Columns\TextColumn::make('wallets_count')->label('محافظ')->sortable(),
                Tables\Columns\TextColumn::make('transactions_count')->label('معاملات')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Currency $record) => ! $record->isProtected())
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCurrencies::route('/'),
            'create' => Pages\CreateCurrency::route('/create'),
            'edit' => Pages\EditCurrency::route('/{record}/edit'),
        ];
    }
}
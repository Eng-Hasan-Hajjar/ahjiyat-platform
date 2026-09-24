<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CurrencyPackResource\Pages;
use App\Models\Currency;
use App\Models\CurrencyPack;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CurrencyPackResource extends Resource
{
    protected static ?string $model = CurrencyPack::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'الاقتصاد';

    protected static ?string $navigationLabel = 'حزم العملات';

    protected static ?string $modelLabel = 'حزمة عملة';

    protected static ?string $pluralModelLabel = 'حزم العملات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الحزمة')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('sku')->label('SKU')->required()->maxLength(60)->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\Select::make('currency_id')
                        ->label('العملة')
                        ->options(fn () => Currency::where('is_purchasable', true)->where('is_active', true)->pluck('name', 'id'))
                        ->required()
                        ->native(false),
                    Forms\Components\TextInput::make('name')->label('اسم الحزمة')->required()->columnSpanFull(),
                    Forms\Components\Textarea::make('description')->label('وصف (اختياري)')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('الكمية والسعر')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('base_amount')->label('الكمية الأساسية')->numeric()->required()->minValue(1),
                    Forms\Components\TextInput::make('bonus_amount')->label('كمية إضافية (Bonus)')->numeric()->default(0)->minValue(0),
                    Forms\Components\TextInput::make('price_minor')
                        ->label('السعر (بوحدة صغرى - مثلاً 999 = 9.99)')
                        ->numeric()->required()->minValue(0)
                        ->helperText('لا فاصلة عشرية - عدد صحيح دائماً.'),
                    Forms\Components\TextInput::make('fiat_currency')->label('رمز العملة الحقيقية (ISO)')->default('USD')->maxLength(3)->required()->extraInputAttributes(['dir' => 'ltr']),
                ]),

            Forms\Components\Section::make('العرض')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('image_path')
                        ->label('صورة الحزمة')
                        ->image()->disk('public')->directory('currency-packs')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048),
                    Forms\Components\Toggle::make('is_active')->label('نشطة')->default(true),
                    Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ من (اختياري)'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي في (اختياري)'),
                    Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('currency:id,name,code'))
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label(''),
                Tables\Columns\TextColumn::make('name')->label('الحزمة')->searchable()->description(fn (CurrencyPack $r) => $r->sku),
                Tables\Columns\TextColumn::make('currency.name')->label('العملة'),
                Tables\Columns\TextColumn::make('base_amount')->label('الكمية')
                    ->formatStateUsing(fn (CurrencyPack $r) => $r->bonus_amount > 0 ? "{$r->base_amount} + {$r->bonus_amount}" : (string) $r->base_amount),
                Tables\Columns\TextColumn::make('price_minor')->label('السعر')->formatStateUsing(fn (CurrencyPack $r) => $r->priceDisplay()),
                Tables\Columns\IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCurrencyPacks::route('/'),
            'create' => Pages\CreateCurrencyPack::route('/create'),
            'edit' => Pages\EditCurrencyPack::route('/{record}/edit'),
        ];
    }
}
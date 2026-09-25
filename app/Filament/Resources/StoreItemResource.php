<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreItemResource\Pages;
use App\Filament\Resources\StoreItemResource\RelationManagers;
use App\Models\StoreItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StoreItemResource extends Resource
{
    protected static ?string $model = StoreItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'المتجر';

    protected static ?string $navigationLabel = 'عناصر المتجر';

    protected static ?string $modelLabel = 'عنصر متجر';

    protected static ?string $pluralModelLabel = 'عناصر المتجر';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الهوية')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('sku')->label('SKU')->required()->maxLength(60)->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\TextInput::make('slug')->label('الرابط المختصر (Slug)')->required()->maxLength(120)->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\TextInput::make('name')->label('الاسم')->required()->columnSpanFull(),
                    Forms\Components\TextInput::make('short_description')->label('وصف مختصر (اختياري)')->columnSpanFull(),
                    Forms\Components\Textarea::make('description')->label('وصف تفصيلي (اختياري)')->columnSpanFull(),
                ]),

            Forms\Components\Section::make('نوع العنصر والتسليم')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('item_type')
                        ->label('نوع العنصر')
                        ->options([
                            StoreItem::TYPE_PHYSICAL => 'مادي (Physical)',
                            StoreItem::TYPE_DIGITAL => 'رقمي (Digital)',
                            StoreItem::TYPE_COSMETIC => 'تجميلي (Cosmetic)',
                            StoreItem::TYPE_CONSUMABLE => 'قابل للاستهلاك (Consumable)',
                            StoreItem::TYPE_ACCESS => 'وصول/امتياز (Access)',
                        ])
                        ->required()
                        ->disabled(fn (?StoreItem $record) => $record?->isProtected() ?? false),

                    Forms\Components\Select::make('fulfillment_type')
                        ->label('طريقة التسليم')
                        ->options([
                            StoreItem::FULFILLMENT_INVENTORY => 'مخزون شخصي (تلقائي عند الشراء)',
                            StoreItem::FULFILLMENT_ENTITLEMENT => 'امتياز/وصول (تلقائي عند الشراء)',
                            StoreItem::FULFILLMENT_MANUAL => 'يدوي (الإدارة تُنجزه لاحقاً)',
                        ])
                        ->live()
                        ->required()
                        ->disabled(fn (?StoreItem $record) => $record?->isProtected() ?? false)
                        ->helperText('لا علاقة حتمية بنوع العنصر - أنت من يحدِّد طريقة التسليم صراحة.'),

                    Forms\Components\TextInput::make('entitlement_key')
                        ->label('مفتاح الامتياز الداخلي')
                        ->helperText('مثال: season.vip أو event.access.2027 - لا يظهر للاعب.')
                        ->required()
                        ->visible(fn (Get $get) => $get('fulfillment_type') === StoreItem::FULFILLMENT_ENTITLEMENT)
                        ->extraInputAttributes(['dir' => 'ltr']),

                    Forms\Components\TextInput::make('entitlement_duration_days')
                        ->label('مدة الامتياز بالأيام (اتركه فارغاً = دائم)')
                        ->numeric()
                        ->visible(fn (Get $get) => $get('fulfillment_type') === StoreItem::FULFILLMENT_ENTITLEMENT),

                    Forms\Components\TextInput::make('grant_quantity')
                        ->label('الكمية المُمنوحة عند الشراء')
                        ->numeric()->default(1)->minValue(1)
                        ->visible(fn (Get $get) => $get('fulfillment_type') === StoreItem::FULFILLMENT_INVENTORY),
                ]),

            Forms\Components\Section::make('المخزون والحدود')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('stock_limit')->label('حد المخزون (اتركه فارغاً = غير محدود)')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('per_user_limit')->label('الحد لكل مستخدم (اتركه فارغاً = بلا حد)')->numeric()->minValue(1),
                ]),

            Forms\Components\Section::make('النطاق (اختياري)')
                ->description('اربط العنصر بموسم أو حملة محدَّدة - اتركه فارغاً لعنصر عام.')
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
                ->columns(2)
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')->label('يصبح متاحاً من'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('يتوقف من'),
                ]),

            Forms\Components\Section::make('الصورة والنشر')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('image_path')
                        ->label('صورة العنصر')
                        ->image()->disk('public')->directory('store-items')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048),
                    Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
                    Forms\Components\Toggle::make('is_featured')->label('مميَّز'),
                    Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['prices', 'purchases']))
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label(''),
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()->description(fn (StoreItem $r) => $r->sku),
                Tables\Columns\BadgeColumn::make('item_type')->label('النوع')->formatStateUsing(fn ($state) => match ($state) {
                    StoreItem::TYPE_PHYSICAL => 'مادي', StoreItem::TYPE_DIGITAL => 'رقمي', StoreItem::TYPE_COSMETIC => 'تجميلي',
                    StoreItem::TYPE_CONSUMABLE => 'استهلاكي', StoreItem::TYPE_ACCESS => 'وصول', default => $state,
                }),
                Tables\Columns\TextColumn::make('fulfillment_type')->label('التسليم')->formatStateUsing(fn ($state) => match ($state) {
                    StoreItem::FULFILLMENT_INVENTORY => 'مخزون', StoreItem::FULFILLMENT_ENTITLEMENT => 'امتياز',
                    StoreItem::FULFILLMENT_MANUAL => 'يدوي', default => $state,
                }),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
                Tables\Columns\TextColumn::make('prices_count')->label('الأسعار')->sortable(),
                Tables\Columns\TextColumn::make('purchases_count')->label('المشتريات')->sortable(),
                Tables\Columns\TextColumn::make('stock_status')->label('المخزون')
                    ->state(fn (StoreItem $r) => $r->stock_limit === null ? 'غير محدود' : ($r->isSoldOut() ? 'نفدت الكمية' : "متبقٍّ {$r->remainingStock()}"))
                    ->badge()->color(fn (StoreItem $r) => $r->isSoldOut() ? 'danger' : 'success'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (StoreItem $record) => ! $record->isProtected())->requiresConfirmation(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoreItems::route('/'),
            'create' => Pages\CreateStoreItem::route('/create'),
            'edit' => Pages\EditStoreItem::route('/{record}/edit'),
        ];
    }
}
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdPlacementResource\Pages;
use App\Models\AdPlacement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E14 (بند 316-319): لا إنشاء يدوي - المواضع تأتي حصرًا من AdPlacementSeeder
 * (مُزامَنة من AdPlacementRegistry). الإدارة تُفعِّل/تُعطِّل وتضبط الجهاز فقط.
 */
class AdPlacementResource extends Resource
{
    protected static ?string $model = AdPlacement::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'الإعلانات والرعايات';

    protected static ?string $navigationLabel = 'المواضع';

    protected static ?string $modelLabel = 'موضع إعلان';

    protected static ?string $pluralModelLabel = 'مواضع الإعلان';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الهوية (للعرض فقط)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('internal_key')->label('المفتاح الداخلي')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('name')->label('الاسم')->disabled()->dehydrated(false),
                    Forms\Components\Textarea::make('description')->label('الوصف')->disabled()->dehydrated(false)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('التحكُّم')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_active')->label('مُفعَّل'),
                    Forms\Components\Toggle::make('desktop_enabled')->label('يظهر بسطح المكتب'),
                    Forms\Components\Toggle::make('mobile_enabled')->label('يظهر بالجوّال'),
                    Forms\Components\TextInput::make('max_ads_per_render')->label('الحد الأقصى بكل عرض')->numeric()->minValue(1)->maxValue(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('surface')->label('السطح')->badge(),
                Tables\Columns\IconColumn::make('is_active')->label('مُفعَّل')->boolean(),
                Tables\Columns\IconColumn::make('desktop_enabled')->label('سطح المكتب')->boolean(),
                Tables\Columns\IconColumn::make('mobile_enabled')->label('الجوّال')->boolean(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdPlacements::route('/'),
            'edit' => Pages\EditAdPlacement::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignStageResource\Pages;
use App\Filament\Resources\CampaignStageResource\RelationManagers\GatesRelationManager;
use App\Models\CampaignStage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/**
 * مخفية عمداً عن التنقّل الرئيسي - يُصل إليها فقط عبر زر "إدارة البوابات"
 * بجدول StagesRelationManager الخاص بـCampaignResource. لا تحتاج صفحة
 * "قائمة" فعلية يستخدمها أحد مباشرة، لكن Filament يتطلبها كـRoute أساسي.
 */
class CampaignStageResource extends Resource
{
    protected static ?string $model = CampaignStage::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('campaign_id')
                ->relationship('campaign', 'title')
                ->label('الحملة')
                ->required()
                ->disabled(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        // لا تُستخدم فعلياً بواجهة التنقّل - الوصول حصراً عبر EditPage من
        // CampaignResource\RelationManagers\StagesRelationManager.
        return $table->columns([]);
    }

    public static function getRelations(): array
    {
        return [GatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaignStages::route('/'),
            'edit' => Pages\EditCampaignStage::route('/{record}/edit'),
        ];
    }
}
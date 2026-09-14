<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignGateResource\Pages;
use App\Filament\Resources\CampaignGateResource\RelationManagers\StepsRelationManager;
use App\Models\CampaignGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/** مخفية عن التنقّل الرئيسي - نفس فلسفة CampaignStageResource بالضبط. */
class CampaignGateResource extends Resource
{
    protected static ?string $model = CampaignGate::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('campaign_stage_id')
                ->relationship('stage', 'title')
                ->label('المرحلة')
                ->required()
                ->disabled(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required(),

            Forms\Components\Select::make('qualification_rule')
                ->label('التأهّل')
                ->options(['first_n' => 'أول عدد محدَّد من المستخدمين (First N)'])
                ->placeholder('بلا تأهّل خاص')
                ->live(),

            Forms\Components\TextInput::make('qualification_config.limit')
                ->label('العدد المسموح (Limit)')
                ->numeric()
                ->minValue(1)
                ->required()
                ->visible(fn (Get $get) => $get('qualification_rule') === 'first_n'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([]); // الوصول حصراً عبر EditPage من GatesRelationManager
    }

    public static function getRelations(): array
    {
        return [StepsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaignGates::route('/'),
            'edit' => Pages\EditCampaignGate::route('/{record}/edit'),
        ];
    }
}
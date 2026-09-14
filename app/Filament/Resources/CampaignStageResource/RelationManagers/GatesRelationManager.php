<?php

namespace App\Filament\Resources\CampaignStageResource\RelationManagers;

use App\Filament\Resources\CampaignGateResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class GatesRelationManager extends RelationManager
{
    protected static string $relationship = 'gates';

    protected static ?string $title = 'البوابات (Gates)';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\Textarea::make('narrative_intro')->label('نص تمهيدي (اختياري)')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required()->default(1),

            // C7.3: Select بسيط بدل كتابة JSON يدوياً - limit تظهر فقط لـfirst_n.
            Forms\Components\Select::make('qualification_rule')
                ->label('التأهّل')
                ->options(['first_n' => 'أول عدد محدَّد من المستخدمين (First N)'])
                ->placeholder('بلا تأهّل خاص')
                ->live()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('qualification_config.limit')
                ->label('العدد المسموح (Limit)')
                ->numeric()
                ->minValue(1)
                ->required()
                ->visible(fn (Get $get) => $get('qualification_rule') === 'first_n'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('العنوان'),
                Tables\Columns\TextColumn::make('qualification_rule')
                    ->label('التأهّل')
                    ->badge()
                    ->placeholder('بلا تأهّل خاص')
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('steps_count')->label('عدد الخطوات')->counts('steps'),
                Tables\Columns\TextColumn::make('qualifications_count')->label('عدد المتأهّلين')->counts('qualifications'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\Action::make('manageSteps')
                    ->label('إدارة الخطوات')
                    ->icon('heroicon-o-arrow-left-circle')
                    ->url(fn ($record) => CampaignGateResource::getUrl('edit', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
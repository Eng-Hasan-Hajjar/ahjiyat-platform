<?php

namespace App\Filament\Resources\CampaignResource\RelationManagers;

use App\Filament\Resources\CampaignStageResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    protected static ?string $title = 'المراحل (Stages)';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required()->default(1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('العنوان'),
                Tables\Columns\TextColumn::make('subtitle')->label('العنوان الفرعي'),
                Tables\Columns\TextColumn::make('gates_count')->label('عدد البوابات')->counts('gates'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\Action::make('manageGates')
                    ->label('إدارة البوابات')
                    ->icon('heroicon-o-arrow-left-circle')
                    ->url(fn ($record) => CampaignStageResource::getUrl('edit', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
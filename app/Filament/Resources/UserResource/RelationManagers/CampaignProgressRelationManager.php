<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CampaignProgressRelationManager extends RelationManager
{
    protected static string $relationship = 'campaignProgress';

    protected static ?string $title = 'المواسم والحملات';

    protected static ?string $icon = 'heroicon-o-flag';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('users.view_activity') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('step.stage.campaign'))
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('step.stage.campaign.title')->label('الحملة')->placeholder('—'),
                Tables\Columns\TextColumn::make('step.stage.title')->label('المرحلة')->placeholder('—'),
                Tables\Columns\TextColumn::make('step.title')->label('الخطوة')->placeholder('—'),
                Tables\Columns\IconColumn::make('completed_at')->label('مكتملة')
                    ->state(fn ($record) => (bool) $record->completed_at)
                    ->boolean(),
                Tables\Columns\TextColumn::make('started_at')->label('بدأت')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('completed_at')->label('اكتملت')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->defaultSort('started_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
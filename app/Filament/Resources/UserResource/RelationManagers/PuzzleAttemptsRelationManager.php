<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PuzzleAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'puzzleAttempts';

    protected static ?string $title = 'نشاط اللعب';

    protected static ?string $icon = 'heroicon-o-puzzle-piece';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('users.view_activity') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('puzzle:id,title'))
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('puzzle.title')->label('الأحجية')->limit(30),
                Tables\Columns\TextColumn::make('context_type')->label('السياق')->placeholder('مستقل'),
                Tables\Columns\IconColumn::make('is_correct')->label('صحيحة')->boolean(),
                Tables\Columns\IconColumn::make('used_hint')->label('استخدم تلميحاً')->boolean(),
                Tables\Columns\TextColumn::make('time_taken_seconds')->label('الزمن (ث)'),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
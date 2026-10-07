<?php

namespace App\Filament\Resources\TeamChallengeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** روستر التحدّي ونتائج اللاعبين (E20-E13): عرض فقط، بلا أي إجراء. لا تعديل لروستر أو لنتيجة. */
class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $title = 'الروستر ونتائج اللاعبين';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['user:id,name', 'team:id,name']))->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('team.name')->label('الفريق'),
                Tables\Columns\TextColumn::make('user.name')->label('اللاعب'),
                Tables\Columns\TextColumn::make('role_snapshot')->label('الدور وقت القفل')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('الحالة'),
                Tables\Columns\TextColumn::make('score')->label('الدرجة')->placeholder('—'),
                Tables\Columns\TextColumn::make('duration_ms')->label('المدة (مللي ثانية)')->placeholder('—'),
            ]);
    }
}

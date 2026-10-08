<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeamChallengeResource\Pages;
use App\Models\TeamChallenge;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** فحص تحدّيات الفرق (E20-E13): **عرض فقط**. لا إنشاء ولا تعديل ولا حذف، ولا تغيير فائز/نتيجة يدويًا (السياسة تمنعها؛ الخادم يحسب النتيجة وحده). */
class TeamChallengeResource extends Resource
{
    protected static ?string $model = TeamChallenge::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'الفرق';

    protected static ?string $navigationLabel = 'تحدّيات الفرق';

    protected static ?string $modelLabel = 'تحدّي فريق';

    protected static ?string $pluralModelLabel = 'تحدّيات الفرق';

    protected static ?int $navigationSort = 61;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['challenger:id,name', 'opponent:id,name', 'puzzle:id,title', 'winner:id,name']))->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('challenger.name')->label('المتحدّي')->searchable(),
                Tables\Columns\TextColumn::make('opponent.name')->label('الخصم')->searchable(),
                Tables\Columns\TextColumn::make('puzzle.title')->label('الأحجية'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->state(fn (TeamChallenge $r) => ['pending' => 'بانتظار الرد', 'accepted' => 'جارٍ', 'completed' => 'انتهى', 'declined' => 'مرفوض', 'cancelled' => 'مُلغى', 'expired' => 'منتهي'][$r->effectiveStatus()] ?? $r->status),
                Tables\Columns\TextColumn::make('winner')->label('النتيجة')->state(fn (TeamChallenge $r) => $r->status !== 'completed' ? '—' : ($r->is_draw ? 'تعادل' : $r->winner?->name)),
                Tables\Columns\TextColumn::make('play_ends_at')->label('نهاية اللعب')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('أُنشئ')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['pending' => 'معلّق', 'accepted' => 'جارٍ', 'completed' => 'منتهٍ', 'declined' => 'مرفوض', 'cancelled' => 'مُلغى', 'expired' => 'منتهي المهلة'])])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('التحدّي')->columns(3)->schema([
                Infolists\Components\TextEntry::make('challenger.name')->label('المتحدّي'),
                Infolists\Components\TextEntry::make('opponent.name')->label('الخصم'),
                Infolists\Components\TextEntry::make('puzzle.title')->label('الأحجية'),
                Infolists\Components\TextEntry::make('status')->label('الحالة'),
                Infolists\Components\TextEntry::make('accepted_at')->label('قُبل')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('play_ends_at')->label('نهاية اللعب')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('winner.name')->label('الفائز')->placeholder('—'),
                Infolists\Components\IconEntry::make('is_draw')->label('تعادل')->boolean(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [TeamChallengeResource\RelationManagers\ParticipantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTeamChallenges::route('/'), 'view' => Pages\ViewTeamChallenge::route('/{record}')];
    }
}

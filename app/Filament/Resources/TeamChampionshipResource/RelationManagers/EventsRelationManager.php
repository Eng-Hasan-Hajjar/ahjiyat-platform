<?php

namespace App\Filament\Resources\TeamChampionshipResource\RelationManagers;

use App\Models\CompetitiveEvent;
use App\Models\TeamChampionship;
use App\Services\Teams\TeamChampionshipService;
use App\Services\Teams\TeamException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** أحداث البطولة (E20-D3/E14): الربط والفك **عبر الخدمة** وللمسودة وحدها (تُقفل بعد النشر)، مع رفض الحدث غير المتوافق بإشعار. لا attach/detach خام. */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'أحداث البطولة';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function editable(): bool
    {
        /** @var TeamChampionship $c */
        $c = $this->getOwnerRecord();

        return $c->isDraft() && (auth()->user()?->can('update', $c) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('team_championship_events.sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('الحدث'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->formatStateUsing(fn ($state) => ['draft' => 'مسودة', 'published' => 'منشور', 'completed' => 'معتمَد', 'cancelled' => 'ملغى'][$state] ?? $state),
                Tables\Columns\IconColumn::make('team_rankings_finalized_at')->label('ترتيب فرق معتمَد')->boolean()->state(fn (CompetitiveEvent $r) => $r->team_rankings_finalized_at !== null),
                Tables\Columns\TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('link')->label('ربط حدث')->visible(fn () => $this->editable())
                    ->form([Forms\Components\Select::make('event_id')->label('الحدث')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $s) => CompetitiveEvent::query()->whereIn('status', ['published', 'completed'])->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%')->orderBy('title')->limit(30)->pluck('title', 'id')->all())
                        ->getOptionLabelUsing(fn ($v) => CompetitiveEvent::query()->find($v)?->title)])
                    ->action(fn (array $data) => $this->guard(fn () => app(TeamChampionshipService::class)->linkEvent(auth()->user(), $this->getOwnerRecord(), CompetitiveEvent::query()->findOrFail($data['event_id'])), 'رُبط الحدث.')),
            ])
            ->actions([
                Tables\Actions\Action::make('unlink')->label('فك الربط')->color('danger')->requiresConfirmation()->visible(fn () => $this->editable())
                    ->action(fn (CompetitiveEvent $record) => $this->guard(fn () => app(TeamChampionshipService::class)->unlinkEvent(auth()->user(), $this->getOwnerRecord(), $record), 'فُكّ الربط.')),
            ]);
    }

    protected function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (TeamException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}

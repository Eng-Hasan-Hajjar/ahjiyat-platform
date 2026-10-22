<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\DescribesDiscussionSubjects;
use App\Filament\Resources\DiscussionThreadResource\Pages;
use App\Models\DiscussionThread;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Engagement\EngagementException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E36 - إدارة النقاشات: قائمة النقاشات (نوع الموضوع، عنوانه الآن، عدّاد الحيّ، القفل، آخر نشاط) وقفل/فتح نقاش بسبب اختياري عبر DiscussionModerationService (discussions.moderate + تدقيق).
 * القفل يمنع تعليقًا/ردًّا/إعجابًا/تعديلًا جديدًا ويُبقي القراءة والبلاغ. الكيان الذي لم يعد عامًّا يظهر بنص محايد بلا عنوان أو رابط. لا إنشاء ولا حذف.
 */
class DiscussionThreadResource extends Resource
{
    use DescribesDiscussionSubjects;

    protected static ?string $model = DiscussionThread::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'النقاشات';

    protected static ?string $modelLabel = 'نقاش';

    protected static ?string $pluralModelLabel = 'النقاشات';

    protected static ?int $navigationSort = 87;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lockedBy:id,name'))
            ->defaultSort('last_activity_at', 'desc')
            ->defaultPaginationPageOption((int) config('discussions.moderation.page_size', 20))
            ->paginationPageOptions([10, (int) config('discussions.moderation.page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('subject_type')->label('النوع')->formatStateUsing(fn ($state) => DiscussionThread::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('subject')->label('الموضوع')->limit(48)->state(fn (DiscussionThread $t, $livewire) => self::subjectOf($livewire, $t)['title']),
                Tables\Columns\TextColumn::make('comments_count')->label('تعليقات ظاهرة')->numeric()->sortable(),
                Tables\Columns\IconColumn::make('is_locked')->label('مقفل')->boolean(),
                Tables\Columns\TextColumn::make('lock_reason')->label('سبب القفل')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('lockedBy.name')->label('قفله')->placeholder('—'),
                Tables\Columns\TextColumn::make('last_activity_at')->label('آخر نشاط')->dateTime('Y-m-d H:i')->sortable()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_locked')->label('مقفل'),
                Tables\Filters\SelectFilter::make('subject_type')->label('النوع')->options(DiscussionThread::TYPES),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('فتح الصفحة')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (DiscussionThread $t, $livewire) => self::subjectOf($livewire, $t)['url'], shouldOpenInNewTab: true)
                    ->visible(fn (DiscussionThread $t, $livewire) => self::subjectOf($livewire, $t)['url'] !== null),
                Tables\Actions\Action::make('lock')->label('قفل')->color('warning')->icon('heroicon-o-lock-closed')
                    ->form([Forms\Components\Textarea::make('note')->label('السبب (اختياري)')->maxLength((int) config('discussions.moderation.note_max'))])
                    ->visible(fn (DiscussionThread $t) => ! $t->is_locked && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionThread $record, array $data) => self::guard(fn () => app(DiscussionModerationService::class)->lockThread($record, auth()->user(), $data['note'] ?? null), 'قُفل النقاش.')),
                Tables\Actions\Action::make('unlock')->label('فتح')->color('success')->icon('heroicon-o-lock-open')->requiresConfirmation()
                    ->visible(fn (DiscussionThread $t) => $t->is_locked && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionThread $record) => self::guard(fn () => app(DiscussionModerationService::class)->unlockThread($record, auth()->user()), 'فُتح النقاش.')),
            ]);
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (EngagementException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDiscussionThreads::route('/')];
    }
}

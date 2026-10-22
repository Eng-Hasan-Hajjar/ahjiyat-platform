<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\DescribesDiscussionSubjects;
use App\Filament\Resources\DiscussionCommentResource\Pages;
use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
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
 * E36 - إشراف التعليقات: قائمة كل التعليقات والردود بمرشّحات (الحالة، بلاغات مفتوحة، نوع الموضوع، رد/جذر، نقاش مقفل) وإجراءات إخفاء (بسبب منظَّم من قائمة مغلقة) / استعادة / قفل-فتح النقاش
 * عبر DiscussionModerationService (discussions.moderate + تدقيق). المشرف يقرأ نص التعليق المخفي هنا فقط (لا يخرج من هذه الشاشة إلى العامة). لا تعديل لنص أحد، لا كتابة باسم أحد، لا حذف.
 */
class DiscussionCommentResource extends Resource
{
    use DescribesDiscussionSubjects;

    protected static ?string $model = DiscussionComment::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'التعليقات';

    protected static ?string $modelLabel = 'تعليق';

    protected static ?string $pluralModelLabel = 'التعليقات';

    protected static ?int $navigationSort = 86;

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

    public static function stateLabel(DiscussionComment $c): string
    {
        return $c->deleted_at !== null ? 'محذوف' : ($c->status === DiscussionComment::STATUS_HIDDEN ? 'مخفي' : 'ظاهر');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['author:id,name', 'thread:id,subject_type,subject_id,is_locked'])
                ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('status', DiscussionCommentReport::STATUS_OPEN)]))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption((int) config('discussions.moderation.page_size', 20))
            ->paginationPageOptions([10, (int) config('discussions.moderation.page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('state')->label('الحالة')->badge()->state(fn (DiscussionComment $c) => self::stateLabel($c))
                    ->color(fn ($state) => ['ظاهر' => 'success', 'مخفي' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('body')->label('النص')->limit(70)->placeholder('—')->tooltip(fn (DiscussionComment $c) => $c->deleted_at === null ? (string) $c->body : null),
                Tables\Columns\TextColumn::make('author.name')->label('الكاتب')->placeholder('—'),
                Tables\Columns\TextColumn::make('kind')->label('النوع')->state(fn (DiscussionComment $c) => $c->parent_id === null ? 'تعليق' : 'رد'),
                Tables\Columns\TextColumn::make('subject')->label('الموضوع')->limit(36)->state(fn (DiscussionComment $c, $livewire) => self::subjectOf($livewire, $c->thread)['title']),
                Tables\Columns\TextColumn::make('likes_count')->label('إعجابات')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('open_reports_count')->label('بلاغات مفتوحة')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('moderation_reason')->label('سبب الإخفاء')->formatStateUsing(fn ($state) => DiscussionCommentReport::REASONS[$state] ?? $state)->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('الحالة')->options(['visible' => 'ظاهر', 'hidden' => 'مخفي', 'deleted' => 'محذوف'])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'visible' => $query->where('discussion_comments.status', DiscussionComment::STATUS_VISIBLE)->whereNull('discussion_comments.deleted_at'),
                            'hidden' => $query->where('discussion_comments.status', DiscussionComment::STATUS_HIDDEN)->whereNull('discussion_comments.deleted_at'),
                            'deleted' => $query->whereNotNull('discussion_comments.deleted_at'),
                            default => $query,
                        };
                    }),
                Tables\Filters\Filter::make('has_open_reports')->label('عليها بلاغات مفتوحة')
                    ->query(fn (Builder $query) => $query->whereHas('reports', fn ($q) => $q->where('status', DiscussionCommentReport::STATUS_OPEN))),
                Tables\Filters\SelectFilter::make('subject_type')->label('نوع الموضوع')->options(DiscussionThread::TYPES)
                    ->query(fn (Builder $query, array $data) => ($data['value'] ?? null) ? $query->whereHas('thread', fn ($q) => $q->where('subject_type', $data['value'])) : $query),
                Tables\Filters\TernaryFilter::make('is_reply')->label('رد')->placeholder('الكل')->trueLabel('الردود فقط')->falseLabel('التعليقات الجذرية فقط')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('discussion_comments.parent_id'),
                        false: fn (Builder $query) => $query->whereNull('discussion_comments.parent_id'),
                        blank: fn (Builder $query) => $query,
                    ),
                Tables\Filters\Filter::make('locked')->label('نقاش مقفل')
                    ->query(fn (Builder $query) => $query->whereHas('thread', fn ($q) => $q->where('is_locked', true))),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('فتح')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (DiscussionComment $c) => route('discussions.permalink', $c->public_id), shouldOpenInNewTab: true)
                    ->visible(fn (DiscussionComment $c) => $c->isLive()),
                Tables\Actions\Action::make('hide')->label('إخفاء')->color('danger')->icon('heroicon-o-eye-slash')
                    ->form([
                        Forms\Components\Select::make('reason')->label('السبب')->options(DiscussionCommentReport::REASONS)->required(),
                        Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength((int) config('discussions.moderation.note_max')),
                    ])
                    ->visible(fn (DiscussionComment $c) => $c->isLive() && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionComment $record, array $data) => self::guard(fn () => app(DiscussionModerationService::class)->hide($record, auth()->user(), (string) $data['reason'], $data['note'] ?? null), 'أُخفي التعليق.')),
                Tables\Actions\Action::make('restore')->label('استعادة')->color('success')->icon('heroicon-o-eye')->requiresConfirmation()
                    ->visible(fn (DiscussionComment $c) => $c->isHidden() && ! $c->isDeleted() && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionComment $record) => self::guard(fn () => app(DiscussionModerationService::class)->restore($record, auth()->user()), 'استُعيد التعليق.')),
                Tables\Actions\Action::make('lock')->label('قفل النقاش')->color('warning')->icon('heroicon-o-lock-closed')
                    ->form([Forms\Components\Textarea::make('note')->label('السبب (اختياري)')->maxLength((int) config('discussions.moderation.note_max'))])
                    ->visible(fn (DiscussionComment $c) => $c->thread !== null && ! $c->thread->is_locked && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionComment $record, array $data) => self::guard(fn () => app(DiscussionModerationService::class)->lockThread($record->thread, auth()->user(), $data['note'] ?? null), 'قُفل النقاش.')),
                Tables\Actions\Action::make('unlock')->label('فتح النقاش')->color('success')->icon('heroicon-o-lock-open')->requiresConfirmation()
                    ->visible(fn (DiscussionComment $c) => $c->thread !== null && $c->thread->is_locked && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionComment $record) => self::guard(fn () => app(DiscussionModerationService::class)->unlockThread($record->thread, auth()->user()), 'فُتح النقاش.')),
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
        return ['index' => Pages\ListDiscussionComments::route('/')];
    }
}

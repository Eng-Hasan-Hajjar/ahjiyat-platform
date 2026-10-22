<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\DescribesDiscussionSubjects;
use App\Filament\Resources\DiscussionCommentReportResource\Pages;
use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Engagement\EngagementException;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E36 - طابور بلاغات التعليقات. يعرض لقطة النص لحظة البلاغ (الدليل، تبقى مهما عدّل صاحب التعليق أو حذف) وحالة التعليق الآن وسبب البلاغ (قائمة مغلقة) واسم المُبلِّغ (الاسم فقط، للإشراف وحده).
 * الإجراءات (رفض / معالجة / معالجة وإخفاء التعليق) عبر DiscussionModerationService بصلاحية discussions.moderate (تدقيق + ذرّية). لا إنشاء ولا تعديل خام ولا حذف.
 * مالك المحتوى ومبدعه لا يملكان هذه الشاشة: الإشراف سلطة المنصة وحدها.
 */
class DiscussionCommentReportResource extends Resource
{
    use DescribesDiscussionSubjects;

    protected static ?string $model = DiscussionCommentReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'بلاغات التعليقات';

    protected static ?string $modelLabel = 'بلاغ تعليق';

    protected static ?string $pluralModelLabel = 'بلاغات التعليقات';

    protected static ?int $navigationSort = 85;

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

    public static function statusLabel(string $s): string
    {
        return ['open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج'][$s] ?? $s;
    }

    public static function commentState(?DiscussionComment $c): string
    {
        if ($c === null) {
            return '—';
        }

        return $c->deleted_at !== null ? 'محذوف' : ($c->status === DiscussionComment::STATUS_HIDDEN ? 'مخفي' : 'ظاهر');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['comment:id,public_id,discussion_thread_id,user_id,status,deleted_at', 'comment.author:id,name', 'comment.thread:id,subject_type,subject_id', 'reporter:id,name']))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption((int) config('discussions.moderation.page_size', 20))
            ->paginationPageOptions([10, (int) config('discussions.moderation.page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel((string) $state))
                    ->color(fn ($state) => ['open' => 'warning', 'actioned' => 'success'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('body_snapshot')->label('النص لحظة البلاغ')->limit(60)->tooltip(fn (DiscussionCommentReport $r) => (string) $r->body_snapshot),
                Tables\Columns\TextColumn::make('comment_state')->label('التعليق الآن')->state(fn (DiscussionCommentReport $r) => self::commentState($r->comment)),
                Tables\Columns\TextColumn::make('subject')->label('الموضوع')->limit(40)->state(fn (DiscussionCommentReport $r, $livewire) => self::subjectOf($livewire, $r->comment?->thread)['title']),
                Tables\Columns\TextColumn::make('comment.author.name')->label('الكاتب')->placeholder('—'),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->formatStateUsing(fn ($state) => DiscussionCommentReport::REASONS[$state] ?? $state),
                Tables\Columns\TextColumn::make('reporter.name')->label('المُبلِّغ')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج'])->default(DiscussionCommentReport::STATUS_OPEN),
                Tables\Filters\SelectFilter::make('reason')->label('السبب')->options(DiscussionCommentReport::REASONS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('open')->label('فتح التعليق')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (DiscussionCommentReport $r) => route('discussions.permalink', $r->comment->public_id), shouldOpenInNewTab: true)
                    ->visible(fn (DiscussionCommentReport $r) => $r->comment !== null && $r->comment->isLive()),
                self::closeAction('dismiss', 'رفض البلاغ', 'gray', fn (DiscussionModerationService $s, DiscussionCommentReport $r, ?string $n) => $s->dismiss($r, auth()->user(), $n), 'رُفض البلاغ.'),
                self::closeAction('action', 'تمت المعالجة', 'success', fn (DiscussionModerationService $s, DiscussionCommentReport $r, ?string $n) => $s->action($r, auth()->user(), $n), 'حُسم البلاغ.'),
                Tables\Actions\Action::make('hide')->label('معالجة وإخفاء التعليق')->color('danger')->icon('heroicon-o-eye-slash')
                    ->form([Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength((int) config('discussions.moderation.note_max'))])
                    ->visible(fn (DiscussionCommentReport $r) => $r->status === DiscussionCommentReport::STATUS_OPEN && (auth()->user()?->can('discussions.moderate') ?? false))
                    ->action(fn (DiscussionCommentReport $record, array $data) => self::guard(fn () => app(DiscussionModerationService::class)->actionAndHide($record, auth()->user(), $data['note'] ?? null), 'أُخفي التعليق وحُسمت البلاغات المفتوحة عليه.')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('البلاغ')->columns(3)->schema([
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel((string) $state)),
                Infolists\Components\TextEntry::make('reason')->label('السبب')->formatStateUsing(fn ($state) => DiscussionCommentReport::REASONS[$state] ?? $state),
                Infolists\Components\TextEntry::make('reporter.name')->label('المُبلِّغ')->placeholder('—'),
                Infolists\Components\TextEntry::make('created_at')->label('وقت البلاغ')->dateTime('Y-m-d H:i'),
                Infolists\Components\TextEntry::make('handled_at')->label('وقت المعالجة')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('handler_note')->label('ملاحظة المعالج')->placeholder('—'),
                Infolists\Components\TextEntry::make('body_snapshot')->label('النص لحظة البلاغ (الدليل)')->columnSpanFull(),
            ]),
        ]);
    }

    /** @param \Closure(DiscussionModerationService, DiscussionCommentReport, ?string): mixed $call */
    protected static function closeAction(string $name, string $label, string $color, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->form([Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength((int) config('discussions.moderation.note_max'))])
            ->visible(fn (DiscussionCommentReport $r) => $r->status === DiscussionCommentReport::STATUS_OPEN && (auth()->user()?->can('discussions.moderate') ?? false))
            ->action(fn (DiscussionCommentReport $record, array $data) => self::guard(fn () => $call(app(DiscussionModerationService::class), $record, $data['note'] ?? null), $ok));
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
        return ['index' => Pages\ListDiscussionCommentReports::route('/'), 'view' => Pages\ViewDiscussionCommentReport::route('/{record}')];
    }
}

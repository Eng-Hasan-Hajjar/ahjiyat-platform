<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorReviewService;
use App\Services\Creators\CreatorVisibilityService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E28 - مركز مراجعة الأحجيات المرسلة. الصف = نسخة من عمل مبدع. القائمة لا تقرأ عمود content المشفَّر (لا فكّ تشفير ولا جواب خام بالجداول)،
 * وصفحة التفاصيل تعرض نص السؤال والتلميح فقط؛ **الجواب الصحيح يظهر للمراجع في صفحة المعاينة** (creators.review) لا هنا.
 * الإجراءات (اعتماد/طلب تعديل/رفض/إخفاء/استعادة) كلها عبر CreatorReviewService وCreatorVisibilityService (تدقيق + إشعار + ذرّية). لا إنشاء ولا تعديل خام ولا حذف.
 */
class CreatorPuzzleRevisionResource extends Resource
{
    protected static ?string $model = CreatorPuzzleRevision::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'مراجعة أحجيات المجتمع';

    protected static ?string $modelLabel = 'نسخة أحجية';

    protected static ?string $pluralModelLabel = 'أحجيات المجتمع المرسلة';

    protected static ?int $navigationSort = 81;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['draft' => 'مسودة', 'pending_review' => 'قيد المراجعة', 'changes_requested' => 'تحتاج تعديلات', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query
                ->select('id', 'creator_puzzle_id', 'revision_number', 'status', 'kind', 'title', 'puzzle_category_id', 'difficulty', 'submitted_at', 'reviewed_at', 'review_note', 'puzzle_id')
                ->where('status', '!=', CreatorPuzzleRevision::STATUS_DRAFT) // المسودات خاصة بالمبدع ولا تصل المراجع
                ->with(['work:id,public_id,creator_profile_id,published_revision_id,hidden_at,hidden_reason,is_visible', 'work.creator:id,user_id', 'work.creator.user:id,name', 'category:id,name']))
            ->defaultSort('submitted_at', 'asc')
            ->defaultPaginationPageOption((int) config('creators.moderation_page_size', 20))
            ->paginationPageOptions([10, (int) config('creators.moderation_page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('kind')->label('النوع')->formatStateUsing(fn ($state) => config("creators.kinds.{$state}.label", $state)),
                Tables\Columns\TextColumn::make('work.creator.user.name')->label('المبدع'),
                Tables\Columns\TextColumn::make('revision_number')->label('النسخة'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['pending_review' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'changes_requested' => 'info'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('visibility')->label('الظهور')->state(fn (CreatorPuzzleRevision $r) => self::visibilityLabel($r)),
                Tables\Columns\TextColumn::make('submitted_at')->label('أُرسلت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(array_diff_key(
                    array_combine(CreatorPuzzleRevision::STATUSES, array_map(fn ($s) => self::statusLabel($s), CreatorPuzzleRevision::STATUSES)),
                    ['draft' => true],
                ))->default(CreatorPuzzleRevision::STATUS_PENDING_REVIEW),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('preview')->label('معاينة ولوحة الحل')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (CreatorPuzzleRevision $r) => route('community.review.preview', $r), shouldOpenInNewTab: true)
                    ->visible(fn () => auth()->user()?->can('creators.review') ?? false),
                self::reviewAction('approve', 'اعتماد ونشر', 'success', false, fn (CreatorReviewService $s, CreatorPuzzleRevision $r, ?string $n) => $s->approve($r, auth()->user(), $n), 'اعتُمدت الأحجية ونُشرت.'),
                self::reviewAction('changes', 'طلب تعديلات', 'warning', true, fn (CreatorReviewService $s, CreatorPuzzleRevision $r, ?string $n) => $s->requestChanges($r, auth()->user(), (string) $n), 'أُرسل طلب التعديل للمبدع.'),
                self::reviewAction('reject', 'رفض', 'danger', true, fn (CreatorReviewService $s, CreatorPuzzleRevision $r, ?string $n) => $s->reject($r, auth()->user(), (string) $n), 'رُفضت الأحجية وأُبلغ المبدع.'),
                self::hideAction(),
                self::restoreAction(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('النسخة')->columns(3)->schema([
                Infolists\Components\TextEntry::make('title')->label('العنوان'),
                Infolists\Components\TextEntry::make('kind')->label('النوع')->formatStateUsing(fn ($state) => config("creators.kinds.{$state}.label", $state)),
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel($state)),
                Infolists\Components\TextEntry::make('work.creator.user.name')->label('المبدع'),
                Infolists\Components\TextEntry::make('category.name')->label('التصنيف')->placeholder('—'),
                Infolists\Components\TextEntry::make('difficulty')->label('الصعوبة'),
                Infolists\Components\TextEntry::make('revision_number')->label('رقم النسخة'),
                Infolists\Components\TextEntry::make('submitted_at')->label('وقت الإرسال')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('reviewed_at')->label('وقت القرار')->dateTime('Y-m-d H:i')->placeholder('—'),
            ]),
            // نص السؤال والتلميح فقط (نص عادي مهرَّب). الجواب الصحيح لا يُعرض هنا؛ يراه المراجع بصفحة المعاينة.
            Infolists\Components\Section::make('المحتوى المعروض للاعبين')->schema([
                Infolists\Components\TextEntry::make('prompt')->label('السؤال')->state(fn (CreatorPuzzleRevision $r) => (string) (($r->content ?? [])['prompt'] ?? ''))->columnSpanFull(),
                Infolists\Components\TextEntry::make('hint')->label('التلميح')->state(fn (CreatorPuzzleRevision $r) => (string) (($r->content ?? [])['hint'] ?? ''))->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('القرار')->schema([
                Infolists\Components\TextEntry::make('review_note')->label('ملاحظة المراجع')->placeholder('—'),
            ]),
        ]);
    }

    protected static function visibilityLabel(CreatorPuzzleRevision $r): string
    {
        $work = $r->work;

        if ($r->status !== CreatorPuzzleRevision::STATUS_APPROVED || $work === null || $work->published_revision_id !== $r->id) {
            return '—';
        }

        return $work->is_visible ? 'ظاهرة للعامة' : ($work->hidden_reason === CreatorPuzzle::HIDDEN_MODERATION ? 'مخفية إشرافيًا' : 'غير ظاهرة');
    }

    /** @param \Closure(CreatorReviewService, CreatorPuzzleRevision, ?string): mixed $call */
    protected static function reviewAction(string $name, string $label, string $color, bool $noteRequired, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->form([Forms\Components\Textarea::make('note')->label($noteRequired ? 'السبب (إلزامي، يظهر للمبدع)' : 'ملاحظة (اختياري)')->required($noteRequired)->maxLength((int) config('creators.limits.reason_max'))])
            ->visible(fn (CreatorPuzzleRevision $r) => $r->status === CreatorPuzzleRevision::STATUS_PENDING_REVIEW && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorPuzzleRevision $record, array $data) => self::guard(fn () => $call(app(CreatorReviewService::class), $record, $data['note'] ?? null), $ok));
    }

    protected static function hideAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('hide')->label('إخفاء المنشور')->color('danger')->icon('heroicon-o-eye-slash')
            ->form([Forms\Components\Textarea::make('note')->label('السبب (إلزامي)')->required()->maxLength((int) config('creators.limits.reason_max'))])
            ->visible(fn (CreatorPuzzleRevision $r) => self::isLiveRevision($r) && ! $r->work->isHidden() && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorPuzzleRevision $record, array $data) => self::guard(fn () => app(CreatorVisibilityService::class)->hide($record->work, auth()->user(), (string) $data['note']), 'أُخفي المنشور وأُبلغ المبدع.'));
    }

    protected static function restoreAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore')->label('استعادة المنشور')->color('success')->icon('heroicon-o-eye')
            ->requiresConfirmation()
            ->visible(fn (CreatorPuzzleRevision $r) => self::isLiveRevision($r) && $r->work->hidden_reason === CreatorPuzzle::HIDDEN_MODERATION && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorPuzzleRevision $record) => self::guard(fn () => app(CreatorVisibilityService::class)->restore($record->work, auth()->user()), 'استُعيد المنشور.'));
    }

    /** النسخة المعتمدة التي هي المنشورة حاليًا للعمل (لا النسخ القديمة). */
    protected static function isLiveRevision(CreatorPuzzleRevision $r): bool
    {
        return $r->status === CreatorPuzzleRevision::STATUS_APPROVED && $r->work !== null && $r->work->published_revision_id === $r->id;
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (CreatorException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCreatorPuzzleRevisions::route('/'), 'view' => Pages\ViewCreatorPuzzleRevision::route('/{record}')];
    }
}

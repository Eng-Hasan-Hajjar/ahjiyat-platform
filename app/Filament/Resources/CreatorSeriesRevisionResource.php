<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CreatorSeriesRevisionResource\Pages;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorPremium\CreatorPremiumPublishGuard;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorVisibilityService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - مركز مراجعة سلاسل المبدعين المرسلة. الصف = نسخة من سلسلة مبدع (المسودات خاصة بالمبدع ولا تصل المراجع). القائمة مرقَّمة بحجم ثابت وبلا N+1:
 * عدد العناصر غير الظاهرة يُحسب باستعلام فرعي واحد ضمن الصفحة. صفحة التفاصيل تعرض **ظهور كل عنصر على حدة** وأسباب عدم ظهور السلسلة.
 * الإجراءات: اعتماد/طلب تعديل/رفض (CreatorSeriesReviewService) + إخفاء/استعادة السلسلة + إخفاء/استعادة **عنصر** (أحجية) عبر CreatorVisibilityService E28 نفسها
 * — وإخفاء عنصر يخفي السلسلة تلقائيًا بقاعدة الظهور الحيّة. كلها بصلاحية creators.review وبتدقيق وإشعار؛ لا إنشاء ولا تعديل خام ولا حذف.
 */
class CreatorSeriesRevisionResource extends Resource
{
    protected static ?string $model = CreatorSeriesRevision::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'مراجعة سلاسل المجتمع';

    protected static ?string $modelLabel = 'نسخة سلسلة';

    protected static ?string $pluralModelLabel = 'سلاسل المجتمع المرسلة';

    protected static ?int $navigationSort = 82;

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
        $pageSize = (int) config('creator_series.moderation_page_size', 20);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('creator_series_revisions.id', 'creator_series_revisions.creator_series_id', 'creator_series_revisions.revision_number', 'creator_series_revisions.status',
                    'creator_series_revisions.title', 'creator_series_revisions.submitted_at', 'creator_series_revisions.reviewed_at', 'creator_series_revisions.review_note')
                ->selectSub(
                    fn ($q) => $q->from('creator_series_items as hi')->join('creator_puzzles as hw', 'hw.id', '=', 'hi.creator_puzzle_id')
                        ->whereColumn('hi.creator_series_revision_id', 'creator_series_revisions.id')->where('hw.is_visible', false)->selectRaw('COUNT(*)'),
                    'hidden_items',
                )
                ->selectSub(
                    fn ($q) => $q->from('creator_series_items as ci')->whereColumn('ci.creator_series_revision_id', 'creator_series_revisions.id')->selectRaw('COUNT(*)'),
                    'items_total',
                )
                ->where('creator_series_revisions.status', '!=', CreatorSeriesRevision::STATUS_DRAFT) // المسودات خاصة بالمبدع
                ->with(['series:id,public_id,creator_profile_id,published_revision_id,hidden_at,hidden_reason,archived_at', 'series.creator:id,user_id,status', 'series.creator.user:id,name,is_frozen']))
            ->defaultSort('submitted_at', 'asc')
            ->defaultPaginationPageOption($pageSize)
            ->paginationPageOptions([10, $pageSize, 50])
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('series.creator.user.name')->label('المبدع'),
                Tables\Columns\TextColumn::make('items_total')->label('العناصر'),
                Tables\Columns\TextColumn::make('revision_number')->label('النسخة'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['pending_review' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'changes_requested' => 'info'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('visibility')->label('الظهور')->state(fn (CreatorSeriesRevision $r) => self::visibilityLabel($r)),
                Tables\Columns\TextColumn::make('submitted_at')->label('أُرسلت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(array_diff_key(
                    array_combine(CreatorSeriesRevision::STATUSES, array_map(fn ($s) => self::statusLabel($s), CreatorSeriesRevision::STATUSES)),
                    ['draft' => true],
                ))->default(CreatorSeriesRevision::STATUS_PENDING_REVIEW),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::approveAction(),
                self::reviewAction('changes', 'طلب تعديلات', 'warning', true, fn (CreatorSeriesReviewService $s, CreatorSeriesRevision $r, ?string $n) => $s->requestChanges($r, auth()->user(), (string) $n), 'أُرسل طلب التعديل للمبدع.'),
                self::reviewAction('reject', 'رفض', 'danger', true, fn (CreatorSeriesReviewService $s, CreatorSeriesRevision $r, ?string $n) => $s->reject($r, auth()->user(), (string) $n), 'رُفضت السلسلة وأُبلغ المبدع.'),
                self::hideAction(),
                self::restoreAction(),
                self::hideItemAction(),
                self::restoreItemAction(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('النسخة')->columns(3)->schema([
                Infolists\Components\TextEntry::make('title')->label('العنوان'),
                Infolists\Components\TextEntry::make('series.creator.user.name')->label('المبدع'),
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel($state)),
                Infolists\Components\TextEntry::make('revision_number')->label('رقم النسخة'),
                Infolists\Components\TextEntry::make('submitted_at')->label('وقت الإرسال')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('reviewed_at')->label('وقت القرار')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('visibility')->label('الظهور الآن')->state(fn (CreatorSeriesRevision $r) => self::visibilityLabel($r)),
            ]),
            Infolists\Components\Section::make('الوصف (نص عادي)')->schema([
                Infolists\Components\TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('العناصر وظهور كل عنصر')->schema([
                Infolists\Components\RepeatableEntry::make('item_states')->label('')
                    ->state(fn (CreatorSeriesRevision $r) => app(CreatorSeriesVisibilityService::class)->itemStates((int) $r->id))
                    ->columns(3)->schema([
                        Infolists\Components\TextEntry::make('position')->label('الموضع'),
                        Infolists\Components\TextEntry::make('title')->label('الأحجية'),
                        Infolists\Components\TextEntry::make('visible')->label('الظهور')->formatStateUsing(fn ($state) => $state ? 'ظاهرة' : 'غير ظاهرة (مخفية/مؤرشفة/غير منشورة)'),
                    ]),
            ]),
            Infolists\Components\Section::make('القرار')->schema([
                Infolists\Components\TextEntry::make('review_note')->label('ملاحظة المراجع')->placeholder('—'),
            ]),
        ]);
    }

    protected static function visibilityLabel(CreatorSeriesRevision $r): string
    {
        $series = $r->series;

        if (! self::isLiveRevision($r)) {
            return '—';
        }

        $creator = $series->creator;

        return match (true) {
            $series->isHidden() => 'مخفية إشرافيًا',
            $series->isArchived() => 'مؤرشفة',
            $creator === null || ! $creator->isApproved() => 'مبدع معلَّق',
            (bool) $creator->user?->is_frozen => 'حساب المبدع مقيَّد',
            (int) ($r->getAttribute('hidden_items') ?? 0) > 0 => 'عنصر غير ظاهر ('.(int) $r->getAttribute('hidden_items').')',
            default => 'ظاهرة للعامة',
        };
    }

    /** E43: الاعتماد، مع خيار تأكيد صريح يظهر فقط حين تُسقط النسخة أحجيات من سلسلة لها مشترون (يُدقَّق ويلزمه ملاحظة). */
    protected static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')->label('اعتماد ونشر')->color('success')
            ->form(fn (CreatorSeriesRevision $record) => [
                Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength((int) config('creator_series.limits.note_max')),
                Forms\Components\Toggle::make('override_sold_removal')
                    ->label('تأكيد صريح: هذه النسخة تُسقط أحجيات من سلسلة لها مشترون (الملاحظة إلزامية)')
                    ->visible(fn () => app(CreatorPremiumPublishGuard::class)->soldItemsRemoved($record->series, $record) !== []),
            ])
            ->visible(fn (CreatorSeriesRevision $r) => $r->status === CreatorSeriesRevision::STATUS_PENDING_REVIEW && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorSeriesRevision $record, array $data) => self::guard(fn () => app(CreatorSeriesReviewService::class)->approve($record, auth()->user(), $data['note'] ?? null, (bool) ($data['override_sold_removal'] ?? false)), 'اعتُمدت السلسلة ونُشرت.'));
    }

    /** @param \Closure(CreatorSeriesReviewService, CreatorSeriesRevision, ?string): mixed $call */
    protected static function reviewAction(string $name, string $label, string $color, bool $noteRequired, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->form([Forms\Components\Textarea::make('note')->label($noteRequired ? 'السبب (إلزامي، يظهر للمبدع)' : 'ملاحظة (اختياري)')->required($noteRequired)->maxLength((int) config('creator_series.limits.note_max'))])
            ->visible(fn (CreatorSeriesRevision $r) => $r->status === CreatorSeriesRevision::STATUS_PENDING_REVIEW && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorSeriesRevision $record, array $data) => self::guard(fn () => $call(app(CreatorSeriesReviewService::class), $record, $data['note'] ?? null), $ok));
    }

    protected static function hideAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('hide')->label('إخفاء السلسلة')->color('danger')->icon('heroicon-o-eye-slash')
            ->form([Forms\Components\Textarea::make('note')->label('السبب (إلزامي)')->required()->maxLength((int) config('creator_series.limits.note_max'))])
            ->visible(fn (CreatorSeriesRevision $r) => self::isLiveRevision($r) && ! $r->series->isHidden() && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorSeriesRevision $record, array $data) => self::guard(fn () => app(CreatorSeriesReviewService::class)->hide($record->series, auth()->user(), (string) $data['note']), 'أُخفيت السلسلة وأُبلغ المبدع.'));
    }

    protected static function restoreAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore')->label('استعادة السلسلة')->color('success')->icon('heroicon-o-eye')
            ->requiresConfirmation()
            ->visible(fn (CreatorSeriesRevision $r) => self::isLiveRevision($r) && $r->series->hidden_reason === CreatorSeries::HIDDEN_MODERATION && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorSeriesRevision $record) => self::guard(fn () => app(CreatorSeriesReviewService::class)->restore($record->series, auth()->user()), 'استُعيدت السلسلة.'));
    }

    /** إخفاء أحجية (عنصر) من السلسلة المنشورة: يخفيها من كل المنصة (E28) ومعها كل سلسلة تحويها. */
    protected static function hideItemAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('hide_item')->label('إخفاء أحجية من السلسلة')->color('danger')->icon('heroicon-o-eye-slash')
            ->form([
                Forms\Components\Select::make('work')->label('الأحجية')->required()
                    ->options(fn (CreatorSeriesRevision $r) => collect(app(CreatorSeriesVisibilityService::class)->itemStates((int) $r->id))->where('visible', true)->mapWithKeys(fn ($i) => [$i['public_id'] => $i['position'].'. '.$i['title']])->all()),
                Forms\Components\Textarea::make('note')->label('السبب (إلزامي)')->required()->maxLength((int) config('creators.limits.reason_max')),
            ])
            ->visible(fn (CreatorSeriesRevision $r) => self::isLiveRevision($r) && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorSeriesRevision $record, array $data) => self::guard(function () use ($record, $data) {
                $work = self::itemWork($record, (string) $data['work']);
                app(CreatorVisibilityService::class)->hide($work, auth()->user(), (string) $data['note']);
            }, 'أُخفيت الأحجية وبالتالي السلسلة، وأُبلغ المبدع.'));
    }

    protected static function restoreItemAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore_item')->label('استعادة أحجية مخفية')->color('success')->icon('heroicon-o-eye')
            ->form([
                Forms\Components\Select::make('work')->label('الأحجية')->required()
                    ->options(fn (CreatorSeriesRevision $r) => collect(app(CreatorSeriesVisibilityService::class)->itemStates((int) $r->id))->where('visible', false)->mapWithKeys(fn ($i) => [$i['public_id'] => $i['position'].'. '.$i['title']])->all()),
            ])
            ->visible(fn (CreatorSeriesRevision $r) => self::isLiveRevision($r) && (int) $r->getAttribute('hidden_items') > 0 && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CreatorSeriesRevision $record, array $data) => self::guard(function () use ($record, $data) {
                app(CreatorVisibilityService::class)->restore(self::itemWork($record, (string) $data['work']), auth()->user());
            }, 'استُعيدت الأحجية.'));
    }

    /** العمل المختار يجب أن يكون عنصرًا فعلًا في هذه النسخة (لا إخفاء أحجية عشوائية عبر نموذج مزوَّر). */
    protected static function itemWork(CreatorSeriesRevision $revision, string $publicId): CreatorPuzzle
    {
        $work = CreatorPuzzle::query()->where('public_id', $publicId)
            ->whereIn('id', fn ($q) => $q->select('creator_puzzle_id')->from('creator_series_items')->where('creator_series_revision_id', $revision->id))
            ->first();

        if ($work === null) {
            throw new CreatorSeriesException('الأحجية ليست ضمن عناصر هذه السلسلة.');
        }

        return $work;
    }

    /** النسخة المعتمدة التي هي المنشورة حاليًا للسلسلة (لا النسخ القديمة). */
    protected static function isLiveRevision(CreatorSeriesRevision $r): bool
    {
        return $r->status === CreatorSeriesRevision::STATUS_APPROVED && $r->series !== null && (int) $r->series->published_revision_id === (int) $r->id;
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
        return ['index' => Pages\ListCreatorSeriesRevisions::route('/'), 'view' => Pages\ViewCreatorSeriesRevision::route('/{record}')];
    }
}

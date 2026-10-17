# E30 — DELIVERY PACK (A–E)

NO GIT COMMANDS. أساس المقارنة: remote `main` = v29 (`58d3882c4c66d538b3712d856034731033586685`). هذا الملف خارج المستودع عمدًا (لا يُنسخ إليه). لا حزم جديدة (0 Composer / 0 npm)، `composer.lock` و`composer.json` و`package*.json` لم تُمسّ، وأصول الواجهة (CSS/JS) لم تتغيّر (Blade فقط) فلا `npm run build`.

الملفات: **112 جديدًا** (منها 3 ترحيلات) + **29 معدَّلًا** (كاملة). التحقق بعد النسخ: `sha256sum <path>` مقابل `manifest-sha256.txt`.

## الفهرس

- A) NEW FILES (محتوى كامل، بلا الترحيلات)
- B) MODIFIED FILES (ملفات كاملة)
- C) MIGRATIONS (3 جديدة، محتوى كامل)
- D) ENV / CONFIG
- E) LOCAL COMMANDS

---

## A) NEW FILES — المسار + المحتوى الكامل (109 ملفًا)

### `app/Console/Commands/LockSponsorshipEventPlans.php`

`sha256: a2854b00406c0d6d15bd15bf3f82e2dc7b670375946e58ae9e4b36c0c61231fc`

`````php
<?php

namespace App\Console\Commands;

use App\Services\Sponsorship\EventRewardPlanService;
use Illuminate\Console\Command;

class LockSponsorshipEventPlans extends Command
{
    protected $signature = 'sponsorship:lock-event-plans';

    protected $description = 'E30: يقفل خطط تعزيز مكافآت المنافسات القريبة من البدء (لقطة التمويل قبل البدء). Idempotent وآمن للتشغيل المتكرر؛ يُجدوَل كل 5 دقائق.';

    public function handle(EventRewardPlanService $plans): int
    {
        try {
            $count = $plans->lockUpcoming();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل قفل خطط الرعاية: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("locked={$count}");

        return self::SUCCESS;
    }
}
`````

### `app/Filament/Resources/CreatorSeriesRevisionResource.php`

`sha256: b40213337d11d9880d6f78ad42448200bfef686a81536a27a07ecf5fd8e7e54c`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CreatorSeriesRevisionResource\Pages;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesException;
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
                self::reviewAction('approve', 'اعتماد ونشر', 'success', false, fn (CreatorSeriesReviewService $s, CreatorSeriesRevision $r, ?string $n) => $s->approve($r, auth()->user(), $n), 'اعتُمدت السلسلة ونُشرت.'),
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
`````

### `app/Filament/Resources/CreatorSeriesRevisionResource/Pages/ListCreatorSeriesRevisions.php`

`sha256: cabb1ea38636db3b542cd2e8a5edea6d2532032d10f83507591cf3a1b970482f`

`````php
<?php

namespace App\Filament\Resources\CreatorSeriesRevisionResource\Pages;

use App\Filament\Resources\CreatorSeriesRevisionResource;
use Filament\Resources\Pages\ListRecords;

class ListCreatorSeriesRevisions extends ListRecords
{
    protected static string $resource = CreatorSeriesRevisionResource::class;
}
`````

### `app/Filament/Resources/CreatorSeriesRevisionResource/Pages/ViewCreatorSeriesRevision.php`

`sha256: 2b79effb800b769be783d823dff95eaec1bf668e124059b0b358d706d9535648`

`````php
<?php

namespace App\Filament\Resources\CreatorSeriesRevisionResource\Pages;

use App\Filament\Resources\CreatorSeriesRevisionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCreatorSeriesRevision extends ViewRecord
{
    protected static string $resource = CreatorSeriesRevisionResource::class;
}
`````

### `app/Filament/Resources/SponsorshipContributionResource.php`

`sha256: ed2604fba2cb25efe22f089548ffd83e267453e7037cbef77833aa5fac8750af`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipContributionResource\Pages;
use App\Models\SponsorshipContribution;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - المساهمات (مالية): لا إنشاء/تعديل خام ولا حذف من الجدول. التسجيل من زر الصفحة (sponsorships.manage)، والتأكيد/الإلغاء/الاسترداد (sponsorships.confirm) عبر
 * ContributionService حصرًا (تدقيق + قفل صف + حماية الحقول المالية). التصحيح بعد التأكيد = استرداد ثم تسجيل جديد. لا أرقام دفع/بطاقات في أي حقل؛ المرجع نص حر قصير.
 * الملاحظات الإدارية الخاصة تظهر لمن يملك manage فقط وفي أعمدة مخفية افتراضيًا.
 */
class SponsorshipContributionResource extends Resource
{
    protected static ?string $model = SponsorshipContribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'المساهمات';

    protected static ?string $modelLabel = 'مساهمة';

    protected static ?string $pluralModelLabel = 'المساهمات';

    protected static ?int $navigationSort = 92;

    public static function canCreate(): bool
    {
        return false; // التسجيل من إجراء الصفحة (ContributionService::record) لا من نموذج خام
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
        return ['pending' => 'معلّقة', 'confirmed' => 'مؤكَّدة', 'cancelled' => 'ملغاة', 'refunded' => 'مستردة'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('id', 'public_id', 'supporter_profile_id', 'sponsorship_program_id', 'original_amount_minor', 'currency', 'accounting_amount_minor', 'accounting_currency', 'status', 'is_anonymous', 'reference', 'created_at', 'confirmed_at')
                ->with(['supporter:id,display_name', 'program:id,title,accounting_currency']))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('public_id')->label('المعرّف')->limit(10)->copyable(),
                Tables\Columns\TextColumn::make('supporter.display_name')->label('الداعم')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('program.title')->label('البرنامج')->limit(30),
                Tables\Columns\TextColumn::make('original_amount_minor')->label('المبلغ الأصلي')->state(fn (SponsorshipContribution $r) => SponsorshipMoney::format((int) $r->original_amount_minor, $r->currency)),
                Tables\Columns\TextColumn::make('accounting_amount_minor')->label('المعادل المحاسبي')->state(fn (SponsorshipContribution $r) => $r->accounting_amount_minor === null ? '—' : SponsorshipMoney::format((int) $r->accounting_amount_minor, (string) $r->accounting_currency)),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['pending' => 'warning', 'confirmed' => 'success', 'refunded' => 'gray', 'cancelled' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\IconColumn::make('is_anonymous')->label('مجهولة')->boolean(),
                Tables\Columns\TextColumn::make('reference')->label('المرجع')->limit(20)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('سُجّلت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['pending' => 'معلّقة', 'confirmed' => 'مؤكَّدة', 'cancelled' => 'ملغاة', 'refunded' => 'مستردة']),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')->label('تأكيد')->color('success')->icon('heroicon-o-check-circle')
                    ->visible(fn (SponsorshipContribution $r) => $r->status === SponsorshipContribution::STATUS_PENDING && (auth()->user()?->can('sponsorships.confirm') ?? false))
                    ->form(fn (SponsorshipContribution $r) => $r->currency === SponsorshipMoney::accountingCurrency() ? [] : [
                        Forms\Components\TextInput::make('accounting_amount')->label('المعادل بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().') — إلزامي لأن العملة مختلفة')->required()->maxLength(20),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (SponsorshipContribution $record, array $data) => self::guard(fn () => app(ContributionService::class)->confirm(auth()->user(), $record, $data['accounting_amount'] ?? null), 'تم تأكيد المساهمة.')),
                self::reasonAction('cancel', 'إلغاء', 'danger', SponsorshipContribution::STATUS_PENDING, fn (ContributionService $s, SponsorshipContribution $r, string $reason) => $s->cancel(auth()->user(), $r, $reason), 'أُلغيت المساهمة.'),
                self::reasonAction('refund', 'استرداد', 'warning', SponsorshipContribution::STATUS_CONFIRMED, fn (ContributionService $s, SponsorshipContribution $r, string $reason) => $s->refund(auth()->user(), $r, $reason), 'سُجّل الاسترداد (لا تُسحب مكافآت سبق منحها).'),
                Tables\Actions\Action::make('toggle_anonymous')->label('تبديل الإخفاء')->color('gray')->icon('heroicon-o-eye-slash')
                    ->visible(fn () => auth()->user()?->can('sponsorships.manage') ?? false)
                    ->action(fn (SponsorshipContribution $record) => self::guard(fn () => app(ContributionService::class)->setAnonymity(auth()->user(), $record, ! $record->is_anonymous), 'تغيّر إخفاء الهوية.')),
            ]);
    }

    protected static function reasonAction(string $name, string $label, string $color, string $from, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->visible(fn (SponsorshipContribution $r) => $r->status === $from && (auth()->user()?->can('sponsorships.confirm') ?? false))
            ->form([Forms\Components\Textarea::make('reason')->label('السبب (إلزامي)')->required()->minLength(3)->maxLength(255)])
            ->action(fn (SponsorshipContribution $record, array $data) => self::guard(fn () => $call(app(ContributionService::class), $record, (string) $data['reason']), $ok));
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSponsorshipContributions::route('/')];
    }
}
`````

### `app/Filament/Resources/SponsorshipContributionResource/Pages/ListSponsorshipContributions.php`

`sha256: 4c2c5202158d6e66380f94490e1c1afb08276fcbde746bc910f9489c91535255`

`````php
<?php

namespace App\Filament\Resources\SponsorshipContributionResource\Pages;

use App\Filament\Resources\SponsorshipContributionResource;
use App\Filament\Widgets\SponsorshipStatsWidget;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipContributions extends ListRecords
{
    protected static string $resource = SponsorshipContributionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SponsorshipStatsWidget::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('record')->label('تسجيل مساهمة')->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->can('sponsorships.manage') ?? false)
                ->modalDescription('تُسجَّل المساهمة «معلّقة» ولا تُحتسب حتى يؤكّدها مسؤول مالي. لا تُدخل أرقام بطاقات أو حسابات بنكية.')
                ->form([
                    Forms\Components\Select::make('supporter_id')->label('الداعم')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => SupporterProfile::query()->where('status', SupporterProfile::STATUS_ACTIVE)->where('display_name', 'like', '%'.addcslashes($search, '%_\\').'%')->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value) => SupporterProfile::query()->whereKey($value)->value('display_name')),
                    Forms\Components\Select::make('program_id')->label('البرنامج')->required()
                        ->options(fn () => SponsorshipProgram::query()->whereIn('status', [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE])->orderByDesc('id')->limit(100)->pluck('title', 'id')->all()),
                    Forms\Components\TextInput::make('amount')->label('المبلغ')->required()->maxLength(20),
                    Forms\Components\Select::make('currency')->label('العملة')->required()->options(SponsorshipMoney::options())->default(SponsorshipMoney::accountingCurrency()),
                    Forms\Components\Toggle::make('is_anonymous')->label('مجهولة (لا تُنسب علنًا)'),
                    Forms\Components\TextInput::make('reference')->label('مرجع داخلي قصير (اختياري)')->maxLength((int) config('sponsorship.profile.reference_max')),
                    Forms\Components\Textarea::make('notes')->label('ملاحظات إدارية خاصة (لا تُعرض علنًا)')->rows(2)->maxLength((int) config('sponsorship.profile.notes_max')),
                ])
                ->action(function (array $data) {
                    try {
                        app(ContributionService::class)->record(
                            auth()->user(),
                            SupporterProfile::query()->findOrFail((int) $data['supporter_id']),
                            SponsorshipProgram::query()->findOrFail((int) $data['program_id']),
                            (string) $data['amount'],
                            (string) $data['currency'],
                            ['is_anonymous' => (bool) ($data['is_anonymous'] ?? false), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null],
                        );
                        Notification::make()->success()->title('سُجّلت المساهمة (معلّقة بانتظار التأكيد).')->send();
                    } catch (SponsorshipException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
`````

### `app/Filament/Resources/SponsorshipProgramResource.php`

`sha256: f6e790a4cc4ecc1e2de8c5d7cef41504cfbc7295e4d74b9635efb0b67f3e909d`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipProgramResource\Pages;
use App\Models\CompetitiveEvent;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\EventRewardPlanService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SponsorshipTargets;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - برامج الرعاية (أهداف رسمية فقط: المنصة/موسم/حملة/منافسة). إنشاء وتعديل sponsorships.manage؛ **خطة تعزيز المكافآت وقفلها sponsorships.reward_plan حصرًا** (فصل مالي)؛
 * كل التغييرات عبر SponsorshipProgramService (تحقق + تدقيق). لا حذف. الأدوار (راعٍ/راعٍ رئيسي) تشترط مساهمة مؤكَّدة. محتوى المجتمع ليس هدفًا ممكنًا بالتصميم.
 */
class SponsorshipProgramResource extends Resource
{
    protected static ?string $model = SponsorshipProgram::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'برامج الرعاية';

    protected static ?string $modelLabel = 'برنامج رعاية';

    protected static ?string $pluralModelLabel = 'برامج الرعاية';

    protected static ?int $navigationSort = 91;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['draft' => 'مسودة', 'active' => 'نشط', 'closed' => 'مغلق', 'cancelled' => 'ملغى'][$s] ?? $s;
    }

    public static function form(Form $form): Form
    {
        $targets = app(SponsorshipTargets::class);

        return $form->schema([
            Forms\Components\Section::make('البرنامج')->columns(2)->schema([
                Forms\Components\TextInput::make('title')->label('العنوان')->required()->minLength(3)->maxLength(160)->columnSpanFull(),
                Forms\Components\Textarea::make('description')->label('الوصف (نص عادي)')->rows(3)->maxLength(1000)->columnSpanFull(),
                Forms\Components\Select::make('target_type')->label('الهدف الرسمي')->required()->live()->options([
                    'platform' => 'المنصة', 'season' => 'موسم', 'campaign' => 'حملة', 'competitive_event' => 'منافسة',
                ])->helperText('الرعاية للمحتوى الرسمي فقط؛ لا تُرعى أحجيات المجتمع ولا السلاسل.'),
                Forms\Components\Select::make('target_id')->label('العنصر المستهدف')->searchable()
                    ->options(fn (Forms\Get $get) => $targets->options((string) $get('target_type')))
                    ->visible(fn (Forms\Get $get) => $get('target_type') && $get('target_type') !== 'platform')
                    ->required(fn (Forms\Get $get) => $get('target_type') && $get('target_type') !== 'platform'),
                Forms\Components\DateTimePicker::make('starts_at')->label('يبدأ (اختياري)'),
                Forms\Components\DateTimePicker::make('ends_at')->label('ينتهي (اختياري)')->after('starts_at'),
                Forms\Components\TextInput::make('goal_amount')->label('هدف التمويل بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().'، اختياري)')->maxLength(20),
            ]),
            Forms\Components\Section::make('العرض العام')->columns(2)->schema([
                Forms\Components\Toggle::make('show_public_amounts')->label('إظهار المبالغ الدقيقة علنًا')->helperText('مغلق افتراضيًا: تظهر المستويات والأسماء فقط.'),
                Forms\Components\Toggle::make('show_goal_progress')->label('إظهار تقدّم الهدف علنًا'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('sponsorship_programs.*')->selectSub(
                'select COALESCE(SUM(c.accounting_amount_minor), 0) from sponsorship_contributions c where c.sponsorship_program_id = sponsorship_programs.id and c.status = \'confirmed\'',
                'funding_minor',
            ))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('target_type')->label('الهدف')->formatStateUsing(fn ($state, SponsorshipProgram $r) => app(SponsorshipTargets::class)->typeLabel((string) $state).($r->target_id ? " #{$r->target_id}" : '')),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['active' => 'success', 'draft' => 'gray', 'closed' => 'warning', 'cancelled' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('funding_minor')->label('التمويل المؤكَّد')->state(fn (SponsorshipProgram $r) => SponsorshipMoney::format((int) $r->funding_minor, $r->accounting_currency)),
                Tables\Columns\IconColumn::make('reward_boost_enabled')->label('تعزيز المكافآت')->boolean(),
                Tables\Columns\IconColumn::make('reward_plan_locked_at')->label('الخطة مقفلة')->boolean()->state(fn (SponsorshipProgram $r) => $r->reward_plan_locked_at !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['draft' => 'مسودة', 'active' => 'نشط', 'closed' => 'مغلق', 'cancelled' => 'ملغى']),
                Tables\Filters\SelectFilter::make('target_type')->label('الهدف')->options(['platform' => 'المنصة', 'season' => 'موسم', 'campaign' => 'حملة', 'competitive_event' => 'منافسة']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (SponsorshipProgram $r) => $r->status !== SponsorshipProgram::STATUS_CANCELLED),
                Tables\Actions\ActionGroup::make([
                    self::statusAction('activate', 'تفعيل', 'success', fn (SponsorshipProgramService $s, SponsorshipProgram $r) => $s->activate(auth()->user(), $r), [SponsorshipProgram::STATUS_DRAFT], 'فُعّل البرنامج.'),
                    self::statusAction('close', 'إغلاق', 'warning', fn (SponsorshipProgramService $s, SponsorshipProgram $r) => $s->close(auth()->user(), $r), [SponsorshipProgram::STATUS_ACTIVE], 'أُغلق البرنامج (يبقى سجلّه).'),
                    self::statusAction('cancel_program', 'إلغاء', 'danger', fn (SponsorshipProgramService $s, SponsorshipProgram $r) => $s->cancel(auth()->user(), $r), [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], 'أُلغي البرنامج.'),
                    self::assignRoleAction(),
                    self::removeRoleAction(),
                    self::rewardPlanAction(),
                    self::lockPlanAction(),
                ]),
            ]);
    }

    protected static function statusAction(string $name, string $label, string $color, \Closure $call, array $from, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (SponsorshipProgram $r) => in_array($r->status, $from, true) && (auth()->user()?->can('sponsorships.manage') ?? false))
            ->action(fn (SponsorshipProgram $record) => self::guard(fn () => $call(app(SponsorshipProgramService::class), $record), $ok));
    }

    protected static function assignRoleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('assign_role')->label('تعيين دور (راعٍ / راعٍ رئيسي)')->icon('heroicon-o-star')
            ->visible(fn (SponsorshipProgram $r) => $r->status !== SponsorshipProgram::STATUS_CANCELLED && (auth()->user()?->can('sponsorships.manage') ?? false))
            ->form(fn (SponsorshipProgram $r) => [
                Forms\Components\Select::make('supporter_id')->label('الداعم (له مساهمة مؤكَّدة بهذا البرنامج)')->required()
                    ->options(fn () => SupporterProfile::query()->whereIn('id', SponsorshipContribution::query()->where('sponsorship_program_id', $r->id)
                        ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->select('supporter_profile_id'))->orderBy('display_name')->limit(100)->pluck('display_name', 'id')->all()),
                Forms\Components\Select::make('role')->label('الدور')->required()->options(['supporter' => 'داعم', 'sponsor' => 'راعٍ', 'main_sponsor' => 'الراعي الرئيسي'])->default('sponsor'),
            ])
            ->action(fn (SponsorshipProgram $record, array $data) => self::guard(function () use ($record, $data) {
                app(SponsorshipProgramService::class)->assignRole(auth()->user(), $record, SupporterProfile::query()->findOrFail((int) $data['supporter_id']), (string) $data['role']);
            }, 'عُيّن الدور.'));
    }

    protected static function removeRoleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('remove_role')->label('إزالة دور')->icon('heroicon-o-x-mark')->color('gray')
            ->visible(fn (SponsorshipProgram $r) => auth()->user()?->can('sponsorships.manage') ?? false)
            ->form(fn (SponsorshipProgram $r) => [
                Forms\Components\Select::make('supporter_id')->label('الداعم')->required()
                    ->options(fn () => SupporterProfile::query()->whereIn('id', \App\Models\SponsorshipProgramRole::query()->where('sponsorship_program_id', $r->id)->select('supporter_profile_id'))->limit(100)->pluck('display_name', 'id')->all()),
            ])
            ->action(fn (SponsorshipProgram $record, array $data) => self::guard(function () use ($record, $data) {
                app(SponsorshipProgramService::class)->removeRole(auth()->user(), $record, SupporterProfile::query()->findOrFail((int) $data['supporter_id']));
            }, 'أُزيل الدور.'));
    }

    protected static function rewardPlanAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reward_plan')->label('خطة تعزيز المكافآت')->icon('heroicon-o-sparkles')
            ->visible(fn (SponsorshipProgram $r) => $r->status !== SponsorshipProgram::STATUS_CANCELLED && $r->reward_plan_locked_at === null && (auth()->user()?->can('sponsorships.reward_plan') ?? false))
            ->fillForm(fn (SponsorshipProgram $r) => [
                'enabled' => (bool) $r->reward_boost_enabled,
                'step' => $r->funding_step_minor ? self::decimal((int) $r->funding_step_minor, (string) $r->accounting_currency) : null,
                'per_step_bps' => (int) $r->boost_per_step_bps ?: 100,
                'max_bps' => (int) $r->max_boost_bps ?: 500,
            ])
            ->modalDescription('الصيغة: عدد الخطوات = floor(التمويل المؤكَّد ÷ الخطوة)، النسبة = min(الخطوات × نسبة الخطوة، السقف). 100 نقطة أساسية = 1%. تُطبَّق على المحتوى الرسمي فقط، وبلا أثر رجعي.')
            ->form([
                Forms\Components\Toggle::make('enabled')->label('تفعيل تعزيز المكافآت'),
                Forms\Components\TextInput::make('step')->label('الخطوة (بعملة المحاسبة '.SponsorshipMoney::accountingCurrency().')')->maxLength(20),
                Forms\Components\TextInput::make('per_step_bps')->label('نقاط أساسية لكل خطوة')->numeric()->minValue(0)->maxValue((int) config('sponsorship.reward.hard_max_bps')),
                Forms\Components\TextInput::make('max_bps')->label('سقف التعزيز (نقاط أساسية)')->numeric()->minValue(0)->maxValue((int) config('sponsorship.reward.hard_max_bps')),
            ])
            ->action(fn (SponsorshipProgram $record, array $data) => self::guard(fn () => app(SponsorshipProgramService::class)->updateRewardPlan(auth()->user(), $record, [
                'enabled' => (bool) ($data['enabled'] ?? false), 'step' => $data['step'] ?? null, 'per_step_bps' => (int) ($data['per_step_bps'] ?? 0), 'max_bps' => (int) ($data['max_bps'] ?? 0),
            ]), 'حُفظت خطة التعزيز.'));
    }

    protected static function lockPlanAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('lock_plan')->label('قفل خطة المنافسة الآن')->icon('heroicon-o-lock-closed')->color('warning')->requiresConfirmation()
            ->modalDescription('يثبّت لقطة التمويل والنسبة لهذه المنافسة قبل بدئها. لا تعديل بعد القفل، والمساهمات اللاحقة لا تغيّر جوائزها.')
            ->visible(fn (SponsorshipProgram $r) => $r->target_type === SponsorshipProgram::TARGET_COMPETITIVE_EVENT && $r->status === SponsorshipProgram::STATUS_ACTIVE
                && $r->reward_boost_enabled && $r->reward_plan_locked_at === null && (auth()->user()?->can('sponsorships.reward_plan') ?? false))
            ->action(fn (SponsorshipProgram $record) => self::guard(function () use ($record) {
                app(EventRewardPlanService::class)->lock(CompetitiveEvent::query()->findOrFail((int) $record->target_id), auth()->user());
            }, 'قُفلت خطة المنافسة.'));
    }

    /** وحدة صغرى ← نص عشري بلا floats (لملء النماذج). */
    public static function decimal(int $minor, string $currency): string
    {
        $exp = SponsorshipMoney::exponent($currency);

        return $exp === 0 ? (string) $minor : intdiv($minor, 10 ** $exp).'.'.str_pad((string) ($minor % (10 ** $exp)), $exp, '0', STR_PAD_LEFT);
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSponsorshipPrograms::route('/'),
            'create' => Pages\CreateSponsorshipProgram::route('/create'),
            'edit' => Pages\EditSponsorshipProgram::route('/{record}/edit'),
        ];
    }
}
`````

### `app/Filament/Resources/SponsorshipProgramResource/Pages/CreateSponsorshipProgram.php`

`sha256: b3738636f86443aeb61c28967c5bd6c403cc1639d87508c2d8d17ae2e78bfffe`

`````php
<?php

namespace App\Filament\Resources\SponsorshipProgramResource\Pages;

use App\Filament\Resources\SponsorshipProgramResource;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSponsorshipProgram extends CreateRecord
{
    protected static string $resource = SponsorshipProgramResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SponsorshipProgramService::class)->create(auth()->user(), $data);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }
    }
}
`````

### `app/Filament/Resources/SponsorshipProgramResource/Pages/EditSponsorshipProgram.php`

`sha256: ce103f7f2f9ca5e8a5d501ae1e863e26c1b9a153b667b7788105973b64d28aae`

`````php
<?php

namespace App\Filament\Resources\SponsorshipProgramResource\Pages;

use App\Filament\Resources\SponsorshipProgramResource;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSponsorshipProgram extends EditRecord
{
    protected static string $resource = SponsorshipProgramResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['goal_amount'] = ! empty($data['goal_amount_minor']) ? SponsorshipProgramResource::decimal((int) $data['goal_amount_minor'], (string) $data['accounting_currency']) : null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(SponsorshipProgramService::class)->update(auth()->user(), $record, $data);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }
    }
}
`````

### `app/Filament/Resources/SponsorshipProgramResource/Pages/ListSponsorshipPrograms.php`

`sha256: f197faaa054093bcf1632975a125791a0492e1989bcda5073b9c6f1e68648109`

`````php
<?php

namespace App\Filament\Resources\SponsorshipProgramResource\Pages;

use App\Filament\Resources\SponsorshipProgramResource;
use App\Filament\Widgets\SponsorshipStatsWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipPrograms extends ListRecords
{
    protected static string $resource = SponsorshipProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [SponsorshipStatsWidget::class];
    }
}
`````

### `app/Filament/Resources/SupporterProfileResource.php`

`sha256: 5bb89a3c6a0354abcae37049e36681a2a9ad7d89e6881c683a216ca2ab7e59fb`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupporterProfileResource\Pages;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Sponsorship\SupporterLogoService;
use App\Services\Sponsorship\SupporterTierService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - ملفات الداعمين (أفراد/مؤسسات). عرض sponsorships.view، إنشاء/تعديل sponsorships.manage (سياسة SupporterProfilePolicy). لا حذف (تاريخ مالي مرتبط)؛ الإيقاف يخفي الملف علنًا.
 * الكتابة كلها عبر SupporterService (تحقق + تدقيق + ربط الشعار الآمن)، لا عبر حفظ Filament الخام. لا تفاصيل دفع بأي حقل. المستوى مشتقّ للعرض فقط.
 */
class SupporterProfileResource extends Resource
{
    protected static ?string $model = SupporterProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'الداعمون';

    protected static ?string $modelLabel = 'داعم';

    protected static ?string $pluralModelLabel = 'الداعمون';

    protected static ?int $navigationSort = 90;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الهوية')->columns(2)->schema([
                Forms\Components\TextInput::make('display_name')->label('الاسم المعروض')->required()->minLength(2)->maxLength((int) config('sponsorship.profile.name_max')),
                Forms\Components\Select::make('type')->label('النوع')->options(['individual' => 'فرد', 'organization' => 'مؤسسة / شركة'])->required()->default('individual'),
                Forms\Components\Textarea::make('description')->label('نبذة (نص عادي)')->rows(3)->maxLength((int) config('sponsorship.profile.description_max'))->columnSpanFull(),
                Forms\Components\TextInput::make('website_url')->label('الموقع (http/https فقط)')->maxLength((int) config('sponsorship.profile.url_max'))->url()->columnSpanFull(),
                Forms\Components\FileUpload::make('logo')->label('الشعار (JPEG / PNG / WEBP)')->image()->storeFiles(false)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize((int) config('sponsorship.logo.max_kb'))->disk('public')->directory('supporters')->dehydrated(),
                Forms\Components\Toggle::make('remove_logo')->label('إزالة الشعار الحالي')->visibleOn('edit'),
            ]),
            Forms\Components\Section::make('الظهور والخصوصية')->columns(2)->schema([
                Forms\Components\Select::make('status')->label('الحالة')->options(['active' => 'نشط', 'suspended' => 'موقوف'])->default('active')->required(),
                Forms\Components\Toggle::make('is_public')->label('ملف علني')->default(true),
                Forms\Components\Toggle::make('anonymous_by_default')->label('مساهماته مجهولة افتراضيًا')->default(false),
                Forms\Components\Select::make('user_id')->label('حساب لاعب مرتبط (اختياري)')->searchable()->placeholder('— بلا ربط —')
                    ->getSearchResultsUsing(fn (string $search) => User::query()->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('email', 'like', addcslashes($search, '%_\\').'%')->limit(20)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => User::query()->whereKey($value)->value('name')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tiers = app(SupporterTierService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('supporter_profiles.*')->selectSub(
                'select COALESCE(SUM(c.accounting_amount_minor), 0) from sponsorship_contributions c where c.supporter_profile_id = supporter_profiles.id and c.status = \'confirmed\'',
                'net_minor',
            ))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('display_name')->label('الاسم')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('type')->label('النوع')->formatStateUsing(fn ($state) => $state === 'organization' ? 'مؤسسة' : 'فرد'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state === 'active' ? 'نشط' : 'موقوف')->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
                Tables\Columns\IconColumn::make('is_public')->label('علني')->boolean(),
                Tables\Columns\TextColumn::make('tier')->label('المستوى (مشتقّ)')->state(fn (SupporterProfile $r) => $tiers->forAmount((int) $r->net_minor)?->name ?? '—'),
                Tables\Columns\TextColumn::make('net_minor')->label('صافي المؤكَّد')->state(fn (SupporterProfile $r) => \App\Services\Sponsorship\SponsorshipMoney::format((int) $r->net_minor))
                    ->visible(fn () => auth()->user()?->can('sponsorships.view') ?? false),
                Tables\Columns\TextColumn::make('created_at')->label('أُنشئ')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('النوع')->options(['individual' => 'فرد', 'organization' => 'مؤسسة']),
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['active' => 'نشط', 'suspended' => 'موقوف']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultPaginationPageOption(20);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupporterProfiles::route('/'),
            'create' => Pages\CreateSupporterProfile::route('/create'),
            'edit' => Pages\EditSupporterProfile::route('/{record}/edit'),
        ];
    }

    /** يستخرج ملف الشعار المرفوع (إن وُجد) من حالة النموذج. يقبل كائنًا أو مصفوفة مفاتيحها uuid (سلوك Filament عند storeFiles(false)). */
    public static function extractLogo(mixed $state): ?\Illuminate\Http\UploadedFile
    {
        if (is_array($state)) {
            $state = reset($state) ?: null;
        }

        return $state instanceof \Illuminate\Http\UploadedFile ? $state : null;
    }
}
`````

### `app/Filament/Resources/SupporterProfileResource/Pages/CreateSupporterProfile.php`

`sha256: cfa709230f681293a376bb341344c5050ed87432936589a6210c1c34d750dbb3`

`````php
<?php

namespace App\Filament\Resources\SupporterProfileResource\Pages;

use App\Filament\Resources\SupporterProfileResource;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSupporterProfile extends CreateRecord
{
    protected static string $resource = SupporterProfileResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $logo = SupporterProfileResource::extractLogo($data['logo'] ?? null);
        unset($data['logo'], $data['remove_logo']);

        try {
            return app(SupporterService::class)->create(auth()->user(), $data, $logo);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['display_name' => $e->getMessage()]);
        }
    }
}
`````

### `app/Filament/Resources/SupporterProfileResource/Pages/EditSupporterProfile.php`

`sha256: 74ec5f8daece2a2fd736927a74482fab149782a51ed582c896588e6d2b61446e`

`````php
<?php

namespace App\Filament\Resources\SupporterProfileResource\Pages;

use App\Filament\Resources\SupporterProfileResource;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSupporterProfile extends EditRecord
{
    protected static string $resource = SupporterProfileResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['logo_path']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $logo = SupporterProfileResource::extractLogo($data['logo'] ?? null);
        $remove = (bool) ($data['remove_logo'] ?? false);
        unset($data['logo'], $data['remove_logo']);

        try {
            return app(SupporterService::class)->update(auth()->user(), $record, $data, $logo, $remove);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['display_name' => $e->getMessage()]);
        }
    }
}
`````

### `app/Filament/Resources/SupporterProfileResource/Pages/ListSupporterProfiles.php`

`sha256: a28dd31add030c6e79e736caffdb738de9bc0064bcab3857c8971228d49f85a4`

`````php
<?php

namespace App\Filament\Resources\SupporterProfileResource\Pages;

use App\Filament\Resources\SupporterProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupporterProfiles extends ListRecords
{
    protected static string $resource = SupporterProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
`````

### `app/Filament/Resources/SupporterTierResource.php`

`sha256: e5351b441ab9a3a62e4565b2e34cb6f3866e8dea027a7d285ec2978a02641452`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupporterTierResource\Pages;
use App\Models\SupporterTier;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E30 - مستويات الداعمين (تُدار من قاعدة البيانات). الحدّ الأدنى بعملة المحاسبة؛ المستوى يُشتقّ دائمًا من صافي الدعم المؤكَّد ولا يُسنَد يدويًا لداعم.
 * تعديل المستويات صلاحية sponsorships.manage ويُدقَّق (صفحات الإنشاء/التعديل) ويُبطل الكاش تلقائيًا (خطاف النموذج). لا حذف: يُعطَّل المستوى بدل حذفه.
 */
class SupporterTierResource extends Resource
{
    protected static ?string $model = SupporterTier::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'مستويات الداعمين';

    protected static ?string $modelLabel = 'مستوى';

    protected static ?string $pluralModelLabel = 'مستويات الداعمين';

    protected static ?int $navigationSort = 93;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required()->maxLength(60),
            Forms\Components\TextInput::make('slug')->label('المعرّف')->required()->maxLength(40)->alphaDash()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('minimum_decimal')->label('الحدّ الأدنى بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().')')->required()->maxLength(20),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
            Forms\Components\Select::make('badge_key')->label('الشارة')->options(['supporter' => 'داعم', 'silver' => 'فضي', 'gold' => 'ذهبي', 'platinum' => 'بلاتيني', 'diamond' => 'ماسي'])->required()->default('supporter'),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('minimum_accounting_amount_minor')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم'),
                Tables\Columns\TextColumn::make('minimum_accounting_amount_minor')->label('الحدّ الأدنى')->state(fn (SupporterTier $r) => SponsorshipMoney::format((int) $r->minimum_accounting_amount_minor)),
                Tables\Columns\TextColumn::make('badge_key')->label('الشارة'),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupporterTiers::route('/'),
            'create' => Pages\CreateSupporterTier::route('/create'),
            'edit' => Pages\EditSupporterTier::route('/{record}/edit'),
        ];
    }
}
`````

### `app/Filament/Resources/SupporterTierResource/Pages/CreateSupporterTier.php`

`sha256: bd526fccf0d6994b29217b775435c5c6557980e92f627c0906f73c4d3e7e93f4`

`````php
<?php

namespace App\Filament\Resources\SupporterTierResource\Pages;

use App\Filament\Resources\SupporterTierResource;
use App\Models\SupporterTier;
use App\Services\OperationalAuditService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSupporterTier extends CreateRecord
{
    protected static string $resource = SupporterTierResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $data['minimum_accounting_amount_minor'] = SponsorshipMoney::parse($data['minimum_decimal'], SponsorshipMoney::accountingCurrency());
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['minimum_decimal' => $e->getMessage()]);
        }

        unset($data['minimum_decimal']);
        $tier = SupporterTier::query()->create($data);
        app(OperationalAuditService::class)->log('supporter_tier_saved', $tier, ['slug' => $tier->slug, 'minimum' => $tier->minimum_accounting_amount_minor, 'active' => $tier->is_active], auth()->user());

        return $tier;
    }
}
`````

### `app/Filament/Resources/SupporterTierResource/Pages/EditSupporterTier.php`

`sha256: ec7add0f76bd2a6e87c2fd9f82420c8f64e94dd18805fe374460da1d5b4e5159`

`````php
<?php

namespace App\Filament\Resources\SupporterTierResource\Pages;

use App\Filament\Resources\SupporterTierResource;
use App\Filament\Resources\SponsorshipProgramResource;
use App\Services\OperationalAuditService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSupporterTier extends EditRecord
{
    protected static string $resource = SupporterTierResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['minimum_decimal'] = SponsorshipProgramResource::decimal((int) $data['minimum_accounting_amount_minor'], SponsorshipMoney::accountingCurrency());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $data['minimum_accounting_amount_minor'] = SponsorshipMoney::parse($data['minimum_decimal'], SponsorshipMoney::accountingCurrency());
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['minimum_decimal' => $e->getMessage()]);
        }

        unset($data['minimum_decimal']);
        $before = $record->only(['name', 'minimum_accounting_amount_minor', 'is_active']);
        $record->update($data);
        app(OperationalAuditService::class)->log('supporter_tier_saved', $record, ['slug' => $record->slug, 'before' => $before, 'after' => $record->only(['name', 'minimum_accounting_amount_minor', 'is_active'])], auth()->user());

        return $record;
    }
}
`````

### `app/Filament/Resources/SupporterTierResource/Pages/ListSupporterTiers.php`

`sha256: 7469a20469c69d7b3ce013e08bcaac6171237df92f6ac74c6157dfa1df67fee6`

`````php
<?php

namespace App\Filament\Resources\SupporterTierResource\Pages;

use App\Filament\Resources\SupporterTierResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupporterTiers extends ListRecords
{
    protected static string $resource = SupporterTierResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
`````

### `app/Filament/Widgets/SponsorshipStatsWidget.php`

`sha256: fde107224ec490ce6f841121cc828d9413bbed0c9114a134f7071dd2ff40e70e`

`````php
<?php

namespace App\Filament\Widgets;

use App\Services\Sponsorship\SponsorshipAnalytics;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E30 - ملخص الرعاية لرأس صفحات الرعاية (غير مكتشَف تلقائيًا بالصفحة الرئيسية). أرقام مجمَّعة مخزَّنة مدة قصيرة؛ لا هويات ولا تفاصيل دفع. يراه sponsorships.view فقط.
 */
class SponsorshipStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('sponsorships.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(SponsorshipAnalytics::class)->summary();

        return [
            Stat::make('صافي الدعم المؤكَّد', SponsorshipMoney::format($s['confirmed_minor'], $s['currency']))->description($s['confirmed_count'].' مساهمة مؤكَّدة')->icon('heroicon-o-banknotes')->color('success'),
            Stat::make('مساهمات معلّقة', number_format($s['pending_count']))->icon('heroicon-o-clock')->color($s['pending_count'] > 0 ? 'warning' : 'success'),
            Stat::make('المسترد', SponsorshipMoney::format($s['refunded_minor'], $s['currency']))->description($s['refunded_count'].' مساهمة مستردة · '.$s['cancelled_count'].' ملغاة')->icon('heroicon-o-arrow-uturn-left')->color('gray'),
            Stat::make('برامج نشطة', number_format($s['active_programs']))->description($s['boost_programs'].' بتعزيز مكافآت · '.$s['locked_plans'].' خطة منافسة مقفلة')->icon('heroicon-o-gift')->color('primary'),
            Stat::make('جواهر رعاية ممنوحة', number_format($s['bonus_total']))->description($s['bonus_count'].' منحة')->icon('heroicon-o-sparkles')->color('primary'),
            Stat::make('أعلى برنامج تمويلًا', $s['top_program']['title'] ?? '—')->description(isset($s['top_program']) ? SponsorshipMoney::format($s['top_program']['funding_minor'], $s['currency']) : 'لا تمويل مؤكَّد بعد')->icon('heroicon-o-trophy')->color('success'),
        ];
    }
}
`````

### `app/Http/Controllers/CreatorSeries/CreatorSeriesStudioController.php`

`sha256: e8535e5e3115d99fe59063efe6b54f41523d7ac9e84d06fda310345c865f1710`

`````php
<?php

namespace App\Http\Controllers\CreatorSeries;

use App\Http\Controllers\Creators\CreatorController;
use App\Http\Requests\CreatorSeries\SaveCreatorSeriesRequest;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesAnalytics;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E30 - استوديو سلاسل المبدع. كل مسار {series} بمعرّف ULID عام يُحلّ باستعلام محصور بمالكه (سلسلة غيرك = 404 بلا تسريب). الأهلية بميدلوير E28 نفسه وبالخدمة (دفاع مزدوج).
 * المدخلات من FormRequest فقط (validated) وبلا تعيين جماعي للطلب كله؛ أخطاء المجال المتوقَّعة تعود برسالة عربية.
 */
class CreatorSeriesStudioController extends CreatorController
{
    public function __construct(
        protected CreatorSeriesService $series,
        protected CreatorSeriesAnalytics $analytics,
        protected CreatorSeriesVisibilityService $visibility,
    ) {
        abort_unless((bool) config('creator_series.enabled', true), 404);
    }

    public function index(Request $request)
    {
        $profile = $this->profile($request);
        $bucket = in_array($request->query('status'), CreatorSeriesService::BUCKETS, true) ? (string) $request->query('status') : 'all';
        $perSeries = $this->analytics->forCreator($profile);

        return view('creator.series.index', [
            'bucket' => $bucket,
            'counts' => $this->series->bucketCounts($profile),
            'seriesList' => $this->series->paginate($profile, $bucket),
            'stats' => $this->analytics->totals($perSeries),
            'perSeries' => $perSeries,
        ]);
    }

    public function create(Request $request)
    {
        return view('creator.series.create', [
            'works' => $this->series->eligibleWorks($this->profile($request)),
            'current' => collect(),
            'selected' => $this->oldSelection() ?? [],
            'limits' => config('creator_series.limits'),
        ]);
    }

    public function store(SaveCreatorSeriesRequest $request): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->createDraft($request->user(), $this->payload($request), $request->file('cover')),
            'حُفظت السلسلة كمسودة. راجعها ثم أرسلها للمراجعة.',
            fn (CreatorSeriesRevision $revision) => redirect()->route('creator.series.edit', $revision->series()->value('public_id')),
        );
    }

    public function edit(Request $request, string $series)
    {
        $profile = $this->profile($request);
        $model = $this->series->ownedSeries($profile, $series);
        $revision = CreatorSeriesRevision::query()->findOrFail($model->latest_revision_id);
        $current = $this->series->revisionItems($revision);

        return view('creator.series.edit', [
            'series' => $model,
            'revision' => $revision,
            'works' => $this->series->eligibleWorks($profile),
            'current' => $current,
            'selected' => $this->oldSelection() ?? $current->pluck('public_id')->all(),
            'limits' => config('creator_series.limits'),
            'blockers' => $model->isPublished() ? $this->visibility->blockers($model) : [],
            'publicUrl' => $model->isPublished() ? route('series.show', $model->public_id) : null,
        ]);
    }

    public function update(SaveCreatorSeriesRequest $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->updateDraft($request->user(), $series, $this->payload($request), $request->file('cover'), $request->boolean('remove_cover')),
            'حُفظت التعديلات.',
        );
    }

    public function destroy(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->destroy($request->user(), $series),
            'تم الحذف.',
            fn () => redirect()->route('creator.series.index'),
        );
    }

    public function submit(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->submit($request->user(), $series),
            'أُرسلت السلسلة للمراجعة.',
            fn () => redirect()->route('creator.series.edit', $series),
        );
    }

    public function withdraw(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->withdraw($request->user(), $series),
            'سُحبت السلسلة من المراجعة وعادت مسودة.',
            fn () => redirect()->route('creator.series.edit', $series),
        );
    }

    public function revise(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->revise($request->user(), $series),
            'أُنشئت نسخة جديدة للتعديل. النسخة المنشورة تبقى متاحة حتى اعتماد الجديدة.',
            fn () => redirect()->route('creator.series.edit', $series),
        );
    }

    public function archive(Request $request, string $series): RedirectResponse
    {
        return $this->perform(fn () => $this->series->archive($request->user(), $series), 'تمت أرشفة السلسلة وأُخفيت من العامة.');
    }

    public function unarchive(Request $request, string $series): RedirectResponse
    {
        return $this->perform(fn () => $this->series->unarchive($request->user(), $series), 'أُلغيت الأرشفة.');
    }

    /** @return array{title:string, description:?string, items:array<int,string>} مدخلات مُتحقَّق منها فقط (لا $request->all()). */
    protected function payload(SaveCreatorSeriesRequest $request): array
    {
        $data = $request->validated();

        return [
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'items' => array_values($data['items']),
        ];
    }

    /** اختيار المستخدم بعد فشل تحقق (old) بالترتيب نفسه، أو null. @return list<string>|null */
    protected function oldSelection(): ?array
    {
        $old = old('items');

        return is_array($old) ? array_values(array_filter($old, 'is_string')) : null;
    }
}
`````

### `app/Http/Controllers/CreatorSeries/SeriesController.php`

`sha256: a9a642f62caea4ef1db838666d48be09df8cd8e24acd4ee068fc0b8130ea41c2`

`````php
<?php

namespace App\Http\Controllers\CreatorSeries;

use App\Http\Controllers\Controller;
use App\Models\CreatorSeries;
use App\Services\CreatorSeries\CreatorSeriesProgressService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E30 - صفحة السلسلة العامة /series/{معرّف عام أو slug}. عامة للضيوف لكن لسلسلة ظاهرة فقط (CreatorSeriesVisibilityService): غيرها 404 للجميع.
 * قراءة فقط (GET لا يكتب شيئًا ولا يعدّ مشاهدات). اللعب نفسه يجري في صفحات أحجيات المجتمع القائمة (CommunityPlayService، بلا اقتصاد):
 * هذه الصفحة تعرض العناصر وتقدّم المستخدم المشتقّ من سجل لعب المجتمع وزر «أكمل» لأول عنصر غير محلول. استعلامات الصفحة عدد ثابت مهما كان عدد العناصر.
 */
class SeriesController extends Controller
{
    public function __construct(
        protected CreatorSeriesVisibilityService $visibility,
        protected CreatorSeriesProgressService $progress,
    ) {}

    public function show(Request $request, string $series)
    {
        abort_unless((bool) config('creator_series.enabled', true), 404);

        $query = $this->visibility->visibleQuery()
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedRevision:id,creator_series_id,title,description,cover_path']);

        $model = (Str::isUlid($series) ? $query->where('creator_series.public_id', Str::upper($series)) : $query->where('creator_series.slug', $series))->first();
        abort_unless($model instanceof CreatorSeries && $model->publishedRevision !== null, 404);

        $items = DB::table('creator_series_items as i')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->leftJoin('puzzle_categories as c', 'c.id', '=', 'w.published_category_id')
            ->where('i.creator_series_revision_id', $model->published_revision_id)
            ->orderBy('i.position')
            ->get(['i.position', 'w.id as work_id', 'w.public_id', 'w.published_title', 'w.published_difficulty', 'c.name as category']);

        $user = $request->user();
        $isOwner = $user !== null && (int) $model->creator->user_id === (int) $user->id;
        $canPlay = $user !== null && $user->hasVerifiedEmail() && ! $user->is_frozen && ! $isOwner;
        $progress = $this->progress->forUser($canPlay ? $user : null, $items);

        return view('series.show', [
            'series' => $model,
            'revision' => $model->publishedRevision,
            'creator' => $model->creator,
            'items' => $items,
            'progress' => $progress,
            'isOwner' => $isOwner,
            'canPlay' => $canPlay,
            'coverUrl' => $model->publishedCoverUrl(),
            'continueId' => $progress['next_public_id'] ?? $items->first()?->public_id,
        ]);
    }
}
`````

### `app/Http/Controllers/Sponsorship/SupporterController.php`

`sha256: b9042f13e82dc1e7612d0931700cc448198247b80097a086c9a0391891757168`

`````php
<?php

namespace App\Http\Controllers\Sponsorship;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorDisplay;
use App\Services\Sponsorship\SponsorshipTargets;
use App\Services\Sponsorship\SupportTotals;
use App\Services\Sponsorship\SupporterLeaderboardService;
use App\Services\Sponsorship\SupporterTierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * E30 - صفحات الداعمين العامة (قراءة فقط). الظهور: ملف علني ونشط (غيره 404). لا مبالغ دقيقة إلا لبرنامج فعّل show_public_amounts؛ لا ملاحظات ولا مراجع ولا تفاصيل دفع؛
 * المجهولون «داعم مجهول» بلا ملف ولا رابط. كل القوائم بسقوف ثابتة، واللوحات من تجميع قاعدة البيانات (الكاش معرّفات فقط، والعلنية تُفحص حيًّا).
 */
class SupporterController extends Controller
{
    public function __construct(
        protected SupporterLeaderboardService $board,
        protected SupporterTierService $tiers,
        protected SponsorDisplay $display,
        protected SupportTotals $totals,
        protected SponsorshipTargets $targets,
    ) {}

    public function index(Request $request)
    {
        abort_unless((bool) config('sponsorship.enabled', true), 404);

        $scope = in_array($request->query('scope'), SupporterLeaderboardService::SCOPES, true) ? (string) $request->query('scope') : SupporterLeaderboardService::SCOPE_ALL;
        $slug = $request->query('program');
        $program = null;

        if ($scope === SupporterLeaderboardService::SCOPE_PROGRAM) {
            $program = is_string($slug) ? SponsorshipProgram::query()->where('slug', $slug)->whereIn('status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
                ->first(['id', 'slug', 'title', 'accounting_currency', 'show_public_amounts', 'show_goal_progress', 'goal_amount_minor', 'target_type', 'target_id']) : null;
            $scope = $program === null ? SupporterLeaderboardService::SCOPE_ALL : $scope;
        }

        $programs = SponsorshipProgram::query()->whereIn('status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
            ->orderByDesc('activated_at')->orderByDesc('id')->limit(8)
            ->get(['id', 'slug', 'title', 'status', 'accounting_currency', 'show_public_amounts', 'show_goal_progress', 'goal_amount_minor', 'target_type', 'target_id']);

        $funding = $this->totals->programFundingMany($programs->pluck('id')->all());

        return view('supporters.index', [
            'scope' => $scope,
            'program' => $program,
            'leaderboard' => $this->board->top($scope, $program),
            'recent' => $this->board->recent(),
            'organizations' => $this->board->organizations(),
            'programs' => $programs,
            'funding' => $funding,
            'tiers' => $this->tiers->tiers(),
            'platformSponsors' => $this->display->forTarget(SponsorshipProgram::TARGET_PLATFORM),
            'targets' => $this->targets,
        ]);
    }

    public function show(SupporterProfile $supporter)
    {
        abort_unless($supporter->isPublic() && (bool) config('sponsorship.enabled', true), 404);

        // البرامج التي دعمها علنًا (مساهمات مؤكَّدة غير مجهولة فقط) مع المبلغ إن فعّل البرنامج ذلك. سقف 10.
        $programs = DB::table('sponsorship_contributions as c')
            ->join('sponsorship_programs as p', 'p.id', '=', 'c.sponsorship_program_id')
            ->where('c.supporter_profile_id', $supporter->id)->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('c.is_anonymous', false)
            ->whereIn('p.status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
            ->groupBy('p.id', 'p.slug', 'p.title', 'p.show_public_amounts', 'p.accounting_currency')
            ->selectRaw('p.id, p.slug, p.title, p.show_public_amounts as show_amounts, p.accounting_currency as cur, SUM(c.accounting_amount_minor) as total, MIN(c.confirmed_at) as since')
            ->orderBy('since')->orderBy('p.id')->limit(10)->get();

        $since = $programs->min('since');

        return view('supporters.show', [
            'supporter' => $supporter,
            'tier' => $this->tiers->forSupporter($supporter),
            'programs' => $programs,
            'since' => $since,
            'card' => $this->display->card($supporter),
        ]);
    }
}
`````

### `app/Http/Requests/CreatorSeries/SaveCreatorSeriesRequest.php`

`sha256: 3134b41e63a1090c9961a6ee79a0a2cda6bdc53a4b8c480ef5d78e8dec70633c`

`````php
<?php

namespace App\Http\Requests\CreatorSeries;

use App\Rules\NoMarkup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E30 - إنشاء/تعديل مسودة سلسلة. نصوص عادية فقط (NoMarkup)، عناصر = معرّفات ULID عامة مرتّبة (الموضع = الترتيب بالمصفوفة، 1..n)،
 * وغلاف اختياري JPEG/PNG/WEBP. أي حقل سلطة/اقتصاد/ظهور بالطلب (config creator_series.forbidden_fields) يُرفض صراحة لا يُتجاهل.
 * الملكية والظهور والتكرار والحدود يعيد فرضها CreatorSeriesService من القاعدة (هذا الطلب أول طبقة فقط).
 */
class SaveCreatorSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $l = config('creator_series.limits');
        $media = config('creators.media');

        return [
            'title' => ['required', 'string', 'min:'.$l['title_min'], 'max:'.$l['title_max'], new NoMarkup],
            'description' => ['nullable', 'string', 'max:'.$l['description_max'], new NoMarkup],
            'items' => ['required', 'array', 'min:'.$l['min_items'], 'max:'.$l['max_items']],
            'items.*' => ['required', 'string', 'ulid', 'distinct'],
            'cover' => [
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:'.implode(',', array_keys($media['mimes'])), 'max:'.$media['max_kb'],
                'dimensions:min_width='.$media['min_dimension'].',min_height='.$media['min_dimension'].',max_width='.$media['max_dimension'].',max_height='.$media['max_dimension'],
            ],
            'remove_cover' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) config('creator_series.forbidden_fields') as $field) {
                if ($this->exists($field)) {
                    $v->errors()->add($field, 'لا يمكنك تحديد هذا الحقل؛ يحدّده النظام.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return ['title' => 'عنوان السلسلة', 'description' => 'الوصف', 'items' => 'الأحجيات', 'items.*' => 'الأحجية', 'cover' => 'الغلاف'];
    }

    public function messages(): array
    {
        $l = config('creator_series.limits');

        return [
            'items.required' => "أضف {$l['min_items']} أحجيات على الأقل إلى السلسلة.",
            'items.min' => "أضف {$l['min_items']} أحجيات على الأقل إلى السلسلة.",
            'items.max' => "الحد الأقصى {$l['max_items']} أحجية في السلسلة الواحدة.",
            'items.*.distinct' => 'لا يمكن تكرار الأحجية نفسها داخل السلسلة.',
            'items.*.ulid' => 'معرّف الأحجية غير صالح.',
        ];
    }
}
`````

### `app/Jobs/DispatchCreatorSeriesChunk.php`

`sha256: db409efefd5a03b2891f0afff6512728df931c50b201d72d6e3cf2bec09580bd`

`````php
<?php

namespace App\Jobs;

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * E30 - دفعة واحدة من إشعارات «سلسلة جديدة» لمتابعي مبدع، ثم الدفعة التالية بمؤشر id لصف المتابعة (إعادة استعمال نمط E29 بلا اختراع جديد).
 * حجم الدفعة = player_notifications.chunk_size. إعادة تشغيل أي دفعة آمنة: المفتاح creator-series:{revision}:{user} + القيد الفريد بالإشعارات.
 * يُعاد فحص الظهور لحظة التنفيذ عبر CreatorSeriesVisibilityService: سلسلة أُخفيت أو عُلِّق مبدعها أو حلّت محلّها نسخة أحدث قبل أن يعمل العامل لا يُرسَل عنها شيء.
 * الجمهور: متابعون موثَّقون وغير مجمَّدين (التفضيل/المفتاح الشامل يُفحصان داخل NotificationDispatcher). لا XP ولا عملة ولا تقدّم ولا مكافأة.
 */
class DispatchCreatorSeriesChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use \App\Jobs\Concerns\BoundedRetries;

    public int $tries = 3;

    public function __construct(public int $revisionId, public bool $isUpdate = false, public int $afterFollowId = 0) {}

    public function handle(NotificationDispatcher $dispatcher, CreatorSeriesVisibilityService $visibility): void
    {
        $revision = CreatorSeriesRevision::query()->find($this->revisionId, ['id', 'creator_series_id', 'status']);

        if ($revision === null || $revision->status !== CreatorSeriesRevision::STATUS_APPROVED) {
            return;
        }

        $series = $visibility->visibleQuery()
            ->where('creator_series.id', $revision->creator_series_id)->where('creator_series.published_revision_id', $revision->id)
            ->first(['creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_title']);

        if (! $series instanceof CreatorSeries) {
            return;
        }

        $creatorUserId = (int) CreatorProfile::query()->whereKey($series->creator_profile_id)->value('user_id');
        $creatorName = (string) User::query()->whereKey($creatorUserId)->value('name');
        $size = max(1, (int) config('player_notifications.chunk_size', 200));

        $follows = CreatorFollow::query()
            ->where('creator_profile_id', $series->creator_profile_id)->where('id', '>', $this->afterFollowId)
            ->orderBy('id')->limit($size)->get(['id', 'user_id']);

        if ($follows->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $follows->pluck('user_id'))->where('is_frozen', false)->whereNotNull('email_verified_at')->get()->keyBy('id');

        foreach ($follows as $follow) {
            $user = $users->get($follow->user_id);

            if ($user === null || (int) $user->id === $creatorUserId) {
                continue;
            }

            $dispatcher->dispatch(
                $user,
                NotificationType::CreatorNewSeries,
                ['creator' => $creatorName, 'title' => (string) $series->published_title, 'updated' => $this->isUpdate],
                "creator-series:{$revision->id}:{$user->id}",
                ['creator_series_id' => $series->id],
                ['series' => $series->public_id],
            );
        }

        if ($follows->count() === $size) {
            self::dispatch($this->revisionId, $this->isUpdate, (int) $follows->last()->id);
        }
    }
}
`````

### `app/Models/CreatorSeries.php`

`sha256: cfdc560e9c114ae9badc483b11f5e7893afb8eaf059eb9171e86f0d3dc66635b`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E30 - سلسلة مبدع: الهوية الثابتة (public_id + slug) + لقطة النشر الحالية + الإخفاء/الأرشفة. المحتوى نفسه بالنسخ (CreatorSeriesRevision).
 *
 * لا عمود ظهور مخزَّن: الظهور العام يُشتقّ حيًّا في CreatorSeriesVisibilityService (منشورة ∧ غير مخفية/مؤرشفة ∧ مبدع معتمَد وحسابه سليم ∧ كل عناصر
 * النسخة المنشورة ظاهرة الآن). لا اقتصاد: لا جواهر/XP/مهام/صدارة. لا تعيين جماعي: الكتابة عبر خدمات السلاسل فقط.
 */
class CreatorSeries extends Model
{
    public const HIDDEN_MODERATION = 'moderation';

    protected $table = 'creator_series';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CreatorSeries $series) {
            $series->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'archived_at' => 'datetime',
            'hidden_at' => 'datetime',
            'published_items_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CreatorSeriesRevision::class);
    }

    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesRevision::class, 'latest_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesRevision::class, 'published_revision_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function isPublished(): bool
    {
        return $this->published_revision_id !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** رابط الغلاف المنشور (لقطة السلسلة). */
    public function publishedCoverUrl(): ?string
    {
        return app(\App\Services\Creators\CreatorMediaService::class)->url($this->published_cover_path);
    }
}
`````

### `app/Models/CreatorSeriesItem.php`

`sha256: 411fdd4b1f9b1264931caf09cca55c9cf4f009c166858c0bac1ef52e6eb633f5`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E30 - عنصر سلسلة: عمل مجتمع منشور بموضع صريح داخل نسخة. الفريد: (النسخة، العمل) و(النسخة، الموضع). */
class CreatorSeriesItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesRevision::class, 'creator_series_revision_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}
`````

### `app/Models/CreatorSeriesRevision.php`

`sha256: 844ee5513a2de4c2b6d36df6bfbec6b25e2e9c45f6ba979f7b1d23f042a4c291`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E30 - نسخة سلسلة (سجل التاريخ): العنوان/الوصف/الغلاف + عناصرها المرتبة + حالة سير العمل والمراجعة.
 * draft → pending_review → approved | changes_requested | rejected. المعتمدة لا تُعدَّل أبدًا (التعديل = نسخة جديدة).
 */
class CreatorSeriesRevision extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_CHANGES_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'revision_number' => 'integer'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreatorSeriesItem::class)->orderBy('position');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED], true);
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    /** رابط غلاف النسخة (يضعه المخدّم وحده من رفع صالح). */
    public function coverUrl(): ?string
    {
        return app(\App\Services\Creators\CreatorMediaService::class)->url($this->cover_path);
    }
}
`````

### `app/Models/SponsorshipContribution.php`

`sha256: c7948dec1bcfc0ef55af1d7a7ce754c29b3bbaba009f3997dbd5e9a03a33824b`

`````php
<?php

namespace App\Models;

use App\Services\Sponsorship\SponsorshipException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E30 - مساهمة. مصدر الحقيقة المالي الوحيد للتمويل/المستويات/الصدارة/التعزيز (status = confirmed فقط). المبالغ أعداد صحيحة بالوحدة الصغرى.
 *
 * حماية بنيوية (لا تعتمد على حسن نية المنادي): بعد مغادرة pending لا يمكن تعديل الحقول المالية (المبلغ/العملة/المعادل/الداعم/البرنامج) إطلاقًا؛
 * التصحيح = استرداد ثم مساهمة جديدة. الانتقالات المسموحة فقط: pending → confirmed|cancelled و confirmed → refunded. لا حذف أبدًا.
 * لا تعيين جماعي ($guarded): الكتابة عبر ContributionService فقط.
 */
class SponsorshipContribution extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_REFUNDED];

    /** @var list<string> حقول لا تتغير بعد مغادرة pending. */
    public const FINANCIAL_FIELDS = ['original_amount_minor', 'currency', 'accounting_amount_minor', 'accounting_currency', 'supporter_profile_id', 'sponsorship_program_id'];

    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (SponsorshipContribution $c) {
            $c->public_id ??= (string) Str::ulid();
        });

        static::updating(function (SponsorshipContribution $c) {
            $was = (string) $c->getOriginal('status');

            if ($c->isDirty('status') && ! in_array((string) $c->status, self::TRANSITIONS[$was] ?? [], true)) {
                throw new SponsorshipException('انتقال حالة غير مسموح لهذه المساهمة.');
            }

            if ($was !== self::STATUS_PENDING) {
                foreach (self::FINANCIAL_FIELDS as $field) {
                    if ($c->isDirty($field)) {
                        throw new SponsorshipException('الحقول المالية لمساهمة غير معلّقة محمية؛ صحّح بالاسترداد ثم سجّل مساهمة جديدة.');
                    }
                }
            }
        });

        static::deleting(function () {
            throw new SponsorshipException('لا تُحذف المساهمات؛ التاريخ المالي يبقى (إلغاء أو استرداد بدل الحذف).');
        });
    }

    protected function casts(): array
    {
        return [
            'original_amount_minor' => 'integer',
            'accounting_amount_minor' => 'integer',
            'is_anonymous' => 'boolean',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(SupporterProfile::class, 'supporter_profile_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
`````

### `app/Models/SponsorshipEventRewardPlan.php`

`sha256: 6d6580be0c41800f349fef74a1b578058278fca33774081b1e4b6e1dd749e85f`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E30 - خطة تعزيز منافسة مقفلة قبل بدئها: لقطة التمويل والنسبة. بعد القفل لا تتغير (المساهمات المتأخرة لا تُغيّر جوائز منافسة جارية).
 * منافسة بلا خطة مقفلة وقت التوزيع = بلا تعزيز (فشل مغلق).
 */
class SponsorshipEventRewardPlan extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['funding_snapshot_minor' => 'integer', 'funding_step_minor' => 'integer', 'boost_bps' => 'integer', 'locked_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class, 'competitive_event_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }
}
`````

### `app/Models/SponsorshipProgram.php`

`sha256: 25932145c22f7c2675c07b935591e7042a2e4d0d77dd76cb54492362edcb58d3`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E30 - برنامج رعاية لهدف رسمي. الهدف من قائمة بيضاء (TARGETS) ويُتحقق من وجوده ورسميته بـSponsorshipProgramService؛ أحجيات/محتوى المجتمع ليست هدفًا أبدًا.
 * خطة التعزيز (funding_step_minor/boost_per_step_bps/max_boost_bps) حقول ضبط فقط؛ كل الحساب والمنح بـSponsorshipRewardService.
 * لا تعيين جماعي: كل كتابة عبر الخدمة (تدقيق).
 */
class SponsorshipProgram extends Model
{
    public const TARGET_PLATFORM = 'platform';

    public const TARGET_SEASON = 'season';

    public const TARGET_CAMPAIGN = 'campaign';

    public const TARGET_COMPETITIVE_EVENT = 'competitive_event';

    public const TARGETS = [self::TARGET_PLATFORM, self::TARGET_SEASON, self::TARGET_CAMPAIGN, self::TARGET_COMPETITIVE_EVENT];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CLOSED, self::STATUS_CANCELLED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'goal_amount_minor' => 'integer',
            'show_public_amounts' => 'boolean',
            'show_goal_progress' => 'boolean',
            'reward_boost_enabled' => 'boolean',
            'funding_step_minor' => 'integer',
            'boost_per_step_bps' => 'integer',
            'max_boost_bps' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'reward_plan_locked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SponsorshipContribution::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(SponsorshipProgramRole::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** نشط الآن: حالة active ضمن نافذة البدء/الانتهاء (إن وُجدت). */
    public function scopeActiveNow(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($table.'.status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull($table.'.starts_at')->orWhere($table.'.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull($table.'.ends_at')->orWhere($table.'.ends_at', '>=', now()));
    }

    public function isActiveNow(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo(now()))
            && ($this->ends_at === null || $this->ends_at->greaterThanOrEqualTo(now()));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
`````

### `app/Models/SponsorshipProgramRole.php`

`sha256: 6ad33e11d34b4be7be0b4dd770ed7b08151a540ff4ae050d8f10dd9a4c07f6df`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E30 - دور داعم ضمن برنامج: supporter < sponsor < main_sponsor. الراعي الرئيسي واحد لكل برنامج بقيد قاعدة البيانات (main_marker فريد).
 * الراعي الرئيسي ≠ أعلى داعم: يُعيَّن إداريًا ويشترط مساهمة مؤكَّدة بهذا البرنامج.
 */
class SponsorshipProgramRole extends Model
{
    public const ROLE_SUPPORTER = 'supporter';

    public const ROLE_SPONSOR = 'sponsor';

    public const ROLE_MAIN_SPONSOR = 'main_sponsor';

    public const ROLES = [self::ROLE_SUPPORTER, self::ROLE_SPONSOR, self::ROLE_MAIN_SPONSOR];

    protected $guarded = ['*'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(SupporterProfile::class, 'supporter_profile_id');
    }
}
`````

### `app/Models/SponsorshipRewardBonus.php`

`sha256: d17dce6370fa60e2d902643278af287310139bff1ddaeaefe2d83927341368d2`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E30 - سجل مكافأة رعاية مُنحت فعلًا (لقطة: الأساس، النقاط الأساسية، المحسوب، الممنوح، التمويل وقتها، البرنامج). source_key فريد ⇒ لا منح مزدوج.
 * هذا سجل توزيع فقط؛ الأصل الاقتصادي (الجواهر) مصدر حقيقته دفتر المحفظة (currency_transactions) عبر CurrencyWalletService.
 */
class SponsorshipRewardBonus extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }
}
`````

### `app/Models/SupporterProfile.php`

`sha256: fd6004d0b1889d42856f547f8136987c1c2bc6eaeb08b618dded8f4891cfd632`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E30 - ملف داعم (فرد/مؤسسة). لا تفاصيل دفع هنا ولا بالنظام كله. العلنية = status active ∧ is_public (scopePublic) وتُفحص حيًّا بكل عرض عام.
 * لا تعيين جماعي: كل كتابة عبر SupporterService (تدقيق + تنظيف + رابط آمن). الرابط العام بـslug (لا معرّف داخلي)؛ public_id للإحالات الآمنة.
 */
class SupporterProfile extends Model
{
    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_ORGANIZATION = 'organization';

    public const TYPES = [self::TYPE_INDIVIDUAL, self::TYPE_ORGANIZATION];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (SupporterProfile $profile) {
            $profile->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'anonymous_by_default' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SponsorshipContribution::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(SponsorshipProgramRole::class);
    }

    /** علني: نشط ومعلن. الموقوف/غير النشط/الخاص لا يظهر بأي سطح عام (ولو بقيت مساهماته تُحتسب بمجاميع البرنامج). */
    public function scopePublic(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($table.'.status', self::STATUS_ACTIVE)->where($table.'.is_public', true);
    }

    public function isPublic(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_public;
    }

    public function isOrganization(): bool
    {
        return $this->type === self::TYPE_ORGANIZATION;
    }
}
`````

### `app/Models/SupporterTier.php`

`sha256: 28d07a62363ad4c602b42dc95c94e6f77d4a7f500dc489fdded670ce1f4b7ea8`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E30 - مستوى داعم يُدار من الإدارة (اسم عربي + حدّ أدنى بالوحدة الصغرى لعملة المحاسبة). المستوى لا يُخزَّن على الداعم ولا يأتي من العميل:
 * يُشتقّ دائمًا من صافي دعمه المؤكَّد (SupporterTierService)، فالاسترداد يخفّضه آليًا.
 */
class SupporterTier extends Model
{
    protected $fillable = ['name', 'slug', 'minimum_accounting_amount_minor', 'sort_order', 'badge_key', 'is_active'];

    /** أي تعديل/حذف لمستوى يُبطل كاشات الرعاية فورًا (المستويات تُدار من قاعدة البيانات؛ الكاش ليس سلطة). */
    protected static function booted(): void
    {
        $bust = fn () => \App\Services\Sponsorship\SponsorshipCache::bust();
        static::saved($bust);
        static::deleted($bust);
    }

    protected function casts(): array
    {
        return ['minimum_accounting_amount_minor' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
`````

### `app/Policies/CreatorSeriesRevisionPolicy.php`

`sha256: 99dd0fea74f4b8ee2a1660353fafebda10dfea3cf99c73b8f4e74723f707e189`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E30 - سلاسل المبدعين المرسلة للمراجعة (نسخ السلاسل): العرض بـcreators.view والإجراءات (اعتماد/طلب تعديل/رفض/إخفاء/استعادة) بصلاحيتها داخل الخدمات المدقَّقة (creators.review). بالصلاحيات لا بأسماء الأدوار.
 * لا إنشاء ولا تعديل خام ولا حذف من لوحة الإدارة: التاريخ وسجل التدقيق لا يُمحيان، وكل تغيير حالة يمرّ بالخدمة (تدقيق + إشعار + إعلان المتابعين).
 */
class CreatorSeriesRevisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('creators.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('creators.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

### `app/Policies/SponsorshipContributionPolicy.php`

`sha256: e775affe974b640d49310fd0ee851fb573541648157c2069d566d9d775795c8c`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E30 - المساهمات (مالية): العرض sponsorships.view؛ التسجيل sponsorships.manage؛ التأكيد/الإلغاء/الاسترداد sponsorships.confirm فقط (فصل مالي) عبر ContributionService.
 * لا تعديل خام ولا حذف من لوحة الإدارة: التصحيح بالاسترداد.
 */
class SponsorshipContributionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

### `app/Policies/SponsorshipProgramPolicy.php`

`sha256: 409e9134b5936a1aec591c95a0d38b4929dd672ec8c90f08e6d4c298f20eb8a5`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/** E30 - برامج الرعاية: العرض sponsorships.view، الإنشاء/التعديل sponsorships.manage، بلا حذف (الإغلاق/الإلغاء بالخدمة). خطة المكافآت بصلاحية reward_plan داخل الخدمة. */
class SponsorshipProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

### `app/Policies/SupporterProfilePolicy.php`

`sha256: 575fdb12f127729f2ba2b57e513d4abae7a8552d297b0ee93b802cbd1b54037c`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E30 - ملفات الداعمين: العرض بـsponsorships.view، الإنشاء والتعديل بـsponsorships.manage (بالصلاحيات لا بأسماء الأدوار). لا حذف من لوحة الإدارة (التاريخ المالي مرتبط بها):
 * الإيقاف/الإخفاء بتغيير الحالة (مدقَّق عبر الخدمة).
 */
class SupporterProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

### `app/Policies/SupporterTierPolicy.php`

`sha256: 12b15f9e8ae2184ddf84fd3ad8dfdacab3f633f0c034b76ecab94c4c210c02cf`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/** E30 - مستويات الداعمين: العرض sponsorships.view، الإنشاء/التعديل sponsorships.manage. لا حذف (تعطيل المستوى بدل الحذف). */
class SupporterTierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('sponsorships.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesAnalytics.php`

`sha256: 255c880faa51beab211e199dabac00df7d05c4583ca03a6a0e9d97a26e5c2f83`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E30 - تحليلات تجميعية لسلاسل المبدع (للمالك فقط): بدايات العناصر، اللاعبون المميَّزون، الإكمالات، ونسبة الإكمال. **أرقام مجمَّعة بلا أي هوية لاعب.**
 *
 *  - المصدر community_puzzle_attempts (سجل لعب المجتمع) على عناصر النسخة المنشورة الحالية فقط؛ إكمال اللاعب = كل العناصر محلولة على نسخها المنشورة الحالية (نفس قاعدة التقدّم).
 *  - starts = عدد أزواج (لاعب، عنصر) التي بدأها اللاعبون؛ players = لاعبون مميَّزون؛ completions = لاعبون أكملوا كل العناصر.
 *  - لا تتبّع «المشاهدات»: طلب GET لا يكتب شيئًا أبدًا (لا عدّاد مشاهدات ولا جدول مشاهدات).
 *  - مخزَّنة TTL محدود (creator_series.analytics.cache_ttl) لكل مبدع؛ الكاش للعرض فقط وليس سلطة لأي قرار.
 *  - استعلامان مجمَّعان ثابتان مهما كبر عدد اللاعبين (التجميع بقاعدة البيانات لا بالـPHP).
 */
class CreatorSeriesAnalytics
{
    /** @return array<int, array{players:int, starts:int, completions:int, completion_rate:int}> مفتاحها معرّف السلسلة الداخلي */
    public function forCreator(CreatorProfile $profile): array
    {
        return CachedSnapshot::remember(
            'creator-series:analytics:'.$profile->id,
            (int) config('creator_series.analytics.cache_ttl', 600),
            fn () => $this->compute((int) $profile->id),
        );
    }

    /** @return array{players:int, starts:int, completions:int, completion_rate:int} مجموع كل سلاسل المبدع */
    public function totals(array $perSeries): array
    {
        $players = array_sum(array_column($perSeries, 'players'));
        $completions = array_sum(array_column($perSeries, 'completions'));

        return [
            'players' => (int) $players,
            'starts' => (int) array_sum(array_column($perSeries, 'starts')),
            'completions' => (int) $completions,
            'completion_rate' => $players > 0 ? (int) floor($completions * 100 / $players) : 0,
        ];
    }

    protected function compute(int $profileId): array
    {
        $perUser = DB::table('creator_series as s')
            ->join('creator_series_items as i', 'i.creator_series_revision_id', '=', 's.published_revision_id')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->join('community_puzzle_attempts as a', 'a.creator_puzzle_id', '=', 'w.id')
            ->where('s.creator_profile_id', $profileId)
            ->whereNotNull('s.published_revision_id')
            ->groupBy('s.id', 's.published_items_count', 'a.user_id')
            ->selectRaw('s.id as series_id, s.published_items_count as total_items, a.user_id as user_id')
            ->selectRaw('COUNT(DISTINCT a.creator_puzzle_id) as started')
            ->selectRaw('COUNT(DISTINCT CASE WHEN a.is_correct AND a.puzzle_id = w.published_puzzle_id THEN a.creator_puzzle_id END) as solved');

        $rows = DB::query()->fromSub($perUser, 't')
            ->groupBy('series_id')
            ->selectRaw('series_id, COUNT(*) as players, SUM(started) as starts')
            ->selectRaw('SUM(CASE WHEN solved >= total_items THEN 1 ELSE 0 END) as completions')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $players = (int) $row->players;
            $completions = (int) $row->completions;
            $out[(int) $row->series_id] = [
                'players' => $players,
                'starts' => (int) $row->starts,
                'completions' => $completions,
                'completion_rate' => $players > 0 ? (int) floor($completions * 100 / $players) : 0,
            ];
        }

        return $out;
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesAnnouncer.php`

`sha256: b22bf8e01a43442db546ee36c141a7f38f9255cebd69d39b313d0c0d57d28732`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Jobs\DispatchCreatorSeriesChunk;
use App\Models\CreatorFollow;
use App\Models\CreatorSeriesRevision;
use Throwable;

/**
 * E30 - نقطة الإعلان الوحيدة عن «سلسلة جديدة» لمتابعي المبدع، تُستدعى من CreatorSeriesReviewService **بعد** إتمام معاملة الاعتماد (بعد الـcommit).
 * نفس نمط E29 (CreatorPublicationAnnouncer): فشل الإعلان لا يتراجع عنه الاعتماد، لا متابعون ⇒ لا Job، والتوزيع بدفعات بمؤشر id عبر الطابور.
 * إشعار واحد لكل (نسخة منشورة، متابع). نسخة جديدة لسلسلة نُشرت سابقًا تُعلَن فقط إن فُعِّل creator_series.notifications.on_revision.
 */
class CreatorSeriesAnnouncer
{
    public function announce(CreatorSeriesRevision $revision, int $creatorProfileId, bool $isUpdate): void
    {
        try {
            if (! (bool) config('creator_series.notifications.on_new_series', true)) {
                return;
            }

            if ($isUpdate && ! (bool) config('creator_series.notifications.on_revision', true)) {
                return;
            }

            if (! CreatorFollow::query()->where('creator_profile_id', $creatorProfileId)->exists()) {
                return;
            }

            DispatchCreatorSeriesChunk::dispatch((int) $revision->id, $isUpdate)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesDiscovery.php`

`sha256: a541ac30219b9c70b933431a942f847547b9cecf04a4f7988fc959592e49890d`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorSeries;
use App\Models\User;
use App\Services\Discovery\DiscoverCard;
use App\Support\CachedSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * E30 - سلاسل المجتمع بالاكتشاف: «سلاسل من المجتمع» (أحدث الظاهرة، بسقف سلاسل لكل مبدع لتنوّع المبدعين) و«سلاسل جديدة ممّن تتابعهم» (إشارة متابعة E29، للمسجَّل فقط).
 *
 *  - **الظهور حيّ دائمًا** (CreatorSeriesVisibilityService): الكاش يحمل **معرّفات فقط** (لا أسماء ولا عناوين ولا بيانات لاعبين)، وكل عرض يعيد فحص الظهور باستعلام واحد،
 *    فإخفاء أحجية أو تعليق مبدع يُخرج السلسلة من الاكتشاف فورًا ولو بقيت المعرّفات بالكاش. الكاش ليس سلطة ظهور أبدًا.
 *  - عدد الاستعلامات ثابت لا يتبع حجم البيانات: معرّفات (1، أو 0 من الكاش) + بطاقات (1) + للمسجَّل: المتابَعون (1).
 *  - عرض فقط: لا مكافآت ولا رعاية ولا ترتيب مدفوع ولا كتابة. فشل هذا القسم لا يكسر مركز الاكتشاف (DiscoveryService يعزله).
 */
class CreatorSeriesDiscovery
{
    public function __construct(protected CreatorSeriesVisibilityService $visibility) {}

    /**
     * عزل الفشل هنا لا بـDiscoveryService: حارس E27 (S9) يمنع أي استدعاء تسجيل بشيفرة البحث والاكتشاف كي لا يصل مصطلح بحث لسجل أبدًا؛ وهذا الملف خارج ذلك النطاق ولا يرى مصطلحًا.
     *
     * @return array{new: list<DiscoverCard>, following: list<DiscoverCard>}
     */
    public function sections(?User $viewer): array
    {
        try {
            return $this->build($viewer);
        } catch (\Throwable $e) {
            report($e);

            return ['new' => [], 'following' => []];
        }
    }

    /** @return array{new: list<DiscoverCard>, following: list<DiscoverCard>} */
    protected function build(?User $viewer): array
    {
        if (! (bool) config('creator_series.enabled', true)) {
            return ['new' => [], 'following' => []];
        }

        $limit = max(1, (int) config('creator_series.discovery.section_limit', 6));
        $cap = max(1, (int) config('creator_series.discovery.per_creator_cap', 2));
        $following = collect();

        if ($viewer !== null) {
            $following = $this->capPerCreator(
                $this->cards()
                    ->whereIn('creator_series.creator_profile_id', fn ($q) => $q->select('creator_profile_id')->from('creator_follows')->where('user_id', $viewer->id))
                    ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
                    ->limit($limit * $cap + $limit)->get(),
                $cap,
                $limit,
            );
        }

        $followingIds = $following->pluck('id')->all();
        $ids = $this->newestIds();
        $new = $ids === [] ? collect() : $this->capPerCreator(
            $this->cards()->whereIn('creator_series.id', $ids)->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')->get()
                ->reject(fn ($s) => in_array($s->id, $followingIds, true))->values(),
            $cap,
            $limit,
        );

        return [
            'new' => $new->map(fn ($s) => $this->card($s, null))->values()->all(),
            'following' => $following->map(fn ($s) => $this->card($s, 'من مبدع تتابعه'))->values()->all(),
        ];
    }

    /** معرّفات أحدث الظاهرة (كاش قصير). تُعاد المقارنة بالظهور الحي في الاستعلام التالي. @return list<int> */
    protected function newestIds(): array
    {
        return CachedSnapshot::remember('discover.series.newest', (int) config('creator_series.discovery.cache_ttl', 120), function () {
            return $this->visibility->visibleQuery()
                ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
                ->limit(max(1, (int) config('creator_series.discovery.pool', 30)))->pluck('creator_series.id')->map(fn ($id) => (int) $id)->all();
        });
    }

    /** استعلام بطاقات ظاهرة بحقول لقطة النشر + اسم المبدع (ضمّان ثابتان، لا N+1). */
    protected function cards(): Builder
    {
        return $this->visibility->visibleQuery()
            ->join('creator_profiles as dcp', 'dcp.id', '=', 'creator_series.creator_profile_id')
            ->join('users as dcu', 'dcu.id', '=', 'dcp.user_id')
            ->select('creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_title', 'creator_series.published_items_count', 'creator_series.published_at', 'dcu.name as creator_name');
    }

    protected function capPerCreator(Collection $series, int $cap, int $limit): Collection
    {
        $perCreator = [];

        return $series->filter(function ($s) use (&$perCreator, $cap) {
            $perCreator[$s->creator_profile_id] = ($perCreator[$s->creator_profile_id] ?? 0) + 1;

            return $perCreator[$s->creator_profile_id] <= $cap;
        })->take($limit)->values();
    }

    protected function card(CreatorSeries $series, ?string $reason): DiscoverCard
    {
        return new DiscoverCard(
            (string) $series->published_title,
            route('series.show', $series->public_id),
            'grid',
            (int) $series->published_items_count.' أحجية · بقلم '.$series->getAttribute('creator_name'),
            'سلسلة من المجتمع',
            'primary',
            $reason,
        );
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesException.php`

`sha256: 2560634989e0a84d59e0749371196910691ec077befc45a6ceff16a5d9f946b1`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Services\Creators\CreatorException;

/** E30 - خطأ مجال متوقَّع لسلاسل المبدعين (رسالته عربية آمنة للعرض). يرث CreatorException فيلتقطه أساس متحكمات المبدعين نفسه. */
class CreatorSeriesException extends CreatorException {}
`````

### `app/Services/CreatorSeries/CreatorSeriesNavigator.php`

`sha256: 5496913f52c914b29fbece0c070b9c5a290ac731a973e1b396effbe3716239ac`

`````php
<?php

namespace App\Services\CreatorSeries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E30 - سياق السلسلة على صفحة أحجية مجتمع (اختياري: ?series=...). يعمل **فقط** عند وجود المعامل، فصفحة الأحجية العادية بلا أي استعلام إضافي.
 * يعيد الموضع والسابق والتالي من النسخة المنشورة للسلسلة الظاهرة حاليًا إن كانت الأحجية عنصرًا فيها، وإلا null (معامل مختلق/سلسلة مخفية = لا سياق ولا خطأ).
 * قراءة فقط: لا يكتب شيئًا ولا يمنح شيئًا.
 */
class CreatorSeriesNavigator
{
    public function __construct(protected CreatorSeriesVisibilityService $visibility) {}

    /** @return array{series:string, title:string, url:string, position:int, total:int, previous:?array{public_id:string,title:string}, next:?array{public_id:string,title:string}}|null */
    public function context(mixed $seriesPublicId, int $workId): ?array
    {
        if (! (bool) config('creator_series.enabled', true) || ! is_string($seriesPublicId) || ! Str::isUlid($seriesPublicId)) {
            return null;
        }

        $series = $this->visibility->visibleQuery()
            ->where('creator_series.public_id', Str::upper($seriesPublicId))
            ->first(['creator_series.id', 'creator_series.public_id', 'creator_series.published_revision_id', 'creator_series.published_title']);

        if ($series === null) {
            return null;
        }

        $rows = DB::table('creator_series_items as i')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.creator_series_revision_id', $series->published_revision_id)
            ->orderBy('i.position')
            ->get(['w.id as work_id', 'w.public_id', 'w.published_title'])->values();

        $index = $rows->search(fn ($r) => (int) $r->work_id === $workId);

        if ($index === false) {
            return null;
        }

        $shape = fn ($r) => $r === null ? null : ['public_id' => (string) $r->public_id, 'title' => (string) $r->published_title];

        return [
            'series' => (string) $series->public_id,
            'title' => (string) $series->published_title,
            'url' => route('series.show', $series->public_id),
            'position' => $index + 1,
            'total' => $rows->count(),
            'previous' => $shape($rows->get($index - 1)),
            'next' => $shape($rows->get($index + 1)),
        ];
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesNotifier.php`

`sha256: 518d7b31e1ce2f17214d61b3330fb1febc417f308ef50527829c999f51cb4fa6`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E30 - إشعارات المبدع بنتيجة مراجعة سلسلته (E15). تُستدعى دائمًا **بعد** نجاح تغيير الحالة (بعد الـcommit) وفشلها لا يُرجع أي حالة:
 * إخفاق الإشعار لا يتراجع عنه الاعتماد/الرفض/الإخفاء. مفاتيح idempotency دلالية (حدث واحد = إشعار واحد).
 */
class CreatorSeriesNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function approved(CreatorSeriesRevision $revision): void
    {
        $this->send($revision, NotificationType::SeriesApproved, ['title' => $revision->title], 'series_approved:'.$revision->id);
    }

    public function changesRequested(CreatorSeriesRevision $revision): void
    {
        $this->send($revision, NotificationType::SeriesChangesRequested, ['title' => $revision->title, 'note' => $revision->review_note], 'series_changes_requested:'.$revision->id.':'.($revision->reviewed_at?->timestamp ?? 0));
    }

    public function rejected(CreatorSeriesRevision $revision): void
    {
        $this->send($revision, NotificationType::SeriesRejected, ['title' => $revision->title, 'note' => $revision->review_note], 'series_rejected:'.$revision->id);
    }

    public function hidden(CreatorSeries $series): void
    {
        try {
            $user = CreatorProfile::query()->with('user')->find($series->creator_profile_id)?->user;

            if ($user !== null) {
                $this->dispatcher->dispatch($user, NotificationType::SeriesHidden, ['title' => (string) $series->published_title], 'series_hidden:'.$series->id.':'.($series->hidden_at?->timestamp ?? 0));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function send(CreatorSeriesRevision $revision, NotificationType $type, array $params, string $key): void
    {
        try {
            $user = $revision->series()->first()?->creator()->first()?->user;

            if ($user !== null) {
                $this->dispatcher->dispatch($user, $type, $params, $key);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesProgressService.php`

`sha256: d80ac2cc928795efeb2288d28372417385ec6ebf2405027dec03f56d9412206a`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CommunityPuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * E30 - تقدّم المستخدم في سلسلة: **مشتقّ دائمًا** من community_puzzle_attempts (سجل لعب المجتمع نفسه) لا جدول تقدّم ولا كاش.
 * العنصر مكتمل ⇔ للمستخدم محاولة صحيحة على النسخة المنشورة الحالية للعمل (وهي نفس قاعدة «حللتها» في صفحة الأحجية CommunityPlayService::state)،
 * فحلّ الأحجية قبل وجود السلسلة أو من خارجها يُحتسب تلقائيًا، و«أكمل» يفتح أول عنصر غير محلول بالترتيب.
 * استعلام واحد محدود بعناصر السلسلة (≤ 25) مهما كان عددها. لا كتابة هنا أبدًا: لا جواهر ولا XP ولا مهام ولا سلسلة أيام ولا صدارة.
 */
class CreatorSeriesProgressService
{
    /**
     * @param  Collection<int, object>  $items  عناصر بالترتيب، لكل منها work_id (creator_puzzles.id) و public_id
     * @return array{done:int, total:int, percent:int, completed:bool, solved:array<int,bool>, next_public_id:?string}
     */
    public function forUser(?User $user, Collection $items): array
    {
        $total = $items->count();
        $solvedIds = [];

        if ($user !== null && $total > 0) {
            $solvedIds = CommunityPuzzleAttempt::query()
                ->join('creator_puzzles as w', 'w.id', '=', 'community_puzzle_attempts.creator_puzzle_id')
                ->whereColumn('community_puzzle_attempts.puzzle_id', 'w.published_puzzle_id')
                ->where('community_puzzle_attempts.user_id', $user->id)
                ->where('community_puzzle_attempts.is_correct', true)
                ->whereIn('community_puzzle_attempts.creator_puzzle_id', $items->pluck('work_id')->all())
                ->distinct()->pluck('community_puzzle_attempts.creator_puzzle_id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $solved = [];
        $next = null;

        foreach ($items as $item) {
            $isSolved = in_array((int) $item->work_id, $solvedIds, true);
            $solved[(int) $item->work_id] = $isSolved;

            if (! $isSolved && $next === null) {
                $next = (string) $item->public_id;
            }
        }

        $done = count(array_filter($solved));

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            'completed' => $total > 0 && $done === $total,
            'solved' => $solved,
            'next_public_id' => $next,
        ];
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesReviewService.php`

`sha256: ae7dbec3e612772f547eac346febb48bd49a2c536791205365315faae819b777`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E30 - مراجعة السلاسل المرسلة. المراجع بصلاحية creators.review فقط (RBAC المبدعين نفسها)، ولا يعدّل محتوى المبدع بصمت: اعتماد/طلب تعديل/رفض، وإخفاء/استعادة إشرافيان.
 *
 * الاعتماد ذرّي: قفل النسخة والسلسلة ← إعادة تحقق كاملة من العناصر (أعمال المبدع نفسه، ظاهرة الآن، العدد ضمن الحدود) ← نشر لقطة السلسلة ← تدقيق.
 * أي فشل يُرجع كل شيء. الإشعار والإعلان للمتابعين بعد الـcommit، وفشلهما لا يتراجع عنه الاعتماد.
 * النسخة المنشورة القديمة لا تُحذف ولا تُعدَّل: عناصرها وتاريخها يبقيان؛ لقطة السلسلة وحدها تتحول للنسخة الجديدة.
 * لا اقتصاد: لا يوجد هنا أي مرجع للمحافظ/XP/الجوائز/المهام.
 */
class CreatorSeriesReviewService
{
    public function __construct(
        protected CreatorSeriesService $series,
        protected OperationalAuditService $audit,
        protected CreatorSeriesNotifier $notifier,
        protected CreatorSeriesAnnouncer $announcer,
    ) {}

    public function approve(CreatorSeriesRevision $revision, User $actor, ?string $note = null): CreatorSeriesRevision
    {
        $this->assertReviewer($actor);

        $isUpdate = false;
        $creatorProfileId = 0;

        $approved = DB::transaction(function () use ($revision, $actor, $note, &$isUpdate, &$creatorProfileId) {
            $locked = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $series = CreatorSeries::query()->lockForUpdate()->findOrFail($locked->creator_series_id);
            $profile = CreatorProfile::query()->with('user')->findOrFail($series->creator_profile_id);

            if (! $profile->isApproved() || (bool) $profile->user?->is_frozen) {
                throw new CreatorSeriesException('لا يمكن اعتماد سلسلة مبدع معلَّق أو حسابه مقيَّد.');
            }

            // العناصر لحظة الاعتماد: أعمال المبدع نفسه، ظاهرة الآن، والعدد ضمن الحدود.
            $this->series->assertItemsReady($profile, $locked);

            $isUpdate = $series->first_published_at !== null;
            $creatorProfileId = (int) $series->creator_profile_id;

            $locked->forceFill([
                'status' => CreatorSeriesRevision::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            $series->forceFill([
                'published_revision_id' => $locked->id,
                'published_title' => $locked->title,
                'published_cover_path' => $locked->cover_path,
                'published_items_count' => (int) DB::table('creator_series_items')->where('creator_series_revision_id', $locked->id)->count(),
                'published_at' => now(),
                'first_published_at' => $series->first_published_at ?? now(),
            ])->save();

            $this->audit->log('creator_series_approved', $locked, [
                'series' => $series->public_id,
                'revision' => $locked->revision_number,
                'title' => $locked->title,
                'items' => (int) $series->published_items_count,
            ], $actor);

            return $locked;
        });

        $this->notifier->approved($approved);
        $this->announcer->announce($approved, $creatorProfileId, $isUpdate);

        return $approved;
    }

    public function requestChanges(CreatorSeriesRevision $revision, User $actor, string $note): CreatorSeriesRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorSeriesRevision::STATUS_CHANGES_REQUESTED, 'creator_series_changes_requested');
        $this->notifier->changesRequested($revision);

        return $revision;
    }

    public function reject(CreatorSeriesRevision $revision, User $actor, string $note): CreatorSeriesRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorSeriesRevision::STATUS_REJECTED, 'creator_series_rejected');
        $this->notifier->rejected($revision);

        return $revision;
    }

    /** إخفاء إشرافي فوري لسلسلة منشورة (يُبقي التاريخ كله؛ تستعيده استعادة إشرافية فقط). */
    public function hide(CreatorSeries $series, User $actor, string $note): CreatorSeries
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        $series = DB::transaction(function () use ($series, $actor, $note) {
            $series = CreatorSeries::query()->lockForUpdate()->findOrFail($series->id);

            if (! $series->isPublished()) {
                throw new CreatorSeriesException('لا توجد سلسلة منشورة لإخفائها.');
            }

            if ($series->isHidden()) {
                throw new CreatorSeriesException('هذه السلسلة مخفية بالفعل.');
            }

            $series->forceFill([
                'hidden_at' => now(),
                'hidden_by' => $actor->id,
                'hidden_reason' => CreatorSeries::HIDDEN_MODERATION,
                'hidden_note' => $note,
            ])->save();

            $this->audit->log('creator_series_hidden', $series, ['title' => $series->published_title, 'note' => $note], $actor);

            return $series;
        });

        $this->notifier->hidden($series);

        return $series;
    }

    public function restore(CreatorSeries $series, User $actor): CreatorSeries
    {
        $this->assertReviewer($actor);

        return DB::transaction(function () use ($series, $actor) {
            $series = CreatorSeries::query()->lockForUpdate()->findOrFail($series->id);

            if (! $series->isHidden()) {
                throw new CreatorSeriesException('هذه السلسلة غير مخفية.');
            }

            $series->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null, 'hidden_note' => null])->save();
            $this->audit->log('creator_series_restored', $series, ['title' => $series->published_title], $actor);

            return $series->refresh();
        });
    }

    protected function close(CreatorSeriesRevision $revision, User $actor, string $note, string $status, string $auditAction): CreatorSeriesRevision
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        return DB::transaction(function () use ($revision, $actor, $note, $status, $auditAction) {
            $locked = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $locked->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, [
                'series' => $locked->series()->value('public_id'), 'revision' => $locked->revision_number, 'title' => $locked->title, 'note' => $note,
            ], $actor);

            return $locked;
        });
    }

    protected function assertPending(CreatorSeriesRevision $revision): void
    {
        if (! $revision->isPendingReview()) {
            throw new CreatorSeriesException('هذه النسخة ليست قيد المراجعة (ربما عولجت للتو).');
        }
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creator_series.limits.note_min');
        $max = (int) config('creator_series.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorSeriesException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesService.php`

`sha256: 794a84d53aed6bfe6c31cb062ab0e748e4e091cc075c6cc989abbc9f315a1f21`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\Creators\CreatorEligibility;
use App\Services\Creators\CreatorMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E30 - دورة تأليف السلسلة (استوديو المبدع): مسودة ← إرسال ← (طلب تعديل ↔ إعادة إرسال | اعتماد | رفض) ثم نسخة جديدة بعد النشر، أرشفة، حذف.
 * نفس ضمانات E28 للأحجيات حرفيًا:
 *  (1) الأهلية: ملف مبدع معتمَد + بريد موثَّق + حساب غير مجمَّد (CreatorEligibility) فالمعلَّق/المجمَّد لا ينشئ ولا يعدّل ولا يرسل.
 *  (2) الملكية بالاستعلام نفسه (creator_profile_id) فسلسلة غيرك = 404 كأنها غير موجودة (لا IDOR).
 *  (3) العناصر **أعمال المبدع نفسه فقط، منشورة وظاهرة الآن**، بلا تكرار، من 2 إلى 25 (قابل للضبط)، بموضع صريح 1..n. أحجية رسمية/لمبدع آخر ⇒ "غير موجودة" بلا تسريب.
 *  (4) قفل صف النسخة بمعاملة فلا تعارض تزامن؛ النسخة المرسلة/المعتمدة/المرفوضة لا تُعدَّل أبدًا: التعديل بعد النشر = نسخة جديدة تُراجَع، والمنشورة تبقى حيّة حتى اعتماد الجديدة.
 *  (5) نصوص عادية فقط (تنظيف العلامات في الطلب) ولا اقتصاد: لا يوجد هنا أي مرجع للمحافظ/XP/الجوائز.
 */
class CreatorSeriesService
{
    public const BUCKETS = ['all', 'draft', 'pending', 'changes', 'published', 'rejected', 'archived'];

    public function __construct(
        protected CreatorEligibility $eligibility,
        protected CreatorMediaService $media,
        protected CreatorSeriesVisibilityService $visibility,
    ) {}

    // ───────────── القراءة ─────────────

    /** سلسلة المبدع نفسه فقط (404 لغيره). */
    public function ownedSeries(CreatorProfile $profile, string $publicId): CreatorSeries
    {
        return CreatorSeries::query()->where('creator_profile_id', $profile->id)->where('public_id', $publicId)->firstOrFail();
    }

    public function paginate(CreatorProfile $profile, string $bucket = 'all'): LengthAwarePaginator
    {
        $query = CreatorSeries::query()
            ->select('creator_series.*')
            ->where('creator_series.creator_profile_id', $profile->id)
            ->leftJoin('creator_series_revisions as lr', 'lr.id', '=', 'creator_series.latest_revision_id');

        match ($bucket) {
            'draft' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_DRAFT),
            'pending' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_PENDING_REVIEW),
            'changes' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_CHANGES_REQUESTED),
            'published' => $query->whereNull('creator_series.archived_at')->whereNotNull('creator_series.published_revision_id'),
            'rejected' => $query->whereNull('creator_series.archived_at')->where('lr.status', CreatorSeriesRevision::STATUS_REJECTED),
            'archived' => $query->whereNotNull('creator_series.archived_at'),
            default => null,
        };

        return $query
            ->with(['latestRevision' => fn ($q) => $q->select('id', 'creator_series_id', 'revision_number', 'status', 'title', 'review_note', 'reviewed_at', 'submitted_at')])
            ->orderByDesc('creator_series.id')
            ->paginate((int) config('creator_series.studio_page_size'))
            ->withQueryString();
    }

    /** عدّادات حاويات الاستوديو باستعلام واحد. @return array<string,int> */
    public function bucketCounts(CreatorProfile $profile): array
    {
        $row = CreatorSeries::query()
            ->leftJoin('creator_series_revisions as lr', 'lr.id', '=', 'creator_series.latest_revision_id')
            ->where('creator_series.creator_profile_id', $profile->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as draft', [CreatorSeriesRevision::STATUS_DRAFT])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as pending', [CreatorSeriesRevision::STATUS_PENDING_REVIEW])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as changes', [CreatorSeriesRevision::STATUS_CHANGES_REQUESTED])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND creator_series.published_revision_id IS NOT NULL THEN 1 ELSE 0 END) as published')
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as rejected', [CreatorSeriesRevision::STATUS_REJECTED])
            ->selectRaw('SUM(CASE WHEN creator_series.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived')
            ->first();

        return [
            'all' => (int) ($row->total ?? 0),
            'draft' => (int) ($row->draft ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'changes' => (int) ($row->changes ?? 0),
            'published' => (int) ($row->published ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'archived' => (int) ($row->archived ?? 0),
        ];
    }

    /** أعمال المبدع المؤهَّلة كعناصر (منشورة وظاهرة الآن) لمنتقي المنشئ. بلا أعمدة المحتوى. */
    public function eligibleWorks(CreatorProfile $profile): Collection
    {
        return CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $profile->id)
            ->select('id', 'public_id', 'published_title', 'published_difficulty', 'published_category_id')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(max(1, (int) config('creators.caps.works', 100)))
            ->get();
    }

    /** عناصر نسخة بترتيبها (للمنشئ والمعاينة): معرّف العمل العام والعنوان الحالي وظهوره. */
    public function revisionItems(CreatorSeriesRevision $revision): Collection
    {
        return CreatorSeriesItem::query()
            ->join('creator_puzzles as w', 'w.id', '=', 'creator_series_items.creator_puzzle_id')
            ->where('creator_series_items.creator_series_revision_id', $revision->id)
            ->orderBy('creator_series_items.position')
            ->get(['creator_series_items.position', 'w.public_id', 'w.published_title', 'w.is_visible']);
    }

    // ───────────── الكتابة ─────────────

    /**
     * @param  array{title:string, description?:?string, items:array<int,string>}  $data  مدخلات مُتحقَّق منها
     */
    public function createDraft(User $user, array $data, ?UploadedFile $cover = null): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $coverPath = null;

        try {
            return DB::transaction(function () use ($profile, $data, $cover, &$coverPath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first(); // يسلسل فحص السقوف للمبدع الواحد
                $this->assertCap($profile);
                $works = $this->resolveItems($profile, (array) ($data['items'] ?? []));

                if ($cover !== null && (bool) config('creator_series.cover.enabled', true)) {
                    $coverPath = $this->media->store($cover, $profile);
                }

                $title = $this->title($data['title'] ?? '');

                $series = new CreatorSeries;
                $series->forceFill(['creator_profile_id' => $profile->id, 'slug' => $this->uniqueSlug($title)])->save();

                $revision = new CreatorSeriesRevision;
                $revision->forceFill([
                    'creator_series_id' => $series->id,
                    'revision_number' => 1,
                    'status' => CreatorSeriesRevision::STATUS_DRAFT,
                    'title' => $title,
                    'description' => $this->description($data['description'] ?? null),
                    'cover_path' => $coverPath,
                ])->save();

                $this->replaceItems($revision, $works);
                $series->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($coverPath, $profile);

            throw $e;
        }
    }

    public function updateDraft(User $user, string $publicId, array $data, ?UploadedFile $cover = null, bool $removeCover = false): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);
        $newPath = null;
        $oldPath = null;

        try {
            $revision = DB::transaction(function () use ($series, $profile, $data, $cover, $removeCover, &$newPath, &$oldPath) {
                $revision = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

                if (! $revision->isEditable()) {
                    throw new CreatorSeriesException($revision->isPendingReview() ? 'السلسلة قيد المراجعة؛ اسحبها أولًا لتعديلها.' : 'لا يمكن تعديل هذه النسخة؛ أنشئ نسخة جديدة.');
                }

                $works = $this->resolveItems($profile, (array) ($data['items'] ?? []));
                $path = $revision->cover_path;

                if ($cover !== null && (bool) config('creator_series.cover.enabled', true)) {
                    $newPath = $this->media->store($cover, $profile);
                    $oldPath = $path;
                    $path = $newPath;
                } elseif ($removeCover) {
                    $oldPath = $path;
                    $path = null;
                }

                $revision->forceFill([
                    'title' => $this->title($data['title'] ?? ''),
                    'description' => $this->description($data['description'] ?? null),
                    'cover_path' => $path,
                ])->save();

                $this->replaceItems($revision, $works);

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($newPath, $profile);

            throw $e;
        }

        $this->media->delete($oldPath, $profile); // بعد الـcommit: الغلاف القديم لا يُحذف قبل نجاح الاستبدال

        return $revision;
    }

    public function submit(User $user, string $publicId): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        return DB::transaction(function () use ($series, $profile) {
            CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $revision = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

            if (! $revision->isEditable()) {
                throw new CreatorSeriesException('هذه النسخة أُرسلت بالفعل أو لا يمكن إرسالها.');
            }

            // إعادة تحقق لحظة الإرسال: عنصر أُخفي/أُرشف منذ الحفظ يمنع الإرسال بدل أن تُرسَل سلسلة لن تظهر.
            $this->assertItemsReady($profile, $revision);

            $pending = CreatorSeriesRevision::query()
                ->join('creator_series', 'creator_series.id', '=', 'creator_series_revisions.creator_series_id')
                ->where('creator_series.creator_profile_id', $profile->id)
                ->where('creator_series_revisions.status', CreatorSeriesRevision::STATUS_PENDING_REVIEW)
                ->count();

            if ($pending >= (int) config('creator_series.limits.max_pending')) {
                throw new CreatorSeriesException('وصلت للحد الأقصى من السلاسل قيد المراجعة؛ انتظر نتيجة مراجعتها.');
            }

            $revision->forceFill([
                'status' => CreatorSeriesRevision::STATUS_PENDING_REVIEW,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            return $revision;
        });
    }

    public function withdraw(User $user, string $publicId): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        return DB::transaction(function () use ($series) {
            $revision = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

            if (! $revision->isPendingReview()) {
                throw new CreatorSeriesException('لا يوجد إرسال قيد المراجعة لسحبه.');
            }

            $revision->forceFill(['status' => CreatorSeriesRevision::STATUS_DRAFT, 'submitted_at' => null])->save();

            return $revision;
        });
    }

    /** نسخة جديدة مسودة من آخر نسخة نهائية (معتمدة/مرفوضة)؛ المنشورة الحالية تبقى حيّة بلا مساس. */
    public function revise(User $user, string $publicId): CreatorSeriesRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);
        $copiedPath = null;

        try {
            return DB::transaction(function () use ($series, $profile, &$copiedPath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
                $latest = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

                if (! in_array($latest->status, [CreatorSeriesRevision::STATUS_APPROVED, CreatorSeriesRevision::STATUS_REJECTED], true)) {
                    throw new CreatorSeriesException('لديك نسخة مفتوحة بالفعل لهذه السلسلة.');
                }

                $copiedPath = $this->media->copy($latest->cover_path, $profile);

                $revision = new CreatorSeriesRevision;
                $revision->forceFill([
                    'creator_series_id' => $series->id,
                    'revision_number' => (int) CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->max('revision_number') + 1,
                    'status' => CreatorSeriesRevision::STATUS_DRAFT,
                    'title' => $latest->title,
                    'description' => $latest->description,
                    'cover_path' => $copiedPath,
                ])->save();

                $rows = CreatorSeriesItem::query()->where('creator_series_revision_id', $latest->id)->orderBy('position')->get(['creator_puzzle_id', 'position']);
                $now = now();
                CreatorSeriesItem::query()->insert($rows->map(fn ($r) => [
                    'creator_series_revision_id' => $revision->id,
                    'creator_puzzle_id' => $r->creator_puzzle_id,
                    'position' => $r->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                $series->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($copiedPath, $profile);

            throw $e;
        }
    }

    /** سلسلة لم تُنشر قط تُحذف كاملة (مع أغلفتها)؛ المنشورة لا تُحذف (الأرشفة) لكن مسودة التعديل المفتوحة عليها تُحذف وحدها؛ قيد المراجعة يُسحب أولًا. */
    public function destroy(User $user, string $publicId): void
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);
        $paths = [];

        DB::transaction(function () use ($series, &$paths) {
            $latest = CreatorSeriesRevision::query()->lockForUpdate()->findOrFail($series->latest_revision_id);

            if ($latest->isPendingReview()) {
                throw new CreatorSeriesException('اسحب السلسلة من المراجعة أولًا.');
            }

            if ($series->isPublished()) {
                if (! $latest->isEditable()) {
                    throw new CreatorSeriesException('السلسلة المنشورة لا تُحذف؛ يمكنك أرشفتها.');
                }

                $paths[] = $latest->cover_path;
                $latest->delete();

                $previous = CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->orderByDesc('revision_number')->first();
                $series->forceFill(['latest_revision_id' => $previous?->id])->save();

                return;
            }

            $paths = CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->pluck('cover_path')->all();
            $series->delete();
        });

        foreach ($paths as $path) {
            $this->media->delete($path, $profile);
        }
    }

    public function archive(User $user, string $publicId): CreatorSeries
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        if (! $series->isPublished()) {
            throw new CreatorSeriesException('الأرشفة للسلاسل المنشورة فقط.');
        }

        if (! $series->isArchived()) {
            $series->forceFill(['archived_at' => now()])->save();
        }

        return $series;
    }

    public function unarchive(User $user, string $publicId): CreatorSeries
    {
        $profile = $this->eligibility->studioProfile($user);
        $series = $this->ownedSeries($profile, $publicId);

        if ($series->isArchived()) {
            $series->forceFill(['archived_at' => null])->save();
        }

        return $series;
    }

    // ───────────── التحقق المشترك ─────────────

    /**
     * يحوّل قائمة معرّفات عامة مرتّبة إلى أعمال المبدع نفسه الظاهرة الآن، أو يرمي برسالة عربية.
     * عمل غير موجود ضمن أعمال هذا المبدع (أحجية رسمية/لمبدع آخر/معرّف مختلق) = نفس الرسالة بلا تسريب وجود.
     *
     * @param  array<int, mixed>  $publicIds
     * @return Collection<int, CreatorPuzzle> بترتيب المدخلات
     */
    public function resolveItems(CreatorProfile $profile, array $publicIds): Collection
    {
        $min = (int) config('creator_series.limits.min_items');
        $max = (int) config('creator_series.limits.max_items');

        $ids = array_values(array_filter($publicIds, 'is_string'));

        if (count($ids) !== count($publicIds)) {
            throw new CreatorSeriesException('قائمة الأحجيات غير صالحة.');
        }

        if (count($ids) < $min) {
            throw new CreatorSeriesException("أضف {$min} أحجيات على الأقل إلى السلسلة.");
        }

        if (count($ids) > $max) {
            throw new CreatorSeriesException("الحد الأقصى {$max} أحجية في السلسلة الواحدة.");
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new CreatorSeriesException('لا يمكن تكرار الأحجية نفسها داخل السلسلة.');
        }

        $works = CreatorPuzzle::query()
            ->where('creator_profile_id', $profile->id)->whereIn('public_id', $ids)
            ->get(['id', 'public_id', 'is_visible', 'published_title'])->keyBy('public_id');

        $ordered = new Collection;

        foreach ($ids as $id) {
            $work = $works->get($id);

            if ($work === null) {
                throw new CreatorSeriesException('إحدى الأحجيات المختارة غير موجودة ضمن أعمالك المنشورة.');
            }

            if (! $work->is_visible) {
                throw new CreatorSeriesException('الأحجية «'.$work->published_title.'» غير منشورة أو مخفية حاليًا؛ لا يمكن إدراجها.');
            }

            $ordered->push($work);
        }

        return $ordered;
    }

    /** إعادة تحقق عناصر نسخة محفوظة (لحظة الإرسال والاعتماد): العدد والملكية والظهور الآن. */
    public function assertItemsReady(CreatorProfile $profile, CreatorSeriesRevision $revision): void
    {
        $publicIds = CreatorSeriesItem::query()
            ->join('creator_puzzles as w', 'w.id', '=', 'creator_series_items.creator_puzzle_id')
            ->where('creator_series_items.creator_series_revision_id', $revision->id)
            ->orderBy('creator_series_items.position')
            ->pluck('w.public_id')->all();

        $this->resolveItems($profile, $publicIds);
    }

    protected function replaceItems(CreatorSeriesRevision $revision, Collection $works): void
    {
        CreatorSeriesItem::query()->where('creator_series_revision_id', $revision->id)->delete();

        $now = now();
        $rows = [];

        foreach ($works->values() as $index => $work) {
            $rows[] = [
                'creator_series_revision_id' => $revision->id,
                'creator_puzzle_id' => $work->id,
                'position' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CreatorSeriesItem::query()->insert($rows);
    }

    protected function assertCap(CreatorProfile $profile): void
    {
        $count = CreatorSeries::query()->where('creator_profile_id', $profile->id)->whereNull('archived_at')->count();

        if ($count >= (int) config('creator_series.limits.max_per_creator')) {
            throw new CreatorSeriesException('وصلت للحد الأقصى من السلاسل. احذف مسودة أو أرشف سلسلة قديمة.');
        }
    }

    protected function title(mixed $value): string
    {
        $title = trim((string) $value);
        $min = (int) config('creator_series.limits.title_min');
        $max = (int) config('creator_series.limits.title_max');

        if (mb_strlen($title) < $min || mb_strlen($title) > $max) {
            throw new CreatorSeriesException("عنوان السلسلة من {$min} إلى {$max} حرفًا.");
        }

        return $title;
    }

    protected function description(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > (int) config('creator_series.limits.description_max')) {
            throw new CreatorSeriesException('وصف السلسلة أطول من المسموح.');
        }

        return $text;
    }

    /** رابط ثابت لا يتغير بتغيّر العنوان: أصل من العنوان + لاحقة قصيرة عشوائية من ULID (مضمون الفرادة بالفحص). */
    protected function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'series', 60, '');
        $base = trim($base, '-') ?: 'series';

        do {
            $slug = $base.'-'.strtolower(substr((string) Str::ulid(), -8));
        } while (CreatorSeries::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
`````

### `app/Services/CreatorSeries/CreatorSeriesVisibilityService.php`

`sha256: 81ca39215184504e9db7f74764222d8cb6233ee84caad744b63c8bc99c4bd69d`

`````php
<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E30 - مصدر الحقيقة الوحيد لظهور السلاسل للعامة (البحث/الاكتشاف/الصفحة/ملف المبدع/الإشعارات كلها تمرّ من هنا).
 *
 * السلسلة ظاهرة ⇔ كل ما يلي معًا، **محسوبًا حيًّا** في كل قراءة (لا عمود is_visible مخزَّن ولا مصالحة):
 *   - لها نسخة منشورة (معتمَدة) وعدد عناصر > 0
 *   - غير مخفية إشرافيًا وغير مؤرشفة
 *   - مبدعها معتمَد وحسابه غير مجمَّد
 *   - **كل** عناصر النسخة المنشورة أعمال ظاهرة الآن (is_visible)
 * فإخفاء أحجية واحدة أو تعليق المبدع يخفي السلسلة فورًا في كل مكان، وإعادة الأحجية/رفع التعليق تستعيدها فورًا بلا أي خطوة صيانة.
 * (الصفحة تقرأ من DB دائمًا؛ الكاش لا يكون سلطة ظهور أبدًا.)
 */
class CreatorSeriesVisibilityService
{
    /** شرط الظهور على استعلام CreatorSeries (استعلام فرعي EXISTS/NOT EXISTS مفهرس، بلا ضمّ ولا N+1). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereNotNull('creator_series.published_revision_id')
            ->where('creator_series.published_items_count', '>', 0)
            ->whereNull('creator_series.hidden_at')
            ->whereNull('creator_series.archived_at')
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('creator_profiles')
                    ->whereColumn('creator_profiles.id', 'creator_series.creator_profile_id')
                    ->where('creator_profiles.status', CreatorProfile::STATUS_APPROVED)
                    ->whereExists(function ($u) {
                        $u->selectRaw('1')->from('users')
                            ->whereColumn('users.id', 'creator_profiles.user_id')
                            ->where('users.is_frozen', false);
                    });
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('creator_series_items as csi')
                    ->leftJoin('creator_puzzles as csw', 'csw.id', '=', 'csi.creator_puzzle_id')
                    ->whereColumn('csi.creator_series_revision_id', 'creator_series.published_revision_id')
                    ->where(function ($x) {
                        $x->whereNull('csw.id')->orWhere('csw.is_visible', false);
                    });
            });
    }

    public function visibleQuery(): Builder
    {
        return $this->scopeVisible(CreatorSeries::query());
    }

    public function isVisible(CreatorSeries $series): bool
    {
        return $this->visibleQuery()->whereKey($series->getKey())->exists();
    }

    /**
     * أسباب عدم ظهور السلسلة الآن بالعربية (فارغة = ظاهرة). للمبدع ("لماذا لا تظهر سلسلتي؟") وللمراجع.
     *
     * @return list<string>
     */
    public function blockers(CreatorSeries $series): array
    {
        $reasons = [];

        if ($series->published_revision_id === null) {
            return ['لم تُنشر بعد.'];
        }

        if ($series->hidden_at !== null) {
            $reasons[] = 'أخفاها المراجع.';
        }

        if ($series->archived_at !== null) {
            $reasons[] = 'مؤرشفة.';
        }

        $profile = CreatorProfile::query()->with('user:id,is_frozen')->find($series->creator_profile_id, ['id', 'user_id', 'status']);

        if ($profile === null || ! $profile->isApproved()) {
            $reasons[] = 'صلاحيات المبدع معلَّقة.';
        } elseif ($profile->user === null || (bool) $profile->user->is_frozen) {
            $reasons[] = 'حساب المبدع مقيَّد.';
        }

        $hiddenItems = $this->hiddenItemCount((int) $series->published_revision_id);

        if ($hiddenItems > 0) {
            $reasons[] = "تحتوي {$hiddenItems} أحجية غير ظاهرة حاليًا (مخفية أو مؤرشفة أو غير منشورة).";
        }

        return $reasons;
    }

    /** عناصر نسخة منشورة بحالة ظهور كل عمل (للوحة المراجعة: ظهور كل عنصر على حدة). @return list<array{position:int, work_id:int, public_id:string, title:string, visible:bool}> */
    public function itemStates(int $revisionId): array
    {
        return DB::table('creator_series_items as i')
            ->leftJoin('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.creator_series_revision_id', $revisionId)
            ->orderBy('i.position')
            ->get(['i.position', 'w.id as work_id', 'w.public_id', 'w.published_title', 'w.is_visible'])
            ->map(fn ($r) => [
                'position' => (int) $r->position,
                'work_id' => (int) $r->work_id,
                'public_id' => (string) $r->public_id,
                'title' => (string) $r->published_title,
                'visible' => (bool) $r->is_visible,
            ])->all();
    }

    public function hiddenItemCount(int $revisionId): int
    {
        return (int) DB::table('creator_series_items as i')
            ->leftJoin('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->where('i.creator_series_revision_id', $revisionId)
            ->where(function ($x) {
                $x->whereNull('w.id')->orWhere('w.is_visible', false);
            })->count();
    }
}
`````

### `app/Services/Search/Sources/CreatorSeriesSource.php`

`sha256: a62158eacb2f8fb518cbe68e74be0b0c38f3bc94f235e3f37582c9f2a1b6e439`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\CreatorSeries;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E30 / E27 - سلاسل المبدعين: **نوع بحث مستقل** (creator_series) بوسم «سلسلة من المجتمع». قاعدة الظهور = CreatorSeriesVisibilityService نفسها التي تحكم صفحة السلسلة والاكتشاف
 * (منشورة ∧ غير مخفية/مؤرشفة ∧ مبدع معتمَد بحساب سليم ∧ كل عناصرها ظاهرة الآن)، فالمسودة والمرفوضة والمخفية والمؤرشفة وسلسلة فيها عنصر مخفي أو مبدعها معلَّق لا تُكتشف أبدًا.
 * البحث بعنوان اللقطة المنشورة **فقط** (published_title) بلا ضمّ للنسخ ولا لعناصر ولا لمحتوى الأحجيات. الأعمدة مختارة صراحة وبضمّ واحد لاسم المبدع (لا N+1)؛ الرابط بمعرّف ULID عام.
 */
class CreatorSeriesSource extends EloquentSource
{
    public function __construct(PartialProbe $probe, protected CreatorSeriesVisibilityService $visibility)
    {
        parent::__construct($probe);
    }

    public function type(): string
    {
        return 'creator_series';
    }

    public function label(): string
    {
        return 'سلاسل المجتمع';
    }

    public function icon(): string
    {
        return 'grid';
    }

    protected function table(): string
    {
        return 'creator_series';
    }

    protected function column(): string
    {
        return 'creator_series.published_title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return $this->visibility->visibleQuery()
            ->join('creator_profiles as scp', 'scp.id', '=', 'creator_series.creator_profile_id')
            ->join('users as scu', 'scu.id', '=', 'scp.user_id')
            ->select('creator_series.id', 'creator_series.public_id', 'creator_series.published_title', 'creator_series.published_items_count', 'scu.name as creator_name');
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var CreatorSeries $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->published_title,
            url: route('series.show', $model->public_id),
            subtitle: (int) $model->published_items_count.' أحجية · بقلم '.$model->getAttribute('creator_name'),
            badge: 'سلسلة من المجتمع',
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `app/Services/Sponsorship/ContributionService.php`

`sha256: c2da8a92441ee56d684b6e501fe7aea7231f19a9762a4facbc3bb196f8299665`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E30 - دورة حياة المساهمة. **لا بوابة دفع**: تُسجَّل معلّقة ثم يؤكّدها مدير مخوَّل فقط (sponsorships.confirm، منفصلة عن إدارة الملفات/البرامج).
 *
 * - المبلغ الأصلي + عملته (قائمة بيضاء) أعداد صحيحة بالوحدة الصغرى. عند التأكيد يُسجَّل المعادل المحاسبي: عملة المحاسبة نفسها ⇒ نفس المبلغ؛ عملة أخرى ⇒
 *   يدخله المدير (لا سعر صرف حيّ) ويُدقَّق قرار التحويل. بعد التأكيد تُحمى الحقول المالية بنيويًا (SponsorshipContribution): التصحيح = استرداد ثم مساهمة جديدة.
 * - التأكيد/الإلغاء/الاسترداد بقفل الصف + تحقق الحالة داخل المعاملة (تأكيدان متزامنان ⇒ واحد ينجح). الاسترداد لا يسحب مكافآت لاعبين مُنحت سابقًا (لقطات تاريخية)،
 *   لكنه يخفّض التمويل/المستوى/الصدارة فورًا ويُسقط دور الراعي إن لم يبقَ للداعم مساهمة مؤكَّدة بالبرنامج.
 * - الإشعارات والكاش بعد الـcommit؛ فشلها لا يُرجع شيئًا. قراءات الكاش لا تُستعمل هنا إطلاقًا (القرارات من قاعدة البيانات).
 */
class ContributionService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected SupportTotals $totals,
        protected SupporterTierService $tiers,
        protected SponsorshipNotifier $notifier,
    ) {}

    /**
     * @param  array{reference?: ?string, notes?: ?string, is_anonymous?: ?bool}  $options
     *
     * @throws SponsorshipException
     */
    public function record(User $actor, SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount, string $currency, array $options = []): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        $currency = strtoupper(trim($currency));
        $minor = SponsorshipMoney::parse($amount, $currency);
        $extra = $this->cleanText($options);

        if (! in_array($program->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
            throw new SponsorshipException('لا تُسجَّل مساهمات على برنامج مغلق أو ملغى.');
        }

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف.');
        }

        $contribution = DB::transaction(function () use ($actor, $supporter, $program, $minor, $currency, $options, $extra) {
            $contribution = new SponsorshipContribution;
            $contribution->forceFill([
                'supporter_profile_id' => $supporter->id,
                'sponsorship_program_id' => $program->id,
                'original_amount_minor' => $minor,
                'currency' => $currency,
                'status' => SponsorshipContribution::STATUS_PENDING,
                'is_anonymous' => array_key_exists('is_anonymous', $options) && $options['is_anonymous'] !== null ? (bool) $options['is_anonymous'] : (bool) $supporter->anonymous_by_default,
                'reference' => $extra['reference'],
                'notes' => $extra['notes'],
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('sponsorship_contribution_recorded', $contribution, [
                'public_id' => $contribution->public_id, 'supporter' => $supporter->public_id, 'program' => $program->slug,
                'original_amount_minor' => $minor, 'currency' => $currency,
            ], $actor);

            return $contribution;
        });

        SponsorshipCache::bust();

        return $contribution;
    }

    /** تصحيح مساهمة **معلّقة** فقط (خطأ إدخال). تغيير البرنامج/المبلغ/العملة يُدقَّق بإجراء مستقل. @throws SponsorshipException */
    public function updatePending(User $actor, SponsorshipContribution $contribution, array $data): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        return DB::transaction(function () use ($actor, $contribution, $data) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('لا يُعدَّل إلا المعلّق؛ صحّح المؤكَّد بالاسترداد.');
            }

            $changes = [];

            if (isset($data['amount']) || isset($data['currency'])) {
                $currency = strtoupper(trim((string) ($data['currency'] ?? $locked->currency)));
                $minor = isset($data['amount']) ? SponsorshipMoney::parse($data['amount'], $currency) : SponsorshipMoney::assertMinor((int) $locked->original_amount_minor);

                if ($minor !== (int) $locked->original_amount_minor || $currency !== $locked->currency) {
                    $changes['original_amount_minor'] = $minor;
                    $changes['currency'] = $currency;
                }
            }

            if (isset($data['sponsorship_program_id']) && (int) $data['sponsorship_program_id'] !== (int) $locked->sponsorship_program_id) {
                $target = SponsorshipProgram::query()->findOrFail((int) $data['sponsorship_program_id']);

                if (! in_array($target->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
                    throw new SponsorshipException('لا يمكن النقل إلى برنامج مغلق أو ملغى.');
                }

                $changes['sponsorship_program_id'] = $target->id;
            }

            $text = $this->cleanText($data);

            foreach (['reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $text[$field];
                }
            }

            if (array_key_exists('is_anonymous', $data) && $data['is_anonymous'] !== null) {
                $changes['is_anonymous'] = (bool) $data['is_anonymous'];
            }

            if ($changes === []) {
                return $locked;
            }

            $before = $locked->only(array_keys($changes));
            $locked->forceFill($changes)->save();

            $this->audit->log('sponsorship_contribution_updated', $locked, ['changed' => array_keys($changes)], $actor);

            if (isset($changes['sponsorship_program_id'])) {
                $this->audit->log('sponsorship_contribution_program_changed', $locked, ['from' => $before['sponsorship_program_id'] ?? null, 'to' => $changes['sponsorship_program_id']], $actor);
            }

            return $locked;
        });
    }

    /**
     * يؤكّد مساهمة معلّقة: يسجّل المعادل المحاسبي ويُدقَّق. فقط sponsorships.confirm.
     *
     * @throws SponsorshipException
     */
    public function confirm(User $actor, SponsorshipContribution $contribution, string|int|null $accountingAmount = null): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);

        $accounting = SponsorshipMoney::accountingCurrency();
        $before = null;
        $supporterId = 0;

        $confirmed = DB::transaction(function () use ($actor, $contribution, $accountingAmount, $accounting, &$before, &$supporterId) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('هذه المساهمة ليست معلّقة (ربما عولجت للتو).');
            }

            $supporter = SupporterProfile::query()->findOrFail($locked->supporter_profile_id);
            $program = SponsorshipProgram::query()->findOrFail($locked->sponsorship_program_id);

            if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
                throw new SponsorshipException('ملف الداعم موقوف؛ لا يمكن تأكيد مساهمته.');
            }

            if (! in_array($program->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
                throw new SponsorshipException('البرنامج مغلق أو ملغى؛ لا يمكن تأكيد مساهمة عليه.');
            }

            if (SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('accounting_currency', '!=', $accounting)->exists()) {
                throw new SponsorshipException('عملة المحاسبة تغيّرت بعد وجود مساهمات مؤكَّدة بعملة أخرى؛ لا يمكن خلط العملات (راجع التوثيق).');
            }

            if ($locked->currency === $accounting) {
                $accountingMinor = (int) $locked->original_amount_minor;

                if ($accountingAmount !== null && $accountingAmount !== '' && SponsorshipMoney::parse($accountingAmount, $accounting) !== $accountingMinor) {
                    throw new SponsorshipException('بعملة المحاسبة نفسها يجب أن يساوي المعادل المبلغ الأصلي.');
                }
            } else {
                if ($accountingAmount === null || $accountingAmount === '') {
                    throw new SponsorshipException('أدخل المعادل بعملة المحاسبة ('.$accounting.') لأن عملة المساهمة مختلفة.');
                }

                $accountingMinor = SponsorshipMoney::parse($accountingAmount, $accounting);
            }

            $supporterId = (int) $supporter->id;
            $before = $this->tiers->forSupporter($supporter);

            $locked->forceFill([
                'accounting_amount_minor' => $accountingMinor,
                'accounting_currency' => $accounting,
                'status' => SponsorshipContribution::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $actor->id,
            ])->save();

            $this->audit->log('sponsorship_contribution_confirmed', $locked, [
                'public_id' => $locked->public_id, 'supporter' => $supporter->public_id, 'program' => $program->slug,
                'original_amount_minor' => (int) $locked->original_amount_minor, 'currency' => $locked->currency,
                'accounting_amount_minor' => $accountingMinor, 'accounting_currency' => $accounting,
            ], $actor);

            if ($locked->currency !== $accounting) {
                $this->audit->log('sponsorship_contribution_normalized', $locked, [
                    'from_currency' => $locked->currency, 'from_minor' => (int) $locked->original_amount_minor,
                    'to_currency' => $accounting, 'to_minor' => $accountingMinor,
                ], $actor);
            }

            return $locked;
        });

        SponsorshipCache::bust();
        $this->afterChange($confirmed, $supporterId, $before, true);

        return $confirmed;
    }

    /** @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipContribution $contribution, string $reason): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);
        $reason = $this->requireReason($reason);

        $cancelled = DB::transaction(function () use ($actor, $contribution, $reason) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('يُلغى المعلّق فقط؛ المؤكَّد يُسترد.');
            }

            $locked->forceFill(['status' => SponsorshipContribution::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'status_note' => $reason])->save();
            $this->audit->log('sponsorship_contribution_cancelled', $locked, ['public_id' => $locked->public_id, 'reason' => $reason], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $cancelled;
    }

    /** @throws SponsorshipException */
    public function refund(User $actor, SponsorshipContribution $contribution, string $reason): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);
        $reason = $this->requireReason($reason);
        $before = null;
        $supporterId = 0;

        $refunded = DB::transaction(function () use ($actor, $contribution, $reason, &$before, &$supporterId) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isConfirmed()) {
                throw new SponsorshipException('يُسترد المؤكَّد فقط.');
            }

            $supporter = SupporterProfile::query()->findOrFail($locked->supporter_profile_id);
            $supporterId = (int) $supporter->id;
            $before = $this->tiers->forSupporter($supporter);

            $locked->forceFill(['status' => SponsorshipContribution::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor->id, 'status_note' => $reason])->save();
            $this->audit->log('sponsorship_contribution_refunded', $locked, [
                'public_id' => $locked->public_id, 'reason' => $reason, 'accounting_amount_minor' => (int) $locked->accounting_amount_minor,
            ], $actor);

            // لا مساهمة مؤكَّدة متبقية للداعم بهذا البرنامج ⇒ يسقط دوره (راعٍ/راعٍ رئيسي) ويُدقَّق.
            if (! $this->totals->hasConfirmed($supporterId, (int) $locked->sponsorship_program_id)) {
                $roles = SponsorshipProgramRole::query()->where('sponsorship_program_id', $locked->sponsorship_program_id)->where('supporter_profile_id', $supporterId)->get();

                foreach ($roles as $role) {
                    $this->audit->log($role->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? 'main_sponsor_removed' : 'sponsorship_role_removed', $locked->program, ['supporter' => $supporter->public_id, 'reason' => 'refund'], $actor);
                    $role->delete();
                }
            }

            return $locked;
        });

        SponsorshipCache::bust();
        $this->afterChange($refunded, $supporterId, $before, false);

        return $refunded;
    }

    /** يبدّل إخفاء الهوية لمساهمة (قرار خصوصية الداعم) دون المساس بالمبالغ. مدقَّق. */
    public function setAnonymity(User $actor, SponsorshipContribution $contribution, bool $anonymous): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        if ((bool) $contribution->is_anonymous !== $anonymous) {
            $contribution->forceFill(['is_anonymous' => $anonymous])->save();
            $this->audit->log('sponsorship_contribution_anonymity_changed', $contribution, ['public_id' => $contribution->public_id, 'anonymous' => $anonymous], $actor);
            SponsorshipCache::bust();
        }

        return $contribution;
    }

    /** إشعارات ما بعد الـcommit: تأكيد الدعم (للتأكيد فقط) وتغيّر المستوى. فشلها لا يمسّ المعاملة. */
    protected function afterChange(SponsorshipContribution $contribution, int $supporterId, mixed $tierBefore, bool $confirmed): void
    {
        try {
            $supporter = SupporterProfile::query()->with('user')->find($supporterId);

            if ($supporter === null) {
                return;
            }

            $contribution->setRelation('supporter', $supporter);

            if ($confirmed) {
                $this->notifier->supportConfirmed($contribution);
            }

            $this->notifier->tierChanged($supporter, $tierBefore, $this->tiers->forSupporter($supporter), (int) $contribution->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new SponsorshipException('اكتب سببًا واضحًا (3–255 حرفًا).');
        }

        if (Validator::make(['r' => $reason], ['r' => [new NoMarkup]])->fails()) {
            throw new SponsorshipException('لا يُسمح بالوسوم أو الأكواد في السبب.');
        }

        return $reason;
    }

    /** @return array{reference: ?string, notes: ?string} */
    protected function cleanText(array $data): array
    {
        $max = (array) config('sponsorship.profile');
        $validator = Validator::make($data, [
            'reference' => ['nullable', 'string', 'max:'.$max['reference_max'], new NoMarkup],
            'notes' => ['nullable', 'string', 'max:'.$max['notes_max'], new NoMarkup],
        ]);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        return [
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }
}
`````

### `app/Services/Sponsorship/EventRewardPlanService.php`

`sha256: f84832411ffa25d3813b8e0e0da680b05a495b2d07ed812b4489eab24af51ae3`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\CompetitiveEvent;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E30 - قفل خطة تعزيز منافسة **قبل بدئها** (مبدأ CompetitiveEvent::rewardsEditable()): يُسجَّل تمويل البرنامج ونسبته لحظة القفل، وتقرأ الجوائز النسبة المقفلة لا التمويل الحيّ.
 * مساهمة بعد القفل (أو استرداد) لا تغيّر جوائز المنافسة. منافسة بلا خطة مقفلة وقت التوزيع ⇒ بلا تعزيز (فشل مغلق). القفل نهائي (لا تعديل ولا إعادة قفل).
 * الأهداف: برامج هدفها هذه المنافسة نفسها حصرًا (برنامج المنصة/الموسم/الحملة لا يعزّز المنافسات). يقفل يدويًا مدير بصلاحية reward_plan أو آليًا الأمر المجدول.
 */
class EventRewardPlanService
{
    public function __construct(protected OperationalAuditService $audit, protected SupportTotals $totals) {}

    public function planFor(CompetitiveEvent $event): ?SponsorshipEventRewardPlan
    {
        return SponsorshipEventRewardPlan::query()->where('competitive_event_id', $event->getKey())->first();
    }

    /**
     * @throws SponsorshipException
     */
    public function lock(CompetitiveEvent $event, ?User $actor = null): SponsorshipEventRewardPlan
    {
        if ($actor !== null) {
            abort_unless($actor->can('sponsorships.reward_plan'), 403);
        }

        $plan = DB::transaction(function () use ($event, $actor) {
            $locked = CompetitiveEvent::query()->lockForUpdate()->findOrFail($event->getKey());

            if (($existing = $this->planFor($locked)) !== null) {
                return $existing;
            }

            if (! $locked->rewardsEditable()) {
                throw new SponsorshipException('بدأت المنافسة أو انتهت؛ لا تُقفل خطة تعزيزها الآن (لا تعزيز لها).');
            }

            $programs = SponsorshipProgram::query()
                ->where('target_type', SponsorshipProgram::TARGET_COMPETITIVE_EVENT)->where('target_id', $locked->getKey())
                ->where('status', SponsorshipProgram::STATUS_ACTIVE)->where('reward_boost_enabled', true)->where('funding_step_minor', '>', 0)
                ->orderBy('id')->get();

            $funding = $this->totals->programFundingMany($programs->pluck('id')->all());
            $best = null;
            $bestBps = 0;

            foreach ($programs as $program) {
                $bps = SponsorshipRewardService::computeBps($funding[$program->id] ?? 0, (int) $program->funding_step_minor, (int) $program->boost_per_step_bps, (int) $program->max_boost_bps);

                if ($bps > $bestBps) {
                    $best = $program;
                    $bestBps = $bps;
                }
            }

            $plan = new SponsorshipEventRewardPlan;
            $plan->forceFill([
                'competitive_event_id' => $locked->getKey(),
                'sponsorship_program_id' => $best?->id,
                'funding_snapshot_minor' => $best !== null ? (int) ($funding[$best->id] ?? 0) : 0,
                'funding_step_minor' => $best !== null ? (int) $best->funding_step_minor : 0,
                'boost_bps' => $bestBps,
                'locked_at' => now(),
                'locked_by' => $actor?->id,
            ])->save();

            foreach ($programs as $program) {
                $program->forceFill(['reward_plan_locked_at' => now(), 'reward_plan_locked_by' => $actor?->id])->save();
                $this->audit->log('sponsorship_reward_plan_locked', $program, ['event' => $locked->slug], $actor);
            }

            $this->audit->log('sponsorship_event_reward_plan_locked', $locked, [
                'program' => $best?->slug, 'boost_bps' => $bestBps, 'funding_snapshot_minor' => (int) $plan->funding_snapshot_minor,
            ], $actor);

            return $plan;
        });

        SponsorshipCache::bust();

        return $plan;
    }

    /**
     * الأمر المجدول: يقفل خطط المنافسات التي تبدأ خلال المدة المضبوطة (أو لا تزال قابلة للتعديل) ولها برنامج تعزيز نشط ولا خطة لها. يعيد العدد.
     */
    public function lockUpcoming(): int
    {
        $lead = max(1, (int) config('sponsorship.events.lock_lead_minutes', 60));
        $count = 0;

        CompetitiveEvent::query()
            ->whereIn('id', fn ($q) => $q->select('target_id')->from('sponsorship_programs')
                ->where('target_type', SponsorshipProgram::TARGET_COMPETITIVE_EVENT)->where('status', SponsorshipProgram::STATUS_ACTIVE)->where('reward_boost_enabled', true))
            ->whereNotIn('id', fn ($q) => $q->select('competitive_event_id')->from('sponsorship_event_reward_plans'))
            ->where('status', CompetitiveEvent::STATUS_PUBLISHED)->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes($lead))
            ->orderBy('id')->each(function (CompetitiveEvent $event) use (&$count) {
                try {
                    $this->lock($event);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
`````

### `app/Services/Sponsorship/SponsorDisplay.php`

`sha256: 3a38720f4ef15cefa51b040f106912267fe9beadf7d2815c953f31ec88124f82`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;

/**
 * E30 - عرض الرعاة على صفحات الموسم/الحملة/المنافسة (و«المنصة»): بيانات **منظَّمة** (اسم، شعار، رابط آمن، نوع) لا HTML حرًّا، ولا علاقة لها بمحرّك إعلانات E14.
 *
 * يظهر راعٍ فقط إذا: دوره sponsor/main_sponsor ∧ برنامجه active/closed ∧ له مساهمة مؤكَّدة **غير مجهولة** بالبرنامج (راعٍ كل مساهماته مجهولة لا يُنسب علنًا) ∧ ملفه علني الآن.
 * الكاش يحمل معرّفات وأدوارًا فقط؛ العلنية تُفحص حيًّا. أي فشل هنا يُلتقط ويُعاد «لا رعاة» (الصفحات الرسمية لا تتعطل بالرعاية).
 */
class SponsorDisplay
{
    public function __construct(protected SupporterLogoService $logos) {}

    /**
     * @return array{main: ?array<string, mixed>, supporting: list<array<string, mixed>>, any: bool}
     */
    public function forTarget(string $type, ?int $id = null): array
    {
        $empty = ['main' => null, 'supporting' => [], 'any' => false];

        if (! in_array($type, SponsorshipProgram::TARGETS, true) || ! (bool) config('sponsorship.enabled', true)) {
            return $empty;
        }

        try {
            $limit = 1 + (int) config('sponsorship.public.supporting_sponsors_limit', 8);
            $roles = SponsorshipCache::remember('display', [$type, $id ?? 0], (int) config('sponsorship.public.display_ttl', 60), fn () => $this->roles($type, $id, $limit));

            if ($roles === []) {
                return $empty;
            }

            $profiles = SupporterProfile::query()->public()->whereIn('id', array_column($roles, 'sid'))
                ->get(['id', 'type', 'display_name', 'slug', 'logo_path', 'website_url'])->keyBy('id');

            $main = null;
            $supporting = [];

            foreach ($roles as $row) {
                $profile = $profiles->get($row['sid']);

                if ($profile === null) {
                    continue;
                }

                $card = $this->card($profile, $row['role'] === SponsorshipProgramRole::ROLE_MAIN_SPONSOR);

                if ($card['is_main'] && $main === null) {
                    $main = $card;
                } elseif (count($supporting) < $limit - 1) {
                    $supporting[] = $card;
                }
            }

            return ['main' => $main, 'supporting' => $supporting, 'any' => $main !== null || $supporting !== []];
        } catch (\Throwable $e) {
            report($e);

            return $empty;
        }
    }

    /** @return list<array{sid: int, role: string}> */
    protected function roles(string $type, ?int $id, int $limit): array
    {
        return DB::table('sponsorship_program_roles as r')
            ->join('sponsorship_programs as p', 'p.id', '=', 'r.sponsorship_program_id')
            ->where('p.target_type', $type)
            ->when($type !== SponsorshipProgram::TARGET_PLATFORM, fn ($q) => $q->where('p.target_id', $id))
            ->whereIn('p.status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
            ->whereIn('r.role', [SponsorshipProgramRole::ROLE_MAIN_SPONSOR, SponsorshipProgramRole::ROLE_SPONSOR])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sponsorship_contributions as c')
                ->whereColumn('c.supporter_profile_id', 'r.supporter_profile_id')->whereColumn('c.sponsorship_program_id', 'r.sponsorship_program_id')
                ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('c.is_anonymous', false))
            ->orderByRaw("case when r.role = 'main_sponsor' then 0 else 1 end")->orderBy('r.id')
            ->limit($limit)->get(['r.supporter_profile_id as sid', 'r.role'])
            ->map(fn ($r) => ['sid' => (int) $r->sid, 'role' => (string) $r->role])->all();
    }

    /** @return array<string, mixed> */
    public function card(SupporterProfile $profile, bool $main = false): array
    {
        return [
            'name' => $profile->display_name,
            'slug' => $profile->slug,
            'type' => $profile->type,
            'logo_url' => $this->logos->url($profile->logo_path),
            'website' => SafeUrl::normalize($profile->website_url),
            'is_main' => $main,
        ];
    }
}
`````

### `app/Services/Sponsorship/SponsorshipAnalytics.php`

`sha256: a7db495a85129e0ff799a968bd44be5bb76210731267e8a2b07e198aba6fef56`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use Illuminate\Support\Facades\DB;

/**
 * E30 - تحليلات الرعاية الإدارية: أرقام مجمَّعة بتجميعات قاعدة بيانات (لا تحميل صفوف)، مخزَّنة مدة قصيرة مع إبطال تلقائي بأي تغيير مالي (SponsorshipCache::bust).
 * الكاش للعرض فقط (ليس سلطة): التمويل/المستويات/التعزيز تقرأ دائمًا من قاعدة البيانات. لا هويات لاعبين هنا ولا بيانات دفع.
 */
class SponsorshipAnalytics
{
    /**
     * @return array{confirmed_minor: int, refunded_minor: int, confirmed_count: int, pending_count: int, refunded_count: int, cancelled_count: int, supporters: int, active_programs: int, boost_programs: int, locked_plans: int, bonus_total: int, bonus_count: int, top_program: ?array{title: string, funding_minor: int}, currency: string}
     */
    public function summary(): array
    {
        return SponsorshipCache::remember('analytics', [], (int) config('sponsorship.analytics.cache_ttl', 60), function () {
            $by = DB::table('sponsorship_contributions')->groupBy('status')
                ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(accounting_amount_minor), 0) as total')->get()->keyBy('status');

            $top = DB::table('sponsorship_contributions as c')->join('sponsorship_programs as p', 'p.id', '=', 'c.sponsorship_program_id')
                ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->groupBy('p.id', 'p.title')
                ->selectRaw('p.title as title, SUM(c.accounting_amount_minor) as funding')->orderByDesc('funding')->orderBy('p.id')->first();

            $bonus = DB::table('sponsorship_reward_bonuses')->selectRaw('COALESCE(SUM(bonus_amount), 0) as total, COUNT(*) as n')->first();

            return [
                'confirmed_minor' => (int) ($by->get(SponsorshipContribution::STATUS_CONFIRMED)->total ?? 0),
                'refunded_minor' => (int) ($by->get(SponsorshipContribution::STATUS_REFUNDED)->total ?? 0),
                'confirmed_count' => (int) ($by->get(SponsorshipContribution::STATUS_CONFIRMED)->n ?? 0),
                'pending_count' => (int) ($by->get(SponsorshipContribution::STATUS_PENDING)->n ?? 0),
                'refunded_count' => (int) ($by->get(SponsorshipContribution::STATUS_REFUNDED)->n ?? 0),
                'cancelled_count' => (int) ($by->get(SponsorshipContribution::STATUS_CANCELLED)->n ?? 0),
                'supporters' => SupporterProfile::query()->where('status', SupporterProfile::STATUS_ACTIVE)->count(),
                'active_programs' => SponsorshipProgram::query()->where('status', SponsorshipProgram::STATUS_ACTIVE)->count(),
                'boost_programs' => SponsorshipProgram::query()->where('status', SponsorshipProgram::STATUS_ACTIVE)->where('reward_boost_enabled', true)->count(),
                'locked_plans' => (int) DB::table('sponsorship_event_reward_plans')->count(),
                'bonus_total' => (int) ($bonus->total ?? 0),
                'bonus_count' => (int) ($bonus->n ?? 0),
                'top_program' => $top ? ['title' => (string) $top->title, 'funding_minor' => (int) $top->funding] : null,
                'currency' => SponsorshipMoney::accountingCurrency(),
            ];
        });
    }
}
`````

### `app/Services/Sponsorship/SponsorshipCache.php`

`sha256: bcf92c6cbe4affaff4e6303ad59adc193f86cda843e2eea8f7a6a16a13e0bf5b`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * E30 - كاش العرض العام للرعاية (لوحات الصدارة، شرائح الرعاة، المستويات). **ليس سلطة** أبدًا: لا يُقرأ لتأكيد/استرداد/منح مكافأة (تلك من قاعدة البيانات حصرًا).
 * ما يُخزَّن معرّفات ومجاميع فقط (لا أسماء ولا روابط)، والظهور العام يُعاد فحصه حيًّا بكل عرض. أي تغيير مالي/ظهور يرفع «إصدار» الكاش فتتبدّل المفاتيح فورًا.
 * فشل الكاش = حساب مباشر (CachedSnapshot fail-open). TTL محدود دائمًا.
 */
final class SponsorshipCache
{
    private const VERSION_KEY = 'sponsorship:version';

    public static function version(): string
    {
        try {
            return (string) Cache::get(self::VERSION_KEY, '1');
        } catch (\Throwable) {
            return '0';
        }
    }

    /** يبطل كل لقطات الرعاية فورًا (يُستدعى بعد أي تأكيد/إلغاء/استرداد/تغيير برنامج أو دور أو ملف أو مستوى). */
    public static function bust(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, Str::lower(Str::random(10)));
        } catch (\Throwable) {
            // الكاش غير متاح: لا لقطات أصلًا
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $compute
     * @return T
     */
    public static function remember(string $name, array $parts, ?int $ttl, \Closure $compute): mixed
    {
        $key = 'sponsorship:v'.self::version().':'.$name.':'.implode(':', array_map('strval', $parts));

        return CachedSnapshot::remember($key, $ttl ?? (int) config('sponsorship.public.cache_ttl', 120), $compute);
    }
}
`````

### `app/Services/Sponsorship/SponsorshipException.php`

`sha256: c07fa15e80d6fcf2bc2726f529f59104467b0c5006375a0cfaf0e37452133013`

`````php
<?php

namespace App\Services\Sponsorship;

/** E30 - خطأ أعمال مفهوم للمستخدم/المدير (رسالة عربية آمنة للعرض). لا يحمل بيانات خاصة. */
class SponsorshipException extends \RuntimeException {}
`````

### `app/Services/Sponsorship/SponsorshipMoney.php`

`sha256: 40984b877093f7863c88266d8d0e7489444f7b8f8b1eb34caeeee30ab51a8ff7`

`````php
<?php

namespace App\Services\Sponsorship;

/**
 * E30 - المبالغ كأعداد صحيحة بالوحدة الصغرى (cents/kuruş). لا floats في أي حساب: التحليل نصّي (أرقام + فاصلة عشرية اختيارية) والتنسيق بقسمة صحيحة.
 * العملات من قائمة بيضاء بالإعداد (config/sponsorship.php). لا سعر صرف حيّ: المعادل المحاسبي يدخله المدير عند التأكيد.
 */
final class SponsorshipMoney
{
    public static function accountingCurrency(): string
    {
        $code = strtoupper((string) config('sponsorship.accounting_currency', 'USD'));

        return self::isAllowed($code) ? $code : 'USD';
    }

    /** @return list<string> */
    public static function currencies(): array
    {
        return array_map('strval', array_keys((array) config('sponsorship.currencies', [])));
    }

    /** @return array<string, string> رمز العملة ← تسمية عربية للقوائم. */
    public static function options(): array
    {
        $out = [];

        foreach ((array) config('sponsorship.currencies', []) as $code => $meta) {
            $out[(string) $code] = $code.' — '.($meta['label'] ?? $code);
        }

        return $out;
    }

    public static function isAllowed(?string $currency): bool
    {
        return is_string($currency) && $currency !== '' && array_key_exists($currency, (array) config('sponsorship.currencies', []));
    }

    public static function exponent(string $currency): int
    {
        return max(0, min(4, (int) config("sponsorship.currencies.{$currency}.exponent", 2)));
    }

    public static function symbol(string $currency): string
    {
        return (string) config("sponsorship.currencies.{$currency}.symbol", $currency);
    }

    /**
     * يحوّل مُدخَلًا نصيًا (مثل "1250" أو "12.50" أو بأرقام عربية) إلى وحدة صغرى صحيحة موجبة. يرمي SponsorshipException لأي مُدخَل غير سليم.
     *
     * @throws SponsorshipException
     */
    public static function parse(string|int $amount, string $currency): int
    {
        if (! self::isAllowed($currency)) {
            throw new SponsorshipException('عملة غير مدعومة.');
        }

        $exp = self::exponent($currency);
        $text = strtr(trim((string) $amount), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '', ',' => '', ' ' => '']);

        if (preg_match('/^(\d{1,13})(?:\.(\d{1,'.max(1, $exp).'}))?$/', $text, $m) !== 1 || ($exp === 0 && isset($m[2]))) {
            throw new SponsorshipException('المبلغ غير صالح؛ اكتب رقمًا موجبًا'.($exp > 0 ? " بحد أقصى {$exp} خانة عشرية" : ' صحيحًا').'.');
        }

        $minor = ((int) $m[1]) * (10 ** $exp) + ($exp > 0 && isset($m[2]) ? (int) str_pad($m[2], $exp, '0') : 0);

        return self::assertMinor($minor);
    }

    /** @throws SponsorshipException */
    public static function assertMinor(int $minor): int
    {
        if ($minor <= 0) {
            throw new SponsorshipException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        if ($minor > (int) config('sponsorship.max_amount_minor', 1_000_000_000)) {
            throw new SponsorshipException('المبلغ يتجاوز الحدّ الأقصى المسموح.');
        }

        return $minor;
    }

    /** تنسيق للعرض بقسمة صحيحة (لا floats): "$1,250.00". */
    public static function format(int $minor, ?string $currency = null): string
    {
        $currency ??= self::accountingCurrency();
        $exp = self::exponent($currency);
        $div = 10 ** $exp;
        $whole = intdiv(abs($minor), $div);
        $fraction = $exp > 0 ? '.'.str_pad((string) (abs($minor) % $div), $exp, '0', STR_PAD_LEFT) : '';

        return ($minor < 0 ? '-' : '').self::symbol($currency).number_format($whole).$fraction;
    }
}
`````

### `app/Services/Sponsorship/SponsorshipNotifier.php`

`sha256: 7567c048696205bb80bbe89373980cb28cef3ce52ebfb9e0691f60b5d1d29c1c`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E30 - إشعارات الرعاية (E15). تُستدعى **بعد** نجاح التغيير المالي (بعد الـcommit) وفشلها لا يُرجع أي حالة (try/catch + report). مفاتيح idempotency دلالية.
 * للداعم المرتبط بحساب فقط؛ **لا مبالغ ولا مراجع ولا ملاحظات خاصة** بنصوص الإشعارات أو معاملاتها أبدًا.
 */
class SponsorshipNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function supportConfirmed(SponsorshipContribution $contribution): void
    {
        $this->send($contribution->supporter, NotificationType::SupportConfirmed, [], 'support_confirmed:'.$contribution->id, $this->actionParams($contribution->supporter));
    }

    public function tierChanged(SupporterProfile $supporter, ?SupporterTier $from, ?SupporterTier $to, int $contributionId): void
    {
        if (($from?->id) === ($to?->id)) {
            return;
        }

        $up = $to !== null && ($from === null || (int) $to->minimum_accounting_amount_minor > (int) $from->minimum_accounting_amount_minor);

        $this->send($supporter, NotificationType::SupporterTierChanged, ['tier' => $to?->name, 'up' => $up], 'supporter_tier:'.$supporter->id.':'.($to?->id ?? 0).':'.$contributionId, $this->actionParams($supporter));
    }

    public function programStarted(SupporterProfile $supporter, SponsorshipProgram $program): void
    {
        $this->send($supporter, NotificationType::SponsorshipProgramStarted, ['title' => $program->title], 'sponsorship_program_started:'.$program->id.':'.$supporter->id);
    }

    /** @return array<string, string> */
    protected function actionParams(SupporterProfile $supporter): array
    {
        return $supporter->isPublic() ? ['supporter' => (string) $supporter->slug] : [];
    }

    protected function send(SupporterProfile $supporter, NotificationType $type, array $params, string $key, array $actionParams = []): void
    {
        if (! (bool) config('sponsorship.notifications.enabled', true)) {
            return;
        }

        try {
            $user = $supporter->user;

            if ($user === null || $user->is_frozen) {
                return;
            }

            $route = $actionParams !== [] ? 'supporters.show' : null;
            $this->dispatcher->dispatch($user, $type, $params, $key, ['supporter_profile_id' => $supporter->id], $actionParams, $route);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Services/Sponsorship/SponsorshipProgramService.php`

`sha256: 126f325d8240d32a9b5c6915fccd391eb114d36013c115e354f6c239235773a4`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * E30 - برامج الرعاية. كل كتابة هنا بصلاحية وتدقيق، بلا تعيين جماعي:
 *  - إنشاء/تعديل البرنامج وأدواره: sponsorships.manage. خطة تعزيز المكافآت: sponsorships.reward_plan (صلاحية منفصلة) وتُقفل بقفل خطة المنافسة.
 *  - الهدف من قائمة بيضاء رسمية (SponsorshipTargets) ويتحقق من وجوده؛ لا يتغير الهدف بعد أن صارت للبرنامج مساهمات أو خرج من المسودة.
 *  - راعٍ رئيسي واحد لكل برنامج (قيد قاعدة بيانات) ويشترط مساهمة مؤكَّدة منه بهذا البرنامج؛ الراعي الرئيسي ≠ أعلى داعم (تعيين إداري صريح فقط).
 */
class SponsorshipProgramService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected SponsorshipTargets $targets,
        protected SupportTotals $totals,
        protected SponsorshipNotifier $notifier,
    ) {}

    /**
     * @param  array{title: string, description?: ?string, target_type: string, target_id?: ?int, starts_at?: mixed, ends_at?: mixed, goal_amount?: string|int|null, show_public_amounts?: bool, show_goal_progress?: bool}  $data
     *
     * @throws SponsorshipException
     */
    public function create(User $actor, array $data): SponsorshipProgram
    {
        $this->authorize($actor);
        $clean = $this->clean($data);
        $this->targets->validate($data['target_type'] ?? '', isset($data['target_id']) ? (int) $data['target_id'] : null);

        $program = DB::transaction(function () use ($actor, $data, $clean) {
            $program = new SponsorshipProgram;
            $program->forceFill($clean + [
                'target_type' => $data['target_type'],
                'target_id' => $data['target_type'] === SponsorshipProgram::TARGET_PLATFORM ? null : (int) $data['target_id'],
                'slug' => $this->uniqueSlug((string) $data['title']),
                'status' => SponsorshipProgram::STATUS_DRAFT,
                'accounting_currency' => SponsorshipMoney::accountingCurrency(),
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('sponsorship_program_created', $program, ['slug' => $program->slug, 'target' => $program->target_type, 'target_id' => $program->target_id], $actor);

            return $program;
        });

        SponsorshipCache::bust();

        return $program;
    }

    /** @throws SponsorshipException */
    public function update(User $actor, SponsorshipProgram $program, array $data): SponsorshipProgram
    {
        $this->authorize($actor);

        if (in_array($program->status, [SponsorshipProgram::STATUS_CANCELLED], true)) {
            throw new SponsorshipException('البرنامج الملغى لا يُعدَّل.');
        }

        $clean = $this->clean($data + ['title' => $program->title], partial: true);

        if (isset($data['target_type']) && ($data['target_type'] !== $program->target_type || (int) ($data['target_id'] ?? 0) !== (int) $program->target_id)) {
            if (! $program->isDraft() || $program->contributions()->exists()) {
                throw new SponsorshipException('لا يتغير هدف البرنامج بعد خروجه من المسودة أو بعد وجود مساهمات عليه.');
            }

            $this->targets->validate($data['target_type'], isset($data['target_id']) ? (int) $data['target_id'] : null);
            $clean['target_type'] = $data['target_type'];
            $clean['target_id'] = $data['target_type'] === SponsorshipProgram::TARGET_PLATFORM ? null : (int) $data['target_id'];
        }

        return DB::transaction(function () use ($actor, $program, $clean) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);
            $locked->forceFill($clean);
            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                $this->audit->log('sponsorship_program_updated', $locked, ['changed' => $changed], $actor);
                SponsorshipCache::bust();
            }

            return $locked;
        });
    }

    /**
     * خطة تعزيز المكافآت: step بعملة المحاسبة (نصًّا) + نقاط أساسية لكل خطوة + سقف. بصلاحية reward_plan وقبل القفل فقط.
     *
     * @param  array{enabled: bool, step?: string|int|null, per_step_bps?: int, max_bps?: int}  $plan
     *
     * @throws SponsorshipException
     */
    public function updateRewardPlan(User $actor, SponsorshipProgram $program, array $plan): SponsorshipProgram
    {
        abort_unless($actor->can('sponsorships.reward_plan'), 403);

        if ($program->reward_plan_locked_at !== null) {
            throw new SponsorshipException('خطة المكافآت مقفلة (منافسة اقتربت من البدء) ولا تُعدَّل.');
        }

        if ($program->status === SponsorshipProgram::STATUS_CANCELLED) {
            throw new SponsorshipException('البرنامج الملغى لا يُعدَّل.');
        }

        $enabled = (bool) ($plan['enabled'] ?? false);
        $hard = (int) config('sponsorship.reward.hard_max_bps', 5000);
        $step = null;
        $per = max(0, (int) ($plan['per_step_bps'] ?? 0));
        $max = max(0, (int) ($plan['max_bps'] ?? 0));

        if ($enabled) {
            $step = SponsorshipMoney::parse($plan['step'] ?? '', SponsorshipMoney::accountingCurrency());

            if ($per <= 0 || $max <= 0) {
                throw new SponsorshipException('حدّد نسبة التعزيز لكل خطوة وسقف التعزيز (نقاط أساسية، 100 = 1%).');
            }

            if ($max < $per) {
                throw new SponsorshipException('سقف التعزيز يجب ألا يقلّ عن نسبة الخطوة الواحدة.');
            }

            if ($max > $hard) {
                throw new SponsorshipException('سقف التعزيز يتجاوز الحدّ الصلب للمنصة ('.$hard.' نقطة أساسية).');
            }
        } elseif (filled($plan['step'] ?? null)) {
            $step = SponsorshipMoney::parse($plan['step'], SponsorshipMoney::accountingCurrency());
        }

        return DB::transaction(function () use ($actor, $program, $enabled, $step, $per, $max) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if ($locked->reward_plan_locked_at !== null) {
                throw new SponsorshipException('خطة المكافآت مقفلة ولا تُعدَّل.');
            }

            $before = $locked->only(['reward_boost_enabled', 'funding_step_minor', 'boost_per_step_bps', 'max_boost_bps']);
            $locked->forceFill(['reward_boost_enabled' => $enabled, 'funding_step_minor' => $step, 'boost_per_step_bps' => $enabled ? $per : 0, 'max_boost_bps' => $enabled ? $max : 0])->save();

            $this->audit->log('sponsorship_reward_plan_updated', $locked, ['before' => $before, 'after' => $locked->only(['reward_boost_enabled', 'funding_step_minor', 'boost_per_step_bps', 'max_boost_bps'])], $actor);
            SponsorshipCache::bust();

            return $locked;
        });
    }

    /** @throws SponsorshipException */
    public function activate(User $actor, SponsorshipProgram $program): SponsorshipProgram
    {
        $this->authorize($actor);

        $activated = DB::transaction(function () use ($actor, $program) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if (! $locked->isDraft()) {
                throw new SponsorshipException('يُفعَّل البرنامج من حالة المسودة فقط.');
            }

            $this->targets->validate((string) $locked->target_type, $locked->target_id);

            if ($locked->ends_at !== null && $locked->starts_at !== null && $locked->ends_at->lessThanOrEqualTo($locked->starts_at)) {
                throw new SponsorshipException('تاريخ الانتهاء يجب أن يلي تاريخ البدء.');
            }

            if ($locked->ends_at !== null && $locked->ends_at->isPast()) {
                throw new SponsorshipException('تاريخ الانتهاء مضى؛ عدّل المدة قبل التفعيل.');
            }

            $locked->forceFill(['status' => SponsorshipProgram::STATUS_ACTIVE, 'activated_at' => now()])->save();
            $this->audit->log('sponsorship_program_activated', $locked, ['slug' => $locked->slug], $actor);

            return $locked;
        });

        SponsorshipCache::bust();
        $this->announceStart($activated);

        return $activated;
    }

    /** @throws SponsorshipException */
    public function close(User $actor, SponsorshipProgram $program): SponsorshipProgram
    {
        $this->authorize($actor);

        $closed = DB::transaction(function () use ($actor, $program) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if ($locked->status !== SponsorshipProgram::STATUS_ACTIVE) {
                throw new SponsorshipException('يُغلق البرنامج النشط فقط.');
            }

            $locked->forceFill(['status' => SponsorshipProgram::STATUS_CLOSED, 'closed_at' => now()])->save();
            $this->audit->log('sponsorship_program_closed', $locked, ['slug' => $locked->slug], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $closed;
    }

    /** @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipProgram $program): SponsorshipProgram
    {
        $this->authorize($actor);

        $cancelled = DB::transaction(function () use ($actor, $program) {
            $locked = SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if (! in_array($locked->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
                throw new SponsorshipException('لا يُلغى إلا مسودة أو برنامج نشط.');
            }

            if ($locked->contributions()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->exists()) {
                throw new SponsorshipException('عليه مساهمات مؤكَّدة؛ أغلقه بدل الإلغاء أو استردّ مساهماته أولًا.');
            }

            $locked->forceFill(['status' => SponsorshipProgram::STATUS_CANCELLED, 'closed_at' => now()])->save();
            $this->audit->log('sponsorship_program_cancelled', $locked, ['slug' => $locked->slug], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $cancelled;
    }

    /**
     * يعيّن دور داعم بالبرنامج. sponsor/main_sponsor يشترطان مساهمة مؤكَّدة من الداعم بهذا البرنامج وملفًا غير موقوف. الراعي الرئيسي واحد: تعيين جديد يُنزل السابق إلى sponsor.
     *
     * @throws SponsorshipException
     */
    public function assignRole(User $actor, SponsorshipProgram $program, SupporterProfile $supporter, string $role): SponsorshipProgramRole
    {
        $this->authorize($actor);

        if (! in_array($role, SponsorshipProgramRole::ROLES, true)) {
            throw new SponsorshipException('دور غير صالح.');
        }

        if (in_array($program->status, [SponsorshipProgram::STATUS_CANCELLED], true)) {
            throw new SponsorshipException('البرنامج ملغى.');
        }

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف.');
        }

        $result = DB::transaction(function () use ($actor, $program, $supporter, $role) {
            SponsorshipProgram::query()->lockForUpdate()->findOrFail($program->id);

            if (! $this->totals->hasConfirmed($supporter->id, $program->id)) {
                throw new SponsorshipException('لا يُعيَّن راعيًا إلا من له مساهمة مؤكَّدة بهذا البرنامج.');
            }

            if ($role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR) {
                $previous = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('role', SponsorshipProgramRole::ROLE_MAIN_SPONSOR)
                    ->where('supporter_profile_id', '!=', $supporter->id)->first();

                if ($previous !== null) {
                    $previous->forceFill(['role' => SponsorshipProgramRole::ROLE_SPONSOR, 'main_marker' => null])->save();
                    $this->audit->log('main_sponsor_removed', $program, ['supporter_profile_id' => $previous->supporter_profile_id, 'reason' => 'replaced'], $actor);
                }
            }

            $row = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('supporter_profile_id', $supporter->id)->first() ?? new SponsorshipProgramRole;
            $wasMain = $row->exists && $row->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR;
            $row->forceFill([
                'sponsorship_program_id' => $program->id,
                'supporter_profile_id' => $supporter->id,
                'role' => $role,
                'main_marker' => $role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? $program->id : null,
                'assigned_by' => $actor->id,
            ])->save();

            if ($role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR) {
                $this->audit->log('main_sponsor_assigned', $program, ['supporter' => $supporter->public_id], $actor);
            } elseif ($wasMain) {
                $this->audit->log('main_sponsor_removed', $program, ['supporter' => $supporter->public_id, 'reason' => 'demoted'], $actor);
            } else {
                $this->audit->log('sponsorship_role_assigned', $program, ['supporter' => $supporter->public_id, 'role' => $role], $actor);
            }

            return $row;
        });

        SponsorshipCache::bust();

        return $result;
    }

    /** @throws SponsorshipException */
    public function removeRole(User $actor, SponsorshipProgram $program, SupporterProfile $supporter): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $program, $supporter) {
            $row = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('supporter_profile_id', $supporter->id)->lockForUpdate()->first();

            if ($row === null) {
                return;
            }

            $this->audit->log($row->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? 'main_sponsor_removed' : 'sponsorship_role_removed', $program, ['supporter' => $supporter->public_id, 'reason' => 'manual'], $actor);
            $row->delete();
        });

        SponsorshipCache::bust();
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('sponsorships.manage'), 403);
    }

    /** @return array<string, mixed> */
    protected function clean(array $data, bool $partial = false): array
    {
        $validator = Validator::make($data, [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'min:3', 'max:160', new NoMarkup],
            'description' => ['nullable', 'string', 'max:1000', new NoMarkup],
            'show_public_amounts' => ['sometimes', 'boolean'],
            'show_goal_progress' => ['sometimes', 'boolean'],
        ], [], ['title' => 'العنوان', 'description' => 'الوصف']);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        $out = [];

        if (isset($data['title'])) {
            $out['title'] = trim((string) $data['title']);
        }

        if (array_key_exists('description', $data)) {
            $out['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        foreach (['show_public_amounts', 'show_goal_progress'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $out[$flag] = (bool) $data[$flag];
            }
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = filled($data[$field]) ? Carbon::parse($data[$field]) : null;
            }
        }

        if (($out['starts_at'] ?? null) !== null && ($out['ends_at'] ?? null) !== null && $out['ends_at']->lessThanOrEqualTo($out['starts_at'])) {
            throw new SponsorshipException('تاريخ الانتهاء يجب أن يلي تاريخ البدء.');
        }

        if (array_key_exists('goal_amount', $data)) {
            $out['goal_amount_minor'] = filled($data['goal_amount']) ? SponsorshipMoney::parse($data['goal_amount'], SponsorshipMoney::accountingCurrency()) : null;
        }

        return $out;
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug(Str::ascii($title)), 70, '') ?: 'program';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (SponsorshipProgram::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /** إشعار «بدأ البرنامج» لداعميه المرتبطين بحسابات (ذوو مساهمة/دور) على دفعات صغيرة؛ فشله لا يمسّ التفعيل. */
    protected function announceStart(SponsorshipProgram $program): void
    {
        try {
            SupporterProfile::query()->whereNotNull('user_id')->whereIn('id', fn ($q) => $q->select('supporter_profile_id')->from('sponsorship_contributions')
                ->where('sponsorship_program_id', $program->id)->whereIn('status', [SponsorshipContribution::STATUS_PENDING, SponsorshipContribution::STATUS_CONFIRMED]))
                ->with('user')->chunkById(100, function ($supporters) use ($program) {
                    foreach ($supporters as $supporter) {
                        $this->notifier->programStarted($supporter, $program);
                    }
                });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Services/Sponsorship/SponsorshipRewardService.php`

`sha256: 934a8b64d0bf5f0a6358eeeac65640804f0aba99cbfd0fdce487a42888855abd`

`````php
<?php

namespace App\Services\Sponsorship;

use App\GameEngine\Support\AttemptContext;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipRewardBonus;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Facades\DB;

/**
 * E30 - **البوابة المركزية الوحيدة** لتعزيز المكافآت الممولة بالرعاية. لا مضاعف رعاية في أي متحكم أو نموذج أو خدمة أخرى: PuzzleAttemptService وCompetitiveRewardDistributionService
 * يستدعيان هذه الخدمة فقط (نقطتان). الصيغة (أعداد صحيحة، لا floats):
 *
 *     steps     = floor(confirmed_funding / funding_step)
 *     boost_bps = min(steps × boost_per_step_bps, max_boost_bps, hard_max_bps)        (100 bps = 1%)
 *     bonus     = floor(base × boost_bps / 10000)
 *
 * قواعد صلبة:
 *  - **محتوى رسمي فقط**: أحجية مجتمع (origin=community)، وسلاسل المبدعين، ومسارات المجتمع لا تمرّ هنا أصلًا (CommunityPlayService لا يلمس الاقتصاد)، وهذه الخدمة ترفض أحجية المجتمع صراحة.
 *  - الأساس سليم: المكافأة الأساسية تُمنح كما هي بمنطقها القائم؛ المكافأة الإضافية معاملة **منفصلة** بدفتر المحفظة (CurrencyWalletService::creditPending، معلّقة بفترة الحجز المعتادة)، لا تعديل رصيد مباشر.
 *  - الجواهر فقط (العملة المكتسبة الافتراضية). XP والمهام والسلسلة والمستويات لا تُعزَّز.
 *  - منصة/موسم/حملة: التمويل المؤكَّد لحظة المنح (حيّ من قاعدة البيانات، لا كاش)، ولمنح مستقبلية فقط؛ لا أثر رجعي لتمويل أو استرداد لاحق على ما مُنح.
 *    سقف الكسب اليومي يُحترم (المكافأة تُقصّ إلى المتبقي)، وتقييم الاحتيال يعمل بعد الإيداع كالمعتاد.
 *  - المنافسات: لقطة مقفلة قبل البدء (EventRewardPlanService) ⇒ نسبة ثابتة؛ بلا لقطة = بلا تعزيز (فشل مغلق). المكافأة الإضافية تتبع أصلها (لا سقف يومي، فالجائزة الأساسية نفسها خارجه).
 *  - الدعم لا يمنح الداعم أي أفضلية لعب أو مكافأة شخصية: الحساب من تمويل البرنامج لا من هوية اللاعب.
 *  - أي فشل هنا يُلتقط ويُبلَّغ (report) ويُعاد 0: لا يتعطل حل الأحجية ولا توزيع الجوائز بسبب الرعاية. المنح idempotent بمفتاح مصدر فريد.
 */
class SponsorshipRewardService
{
    public function __construct(protected CurrencyWalletService $wallets, protected CurrencyRegistry $currencies) {}

    public function enabled(): bool
    {
        return (bool) config('sponsorship.enabled', true) && (bool) config('sponsorship.reward.enabled', true);
    }

    /** نقاط التعزيز الأساسية لتمويل وخطة معطاة. صفر حين تنقص الخطة أو لا تمويل. */
    public static function computeBps(int $funding, int $step, int $perStep, int $max): int
    {
        if ($funding <= 0 || $step <= 0 || $perStep <= 0 || $max <= 0) {
            return 0;
        }

        $hard = (int) config('sponsorship.reward.hard_max_bps', 5000);

        return max(0, min(intdiv($funding, $step) * $perStep, $max, $hard));
    }

    public static function bonusFor(int $base, int $bps): int
    {
        return $base <= 0 || $bps <= 0 ? 0 : intdiv($base * $bps, (int) config('sponsorship.reward.bps_denominator', 10000));
    }

    /**
     * أعلى تعزيز منطبق على حلّ أحجية رسمية بسياق معيّن (بلا تراكم بين البرامج: الأعلى فقط، وعند التساوي الأصغر معرّفًا). null = لا تعزيز.
     * السياقات التنافسية (تحدي صديق/منافسة/فريق) وأي سياق مجهول ⇒ null (فشل مغلق).
     *
     * @return array{program_id: int, bps: int, funding: int}|null
     */
    public function resolveForSolve(AttemptContext $context): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $campaignId = null;

        if ($context->type === AttemptContext::TYPE_CAMPAIGN_STEP && $context->id !== null) {
            $campaignId = DB::table('campaign_steps as st')
                ->join('campaign_gates as g', 'g.id', '=', 'st.campaign_gate_id')->join('campaign_stages as sg', 'sg.id', '=', 'g.campaign_stage_id')
                ->where('st.id', $context->id)->value('sg.campaign_id');

            if ($campaignId === null) {
                return null;
            }
        } elseif ($context->isPresent()) {
            return null;
        }

        $programs = DB::table('sponsorship_programs as p')
            ->where('p.status', SponsorshipProgram::STATUS_ACTIVE)->where('p.reward_boost_enabled', true)->where('p.funding_step_minor', '>', 0)
            ->where(fn ($q) => $q->whereNull('p.starts_at')->orWhere('p.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('p.ends_at')->orWhere('p.ends_at', '>=', now()))
            ->where(function ($w) use ($campaignId) {
                $w->where('p.target_type', SponsorshipProgram::TARGET_PLATFORM);

                if ($campaignId !== null) {
                    $w->orWhere(fn ($c) => $c->where('p.target_type', SponsorshipProgram::TARGET_CAMPAIGN)->where('p.target_id', $campaignId))
                        ->orWhere(fn ($s) => $s->where('p.target_type', SponsorshipProgram::TARGET_SEASON)
                            ->whereIn('p.target_id', fn ($q) => $q->select('id')->from('seasons')->where('campaign_id', $campaignId)->where('is_published', true)));
                }
            })
            ->select('p.id', 'p.funding_step_minor', 'p.boost_per_step_bps', 'p.max_boost_bps')
            ->selectSub(fn ($q) => $q->from('sponsorship_contributions as c')->whereColumn('c.sponsorship_program_id', 'p.id')
                ->where('c.status', 'confirmed')->selectRaw('COALESCE(SUM(c.accounting_amount_minor), 0)'), 'funding')
            ->orderBy('p.id')->get();

        $best = null;

        foreach ($programs as $program) {
            $bps = self::computeBps((int) $program->funding, (int) $program->funding_step_minor, (int) $program->boost_per_step_bps, (int) $program->max_boost_bps);

            if ($bps > 0 && ($best === null || $bps > $best['bps'])) {
                $best = ['program_id' => (int) $program->id, 'bps' => $bps, 'funding' => (int) $program->funding];
            }
        }

        return $best;
    }

    /**
     * مكافأة إضافية لحلّ أحجية رسمية. تُستدعى من PuzzleAttemptService داخل معاملة المحاولة بعد منح الأساس، والمحفظة مقفلة أصلًا.
     * تعيد ما مُنح فعلًا (0 غالبًا). لا ترمي أبدًا.
     */
    public function applyToSolve(User $user, Puzzle $puzzle, AttemptContext $context, Currency $currency, int $baseReward, int $attemptId): int
    {
        try {
            if ($baseReward <= 0 || $attemptId <= 0 || $puzzle->isCommunity() || ! $currency->is($this->currencies->defaultEarnedCurrency())) {
                return 0;
            }

            $boost = $this->resolveForSolve($context);

            if ($boost === null) {
                return 0;
            }

            $calculated = self::bonusFor($baseReward, $boost['bps']);
            $allowance = max(0, (int) config('gems.daily_earn_cap') - $this->wallets->dailyEarnedToday($user, $currency));
            $bonus = min($calculated, $allowance);

            if ($bonus <= 0) {
                return 0;
            }

            return $this->credit($user, $currency, $boost['program_id'], 'solve:'.$attemptId, $context->type, $context->id, $baseReward, $boost['bps'], $calculated, $bonus, $boost['funding']);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * لقطة تعزيز جائزة منافسة عند التخطيط (بعد اعتماد المنافسة): من الخطة المقفلة قبل البدء حصرًا. جوائز العملة بالجواهر الافتراضية فقط.
     *
     * @return array{sponsor_program_id: ?int, sponsor_bonus_bps: int, sponsor_bonus_amount: int}
     */
    public function competitiveSnapshot(CompetitiveEvent $event, CompetitiveRewardRule $rule): array
    {
        $none = ['sponsor_program_id' => null, 'sponsor_bonus_bps' => 0, 'sponsor_bonus_amount' => 0];

        try {
            if (! $this->enabled() || $rule->reward_type !== CompetitiveRewardRule::TYPE_CURRENCY || (int) $rule->currency_id !== (int) $this->currencies->defaultEarnedCurrency()->id) {
                return $none;
            }

            $plan = SponsorshipEventRewardPlan::query()->where('competitive_event_id', $event->getKey())->first();

            if ($plan === null || $plan->sponsorship_program_id === null || (int) $plan->boost_bps <= 0) {
                return $none;
            }

            $bonus = self::bonusFor((int) $rule->amount, (int) $plan->boost_bps);

            return $bonus > 0 ? ['sponsor_program_id' => (int) $plan->sponsorship_program_id, 'sponsor_bonus_bps' => (int) $plan->boost_bps, 'sponsor_bonus_amount' => $bonus] : $none;
        } catch (\Throwable $e) {
            report($e);

            return $none;
        }
    }

    /**
     * يمنح المكافأة الإضافية المخزَّنة بلقطة الجائزة (معاملة منفصلة بمفتاح فريد). يُستدعى من deliver() بعد منح الأساس؛ فشله معزول بنقطة حفظ ولا يُبطل الأساس.
     */
    public function grantCompetitiveBonus(CompetitiveRewardGrant $grant, User $user, Currency $currency): int
    {
        try {
            $bonus = (int) $grant->sponsor_bonus_amount;

            if ($bonus <= 0 || $grant->sponsor_program_id === null || (int) $grant->sponsor_bonus_bps <= 0 || ! $this->enabled()) {
                return 0;
            }

            return $this->credit($user, $currency, (int) $grant->sponsor_program_id, 'competitive:'.$grant->competitive_event_id.':'.$grant->user_id,
                AttemptContext::TYPE_COMPETITIVE_EVENT, (int) $grant->competitive_event_id, (int) $grant->amount, (int) $grant->sponsor_bonus_bps, $bonus, $bonus,
                (int) SponsorshipEventRewardPlan::query()->where('competitive_event_id', $grant->competitive_event_id)->value('funding_snapshot_minor'));
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** إيداع المكافأة + سجلّها في معاملة (نقطة حفظ عند التداخل) بمفتاح مصدر فريد: إعادة الاستدعاء لا تمنح ثانية. */
    protected function credit(User $user, Currency $currency, int $programId, string $sourceKey, ?string $contextType, ?int $contextId, int $base, int $bps, int $calculated, int $bonus, int $funding): int
    {
        $key = 'sponsor-bonus:'.$sourceKey;

        return DB::transaction(function () use ($user, $currency, $programId, $key, $contextType, $contextId, $base, $bps, $calculated, $bonus, $funding) {
            $existing = SponsorshipRewardBonus::query()->where('source_key', $key)->first();

            if ($existing !== null) {
                return 0;
            }

            $program = SponsorshipProgram::query()->findOrFail($programId);
            $tx = $this->wallets->creditPending($user, $currency, $bonus, 'sponsor_bonus:'.$programId, $program, $key);

            $row = new SponsorshipRewardBonus;
            $row->forceFill([
                'source_key' => $key,
                'sponsorship_program_id' => $programId,
                'user_id' => $user->id,
                'context_type' => $contextType,
                'context_id' => $contextId,
                'currency_id' => $currency->id,
                'base_amount' => $base,
                'boost_bps' => $bps,
                'calculated_bonus_amount' => $calculated,
                'bonus_amount' => $bonus,
                'final_amount' => $base + $bonus,
                'funding_snapshot_minor' => $funding,
                'currency_transaction_id' => $tx->id,
            ])->save();

            return $bonus;
        });
    }
}
`````

### `app/Services/Sponsorship/SponsorshipTargets.php`

`sha256: 5d18a1505e4d7b81834dc629458d13136cf95b25f69046624b18f3596fa40e8c`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Season;
use App\Models\SponsorshipProgram;

/**
 * E30 - أهداف الرعاية: قائمة بيضاء مغلقة (platform / season / campaign / competitive_event) كلها محتوى **رسمي**. أحجيات المجتمع وسلاسل المبدعين وملفات المبدعين
 * ليست أهدافًا أبدًا (لا نوع لها هنا فيُرفض أي هدف خارج القائمة). الهدف يجب أن يكون موجودًا؛ المنصة بلا معرّف.
 */
class SponsorshipTargets
{
    /** @throws SponsorshipException */
    public function validate(string $type, ?int $id): void
    {
        if (! in_array($type, SponsorshipProgram::TARGETS, true)) {
            throw new SponsorshipException('نوع هدف الرعاية غير مسموح (المسموح: المنصة، موسم، حملة، منافسة).');
        }

        if ($type === SponsorshipProgram::TARGET_PLATFORM) {
            if ($id !== null) {
                throw new SponsorshipException('رعاية المنصة لا تحمل معرّف هدف.');
            }

            return;
        }

        if ($id === null || $id <= 0) {
            throw new SponsorshipException('اختر الهدف.');
        }

        $exists = match ($type) {
            SponsorshipProgram::TARGET_SEASON => Season::query()->whereKey($id)->exists(),
            SponsorshipProgram::TARGET_CAMPAIGN => Campaign::query()->whereKey($id)->exists(),
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => CompetitiveEvent::query()->whereKey($id)->where('status', '!=', CompetitiveEvent::STATUS_CANCELLED)->exists(),
        };

        if (! $exists) {
            throw new SponsorshipException('الهدف غير موجود أو غير صالح للرعاية.');
        }
    }

    public function typeLabel(string $type): string
    {
        return [
            SponsorshipProgram::TARGET_PLATFORM => 'المنصة',
            SponsorshipProgram::TARGET_SEASON => 'موسم',
            SponsorshipProgram::TARGET_CAMPAIGN => 'حملة',
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => 'منافسة',
        ][$type] ?? $type;
    }

    public function label(SponsorshipProgram $program): string
    {
        $name = match ($program->target_type) {
            SponsorshipProgram::TARGET_SEASON => Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')->where('seasons.id', $program->target_id)->value('campaigns.title'),
            SponsorshipProgram::TARGET_CAMPAIGN => Campaign::query()->whereKey($program->target_id)->value('title'),
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => CompetitiveEvent::query()->whereKey($program->target_id)->value('title'),
            default => null,
        };

        return $this->typeLabel((string) $program->target_type).($name ? ': '.$name : '');
    }

    /** رابط صفحة الهدف العامة (إن وُجدت). */
    public function url(SponsorshipProgram $program): ?string
    {
        return match ($program->target_type) {
            SponsorshipProgram::TARGET_SEASON => ($slug = Season::query()->whereKey($program->target_id)->value('slug')) ? route('seasons.show', $slug) : null,
            SponsorshipProgram::TARGET_CAMPAIGN => ($slug = Campaign::query()->whereKey($program->target_id)->value('slug')) ? route('campaigns.show', $slug) : null,
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => ($slug = CompetitiveEvent::query()->whereKey($program->target_id)->value('slug')) ? route('competitions.show', $slug) : null,
            default => null,
        };
    }

    /** @return array<int, string> خيارات الهدف لنوع معيّن (للنماذج الإدارية). مقيَّدة العدد. */
    public function options(string $type): array
    {
        return match ($type) {
            SponsorshipProgram::TARGET_SEASON => Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')->orderByDesc('seasons.id')->limit(100)->pluck('campaigns.title', 'seasons.id')->all(),
            SponsorshipProgram::TARGET_CAMPAIGN => Campaign::query()->orderByDesc('id')->limit(100)->pluck('title', 'id')->all(),
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => CompetitiveEvent::query()->where('status', '!=', CompetitiveEvent::STATUS_CANCELLED)->orderByDesc('id')->limit(100)->pluck('title', 'id')->all(),
            default => [],
        };
    }
}
`````

### `app/Services/Sponsorship/SupportTotals.php`

`sha256: 5acea941276e9d57b9369b1ab539a32203f9502a63fd706b9afd269d70b4143c`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use Illuminate\Support\Facades\DB;

/**
 * E30 - المجاميع المالية الصافية: SUM(المعادل المحاسبي) للمساهمات **المؤكَّدة فقط** (المستردة/الملغاة/المعلّقة لا تُحتسب). تقرأ قاعدة البيانات مباشرة دائمًا
 * (سلطة التمويل/المستوى/التعزيز)، وتستعمل فهارس sponsorship_contrib_*؛ لا كاش هنا.
 */
class SupportTotals
{
    public function net(int $supporterId): int
    {
        return (int) SponsorshipContribution::query()
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('supporter_profile_id', $supporterId)
            ->sum('accounting_amount_minor');
    }

    /** @param list<int> $supporterIds @return array<int, int> */
    public function netMany(array $supporterIds): array
    {
        if ($supporterIds === []) {
            return [];
        }

        return DB::table('sponsorship_contributions')
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->whereIn('supporter_profile_id', $supporterIds)
            ->groupBy('supporter_profile_id')
            ->selectRaw('supporter_profile_id as id, SUM(accounting_amount_minor) as total')
            ->pluck('total', 'id')->map(fn ($v) => (int) $v)->all();
    }

    public function programFunding(int $programId): int
    {
        return (int) SponsorshipContribution::query()
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('sponsorship_program_id', $programId)
            ->sum('accounting_amount_minor');
    }

    /** @param list<int> $programIds @return array<int, int> */
    public function programFundingMany(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        return DB::table('sponsorship_contributions')
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->whereIn('sponsorship_program_id', $programIds)
            ->groupBy('sponsorship_program_id')
            ->selectRaw('sponsorship_program_id as id, SUM(accounting_amount_minor) as total')
            ->pluck('total', 'id')->map(fn ($v) => (int) $v)->all();
    }

    public function confirmedCount(int $programId): int
    {
        return SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('sponsorship_program_id', $programId)->count();
    }

    /** هل للداعم مساهمة مؤكَّدة بهذا البرنامج (شرط الأدوار). */
    public function hasConfirmed(int $supporterId, int $programId): bool
    {
        return SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)
            ->where('supporter_profile_id', $supporterId)->where('sponsorship_program_id', $programId)->exists();
    }
}
`````

### `app/Services/Sponsorship/SupporterLeaderboardService.php`

`sha256: 5abfd842f17b2ebac6d0cacbb3d30075bfefda528e818954b27de04679e26f2b`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E30 - لوحات الداعمين العامة (الأعلى كل الوقت/هذا العام/الموسم الحالي/برنامج، الأحدث، المؤسسات). مبدأ التصميم:
 *
 *  - الترتيب بصافي المؤكَّد المحاسبي (SUM مجمَّعة بقاعدة البيانات بفهرس مغطٍّ؛ لا تحميل صفوف ولا فرز PHP) ثم أول تأكيد (الأقدم أولًا) ثم المعرّف: ترتيب قطعي لا يتأرجح.
 *  - المجهولون: تجميع منفصل لكل (داعم، مجهول). صف المجهول يظهر باسم «داعم مجهول» بلا ملف ولا رابط ولا شعار ولا مستوى، فلا يمكن ربطه بالاسم. ما حُسب مجهولًا
 *    لا يدخل صف الاسم الصريح ولا قائمة المؤسسات. المساهمات المجهولة تُحتسب في المجاميع/المستوى/التمويل لكنها لا تُنسب علنًا لأحد.
 *  - المبالغ الدقيقة لا تظهر إلا للوحة برنامج فعّل show_public_amounts؛ اللوحات العامة الأخرى تعرض المستوى فقط.
 *  - الكاش يحمل معرّفات وترتيبًا ومجاميع فقط (TTL قصير + إبطال بأي تغيير مالي/ظهور)؛ العلنية تُعاد فحصها حيًّا بكل عرض (موقوف/خاص يختفي فورًا) بهامش جلب إضافي.
 *  - كل قائمة محدودة بسقف (لا ترقيم مفتوح).
 *
 * @phpstan-type Entry array{rank: int, anonymous: bool, supporter: ?SupporterProfile, tier: ?SupporterTier, total_minor: ?int, since: ?Carbon}
 */
class SupporterLeaderboardService
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_YEAR = 'year';

    public const SCOPE_SEASON = 'season';

    public const SCOPE_PROGRAM = 'program';

    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_YEAR, self::SCOPE_SEASON, self::SCOPE_PROGRAM];

    public function __construct(protected SupporterTierService $tiers) {}

    /**
     * @return array{entries: list<array>, show_amounts: bool, scope: string}
     */
    public function top(string $scope = self::SCOPE_ALL, ?SponsorshipProgram $program = null, ?int $limit = null): array
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_ALL;
        $limit = max(1, min((int) ($limit ?? config('sponsorship.public.top_limit', 20)), 100));
        $programId = $scope === self::SCOPE_PROGRAM ? ($program?->id) : null;

        if ($scope === self::SCOPE_PROGRAM && $programId === null) {
            return ['entries' => [], 'show_amounts' => false, 'scope' => $scope];
        }

        $seasonId = $scope === self::SCOPE_SEASON ? $this->currentSeasonId() : null;

        if ($scope === self::SCOPE_SEASON && $seasonId === null) {
            return ['entries' => [], 'show_amounts' => false, 'scope' => $scope];
        }

        $fetch = $limit + (int) config('sponsorship.public.overfetch', 10);
        $rows = SponsorshipCache::remember('top', [$scope, $programId ?? 0, $seasonId ?? 0, now()->year, $fetch], null, fn () => $this->aggregate($scope, $programId, $seasonId, $fetch));
        $showAmounts = $scope === self::SCOPE_PROGRAM && (bool) $program?->show_public_amounts;

        return ['entries' => $this->hydrate($rows, $limit, $showAmounts), 'show_amounts' => $showAmounts, 'scope' => $scope];
    }

    /**
     * أحدث الداعمين (تأكيدات حديثة). كل مساهمة مجهولة سطر «داعم مجهول» مستقل؛ المسمّون مرة واحدة لكل داعم.
     *
     * @return list<array{anonymous: bool, supporter: ?SupporterProfile, at: ?Carbon}>
     */
    public function recent(?int $limit = null): array
    {
        $limit = max(1, min((int) ($limit ?? config('sponsorship.public.recent_limit', 12)), 50));
        $fetch = $limit * 3 + (int) config('sponsorship.public.overfetch', 10);

        $rows = SponsorshipCache::remember('recent', [$fetch], null, fn () => DB::table('sponsorship_contributions as c')
            ->join('supporter_profiles as s', 's.id', '=', 'c.supporter_profile_id')
            ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('s.status', SupporterProfile::STATUS_ACTIVE)->where('s.is_public', true)
            ->orderByDesc('c.confirmed_at')->orderByDesc('c.id')->limit($fetch)
            ->get(['c.supporter_profile_id as sid', 'c.is_anonymous as anon', 'c.confirmed_at as at'])
            ->map(fn ($r) => ['sid' => (int) $r->sid, 'anon' => (bool) $r->anon, 'at' => $r->at])->all());

        $profiles = $this->livePublic(array_column($rows, 'sid'));
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            if (! isset($profiles[$row['sid']])) {
                continue;
            }

            if (! $row['anon']) {
                if (isset($seen[$row['sid']])) {
                    continue;
                }

                $seen[$row['sid']] = true;
            }

            $out[] = ['anonymous' => $row['anon'], 'supporter' => $row['anon'] ? null : $profiles[$row['sid']], 'at' => $row['at'] ? Carbon::parse($row['at']) : null];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * المؤسسات الداعمة (علنيًا باسمها فقط): مؤسسات علنية لها دعم مؤكَّد **غير مجهول**، بترتيب صافي الدعم المسمّى.
     *
     * @return list<array{rank: int, supporter: SupporterProfile, tier: ?SupporterTier}>
     */
    public function organizations(?int $limit = null): array
    {
        $limit = max(1, min((int) ($limit ?? config('sponsorship.public.organizations_limit', 12)), 50));
        $fetch = $limit + (int) config('sponsorship.public.overfetch', 10);

        $rows = SponsorshipCache::remember('orgs', [$fetch], null, fn () => DB::table('sponsorship_contributions as c')
            ->join('supporter_profiles as s', 's.id', '=', 'c.supporter_profile_id')
            ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('c.is_anonymous', false)
            ->where('s.type', SupporterProfile::TYPE_ORGANIZATION)->where('s.status', SupporterProfile::STATUS_ACTIVE)->where('s.is_public', true)
            ->groupBy('c.supporter_profile_id')
            ->selectRaw('c.supporter_profile_id as sid, SUM(c.accounting_amount_minor) as total, MIN(c.confirmed_at) as first_at')
            ->orderByDesc('total')->orderBy('first_at')->orderBy('sid')->limit($fetch)
            ->get()->map(fn ($r) => ['sid' => (int) $r->sid])->all());

        $profiles = $this->livePublic(array_column($rows, 'sid'));
        $tiers = $this->tiers->forMany(array_keys($profiles));
        $out = [];

        foreach ($rows as $row) {
            if (! isset($profiles[$row['sid']])) {
                continue;
            }

            $out[] = ['rank' => count($out) + 1, 'supporter' => $profiles[$row['sid']], 'tier' => $tiers[$row['sid']] ?? null];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** الموسم «الحالي»: منشور وحملته نشطة ضمن نافذتها، الأحدث فالمميّز أولًا؛ null إن لم يوجد. */
    public function currentSeasonId(): ?int
    {
        $id = Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->where('seasons.is_published', true)->where('campaigns.is_active', true)
            ->where(fn ($q) => $q->whereNull('campaigns.starts_at')->orWhere('campaigns.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('campaigns.ends_at')->orWhere('campaigns.ends_at', '>=', now()))
            ->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')->value('seasons.id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{sid: int, anon: bool, total: int, first: ?string}> */
    protected function aggregate(string $scope, ?int $programId, ?int $seasonId, int $limit): array
    {
        $query = DB::table('sponsorship_contributions as c')
            ->join('supporter_profiles as s', 's.id', '=', 'c.supporter_profile_id')
            ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('s.status', SupporterProfile::STATUS_ACTIVE)->where('s.is_public', true);

        match ($scope) {
            self::SCOPE_YEAR => $query->where('c.confirmed_at', '>=', now()->startOfYear()),
            self::SCOPE_SEASON => $query->whereIn('c.sponsorship_program_id', fn ($q) => $q->select('id')->from('sponsorship_programs')
                ->where('target_type', SponsorshipProgram::TARGET_SEASON)->where('target_id', $seasonId)),
            self::SCOPE_PROGRAM => $query->where('c.sponsorship_program_id', $programId),
            default => null,
        };

        return $query->groupBy('c.supporter_profile_id', 'c.is_anonymous')
            ->selectRaw('c.supporter_profile_id as sid, c.is_anonymous as anon, SUM(c.accounting_amount_minor) as total, MIN(c.confirmed_at) as first_at')
            ->orderByDesc('total')->orderBy('first_at')->orderBy('sid')->orderBy('anon')
            ->limit($limit)->get()
            ->map(fn ($r) => ['sid' => (int) $r->sid, 'anon' => (bool) $r->anon, 'total' => (int) $r->total, 'first' => $r->first_at])->all();
    }

    /**
     * @param  list<array{sid: int, anon: bool, total: int, first: ?string}>  $rows
     * @return list<array>
     */
    protected function hydrate(array $rows, int $limit, bool $showAmounts): array
    {
        $profiles = $this->livePublic(array_column($rows, 'sid'));
        $named = array_values(array_unique(array_map(fn ($r) => $r['sid'], array_filter($rows, fn ($r) => ! $r['anon'] && isset($profiles[$r['sid']])))));
        $tiers = $this->tiers->forMany($named);
        $out = [];

        foreach ($rows as $row) {
            if (! isset($profiles[$row['sid']])) {
                continue;
            }

            $out[] = [
                'rank' => count($out) + 1,
                'anonymous' => $row['anon'],
                'supporter' => $row['anon'] ? null : $profiles[$row['sid']],
                'tier' => $row['anon'] ? null : ($tiers[$row['sid']] ?? null),
                'total_minor' => $showAmounts ? $row['total'] : null,
                'since' => $row['first'] ? Carbon::parse($row['first']) : null,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @param list<int> $ids @return array<int, SupporterProfile> علنيون الآن فقط (فحص حيّ بكل عرض). */
    protected function livePublic(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return SupporterProfile::query()->public()->whereIn('id', $ids)
            ->get(['id', 'public_id', 'type', 'display_name', 'slug', 'description', 'logo_path', 'website_url', 'status', 'is_public', 'created_at'])->keyBy('id')->all();
    }
}
`````

### `app/Services/Sponsorship/SupporterLogoService.php`

`sha256: 1a565452d0d958686f5a891b23c8eb69b6ba18aa0da887734e825772d8ed541a`

`````php
<?php

namespace App\Services\Sponsorship;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E30 - شعارات الداعمين على قرص التخزين الحالي (config/sponsorship.php logo.disk). لا بنية تخزين جديدة ولا حزم.
 *  - JPEG/PNG/WEBP فقط بالنوع الفعلي للمحتوى (finfo + getimagesize متطابقان)؛ SVG مرفوض بالبناء (ليس بالقائمة). حدّ حجم من الإعداد.
 *  - الاسم يولّده المخدّم (UUID + لاحقة من النوع المكتشف) داخل مجلد الشعارات؛ اسم العميل لا يدخل المسار إطلاقًا.
 *  - الحذف لا يطال إلا ملفات داخل مجلد الشعارات (ownedPath) ولا يقبل مسارًا من العميل.
 */
class SupporterLogoService
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('sponsorship.logo.disk'));
    }

    public function url(?string $path): ?string
    {
        return blank($path) ? null : $this->disk()->url($path);
    }

    public function directory(): string
    {
        return trim((string) config('sponsorship.logo.directory'), '/');
    }

    /** @throws SponsorshipException */
    public function store(UploadedFile $file): string
    {
        $allowed = (array) config('sponsorship.logo.mimes');
        $path = $file->getRealPath();
        $mime = $path ? (string) mime_content_type($path) : '';
        $info = $path ? @getimagesize($path) : false;

        if (! isset($allowed[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw new SponsorshipException('صيغة الشعار غير مدعومة. المسموح: JPEG أو PNG أو WEBP.');
        }

        if ($file->getSize() > ((int) config('sponsorship.logo.max_kb')) * 1024) {
            throw new SponsorshipException('حجم الشعار أكبر من المسموح.');
        }

        $name = Str::uuid()->toString().'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directory(), $file, $name);

        if ($stored === false) {
            throw new SponsorshipException('تعذّر حفظ الشعار، حاول لاحقًا.');
        }

        return $stored;
    }

    public function ownedPath(?string $path): bool
    {
        return ! blank($path) && ! str_contains($path, '..') && ! str_contains($path, "\0") && str_starts_with($path, $this->directory().'/');
    }

    public function delete(?string $path): void
    {
        if ($this->ownedPath($path) && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }
}
`````

### `app/Services/Sponsorship/SupporterRecognitionService.php`

`sha256: 2da08a71d24380eccd1c6236268e325e6288414f9dfa562ed9bd486361e0f246`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SupporterProfile;
use App\Models\User;

/**
 * E30 - تقدير الداعم بجانب هوية اللاعب (شارة «داعم · المستوى» على ملفه العام). قرار تصميم: **شارة مخصصة مشتقّة** وليست عنصرًا من متجر الهوية/Loadout (لا تلمس نظام التجهيز ولا يمكن شراؤها).
 * تُعرض فقط إذا: ملف الداعم المرتبط بالحساب علني ونشط ∧ له مستوى ∧ له دعم مؤكَّد **غير مجهول** واحد على الأقل (من كل دعمه مجهول لا تُنسب له شارة علنًا).
 * المستوى مشتقّ من صافي المؤكَّد (يتبع الاسترداد فورًا). استعلامان صغيران فقط (الملف ثم مجمَّع واحد)؛ لا بيانات مالية تُعرض.
 */
class SupporterRecognitionService
{
    public function __construct(protected SupporterTierService $tiers) {}

    /** @return array{name: string, slug: string, tier: string, badge_key: string}|null */
    public function forUser(User $user): ?array
    {
        if (! (bool) config('sponsorship.enabled', true)) {
            return null;
        }

        $profile = SupporterProfile::query()->public()->where('user_id', $user->id)->first(['id', 'display_name', 'slug']);

        if ($profile === null) {
            return null;
        }

        $agg = SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('supporter_profile_id', $profile->id)
            ->selectRaw('COALESCE(SUM(accounting_amount_minor), 0) as total, COALESCE(SUM(CASE WHEN is_anonymous = 0 THEN 1 ELSE 0 END), 0) as named')->first();

        if ((int) ($agg->named ?? 0) <= 0) {
            return null;
        }

        $tier = $this->tiers->forAmount((int) ($agg->total ?? 0));

        return $tier === null ? null : ['name' => $profile->display_name, 'slug' => $profile->slug, 'tier' => $tier->name, 'badge_key' => $tier->badge_key];
    }
}
`````

### `app/Services/Sponsorship/SupporterService.php`

`sha256: 111ffe80bf01f2af35cdc4ce2d4fef16dabb2b5861728ef5023d73dad20e0e33`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use App\Support\SafeUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * E30 - ملفات الداعمين. الكتابة الإدارية فقط (sponsorships.manage) وبلا تعيين جماعي؛ كل تغيير يُدقَّق. لا تفاصيل دفع هنا ولا بأي مكان.
 * المدخلات نص عادي (NoMarkup)، الرابط يمرّ بـSafeUrl (http/https فقط)، الشعار JPEG/PNG/WEBP فقط. الـslug يُولَّد مرة ولا يتغير (روابط ثابتة).
 */
class SupporterService
{
    public function __construct(protected OperationalAuditService $audit, protected SupporterLogoService $logos) {}

    /**
     * @param  array{display_name?: string, type?: string, description?: ?string, website_url?: ?string, status?: string, is_public?: bool, anonymous_by_default?: bool, user_id?: ?int}  $data
     *
     * @throws SponsorshipException
     */
    public function create(User $actor, array $data, ?UploadedFile $logo = null): SupporterProfile
    {
        $this->authorize($actor);
        $clean = $this->clean($data, true);
        $logoPath = $logo !== null ? $this->logos->store($logo) : null;

        try {
            $profile = DB::transaction(function () use ($actor, $clean, $logoPath) {
                $profile = new SupporterProfile;
                $profile->forceFill($clean + [
                    'slug' => $this->uniqueSlug($clean['display_name']),
                    'logo_path' => $logoPath,
                    'created_by' => $actor->id,
                ])->save();

                $this->audit->log('supporter_created', $profile, ['public_id' => $profile->public_id, 'type' => $profile->type, 'name' => $profile->display_name], $actor);

                return $profile;
            });
        } catch (\Throwable $e) {
            $this->logos->delete($logoPath);

            throw $e;
        }

        SponsorshipCache::bust();

        return $profile;
    }

    /** @throws SponsorshipException */
    public function update(User $actor, SupporterProfile $profile, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): SupporterProfile
    {
        $this->authorize($actor);
        $clean = $this->clean($data, false, $profile);
        $newLogo = $logo !== null ? $this->logos->store($logo) : null;
        $oldLogo = $profile->logo_path;

        try {
            DB::transaction(function () use ($actor, $profile, $clean, $newLogo, $removeLogo) {
                $profile->forceFill($clean);

                if ($newLogo !== null) {
                    $profile->logo_path = $newLogo;
                } elseif ($removeLogo) {
                    $profile->logo_path = null;
                }

                $changed = array_keys($profile->getDirty());
                $profile->save();

                if ($changed !== []) {
                    $this->audit->log('supporter_updated', $profile, ['changed' => $changed], $actor);
                }
            });
        } catch (\Throwable $e) {
            $this->logos->delete($newLogo);

            throw $e;
        }

        if (($newLogo !== null || $removeLogo) && $oldLogo !== $profile->logo_path) {
            $this->logos->delete($oldLogo);
        }

        SponsorshipCache::bust();

        return $profile->refresh();
    }

    /** @throws SponsorshipException */
    public function setStatus(User $actor, SupporterProfile $profile, string $status): SupporterProfile
    {
        $this->authorize($actor);

        if (! in_array($status, SupporterProfile::STATUSES, true)) {
            throw new SponsorshipException('حالة غير صالحة.');
        }

        $from = $profile->status;

        if ($from !== $status) {
            $profile->forceFill(['status' => $status])->save();
            $this->audit->log('supporter_status_changed', $profile, ['from' => $from, 'to' => $status], $actor);
            SponsorshipCache::bust();
        }

        return $profile;
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('sponsorships.manage'), 403);
    }

    /** @return array<string, mixed> */
    protected function clean(array $data, bool $creating, ?SupporterProfile $existing = null): array
    {
        $max = (array) config('sponsorship.profile');

        $validator = Validator::make($data, [
            'display_name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:'.$max['name_max'], new NoMarkup],
            'type' => [$creating ? 'required' : 'sometimes', 'in:'.implode(',', SupporterProfile::TYPES)],
            'description' => ['nullable', 'string', 'max:'.$max['description_max'], new NoMarkup],
            'website_url' => ['nullable', 'string', 'max:'.$max['url_max']],
            'status' => ['sometimes', 'in:'.implode(',', SupporterProfile::STATUSES)],
            'is_public' => ['sometimes', 'boolean'],
            'anonymous_by_default' => ['sometimes', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [], ['display_name' => 'الاسم', 'type' => 'النوع', 'description' => 'الوصف', 'website_url' => 'الموقع', 'user_id' => 'المستخدم']);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        $out = [];

        foreach (['display_name', 'type', 'status', 'is_public', 'anonymous_by_default'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
            }
        }

        if (array_key_exists('description', $data)) {
            $out['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        if (array_key_exists('website_url', $data)) {
            $url = filled($data['website_url']) ? SafeUrl::normalize((string) $data['website_url'], (int) $max['url_max']) : null;

            if (filled($data['website_url']) && $url === null) {
                throw new SponsorshipException('رابط الموقع غير آمن أو غير صالح (http/https فقط).');
            }

            $out['website_url'] = $url;
        }

        if (array_key_exists('user_id', $data)) {
            $userId = filled($data['user_id']) ? (int) $data['user_id'] : null;

            if ($userId !== null && SupporterProfile::query()->where('user_id', $userId)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                throw new SponsorshipException('هذا المستخدم مرتبط بملف داعم آخر.');
            }

            $out['user_id'] = $userId;
        }

        if ($creating) {
            $out += ['status' => SupporterProfile::STATUS_ACTIVE, 'is_public' => true, 'anonymous_by_default' => false];
        }

        return $out;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug(Str::ascii($name)), 60, '') ?: 'supporter';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (SupporterProfile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
`````

### `app/Services/Sponsorship/SupporterTierService.php`

`sha256: f71865289f62c17489a25844c325cf86df7eb476f0c96f139f1267cc83439697`

`````php
<?php

namespace App\Services\Sponsorship;

use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use Illuminate\Support\Collection;

/**
 * E30 - مستوى الداعم **مشتقّ دائمًا** من صافي دعمه المؤكَّد (SupportTotals) مقابل مستويات الإدارة النشطة: أعلى مستوى حدّه الأدنى ≤ الصافي.
 * لا يُخزَّن على الداعم ولا يُقبل من العميل أبدًا؛ فالاسترداد يخفّضه آليًا والتأكيد يرفعه. تعادل الحدّ الأدنى بين مستويين يُحسم بـsort_order ثم المعرّف (قطعي).
 * قائمة المستويات (إعداد) تُخزَّن مؤقتًا بكاش الرعاية وتُبطَل بأي تغيير (SponsorshipCache::bust)؛ المبلغ نفسه لا يُخزَّن بالكاش.
 */
class SupporterTierService
{
    public function __construct(protected SupportTotals $totals) {}

    /** @return Collection<int, SupporterTier> نشطة مرتبة بالحدّ الأدنى تصاعديًا. */
    public function tiers(): Collection
    {
        $rows = SponsorshipCache::remember('tiers', [], 300, fn () => SupporterTier::query()->where('is_active', true)
            ->orderBy('minimum_accounting_amount_minor')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'slug', 'minimum_accounting_amount_minor', 'sort_order', 'badge_key'])->map->getAttributes()->all());

        return collect($rows)->map(fn (array $row) => (new SupporterTier)->forceFill($row));
    }

    public function forAmount(int $netMinor): ?SupporterTier
    {
        if ($netMinor <= 0) {
            return null;
        }

        return $this->tiers()->filter(fn (SupporterTier $t) => (int) $t->minimum_accounting_amount_minor <= $netMinor)->last();
    }

    public function forSupporter(SupporterProfile|int $supporter): ?SupporterTier
    {
        return $this->forAmount($this->totals->net($supporter instanceof SupporterProfile ? $supporter->id : $supporter));
    }

    /** @param list<int> $supporterIds @return array<int, ?SupporterTier> */
    public function forMany(array $supporterIds): array
    {
        $net = $this->totals->netMany($supporterIds);
        $out = [];

        foreach ($supporterIds as $id) {
            $out[$id] = $this->forAmount($net[$id] ?? 0);
        }

        return $out;
    }
}
`````

### `app/Support/SafeUrl.php`

`sha256: 0d030200132b59a72f6ec3f329209f59f9231ce33ad87fe456849b5104b4dc20`

`````php
<?php

namespace App\Support;

/**
 * E30 - تطبيع روابط المواقع الخارجية (موقع داعم). http/https فقط؛ لا بيانات اعتماد داخل الرابط (user:pass@ خدعة تصيّد شائعة)، لا مضيف فارغ ولا IP ولا localhost،
 * لا محارف تحكم أو مسافات، ولا منافذ غير قياسية. النتيجة سلسلة مُعاد بناؤها من أجزاء مُحقَّقة (المضيف بصيغة ASCII/punycode). null = رابط مرفوض.
 * العرض دائمًا مع rel="noopener noreferrer sponsored" وtarget=_blank، والرابط لا يُنفَّذ كـHTML أبدًا (Blade المهرَّب).
 */
final class SafeUrl
{
    public static function normalize(?string $url, int $maxLength = 255): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || mb_strlen($url) > $maxLength || preg_match('/[\x00-\x20\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTF46);
            $host = is_string($converted) ? $converted : '';
        }

        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return null;
        }

        $rebuilt = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return mb_strlen($rebuilt) <= $maxLength ? $rebuilt : null;
    }
}
`````

### `app/View/Components/SponsorStrip.php`

`sha256: c1da5df6856bcca6e78fed7217b1614c63f0376cae2d70aa7749e0f4d0b18a89`

`````php
<?php

namespace App\View\Components;

use App\Services\Sponsorship\SponsorDisplay;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * E30 - شريط «برعاية» المنظَّم على صفحات الموسم/الحملة/المنافسة/المنصة. بيانات منظَّمة (اسم/شعار/رابط آمن) من SponsorDisplay؛ لا علاقة بمحرّك إعلانات E14.
 * معزول الفشل: أي استثناء ⇒ لا شريط (صفحات الهدف الرسمية لا تتعطل بالرعاية). بلا رعاة ⇒ صفر إخراج.
 */
class SponsorStrip extends Component
{
    /** @var array{main: ?array<string, mixed>, supporting: list<array<string, mixed>>, any: bool} */
    public array $sponsors;

    public function __construct(public string $type, public ?int $targetId = null)
    {
        try {
            $this->sponsors = app(SponsorDisplay::class)->forTarget($type, $targetId);
        } catch (\Throwable $e) {
            report($e);
            $this->sponsors = ['main' => null, 'supporting' => [], 'any' => false];
        }
    }

    public function render(): View
    {
        return view('components.sponsor-strip');
    }
}
`````

### `config/creator_series.php`

`sha256: 977a5905dce7d57108de6012d797e22e33ca6d51f6749c019ffc17c13f178111`

`````php
<?php

/*
| E30 (B) - سلاسل المبدعين: قوائم أحجيات مجتمع مرتّبة. محتوى مجتمع بلا أي عائد اقتصادي (لا جواهر/XP/مهام/سلسلة/صدارة/حملات)،
| ولا يتأثر أبدًا بتعزيز الرعاية. القيم قابلة للضبط بالبيئة.
*/
return [
    'enabled' => (bool) env('CREATOR_SERIES_ENABLED', true),

    'limits' => [
        'min_items' => (int) env('CREATOR_SERIES_MIN_ITEMS', 2),
        'max_items' => (int) env('CREATOR_SERIES_MAX_ITEMS', 25),
        'title_min' => 3,
        'title_max' => 100,
        'description_max' => 600,
        'slug_max' => 80,
        // سقف عدد السلاسل غير المؤرشفة للمبدع الواحد (حماية من الإغراق).
        'max_per_creator' => (int) env('CREATOR_SERIES_MAX_PER_CREATOR', 50),
        // سقف السلاسل قيد المراجعة للمبدع الواحد (مثل سقف أحجياته) كي لا تُغرَق قائمة المراجعة.
        'max_pending' => (int) env('CREATOR_SERIES_MAX_PENDING', 5),
        'note_min' => 5,
        'note_max' => 500,
    ],

    // حقول سلطة/اقتصاد/ظهور لا يحدّدها المبدع أبدًا؛ وجودها بالطلب يُرفض صراحة (لا يُتجاهل).
    'forbidden_fields' => [
        'status', 'creator_profile_id', 'slug', 'public_id', 'latest_revision_id', 'published_revision_id', 'published_title', 'published_items_count', 'published_at',
        'first_published_at', 'archived_at', 'hidden_at', 'hidden_by', 'hidden_reason', 'hidden_note', 'reviewed_by', 'reviewed_at', 'review_note', 'cover_path', 'is_visible',
        'gem_reward', 'xp_reward', 'reward', 'reward_currency_id', 'boost', 'sponsor_id', 'supporter_id', 'sponsorship_program_id', 'is_sponsored', 'is_official',
        'campaign_id', 'season_id', 'competitive_event_id', 'challenge_id',
    ],

    'page_size' => 12,
    'studio_page_size' => 15,
    'moderation_page_size' => 20,

    // غلاف السلسلة اختياري ويستعمل خدمة وسائط المبدعين نفسها (JPEG/PNG/WEBP فقط).
    'cover' => ['enabled' => true],

    'discovery' => [
        'section_limit' => 6,
        'per_creator_cap' => 2,
        'pool' => 30,
        // كاش معرّفات «أحدث السلاسل» فقط (الظهور يُفحص حيًّا بكل عرض؛ الكاش ليس سلطة).
        'cache_ttl' => (int) env('CREATOR_SERIES_DISCOVERY_TTL', 120),
    ],

    'analytics' => [
        'cache_ttl' => (int) env('CREATOR_SERIES_ANALYTICS_TTL', 600),
    ],

    'notifications' => [
        'on_new_series' => (bool) env('CREATOR_SERIES_NOTIFY_FOLLOWERS', true),
        // إشعار المتابعين بنسخة جديدة معتمدة لسلسلة نُشرت سابقًا (مثل engagement.notifications.on_revision).
        'on_revision' => (bool) env('CREATOR_SERIES_NOTIFY_ON_REVISION', true),
    ],
];
`````

### `config/sponsorship.php`

`sha256: c3aa77626e4f4c6217e9f2f10cd736fff3ca56fb723421d3260ed8e1fcd10511`

`````php
<?php

/*
| E30 (A) - الرعاة والداعمون وتمويل المكافآت. كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار ولا بيانات دفع.
| لا بوابة دفع: المساهمات تُسجَّل وتُؤكَّد يدويًا من الإدارة. المبالغ أعداد صحيحة بالوحدة الصغرى (cents/kuruş) بلا floats.
| لا سعر صرف حيّ: المدير يدخل المبلغ الأصلي وعملته ثم المبلغ المعادل بعملة المحاسبة عند التأكيد (يُدقَّق).
*/
return [
    'enabled' => (bool) env('SPONSORSHIP_ENABLED', true),

    // عملة المحاسبة الموحَّدة التي تُقاس بها كل المجاميع والمستويات والتعزيز.
    'accounting_currency' => strtoupper((string) env('SPONSORSHIP_ACCOUNTING_CURRENCY', 'USD')),

    // قائمة العملات المسموحة لتسجيل المساهمة (المفتاح ISO-4217) وأسّ الوحدة الصغرى. أي عملة خارجها ترفضها الخدمة.
    'currencies' => [
        'USD' => ['symbol' => '$', 'exponent' => 2, 'label' => 'دولار أمريكي'],
        'EUR' => ['symbol' => '€', 'exponent' => 2, 'label' => 'يورو'],
        'TRY' => ['symbol' => '₺', 'exponent' => 2, 'label' => 'ليرة تركية'],
        'SAR' => ['symbol' => 'ر.س', 'exponent' => 2, 'label' => 'ريال سعودي'],
        'AED' => ['symbol' => 'د.إ', 'exponent' => 2, 'label' => 'درهم إماراتي'],
        'EGP' => ['symbol' => 'ج.م', 'exponent' => 2, 'label' => 'جنيه مصري'],
    ],

    // سقف المبلغ الواحد بالوحدة الصغرى (حماية من أخطاء الإدخال): 10 ملايين وحدة رئيسية.
    'max_amount_minor' => (int) env('SPONSORSHIP_MAX_AMOUNT_MINOR', 1_000_000_000),

    'profile' => [
        'name_max' => 80,
        'description_max' => 600,
        'url_max' => 255,
        'reference_max' => 120,
        'notes_max' => 1000,
    ],

    // شعارات الداعمين: JPEG/PNG/WEBP فقط بنوع المحتوى الفعلي (لا SVG). اسم عشوائي يولّده المخدّم.
    'logo' => [
        'disk' => env('SPONSORSHIP_LOGO_DISK', 'public'),
        'directory' => 'supporters',
        'max_kb' => (int) env('SPONSORSHIP_LOGO_MAX_KB', 1024),
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    ],

    'public' => [
        'page_size' => 12,
        'top_limit' => 20,
        'recent_limit' => 12,
        'organizations_limit' => 12,
        // عمق الجلب من الكاش قبل إعادة فحص الظهور الحيّ (هامش لمن قد يُخفون بعد تخزين اللقطة).
        'overfetch' => 10,
        'cache_ttl' => (int) env('SPONSORSHIP_CACHE_TTL', 120),
        'display_ttl' => (int) env('SPONSORSHIP_DISPLAY_TTL', 60),
        'supporting_sponsors_limit' => 8,
    ],

    // تعزيز المكافآت الرسمية. الصيغة: steps = floor(funding / step)؛ boost_bps = min(steps * per_step, max).
    // الأرقام بالنقاط الأساسية (bps): 100 = 1%. السقف الصلب أدناه لا يتجاوزه أي برنامج مهما ضُبط.
    'reward' => [
        'enabled' => (bool) env('SPONSORSHIP_REWARD_BOOST_ENABLED', true),
        'bps_denominator' => 10000,
        'hard_max_bps' => (int) env('SPONSORSHIP_HARD_MAX_BOOST_BPS', 5000),
        'default_step_minor' => 10000,
        'default_per_step_bps' => 50,
        'default_max_bps' => 500,
    ],

    // قفل خطة المنافسة قبل بدئها: يقفلها الأمر المجدول لكل منافسة تبدأ خلال هذه المدة (دقائق) وما زالت قابلة للتعديل.
    'events' => [
        'lock_lead_minutes' => (int) env('SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES', 60),
    ],

    'analytics' => [
        'cache_ttl' => 300,
    ],

    'notifications' => [
        'enabled' => (bool) env('SPONSORSHIP_NOTIFICATIONS_ENABLED', true),
    ],
];
`````

### `database/seeders/DemoCreatorSeriesSeeder.php`

`sha256: 747a24d80b7b1f0742c3a61b69368a71cfd15b0cdc990e06ce40f7b707201ff4`

`````php
<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

/**
 * E30 (ب) - بيانات Demo لسلاسل المبدعين.   php artisan db:seed --class=DemoCreatorSeriesSeeder
 *
 * - اختيارية وترفض الإنتاج. **تعتمد على DemoCreatorSeeder** (تستدعيه إن لم يوجد مبدعها) ولا تُستدعى من DemoQaSeeder.
 * - **بالخدمات الرسمية فقط** (مسودة، إرسال، اعتماد): سلسلتان منشورتان وواحدة قيد المراجعة وواحدة مسودة. لا مكافآت ولا نقاط ولا أي أثر اقتصادي بالبناء.
 * - **Idempotent**: السلسلة بعنوانها لكل مبدع. مستقلة تمامًا عن DemoSponsorshipSeeder.
 */
class DemoCreatorSeriesSeeder extends Seeder
{
    use RefusesProduction;

    public const CREATOR_EMAIL = 'demo-creator-nour@creators.test';

    public const REVIEWER_EMAIL = 'demo-reviewer@creators.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoCreatorSeriesSeeder');

        $creator = User::query()->where('email', self::CREATOR_EMAIL)->first();

        if ($creator === null || ! CreatorProfile::query()->where('user_id', $creator->id)->where('status', CreatorProfile::STATUS_APPROVED)->exists()) {
            $this->call(DemoCreatorSeeder::class);
            $creator = User::query()->where('email', self::CREATOR_EMAIL)->firstOrFail();
        }

        $reviewer = User::query()->where('email', self::REVIEWER_EMAIL)->firstOrFail();
        $profile = CreatorProfile::query()->where('user_id', $creator->id)->firstOrFail();

        $works = CreatorPuzzle::query()->visible()->where('creator_profile_id', $profile->id)->orderBy('id')->pluck('public_id', 'published_title');

        $pick = fn (array $titles): array => array_values(array_filter(array_map(fn ($t) => $works[$t] ?? null, $titles)));

        $series = app(CreatorSeriesService::class);
        $review = app(CreatorSeriesReviewService::class);

        $plan = [
            ['ألغاز القلم والكواكب', 'مجموعة قصيرة من ألغاز نور: كلمات وفلك.', ['ما الذي يكتب ولا يقرأ؟', 'أي هذه الكواكب أكبر؟'], 'published'],
            ['رحلة الفصول والألغاز', 'ثلاثة ألغاز خفيفة بترتيب مقصود.', ['ما الذي يكتب ولا يقرأ؟', 'أي هذه الكواكب أكبر؟', 'رتّب فصول السنة'], 'published'],
            ['سلسلة قيد المراجعة', 'سلسلة أُرسلت وتنتظر قرار الإشراف.', ['أي هذه الكواكب أكبر؟', 'رتّب فصول السنة'], 'pending'],
            ['مسودة سلسلة', 'مسودة لم تُرسل بعد.', ['ما الذي يكتب ولا يقرأ؟', 'رتّب فصول السنة'], 'draft'],
        ];

        foreach ($plan as [$title, $description, $titles, $state]) {
            $items = $pick($titles);

            if (count($items) < 2) {
                $this->command?->warn("DemoCreatorSeriesSeeder: لا أعمال منشورة كافية لسلسلة «{$title}».");

                continue;
            }

            $revision = CreatorSeriesRevision::query()->where('title', $title)->whereHas('series', fn ($q) => $q->where('creator_profile_id', $profile->id))->first()
                ?? $series->createDraft($creator, ['title' => $title, 'description' => $description, 'items' => $items]);

            if ($state !== 'draft' && $revision->status === CreatorSeriesRevision::STATUS_DRAFT) {
                $revision = $series->submit($creator, $revision->series()->value('public_id'));
            }

            if ($state === 'published' && $revision->status === CreatorSeriesRevision::STATUS_PENDING_REVIEW) {
                $review->approve($revision, $reviewer, 'معتمدة للعرض التجريبي.');
            }
        }

        $this->command?->info('DemoCreatorSeriesSeeder: سلاسل ('.\App\Models\CreatorSeries::query()->count().').');
    }
}
`````

### `database/seeders/DemoE30Seeder.php`

`sha256: 0439abd4c83c0b680a4c032255ed81c34c0a8f0cf70184e498de371de7aaa1fb`

`````php
<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

/**
 * E30 - يشغّل بيانات Demo للوحدتين المستقلتين معًا: الرعاية والداعمون، وسلاسل المبدعين.   php artisan db:seed --class=DemoE30Seeder
 * اختيارية وترفض الإنتاج. كل وحدة تعمل منفردة (DemoSponsorshipSeeder / DemoCreatorSeriesSeeder) ولا تستدعي إحداهما الأخرى.
 */
class DemoE30Seeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction('DemoE30Seeder');
        $this->call(DemoSponsorshipSeeder::class);
        $this->call(DemoCreatorSeriesSeeder::class);
    }
}
`````

### `database/seeders/DemoSponsorshipSeeder.php`

`sha256: 5b3ce559a2a926086d900c9baff2ac5052af822ab789b22a4ac2981b8ac4b81a`

`````php
<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E30 (أ) - بيانات Demo للرعاية والداعمين.   php artisan db:seed --class=DemoSponsorshipSeeder
 *
 * - اختيارية وترفض الإنتاج. مستقلة عن DemoQaSeeder (أرقام Demo المثبَّتة بالاختبارات لا تتغير) وعن سلاسل المبدعين.
 * - **بالخدمات الرسمية فقط** (ملفات داعمين، برامج، تسجيل/تأكيد/استرداد مساهمات، تعيين الراعي الرئيسي): لا كتابة مباشرة بحالة أو مبلغ.
 * - **Idempotent**: ملف الداعم بالاسم، البرنامج بالعنوان، المساهمة بمرجعها (DEMO-E30-xx). إعادة التشغيل لا تضاعف شيئًا.
 * - لا تفعّل خطة تعزيز المكافآت: عرض الرعاية لا يغيّر اقتصاد المكافآت في بيئة العرض. (فعّلها من لوحة الإدارة إن أردت رؤية التعزيز.)
 * - الحساب الإداري والحساب المرتبط بداعم بكلمة مرور معروفة (.test): للبيئات المحلية/الاختبارية فقط.
 */
class DemoSponsorshipSeeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-sponsorship-admin@sponsorship.test';

    public const SUPPORTER_EMAIL = 'demo-supporter-ahmad@sponsorship.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoSponsorshipSeeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الرعاية التجريبي');
        $admin->syncRoles(['administrator']);
        $linked = $this->user(self::SUPPORTER_EMAIL, 'أحمد الداعم');

        $supporters = app(SupporterService::class);
        $programs = app(SponsorshipProgramService::class);
        $contributions = app(ContributionService::class);

        // 1 شركة راعٍ رئيسي + 2 مؤسستان داعمتان + 3 أفراد + 1 داعم يفضّل عدم الظهور.
        $people = [
            'main' => $this->supporter($supporters, $admin, ['display_name' => 'شركة النخبة للتقنية', 'type' => 'organization', 'description' => 'شركة تقنية تدعم المحتوى العربي الذكي.', 'website_url' => 'https://example.com', 'is_public' => true]),
            'org1' => $this->supporter($supporters, $admin, ['display_name' => 'مؤسسة الأفق التعليمية', 'type' => 'organization', 'description' => 'مؤسسة تعليمية داعمة للتفكير والمعرفة.', 'is_public' => true]),
            'org2' => $this->supporter($supporters, $admin, ['display_name' => 'جمعية دعم الابتكار', 'type' => 'organization', 'is_public' => true]),
            'ahmad' => $this->supporter($supporters, $admin, ['display_name' => 'أحمد الداعم', 'type' => 'individual', 'is_public' => true, 'user_id' => $linked->id]),
            'sara' => $this->supporter($supporters, $admin, ['display_name' => 'سارة الداعمة', 'type' => 'individual', 'is_public' => true]),
            'khaled' => $this->supporter($supporters, $admin, ['display_name' => 'خالد المحب', 'type' => 'individual', 'is_public' => true]),
            'anon' => $this->supporter($supporters, $admin, ['display_name' => 'داعم كريم', 'type' => 'individual', 'is_public' => true, 'anonymous_by_default' => true]),
        ];

        $platform = $this->program($programs, $admin, 'دعم منصة أحجيات', ['target_type' => 'platform', 'description' => 'دعم عام لتشغيل المنصة وتطويرها.', 'goal_amount' => '5000', 'show_public_amounts' => true, 'show_goal_progress' => true]);
        $season = $this->program($programs, $admin, 'رعاية الموسم التجريبي', ['target_type' => 'season', 'target_id' => $this->season()->id, 'description' => 'برنامج رعاية مرتبط بموسم تجريبي.', 'goal_amount' => '1000', 'show_public_amounts' => true, 'show_goal_progress' => true]);

        // [مرجع، الداعم، البرنامج، المبلغ، الحالة، مجهول؟]
        $rows = [
            ['DEMO-E30-01', 'main', $platform, '2500', 'confirmed', null],
            ['DEMO-E30-02', 'org1', $platform, '600', 'confirmed', null],
            ['DEMO-E30-03', 'org2', $platform, '150', 'confirmed', null],
            ['DEMO-E30-04', 'ahmad', $platform, '40', 'confirmed', null],
            ['DEMO-E30-05', 'sara', $platform, '12', 'confirmed', null],
            ['DEMO-E30-06', 'khaled', $platform, '25', 'pending', null],
            ['DEMO-E30-07', 'anon', $platform, '80', 'confirmed', true],
            ['DEMO-E30-08', 'khaled', $platform, '30', 'refunded', null],
            ['DEMO-E30-09', 'main', $season, '500', 'confirmed', null],
            ['DEMO-E30-10', 'org1', $season, '200', 'confirmed', null],
            ['DEMO-E30-11', 'sara', $season, '20', 'pending', null],
        ];

        foreach ($rows as [$reference, $who, $program, $amount, $state, $anonymous]) {
            $this->contribution($contributions, $admin, $people[$who], $program, $reference, $amount, $state, $anonymous);
        }

        // الراعي الرئيسي لبرنامجي المنصة والموسم، وراعٍ داعم واحد (يُعيَّن فقط من له مساهمة مؤكَّدة).
        $this->role($programs, $admin, $platform, $people['main'], SponsorshipProgramRole::ROLE_MAIN_SPONSOR);
        $this->role($programs, $admin, $season, $people['main'], SponsorshipProgramRole::ROLE_MAIN_SPONSOR);
        $this->role($programs, $admin, $platform, $people['org1'], SponsorshipProgramRole::ROLE_SPONSOR);

        $this->command?->info('DemoSponsorshipSeeder: داعمون ('.SupporterProfile::query()->count().')، برامج ('.SponsorshipProgram::query()->count().')، مساهمات ('.SponsorshipContribution::query()->count().'). كلمة مرور الحسابات التجريبية: '.self::PASSWORD);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    protected function supporter(SupporterService $service, User $admin, array $data): SupporterProfile
    {
        return SupporterProfile::query()->where('display_name', $data['display_name'])->first() ?? $service->create($admin, $data);
    }

    protected function program(SponsorshipProgramService $service, User $admin, string $title, array $data): SponsorshipProgram
    {
        $program = SponsorshipProgram::query()->where('title', $title)->first();

        if ($program === null) {
            $program = $service->create($admin, ['title' => $title] + $data);
        }

        return $program->status === SponsorshipProgram::STATUS_DRAFT ? $service->activate($admin, $program) : $program;
    }

    /** يعيّن الدور فقط إن لم يكن قائمًا (إعادة التشغيل لا تُنتج تدقيقًا مكررًا). */
    protected function role(SponsorshipProgramService $service, User $admin, SponsorshipProgram $program, SupporterProfile $supporter, string $role): void
    {
        $exists = SponsorshipProgramRole::query()->where('sponsorship_program_id', $program->id)->where('supporter_profile_id', $supporter->id)->where('role', $role)->exists();

        if (! $exists) {
            $service->assignRole($admin, $program, $supporter, $role);
        }
    }

    protected function contribution(ContributionService $service, User $admin, SupporterProfile $supporter, SponsorshipProgram $program, string $reference, string $amount, string $state, ?bool $anonymous): void
    {
        $contribution = SponsorshipContribution::query()->where('reference', $reference)->first()
            ?? $service->record($admin, $supporter, $program, $amount, 'USD', ['reference' => $reference, 'notes' => 'مساهمة تجريبية للعرض فقط.', 'is_anonymous' => $anonymous]);

        if ($state !== 'pending' && $contribution->status === SponsorshipContribution::STATUS_PENDING) {
            $contribution = $service->confirm($admin, $contribution);
        }

        if ($state === 'refunded' && $contribution->status === SponsorshipContribution::STATUS_CONFIRMED) {
            $service->refund($admin, $contribution, 'استرداد تجريبي لعرض الحالة بالواجهة.');
        }
    }

    /** أول موسم قائم، وإلا يُنشأ موسم تجريبي صغير (حملة + موسم) بالمصانع - للعرض المحلي فقط. */
    protected function season(): Season
    {
        return Season::query()->orderBy('id')->first()
            ?? Season::factory()->create(['campaign_id' => Campaign::factory()->create(['is_active' => true])->id, 'is_published' => true]);
    }
}
`````

### `docs/creator-series-e30.md`

`sha256: 9017fd0fc5a92c08bdc79dc4ac9c3240b1e6e9f988c84d8ac1b0d9d45a36215a`

`````markdown
# سلاسل المبدعين (E30 - الوحدة ب)

> سلسلة = قائمة **مرتّبة** (2–25 عنصرًا افتراضيًا) من أحجيات **مجتمع** منشورة وظاهرة لنفس المبدع، بمراجعة إشرافية، وصفحة عامة `/series/{public_id|slug}`.
> **محتوى مجتمع بلا أي عائد اقتصادي**: لا جواهر ولا XP ولا مهام ولا سلسلة أيام (streak) ولا صدارة رسمية ولا حملات ولا منافسات، **ولا يتأثر بتعزيز الرعاية أبدًا**.
> **بلا حزمة Composer أو npm جديدة** (هجرة جديدة واحدة). مستقلة عن [الرعاية](sponsorship-supporters-e30.md): فشل أحدهما لا يكسر الآخر.

## النموذج

```
creator_series (الهوية والنشر)  ──1:N──  creator_series_revisions (نسخ قابلة للمراجعة)  ──1:N──  creator_series_items (الأحجيات بمواضع صريحة)
```

- الملكية لـ**ملف المبدع** (`creator_profile_id`) لا للمستخدم. المنشور هو لقطة النسخة المعتمدة (`published_revision_id`، العنوان، الغلاف، عدد العناصر، تاريخ النشر).
- **تعديل سلسلة منشورة ينشئ نسخة جديدة قابلة للمراجعة**؛ المنشور يبقى ظاهرًا بالنسخة القديمة حتى اعتماد الجديدة.
- الحالات: `draft | pending_review | changes_requested | approved (منشورة) | rejected` + أرشفة (`archived_at`) وإخفاء إشرافي (`hidden_at`).
- العنصر: عمل مجتمع **منشور وظاهر لنفس المبدع** (فريد داخل النسخة، موضع فريد). أي معرّف غريب أو رسمي أو وهمي يُرجِع الرسالة العامة نفسها «ليست من أعمالك» (بلا تسريب وجود). عنصر مخفي/مؤرشف/غير منشور رسالته محددة لأنه ملك المبدع.
- العنوان والوصف **نص عادي** (قاعدة `NoMarkup`) ويُهرَّب عند العرض. الغلاف اختياري عبر `CreatorMediaService` نفسها (JPEG/PNG/WEBP فقط).
- الرابط: `slug` ثابت لا يتغير (`عنوان-مختصر-` + آخر 8 محارف من ULID) و`public_id` (ULID). البحث العام: ULID صالح ⇒ `public_id`، وإلا `slug`.
- **حدود قابلة للضبط**: `min_items`، `max_items`، `max_per_creator` (50)، `max_pending` (5). الحقول المحظورة (`forbidden_fields`: الحالة، الملكية، المكافآت، التعزيز، الرعاة...) **تُرفض صراحة** بدل تجاهلها.

## الظهور — قاعدة مركزية حيّة (`CreatorSeriesVisibilityService`)

السلسلة ظاهرة للعامة **فقط** إذا اجتمع:

1. لها نسخة منشورة وعدد عناصرها > 0؛
2. غير مخفية إشرافيًا وغير مؤرشفة؛
3. ملف المبدع **معتمَد** وحسابه **غير مجمَّد**؛
4. **كل** عناصر النسخة المنشورة ظاهرة الآن (لا عنصر مفقود ولا `is_visible=0`).

الحساب **حيّ** باستعلام واحد بشروط فرعية (`EXISTS / NOT EXISTS`): لا عمود `is_visible` مخزَّن، ولا مصالحة (reconcile)، ولا كاش يخصّ الظهور. إخفاء أحجية بواسطة الإشراف أو تجميد المبدع أو تعليقه يُخفي السلسلة **فورًا** من الصفحة العامة والبحث والاكتشاف وملف المبدع والمتابعة. استعادة العنصر تعيدها.

أسباب عدم الظهور (`blockers`) تظهر للمبدع في الاستوديو وللمراجع في اللوحة، لا للعامة.

## الاستوديو (للمبدع المعتمد)

`/creator/series` (قائمة بأقسام + ترقيم)، إنشاء، تعديل، إرسال للمراجعة، سحب، مراجعة نسخة جديدة، أرشفة/إلغاء أرشفة، وحذف المسودة (مسودة سلسلة جديدة أو مسودة نسخة قيد التحضير لسلسلة منشورة؛ السلسلة **المنشورة نفسها لا تُحذف بل تُؤرشَف**، وما هو قيد المراجعة يُسحب أولًا). منشئ عناصر بأزرار أعلى/أسفل/إزالة وحقول `items[]` مخفية؛ الخادم هو السلطة (يعيد التحقق عند الحفظ وعند الإرسال وعند الاعتماد). كل تعديل حالة بـ`POST/PUT/DELETE` + CSRF + `perform()`؛ لا تعديل بـGET. مسارات الإنشاء/الإرسال محدودة المعدّل (`creator-draft-create`، `creator-submit`).

## الإشراف (Filament — «مراجعة سلاسل المجتمع»)

- الصف = نسخة مُرسلة (المسودات خاصة بالمبدع ولا تصل المراجع). القائمة مرقّمة بحجم ثابت (`moderation_page_size`) وعدد العناصر غير الظاهرة يُحسب باستعلام فرعي واحد ⇒ **بلا N+1**.
- إجراءات: اعتماد / طلب تعديلات (سبب إلزامي) / رفض (سبب إلزامي) / إخفاء السلسلة / استعادتها / إخفاء أحجية (عنصر) / استعادتها. إخفاء العنصر يمرّ بـ`CreatorVisibilityService` نفسها (E28) ويتحقق أن العمل ضمن عناصر السلسلة فعلًا (لا إخفاء عشوائي بقيم مزوَّرة).
- صفحة التفاصيل تعرض **ظهور كل عنصر على حدة**، ولا تعرض أي إجابة.
- كلها بصلاحية `creators.review` وبتدقيق (`creator_series_approved / changes_requested / rejected / hidden / restored`) وإشعار للمبدع. `creators.view` للعرض فقط.

## اللعب والتقدّم

اللعب يتم **عبر صفحة أحجية المجتمع نفسها** (E28/E29) بلا أي أثر اقتصادي. صفحة السلسلة تعرض تقدّم اللاعب (محلولة/غير محلولة، شريط `aria-valuenow`) المشتق من `community_puzzle_attempts` باستعلام واحد محدود بالعناصر، وزر «تابع» يقود لأول عنصر غير محلول. سياق `?series=` على صفحة الأحجية يعرض الموضع والسابق والتالي (يعمل فقط عند وجود المعامل ويتحقق أن العمل ضمن السلسلة الظاهرة).

## البحث والاكتشاف والمتابعة

- **البحث:** نوع جديد `creator_series` («سلاسل المجتمع») بعنوان المنشور فقط، بقاعدة الظهور المركزية.
- **الاكتشاف:** قسمان «سلاسل من المجتمع» و«سلاسل جديدة ممّن تتابعهم»، سقف 2 لكل مبدع و6 للقسم. فشل القسم لا يكسر مركز الاكتشاف (`try/catch + report`).
- **ملف المبدع العام:** قسم «سلاسله» بالظاهر فقط.
- **المتابعون:** إشعار `creator_new_series` عند نشر سلسلة جديدة (وعند نسخة جديدة لسلسلة منشورة إن فُعّل `on_revision`) عبر `DispatchCreatorSeriesChunk` على **دفعات** (chunked fan-out) و**idempotent** بمفتاح `creator-series:{revision}:{user}`. فشل الإشعار **لا يتراجع النشر**.

## التحليلات المجمَّعة للمبدع

لاعبون، بدايات (أزواج لاعب-عنصر مميزة)، إكمالات، نسبة الإكمال — باستعلامين مجمَّعين، **كاش 600ث لكل ملف مبدع** (`creator-series:analytics:{profileId}`). **لا هويات لاعبين ولا تتبّع مشاهدات** (لا عمود ولا جدول مشاهدات).

## الأداء والفهارس والكاش

- فهارس صريحة: `creator_series_public_id_unique`، `creator_series_slug_unique`، `creator_series_owner_index`، `creator_series_creator_public_index`، `creator_series_public_index`، `creator_series_title_index`، `creator_series_revisions_queue_index`، `creator_series_items_work_unique`، `creator_series_items_position_unique`، `creator_series_items_work_index` — مقيسة بـ`EXPLAIN QUERY PLAN` في اختبارات `CreatorSeriesE30`.
- **عدد الاستعلامات لا يتبع عدد العناصر** (5 مقابل 25 عنصرًا: نفس العدد في صفحة السلسلة والاستوديو والاكتشاف والبحث وملف المبدع).
- **الكاش**: فقط (أ) معرّفات «أحدث السلاسل» للاكتشاف (`discover.series.newest`، TTL = `CREATOR_SERIES_DISCOVERY_TTL` 120ث، **معرّفات فقط** بلا عناوين) و(ب) التحليلات المجمَّعة. **الظهور يُفحص حيًّا بكل عرض فالكاش ليس سلطة**؛ معرّف مخزَّن لسلسلة لم تعد ظاهرة لا يعيد إظهارها.

## إعدادات البيئة

```
CREATOR_SERIES_ENABLED=true
CREATOR_SERIES_MIN_ITEMS=2
CREATOR_SERIES_MAX_ITEMS=25
CREATOR_SERIES_MAX_PER_CREATOR=50
CREATOR_SERIES_MAX_PENDING=5
CREATOR_SERIES_DISCOVERY_TTL=120
CREATOR_SERIES_ANALYTICS_TTL=600
CREATOR_SERIES_NOTIFY_FOLLOWERS=true
CREATOR_SERIES_NOTIFY_ON_REVISION=true
```

`CREATOR_SERIES_ENABLED=false` يعطّل مسارات الاستوديو والصفحة العامة (404) ويخفي الأقسام دون مساس بأحجيات المجتمع.

## بيانات Demo

```
php artisan db:seed --class=DemoCreatorSeriesSeeder     # السلاسل فقط (يستدعي DemoCreatorSeeder إن لزم)
php artisan db:seed --class=DemoE30Seeder               # الرعاية + السلاسل
```

سلسلتان منشورتان وواحدة قيد المراجعة وواحدة مسودة للمبدعة التجريبية. **يرفض الإنتاج** و**idempotent** (السلسلة بعنوانها لكل مبدع) ويعمل بالخدمات الرسمية فقط.

## حدود مقبولة موثَّقة

- حذف أحجية مجتمع (إن سُمح به مستقبلًا) قد يُبقي `published_items_count` قديمًا إلى أن تُراجَع السلسلة؛ لكن **الظهور لا يتأثر** لأن القاعدة الحيّة تستبعد أي سلسلة فيها عنصر مفقود.
- الربط العكسي «هذه الأحجية ضمن سلسلة كذا» على صفحة الأحجية مؤجَّل؛ المنفَّذ سياق `?series=` فقط.
- مؤجَّل عمدًا: تقييم/مفضلة/مجموعات للسلاسل، سلاسل ممولة/برعاية، أي تسييل.
`````

### `docs/sponsorship-supporters-e30.md`

`sha256: 3f9049be9e34299c6d1174ebe0d5a8a9bff6211b91a344f4d6e145c47b919d1c`

`````markdown
# الرعاة والداعمون وتمويل المكافآت (E30 - الوحدة أ)

> منظومة رعاية **رسمية** للمنصة: ملفات داعمين (أفراد/مؤسسات)، برامج رعاية لأهداف رسمية، مساهمات تُسجَّل وتُؤكَّد يدويًا، مستويات مشتقّة، لوحات شرف علنية، راعٍ رئيسي، وتعزيز اختياري لمكافآت المحتوى الرسمي.
> **بلا بوابة دفع** ولا بيانات دفع ولا KYC ولا تحويل آلي. **بلا حزمة Composer أو npm جديدة** (هجرات جديدة فقط، لا تعديل لأي هجرة قديمة).
> مستقلة تمامًا عن [سلاسل المبدعين](creator-series-e30.md): تعطّل إحداهما لا يكسر الأخرى. سلاسل المبدعين والمجتمع **لا تتأثر بالرعاية أبدًا** (لا تعزيز، لا أهداف).

## المبادئ الصلبة

1. **المبالغ أعداد صحيحة بالوحدة الصغرى** (cents/kuruş). لا floats في أي مسار (`SponsorshipMoney`). السقف الواحد `SPONSORSHIP_MAX_AMOUNT_MINOR`.
2. **لا سعر صرف حيّ.** المدير يدخل المبلغ الأصلي وعملته، ثم عند التأكيد المعادل بعملة المحاسبة (`SPONSORSHIP_ACCOUNTING_CURRENCY`، افتراضيًا USD). كل المجاميع والمستويات والتعزيز تُقاس بالمعادل المحاسبي المؤكَّد فقط.
3. **المعلّق والملغى لا يُحتسبان.** المؤكَّد وحده يدخل المجاميع. الاسترداد يُخرج المساهمة من الصافي ويُبقيها تاريخًا.
4. **المستوى مشتقّ دائمًا** من صافي المؤكَّد (`SupporterTierService`): لا يُخزَّن على الداعم ولا يُقبل من العميل. الاسترداد يخفّضه آليًا. المستويات تُدار من قاعدة البيانات (`supporter_tiers`).
5. **الأهداف قائمة بيضاء مغلقة**: `platform | season | campaign | competitive_event` — كلها محتوى رسمي. أحجيات المجتمع وسلاسل المبدعين وملفات المبدعين **ليست أهدافًا** (`SponsorshipTargets`).
6. **الراعي الرئيسي واحد لكل برنامج بقيد قاعدة بيانات** (`sponsorship_program_roles.main_marker` فريد). لا يُعيَّن راعيًا إلا من له مساهمة مؤكَّدة بالبرنامج.
7. **الخصوصية**: الداعم المجهول يظهر باسم «داعم مجهول» بلا ملف ولا رابط ولا شعار ولا مستوى، وتُحتسب مساهماته في المجاميع والتمويل دون نسبتها علنًا. المراجع والملاحظات الإدارية **لا تظهر خارج لوحة الإدارة** ولا في الإشعارات.
8. **الكاش ليس سلطة**: لا يُقرأ لتأكيد أو استرداد أو منح مكافأة (تلك من قاعدة البيانات حصرًا).

## الجداول (هجرتان جديدتان)

`2026_10_22_000001_create_sponsorship_tables` — سبعة جداول:

| الجدول | الغرض |
| --- | --- |
| `supporter_tiers` | مستويات الداعمين (اسم، حد أدنى بالوحدة الصغرى، ترتيب، شارة، فعّال). |
| `supporter_profiles` | ملف داعم (فرد/مؤسسة)، `user_id` اختياري وفريد، `slug` للرابط العام، `public_id` للإحالات الآمنة، شعار اختياري. |
| `sponsorship_programs` | برنامج رعاية لهدف رسمي + هدف تمويل + خطة تعزيز (خطوة/نقاط لكل خطوة/سقف). |
| `sponsorship_program_roles` | دور الداعم ضمن البرنامج: `supporter / sponsor / main_sponsor`. |
| `sponsorship_contributions` | المساهمة: الأصلي + العملة + المعادل المحاسبي + الحالة (`pending/confirmed/cancelled/refunded`) + مرجع/ملاحظات خاصة. |
| `sponsorship_reward_bonuses` | دفتر مكافآت الرعاية الممنوحة فعلًا (مفتاح مصدر فريد ⇒ لا منح مزدوج) بلقطة الأساس/النقاط/المكافأة/البرنامج. |
| `sponsorship_event_reward_plans` | لقطة خطة تعزيز منافسة مقفلة قبل بدئها. |

`2026_10_22_000002_add_sponsor_snapshot_to_competitive_reward_grants` — أعمدة لقطة التعزيز على جوائز المنافسات. لا حذف تتابعي للتاريخ المالي (`restrictOnDelete`). أسماء الفهارس صريحة (حد MySQL 64 حرفًا).

## تعزيز المكافآت (`SponsorshipRewardService` — البوابة الوحيدة)

```
steps     = floor(confirmed_funding / funding_step)
boost_bps = min(steps × boost_per_step_bps, max_boost_bps, hard_max_bps)     (100 bps = 1%)
bonus     = floor(base × boost_bps / 10000)
```

- **محتوى رسمي فقط.** أحجيات المجتمع (`origin=community`) وسلاسل المبدعين لا تمرّ هنا ولا تُمنح شيئًا؛ وتُرفض صراحة لو وصلت.
- **الأساس لا يُمسّ.** المكافأة الأساسية تُمنح بمنطقها القائم، والمكافأة الإضافية **معاملة منفصلة** بدفتر المحفظة (`CurrencyWalletService::creditPending`، معلّقة بفترة الحجز المعتادة)، بمفتاح مصدر فريد (idempotent).
- **الجواهر فقط.** لا XP ولا مهام ولا سلسلة ولا مستويات.
- **منصة/موسم/حملة:** التمويل المؤكَّد حيّ من قاعدة البيانات لحظة المنح، ولمنح مستقبلية فقط (لا أثر رجعي لتمويل أو استرداد لاحق). **سقف الكسب اليومي يُحترم** (تُقصّ المكافأة إلى المتبقي) وتقييم الاحتيال يعمل بعد الإيداع كالمعتاد.
- **المنافسات:** لقطة مقفلة قبل البدء (`EventRewardPlanService`، يقفلها الأمر المجدول `sponsorship:lock-event-plans` كل 5 دقائق لكل منافسة تبدأ خلال `SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES`، أو يدويًا مدير بصلاحية `sponsorships.reward_plan`). **لا لقطة = لا تعزيز (فشل مغلق).** مساهمة أو استرداد بعد القفل لا يغيّر الجوائز.
  - **قرار مقصود:** مكافأة المنافسة الإضافية **لا تُقصّ بالسقف اليومي** لأنها تتبع أصلها؛ الجائزة الأساسية للمنافسة نفسها خارج السقف اليومي أصلًا. أي قصّ للإضافة فقط كان سيجعلها غير متسقة مع أصلها.
- **السياقات التنافسية الأخرى** (تحدي صديق/فريق) وأي سياق مجهول ⇒ لا تعزيز (فشل مغلق).
- **الداعم لا يحصل على أفضلية لعب ولا مكافأة شخصية**: الحساب من تمويل البرنامج لا من هوية اللاعب.
- **عزل الفشل:** أي استثناء يُلتقط ويُبلَّغ (`report`) ويُعاد 0؛ لا يتعطل حل الأحجية ولا توزيع الجوائز بسبب الرعاية.
- **تعطيل شامل:** `SPONSORSHIP_ENABLED=false` أو `SPONSORSHIP_REWARD_BOOST_ENABLED=false` ⇒ لا تعزيز. السقف الصلب `SPONSORSHIP_HARD_MAX_BOOST_BPS` (5000 = 50%) لا يتجاوزه أي برنامج مهما ضُبط. بلا خطة مفعّلة على البرنامج ⇒ لا تعزيز.

## الواجهة العامة

| المسار | الوظيفة | حد المعدّل |
| --- | --- | --- |
| `GET /supporters` | لوحات الداعمين: الأعلى (كل الوقت/هذا العام)، الأحدث، المؤسسات، الراعي الرئيسي | `supporters-read` |
| `GET /supporters/{slug}` | ملف داعم علني (لا مبالغ، لا مراجع) | `supporters-read` |

- **ترتيب قطعي**: صافي المؤكَّد ثم أول تأكيد (الأقدم أولًا) ثم المعرّف. المبالغ الدقيقة لا تظهر إلا للوحة برنامج فعّل `show_public_amounts`.
- شارة «داعم · المستوى» على ملف اللاعب العام (`SupporterRecognitionService`): **شارة مشتقّة** وليست عنصرًا من متجر الهوية/Loadout (لا تُشترى ولا تلمس التجهيز)، وتظهر فقط لمن له ملف علني نشط ودعم مؤكَّد غير مجهول.
- شريط الرعاة على صفحات الموسم/الحملة/المنافسة/المنصة (`SponsorDisplay`): بيانات منظَّمة (اسم، شعار، رابط آمن) لا HTML حرًّا، **ولا علاقة له بمحرك إعلانات E14**. أي فشل ⇒ «لا رعاة» والصفحة الرسمية تعمل.
- الشعارات (`SupporterLogoService`): JPEG/PNG/WEBP بالنوع الفعلي فقط، لا SVG، اسم عشوائي يولّده المخدّم، حد حجم من الإعداد.

## لوحة الإدارة (Filament — مجموعة «الرعاية والدعم»)

الداعمون · برامج الرعاية · المساهمات · مستويات الداعمين.

| الصلاحية | تمنح | الأدوار الافتراضية |
| --- | --- | --- |
| `sponsorships.view` | عرض كل شيء + التحليلات | administrator, content-manager |
| `sponsorships.manage` | إنشاء/تعديل الملفات والبرامج وتسجيل المساهمات والأدوار | administrator, content-manager |
| `sponsorships.confirm` | **تأكيد/إلغاء/استرداد** المساهمات (قرار مالي) | administrator فقط |
| `sponsorships.reward_plan` | خطة تعزيز المكافآت وقفل خطط المنافسات | administrator فقط |

كل تغيير مالي أو ظهور **مدقَّق** في `operational_audit_logs` (إنشاء/تأكيد/إلغاء/استرداد/تعيين الراعي/تغيير الخطة). الإلغاء والاسترداد يتطلبان سببًا. التأكيد/الاسترداد داخل معاملة بقفل صف (`lockForUpdate`) فلا تأكيد مزدوج ولا سباق.

## الإشعارات (E15)

`support_confirmed` · `supporter_tier_changed` · `sponsorship_program_started` — للداعم المرتبط بحساب فقط، تُرسل **بعد** نجاح المعاملة المالية، **بلا مبالغ ولا مراجع ولا ملاحظات**. فشل الإشعار لا يتراجع بأي حالة مالية (`SponsorshipNotifier`: try/catch + report). `SPONSORSHIP_NOTIFICATIONS_ENABLED=false` يوقفها دون مساس بالمال. مفاتيح idempotency دلالية فلا تكرار.

## الأداء والفهارس والكاش

- المجاميع بتجميعات SQL (`SUM`) بفهارس `sponsorship_contrib_*` المغطّية؛ لا تحميل صفوف ولا فرز PHP. اللوحات العامة محدودة بسقوف (`public.top_limit` / `recent_limit` / `organizations_limit`) بلا ترقيم مفتوح.
- **استراتيجية الكاش** (`SponsorshipCache`): ما يُخزَّن **معرّفات وترتيب ومجاميع فقط** (لا أسماء ولا روابط)؛ TTL محدود دائمًا (`SPONSORSHIP_CACHE_TTL` 120ث للوحات، `SPONSORSHIP_DISPLAY_TTL` 60ث للشريط، 300ث للتحليلات)؛ **إبطال فوري** بأي تغيير مالي/ظهور (`SponsorshipCache::bust()` يرفع «إصدار» الكاش)؛ العلنية والظهور يُعاد فحصهما حيًّا بكل عرض (موقوف/خاص يختفي فورًا) مع هامش جلب `overfetch`؛ فشل الكاش = حساب مباشر (fail-open). التمويل والمستوى والتعزيز **لا تقرأ الكاش أبدًا**.
- عدد الاستعلامات في صفحات الداعمين لا يتبع حجم البيانات (مقيس باختبارات `SponsorshipE30`).

## إعدادات البيئة (كلها اختيارية بقيم افتراضية آمنة)

```
SPONSORSHIP_ENABLED=true
SPONSORSHIP_ACCOUNTING_CURRENCY=USD
SPONSORSHIP_MAX_AMOUNT_MINOR=1000000000
SPONSORSHIP_LOGO_DISK=public
SPONSORSHIP_LOGO_MAX_KB=1024
SPONSORSHIP_CACHE_TTL=120
SPONSORSHIP_DISPLAY_TTL=60
SPONSORSHIP_REWARD_BOOST_ENABLED=true
SPONSORSHIP_HARD_MAX_BOOST_BPS=5000
SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES=60
SPONSORSHIP_NOTIFICATIONS_ENABLED=true
```

المجدول: `sponsorship:lock-event-plans` كل 5 دقائق (غيابه = لا تعزيز لتلك المنافسات، لا أكثر).

## بيانات Demo

```
php artisan db:seed --class=DemoSponsorshipSeeder     # الرعاية فقط
php artisan db:seed --class=DemoE30Seeder             # الرعاية + سلاسل المبدعين
```

شركة راعٍ رئيسي + مؤسستان داعمتان + 3 أفراد + داعم يفضّل عدم الظهور؛ برنامج منصة وبرنامج موسم؛ مساهمات مؤكَّدة ومعلّقة ومستردة. **يرفض الإنتاج** و**idempotent** (الملف بالاسم، البرنامج بالعنوان، المساهمة بمرجعها `DEMO-E30-xx`) ويعمل بالخدمات الرسمية فقط. لا يفعّل خطة تعزيز، فلا يغيّر اقتصاد المكافآت في بيئة العرض. حسابات `.test` بكلمة مرور معروفة للبيئات المحلية فقط.

## ما لم يُنفَّذ عمدًا (تأجيل)

بوابة دفع/اشتراكات، KYC، تحويل مالي آلي، رعاية أحجيات المجتمع أو سلاسل المبدعين، لوحة ذاتية للداعم، فواتير/إيصالات ضريبية، وأسعار صرف حيّة. هذه قرارات منتج/قانون تستلزم مراجعة مختص قبل أي تفعيل.
`````

### `resources/views/components/sponsor-strip.blade.php`

`sha256: e8be13d5ebb5dfb90a1e5df23d6a5441c78ecf7247fccdd2bb552fdb67c20426`

`````blade
{{--
    E30: شريط «برعاية». بلا رعاة = صفر إخراج (لا فراغ ولا تخطيط مكسور). النصوص تُهرَّب دائمًا؛ الرابط الخارجي مُنقَّى مسبقًا (http/https فقط) ويحمل rel="noopener noreferrer sponsored".
    الراعي الرئيسي أبرز بصريًا، والداعمون الآخرون صف أصغر. الشعار صورة (JPEG/PNG/WEBP فقط) بنص بديل. لا إعلانات ولا تتبّع نقرات هنا.
--}}
@if ($sponsors['any'])
    <section {{ $attributes->class('glass rounded-2xl px-4 py-3 md:px-5 md:py-4') }} aria-label="برعاية" data-sponsor-strip>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
            <span class="text-xs font-black text-slate-400">برعاية</span>

            @if ($sponsors['main'])
                @php($m = $sponsors['main'])
                <div class="flex items-center gap-3 min-w-0" data-main-sponsor>
                    @if ($m['logo_url'])
                        <img src="{{ $m['logo_url'] }}" alt="شعار {{ $m['name'] }}" class="w-10 h-10 rounded-xl object-cover bg-white/5 shrink-0" loading="lazy" width="40" height="40">
                    @endif
                    <div class="min-w-0">
                        <a href="{{ route('supporters.show', $m['slug']) }}" class="font-display font-black text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $m['name'] }}</a>
                        <x-status-badge tone="warning" icon="sparkles" small class="ms-2">الراعي الرئيسي</x-status-badge>
                        @if ($m['website'])
                            <a href="{{ $m['website'] }}" target="_blank" rel="noopener noreferrer sponsored" class="block text-[11px] text-slate-400 hover:text-white truncate" dir="ltr">{{ parse_url($m['website'], PHP_URL_HOST) }}</a>
                        @endif
                    </div>
                </div>
            @endif

            @foreach ($sponsors['supporting'] as $s)
                <div class="flex items-center gap-2 min-w-0" data-sponsor>
                    @if ($s['logo_url'])
                        <img src="{{ $s['logo_url'] }}" alt="شعار {{ $s['name'] }}" class="w-7 h-7 rounded-lg object-cover bg-white/5 shrink-0" loading="lazy" width="28" height="28">
                    @endif
                    <a href="{{ route('supporters.show', $s['slug']) }}" class="text-sm font-bold text-slate-200 hover:text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $s['name'] }}</a>
                </div>
            @endforeach

            <a href="{{ route('supporters.index') }}" class="ms-auto text-xs font-bold text-amethyst hover:underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">كل الداعمين</a>
        </div>
    </section>
@endif
`````

### `resources/views/creator/series/_form.blade.php`

`sha256: 12f59b1189b68d0249d939a4fc1c43b70d798ecf1d2a8dfd4c0f3cbfe167c3b6`

`````blade
@php
    /**
     * E30: نموذج السلسلة المشترك بين الإنشاء والتعديل. $works أعمال المبدع المؤهَّلة، $selected معرّفات العناصر بالترتيب، $current العناصر المحفوظة (لإظهار ما لم يعد ظاهرًا).
     * المنشئ بسيط وصالح للجوال: «أضف» من القائمة، ثم أزرار أعلى/أسفل/إزالة بحجم لمس مريح؛ الترتيب = ترتيب العناصر المرسلة (items[]). كل النصوص مهرَّبة.
     * $locked=true للقراءة فقط (قيد المراجعة/منشورة بلا نسخة تعديل).
     */
    $locked = $locked ?? false;
    $val = fn (string $key, $default = '') => old($key, $values[$key] ?? $default);
    $inputCls = 'w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-60';
    $btnCls = 'inline-flex items-center justify-center min-h-[44px] min-w-[44px] rounded-xl bg-white/5 border border-white/10 text-sm font-bold text-white hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-40';

    $worksData = collect($works)->map(fn ($w) => ['id' => $w->public_id, 'title' => $w->published_title, 'category' => $w->publishedCategory?->name, 'ok' => true])->all();
    $known = collect($worksData)->pluck('id')->all();
    foreach (($current ?? collect()) as $c) {
        if (! in_array($c->public_id, $known, true)) {
            $worksData[] = ['id' => $c->public_id, 'title' => $c->published_title, 'category' => null, 'ok' => false];
        }
    }
@endphp

<div x-data="{
        works: @js($worksData),
        selected: @js(array_values($selected)),
        max: {{ (int) $limits['max_items'] }},
        min: {{ (int) $limits['min_items'] }},
        meta(id) { return this.works.find(w => w.id === id) || { title: '', category: null, ok: false }; },
        add(id) { if (! this.selected.includes(id) && this.selected.length < this.max) { this.selected.push(id); } },
        remove(i) { this.selected.splice(i, 1); },
        up(i) { if (i > 0) { const t = this.selected[i - 1]; this.selected[i - 1] = this.selected[i]; this.selected[i] = t; } },
        down(i) { if (i < this.selected.length - 1) { const t = this.selected[i + 1]; this.selected[i + 1] = this.selected[i]; this.selected[i] = t; } },
        get available() { return this.works.filter(w => w.ok && ! this.selected.includes(w.id)); }
     }" class="space-y-5">
    <fieldset @disabled($locked) class="space-y-5">
        <div>
            <label for="title" class="block text-sm font-bold text-white mb-1">عنوان السلسلة</label>
            <input id="title" name="title" type="text" value="{{ $val('title') }}" required minlength="{{ $limits['title_min'] }}" maxlength="{{ $limits['title_max'] }}" autocomplete="off" class="{{ $inputCls }}">
            @error('title') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="description" class="block text-sm font-bold text-white mb-1">وصف قصير (اختياري)</label>
            <textarea id="description" name="description" rows="3" maxlength="{{ $limits['description_max'] }}" class="{{ $inputCls }}">{{ $val('description') }}</textarea>
            <p class="text-xs text-slate-500 mt-1">نص عادي فقط؛ لا وسوم ولا أكواد.</p>
            @error('description') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="cover" class="block text-sm font-bold text-white mb-1">غلاف (اختياري)</label>
            @if (! empty($coverUrl))
                <img src="{{ $coverUrl }}" alt="الغلاف الحالي" class="w-full max-w-xs rounded-xl border border-white/10 mb-2">
                <label class="inline-flex items-center gap-2 text-xs text-slate-300 mb-2"><input type="checkbox" name="remove_cover" value="1" class="rounded"> حذف الغلاف الحالي</label>
            @endif
            <input id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp" class="{{ $inputCls }}">
            <p class="text-xs text-slate-500 mt-1">JPEG أو PNG أو WEBP فقط.</p>
            @error('cover') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <p class="block text-sm font-bold text-white mb-1">أحجيات السلسلة <span class="text-slate-400 font-normal" x-text="'(' + selected.length + ' من ' + max + '، الحد الأدنى ' + min + ')'"></span></p>
            <p class="text-xs text-slate-500 mb-3">من أحجياتك المنشورة والظاهرة فقط. رتّبها بالأسهم؛ هذا هو ترتيب اللعب.</p>

            <template x-for="id in selected" :key="'h' + id"><input type="hidden" name="items[]" :value="id"></template>

            <ol class="space-y-2 mb-4" data-series-selected>
                <template x-for="(id, i) in selected" :key="id">
                    <li class="glass rounded-2xl p-3 flex items-center gap-2">
                        <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-amethyst/15 text-amethyst font-black" x-text="i + 1" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold text-white break-words" x-text="meta(id).title"></span>
                            <span class="block text-xs text-rose" x-show="! meta(id).ok">غير ظاهرة حاليًا — أزلها قبل الإرسال.</span>
                        </span>
                        <button type="button" class="{{ $btnCls }}" @click="up(i)" :disabled="i === 0" aria-label="تقديم">↑</button>
                        <button type="button" class="{{ $btnCls }}" @click="down(i)" :disabled="i === selected.length - 1" aria-label="تأخير">↓</button>
                        <button type="button" class="{{ $btnCls }} !text-rose" @click="remove(i)" aria-label="إزالة">✕</button>
                    </li>
                </template>
            </ol>
            @error('items') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            @error('items.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror

            <p class="text-sm font-bold text-white mb-2">أضف أحجية</p>
            <p class="text-xs text-slate-500" x-show="available.length === 0">لا توجد أحجيات منشورة إضافية متاحة.</p>
            <ul class="space-y-2" data-series-available>
                <template x-for="w in available" :key="w.id">
                    <li class="rounded-xl border border-white/10 bg-white/5 p-2 flex items-center gap-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-white break-words" x-text="w.title"></span>
                            <span class="block text-[11px] text-slate-400" x-text="w.category"></span>
                        </span>
                        <button type="button" class="{{ $btnCls }} px-4" @click="add(w.id)" :disabled="selected.length >= max">إضافة</button>
                    </li>
                </template>
            </ul>
        </div>
    </fieldset>
</div>
`````

### `resources/views/creator/series/create.blade.php`

`sha256: 26778b7a1adc681e6d22fb79e01660562fe2ddb46ff2ac9ef651d1e3993d3ba0`

`````blade
@extends('layouts.app')

@section('title', 'سلسلة جديدة')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="سلسلة جديدة" icon="plus" subtitle="اختر من أحجياتك المنشورة ورتّبها. تُحفظ كمسودة ثم تُرسل للمراجعة." :back="route('creator.series.index')" backLabel="سلاسلي" class="anim-fade-up !mb-0" />

        @if ($works->count() < $limits['min_items'])
            <x-empty-state icon="puzzle" title="تحتاج أحجيات منشورة أولًا" :message="'لإنشاء سلسلة تحتاج '.$limits['min_items'].' أحجيات منشورة وظاهرة على الأقل.'" :action="route('creator.puzzles.index')" actionLabel="أحجياتي" />
        @else
            <form method="POST" action="{{ route('creator.series.store') }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
                @csrf
                @include('creator.series._form', ['values' => [], 'works' => $works, 'selected' => $selected, 'current' => $current, 'limits' => $limits])
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400">السلاسل محتوى مجتمع بلا جوائز اقتصادية، وتحتاج موافقة المراجع قبل ظهورها.</div>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ كمسودة</button>
                </div>
            </form>
        @endif
    </div>
@endsection
`````

### `resources/views/creator/series/edit.blade.php`

`sha256: 4becf18fa559f975e5c17294e209ebdbe00ef98fb2857dd7668623fac4bd231d`

`````blade
@extends('layouts.app')

@section('title', 'تعديل السلسلة')

@section('content')
    @php
        $editable = $revision->isEditable();
        $latestFinal = in_array($revision->status, ['approved', 'rejected'], true);
        $values = ['title' => $revision->title, 'description' => $revision->description];
    @endphp
    <div class="max-w-3xl mx-auto space-y-6">
        <x-page-header :title="$revision->title" icon="pencil" :subtitle="'نسخة '.$revision->revision_number" :back="route('creator.series.index')" backLabel="سلاسلي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <x-creator-status :status="$revision->status" />
                @if ($series->isArchived()) <x-creator-status status="archived" /> @endif
                @if ($series->isHidden()) <x-creator-status status="hidden" /> @endif
            </x-slot:actions>
        </x-page-header>

        @if ($series->isHidden())
            <div class="rounded-2xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 text-sm font-bold" role="status">
                أخفى فريق الإشراف النسخة المنشورة من هذه السلسلة.
                @if ($series->hidden_note) <span class="block font-normal text-slate-300 mt-1">السبب: {{ $series->hidden_note }}</span> @endif
            </div>
        @elseif ($series->isPublished() && ! $series->isArchived() && count($blockers) > 0)
            <div class="rounded-2xl border border-gold/30 bg-gold/10 text-gold px-5 py-4 text-sm font-bold" role="status" data-series-blockers>
                سلسلتك غير ظاهرة حاليًا، وتعود تلقائيًا عند زوال السبب:
                <ul class="list-disc ps-5 mt-1 font-normal text-slate-200">@foreach ($blockers as $reason) <li>{{ $reason }}</li> @endforeach</ul>
            </div>
        @endif

        @if (in_array($revision->status, ['changes_requested', 'rejected'], true) && $revision->review_note)
            <div class="rounded-2xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm" role="status">
                <p class="font-black text-gold">{{ $revision->status === 'rejected' ? 'سبب الرفض' : 'التعديلات المطلوبة' }}</p>
                <p class="text-slate-200 mt-1">{{ $revision->review_note }}</p>
            </div>
        @endif

        @if ($revision->status === 'pending_review')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">أُرسلت للمراجعة في {{ $revision->submitted_at?->translatedFormat('j F Y H:i') }}. لا يمكن تعديلها وهي قيد المراجعة؛ اسحبها لتعديلها.</div>
        @elseif ($series->isPublished() && ! $editable && $revision->status === 'approved')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">هذه النسخة منشورة ولا تُعدَّل مباشرة. لتغييرها أنشئ نسخة جديدة؛ تبقى المنشورة حيّة حتى تُعتمد الجديدة.</div>
        @endif

        <form method="POST" action="{{ route('creator.series.update', $series->public_id) }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
            @csrf @method('PUT')
            @include('creator.series._form', ['values' => $values, 'works' => $works, 'selected' => $selected, 'current' => $current, 'limits' => $limits, 'locked' => ! $editable, 'coverUrl' => $revision->coverUrl()])
            @if ($editable)
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ التعديلات</button>
                </div>
            @endif
        </form>

        <section aria-label="إجراءات" class="glass rounded-3xl p-6 space-y-4">
            <div class="flex flex-wrap gap-3">
                @if ($editable)
                    <form method="POST" action="{{ route('creator.series.submit', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm">إرسال للمراجعة</button>
                    </form>
                @endif

                @if ($revision->status === 'pending_review')
                    <form method="POST" action="{{ route('creator.series.withdraw', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">سحب من المراجعة</button>
                    </form>
                @endif

                @if ($latestFinal)
                    <form method="POST" action="{{ route('creator.series.revise', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">{{ $revision->status === 'approved' ? 'إنشاء نسخة جديدة للتعديل' : 'إنشاء نسخة جديدة' }}</button>
                    </form>
                @endif

                @if ($series->isPublished() && ! $series->isArchived())
                    <form method="POST" action="{{ route('creator.series.archive', $series->public_id) }}" onsubmit="return confirm('أرشفة السلسلة؟ ستُخفى من العامة ويمكنك إلغاء الأرشفة لاحقًا.')">@csrf
                        <button type="submit" class="btn-secondary">أرشفة</button>
                    </form>
                @elseif ($series->isArchived())
                    <form method="POST" action="{{ route('creator.series.unarchive', $series->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">إلغاء الأرشفة</button>
                    </form>
                @endif

                @if ($revision->status !== 'pending_review' && (! $series->isPublished() || $editable))
                    <form method="POST" action="{{ route('creator.series.destroy', $series->public_id) }}" onsubmit="return confirm('{{ $series->isPublished() ? 'حذف مسودة التعديل؟ تبقى النسخة المنشورة كما هي.' : 'حذف هذه السلسلة نهائيًا؟' }}')">@csrf @method('DELETE')
                        <button type="submit" class="btn-danger"><x-ui-icon name="trash" class="w-4 h-4" />{{ $series->isPublished() ? 'حذف مسودة التعديل' : 'حذف' }}</button>
                    </form>
                @endif
            </div>

            @if ($publicUrl && count($blockers) === 0 && ! $series->isArchived())
                <p class="text-xs text-slate-400">المنشورة حاليًا: <a class="text-amethyst font-bold hover:underline" href="{{ $publicUrl }}">{{ $series->published_title }}</a></p>
            @endif
        </section>
    </div>
@endsection
`````

### `resources/views/creator/series/index.blade.php`

`sha256: c8005ec9a373ba5435d7677fe2d27fad833c9544996fd2c29ba5a505922cbbea`

`````blade
@extends('layouts.app')

@section('title', 'سلاسلي')

{{-- E30: استوديو السلاسل. حاويات الحالة + تحليلات مجمَّعة (بلا هوية لاعب). لا أرباح ولا جوائز: السلاسل محتوى مجتمع. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="سلاسلي" icon="grid" subtitle="قوائم أحجيات مرتّبة من أعمالك المنشورة." :back="route('creator.dashboard')" backLabel="الاستوديو" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creator.series.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />سلسلة جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <section aria-label="أرقام سلاسلك" class="grid grid-cols-2 md:grid-cols-4 gap-3 anim-fade-up" data-series-stats>
            <x-stat-card label="لاعبون" :value="number_format($stats['players'])" icon="friends" />
            <x-stat-card label="بدايات الأحجيات" :value="number_format($stats['starts'])" icon="puzzle" />
            <x-stat-card label="أكملوا السلسلة" :value="number_format($stats['completions'])" icon="check-circle" />
            <x-stat-card label="نسبة الإكمال" :value="$stats['completion_rate'].'%'" icon="chart" />
        </section>

        <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 anim-fade-up">
            @foreach (['all' => 'الكل', 'draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                <a href="{{ route('creator.series.index', $key === 'all' ? [] : ['status' => $key]) }}" @if ($bucket === $key) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $bucket === $key ? 'chip-active' : '' }}">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <section class="anim-fade-up">
            @forelse ($seriesList as $series)
                @php $rev = $series->latestRevision; $a = $perSeries[$series->id] ?? null; @endphp
                <div class="glass rounded-2xl p-4 mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('creator.series.edit', $series->public_id) }}" class="font-display font-black text-white hover:text-amethyst break-words focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $rev?->title ?? $series->published_title }}</a>
                        <p class="text-xs text-slate-400 mt-1">
                            @if ($rev) نسخة {{ $rev->revision_number }} @endif
                            @if ($series->isPublished()) · {{ (int) $series->published_items_count }} أحجية @endif
                            @if ($a) · {{ number_format($a['players']) }} لاعب · إكمال {{ $a['completion_rate'] }}% @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($rev) <x-creator-status :status="$rev->status" :small="true" /> @endif
                        @if ($series->isArchived()) <x-creator-status status="archived" :small="true" /> @endif
                        @if ($series->isHidden()) <x-creator-status status="hidden" :small="true" /> @endif
                        <a href="{{ route('creator.series.edit', $series->public_id) }}" class="btn-secondary !py-1.5 !px-3 text-xs">فتح</a>
                    </div>
                </div>
            @empty
                <x-empty-state icon="grid" title="لا سلاسل هنا" message="غيّر التصفية أو أنشئ سلسلة جديدة من أحجياتك المنشورة." :action="route('creator.series.create')" actionLabel="سلسلة جديدة" />
            @endforelse

            <div class="flex justify-center mt-6">{{ $seriesList->links() }}</div>
        </section>
    </div>
@endsection
`````

### `resources/views/series/_card.blade.php`

`sha256: 3d5071de52ddfde4d9c1e32e9db18c5d0b96fb9cb8259ce09b84da0dcf980699`

`````blade
@php
    /** @var \App\Models\CreatorSeries $series */
    $creatorName = $series->relationLoaded('creator') && $series->creator?->relationLoaded('user') ? $series->creator->user?->name : null;
@endphp
{{-- E30: بطاقة سلسلة مجتمع. وسم «من المجتمع» ثابت (يميّزها عن الرسمي). البطاقة كلها رابط واحد. تقرأ لقطة النشر من جدول السلسلة وحده (بلا ضمّ ولا N+1). نصوص مهرَّبة. --}}
<a href="{{ route('series.show', $series->public_id) }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none" data-series-card>
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="grid" class="w-5 h-5" /></span>
        <x-status-badge tone="primary" :small="true" icon="friends">سلسلة من المجتمع</x-status-badge>
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $series->published_title }}</h3>
    <p class="text-xs text-slate-400 mt-1">
        {{ (int) $series->published_items_count }} أحجية
        @if ($creatorName) · {{ $creatorName }} @endif
    </p>
</a>
`````

### `resources/views/series/show.blade.php`

`sha256: 3a04291d83839f9f1c02147b88a42f1e73668cfefd0127e8836bf71547594345`

`````blade
@extends('layouts.app')

@section('title', $revision->title.' - سلسلة من المجتمع')
@section('content_width', 'max-w-3xl')

{{--
    E30: صفحة سلسلة مبدع. وسم «من المجتمع» وتصريح «بلا جوائز» ظاهران دائمًا. قراءة فقط (لا كتابة بـGET). اللعب يجري في صفحات أحجيات المجتمع القائمة (بلا اقتصاد).
    التقدّم مشتقّ من سجل لعب المجتمع، و«أكمل» يفتح أول عنصر غير محلول. نصوص المبدع مهرَّبة دائمًا (إخراج Blade العادي فقط). RTL.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('creators.show', $creator->slug) }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />{{ $creator->user->name }}
        </a>

        <header class="puzzle-card !p-6 md:!p-8 anim-fade-up d-1" data-series-header>
            @if ($coverUrl)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-5">
                    <img src="{{ $coverUrl }}" alt="{{ $revision->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                <x-status-badge tone="neutral" :small="true">بلا جوائز</x-status-badge>
                <span class="chip !py-1 !px-3">{{ $items->count() }} أحجية</span>
            </div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $revision->title }}</h1>
            <p class="text-xs text-slate-400 mb-3">
                بقلم <a href="{{ route('creators.show', $creator->slug) }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $creator->user->name }}</a>
                @if ($series->first_published_at) · نُشرت {{ $series->first_published_at->translatedFormat('j F Y') }} @endif
            </p>
            @if ($revision->description)
                <p class="text-sm text-slate-200 break-words whitespace-pre-line">{{ $revision->description }}</p>
            @endif

            <div class="mt-5">
                @guest
                    <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200 flex flex-wrap items-center justify-between gap-3">
                        <span>سجّل الدخول لتلعب السلسلة ويُحفظ تقدّمك.</span>
                        <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                    </div>
                @else
                    @if ($isOwner)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200" role="status">هذه سلسلتك. لا تُحتسب لك محاولات على أحجياتك. <a href="{{ route('creator.series.index') }}" class="text-amethyst font-bold underline">استوديو السلاسل</a></div>
                    @elseif (! $canPlay)
                        <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-gold font-bold" role="status">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتلعب أحجيات المجتمع.</div>
                    @else
                        <div data-series-progress>
                            <div class="flex items-center justify-between text-sm font-bold text-slate-300 mb-2">
                                <span>تقدّمك: {{ $progress['done'] }} من {{ $progress['total'] }}</span>
                                <span>{{ $progress['percent'] }}%</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" aria-label="تقدّمك في السلسلة">
                                <div class="h-full rounded-full bg-amethyst" style="width: {{ $progress['percent'] }}%"></div>
                            </div>
                            @if ($progress['completed'])
                                <p class="mt-3 text-sm font-bold text-emerald flex items-center gap-2" role="status"><x-ui-icon name="check-circle" class="w-5 h-5" />أكملت السلسلة! أحسنت. (أحجيات المجتمع بلا جوائز اقتصادية.)</p>
                            @endif
                        </div>
                    @endif
                @endguest

                @if ($continueId && ! $isOwner && ! ($canPlay && $progress['completed']))
                    <a data-series-continue href="{{ route('community.puzzles.show', ['work' => $continueId, 'series' => $series->public_id]) }}" class="btn-gem !px-8 mt-4 inline-flex items-center gap-2">
                        {{ $canPlay && $progress['done'] > 0 ? 'أكمل السلسلة' : 'ابدأ السلسلة' }}<x-ui-icon name="chevron-left" class="w-4 h-4" />
                    </a>
                @endif
            </div>
        </header>

        <section aria-label="أحجيات السلسلة" class="anim-fade-up d-2">
            <ol class="space-y-2" data-series-items>
                @foreach ($items as $item)
                    @php $solved = (bool) ($progress['solved'][$item->work_id] ?? false); @endphp
                    <li>
                        <a href="{{ route('community.puzzles.show', ['work' => $item->public_id, 'series' => $series->public_id]) }}"
                           class="glass rounded-2xl p-4 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                            <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl font-black {{ $solved ? 'bg-emerald/15 text-emerald' : 'bg-white/5 text-slate-300' }}" aria-hidden="true">{{ $item->position }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-bold text-white break-words">{{ $item->published_title }}</span>
                                <span class="block text-xs text-slate-400 mt-0.5">{{ $item->category }}</span>
                            </span>
                            @if ($item->published_difficulty) <x-difficulty-badge :difficulty="$item->published_difficulty" :small="true" /> @endif
                            @if ($solved)
                                <x-status-badge tone="success" :small="true" icon="check-circle">محلولة</x-status-badge>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
@endsection
`````

### `resources/views/supporters/_tier.blade.php`

`sha256: 82e536484811a0aab597dca0ebffe0d9c497d974fc44606b968f50cddd64b561`

`````blade
@props(['tier'])
{{-- E30: شارة مستوى الداعم (عرض فقط؛ المستوى مشتقّ من صافي الدعم المؤكَّد). --}}
@if ($tier)
    @php($tone = ['gold' => 'warning', 'platinum' => 'primary', 'diamond' => 'primary', 'silver' => 'neutral'][$tier->badge_key] ?? 'neutral')
    <x-status-badge :tone="$tone" icon="sparkles" small>{{ $tier->name }}</x-status-badge>
@endif
`````

### `resources/views/supporters/index.blade.php`

`sha256: 30f21c676561fde46d34178657bb870d1190594aec3787df1b116e458f4bdc57`

`````blade
@extends('layouts.app')

@section('title', 'الداعمون والرعاة')
@section('content_width', 'max-w-5xl')

{{--
    E30: لوحة الداعمين العامة (قراءة فقط). المجهولون «داعم مجهول» بلا اسم/شعار/رابط/مستوى. المبالغ الدقيقة لا تظهر إلا للوحة برنامج فعّل ذلك؛ غير ذلك المستوى فقط.
    كل النصوص مهرّبة ({{ }})، والروابط الخارجية (إن وُجدت) تُعرض فقط من صفحة الداعم وبـ rel آمن.
--}}
@section('content')
    @php
        $scopeLabels = ['all' => 'كل الوقت', 'year' => 'هذا العام', 'season' => 'الموسم الحالي'];
    @endphp

    <div class="space-y-8">
        <x-page-header title="الداعمون والرعاة" icon="gift" subtitle="شكرًا لكل من يدعم المنصة ويموّل مكافآتها وفعالياتها. الدعم طوعي ولا يمنح أي أفضلية في اللعب." class="anim-fade-up !mb-0" />

        <x-sponsor-strip type="platform" />

        <section aria-labelledby="board-title" class="space-y-4 anim-fade-up">
            <x-section-header title="لوحة الداعمين" />
            <h2 id="board-title" class="sr-only">لوحة الداعمين</h2>

            <nav aria-label="نطاق اللوحة" class="flex flex-wrap gap-2">
                @foreach ($scopeLabels as $key => $label)
                    <a href="{{ route('supporters.index', ['scope' => $key]) }}" @if ($scope === $key) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $scope === $key ? 'chip-active' : '' }}">{{ $label }}</a>
                @endforeach
                @foreach ($programs as $p)
                    <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $p->slug]) }}" @if ($program && $program->id === $p->id) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $program && $program->id === $p->id ? 'chip-active' : '' }}">{{ $p->title }}</a>
                @endforeach
            </nav>

            @if ($leaderboard['entries'] === [])
                <x-empty-state icon="gift" title="لا داعمين في هذا النطاق بعد" message="ستظهر هنا أسماء الداعمين بعد تأكيد مساهماتهم." />
            @else
                <ol class="grid gap-2" aria-label="ترتيب الداعمين">
                    @foreach ($leaderboard['entries'] as $entry)
                        <li class="glass rounded-2xl px-4 py-3 flex items-center gap-3" data-rank="{{ $entry['rank'] }}" @if ($entry['anonymous']) data-anonymous @endif>
                            <span class="grid place-items-center w-9 h-9 rounded-xl bg-amethyst/15 text-amethyst font-black shrink-0">{{ $entry['rank'] }}</span>
                            <div class="min-w-0 flex-1">
                                @if ($entry['anonymous'])
                                    <span class="font-bold text-slate-300">داعم مجهول</span>
                                @else
                                    <a href="{{ route('supporters.show', $entry['supporter']->slug) }}" class="font-display font-black text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $entry['supporter']->display_name }}</a>
                                    <span class="ms-2">@include('supporters._tier', ['tier' => $entry['tier']])</span>
                                @endif
                            </div>
                            @if ($leaderboard['show_amounts'] && $entry['total_minor'] !== null && $program)
                                <span class="font-black text-gold shrink-0">{{ \App\Services\Sponsorship\SponsorshipMoney::format($entry['total_minor'], $program->accounting_currency) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        @if ($programs->isNotEmpty())
            <section aria-labelledby="programs-title" class="anim-fade-up">
                <x-section-header title="برامج الرعاية" />
                <h2 id="programs-title" class="sr-only">برامج الرعاية</h2>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach ($programs as $p)
                        @php($raised = $funding[$p->id] ?? 0)
                        <article class="glass rounded-2xl p-4 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-display font-black text-white break-words">{{ $p->title }}</h3>
                                <x-status-badge :tone="$p->status === 'active' ? 'success' : 'neutral'" small>{{ $p->status === 'active' ? 'نشط' : 'مغلق' }}</x-status-badge>
                            </div>
                            <p class="text-xs text-slate-400">{{ $targets->typeLabel($p->target_type) }}</p>
                            @if ($p->show_goal_progress && (int) $p->goal_amount_minor > 0)
                                @php($pct = (int) min(100, intdiv($raised * 100, (int) $p->goal_amount_minor)))
                                <div>
                                    <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1">
                                        <span>تقدّم الهدف</span>
                                        <span class="text-gold">{{ $pct }}٪</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}">
                                        <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $pct }}%"></div>
                                    </div>
                                    @if ($p->show_public_amounts)
                                        <p class="text-xs text-slate-400 mt-1">{{ \App\Services\Sponsorship\SponsorshipMoney::format($raised, $p->accounting_currency) }} من {{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $p->goal_amount_minor, $p->accounting_currency) }}</p>
                                    @endif
                                </div>
                            @endif
                            <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $p->slug]) }}" class="text-xs font-bold text-amethyst hover:underline">داعمو البرنامج</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($organizations !== [])
            <section aria-labelledby="orgs-title" class="anim-fade-up">
                <x-section-header title="المؤسسات الداعمة" />
                <h2 id="orgs-title" class="sr-only">المؤسسات الداعمة</h2>
                <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($organizations as $org)
                        <li class="glass rounded-2xl p-4 flex items-center gap-3">
                            <a href="{{ route('supporters.show', $org['supporter']->slug) }}" class="font-bold text-white hover:underline break-words min-w-0 flex-1 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $org['supporter']->display_name }}</a>
                            @include('supporters._tier', ['tier' => $org['tier']])
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($recent !== [])
            <section aria-labelledby="recent-title" class="anim-fade-up">
                <x-section-header title="أحدث الداعمين" />
                <h2 id="recent-title" class="sr-only">أحدث الداعمين</h2>
                <ul class="grid gap-2">
                    @foreach ($recent as $row)
                        <li class="glass rounded-xl px-4 py-2.5 flex items-center justify-between gap-3 text-sm">
                            @if ($row['anonymous'])
                                <span class="text-slate-300">داعم مجهول</span>
                            @else
                                <a href="{{ route('supporters.show', $row['supporter']->slug) }}" class="font-bold text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $row['supporter']->display_name }}</a>
                            @endif
                            @if ($row['at'])<time datetime="{{ $row['at']->toIso8601String() }}" class="text-xs text-slate-500">{{ $row['at']->format('Y-m-d') }}</time>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($tiers->isNotEmpty())
            <section aria-labelledby="tiers-title" class="anim-fade-up">
                <x-section-header title="مستويات الدعم" />
                <h2 id="tiers-title" class="sr-only">مستويات الدعم</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($tiers as $tier)
                        <li>@include('supporters._tier', ['tier' => $tier])</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="text-xs text-slate-500 anim-fade-up">
            الدعم هبة طوعية تموّل المكافآت والفعاليات داخل المنصة. لا يمنح الداعم مقابلًا ماليًا أو عائدًا، ولا يمنحه أفضلية في الأحجيات أو المنافسات. المكافآت المعزَّزة بالرعاية جواهر داخلية للمحتوى الرسمي فقط، وليست قابلة للسحب نقدًا.
        </p>
    </div>
@endsection
`````

### `resources/views/supporters/show.blade.php`

`sha256: e5d43673821f66390f623ada379a7051164ab9d6847ce752357a81d69a0d1302`

`````blade
@extends('layouts.app')

@section('title', $supporter->display_name)
@section('content_width', 'max-w-3xl')

{{--
    E30: صفحة داعم علنية (ملف علني ونشط فقط). تعرض: الاسم/الشعار/الوصف/الرابط الآمن/المستوى/البرامج التي دعمها علنًا (مساهمات غير مجهولة فقط).
    لا تعرض أبدًا: المبالغ (إلا لبرنامج فعّل ذلك)، الملاحظات الإدارية، المراجع، البريد، ولا أي ربط بمساهمة مجهولة.
--}}
@section('content')
    <div class="space-y-6">
        <a href="{{ route('supporters.index') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition anim-fade-up">→ كل الداعمين</a>

        <article class="glass rounded-3xl p-6 md:p-8 anim-fade-up space-y-4">
            <div class="flex items-start gap-4">
                @if ($card['logo_url'])
                    <img src="{{ $card['logo_url'] }}" alt="شعار {{ $supporter->display_name }}" class="w-20 h-20 rounded-2xl object-cover bg-white/5 shrink-0" width="80" height="80">
                @else
                    <span class="grid place-items-center w-20 h-20 rounded-2xl bg-amethyst/15 text-amethyst shrink-0"><x-ui-icon name="gift" class="w-9 h-9" /></span>
                @endif
                <div class="min-w-0">
                    <h1 class="font-display font-black text-2xl md:text-3xl text-white break-words">{{ $supporter->display_name }}</h1>
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        <x-status-badge tone="neutral" small>{{ $supporter->type === 'organization' ? 'مؤسسة' : 'فرد' }}</x-status-badge>
                        @include('supporters._tier', ['tier' => $tier])
                    </div>
                </div>
            </div>

            @if ($supporter->description)
                <p class="text-slate-300 whitespace-pre-line break-words">{{ $supporter->description }}</p>
            @endif

            @if ($card['website'])
                <a href="{{ $card['website'] }}" target="_blank" rel="noopener noreferrer sponsored" class="inline-flex items-center gap-1.5 text-sm font-bold text-amethyst hover:underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" dir="ltr">
                    <x-ui-icon name="globe" class="w-4 h-4" />{{ parse_url($card['website'], PHP_URL_HOST) }}
                </a>
            @endif

            @if ($since)
                <p class="text-xs text-slate-500">داعم منذ {{ \Illuminate\Support\Carbon::parse($since)->format('Y-m-d') }}</p>
            @endif
        </article>

        @if ($programs->isNotEmpty())
            <section aria-labelledby="supported-title" class="anim-fade-up">
                <x-section-header title="يدعم" />
                <h2 id="supported-title" class="sr-only">البرامج المدعومة</h2>
                <ul class="grid gap-2">
                    @foreach ($programs as $p)
                        <li class="glass rounded-xl px-4 py-3 flex items-center justify-between gap-3">
                            <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $p->slug]) }}" class="font-bold text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $p->title }}</a>
                            @if ($p->show_amounts)
                                <span class="font-black text-gold shrink-0">{{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $p->total, $p->cur) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
`````

### `routes/series.php`

`sha256: 0388083b1debcf90642cc0717f3d9f2f39b33cdf397964e3533e85233c453a03`

`````php
<?php

use App\Http\Controllers\CreatorSeries\CreatorSeriesStudioController;
use App\Http\Controllers\CreatorSeries\SeriesController;
use App\Http\Middleware\EnsureCreatorStudioAccess;
use Illuminate\Support\Facades\Route;

/*
| E30 (B) - سلاسل المبدعين (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  عام (قراءة فقط، للضيوف): /series/{معرّف عام ULID أو slug}. السلسلة غير الظاهرة = 404 للجميع (مسودة/مرفوضة/مخفية/مؤرشفة/عنصر مخفي/مبدع معلَّق).
|  استوديو المبدع (نفس بوابة E28: ملف معتمَد + بريد موثَّق + حساب غير مجمَّد): كل مسارات {series} بمعرّف ULID عام يُحلّ باستعلام محصور بالمالك.
|  لا مسار GET يكتب شيئًا، ولا مسار لعب جديد: اللعب عبر مسارات أحجيات المجتمع القائمة (E28).
| الصفحات الحرفية تسبق الديناميكية (create قبل {series}). المسارات مسجّلة دائمًا (مسار الإشعارات series.show يجب أن يوجد)، وتعطيل الميزة (CREATOR_SERIES_ENABLED=false) يردّ 404 من المتحكمات.
*/

Route::get('/series/{series}', [SeriesController::class, 'show'])->where('series', '[A-Za-z0-9_-]{3,110}')->middleware('throttle:series-read')->name('series.show');

Route::middleware(['auth', 'verified', 'account.active', EnsureCreatorStudioAccess::class])->prefix('creator')->name('creator.')->group(function () {
    Route::get('/series', [CreatorSeriesStudioController::class, 'index'])->name('series.index');
    Route::get('/series/create', [CreatorSeriesStudioController::class, 'create'])->name('series.create');
    Route::post('/series', [CreatorSeriesStudioController::class, 'store'])->middleware('throttle:creator-draft-create')->name('series.store');

    Route::prefix('/series/{series}')->whereUlid('series')->name('series.')->group(function () {
        Route::get('/edit', [CreatorSeriesStudioController::class, 'edit'])->name('edit');
        Route::put('/', [CreatorSeriesStudioController::class, 'update'])->name('update');
        Route::delete('/', [CreatorSeriesStudioController::class, 'destroy'])->name('destroy');
        Route::post('/submit', [CreatorSeriesStudioController::class, 'submit'])->middleware('throttle:creator-submit')->name('submit');
        Route::post('/withdraw', [CreatorSeriesStudioController::class, 'withdraw'])->middleware('throttle:creator-submit')->name('withdraw');
        Route::post('/revise', [CreatorSeriesStudioController::class, 'revise'])->middleware('throttle:creator-submit')->name('revise');
        Route::post('/archive', [CreatorSeriesStudioController::class, 'archive'])->name('archive');
        Route::post('/unarchive', [CreatorSeriesStudioController::class, 'unarchive'])->name('unarchive');
    });
});
`````

### `routes/sponsorship.php`

`sha256: 579fdff4cc4c59c8d9a3d5f80a64c391d5fdb2b8f39c21c5eab5ca283768fae4`

`````php
<?php

use App\Http\Controllers\Sponsorship\SupporterController;
use Illuminate\Support\Facades\Route;

/*
| E30 - الداعمون والرعاة (تُضمَّن من routes/web.php بمجموعة web). قراءة عامة فقط (GET) بلا أي كتابة: التسجيل والتأكيد من لوحة الإدارة حصرًا (لا مسار يؤكد أو يمنح).
| الظهور: ملف علني ونشط فقط (غيره 404). حدّ قراءة معتدل. لا تُكشف مبالغ ولا ملاحظات ولا مراجع داخلية بأي مسار.
*/

Route::middleware('throttle:supporters-read')->group(function () {
    Route::get('/supporters', [SupporterController::class, 'index'])->name('supporters.index');
    Route::get('/supporters/{supporter:slug}', [SupporterController::class, 'show'])->name('supporters.show');
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesCreationTest.php`

`sha256: 1717732ba246e713babf6b609f8d61dfad3b857bbae67671ac38bebf1fc22f09`

`````php
<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\Creators\CreatorException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

test('AM9-83: an approved creator creates a draft series owned by them, with a stable public id and slug', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);

    $revision = e30s_draft($creator, $works, ['title' => 'سلسلة الألغاز الأولى']);
    $series = $revision->series()->first();

    expect($revision->status)->toBe('draft')->and($revision->revision_number)->toBe(1)->and($revision->title)->toBe('سلسلة الألغاز الأولى')
        ->and($series->creator->user_id)->toBe($creator->id)->and($series->public_id)->toHaveLength(26)->and($series->slug)->not->toBe('')
        ->and($series->latest_revision_id)->toBe($revision->id)->and($series->published_revision_id)->toBeNull()
        ->and(e30s_visible($series))->toBeFalse();
});

test('AM9-84: a non-creator (player, applicant) cannot create a series', function () {
    $player = e16User();
    $works = e30s_works(e28_creator(), 2);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($player, ['title' => 'محاولة غير مؤهلة', 'items' => e30s_ids($works)]))->toThrow(CreatorException::class);

    $this->actingAs($player)->post(route('creator.series.store'), ['title' => 'محاولة غير مؤهلة', 'items' => e30s_ids($works)])->assertRedirect(route('creator.apply'));
    expect(CreatorSeries::query()->count())->toBe(0);
});

test('AM9-85: a suspended creator is denied creating and editing', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $revision = e30s_draft($creator, $works);
    $publicId = $revision->series()->value('public_id');

    $profile = $creator->creatorProfile()->first();
    app(\App\Services\Creators\CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'مخالفة تجريبية للاختبار.');
    $creator = $creator->fresh();

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة جديدة', 'items' => e30s_ids($works)]))->toThrow(CreatorException::class)
        ->and(fn () => app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'تعديل مرفوض', 'items' => e30s_ids($works)]))->toThrow(CreatorException::class);

    $this->actingAs($creator)->get(route('creator.series.index'))->assertRedirect(route('creator.apply'));
});

test('AM9-86: the minimum number of items is enforced (service and HTTP)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة قصيرة', 'items' => e30s_ids([$works[0]])]))
        ->toThrow(CreatorSeriesException::class, '2');

    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة قصيرة', 'items' => e30s_ids([$works[0]])])->assertSessionHasErrors('items');
    expect(CreatorSeries::query()->count())->toBe(0);
});

test('AM9-87: the maximum number of items is enforced and is configurable', function () {
    $creator = e28_creator();
    config(['creator_series.limits.max_items' => 3]);
    $works = e30s_works($creator, 4);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة طويلة', 'items' => e30s_ids($works)]))
        ->toThrow(CreatorSeriesException::class, '3');
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة طويلة', 'items' => e30s_ids($works)])->assertSessionHasErrors('items');

    config(['creator_series.limits.max_items' => 4]);
    expect(e30s_draft($creator, $works)->items()->count())->toBe(4);
});

test('AM9-88: a duplicate item is rejected', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $ids = [$works[0]->public_id, $works[1]->public_id, $works[0]->public_id];

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة مكررة', 'items' => $ids]))->toThrow(CreatorSeriesException::class);
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة مكررة', 'items' => $ids])->assertSessionHasErrors();
    expect(CreatorSeries::query()->count())->toBe(0);
});

test("AM9-89: another creator's puzzle is rejected without revealing it exists", function () {
    $creator = e28_creator();
    $other = e28_creator();
    $mine = e30s_works($creator, 2);
    $foreign = e28_published($other, 'text', ['title' => 'لغز مبدع آخر']);

    $thrown = null;
    try {
        app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر غريب', 'items' => [$mine[0]->public_id, $foreign->public_id]]);
    } catch (CreatorSeriesException $e) {
        $thrown = $e->getMessage();
    }

    // نفس رسالة «معرّف غير موجود» تمامًا: لا فرق بين عمل غريب ومعرّف مختلق.
    $fake = null;
    try {
        app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر مختلق', 'items' => [$mine[0]->public_id, (string) \Illuminate\Support\Str::ulid()]]);
    } catch (CreatorSeriesException $e) {
        $fake = $e->getMessage();
    }

    expect($thrown)->not->toBeNull()->and($thrown)->toBe($fake)->and(CreatorSeries::query()->count())->toBe(0);
});

test('AM9-90: a hidden (or archived / unpublished) puzzle is rejected', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    e30s_hide_work($works[2]);

    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر مخفي', 'items' => e30s_ids($works)]))
        ->toThrow(CreatorSeriesException::class);

    // مسودة لم تُنشر (غير ظاهرة) مرفوضة كذلك.
    $draftWork = e28_draft($creator)->work()->first();
    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بمسودة', 'items' => [$works[0]->public_id, $draftWork->public_id]]))
        ->toThrow(CreatorSeriesException::class);

    app(\App\Services\Creators\CreatorPuzzleService::class)->archive($creator, $works[1]->public_id);
    expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بمؤرشف', 'items' => [$works[0]->public_id, $works[1]->public_id]]))
        ->toThrow(CreatorSeriesException::class);
});

test('AM9-91: an official puzzle cannot be an item (series items are community works only)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $official = \App\Models\Puzzle::factory()->create();

    foreach ([$official->slug ?? 'official', (string) $official->id, (string) \Illuminate\Support\Str::ulid()] as $bogus) {
        expect(fn () => app(CreatorSeriesService::class)->createDraft($creator, ['title' => 'سلسلة بعنصر رسمي', 'items' => [$works[0]->public_id, $bogus]]))
            ->toThrow(CreatorSeriesException::class);
    }

    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بعنصر رسمي', 'items' => [$works[0]->public_id, (string) $official->id]])->assertSessionHasErrors();
    expect(CreatorSeries::query()->count())->toBe(0)->and(CreatorSeriesItem::query()->count())->toBe(0);
});

test('AM9-92: item order is saved explicitly (positions 1..n in the submitted order) and re-ordering works', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 4);
    $order = [$works[2], $works[0], $works[3], $works[1]];

    $revision = e30s_draft($creator, $order);
    $positions = CreatorSeriesItem::query()->where('creator_series_revision_id', $revision->id)->orderBy('position')->get();

    expect($positions->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($positions->pluck('creator_puzzle_id')->all())->toBe(array_map(fn ($w) => $w->id, $order));

    $reordered = [$works[1], $works[3], $works[0], $works[2]];
    app(CreatorSeriesService::class)->updateDraft($creator, $revision->series()->value('public_id'), ['title' => $revision->title, 'items' => e30s_ids($reordered)]);

    expect(CreatorSeriesItem::query()->where('creator_series_revision_id', $revision->id)->orderBy('position')->pluck('creator_puzzle_id')->all())
        ->toBe(array_map(fn ($w) => $w->id, $reordered));
});

test('AM9-93: the owner can edit a draft (title, description, items) via HTTP and the page shows it', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_draft($creator, [$works[0], $works[1]], ['title' => 'عنوان قديم']);
    $publicId = $revision->series()->value('public_id');

    $this->actingAs($creator)->get(route('creator.series.edit', $publicId))->assertOk()->assertSee('عنوان قديم');

    $this->actingAs($creator)->put(route('creator.series.update', $publicId), ['title' => 'عنوان جديد', 'description' => 'وصف جديد', 'items' => e30s_ids($works)])
        ->assertSessionHasNoErrors()->assertRedirect();

    $revision->refresh();
    expect($revision->title)->toBe('عنوان جديد')->and($revision->description)->toBe('وصف جديد')->and($revision->items()->count())->toBe(3);
    $this->get(route('creator.series.edit', $publicId))->assertOk()->assertSee('عنوان جديد');
});

test('AM9-94: another creator cannot read or change my series (IDOR -> 404)', function () {
    $owner = e28_creator();
    $intruder = e28_creator();
    $revision = e30s_draft($owner);
    $publicId = $revision->series()->value('public_id');
    $intruderWorks = e30s_works($intruder, 2);

    $this->actingAs($intruder);
    $this->get(route('creator.series.edit', $publicId))->assertNotFound();
    $this->put(route('creator.series.update', $publicId), ['title' => 'اختراق', 'items' => e30s_ids($intruderWorks)])->assertNotFound();
    $this->post(route('creator.series.submit', $publicId))->assertNotFound();
    $this->post(route('creator.series.withdraw', $publicId))->assertNotFound();
    $this->post(route('creator.series.revise', $publicId))->assertNotFound();
    $this->post(route('creator.series.archive', $publicId))->assertNotFound();
    $this->delete(route('creator.series.destroy', $publicId))->assertNotFound();

    expect($revision->fresh()->title)->toBe($revision->title)->and(CreatorSeries::query()->where('public_id', $publicId)->exists())->toBeTrue();
});

test('AM9: a work of the creator that is not theirs by id is unreachable and drafts are capped per creator', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    config(['creator_series.limits.max_per_creator' => 2]);

    e30s_draft($creator, $works);
    e30s_draft($creator, $works);

    expect(fn () => e30s_draft($creator, $works))->toThrow(CreatorSeriesException::class);
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesFollowTest.php`

`sha256: a1bbcca50d30b595850ef4d9b9b8465b9e330c29b23cc27f47fd757843b50512`

`````php
<?php

use App\Jobs\DispatchCreatorSeriesChunk;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

function e30s_series_notes(User $user)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('type_key', 'creator_new_series')->get();
}

test('AM13-123: a newly published series is announced to followers (queued, only when followers exist)', function () {
    Queue::fake();
    $creator = e28_creator();
    $lonely = e28_creator();
    e29_follow(e16User(), $creator);

    e30s_published($creator);
    e30s_published($lonely);

    Queue::assertPushed(DispatchCreatorSeriesChunk::class, 1);
});

test('AM13-123b: the follower gets exactly one correct internal notification pointing to the public series page', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);

    $series = e30s_published($creator, null, ['title' => 'سلسلة الإشعار الصحيح']);

    $notes = e30s_series_notes($follower);
    expect($notes)->toHaveCount(1);
    $data = $notes[0]->data;
    expect($data['category'])->toBe('social')->and($data['title'])->toContain('سلسلة الإشعار الصحيح')->and($data['action_route'])->toBe('series.show')
        ->and($data['action_params']['series'])->toBe($series->public_id)->and($notes[0]->idempotency_key)->toStartWith('creator-series:');
    // الرابط يُحلّ فعلًا إلى مسار حقيقي
    expect(route($data['action_route'], $data['action_params']))->toContain('/series/'.$series->public_id);
});

test('AM13-124: a non-follower (and the creator) is never notified', function () {
    $creator = e28_creator();
    $follower = e16User();
    $stranger = e16User();
    e29_follow($follower, $creator);

    e30s_published($creator);

    expect(e30s_series_notes($stranger))->toHaveCount(0)->and(e30s_series_notes($creator))->toHaveCount(0)->and(e30s_series_notes($follower))->toHaveCount(1);
});

test('AM13-125: the fan-out is idempotent - running the job twice never duplicates', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $series = e30s_published($creator);
    $revisionId = (int) $series->fresh()->published_revision_id;

    (new DispatchCreatorSeriesChunk($revisionId))->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));
    (new DispatchCreatorSeriesChunk($revisionId))->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));

    expect(e30s_series_notes($follower))->toHaveCount(1);
});

test('AM13-126: a notification failure never rolls back the publication', function () {
    $creator = e28_creator();
    e29_follow(e16User(), $creator);

    $this->mock(NotificationDispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->andReturnUsing(function ($user, $type) {
            if ($type === NotificationType::CreatorNewSeries || $type === NotificationType::SeriesApproved) {
                throw new RuntimeException('simulated notification failure');
            }

            return DispatchResult::Created;
        });
    });

    $series = e30s_published($creator, null, ['title' => 'نشر رغم فشل الإشعار']);

    expect(e30s_visible($series))->toBeTrue()->and($series->fresh()->published_revision_id)->not->toBeNull()
        ->and(CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->value('status'))->toBe('approved');
});

test('AM13: a series hidden or blocked before the worker runs is not announced', function () {
    Queue::fake();
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $follower = e16User();
    e29_follow($follower, $creator);
    $series = e30s_published($creator, $works);
    Queue::assertPushed(DispatchCreatorSeriesChunk::class);

    e30s_hide_work($works[0]);
    $revisionId = (int) $series->fresh()->published_revision_id;
    (new DispatchCreatorSeriesChunk($revisionId))->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));

    expect(e30s_series_notes($follower))->toHaveCount(0);
});

test('AM13: a large follower set is processed in bounded chunks chained by id cursor', function () {
    config(['player_notifications.chunk_size' => 3]);
    $creator = e28_creator();
    collect(range(1, 8))->each(fn () => e29_follow(e16User(), $creator));
    Queue::fake();
    $series = e30s_published($creator);
    $revisionId = (int) $series->fresh()->published_revision_id;

    $runs = 0;
    $job = new DispatchCreatorSeriesChunk($revisionId);

    while ($job !== null && $runs < 10) {
        Queue::fake();
        $job->handle(app(NotificationDispatcher::class), app(CreatorSeriesVisibilityService::class));
        $runs++;
        $job = Queue::pushed(DispatchCreatorSeriesChunk::class)->first();
    }

    expect($runs)->toBe(3)->and(DatabaseNotification::query()->where('type_key', 'creator_new_series')->count())->toBe(8);
});

test('AM13: a new approved revision of a published series is announced as an update only when enabled', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'سلسلة قابلة للتحديث']);
    expect(e30s_series_notes($follower))->toHaveCount(1);

    config(['creator_series.notifications.on_revision' => false]);
    $draft = app(CreatorSeriesService::class)->revise($creator, $series->public_id);
    app(CreatorSeriesService::class)->submit($creator, $series->public_id);
    app(CreatorSeriesReviewService::class)->approve($draft->fresh(), e28_reviewer());
    expect(e30s_series_notes($follower))->toHaveCount(1);

    config(['creator_series.notifications.on_revision' => true]);
    $draft2 = app(CreatorSeriesService::class)->revise($creator, $series->public_id);
    app(CreatorSeriesService::class)->submit($creator, $series->public_id);
    app(CreatorSeriesReviewService::class)->approve($draft2->fresh(), e28_reviewer());

    $notes = e30s_series_notes($follower);
    expect($notes)->toHaveCount(2)->and($notes->pluck('data.title')->implode(' '))->toContain('حدّث');
});

test('AM13: the notification master switch and the social-category preference are respected', function () {
    $creator = e28_creator();
    $follower = e16User();
    e29_follow($follower, $creator);
    config(['creator_series.notifications.on_new_series' => false]);

    e30s_published($creator);
    expect(e30s_series_notes($follower))->toHaveCount(0);
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesModerationTest.php`

`sha256: ac7bbbefcf4d4206b2f1cbadaa12163be4c59f44d49f52148291759a39b12375`

`````php
<?php

use App\Filament\Resources\CreatorSeriesRevisionResource;
use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ListCreatorSeriesRevisions;
use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ViewCreatorSeriesRevision;
use App\Models\CreatorSeriesRevision;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E30 (B) - لوحة مراجعة السلاسل في Filament: الدخول بالصلاحية، الاعتماد/طلب التعديل/الرفض بالأسباب، إخفاء/استعادة السلسلة، إخفاء/استعادة عنصر (أحجية)
| ولا يُقبل عنصر ليس من السلسلة، وصفحة التفاصيل تعرض ظهور كل عنصر ولا تعرض أي إجابة. كل قرار مدقَّق ومُشعِر.
*/

beforeEach(function () {
    e30s_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM15-143: the series review queue opens for a moderator and stays closed to players and to roles without creators.view', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator, null, ['title' => 'سلسلة تنتظر المراجعة']);

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful()->assertSee('سلسلة تنتظر المراجعة');
    auth()->logout();

    $this->actingAs(e16User());
    expect(CreatorSeriesRevisionResource::canViewAny())->toBeFalse();
    $this->get('/admin')->assertForbidden();
});

test('AM15-144: drafts are private to the creator and never reach the review queue', function () {
    $creator = e28_creator();
    e30s_draft($creator, null, ['title' => 'مسودة خاصة بالمبدع']);
    e30s_pending($creator, null, ['title' => 'مرسلة للمراجعة']);

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->assertSee('مرسلة للمراجعة')->assertDontSee('مسودة خاصة بالمبدع');
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'draft')->assertDontSee('مسودة خاصة بالمبدع');
});

test('AM15-145: a moderator approves; reasons are mandatory for changes/reject; a view-only role cannot act', function () {
    $creator = e28_creator();
    $toApprove = e30s_pending($creator, null, ['title' => 'للاعتماد']);
    $toReject = e30s_pending($creator, null, ['title' => 'للرفض']);
    $toChange = e30s_pending($creator, null, ['title' => 'لطلب التعديل']);

    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $this->actingAs($viewOnly);
    Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful()
        ->assertTableActionHidden('approve', $toApprove)->assertTableActionHidden('reject', $toApprove)->assertTableActionHidden('changes', $toApprove);
    auth()->logout();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('reject', $toReject, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('changes', $toChange, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($toReject->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('approve', $toApprove)->assertHasNoTableActionErrors();
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('reject', $toReject, ['note' => 'سبب الرفض مكتوب وواضح.'])->assertHasNoTableActionErrors();
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('changes', $toChange, ['note' => 'عدّل ترتيب العناصر.'])->assertHasNoTableActionErrors();

    expect($toApprove->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_APPROVED)
        ->and(e30s_visible($toApprove->series()->first()))->toBeTrue()
        ->and($toReject->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_REJECTED)
        ->and($toChange->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_CHANGES_REQUESTED)
        ->and(DB::table('operational_audit_logs')->whereIn('action', ['creator_series_approved', 'creator_series_rejected', 'creator_series_changes_requested'])->count())->toBe(3);
});

test('AM15-146: hiding a published series from the panel removes it everywhere and restoring brings it back; both are audited', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'سلسلة للإخفاء الإشرافي']);
    $revision = $series->publishedRevision()->value('id');

    $this->get(route('series.show', $series->public_id))->assertOk();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('hide', $revision, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('hide', $revision, ['note' => 'مخالفة لسياسة المحتوى.'])->assertHasNoTableActionErrors();
    auth()->logout();

    $this->get(route('series.show', $series->public_id))->assertNotFound();
    expect(e30s_visible($series))->toBeFalse();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')->callTableAction('restore', $revision)->assertHasNoTableActionErrors();
    auth()->logout();

    $this->get(route('series.show', $series->public_id))->assertOk();
    expect(DB::table('operational_audit_logs')->whereIn('action', ['creator_series_hidden', 'creator_series_restored'])->count())->toBe(2);
});

test('AM15-147: hiding one item (puzzle) from the panel hides the series by the live rule and restoring it returns the series', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $revision = $series->publishedRevision()->value('id');

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('hide_item', $revision, ['work' => $works[1]->public_id, 'note' => 'الأحجية مخالفة.'])->assertHasNoTableActionErrors();
    auth()->logout();

    expect($works[1]->refresh()->is_visible)->toBeFalse()->and(e30s_visible($series))->toBeFalse();
    $this->get(route('series.show', $series->public_id))->assertNotFound();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('restore_item', $revision, ['work' => $works[1]->public_id])->assertHasNoTableActionErrors();
    auth()->logout();

    expect($works[1]->refresh()->is_visible)->toBeTrue()->and(e30s_visible($series))->toBeTrue();
});

test('AM15-148: the item actions refuse a puzzle that is not an item of the series (forged form values change nothing)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, e30s_works($creator, 3));
    $revision = $series->publishedRevision()->value('id');
    $foreign = e28_published(e28_creator(), 'text', ['title' => 'عمل مبدع آخر']);
    $outsider = e28_published($creator, 'text', ['title' => 'عمل لنفس المبدع خارج السلسلة']);

    $this->actingAs(e28_reviewer('moderator'));

    foreach ([$foreign, $outsider] as $work) {
        Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
            ->callTableAction('hide_item', $revision, ['work' => $work->public_id, 'note' => 'محاولة إخفاء عمل ليس من السلسلة.']);

        expect($work->refresh()->is_visible)->toBeTrue();
    }

    expect(e30s_visible($series))->toBeTrue();
});

test('AM15-149: the detail page shows each item with its visibility and the live blockers, and never shows an answer', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $revision = $series->publishedRevision()->value('id');
    e30s_hide_work($works[2]);

    $this->actingAs(e28_reviewer('moderator'));
    $html = Livewire::test(ViewCreatorSeriesRevision::class, ['record' => $revision])->assertSuccessful()->html();

    expect($html)->toContain($works[0]->refresh()->published_title)->and($html)->toContain('غير ظاهرة')->and($html)->toContain('ظاهرة');
    e28_assert_no_secrets($html, ['القلم']);

    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')->assertSee('عنصر غير ظاهر');
});

test('AM15-150: a player without the permission cannot reach the review pages or call the actions', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);

    $this->actingAs(e16User());

    $this->get('/admin/creator-series-revisions')->assertForbidden();
    $this->get('/admin/creator-series-revisions/'.$revision->getKey())->assertForbidden();
    expect($revision->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_PENDING_REVIEW);
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php`

`sha256: 0abd83e0cd07c45cbbbce50d62fa6a949b2a2f7b5cf30573abbc3e2d77db93b4`

`````php
<?php

use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ListCreatorSeriesRevisions;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesAnalytics;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E30 (B) - الأداء. المبدأ كـE27/E28: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1) والسقوف مقيسة، والفهارس تُستعمل فعلًا. SQLite لا يقيس زمن MySQL؛ فالاختبارات بنيوية.
*/

beforeEach(function () {
    e30s_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM15-140: the public series page runs a small bounded number of queries (guest and signed-in)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, e30s_works($creator, 5));
    $player = e16User();
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();   // تسخين الصلاحيات/الإعدادات
    auth()->logout();
    $this->flushSession();

    $guest = e27_queries(fn () => $this->get(route('series.show', $series->public_id))->assertOk());
    $this->actingAs($player);
    $signedIn = e27_queries(fn () => $this->get(route('series.show', $series->public_id))->assertOk());

    expect($guest)->toBeLessThanOrEqual(10)->and($signedIn)->toBeLessThanOrEqual(14);
});

test('AM15-141: 5 vs 25 items do not explode the query count (series page, studio, creator profile, search, discovery)', function () {
    $small = e28_creator();
    $large = e28_creator();
    $smallSeries = e30s_published($small, e30s_works($small, 5), ['title' => 'سلسلة خمسة عناصر']);
    $largeSeries = e30s_published($large, e30s_works($large, 25), ['title' => 'سلسلة خمسة وعشرين']);
    $player = e16User();

    // تسخين كل المسارات مرة (كاش الصلاحيات والإعدادات) ثم القياس البارد للكاش المعني
    foreach ([$smallSeries, $largeSeries] as $s) {
        $this->actingAs($player)->get(route('series.show', $s->public_id))->assertOk();
    }
    auth()->logout();

    $measure = function (callable $request) use ($player) {
        Cache::flush();
        $this->actingAs($player);
        $count = e27_queries($request);
        auth()->logout();

        return $count;
    };

    $surfaces = [
        'series page' => fn ($s) => fn () => $this->get(route('series.show', $s->public_id))->assertOk(),
        'puzzle page with series context' => fn ($s) => fn () => $this->get(route('community.puzzles.show', ['work' => CreatorSeries::query()->find($s->id)->publishedRevision->items()->first()->work()->value('public_id'), 'series' => $s->public_id]))->assertOk(),
    ];

    foreach ($surfaces as $name => $factory) {
        expect($measure($factory($largeSeries)))->toBe($measure($factory($smallSeries)), $name.' query count depends on item count');
    }

    // استوديو المبدع (قائمة + تعديل) بعناصر 5 مقابل 25. كل مسار يُسخَّن مرة لكل مبدع ثم يُقاس القياس الثاني (كاش الصلاحيات/الإعدادات خارج المقارنة).
    $studio = function ($creator, $series) {
        $request = function () use ($creator, $series) {
            $this->actingAs($creator);
            $count = e27_queries(function () use ($series) {
                $this->get(route('creator.series.index'))->assertOk();
                $this->get(route('creator.series.edit', $series->public_id))->assertOk();
            });
            auth()->logout();

            return $count;
        };
        $request();

        return $request();
    };
    $smallStudio = $studio($small, $smallSeries);
    $largeStudio = $studio($large, $largeSeries);
    expect($largeStudio)->toBe($smallStudio, 'studio query count depends on item/work count');

    // البحث والاكتشاف وملف المبدع: لا يتبعون عدد العناصر
    foreach ([
        'search' => fn () => $this->get(route('search.index', ['q' => 'سلسلة', 'type' => 'creator_series']))->assertOk(),
        'discover' => fn () => $this->get(route('discover.index'))->assertOk(),
        'creator profile' => fn () => $this->get(route('creators.show', $large->creatorProfile()->value('slug')))->assertOk(),
    ] as $name => $request) {
        expect($measure($request))->toBeLessThanOrEqual(40, $name.' is not bounded');
    }
});

test('AM15-141b: progress is a single bounded query regardless of the number of items', function () {
    $creator = e28_creator();
    $works5 = e30s_works($creator, 5);
    $works25 = e30s_works($creator, 25);
    $player = e16User();

    $svc = app(\App\Services\CreatorSeries\CreatorSeriesProgressService::class);
    $items = fn (array $works) => collect($works)->map(fn ($w) => (object) ['work_id' => $w->id, 'public_id' => $w->public_id]);

    expect(e27_queries(fn () => $svc->forUser($player, $items($works5))))->toBe(1)
        ->and(e27_queries(fn () => $svc->forUser($player, $items($works25))))->toBe(1)
        ->and(e27_queries(fn () => $svc->forUser(null, $items($works25))))->toBe(0);
});

test('AM15-142: the moderation queue is paginated and its query count does not grow with the number of pending series', function () {
    config(['creator_series.moderation_page_size' => 10]);
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    config(['creator_series.limits.max_pending' => 100, 'creator_series.limits.max_per_creator' => 100]);

    for ($i = 0; $i < 12; $i++) {
        e30s_pending($creator, $works);
    }

    $admin = e28_reviewer('administrator');
    $this->actingAs($admin);

    $page = Livewire::test(ListCreatorSeriesRevisions::class);
    $records = $page->instance()->getTableRecords();

    expect($records->count())->toBe(10)->and($records->total())->toBe(12)->and($records->hasPages())->toBeTrue();
    $page->assertSuccessful();

    $queries12 = e27_queries(fn () => Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful());

    for ($i = 0; $i < 12; $i++) {
        e30s_pending($creator, $works);
    }

    $queries24 = e27_queries(fn () => Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful());
    expect($queries24)->toBeLessThanOrEqual($queries12 + 1);
});

test('AM15: hot series queries use their indexes (public id, creator listing, queue, items by revision)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);

    $plan = fn (string $sql, array $bindings = []) => collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings))->pluck('detail')->implode(' | ');

    expect($plan('select id from creator_series where public_id = ?', [$series->public_id]))->toContain('creator_series_public_id_unique')
        ->and($plan('select id from creator_series where slug = ?', [$series->slug]))->toContain('creator_series_slug_unique')
        ->and($plan('select id from creator_series where creator_profile_id = ? order by id', [$series->creator_profile_id]))->toContain('creator_series_owner_index')
        ->and($plan('select id from creator_series_revisions where status = ? order by submitted_at', ['pending_review']))->toContain('creator_series_revisions_queue_index')
        ->and($plan('select creator_puzzle_id from creator_series_items where creator_series_revision_id = ? order by position', [1]))->toContain('creator_series_items_position_unique')
        ->and($plan('select id from creator_series_items where creator_puzzle_id = ?', [1]))->toContain('creator_series_items_work_index');
});

test('AM15: the visibility rule is one query with indexed lookups (no scan of items by series)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);

    $query = app(CreatorSeriesVisibilityService::class)->visibleQuery()->where('creator_series.public_id', $series->public_id);
    $sql = $query->toSql();
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $query->getBindings()))->pluck('detail')->implode(' | ');

    expect($plan)->toContain('creator_series_public_id_unique')
        ->and($plan)->toMatch('/SEARCH csi USING (COVERING )?INDEX creator_series_items_(work|position)_unique \(creator_series_revision_id=\?\)/')   // عناصر النسخة بفهرس لا بمسح كامل
        ->and($plan)->not->toContain('SCAN csi')
        ->and(substr_count(strtolower($sql), 'select'))->toBeGreaterThan(1);   // استعلام واحد بشروط فرعية، لا حلقة PHP
});

test('AM15: creator analytics are aggregated in SQL, cached with a TTL, carry no identities and never track views', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $profile = $creator->creatorProfile()->first();
    $p1 = e16User();
    $p2 = e16User();
    $p3 = e16User();

    foreach ($works as $w) {
        e30s_solve($p1, $w);          // أكمل
    }
    e30s_solve($p2, $works[0]);       // بدأ فقط
    e30s_wrong($p3, $works[1]);

    $stats = app(CreatorSeriesAnalytics::class)->forCreator($profile);
    expect($stats[$series->id])->toBe(['players' => 3, 'starts' => 5, 'completions' => 1, 'completion_rate' => 33]);
    expect(app(CreatorSeriesAnalytics::class)->totals($stats))->toBe(['players' => 3, 'starts' => 5, 'completions' => 1, 'completion_rate' => 33]);

    // مخزَّنة: لاعب جديد لا يظهر قبل انتهاء الـTTL، ثم يظهر بعد مسح الكاش
    e30s_solve(e16User(), $works[2]);
    expect(app(CreatorSeriesAnalytics::class)->forCreator($profile)[$series->id]['players'])->toBe(3);
    Cache::flush();
    expect(app(CreatorSeriesAnalytics::class)->forCreator($profile)[$series->id]['players'])->toBe(4);

    // لا هويات ولا مشاهدات: لا أعمدة/جداول views ولا أسماء لاعبين في المخرجات
    expect(json_encode($stats))->not->toContain($p1->email)->not->toContain($p1->name)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('creator_series', 'views_count'))->toBeFalse();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk()->assertSee('نسبة الإكمال');
});

test('AM15: discovery caches ids only (never titles or names) and the cache is never an authority', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'عنوان لا يجوز تخزينه بالكاش']);
    $this->get(route('discover.index'))->assertOk()->assertSee('عنوان لا يجوز تخزينه بالكاش');

    $cached = Cache::get('discover.series.newest');
    expect($cached)->toBe([$series->id])->and(json_encode($cached))->not->toContain('عنوان');

    // معرّف مخزَّن لسلسلة لم تعد ظاهرة لا يعيد إظهارها
    app(\App\Services\CreatorSeries\CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('discover.index'))->assertOk()->assertDontSee('عنوان لا يجوز تخزينه بالكاش');
});

test('AM15: beyond the partial-match limit the series search filter is a bare-column prefix LIKE on the published title and reads nothing but the published snapshot', function () {
    config(['search.partial_match_max_id' => 0]);
    $creator = e28_creator();
    e30s_published($creator, null, ['title' => 'نمط سلسلة واحد']);
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $titles = e27_titles(e27_suggest($this, 'نمط', e16User()));
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "creator_series"'));
    expect($titles)->toContain('نمط سلسلة واحد')->and($query)->not->toBeNull();

    $where = substr($query['query'], (int) strpos($query['query'], ' from "'));
    expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
        ->and(str_contains($query['query'], 'creator_series_revisions'))->toBeFalse()   // لا ضمّ للنسخ
        ->and(str_contains($query['query'], 'description'))->toBeFalse();

    foreach ($query['bindings'] as $binding) {
        if (is_string($binding) && str_contains($binding, '%')) {
            expect($binding)->not->toStartWith('%');
        }
    }
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesPlayTest.php`

`sha256: ac4ecb73b5fcb7914e39e51da2ab2b41f860d4f819d9dcf71ee9ed62a6864dbd`

`````php
<?php

use App\Models\CommunityPuzzleAttempt;
use App\Models\PuzzleAttempt;
use App\Models\SponsorshipRewardBonus;
use App\Services\CreatorSeries\CreatorSeriesProgressService;
use App\Services\CreatorSeries\CreatorSeriesService;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SponsorshipE30/_support.php';

beforeEach(fn () => e30s_seed());

/** يستخرج تقدّم الصفحة (النسبة) ورابط «أكمل» من HTML صفحة السلسلة. */
function e30s_page(\Illuminate\Testing\TestResponse $response): array
{
    $html = $response->getContent();
    preg_match('/aria-valuenow="(\d+)" aria-label="تقدّمك في السلسلة"/u', $html, $pct);
    preg_match('/data-series-continue href="([^"]+)"/u', $html, $continue);

    return ['html' => $html, 'percent' => isset($pct[1]) ? (int) $pct[1] : null, 'continue' => isset($continue[1]) ? html_entity_decode($continue[1]) : null];
}

test('AM12-112: the series reuses the Community puzzle engine - play goes through the Community routes only', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    $html = $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk()->getContent();

    // الصفحة بلا نموذج لعب خاص: العناصر روابط لصفحات أحجيات المجتمع (مع سياق السلسلة) لا غير
    expect($html)->not->toContain('name="answer"')->and($html)->not->toContain('/attempt')
        ->and($html)->toContain(route('community.puzzles.show', ['work' => $works[0]->public_id, 'series' => $series->public_id]));

    $before = PuzzleAttempt::query()->count();
    $this->actingAs($player)->post(route('community.puzzles.attempt', $works[0]->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();

    expect(CommunityPuzzleAttempt::query()->where('user_id', $player->id)->where('creator_puzzle_id', $works[0]->id)->where('is_correct', true)->count())->toBe(1)
        ->and(PuzzleAttempt::query()->count())->toBe($before);

    // سياق السلسلة على صفحة الأحجية: الموضع والتالي
    $page = $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[1]->public_id, 'series' => $series->public_id]))->assertOk()
        ->assertSee('سلسلة: '.$series->published_title, false)->assertSee('2 من 3')->getContent();
    expect($page)->toContain('rel="prev"')->and($page)->toContain('rel="next"');

    // معامل سلسلة مختلق/لا يخص الأحجية = لا شريط ولا خطأ
    $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[1]->public_id, 'series' => 'garbage']))->assertOk()->assertDontSee('data-series-context', false);
    $other = e30s_published(e28_creator());
    $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[1]->public_id, 'series' => $other->public_id]))->assertOk()->assertDontSee('data-series-context', false);
});

test('AM12-113: a solved item counts toward progress (and a wrong attempt does not)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(0);

    e30s_wrong($player, $works[0]);
    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(0);

    e30s_solve($player, $works[1]);
    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['percent'])->toBe(33)->and($page['html'])->toContain('تقدّمك: 1 من 3');
});

test('AM12-114: a puzzle solved BEFORE the series existed still counts', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $player = e16User();
    e30s_solve($player, $works[0]);
    e30s_solve($player, $works[2]);

    $series = e30s_published($creator, $works);   // تُنشر بعد الحل

    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['percent'])->toBe(66)->and($page['html'])->toContain('تقدّمك: 2 من 3');
});

test('AM12-115: Continue opens the first incomplete item in order, not the first unsolved by id', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 4);
    $order = [$works[3], $works[1], $works[0], $works[2]];
    $series = e30s_published($creator, $order);
    $player = e16User();

    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['continue'])->toContain($order[0]->public_id)->and($page['html'])->toContain('ابدأ السلسلة');

    e30s_solve($player, $order[0]);
    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['continue'])->toContain($order[1]->public_id)->and($page['html'])->toContain('أكمل السلسلة');

    // حلّ الثالث فقط (قفز) ثم الثاني: الأول غير المكتمل بالترتيب هو الرابع
    e30s_solve($player, $order[2]);
    e30s_solve($player, $order[1]);
    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['continue'])->toContain($order[3]->public_id);

    // الضيف: «ابدأ» بأول عنصر
    auth()->logout();
    $this->flushSession();
    expect(e30s_page($this->get(route('series.show', $series->public_id)))['continue'])->toContain($order[0]->public_id);
});

test('AM12-116: completion is calculated exactly (progress service and page)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    foreach ($works as $work) {
        e30s_solve($player, $work);
    }

    $items = collect($works)->map(fn ($w) => (object) ['work_id' => $w->id, 'public_id' => $w->public_id]);
    $progress = app(CreatorSeriesProgressService::class)->forUser($player, $items);
    expect($progress)->toMatchArray(['done' => 3, 'total' => 3, 'percent' => 100, 'completed' => true, 'next_public_id' => null]);

    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['percent'])->toBe(100)->and($page['html'])->toContain('أكملت السلسلة')->and($page['continue'])->toBeNull();

    // حالات حدّية: بلا مستخدم، وبلا عناصر
    expect(app(CreatorSeriesProgressService::class)->forUser(null, $items))->toMatchArray(['done' => 0, 'percent' => 0, 'completed' => false])
        ->and(app(CreatorSeriesProgressService::class)->forUser($player, collect()))->toMatchArray(['done' => 0, 'total' => 0, 'percent' => 0, 'completed' => false]);
});

test('AM12-116b: a newer revision of an item (new canonical puzzle) is not "solved" until solved again; the creator never plays their own', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $series = e30s_published($creator, $works);
    $player = e16User();
    e30s_solve($player, $works[0]);
    e30s_solve($player, $works[1]);
    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(100);

    // المبدع يعدّل أحجية ويُعتمد إصدارها الجديد => نسخة قانونية جديدة (يلزم حلّها من جديد، كما بصفحة الأحجية)
    $revision = app(\App\Services\Creators\CreatorPuzzleService::class)->revise($creator, $works[0]->public_id);
    app(\App\Services\Creators\CreatorPuzzleService::class)->updateDraft($creator, $works[0]->public_id, e28_data('text', ['title' => 'نسخة جديدة من اللغز']));
    app(\App\Services\Creators\CreatorPuzzleService::class)->submit($creator, $works[0]->public_id);
    app(\App\Services\Creators\CreatorReviewService::class)->approve($revision->fresh(), e28_reviewer());

    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(50);

    // المالك: بلا تقدّم ولا «ابدأ»
    $html = $this->actingAs($creator)->get(route('series.show', $series->public_id))->assertOk()->getContent();
    expect($html)->toContain('هذه سلسلتك')->and($html)->not->toContain('data-series-continue');
});

test('AM12-117..122: playing a whole series changes nothing in the economy - zero gems, XP, quests, streak, competitive and leaderboard effect', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();
    // الطبقة العامة تنشئ صف تقدّم اللاعب كسولًا عند أول عرض لأي صفحة (سلوك قائم لا علاقة له بالسلاسل)؛ نسخّنه قبل اللقطة كي تقيس اللقطة اللعب وحده.
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();
    $before = e28_world($player);
    $leaderboardBefore = \App\Models\PlayerProgression::query()->sum('total_xp');

    foreach ($works as $work) {
        $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();
    }
    e30s_wrong($player, e30s_works($creator, 1)[0]);
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();

    $after = e28_world($player);
    // لقطة الاقتصاد كلها (محافظ، جواهر، XP، مهام، سلسلة أيام، تأهيل، منافسات، جلسات، احتيال) كما كانت
    expect($after['wallet'])->toBe($before['wallet'])->and($after['xp'])->toBe($before['xp'])->and($after['streak'])->toBe($before['streak'])->and($after['quest'])->toBe($before['quest'])
        ->and($after['counts'])->toBe($before['counts'])
        ->and(\App\Models\PlayerProgression::query()->sum('total_xp'))->toBe($leaderboardBefore);
});

test('AM6-60: creator series get ZERO sponsor reward boost even with an active funded boost program', function () {
    e30_seed();
    $program = e30_boost(e30_program(['title' => 'برنامج تعزيز المنصة']));
    e30_confirmed(e30_company(), $program, '500');

    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();
    $before = e28_world($player);
    $bonusRows = SponsorshipRewardBonus::query()->count();

    foreach ($works as $work) {
        $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();
    }

    expect(SponsorshipRewardBonus::query()->count())->toBe($bonusRows)->and(e28_world($player))->toBe($before);
    // الصفحة العامة للسلسلة بلا أي شريط رعاية ولا وعد بمكافأة
    $html = $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk()->getContent();
    expect($html)->not->toContain('data-sponsor')->and($html)->not->toContain('data-main-sponsor');
});

test('AM12: a hidden item makes Continue disappear with the series (no leak of the item list)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    e30s_hide_work($works[1]);
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertNotFound();
    $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[0]->public_id, 'series' => $series->public_id]))->assertOk()->assertDontSee('data-series-context', false);
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesReviewTest.php`

`sha256: a53945f8becfef94e9e019a70ce586a9d6789a068d109fd4ebecc2c6d8c03367`

`````php
<?php

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesException;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

function e30s_notes(\App\Models\User $user, string $type)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('type_key', $type)->get();
}

test('AM10-95: submitting locks the draft - no edit while pending, withdraw returns it to draft', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_draft($creator, $works);
    $publicId = $revision->series()->value('public_id');

    app(CreatorSeriesService::class)->submit($creator, $publicId);
    expect($revision->fresh()->status)->toBe('pending_review')->and($revision->fresh()->submitted_at)->not->toBeNull();

    expect(fn () => app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'تعديل أثناء المراجعة', 'items' => e30s_ids($works)]))->toThrow(CreatorSeriesException::class)
        ->and(fn () => app(CreatorSeriesService::class)->submit($creator, $publicId))->toThrow(CreatorSeriesException::class)
        ->and(fn () => app(CreatorSeriesService::class)->destroy($creator, $publicId))->toThrow(CreatorSeriesException::class);

    $this->actingAs($creator)->put(route('creator.series.update', $publicId), ['title' => 'تعديل أثناء المراجعة', 'items' => e30s_ids($works)])->assertSessionHas('error');
    expect($revision->fresh()->title)->not->toBe('تعديل أثناء المراجعة');

    app(CreatorSeriesService::class)->withdraw($creator, $publicId);
    expect($revision->fresh()->status)->toBe('draft');
});

test('AM10-96: a reviewer requests changes with a mandatory reason; the creator is notified', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);
    $reviewer = e28_reviewer();

    expect(fn () => app(CreatorSeriesReviewService::class)->requestChanges($revision, $reviewer, 'x'))->toThrow(CreatorSeriesException::class);

    $closed = app(CreatorSeriesReviewService::class)->requestChanges($revision, $reviewer, 'أعد ترتيب الأحجيات من الأسهل للأصعب.');
    expect($closed->status)->toBe('changes_requested')->and($closed->review_note)->toContain('أعد ترتيب')->and($closed->reviewed_by)->toBe($reviewer->id);

    $notes = e30s_notes($creator, 'series_changes_requested');
    expect($notes)->toHaveCount(1)->and($notes[0]->data['action_route'])->toBe('creator.series.index');
});

test('AM10-97: the creator edits after a changes request and re-submits', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_pending($creator, [$works[0], $works[1]]);
    $publicId = $revision->series()->value('public_id');
    app(CreatorSeriesReviewService::class)->requestChanges($revision, e28_reviewer(), 'أضف أحجية ثالثة للسلسلة.');

    app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'عنوان بعد التعديل', 'items' => e30s_ids($works)]);
    app(CreatorSeriesService::class)->submit($creator, $publicId);

    $fresh = $revision->fresh();
    expect($fresh->status)->toBe('pending_review')->and($fresh->title)->toBe('عنوان بعد التعديل')->and($fresh->review_note)->toBeNull()
        ->and($fresh->items()->count())->toBe(3);
});

test('AM10-98: a reviewer approves - the series is published with a snapshot, and the creator is notified', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_pending($creator, $works, ['title' => 'سلسلة جاهزة للنشر']);
    $reviewer = e28_reviewer();

    $approved = app(CreatorSeriesReviewService::class)->approve($revision, $reviewer, 'عمل ممتاز.');
    $series = $approved->series()->first();

    expect($approved->status)->toBe('approved')->and($approved->reviewed_by)->toBe($reviewer->id)->and($series->published_revision_id)->toBe($approved->id)
        ->and($series->published_title)->toBe('سلسلة جاهزة للنشر')->and($series->published_items_count)->toBe(3)->and($series->published_at)->not->toBeNull()
        ->and($series->first_published_at)->not->toBeNull()->and(e30s_visible($series))->toBeTrue()
        ->and(e30s_notes($creator, 'series_approved'))->toHaveCount(1);
});

test('AM10-99: an unauthorized reviewer (player, content-manager without creators.review) is denied', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);

    foreach ([e16User(), e28_reviewer('content-manager'), $creator] as $actor) {
        foreach (['approve', 'requestChanges', 'reject'] as $method) {
            $args = $method === 'approve' ? [$revision, $actor] : [$revision, $actor, 'سبب واضح للاختبار هنا.'];
            expect(fn () => app(CreatorSeriesReviewService::class)->{$method}(...$args))->toThrow(HttpException::class);
        }
    }

    expect($revision->fresh()->status)->toBe('pending_review');
});

test('AM10-100: rejection stores the reason, notifies, and the series stays non-public', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);
    $closed = app(CreatorSeriesReviewService::class)->reject($revision, e28_reviewer(), 'السلسلة لا تحقق الحد الأدنى من الجودة.');

    expect($closed->status)->toBe('rejected')->and($closed->review_note)->toContain('الجودة')->and($closed->series->published_revision_id)->toBeNull()
        ->and(e30s_visible($closed->series))->toBeFalse()->and(e30s_notes($creator, 'series_rejected'))->toHaveCount(1);

    $this->get(route('series.show', $closed->series->public_id))->assertNotFound();
    $this->actingAs($creator)->get(route('creator.series.edit', $closed->series->public_id))->assertOk()->assertSee('الجودة');
});

test('AM10-101: editing a published series requires a new revision that is reviewed; the published one stays live meanwhile', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'العنوان المنشور']);
    $publicId = $series->public_id;

    // لا تعديل مباشر للنسخة المعتمدة
    expect(fn () => app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'تعديل صامت', 'items' => e30s_ids($works)]))->toThrow(CreatorSeriesException::class);

    $draft = app(CreatorSeriesService::class)->revise($creator, $publicId);
    app(CreatorSeriesService::class)->updateDraft($creator, $publicId, ['title' => 'العنوان الجديد', 'items' => e30s_ids(array_reverse($works))]);

    // المنشورة باقية حتى الاعتماد
    $page = $this->get(route('series.show', $publicId))->assertOk()->assertSee('العنوان المنشور')->assertDontSee('العنوان الجديد');
    expect($series->fresh()->published_title)->toBe('العنوان المنشور');

    app(CreatorSeriesService::class)->submit($creator, $publicId);
    $this->get(route('series.show', $publicId))->assertSee('العنوان المنشور');

    app(CreatorSeriesReviewService::class)->approve($draft->fresh(), e28_reviewer());
    $this->get(route('series.show', $publicId))->assertOk()->assertSee('العنوان الجديد')->assertDontSee('العنوان المنشور');
    expect($series->fresh()->published_revision_id)->toBe($draft->id)
        ->and(CreatorSeriesRevision::query()->where('creator_series_id', $series->id)->count())->toBe(2)
        ->and(CreatorSeriesItem::query()->where('creator_series_revision_id', $series->published_revision_id)->count())->toBe(0 + 3);
});

test('AM10-102: approval, change request, rejection, hide and restore are audited', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $a = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->approve($a, $reviewer);
    $b = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->requestChanges($b, $reviewer, 'يحتاج مزيدًا من الوضوح.');
    $c = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->reject($c, $reviewer, 'مخالفة واضحة للإرشادات.');
    app(CreatorSeriesReviewService::class)->hide($a->series()->first(), $reviewer, 'سبب إخفاء إشرافي واضح.');
    app(CreatorSeriesReviewService::class)->restore($a->series()->first(), $reviewer);

    $actions = DB::table('operational_audit_logs')->pluck('action')->all();
    foreach (['creator_series_approved', 'creator_series_changes_requested', 'creator_series_rejected', 'creator_series_hidden', 'creator_series_restored'] as $action) {
        expect($actions)->toContain($action);
    }

    $row = DB::table('operational_audit_logs')->where('action', 'creator_series_approved')->first();
    expect((int) $row->actor_user_id)->toBe($reviewer->id)->and($row->subject_type)->toBe(CreatorSeriesRevision::class);
});

test('AM10: a revision that is no longer pending cannot be decided twice, and a stale item blocks approval', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_pending($creator, $works);
    $reviewer = e28_reviewer();

    // عنصر أُخفي بعد الإرسال: الاعتماد يرفض بدل نشر سلسلة لن تظهر
    e30s_hide_work($works[1]);
    expect(fn () => app(CreatorSeriesReviewService::class)->approve($revision, $reviewer))->toThrow(CreatorSeriesException::class);
    expect($revision->fresh()->status)->toBe('pending_review');

    e30s_restore_work($works[1]);
    app(CreatorSeriesReviewService::class)->approve($revision, $reviewer);
    expect(fn () => app(CreatorSeriesReviewService::class)->approve($revision, $reviewer))->toThrow(CreatorSeriesException::class);
});

test('AM10: submitting is blocked if an item was hidden after saving, and the pending cap is enforced', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $revision = e30s_draft($creator, $works);
    e30s_hide_work($works[0]);

    expect(fn () => app(CreatorSeriesService::class)->submit($creator, $revision->series()->value('public_id')))->toThrow(CreatorSeriesException::class);

    e30s_restore_work($works[0]);
    config(['creator_series.limits.max_pending' => 1]);
    e30s_pending($creator, $works);
    $second = e30s_draft($creator, $works);
    expect(fn () => app(CreatorSeriesService::class)->submit($creator, $second->series()->value('public_id')))->toThrow(CreatorSeriesException::class);
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesSecurityTest.php`

`sha256: c3403f843eefc2559d40e53f3c321c770da4ece66e4706cf3cc021f5ab9eac0a`

`````php
<?php

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesItem;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SecurityE26/_support.php';

beforeEach(function () {
    e30s_seed();
    Storage::fake('public');
});

/** مسارات متحكمات السلاسل وحدها. */
function e30s_series_routes(): array
{
    return collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains((string) $r->getActionName(), 'Controllers\\CreatorSeries\\'))->values()->all();
}

test('AM14-132: CSRF protects every mutating series route (419 without a token; all inside the web group)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $draft = e30s_draft($creator, $works)->series()->first();

    $before = [CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count(), CreatorSeriesItem::query()->count()];
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($creator);
        $this->post(route('creator.series.store'), ['title' => 'بلا رمز', 'items' => e30s_ids($works)])->assertStatus(419);
        $this->put(route('creator.series.update', $draft->public_id), ['title' => 'تعديل بلا رمز', 'items' => e30s_ids($works)])->assertStatus(419);
        $this->post(route('creator.series.submit', $draft->public_id))->assertStatus(419);
        $this->post(route('creator.series.withdraw', $draft->public_id))->assertStatus(419);
        $this->delete(route('creator.series.destroy', $draft->public_id))->assertStatus(419);
        $this->post(route('creator.series.revise', $series->public_id))->assertStatus(419);
        $this->post(route('creator.series.archive', $series->public_id))->assertStatus(419);
        $this->post(route('creator.series.unarchive', $series->public_id))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect([CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count(), CreatorSeriesItem::query()->count()])->toBe($before)
        ->and($draft->fresh()->latestRevision()->first()->title)->not->toBe('تعديل بلا رمز')->and($series->fresh()->isArchived())->toBeFalse();

    $checked = 0;

    foreach (e30s_series_routes() as $route) {
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $checked++;
        expect(in_array('web', $route->gatherMiddleware(), true))->toBeTrue('route '.$route->getName().' is outside the web group');
    }

    expect($checked)->toBe(8);
});

test('AM14-133: no GET request mutates anything (no views counter, no progress write, no notification, no row)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $draft = e30s_draft($creator, $works)->series()->first();
    $player = e16User();

    foreach (e30s_series_routes() as $route) {
        if (in_array('GET', $route->methods(), true)) {
            expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([], 'GET route also mutating: '.$route->getName());
        }
    }

    $tables = ['creator_series', 'creator_series_revisions', 'creator_series_items', 'community_puzzle_attempts', 'puzzle_attempts', 'notifications', 'operational_audit_logs', 'creator_puzzles'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => [DB::table($t)->count(), md5(json_encode(DB::table($t)->orderBy('id')->get()))]])->all();
    // أول عرض لصفحة يُنشئ صفوفًا كسولة للمستخدم (تقدّم/تفضيلات)؛ نسخّن بزيارة أولى ثم نقيس التكرار.
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk();
    $before = $snapshot();

    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();
    $this->get(route('series.show', $series->slug))->assertOk();
    $this->get(route('community.puzzles.show', ['work' => $works[0]->public_id, 'series' => $series->public_id]))->assertOk();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk();
    $this->get(route('creator.series.create'))->assertOk();
    $this->get(route('creator.series.edit', $draft->public_id))->assertOk();
    $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertOk();
    $this->get(route('discover.index'))->assertOk();
    $this->get(route('search.index', ['q' => 'سلسلة']))->assertOk();

    expect($snapshot())->toBe($before);
});

test('AM14-134: series IDOR - every cross-creator and cross-user access is 404 or denied, with no side effects', function () {
    $owner = e28_creator();
    $intruder = e28_creator();
    $works = e30s_works($owner, 3);
    $published = e30s_published($owner, $works);
    $draft = e30s_draft($owner, $works)->series()->first();

    $this->actingAs($intruder);
    foreach ([$published, $draft] as $series) {
        $this->get(route('creator.series.edit', $series->public_id))->assertNotFound();
        $this->put(route('creator.series.update', $series->public_id), ['title' => 'اختراق', 'items' => e30s_ids($works)])->assertNotFound();
        $this->post(route('creator.series.submit', $series->public_id))->assertNotFound();
        $this->post(route('creator.series.revise', $series->public_id))->assertNotFound();
        $this->post(route('creator.series.archive', $series->public_id))->assertNotFound();
        $this->delete(route('creator.series.destroy', $series->public_id))->assertNotFound();
    }

    // لا يستطيع مبدع آخر إدراج أحجية غيره بسلسلته ولا اللاعب العادي دخول الاستوديو
    $mine = e30s_works($intruder, 2);
    $this->post(route('creator.series.store'), ['title' => 'سلسلة بأحجية غيري', 'items' => [$mine[0]->public_id, $works[0]->public_id]])->assertSessionHas('error');
    $this->actingAs(e16User())->get(route('creator.series.index'))->assertRedirect(route('creator.apply'));
    $this->actingAs(e16User())->get(route('creator.series.edit', $published->public_id))->assertRedirect(route('creator.apply'));

    expect(CreatorSeries::query()->count())->toBe(2)->and($published->fresh()->isArchived())->toBeFalse();
});

test('AM14-135: series XSS - markup is rejected at input and anything stored is escaped on every surface', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);

    foreach (['<script>alert(1)</script>سلسلة', 'سلسلة <img src=x onerror=alert(1)>'] as $evil) {
        $this->actingAs($creator)->post(route('creator.series.store'), ['title' => $evil, 'items' => e30s_ids($works)])->assertSessionHasErrors('title');
        $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'عنوان سليم', 'description' => $evil, 'items' => e30s_ids($works)])->assertSessionHasErrors('description');
    }
    expect(CreatorSeries::query()->count())->toBe(0);

    // دفاع ثانٍ: حتى لو وصل نص خبيث لقاعدة البيانات (خارج المسار الرسمي) يُهرَّب عند العرض
    $series = e30s_published($creator, $works, ['title' => 'سلسلة آمنة']);
    $payload = '<script>window.xss=1</script>';
    DB::table('creator_series')->where('id', $series->id)->update(['published_title' => $payload]);
    DB::table('creator_series_revisions')->where('id', $series->published_revision_id)->update(['title' => $payload, 'description' => $payload]);
    $slug = $creator->creatorProfile()->value('slug');

    foreach ([
        route('series.show', $series->public_id), route('creators.show', $slug), route('discover.index'), route('search.index', ['q' => 'script']),
    ] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>window.xss')->and($html)->not->toContain('window.xss=1</script>');
    }

    $this->get(route('series.show', $series->public_id))->assertSee('&lt;script&gt;window.xss=1&lt;/script&gt;', false);
    $this->actingAs($creator)->get(route('creator.series.index'))->assertOk();
});

test('AM14: forbidden authority/economy fields in the request are rejected explicitly, never ignored', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $forbidden = ['gem_reward' => 100, 'xp_reward' => 50, 'status' => 'approved', 'published_revision_id' => 1, 'is_sponsored' => 1, 'sponsorship_program_id' => 1, 'creator_profile_id' => 99, 'slug' => 'hijack', 'hidden_at' => now()->toDateTimeString()];

    // طلب واحد بكل الحقول الممنوعة (محدّد creator-draft-create يعدّ كل محاولة)
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بحقل ممنوع', 'items' => e30s_ids($works)] + $forbidden)->assertSessionHasErrors(array_keys($forbidden));
    expect(CreatorSeries::query()->count())->toBe(0);

    // وكل حقل لوحده يُرفض كذلك (دون تجاوز المحدِّد)
    foreach (['gem_reward', 'status', 'slug'] as $field) {
        $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بحقل ممنوع', 'items' => e30s_ids($works), $field => $forbidden[$field]])->assertSessionHasErrors($field);
    }

    expect(CreatorSeries::query()->count())->toBe(0);
});

test('AM14: models are mass-assignment safe (guarded) - only the services write', function () {
    foreach ([CreatorSeries::class, CreatorSeriesRevision::class, CreatorSeriesItem::class] as $model) {
        expect(fn () => $model::query()->create(['title' => 'x', 'status' => 'approved', 'published_revision_id' => 1]))->toThrow(MassAssignmentException::class);
    }
});

test('AM14: cover upload - only JPEG/PNG/WEBP by real content; SVG, scripts and disguised files are rejected; replacement cleans the old file', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $base = ['title' => 'سلسلة بغلاف', 'items' => e30s_ids($works)];

    $svg = UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $php = UploadedFile::fake()->createWithContent('cover.png', '<?php echo "pwn"; ?>');
    $fakePng = UploadedFile::fake()->createWithContent('cover.png', 'not really an image at all');

    foreach ([$svg, $php, $fakePng] as $bad) {
        $this->actingAs($creator)->post(route('creator.series.store'), $base + ['cover' => $bad])->assertSessionHasErrors('cover');
    }
    expect(CreatorSeries::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);

    $this->actingAs($creator)->post(route('creator.series.store'), $base + ['cover' => UploadedFile::fake()->image('a.png', 300, 200)])->assertSessionHasNoErrors();
    $series = CreatorSeries::query()->firstOrFail();
    $revision = $series->latestRevision;
    $path = $revision->cover_path;

    expect($path)->toStartWith(trim((string) config('creators.media.directory'), '/').'/'.$creator->creatorProfile()->value('id').'/')->and($path)->toEndWith('.png')
        ->and(str_contains($path, 'cover'))->toBeFalse()->and(Storage::disk('public')->exists($path))->toBeTrue();

    $this->actingAs($creator)->put(route('creator.series.update', $series->public_id), $base + ['cover' => UploadedFile::fake()->image('b.jpg', 300, 200)])->assertSessionHasNoErrors();
    $newPath = $revision->fresh()->cover_path;
    expect($newPath)->not->toBe($path)->and(Storage::disk('public')->exists($path))->toBeFalse()->and(Storage::disk('public')->exists($newPath))->toBeTrue();

    $this->actingAs($creator)->put(route('creator.series.update', $series->public_id), $base + ['remove_cover' => 1])->assertSessionHasNoErrors();
    expect($revision->fresh()->cover_path)->toBeNull()->and(Storage::disk('public')->exists($newPath))->toBeFalse();
});

test('AM14: the media prune command never deletes a series cover that is still referenced (and removes true orphans)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $this->actingAs($creator)->post(route('creator.series.store'), ['title' => 'سلسلة بغلاف محمي', 'items' => e30s_ids($works), 'cover' => UploadedFile::fake()->image('a.png', 300, 200)])->assertSessionHasNoErrors();
    $series = CreatorSeries::query()->firstOrFail();
    $cover = $series->latestRevision->cover_path;

    $orphan = trim((string) config('creators.media.directory'), '/').'/'.$creator->creatorProfile()->value('id').'/orphan.png';
    Storage::disk('public')->put($orphan, 'x');
    touch(Storage::disk('public')->path($orphan), now()->subDays(3)->getTimestamp());
    touch(Storage::disk('public')->path($cover), now()->subDays(3)->getTimestamp());

    $this->artisan('creators:prune-media')->assertSuccessful();

    expect(Storage::disk('public')->exists($cover))->toBeTrue()->and(Storage::disk('public')->exists($orphan))->toBeFalse();
});

test('AM14: the public series page is rate limited and the studio drafting uses the existing creator limiters', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);
    $statuses = e26_hammer($this, 'get', route('series.show', $series->public_id), 125);
    expect(e26_first_429($statuses))->toBe(121);
});

test('AM14: new series views never use unescaped output for data', function () {
    $files = array_merge(glob(resource_path('views/series/*.blade.php')), glob(resource_path('views/creator/series/*.blade.php')));
    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        expect(str_contains($source, '{!!'))->toBeFalse(basename($file).' uses {!! !!}')
            ->and(preg_match('/\bdd\(|dump\(|console\.log/', $source))->toBe(0);
    }
});

test('AM14: a disabled feature returns 404 from every series surface but keeps notification routes defined', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);
    config(['creator_series.enabled' => false]);

    $this->get(route('series.show', $series->public_id))->assertNotFound();
    $this->actingAs($creator)->get(route('creator.series.index'))->assertNotFound();
    expect(Route::has('series.show'))->toBeTrue()->and(Route::has('creator.series.index'))->toBeTrue();
    $this->get(route('discover.index'))->assertOk();
    $this->get(route('search.index', ['q' => 'سلسلة']))->assertOk();
});
`````

### `tests/Feature/CreatorSeriesE30/E30SeriesVisibilityTest.php`

`sha256: e51e6de1dec26a66f96f314811660043a3a9b3fff1fc35b5dc7d5f89674cff3c`

`````php
<?php

use App\Models\CreatorSeries;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Creators\CreatorApplicationService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

test('AM11-103: a published series is public for guests (by public id and by slug) with the community badge and no rewards wording', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'سلسلة الغيوم المضيئة', 'description' => 'ثلاث ألغاز عن السماء.']);

    foreach ([$series->public_id, $series->slug] as $key) {
        $this->get(route('series.show', $key))->assertOk()->assertSee('سلسلة الغيوم المضيئة')->assertSee('ثلاث ألغاز عن السماء.')->assertSee('من المجتمع')->assertSee('بلا جوائز');
    }

    $html = $this->get(route('series.show', $series->public_id))->getContent();
    expect(substr_count($html, 'data-series-card'))->toBe(0);
    e28_assert_private_absent($html, $creator);
    e28_assert_no_secrets($html);
    // RTL: التخطيط العام يضبط dir=rtl
    expect($html)->toContain('dir="rtl"');
});

test('AM11-104: a draft (and a pending) series is not public', function () {
    $creator = e28_creator();
    $draft = e30s_draft($creator)->series()->first();
    $pending = e30s_pending($creator)->series()->first();

    foreach ([$draft, $pending] as $series) {
        $this->get(route('series.show', $series->public_id))->assertNotFound();
        $this->get(route('series.show', $series->slug))->assertNotFound();
        expect(e30s_visible($series))->toBeFalse();
    }
});

test('AM11-105: a rejected series is not public', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->reject($revision, e28_reviewer(), 'لا تحقق الحد الأدنى من الجودة.');

    $this->get(route('series.show', $revision->series()->value('public_id')))->assertNotFound();
});

test('AM11-106: a suspended creator hides the series everywhere and un-suspending restores it', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'سلسلة المبدع المعلَّق']);
    $profile = $creator->creatorProfile()->first();

    $this->get(route('series.show', $series->public_id))->assertOk();
    $this->get(route('creators.show', $profile->slug))->assertOk()->assertSee('سلسلة المبدع المعلَّق');

    app(CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'تعليق تجريبي للاختبار هنا.');
    $this->get(route('series.show', $series->public_id))->assertNotFound();
    $this->get(route('search.index', ['q' => 'المعلَّق']))->assertOk()->assertDontSee('سلسلة المبدع المعلَّق');
    expect(e30s_visible($series))->toBeFalse();

    app(CreatorApplicationService::class)->restore($profile->fresh(), e28_reviewer('administrator'));
    $this->get(route('series.show', $series->public_id))->assertOk();
    expect(e30s_visible($series))->toBeTrue();
});

test('AM11-106b: a frozen creator account hides the series too', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);

    $creator->forceFill(['is_frozen' => true])->save();
    $this->get(route('series.show', $series->public_id))->assertNotFound();

    $creator->forceFill(['is_frozen' => false])->save();
    $this->get(route('series.show', $series->public_id))->assertOk();
});

test('AM11-107: a hidden required puzzle hides the whole series', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);

    $this->get(route('series.show', $series->public_id))->assertOk();
    e30s_hide_work($works[1]);

    $this->get(route('series.show', $series->public_id))->assertNotFound();
    expect(e30s_visible($series))->toBeFalse()
        ->and(app(CreatorSeriesVisibilityService::class)->blockers($series->fresh()))->not->toBe([])
        ->and(app(CreatorSeriesVisibilityService::class)->hiddenItemCount((int) $series->fresh()->published_revision_id))->toBe(1);
});

test('AM11-108: restoring the puzzle restores the eligible series (and an ineligible one stays hidden)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $seriesA = e30s_published($creator, [$works[0], $works[1]]);
    $seriesB = e30s_published($creator, [$works[1], $works[2]]);

    e30s_hide_work($works[1]);
    expect(e30s_visible($seriesA))->toBeFalse()->and(e30s_visible($seriesB))->toBeFalse();

    e30s_restore_work($works[1]);
    expect(e30s_visible($seriesA))->toBeTrue()->and(e30s_visible($seriesB))->toBeTrue();

    // سلسلة مخفية إشرافيًا تبقى مخفية حتى باستعادة الأحجية
    app(CreatorSeriesReviewService::class)->hide($seriesA->fresh(), e28_reviewer(), 'سبب إخفاء إشرافي واضح.');
    e30s_hide_work($works[0]);
    e30s_restore_work($works[0]);
    expect(e30s_visible($seriesA))->toBeFalse()->and(e30s_visible($seriesB))->toBeTrue();

    app(CreatorSeriesReviewService::class)->restore($seriesA->fresh(), e28_reviewer());
    expect(e30s_visible($seriesA))->toBeTrue();
});

test('AM11-109: an archived series is hidden and un-archiving restores it', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);

    app(CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('series.show', $series->public_id))->assertNotFound();
    expect(e30s_visible($series))->toBeFalse();

    app(CreatorSeriesService::class)->unarchive($creator, $series->public_id);
    $this->get(route('series.show', $series->public_id))->assertOk();
});

test('AM11-110: Search finds a visible series (own type tab) and hides every blocked state', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'سلسلة العنقاء الفريدة']);

    $this->get(route('search.index', ['q' => 'العنقاء']))->assertOk()->assertSee('سلسلة العنقاء الفريدة')->assertSee('سلسلة من المجتمع');
    $this->get(route('search.index', ['q' => 'العنقاء', 'type' => 'creator_series']))->assertOk()->assertSee('سلسلة العنقاء الفريدة');
    $json = json_encode($this->getJson(route('search.suggest', ['q' => 'العنقاء']))->assertOk()->json(), JSON_UNESCAPED_UNICODE);
    expect($json)->toContain('العنقاء')->and($json)->not->toContain('"id"')->and($json)->not->toContain('creator_profile');

    // لا تُكتشف السلسلة بمحتوى الأحجيات ولا بجواب
    $this->get(route('search.index', ['q' => 'القلم', 'type' => 'creator_series']))->assertOk()->assertDontSee('سلسلة العنقاء الفريدة');

    e30s_hide_work($works[0]);
    $this->get(route('search.index', ['q' => 'العنقاء']))->assertOk()->assertDontSee('سلسلة العنقاء الفريدة');
    e30s_restore_work($works[0]);

    app(CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('search.index', ['q' => 'العنقاء', 'type' => 'creator_series']))->assertDontSee('سلسلة العنقاء الفريدة');
    app(CreatorSeriesService::class)->unarchive($creator, $series->public_id);

    app(CreatorSeriesReviewService::class)->hide($series->fresh(), e28_reviewer(), 'سبب إخفاء إشرافي واضح.');
    $this->get(route('search.index', ['q' => 'العنقاء']))->assertOk()->assertDontSee('سلسلة العنقاء الفريدة');
});

test('AM11-110b: a draft or pending series never appears in Search', function () {
    $creator = e28_creator();
    e30s_draft($creator, null, ['title' => 'سلسلة مسودة سرية']);
    e30s_pending($creator, null, ['title' => 'سلسلة معلقة سرية']);

    $this->get(route('search.index', ['q' => 'سرية']))->assertOk()->assertDontSee('مسودة سرية')->assertDontSee('معلقة سرية');
});

test('AM11-111: Discovery lists visible series and hides blocked ones immediately, even while ids are cached', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'سلسلة الفجر الجديد']);

    $this->get(route('discover.index'))->assertOk()->assertSee('سلاسل من المجتمع')->assertSee('سلسلة الفجر الجديد')->assertSee('سلسلة من المجتمع');
    expect(Cache::has('discover.series.newest'))->toBeTrue();

    e30s_hide_work($works[2]);   // المعرّفات ما زالت بالكاش، والظهور حيّ
    $this->get(route('discover.index'))->assertOk()->assertDontSee('سلسلة الفجر الجديد');
    e30s_restore_work($works[2]);
    $this->get(route('discover.index'))->assertOk()->assertSee('سلسلة الفجر الجديد');

    $profile = $creator->creatorProfile()->first();
    app(CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'تعليق تجريبي للاختبار هنا.');
    $this->get(route('discover.index'))->assertOk()->assertDontSee('سلسلة الفجر الجديد');
});

test('AM11: followers get a "series from people you follow" section, others do not', function () {
    $creator = e28_creator();
    $follower = e16User();
    $other = e16User();
    e29_follow($follower, $creator);
    e30s_published($creator, null, ['title' => 'سلسلة من متابَع']);

    $this->actingAs($follower)->get(route('discover.index'))->assertOk()->assertSee('سلاسل جديدة ممّن تتابعهم')->assertSee('من مبدع تتابعه');
    $this->actingAs($other)->get(route('discover.index'))->assertOk()->assertDontSee('سلاسل جديدة ممّن تتابعهم');
});

test('AM11: the creator public profile shows Series next to Puzzles, only the visible ones', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $visible = e30s_published($creator, $works, ['title' => 'سلسلة ظاهرة بالملف']);
    $hidden = e30s_published($creator, [$works[0], $works[1]], ['title' => 'سلسلة مؤرشفة بالملف']);
    app(CreatorSeriesService::class)->archive($creator, $hidden->public_id);
    $slug = $creator->creatorProfile()->value('slug');

    $this->get(route('creators.show', $slug))->assertOk()->assertSee('سلاسله')->assertSee('سلسلة ظاهرة بالملف')->assertDontSee('سلسلة مؤرشفة بالملف')->assertSee('أحجياته');
});

test('AM11: an unknown or malformed series key is a clean 404', function () {
    foreach (['nope', '01ZZZZZZZZZZZZZZZZZZZZZZZZ', str_repeat('a', 40)] as $key) {
        $this->get('/series/'.$key)->assertNotFound();
    }
});
`````

### `tests/Feature/CreatorSeriesE30/_support.php`

`sha256: 286093903c62deeeb8abccba286e5fec4eae40c372785acdcec86cb0b1708ba8`

`````php
<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorVisibilityService;

/*
| E30 (سلاسل المبدعين) - مساعدات مشتركة. كل شيء على SQLite وكاش array وطابور sync؛ لا شبكة. كل التهيئة تمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات).
| تعتمد على مساعدات E28 (e28_creator/e28_published/e28_reviewer) و E29 (e29_follow) و E27 (e27_queries).
*/

require_once __DIR__.'/../EngagementE29/_support.php';

if (! function_exists('e30s_seed')) {
    function e30s_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** @return list<CreatorPuzzle> $n أعمال منشورة للمبدع. */
    function e30s_works(User $creator, int $n = 3): array
    {
        $works = [];

        for ($i = 1; $i <= $n; $i++) {
            $works[] = e28_published($creator, 'text', ['title' => 'لغز السلسلة '.fake()->unique()->numerify('######')]);
        }

        return $works;
    }

    /** @param list<CreatorPuzzle> $works */
    function e30s_ids(array $works): array
    {
        return array_values(array_map(fn (CreatorPuzzle $w) => $w->public_id, $works));
    }

    function e30s_draft(User $creator, ?array $works = null, array $over = []): CreatorSeriesRevision
    {
        $works ??= e30s_works($creator, 3);

        return app(CreatorSeriesService::class)->createDraft($creator, $over + ['title' => 'سلسلة '.fake()->unique()->numerify('####'), 'description' => 'وصف قصير للسلسلة.', 'items' => e30s_ids($works)]);
    }

    function e30s_pending(User $creator, ?array $works = null, array $over = []): CreatorSeriesRevision
    {
        $revision = e30s_draft($creator, $works, $over);

        return app(CreatorSeriesService::class)->submit($creator, $revision->series()->value('public_id'));
    }

    /** سلسلة منشورة فعلًا (مسودة ← إرسال ← اعتماد). */
    function e30s_published(User $creator, ?array $works = null, array $over = [], ?User $reviewer = null): CreatorSeries
    {
        $revision = e30s_pending($creator, $works, $over);
        app(CreatorSeriesReviewService::class)->approve($revision, $reviewer ?? e28_reviewer());

        return $revision->series()->first();
    }

    /** يحلّ العمل بالإجابة الصحيحة كلاعب مجتمع (الإجابة الافتراضية لمساعد E28 هي «القلم»). */
    function e30s_solve(User $player, CreatorPuzzle $work): array
    {
        return app(CommunityPlayService::class)->attempt($player, $work->fresh(), 'القلم');
    }

    function e30s_wrong(User $player, CreatorPuzzle $work): array
    {
        return app(CommunityPlayService::class)->attempt($player, $work->fresh(), 'جواب خاطئ تمامًا');
    }

    function e30s_hide_work(CreatorPuzzle $work, ?User $moderator = null): void
    {
        app(CreatorVisibilityService::class)->hide($work->fresh(), $moderator ?? e28_reviewer(), 'إخفاء تجريبي لأغراض الاختبار.');
    }

    function e30s_restore_work(CreatorPuzzle $work, ?User $moderator = null): void
    {
        app(CreatorVisibilityService::class)->restore($work->fresh(), $moderator ?? e28_reviewer());
    }

    /** هل السلسلة ظاهرة بالقاعدة المركزية؟ */
    function e30s_visible(CreatorSeries $series): bool
    {
        return app(\App\Services\CreatorSeries\CreatorSeriesVisibilityService::class)->isVisible($series->fresh());
    }
}
`````

### `tests/Feature/SponsorshipE30/E30AdminTest.php`

`sha256: 54fa420a142df722722447ed601d6f62f7e29e54e66de197271fbc4e9fe3b28e`

`````php
<?php

use App\Filament\Resources\SponsorshipContributionResource\Pages\ListSponsorshipContributions;
use App\Filament\Resources\SponsorshipProgramResource\Pages\ListSponsorshipPrograms;
use App\Filament\Resources\SupporterProfileResource\Pages\CreateSupporterProfile;
use App\Filament\Resources\SupporterProfileResource\Pages\EditSupporterProfile;
use App\Filament\Resources\SupporterProfileResource\Pages\ListSupporterProfiles;
use App\Filament\Resources\SupporterTierResource\Pages\EditSupporterTier;
use App\Filament\Resources\SupporterTierResource\Pages\ListSupporterTiers;
use App\Models\SponsorshipContribution;
use App\Models\SupporterProfile;
use App\Models\SupporterTier;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e30_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM14-129: sponsorship admin pages respect permissions (view / manage / confirm / reward_plan are separate)', function () {
    $admin = e30_admin();
    $manager = e30_manager();
    $moderator = e28_reviewer('moderator');
    $player = e16User();

    foreach ([$admin, $manager] as $allowed) {
        $this->flushSession(); // AuthenticateSession يقارن بصمة كلمة المرور المخزنة بالجلسة: تبديل المستخدم داخل اختبار واحد يتطلب جلسة نظيفة
        $this->actingAs($allowed);
        foreach (['supporter-profiles', 'sponsorship-programs', 'sponsorship-contributions', 'supporter-tiers'] as $path) {
            $this->get("/admin/{$path}")->assertOk();
        }
    }

    foreach ([$moderator, $player] as $denied) {
        $this->flushSession();
        $this->actingAs($denied);
        expect(\App\Filament\Resources\SupporterProfileResource::canViewAny())->toBeFalse()->and(\App\Filament\Resources\SponsorshipContributionResource::canViewAny())->toBeFalse();
        foreach (['supporter-profiles', 'sponsorship-programs', 'sponsorship-contributions', 'supporter-tiers'] as $path) {
            $this->get("/admin/{$path}")->assertForbidden();
        }
    }

    expect($manager->can('sponsorships.manage'))->toBeTrue()->and($manager->can('sponsorships.confirm'))->toBeFalse()->and($manager->can('sponsorships.reward_plan'))->toBeFalse()
        ->and($admin->can('sponsorships.confirm'))->toBeTrue()->and($admin->can('sponsorships.reward_plan'))->toBeTrue();
});

test('AM14-129b: only a financial role confirms, cancels or refunds; a content manager sees those actions hidden', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = e30_pending($supporter, $program, '25');
    $confirmed = e30_confirmed($supporter, $program, '10');

    $this->actingAs(e30_manager());
    Livewire::test(ListSponsorshipContributions::class)->assertSuccessful()
        ->assertTableActionHidden('confirm', $pending)->assertTableActionHidden('cancel', $pending)->assertTableActionHidden('refund', $confirmed);

    auth()->logout();
    $this->actingAs(e30_admin());
    Livewire::test(ListSponsorshipContributions::class)->assertTableActionVisible('confirm', $pending)->callTableAction('confirm', $pending)->assertHasNoTableActionErrors();
    expect($pending->fresh()->status)->toBe('confirmed');

    Livewire::test(ListSponsorshipContributions::class)->callTableAction('refund', $confirmed, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    Livewire::test(ListSponsorshipContributions::class)->callTableAction('refund', $confirmed, ['reason' => 'طلب الداعم الاسترداد'])->assertHasNoTableActionErrors();
    expect($confirmed->fresh()->status)->toBe('refunded');
});

test('AM14-129c: a content manager records a pending contribution from the page; amounts are validated', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $this->actingAs(e30_manager());

    Livewire::test(ListSponsorshipContributions::class)->callAction('record', ['supporter_id' => $supporter->id, 'program_id' => $program->id, 'amount' => '25.50', 'currency' => 'USD', 'is_anonymous' => true, 'notes' => 'ملاحظة خاصة'])->assertHasNoActionErrors();
    $row = SponsorshipContribution::query()->first();
    expect($row->status)->toBe('pending')->and($row->original_amount_minor)->toBe(2550)->and($row->is_anonymous)->toBeTrue();

    Livewire::test(ListSponsorshipContributions::class)->callAction('record', ['supporter_id' => $supporter->id, 'program_id' => $program->id, 'amount' => '-3', 'currency' => 'USD']);
    expect(SponsorshipContribution::query()->count())->toBe(1);
});

test('AM14-129d: programs - activate, roles and the reward plan are gated by their own permissions', function () {
    $program = e30_program([], false);
    $supporter = e30_company();

    $this->actingAs(e30_manager());
    Livewire::test(ListSponsorshipPrograms::class)->assertSuccessful()->assertTableActionVisible('activate', $program)->assertTableActionHidden('reward_plan', $program)->assertTableActionHidden('lock_plan', $program);

    auth()->logout();
    $this->actingAs(e30_admin());
    Livewire::test(ListSponsorshipPrograms::class)->callTableAction('activate', $program)->assertHasNoTableActionErrors();
    expect($program->fresh()->status)->toBe('active');
    e30_confirmed($supporter, $program->fresh(), '90');

    Livewire::test(ListSponsorshipPrograms::class)->callTableAction('assign_role', $program, ['supporter_id' => $supporter->id, 'role' => 'main_sponsor'])->assertHasNoTableActionErrors();
    expect(\App\Models\SponsorshipProgramRole::query()->where('role', 'main_sponsor')->count())->toBe(1);

    Livewire::test(ListSponsorshipPrograms::class)->callTableAction('reward_plan', $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 500])->assertHasNoTableActionErrors();
    expect($program->fresh())->reward_boost_enabled->toBeTrue()->funding_step_minor->toBe(1000);
});

test('AM1-admin: a content manager creates and edits a supporter through the service (validation, audit, safe URL)', function () {
    $this->actingAs(e30_manager());

    Livewire::test(CreateSupporterProfile::class)->fillForm(['display_name' => 'شركة المثال', 'type' => 'organization', 'website_url' => 'https://example.com', 'is_public' => true, 'status' => 'active'])->call('create')->assertHasNoFormErrors();
    $profile = SupporterProfile::query()->where('display_name', 'شركة المثال')->first();
    expect($profile)->not->toBeNull()->and($profile->type)->toBe('organization');

    Livewire::test(CreateSupporterProfile::class)->fillForm(['display_name' => 'سيئ', 'type' => 'individual', 'website_url' => 'javascript:alert(1)'])->call('create')->assertHasFormErrors(['website_url']);
    expect(SupporterProfile::query()->where('display_name', 'سيئ')->exists())->toBeFalse();

    Livewire::test(EditSupporterProfile::class, ['record' => $profile->getRouteKey()])->fillForm(['display_name' => 'شركة المثال المعدّلة', 'is_public' => false])->call('save')->assertHasNoFormErrors();
    expect($profile->fresh())->display_name->toBe('شركة المثال المعدّلة')->is_public->toBeFalse();
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'supporter_updated']);

    Livewire::test(ListSupporterProfiles::class)->assertSuccessful()->assertSee('شركة المثال المعدّلة');
});

test('AM3-admin: tiers are editable by managers, audited, and an edit takes effect immediately', function () {
    $this->actingAs(e30_manager());
    $gold = SupporterTier::query()->where('slug', 'gold')->first();

    Livewire::test(ListSupporterTiers::class)->assertSuccessful()->assertSee('ذهبي');
    Livewire::test(EditSupporterTier::class, ['record' => $gold->getKey()])->fillForm(['name' => 'ذهبي مميز', 'minimum_decimal' => '1000', 'is_active' => true])->call('save')->assertHasNoFormErrors();

    expect($gold->fresh())->name->toBe('ذهبي مميز')->minimum_accounting_amount_minor->toBe(100000);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'supporter_tier_saved']);

    $supporter = e30_supporter();
    e30_confirmed($supporter, e30_program(), '300');
    expect(app(\App\Services\Sponsorship\SupporterTierService::class)->forSupporter($supporter)?->slug)->toBe('silver');
});
`````

### `tests/Feature/SponsorshipE30/E30CompetitionFairnessTest.php`

`sha256: f44b45ac781957c1f92aac067083f4fc0542c14131be1845935d3939c325b551`

`````php
<?php

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../CompetitiveRewards/RewardTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveRewardGrant;
use App\Models\CurrencyTransaction;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipRewardBonus;
use App\Services\Competitive\Rewards\CompetitiveRewardDistributionService;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Sponsorship\EventRewardPlanService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    e30_seed();
    e17Freeze();
});
afterEach(fn () => Carbon::setTestNow());

/** حدث قادم بجائزة جواهر افتراضية (100) للمركزين 1–2، وبرنامج رعاية مستهدف له بتعزيز (1% لكل 10$، سقف 10%) وتمويل معطى. @return array{0: CompetitiveEvent, 1: \App\Models\SponsorshipProgram, 2: \App\Models\Currency} */
function e30_event(string $funding = '20', int $amount = 100): array
{
    $event = e18Event();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => $amount]);
    $program = e30_program(['target_type' => 'competitive_event', 'target_id' => $event->id]);
    e30_boost($program, '10', 100, 1000);

    if ((float) $funding > 0) {
        e30_confirmed(e30_supporter(), $program, $funding);
    }

    return [$event->refresh(), $program->fresh(), $currency];
}

function e30_lock(CompetitiveEvent $event): SponsorshipEventRewardPlan
{
    return app(EventRewardPlanService::class)->lock($event->fresh(), e30_admin());
}

test('AM7-69: the sponsor reward plan is editable until it is locked, then it is frozen', function () {
    [$event, $program] = e30_event('20');
    $service = app(SponsorshipProgramService::class);

    $service->updateRewardPlan(e30_admin(), $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 200, 'max_bps' => 1000]);
    expect($program->fresh()->boost_per_step_bps)->toBe(200);

    $plan = e30_lock($event);
    expect($plan->boost_bps)->toBe(400)->and($plan->funding_snapshot_minor)->toBe(2000)->and($plan->sponsorship_program_id)->toBe($program->id);

    expect(fn () => $service->updateRewardPlan(e30_admin(), $program->fresh(), ['enabled' => true, 'step' => '10', 'per_step_bps' => 900, 'max_bps' => 1000]))->toThrow(SponsorshipException::class, 'مقفلة');
    expect($program->fresh()->boost_per_step_bps)->toBe(200);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'sponsorship_event_reward_plan_locked']);
});

test('AM7-70: once the competition has started the plan can no longer be locked (and the scheduler command skips it)', function () {
    [$event] = e30_event('20');
    e17Forward(2 * 3600_000); // بدأت

    expect(fn () => e30_lock($event))->toThrow(SponsorshipException::class);
    expect(app(EventRewardPlanService::class)->lockUpcoming())->toBe(0)->and(SponsorshipEventRewardPlan::query()->count())->toBe(0);
});

test('AM7-70b: the scheduled command locks plans of events about to start (and is idempotent)', function () {
    [$event] = e30_event('20');
    e17Forward(30 * 60_000); // نصف ساعة قبل البدء (ضمن نافذة الساعة)

    $this->artisan('sponsorship:lock-event-plans')->assertSuccessful();
    $this->artisan('sponsorship:lock-event-plans')->assertSuccessful();

    expect(SponsorshipEventRewardPlan::query()->where('competitive_event_id', $event->id)->count())->toBe(1);
});

test('AM7-71: a late contribution or refund never changes the rewards of the current event', function () {
    [$event, $program, $currency] = e30_event('20'); // 2%
    e30_lock($event);
    e30_confirmed(e30_supporter(), $program, '500'); // بعد القفل وقبل البدء

    $players = [e16User(), e16User()];
    e18Run($event, [[$players[0], E17_ANSWER, 10_000], [$players[1], E17_ANSWER, 20_000]]);

    expect(e18Pending($players[0], $currency))->toBe(102)->and(e18Pending($players[1], $currency))->toBe(102);
    $grant = e18Grant($event, $players[0]);
    expect($grant->amount)->toBe(100)->and($grant->sponsor_bonus_bps)->toBe(200)->and($grant->sponsor_bonus_amount)->toBe(2)->and($grant->sponsor_program_id)->toBe($program->id);
});

test('AM7-71b: an event with funding but no locked plan gets no boost (fail closed)', function () {
    [$event, , $currency] = e30_event('500');
    $player = e16User();

    e18Run($event, [[$player, E17_ANSWER, 10_000]]);

    expect(e18Pending($player, $currency))->toBe(100)->and(e18Grant($event, $player)->sponsor_bonus_amount)->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('AM7-72/73: sponsorship never changes the winner, ranks or scores', function () {
    $play = function (CompetitiveEvent $event) {
        $users = [e16User(), e16User(), e16User()];
        e18Run($event, [[$users[0], E17_ANSWER, 30_000], [$users[1], E17_ANSWER, 10_000], [$users[2], E17_ANSWER, 20_000]]);

        return \App\Models\CompetitiveEventResult::query()->where('competitive_event_id', $event->id)->orderBy('final_rank')->get(['user_id', 'final_rank', 'score', 'duration_ms'])
            ->map(fn ($p) => [array_search($p->user_id, array_column($users, 'id'), true), (int) $p->final_rank, (int) $p->score, (int) $p->duration_ms])->all();
    };

    [$sponsored] = e30_event('900');
    e30_lock($sponsored);
    $withSponsor = $play($sponsored);

    Carbon::setTestNow();
    e17Freeze();
    $plain = e18Event();
    e18Rule($plain, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'xp', 'amount' => 10]);
    $without = $play($plain);

    expect($withSponsor)->toBe($without)->and($withSponsor[0][0])->toBe(1);
});

test('AM7-74: eligibility and registration do not depend on sponsorship (an ordinary player joins; no sponsor code in the eligibility path)', function () {
    [$event] = e30_event('900');
    e30_lock($event);
    $ordinary = e16User();
    e17Forward(2 * 3600_000);

    $outcome = e17PlayEvent($ordinary, $event, E17_ANSWER, 5_000);

    expect($outcome->correct ?? true)->toBeTrue()->and(CompetitiveEventParticipant::query()->where('user_id', $ordinary->id)->count())->toBe(1);
    foreach (glob(app_path('Services/Competitive/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('Sponsorship');
    }
});

test('AM7-75: reward finalization stays idempotent - re-running, replaying and retrying never duplicates the bonus', function () {
    [$event, , $currency] = e30_event('30'); // 3%
    e30_lock($event);
    $a = e16User();
    $b = e16User();
    e18Run($event, [[$a, E17_ANSWER, 10_000], [$b, E17_ANSWER, 20_000]]);
    $rows = CurrencyTransaction::count();
    $bonusRows = SponsorshipRewardBonus::query()->count();

    $dist = app(CompetitiveRewardDistributionService::class);
    $dist->distributeChunk($event);
    $dist->distributeChunk($event);
    \App\Jobs\DistributeCompetitiveRewardsChunk::dispatchSync($event->id);
    $dist->retryFailed($event, e30_admin());

    expect(CurrencyTransaction::count())->toBe($rows)->and(SponsorshipRewardBonus::query()->count())->toBe($bonusRows)->toBe(2)
        ->and(e18Pending($a, $currency))->toBe(103)->and(e18Pending($b, $currency))->toBe(103)->and(CompetitiveRewardGrant::query()->count())->toBe(2);
    expect(CurrencyTransaction::query()->where('reason', 'like', 'sponsor_bonus:%')->count())->toBe(2);
});
`````

### `tests/Feature/SponsorshipE30/E30ContributionTest.php`

`sha256: 3c588968f284b45a9c783611c5c53caf23991e9a1957e60d6641180e142763ad`

`````php
<?php

use App\Models\SponsorshipContribution;
use App\Models\SupporterTier;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterTierService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

function e30_net(\App\Models\SupporterProfile $s): int
{
    return app(SupportTotals::class)->net($s->id);
}

test('AM2-11: a pending contribution does not count toward totals or funding', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    e30_pending($supporter, $program, '100');

    expect(e30_net($supporter))->toBe(0)->and(app(SupportTotals::class)->programFunding($program->id))->toBe(0);
});

test('AM2-12: a confirmed contribution counts (integer minor units)', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    e30_confirmed($supporter, $program, '12.50');

    expect(e30_net($supporter))->toBe(1250)->and(app(SupportTotals::class)->programFunding($program->id))->toBe(1250);
});

test('AM2-13: a cancelled contribution does not count and cannot be confirmed afterwards', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '40');
    $cancelled = app(ContributionService::class)->cancel(e30_admin(), $contribution, 'تعذّر التحقق من الدفع');

    expect($cancelled->status)->toBe('cancelled')->and(e30_net($supporter))->toBe(0);
    expect(fn () => app(ContributionService::class)->confirm(e30_admin(), $cancelled->fresh()))->toThrow(SponsorshipException::class);
});

test('AM2-14: a refunded contribution is removed from net totals but kept as history', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $a = e30_confirmed($supporter, $program, '30');
    e30_confirmed($supporter, $program, '20');
    expect(e30_net($supporter))->toBe(5000);

    e30_refund($a);

    expect(e30_net($supporter))->toBe(2000)->and(SponsorshipContribution::query()->count())->toBe(2)->and($a->fresh()->status)->toBe('refunded');
});

test('AM2-15: the amount must be a positive integer number of minor units', function (string $amount) {
    expect(fn () => e30_pending(e30_supporter(), e30_program(), $amount))->toThrow(SponsorshipException::class);
})->with(['0', '-5', '1.234', 'abc', '', '99999999999999999999']);

test('AM2-16: unsupported currencies are rejected', function () {
    expect(fn () => e30_pending(e30_supporter(), e30_program(), '10', [], 'XYZ'))->toThrow(SponsorshipException::class, 'عملة');
});

test('AM2-17: a foreign-currency contribution needs an accounting-currency equivalent on confirmation', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = e30_pending($supporter, $program, '100', [], 'EUR');
    $service = app(ContributionService::class);

    expect(fn () => $service->confirm(e30_admin(), $pending))->toThrow(SponsorshipException::class, 'المعادل');

    $confirmed = $service->confirm(e30_admin(), $pending->fresh(), '108.00');
    expect($confirmed->accounting_amount_minor)->toBe(10800)->and($confirmed->accounting_currency)->toBe('USD')->and($confirmed->original_amount_minor)->toBe(10000)->and($confirmed->currency)->toBe('EUR')
        ->and(e30_net($supporter))->toBe(10800);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'sponsorship_contribution_normalized']);
});

test('AM2-18: a public user or a content manager cannot confirm, cancel or refund', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = e30_pending($supporter, $program, '25');
    $service = app(ContributionService::class);
    $status = fn () => $pending->fresh()->status;

    foreach ([e16User(), e30_manager()] as $actor) {
        expect(fn () => $service->confirm($actor, $pending))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect(fn () => $service->cancel($actor, $pending, 'سبب كافٍ'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }

    expect($status())->toBe('pending')->and(e30_net($supporter))->toBe(0);
});

test('AM2-19: confirmation is audited with actor and amounts', function () {
    $admin = e30_admin();
    $supporter = e30_supporter();
    $program = e30_program();
    $pending = app(ContributionService::class)->record($admin, $supporter, $program, '15', 'USD');
    app(ContributionService::class)->confirm($admin, $pending);

    $row = DB::table('operational_audit_logs')->where('action', 'sponsorship_contribution_confirmed')->first();
    expect($row)->not->toBeNull()->and($row->actor_user_id)->toBe($admin->id);
    $meta = json_decode($row->metadata, true);
    expect($meta['accounting_amount_minor'])->toBe(1500)->and($meta['program'])->toBe($program->slug);
    expect(DB::table('operational_audit_logs')->where('action', 'sponsorship_contribution_recorded')->count())->toBe(1);
});

test('AM2-20: refund is audited with its reason, and requires a real reason', function () {
    $contribution = e30_confirmed(e30_supporter(), e30_program(), '15');

    expect(fn () => app(ContributionService::class)->refund(e30_admin(), $contribution, 'x'))->toThrow(SponsorshipException::class);
    e30_refund($contribution, 'طلب المتبرع الاسترداد');

    $row = DB::table('operational_audit_logs')->where('action', 'sponsorship_contribution_refunded')->first();
    expect($row)->not->toBeNull()->and(json_decode($row->metadata, true)['reason'])->toBe('طلب المتبرع الاسترداد');
});

test('AM2-21: confirmed financial fields are protected; correction is by refund, and rows are never deleted', function () {
    $contribution = e30_confirmed(e30_supporter(), e30_program(), '50');

    foreach (['original_amount_minor' => 999999, 'accounting_amount_minor' => 1, 'currency' => 'EUR', 'supporter_profile_id' => e30_supporter()->id] as $field => $value) {
        expect(fn () => $contribution->fresh()->forceFill([$field => $value])->save())->toThrow(SponsorshipException::class, 'محمية');
    }

    expect(fn () => $contribution->fresh()->delete())->toThrow(SponsorshipException::class);
    expect(fn () => $contribution->fresh()->forceFill(['status' => 'pending'])->save())->toThrow(SponsorshipException::class);
    expect($contribution->fresh()->accounting_amount_minor)->toBe(5000);
});

test('AM2-22: an anonymous contribution never leaks the supporter identity publicly, yet still counts', function () {
    $supporter = e30_supporter(['display_name' => 'اسم سري للغاية']);
    $program = e30_program();
    e30_confirmed($supporter, $program, '70', ['is_anonymous' => true]);

    $html = $this->get(route('supporters.index'))->assertOk()->getContent();
    expect($html)->not->toContain('اسم سري للغاية')->not->toContain($supporter->slug)->toContain('داعم مجهول');
    // الدعم المجهول يُحتسب في المجموع/المستوى لكن لا يُنسب علنًا.
    expect(e30_net($supporter))->toBe(7000)->and(app(SupporterTierService::class)->forSupporter($supporter)?->slug)->toBe('silver');
    expect(SupporterTier::query()->count())->toBe(5);
});
`````

### `tests/Feature/SponsorshipE30/E30DemoSeederTest.php`

`sha256: 6c001cfa3257376cd885fa42bb9b09f415f7b743f89409db57c26b686c3967d0`

`````php
<?php

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use Database\Seeders\DemoCreatorSeriesSeeder;
use Database\Seeders\DemoE30Seeder;
use Database\Seeders\DemoSponsorshipSeeder;
use Illuminate\Support\Facades\DB;

/*
| E30 - بيانات Demo: التركيبة المطلوبة، إعادة التشغيل لا تضاعف شيئًا، رفض الإنتاج، واستقلال الوحدتين.
*/

test('AM-D1: the sponsorship demo seeds the required mix and re-running it duplicates nothing', function () {
    $this->seed(DemoSponsorshipSeeder::class);

    $snapshot = fn () => [
        SupporterProfile::query()->count(), SponsorshipProgram::query()->count(), SponsorshipContribution::query()->count(),
        SponsorshipProgramRole::query()->count(), DB::table('operational_audit_logs')->count(),
    ];
    $first = $snapshot();

    expect(SupporterProfile::query()->where('type', 'organization')->count())->toBe(3)         // الراعي الرئيسي + مؤسستان داعمتان
        ->and(SupporterProfile::query()->where('type', 'individual')->count())->toBe(4)        // 3 أفراد + داعم يفضّل عدم الظهور
        ->and(SupporterProfile::query()->where('anonymous_by_default', true)->count())->toBe(1)
        ->and(SponsorshipProgram::query()->where('target_type', 'platform')->count())->toBe(1)
        ->and(SponsorshipProgram::query()->where('target_type', 'season')->count())->toBe(1)
        ->and(SponsorshipProgram::query()->where('status', 'active')->count())->toBe(2)
        ->and(SponsorshipContribution::query()->where('status', 'confirmed')->count())->toBeGreaterThan(0)
        ->and(SponsorshipContribution::query()->where('status', 'pending')->count())->toBe(2)
        ->and(SponsorshipContribution::query()->where('status', 'refunded')->count())->toBe(1)
        ->and(SponsorshipProgramRole::query()->where('role', 'main_sponsor')->count())->toBe(2)
        ->and(Season::query()->count())->toBeGreaterThanOrEqual(1);

    $this->seed(DemoSponsorshipSeeder::class);

    expect($snapshot())->toBe($first);
});

test('AM-D2: the demo sponsorship pages render, the anonymous supporter stays anonymous and no private note or reference leaks', function () {
    $this->seed(DemoSponsorshipSeeder::class);

    $html = $this->get(route('supporters.index'))->assertOk()->getContent();

    expect($html)->toContain('شركة النخبة للتقنية')->and(str_contains($html, 'DEMO-E30-'))->toBeFalse()->and(str_contains($html, 'مساهمة تجريبية للعرض فقط'))->toBeFalse();
});

test('AM-D3: the creator series demo seeds 2 published, 1 pending and 1 draft series, idempotently', function () {
    $this->seed(DemoCreatorSeriesSeeder::class);

    $by = fn (string $status) => CreatorSeriesRevision::query()->where('status', $status)->count();
    $first = [CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count()];

    expect(CreatorSeries::query()->whereNotNull('published_revision_id')->count())->toBe(2)
        ->and($by('approved'))->toBe(2)->and($by('pending_review'))->toBe(1)->and($by('draft'))->toBe(1);

    $this->seed(DemoCreatorSeriesSeeder::class);

    expect([CreatorSeries::query()->count(), CreatorSeriesRevision::query()->count()])->toBe($first);

    $series = CreatorSeries::query()->whereNotNull('published_revision_id')->first();
    $this->get(route('series.show', $series->public_id))->assertOk();
    expect(DB::table('currency_transactions')->count())->toBe(0);   // لا أثر اقتصادي
});

test('AM-D4: the combined seeder runs both modules; both seeders refuse production', function () {
    $this->seed(DemoE30Seeder::class);

    expect(SupporterProfile::query()->count())->toBeGreaterThan(0)->and(CreatorSeries::query()->count())->toBeGreaterThan(0);

    app()['env'] = 'production';

    foreach ([DemoSponsorshipSeeder::class, DemoCreatorSeriesSeeder::class, DemoE30Seeder::class] as $seeder) {
        expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class);
    }
});
`````

### `tests/Feature/SponsorshipE30/E30LeaderboardTest.php`

`sha256: dfa848ec7e1372c880787af77a0d52cc334edea1da46b48ae85b4567bb6351e4`

`````php
<?php

use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterLeaderboardService;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

function e30_board(string $scope = 'all', $program = null): array
{
    Cache::flush();

    return app(SupporterLeaderboardService::class)->top($scope, $program);
}

function e30_names(array $board): array
{
    return array_map(fn ($e) => $e['anonymous'] ? '(مجهول)' : $e['supporter']->display_name, $board['entries']);
}

test('AM5-38: confirmed amounts rank supporters (highest first)', function () {
    $program = e30_program();
    foreach (['ج' => '10', 'أ' => '30', 'ب' => '20'] as $name => $amount) {
        e30_confirmed(e30_supporter(['display_name' => "داعم $name"]), $program, $amount);
    }

    expect(e30_names(e30_board()))->toBe(['داعم أ', 'داعم ب', 'داعم ج']);
    $this->get(route('supporters.index'))->assertOk()->assertSeeInOrder(['داعم أ', 'داعم ب', 'داعم ج']);
});

test('AM5-39: pending and cancelled contributions are excluded from the board', function () {
    $program = e30_program();
    e30_pending(e30_supporter(['display_name' => 'داعم معلّق']), $program, '999');
    app(\App\Services\Sponsorship\ContributionService::class)->cancel(e30_admin(), e30_pending(e30_supporter(['display_name' => 'داعم ملغى']), $program, '999'), 'لم يصل الدفع');
    e30_confirmed(e30_supporter(['display_name' => 'داعم مؤكد']), $program, '5');

    expect(e30_names(e30_board()))->toBe(['داعم مؤكد']);
});

test('AM5-40: refunded amounts reduce the net ranking', function () {
    $program = e30_program();
    $a = e30_supporter(['display_name' => 'داعم الاسترداد']);
    $b = e30_supporter(['display_name' => 'داعم ثابت']);
    e30_confirmed($b, $program, '50');
    e30_confirmed($a, $program, '20');
    e30_refund(e30_confirmed($a, $program, '80'));
    e30_confirmed($a, $program, '5');

    expect(e30_names(e30_board()))->toBe(['داعم ثابت', 'داعم الاسترداد']);

    e30_confirmed($a, $program, '100');
    expect(e30_names(e30_board()))->toBe(['داعم الاسترداد', 'داعم ثابت']);
});

test('AM5-41: anonymous support is a separate nameless row and never exposes the identity', function () {
    $program = e30_program();
    $s = e30_supporter(['display_name' => 'صاحب الدعم الخفي']);
    e30_confirmed($s, $program, '200', ['is_anonymous' => true]);
    e30_confirmed($s, $program, '10');

    $board = e30_board();
    expect(e30_names($board))->toBe(['(مجهول)', 'صاحب الدعم الخفي']);
    expect($board['entries'][0]['supporter'])->toBeNull()->and($board['entries'][0]['tier'])->toBeNull();
});

test('AM5-42: a private or suspended supporter is excluded from every list', function () {
    $program = e30_program();
    e30_confirmed(e30_supporter(['display_name' => 'داعم غير علني', 'is_public' => false]), $program, '900');
    e30_confirmed(e30_company(['display_name' => 'مؤسسة غير علنية', 'is_public' => false]), $program, '900');
    e30_confirmed(e30_supporter(['display_name' => 'داعم علني']), $program, '1');

    expect(e30_names(e30_board()))->toBe(['داعم علني']);
    expect(app(SupporterLeaderboardService::class)->organizations())->toBe([]);
    expect(array_map(fn ($r) => $r['supporter']?->display_name, app(SupporterLeaderboardService::class)->recent()))->toBe(['داعم علني']);
});

test('AM5-43: ties are deterministic (earlier first confirmation, then id) and stable across rebuilds', function () {
    $program = e30_program();
    $first = e30_supporter(['display_name' => 'الأقدم']);
    $second = e30_supporter(['display_name' => 'الأحدث']);
    $c2 = e30_confirmed($second, $program, '40');
    $c1 = e30_confirmed($first, $program, '40');
    DB::table('sponsorship_contributions')->where('id', $c1->id)->update(['confirmed_at' => now()->subDays(3)]);
    DB::table('sponsorship_contributions')->where('id', $c2->id)->update(['confirmed_at' => now()->subDay()]);

    foreach (range(1, 3) as $_) {
        expect(e30_names(e30_board()))->toBe(['الأقدم', 'الأحدث']);
    }
});

test('AM5-44: the all-time board works and the year board only counts this year', function () {
    $program = e30_program();
    $old = e30_supporter(['display_name' => 'داعم قديم']);
    $new = e30_supporter(['display_name' => 'داعم هذا العام']);
    $c = e30_confirmed($old, $program, '500');
    DB::table('sponsorship_contributions')->where('id', $c->id)->update(['confirmed_at' => now()->subYears(2)]);
    e30_confirmed($new, $program, '10');

    expect(e30_names(e30_board('all')))->toBe(['داعم قديم', 'داعم هذا العام'])->and(e30_names(e30_board('year')))->toBe(['داعم هذا العام']);
});

test('AM5-45: the program board ranks only that program and shows amounts only when enabled', function () {
    $one = e30_program(['title' => 'برنامج واحد']);
    $two = e30_program(['title' => 'برنامج اثنان']);
    $a = e30_supporter(['display_name' => 'داعم أ']);
    $b = e30_supporter(['display_name' => 'داعم ب']);
    e30_confirmed($a, $one, '10');
    e30_confirmed($b, $one, '30');
    e30_confirmed($a, $two, '900');

    $board = e30_board('program', $one);
    expect(e30_names($board))->toBe(['داعم ب', 'داعم أ'])->and($board['show_amounts'])->toBeFalse()->and($board['entries'][0]['total_minor'])->toBeNull();

    app(SponsorshipProgramService::class)->update(e30_admin(), $one->fresh(), ['show_public_amounts' => true]);
    $board = e30_board('program', $one->fresh());
    expect($board['show_amounts'])->toBeTrue()->and($board['entries'][0]['total_minor'])->toBe(3000);
});

test('AM5-46: the season board ranks supporters of programs targeting the current season', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $platform = e30_program();
    e30_confirmed(e30_supporter(['display_name' => 'داعم الموسم']), $program, '15');
    e30_confirmed(e30_supporter(['display_name' => 'داعم المنصة فقط']), $platform, '900');

    expect(e30_names(e30_board('season')))->toBe(['داعم الموسم']);
});

test('AM5-47: lists are bounded (no unbounded pagination) and the page size is configurable', function () {
    $program = e30_program();
    foreach (range(1, 25) as $i) {
        e30_confirmed(e30_supporter(), $program, (string) (100 - $i));
    }

    expect(count(e30_board()['entries']))->toBe(20);
    config(['sponsorship.public.top_limit' => 7]);
    expect(count(e30_board()['entries']))->toBe(7);
    $this->get(route('supporters.index', ['scope' => 'all', 'page' => 99, 'limit' => 5000]))->assertOk();
});

test('AM5-48: the board costs the same number of queries for 5 and for 20 supporters (no N+1)', function () {
    $program = e30_program();
    foreach (range(1, 5) as $i) {
        e30_confirmed(e30_supporter(), $program, (string) (10 + $i));
    }
    Cache::flush();
    $few = e27_queries(fn () => app(SupporterLeaderboardService::class)->top());

    foreach (range(1, 15) as $i) {
        e30_confirmed(e30_supporter(), $program, (string) (100 + $i));
    }
    Cache::flush();
    $many = e27_queries(fn () => app(SupporterLeaderboardService::class)->top());

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(6);
});

test('AM5-49: ranking happens in the database (GROUP BY + ORDER BY + LIMIT), never by loading every row into PHP', function () {
    $program = e30_program();
    e30_confirmed(e30_supporter(), $program, '10');
    Cache::flush();

    $sql = collect(e27_sql(fn () => app(SupporterLeaderboardService::class)->top()))->first(fn ($q) => str_contains($q, 'sponsorship_contributions'));

    expect(strtolower($sql))->toContain('group by')->toContain('order by')->toContain('limit')->toContain('sum(');
});
`````

### `tests/Feature/SponsorshipE30/E30MainSponsorTest.php`

`sha256: ad120e12362e040efc3e8524bc82bcff2c41bce4b8bc8854663f52f010f215ae`

`````php
<?php

use App\Models\Campaign;
use App\Models\SponsorshipProgramRole;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

test('AM4-31: a supporter with a confirmed contribution can become the main sponsor', function () {
    $program = e30_program();
    $company = e30_company();
    e30_confirmed($company, $program, '500');

    $role = e30_role($program, $company, 'main_sponsor');

    expect($role->role)->toBe('main_sponsor')->and($role->main_marker)->toBe($program->id);
});

test('AM4-32: a supporter without a confirmed contribution (pending, refunded or none) cannot be a sponsor', function () {
    $program = e30_program();
    $none = e30_company();
    $pending = e30_company();
    e30_pending($pending, $program, '900');
    $refunded = e30_company();
    e30_refund(e30_confirmed($refunded, $program, '900'));

    foreach ([$none, $pending, $refunded] as $supporter) {
        expect(fn () => e30_role($program, $supporter, 'main_sponsor'))->toThrow(SponsorshipException::class, 'مؤكَّدة');
        expect(fn () => e30_role($program, $supporter, 'sponsor'))->toThrow(SponsorshipException::class);
    }

    expect(SponsorshipProgramRole::query()->count())->toBe(0);
});

test('AM4-33: only one main sponsor per program (service demotes the previous one; the database also enforces it)', function () {
    $program = e30_program();
    $a = e30_company();
    $b = e30_company();
    e30_confirmed($a, $program, '100');
    e30_confirmed($b, $program, '900');

    e30_role($program, $a, 'main_sponsor');
    e30_role($program, $b, 'main_sponsor');

    expect(SponsorshipProgramRole::query()->where('role', 'main_sponsor')->count())->toBe(1)
        ->and(SponsorshipProgramRole::query()->where('supporter_profile_id', $a->id)->value('role'))->toBe('sponsor');

    expect(fn () => DB::table('sponsorship_program_roles')->insert([
        'sponsorship_program_id' => $program->id, 'supporter_profile_id' => $a->id + 1000, 'role' => 'main_sponsor', 'main_marker' => $program->id,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('AM4-34: role assignment and removal are audited', function () {
    $program = e30_program();
    $a = e30_company();
    $b = e30_company();
    e30_confirmed($a, $program, '100');
    e30_confirmed($b, $program, '100');

    e30_role($program, $a, 'main_sponsor');
    e30_role($program, $b, 'sponsor');
    app(SponsorshipProgramService::class)->removeRole(e30_admin(), $program, $b);

    $actions = DB::table('operational_audit_logs')->pluck('action')->all();
    expect($actions)->toContain('main_sponsor_assigned')->toContain('sponsorship_role_assigned')->toContain('sponsorship_role_removed');
});

test('AM4-35: the main sponsor appears on the target page (season) with the Main Sponsor label', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $company = e30_company(['display_name' => 'شركة الراعي الذهبية']);
    e30_confirmed($company, $program, '800');
    e30_role($program, $company, 'main_sponsor');

    $this->get(route('seasons.show', $season))->assertOk()->assertSee('data-main-sponsor', false)->assertSee('شركة الراعي الذهبية')->assertSee('الراعي الرئيسي')->assertSee('برعاية');
});

test('AM4-36: supporting sponsors appear alongside the main sponsor, on campaign pages too', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $program = e30_program(['target_type' => 'campaign', 'target_id' => $campaign->id]);
    $main = e30_company(['display_name' => 'الراعي الأول']);
    $second = e30_company(['display_name' => 'الراعي الثاني']);
    e30_confirmed($main, $program, '800');
    e30_confirmed($second, $program, '80');
    e30_role($program, $main, 'main_sponsor');
    e30_role($program, $second, 'sponsor');

    $this->get(route('campaigns.show', $campaign))->assertOk()->assertSee('الراعي الأول')->assertSee('الراعي الثاني')->assertSee('data-sponsor', false);
});

test('AM4-37: hidden, suspended, anonymous-only or refunded sponsors are excluded; a refund also drops the role', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $private = e30_company(['display_name' => 'راعٍ خاص']);
    $suspended = e30_company(['display_name' => 'راعٍ موقوف']);
    $anonymous = e30_company(['display_name' => 'راعٍ مجهول الهوية']);
    $refunded = e30_company(['display_name' => 'راعٍ مسترد']);
    $refundContribution = e30_confirmed($refunded, $program, '200');

    foreach ([$private, $suspended, $refunded] as $s) {
        e30_confirmed($s, $program, '300');
    }
    e30_confirmed($anonymous, $program, '300', ['is_anonymous' => true]);
    foreach ([$private, $suspended, $anonymous, $refunded] as $s) {
        e30_role($program, $s, 'sponsor');
    }

    app(SupporterService::class)->update(e30_admin(), $private, ['is_public' => false]);
    app(SupporterService::class)->setStatus(e30_admin(), $suspended, 'suspended');
    foreach (\App\Models\SponsorshipContribution::query()->where('supporter_profile_id', $refunded->id)->get() as $c) {
        e30_refund($c);
    }

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    foreach (['راعٍ خاص', 'راعٍ موقوف', 'راعٍ مجهول الهوية', 'راعٍ مسترد'] as $name) {
        expect($html)->not->toContain($name);
    }
    expect(SponsorshipProgramRole::query()->where('supporter_profile_id', $refunded->id)->exists())->toBeFalse();
    expect($refundContribution->fresh()->status)->toBe('refunded');
});
`````

### `tests/Feature/SponsorshipE30/E30PublicUiTest.php`

`sha256: 0c7825c0db6a0de270be51647cfb14785cd1558bf3b8665e59c3386e2ddb5bdb`

`````php
<?php

use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

test('AM8-76: the supporters page renders for guests, empty and populated', function () {
    $this->get(route('supporters.index'))->assertOk()->assertSee('الداعمون والرعاة')->assertSee('لا داعمين في هذا النطاق بعد');

    $program = e30_program(['title' => 'برنامج ظاهر']);
    e30_confirmed(e30_supporter(['display_name' => 'داعم ظاهر']), $program, '40');

    $this->get(route('supporters.index'))->assertOk()->assertSee('داعم ظاهر')->assertSee('برنامج ظاهر')->assertSee('مستويات الدعم');
    $this->get(route('supporters.index', ['scope' => 'program', 'program' => $program->slug]))->assertOk()->assertSee('داعم ظاهر');
    $this->get(route('supporters.index', ['scope' => 'bogus', 'program' => 'nope']))->assertOk();
    $this->get(route('supporters.index', ['scope' => 'program', 'program' => 'does-not-exist']))->assertOk();
});

test('AM8-77: a supporter page is safe: escaped output, no private data, 404 for unknown slugs', function () {
    $supporter = e30_supporter(['display_name' => 'داعم آمن', 'description' => 'نبذة عادية بلا وسوم']);
    $program = e30_program();
    e30_confirmed($supporter, $program, '15', ['notes' => 'ملاحظة إدارية سرّية', 'reference' => 'REF-SECRET-1']);

    $html = $this->get(route('supporters.show', $supporter->slug))->assertOk()->getContent();

    expect($html)->toContain('داعم آمن')->not->toContain('ملاحظة إدارية سرّية')->not->toContain('REF-SECRET-1')->not->toContain($supporter->public_id);
    $this->get('/supporters/no-such-supporter')->assertNotFound();
    expect(fn () => e30_supporter(['display_name' => '<script>alert(1)</script>']))->toThrow(\App\Services\Sponsorship\SponsorshipException::class);
});

test('AM8-78: exact amounts are hidden by default everywhere', function () {
    $supporter = e30_supporter(['display_name' => 'داعم المبلغ']);
    $program = e30_program(['title' => 'برنامج بلا مبالغ', 'goal_amount' => '1000', 'show_goal_progress' => true]);
    e30_confirmed($supporter, $program, '123.45');

    foreach ([route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug]), route('supporters.show', $supporter->slug)] as $url) {
        $this->get($url)->assertOk()->assertDontSee('123.45')->assertDontSee('$123')->assertDontSee('12345');
    }
    $this->get(route('supporters.index'))->assertSee('تقدّم الهدف');
});

test('AM8-79: amounts are shown only when the program enables it', function () {
    $supporter = e30_supporter(['display_name' => 'داعم المبلغ']);
    $program = e30_program(['title' => 'برنامج بمبالغ', 'goal_amount' => '1000', 'show_goal_progress' => true, 'show_public_amounts' => true]);
    e30_confirmed($supporter, $program, '123.45');

    $this->get(route('supporters.index', ['scope' => 'program', 'program' => $program->slug]))->assertOk()->assertSee('$123.45');
    $this->get(route('supporters.show', $supporter->slug))->assertOk()->assertSee('$123.45');
    // صفوف لوحة «كل الوقت» العامة لا تكشف مبلغ أي داعم حتى لو فعّل برنامج ذلك (المبلغ يخص لوحة البرنامج وبطاقته فقط).
    $html = $this->get(route('supporters.index', ['scope' => 'all']))->assertOk()->getContent();
    preg_match('/<ol[^>]*aria-label="ترتيب الداعمين".*?<\/ol>/su', $html, $board);
    expect($board[0] ?? '')->not->toBeEmpty()->not->toContain('$123');
});

test('AM8-80: the main sponsor label is shown, and a platform sponsor appears on the supporters page', function () {
    $program = e30_program();
    $company = e30_company(['display_name' => 'راعي المنصة']);
    e30_confirmed($company, $program, '500');
    e30_role($program, $company, 'main_sponsor');

    $this->get(route('supporters.index'))->assertOk()->assertSee('الراعي الرئيسي')->assertSee('راعي المنصة')->assertSee('برعاية');
});

test('AM8-81: external links carry rel="noopener noreferrer sponsored" and open safely; logos are plain images', function () {
    $program = e30_program();
    $company = e30_company(['display_name' => 'شركة الرابط', 'website_url' => 'https://example.com/a']);
    e30_confirmed($company, $program, '500');
    e30_role($program, $company, 'main_sponsor');

    $html = $this->get(route('supporters.show', $company->slug))->assertOk()->getContent();
    expect($html)->toContain('rel="noopener noreferrer sponsored"')->toContain('target="_blank"')->toContain('href="https://example.com/a"');
    $strip = $this->get(route('supporters.index'))->getContent();
    expect($strip)->toContain('rel="noopener noreferrer sponsored"')->not->toContain('javascript:');
    expect(preg_match_all('/<a [^>]*target="_blank"[^>]*>/', $html.$strip, $m))->toBeGreaterThan(0);
    foreach ($m[0] as $tag) {
        expect($tag)->toContain('noopener')->toContain('noreferrer');
    }
});

test('AM8-82: the supporter pages are RTL Arabic with accessible landmarks', function () {
    e30_confirmed(e30_supporter(), e30_program(), '40');

    $html = $this->get(route('supporters.index'))->assertOk()->getContent();

    expect($html)->toContain('dir="rtl"')->toContain('lang="ar"')->toContain('aria-label="ترتيب الداعمين"')->toContain('<h1');
    expect($html)->not->toMatch('/\b(text-left|float-left|ml-\d|mr-\d)\b/');
});

test('AM14-136: hidden/private sponsors leak no private data (name, slug, logo, public id) in any public page', function () {
    $program = e30_program();
    $hidden = e30_company(['display_name' => 'جهة مخفية تمامًا', 'is_public' => false, 'website_url' => 'https://hidden.example.com']);
    e30_confirmed($hidden, $program, '900');
    e30_role($program, $hidden, 'main_sponsor');

    foreach ([route('supporters.index'), route('supporters.index', ['scope' => 'program', 'program' => $program->slug])] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach (['جهة مخفية تمامًا', $hidden->slug, $hidden->public_id, 'hidden.example.com'] as $needle) {
            expect($html)->not->toContain($needle);
        }
    }
    $this->get(route('supporters.show', $hidden->slug))->assertNotFound();

    app(SupporterService::class)->update(e30_admin(), $hidden, ['is_public' => true]);
    $this->get(route('supporters.index'))->assertSee('جهة مخفية تمامًا');
});

test('AM14-130/133: no GET mutates anything and there is no public write route for sponsorship', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '10');
    $before = \Illuminate\Support\Facades\DB::table('sponsorship_contributions')->get()->toArray();

    foreach ([route('supporters.index'), route('supporters.show', $supporter->slug), route('supporters.index', ['confirm' => 1, 'status' => 'confirmed', 'id' => $contribution->id])] as $url) {
        $this->get($url)->assertOk();
    }

    expect(\Illuminate\Support\Facades\DB::table('sponsorship_contributions')->get()->toArray())->toEqual($before);
    $writes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => (str_contains($r->uri(), 'supporters') || str_contains($r->uri(), 'sponsorship')) && ! in_array('GET', $r->methods(), true) && ! str_starts_with($r->uri(), 'admin'));
    expect($writes)->toHaveCount(0);
    $this->post('/supporters', ['display_name' => 'x'])->assertStatus(405);
});

test('AM14-127/128: mass assignment is blocked on every sponsorship model and a contribution cannot be reached by id', function () {
    $supporter = e30_supporter();
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '10');

    foreach ([\App\Models\SupporterProfile::class, \App\Models\SponsorshipProgram::class, \App\Models\SponsorshipContribution::class, \App\Models\SponsorshipProgramRole::class, \App\Models\SponsorshipRewardBonus::class, \App\Models\SponsorshipEventRewardPlan::class] as $model) {
        expect(fn () => (new $model)->fill(['status' => 'confirmed', 'accounting_amount_minor' => 99, 'role' => 'main_sponsor', 'bonus_amount' => 5]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
    }
    expect(fn () => $contribution->fill(['status' => 'confirmed']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);

    $player = e16User();
    $this->actingAs($player)->get('/admin/sponsorship-contributions')->assertForbidden();
    $this->get('/supporters/'.$contribution->public_id)->assertNotFound();
    $this->get('/supporters/'.$contribution->id)->assertNotFound();
});

test('AM14-131: the logo upload is MIME/content checked (not by extension) and the stored name is server generated', function () {
    \Illuminate\Support\Facades\Storage::fake(config('sponsorship.logo.disk'));
    $service = app(\App\Services\Sponsorship\SupporterLogoService::class);

    $php = \Illuminate\Http\UploadedFile::fake()->createWithContent('shell.png', "<?php system('id');");
    $html = \Illuminate\Http\UploadedFile::fake()->createWithContent('page.jpg', '<html><script>alert(1)</script></html>');
    foreach ([$php, $html] as $file) {
        expect(fn () => $service->store($file))->toThrow(\App\Services\Sponsorship\SponsorshipException::class);
    }

    $path = $service->store(\Illuminate\Http\UploadedFile::fake()->image('../../evil name.png', 50, 50));
    expect($path)->toStartWith(config('sponsorship.logo.directory').'/')->not->toContain('evil')->not->toContain('..')->toMatch('/\.(jpg|jpeg|png|webp)$/');
});

test('AM15-137/138/139: the public pages and the sponsor strip are query-bounded regardless of the data volume', function () {
    $season = e30_season();
    $seasonProgram = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $platform = e30_program();

    $seed = function (int $n) use ($seasonProgram, $platform) {
        for ($i = 0; $i < $n; $i++) {
            $s = e30_company();
            e30_confirmed($s, $i % 2 ? $seasonProgram : $platform, (string) (10 + $i));
            e30_role($i % 2 ? $seasonProgram : $platform, $s, 'sponsor');
        }
    };
    $measure = fn () => [
        'index' => (function () { Cache::flush(); return e27_queries(fn () => $this->get(route('supporters.index'))->assertOk()); })->call($this),
        'season' => (function () use ($season) { Cache::flush(); return e27_queries(fn () => $this->get(route('seasons.show', $season))->assertOk()); })->call($this),
        'show' => (function () { Cache::flush(); $p = \App\Models\SupporterProfile::query()->first(); return e27_queries(fn () => $this->get(route('supporters.show', $p->slug))->assertOk()); })->call($this),
    ];

    $seed(3);
    $few = $measure();
    $seed(12);
    $many = $measure();

    expect($many['index'])->toBe($few['index'])->and($many['season'])->toBe($few['season'])->and($many['show'])->toBe($few['show'])
        ->and($many['index'])->toBeLessThanOrEqual(30)->and($many['season'])->toBeLessThanOrEqual(45)->and($many['show'])->toBeLessThanOrEqual(25);
});

test('AM15: the display and the board are cached (warm read is cheaper) and the cache is never an authority', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $company = e30_company(['display_name' => 'شركة الكاش']);
    e30_confirmed($company, $program, '100');
    e30_role($program, $company, 'main_sponsor');

    Cache::flush();
    $cold = e27_queries(fn () => $this->get(route('supporters.index'))->assertOk());
    $warm = e27_queries(fn () => $this->get(route('supporters.index'))->assertOk());
    expect($warm)->toBeLessThan($cold);

    // التمويل الذي يحسب التعزيز يُقرأ حيًّا: استرداد بعد تسخين الكاش يُبطل الفوز فورًا.
    $boosted = e30_program();
    e30_boost($boosted, '10', 100, 500);
    $c = e30_confirmed(e30_supporter(), $boosted, '20');
    Cache::flush();
    $ctx = \App\GameEngine\Support\AttemptContext::none();
    $svc = app(\App\Services\Sponsorship\SponsorshipRewardService::class);
    expect($svc->resolveForSolve($ctx)['bps'])->toBe(200);
    e30_refund($c);
    expect($svc->resolveForSolve($ctx))->toBeNull();
});
`````

### `tests/Feature/SponsorshipE30/E30RewardBoostTest.php`

`sha256: f1e971e3e0bf0a2506b1bce1424395a3c1df0592783a096e9d42ce588fed47bc`

`````php
<?php

use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\SponsorshipRewardBonus;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SponsorshipRewardService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e30_seed();
    config(['gems.daily_earn_cap' => 100000]); // السقف اليومي الافتراضي (200) يقصّ المكافآت الكبيرة؛ يُختبر صراحة في AM6-66.
});

/** يحلّ أحجية رسمية (جواهر أساسية معطاة) بحساب لاعب جديد ويعيد [النتيجة, اللاعب]. */
function e30_solve(int $reward = 200, ?User $user = null, ?Puzzle $puzzle = null): array
{
    $user ??= User::factory()->create();
    $puzzle ??= Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => $reward]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

function e30_pending_gems(User $user): int
{
    return (int) $user->fresh()->wallet->pending_balance;
}

/** برنامج منصة معزَّز: خطوة 10$، 1% لكل خطوة، سقف 10% (إن لم تُعطَ غير ذلك) وممول بمبلغ معيّن. */
function e30_boosted(string $funding = '20', string $step = '10', int $perStep = 100, int $max = 1000)
{
    $program = e30_program();
    e30_boost($program, $step, $perStep, $max);

    if ((float) $funding > 0) {
        e30_confirmed(e30_supporter(), $program, $funding);
    }

    return $program->fresh();
}

test('AM6-50: with no program or no funding the boost is zero and the reward is exactly the base', function () {
    [$result, $user] = e30_solve(200);
    expect($result['gems_awarded'])->toBe(200)->and($result['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(200);

    e30_boosted('0');
    [$result, $user] = e30_solve(200);
    expect($result['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(200)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('AM6-51: funding steps compute the boost (floor(funding/step) x per-step bps) and the bonus is floor(base x bps / 10000)', function () {
    e30_boosted('25'); // خطوتان ⇒ 200 نقطة أساس = 2%

    [$result, $user] = e30_solve(200);

    expect($result['gems_awarded'])->toBe(200)->and($result['sponsor_bonus'])->toBe(4)->and(e30_pending_gems($user))->toBe(204);
});

test('AM6-52: the program max and the global hard max cap the boost', function () {
    e30_boosted('1000', '10', 100, 300); // 100 خطوة لكن السقف 3%

    [$result] = e30_solve(200);
    expect($result['sponsor_bonus'])->toBe(6);

    expect(SponsorshipRewardService::computeBps(1_000_000_00, 1000, 100, 9000))->toBe(5000); // السقف الصلب
    config(['sponsorship.reward.hard_max_bps' => 250]);
    expect(SponsorshipRewardService::computeBps(1_000_000_00, 1000, 100, 9000))->toBe(250);
});

test('AM6-53: the boost uses integer basis points only and rounds the bonus down', function () {
    expect(SponsorshipRewardService::computeBps(2500, 1000, 100, 1000))->toBeInt()->toBe(200)
        ->and(SponsorshipRewardService::bonusFor(15, 100))->toBe(0)
        ->and(SponsorshipRewardService::bonusFor(199, 100))->toBe(1)
        ->and(SponsorshipRewardService::bonusFor(0, 500))->toBe(0)
        ->and(SponsorshipRewardService::computeBps(999, 1000, 100, 1000))->toBe(0);
    expect(\Illuminate\Support\Facades\Schema::getColumnType('sponsorship_programs', 'boost_per_step_bps'))->toContain('int');

    e30_boosted('20'); // 2%
    [$result] = e30_solve(15);
    expect($result['sponsor_bonus'])->toBe(0);
});

test('AM6-54: a pending contribution does not fund the boost', function () {
    $program = e30_boosted('0');
    e30_pending(e30_supporter(), $program, '500');

    [$result] = e30_solve(200);

    expect($result['sponsor_bonus'])->toBe(0);
});

test('AM6-55: a refund reduces the funding for subsequent solves only', function () {
    $program = e30_boosted('0');
    $a = e30_confirmed(e30_supporter(), $program, '30'); // 3%
    [$before] = e30_solve(200);

    e30_refund($a);
    [$after] = e30_solve(200);

    expect($before['sponsor_bonus'])->toBe(6)->and($after['sponsor_bonus'])->toBe(0);
});

test('AM6-56: the browser cannot inject a boost; extra request fields are ignored', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 100]);

    $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'صح', 'sponsor_bonus' => 9999, 'boost_bps' => 5000, 'bonus' => 500, 'program_id' => 1]);

    expect(e30_pending_gems($user))->toBe(100)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('AM6-57: admin reward-plan input is validated, requires the reward_plan permission and is audited', function () {
    $program = e30_program();
    $service = app(SponsorshipProgramService::class);

    foreach ([
        ['enabled' => true, 'step' => '0', 'per_step_bps' => 100, 'max_bps' => 500],
        ['enabled' => true, 'step' => '10', 'per_step_bps' => 0, 'max_bps' => 500],
        ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 0],
        ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 999999],
        ['enabled' => true, 'step' => 'abc', 'per_step_bps' => 100, 'max_bps' => 500],
    ] as $plan) {
        expect(fn () => $service->updateRewardPlan(e30_admin(), $program, $plan))->toThrow(SponsorshipException::class);
    }

    expect(fn () => $service->updateRewardPlan(e30_manager(), $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 500]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($program->fresh()->reward_boost_enabled)->toBeFalse();

    $ok = $service->updateRewardPlan(e30_admin(), $program, ['enabled' => true, 'step' => '10', 'per_step_bps' => 100, 'max_bps' => 500]);
    expect($ok->reward_boost_enabled)->toBeTrue()->and($ok->funding_step_minor)->toBe(1000)->and($ok->boost_per_step_bps)->toBe(100)->and($ok->max_boost_bps)->toBe(500);
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'sponsorship_reward_plan_updated']);
});

test('AM6-58: an official eligible reward gets a separate bonus transaction on the wallet ledger', function () {
    e30_boosted('50');
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 100]);

    [$result, $user] = e30_solve(100, null, $puzzle);

    $rows = CurrencyTransaction::query()->where('user_id', $user->id)->orderBy('id')->get();
    expect($result['sponsor_bonus'])->toBe(5)->and($rows)->toHaveCount(2)->and($rows[0]->reason)->toStartWith('solved_puzzle:')->and($rows[0]->amount)->toBe(100)
        ->and($rows[1]->reason)->toStartWith('sponsor_bonus:')->and($rows[1]->amount)->toBe(5)->and($rows[1]->type)->toBe('earn_pending');
});

test('AM6-59: a community puzzle gets zero bonus and never touches the economy', function () {
    e30_boosted('500');
    $creator = e28_creator();
    $work = e28_published($creator, 'text');
    $player = e16User();

    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), 'إجابة خاطئة');
    $solved = app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), (string) ($work->fresh()->answer_raw ?? 'صح'));

    expect(CurrencyTransaction::query()->where('user_id', $player->id)->count())->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(0);

    // حتى لو مُرِّرت أحجية مجتمع للبوابة مباشرة: صفر.
    $communityPuzzle = Puzzle::factory()->create(['origin' => 'community', 'gem_reward' => 100]);
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    expect(app(SponsorshipRewardService::class)->applyToSolve($player, $communityPuzzle, \App\GameEngine\Support\AttemptContext::none(), $currency, 100, 777))->toBe(0);
    expect(fn () => app(PuzzleAttemptService::class)->attempt($player, $communityPuzzle, 'x'))->toThrow(\RuntimeException::class);
});

test('AM6-61/62: a supporter, a main sponsor and an ordinary player receive exactly the same sponsored reward', function () {
    $program = e30_boosted('0');
    $supporterUser = User::factory()->create();
    $profile = e30_supporter(['user_id' => $supporterUser->id]);
    e30_confirmed($profile, $program, '40'); // 4%
    e30_role($program, $profile, 'main_sponsor');
    $ordinary = User::factory()->create();

    [$a] = e30_solve(200, $supporterUser);
    [$b] = e30_solve(200, $ordinary);

    expect($a['sponsor_bonus'])->toBe(8)->and($b['sponsor_bonus'])->toBe(8)->and(e30_pending_gems($supporterUser))->toBe(e30_pending_gems($ordinary))->toBe(208);
});

test('AM6-63: the base reward is unchanged by sponsorship (gems_awarded and the base ledger row)', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 120]);
    [$plain] = e30_solve(120, null, $puzzle);
    e30_boosted('100'); // 10%
    [$boosted, $user] = e30_solve(120, null, $puzzle);

    expect($boosted['gems_awarded'])->toBe($plain['gems_awarded'])->toBe(120)->and($boosted['sponsor_bonus'])->toBe(12)
        ->and(CurrencyTransaction::query()->where('user_id', $user->id)->where('reason', 'like', 'solved_puzzle:%')->value('amount'))->toBe(120);
});

test('AM6-64/65: the bonus is recorded once per attempt with a unique source key; retries never grant twice', function () {
    e30_boosted('100');
    [$result, $user] = e30_solve(100);
    $row = SponsorshipRewardBonus::query()->first();
    $attemptId = (int) str_replace('solve:', '', str_replace('sponsor-bonus:', '', $row->source_key));
    $service = app(SponsorshipRewardService::class);
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $puzzle = Puzzle::query()->latest('id')->first();

    $retry = $service->applyToSolve($user, $puzzle, \App\GameEngine\Support\AttemptContext::none(), $currency, 100, $attemptId);

    expect($result['sponsor_bonus'])->toBe(10)->and($retry)->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(1)
        ->and(CurrencyTransaction::query()->where('reason', 'like', 'sponsor_bonus:%')->count())->toBe(1)->and(e30_pending_gems($user))->toBe(110);
    expect(fn () => DB::table('sponsorship_reward_bonuses')->insert(['source_key' => $row->source_key, 'sponsorship_program_id' => $row->sponsorship_program_id, 'user_id' => $user->id, 'currency_id' => $currency->id,
        'base_amount' => 1, 'boost_bps' => 1, 'calculated_bonus_amount' => 1, 'bonus_amount' => 1, 'final_amount' => 2, 'funding_snapshot_minor' => 1]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('AM6-66: the daily earn cap is preserved (the bonus is clipped to the remaining allowance, never exceeds it)', function () {
    config(['gems.daily_earn_cap' => 105]);
    e30_boosted('100'); // 10%
    [$result, $user] = e30_solve(100);

    expect($result['gems_awarded'])->toBe(100)->and($result['sponsor_bonus'])->toBe(5)->and(e30_pending_gems($user))->toBe(105);

    [$next] = e30_solve(100, $user);
    expect($next['gems_awarded'])->toBe(0)->and($next['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(105);
    $row = SponsorshipRewardBonus::query()->first();
    expect($row->calculated_bonus_amount)->toBe(10)->and($row->bonus_amount)->toBe(5);
});

test('AM6-67: funding added later never grants retroactively', function () {
    $program = e30_boosted('0');
    [$first, $user] = e30_solve(200);

    e30_confirmed(e30_supporter(), $program, '100');

    expect($first['sponsor_bonus'])->toBe(0)->and(e30_pending_gems($user))->toBe(200)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
    [$later] = e30_solve(200);
    expect($later['sponsor_bonus'])->toBe(20);
});

test('AM6-68: the historical bonus snapshot is stable after refunds and program changes', function () {
    $program = e30_boosted('0');
    $contribution = e30_confirmed(e30_supporter(), $program, '50'); // 5%
    [$result, $user] = e30_solve(200);
    $snapshot = SponsorshipRewardBonus::query()->first()->only(['boost_bps', 'funding_snapshot_minor', 'bonus_amount', 'base_amount', 'final_amount']);

    e30_refund($contribution);
    app(SponsorshipProgramService::class)->close(e30_admin(), $program->fresh());

    expect(SponsorshipRewardBonus::query()->first()->only(['boost_bps', 'funding_snapshot_minor', 'bonus_amount', 'base_amount', 'final_amount']))->toBe($snapshot)
        ->and($snapshot['boost_bps'])->toBe(500)->and($snapshot['funding_snapshot_minor'])->toBe(5000)->and($snapshot['bonus_amount'])->toBe(10)->and(e30_pending_gems($user))->toBe(210);
    [$after] = e30_solve(200);
    expect($after['sponsor_bonus'])->toBe(0);
});
`````

### `tests/Feature/SponsorshipE30/E30SponsorshipNotificationsTest.php`

`sha256: c2698f8cdb4f5b463d4625c6a33ca74214ab36bc49bbcaa5034d24e285c6f15b`

`````php
<?php

use App\Models\SponsorshipContribution;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sponsorship\ContributionService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E30 (R) - إشعارات الداعمين عبر E15: للداعم المرتبط بحساب فقط، بلا أي مبلغ/مرجع/ملاحظة إدارية، وفشلها لا يعيد أي حالة مالية للوراء.
*/

beforeEach(fn () => e30_seed());

function e30n_notes(\App\Models\User $user, ?string $type = null): \Illuminate\Support\Collection
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->when($type, fn ($q) => $q->where('type_key', $type))->orderBy('created_at')->get();
}

test('AM-R1: confirming support notifies the linked supporter once, without any amount, reference or private note', function () {
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id, 'is_public' => true]);
    $program = e30_program();

    $contribution = e30_pending($supporter, $program, '77.25', ['reference' => 'REF-PRIVATE-7781', 'notes' => 'ملاحظة إدارية خاصة جدًا']);
    expect(e30n_notes($user, 'support_confirmed'))->toHaveCount(0);   // المعلّقة لا تُشعِر

    app(ContributionService::class)->confirm(e30_admin(), $contribution);

    $notes = e30n_notes($user, 'support_confirmed');
    expect($notes)->toHaveCount(1);

    $raw = json_encode($notes->first(), JSON_UNESCAPED_UNICODE);
    foreach (['REF-PRIVATE-7781', 'ملاحظة إدارية خاصة جدًا', '77.25', '7725', 'USD'] as $secret) {
        expect(str_contains($raw, $secret))->toBeFalse("leaked: {$secret}");
    }

    // تأكيد ثانٍ مرفوض ولا يكرّر الإشعار
    try {
        app(ContributionService::class)->confirm(e30_admin(), $contribution->fresh());
    } catch (\Throwable) {
    }

    expect(e30n_notes($user, 'support_confirmed'))->toHaveCount(1);
});

test('AM-R2: crossing a tier threshold sends supporter_tier_changed (up), a refund sends it (down), same tier sends nothing', function () {
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id]);
    $program = e30_program();

    e30_confirmed($supporter, $program, '5');      // null → داعم
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(1);

    e30_confirmed($supporter, $program, '1');      // نفس المستوى
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(1);

    $big = e30_confirmed($supporter, $program, '300');   // → ذهبي
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(2);
    $up = json_decode(e30n_notes($user, 'supporter_tier_changed')->last()->data, true);
    expect($up['title'])->toBeString()->and(json_encode($up, JSON_UNESCAPED_UNICODE))->not->toContain('300');

    e30_refund($big);                              // ذهبي → داعم
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(3);
    $titles = e30n_notes($user, 'supporter_tier_changed')->map(fn ($n) => json_decode($n->data, true)['title'])->unique();
    expect($titles->count())->toBeGreaterThan(1);   // نص الارتقاء يختلف عن نص الانخفاض
});

test('AM-R3: a supporter without an account, or a frozen account, is never notified and nothing fails', function () {
    $program = e30_program();
    $orphan = e30_supporter();                    // بلا حساب
    $confirmed = e30_confirmed($orphan, $program, '50');
    expect($confirmed->status)->toBe('confirmed');

    $frozen = e16User();
    $supporter = e30_supporter(['user_id' => $frozen->id]);
    $frozen->forceFill(['is_frozen' => true])->save();
    e30_confirmed($supporter, $program, '50');

    expect(DB::table('notifications')->where('notifiable_id', $frozen->id)->count())->toBe(0);
});

test('AM-R4: notification failure does NOT roll back the confirmation, the totals, the tier or the audit trail', function () {
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id]);
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '120');

    app()->bind(NotificationDispatcher::class, fn () => new class extends NotificationDispatcher
    {
        public function __construct() {}

        public function dispatch(\App\Models\User $user, \App\Services\Notifications\NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): \App\Services\Notifications\DispatchResult
        {
            throw new \RuntimeException('simulated notification outage');
        }
    });
    app()->forgetInstance(\App\Services\Sponsorship\SponsorshipNotifier::class);
    app()->forgetInstance(ContributionService::class);

    $confirmed = app(ContributionService::class)->confirm(e30_admin(), $contribution);

    expect($confirmed->status)->toBe(SponsorshipContribution::STATUS_CONFIRMED)
        ->and(SponsorshipContribution::query()->whereKey($contribution->id)->value('status'))->toBe('confirmed')
        ->and(app(\App\Services\Sponsorship\SupportTotals::class)->net($supporter->id))->toBe(12000)
        ->and(DB::table('operational_audit_logs')->where('action', 'like', 'sponsorship_contribution%confirm%')->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);
});

test('AM-R5: disabling sponsorship notifications silences them without touching the money flow', function () {
    config(['sponsorship.notifications.enabled' => false]);
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id]);

    $c = e30_confirmed($supporter, e30_program(), '60');

    expect($c->status)->toBe('confirmed')->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);
});

test('AM-R6: activating a program notifies only linked supporters that have a contribution in it, once each', function () {
    $in = e16User();
    $out = e16User();
    $inSupporter = e30_supporter(['user_id' => $in->id]);
    e30_supporter(['user_id' => $out->id]);
    $program = e30_program([], false);
    e30_pending($inSupporter, $program, '10');

    app(\App\Services\Sponsorship\SponsorshipProgramService::class)->activate(e30_admin(), $program);

    expect(e30n_notes($in, 'sponsorship_program_started'))->toHaveCount(1)
        ->and(e30n_notes($out, 'sponsorship_program_started'))->toHaveCount(0);
});
`````

### `tests/Feature/SponsorshipE30/E30SupporterProfileTest.php`

`sha256: df10d21715951a6ed725f07ab14d3c91694beef213f359cafa32fd819628a76f`

`````php
<?php

use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

test('AM1-1: admin creates an individual supporter with a ULID public id and a stable slug', function () {
    $profile = e30_supporter(['display_name' => 'فاعل خير', 'type' => 'individual']);

    expect($profile->type)->toBe('individual')->and($profile->public_id)->toHaveLength(26)->and($profile->slug)->not->toBeEmpty()
        ->and($profile->status)->toBe('active')->and($profile->is_public)->toBeTrue();
    $this->assertDatabaseHas('operational_audit_logs', ['action' => 'supporter_created']);

    $slug = $profile->slug;
    app(SupporterService::class)->update(e30_admin(), $profile, ['display_name' => 'اسم جديد']);
    expect($profile->fresh()->slug)->toBe($slug);
});

test('AM1-2: admin creates an organization supporter', function () {
    $profile = e30_company(['display_name' => 'شركة النور']);

    expect($profile->type)->toBe('organization')->and($profile->display_name)->toBe('شركة النور');
});

test('AM1-3: a supporter may optionally link to a platform user, and the link is optional', function () {
    $user = e16User();
    $linked = e30_supporter(['user_id' => $user->id]);
    $free = e30_supporter();

    expect($linked->user_id)->toBe($user->id)->and($free->user_id)->toBeNull();
});

test('AM1-4: linking one user to a second supporter profile is rejected', function () {
    $user = e16User();
    e30_supporter(['user_id' => $user->id]);

    expect(fn () => e30_supporter(['user_id' => $user->id]))->toThrow(SponsorshipException::class, 'مرتبط');
    expect(SupporterProfile::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('AM1-5: a public active supporter is publicly visible by slug', function () {
    $profile = e30_supporter(['display_name' => 'داعم علني']);

    $this->get(route('supporters.show', $profile->slug))->assertOk()->assertSee('داعم علني');
});

test('AM1-6: a private or suspended supporter is not publicly identified anywhere', function () {
    $private = e30_supporter(['display_name' => 'داعم خاص جدًا', 'is_public' => false]);
    $suspended = e30_supporter(['display_name' => 'داعم موقوف جدًا']);
    $program = e30_program();
    e30_confirmed($private, $program, '50');
    e30_confirmed($suspended, $program, '60');
    app(SupporterService::class)->setStatus(e30_admin(), $suspended, 'suspended');

    $this->get(route('supporters.show', $private->slug))->assertNotFound();
    $this->get(route('supporters.show', $suspended->slug))->assertNotFound();
    $this->get(route('supporters.index'))->assertOk()->assertDontSee('داعم خاص جدًا')->assertDontSee('داعم موقوف جدًا');
});

test('AM1-7: unsafe website URLs are rejected', function (string $url) {
    expect(fn () => e30_supporter(['website_url' => $url]))->toThrow(SponsorshipException::class);
})->with(['javascript:alert(1)', 'data:text/html,<b>x</b>', 'ftp://example.com', 'http://127.0.0.1/admin', 'https://user:pass@example.com', 'http://localhost', "https://exa\nmple.com"]);

test('AM1-8: safe websites are accepted and normalized', function () {
    $profile = e30_supporter(['website_url' => 'https://Example.com/about?x=1']);

    expect($profile->website_url)->toStartWith('https://example.com');
});

test('AM1-9: unsafe logos (SVG, script, wrong mime, oversized) are rejected', function () {
    Storage::fake(config('sponsorship.logo.disk'));
    $admin = e30_admin();

    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $fake = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;');
    $big = UploadedFile::fake()->image('logo.png')->size(5000);

    foreach ([$svg, $fake, $big] as $file) {
        expect(fn () => app(SupporterService::class)->create($admin, ['display_name' => 'ذو شعار', 'type' => 'individual'], $file))->toThrow(SponsorshipException::class);
    }

    expect(SupporterProfile::query()->where('display_name', 'ذو شعار')->count())->toBe(0);

    $ok = app(SupporterService::class)->create($admin, ['display_name' => 'ذو شعار صالح', 'type' => 'individual'], UploadedFile::fake()->image('logo.png', 64, 64));
    expect($ok->logo_path)->not->toBeNull();
    Storage::disk(config('sponsorship.logo.disk'))->assertExists($ok->logo_path);
});

test('AM1-10: an ordinary user or a content manager without the permission cannot manage supporters', function () {
    $user = e16User();

    expect(fn () => app(SupporterService::class)->create($user, ['display_name' => 'محاولة', 'type' => 'individual']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SupporterProfile::query()->count())->toBe(0);
    expect($user->can('sponsorships.manage'))->toBeFalse()->and(e30_manager()->can('sponsorships.manage'))->toBeTrue();
});
`````

### `tests/Feature/SponsorshipE30/E30TierTest.php`

`sha256: 23c4437aa1adeef8544fe50a1a42ea7d4b61eb468d7b1edf7318efc2101e89d6`

`````php
<?php

use App\Models\SupporterTier;
use App\Services\Sponsorship\SupporterTierService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

function e30_tier(\App\Models\SupporterProfile $s): ?string
{
    return app(SupporterTierService::class)->forSupporter($s)?->slug;
}

test('AM3-23: the tier derives from the confirmed net total (default Arabic tiers)', function () {
    $program = e30_program();
    $supporter = e30_supporter();
    expect(e30_tier($supporter))->toBeNull();

    e30_confirmed($supporter, $program, '5');
    expect(e30_tier($supporter))->toBe('supporter');
    e30_confirmed($supporter, $program, '45');
    expect(e30_tier($supporter))->toBe('silver');
    e30_confirmed($supporter, $program, '200');
    expect(e30_tier($supporter))->toBe('gold');
    expect(SupporterTier::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['داعم', 'فضي', 'ذهبي', 'بلاتيني', 'ماسي']);
});

test('AM3-24: a pending contribution does not affect the tier', function () {
    $supporter = e30_supporter();
    e30_pending($supporter, e30_program(), '1000');

    expect(e30_tier($supporter))->toBeNull();
});

test('AM3-25: refunded support downgrades the tier automatically', function () {
    $program = e30_program();
    $supporter = e30_supporter();
    $big = e30_confirmed($supporter, $program, '300');
    e30_confirmed($supporter, $program, '10');
    expect(e30_tier($supporter))->toBe('gold');

    e30_refund($big);

    expect(e30_tier($supporter))->toBe('supporter');
});

test('AM3-26: the highest matching tier is chosen', function () {
    $supporter = e30_supporter();
    e30_confirmed($supporter, e30_program(), '5000');

    expect(e30_tier($supporter))->toBe('diamond');
});

test('AM3-27: an inactive tier is ignored (tiers are DB-managed and the cache is invalidated)', function () {
    $program = e30_program();
    $supporter = e30_supporter();
    e30_confirmed($supporter, $program, '300');
    expect(e30_tier($supporter))->toBe('gold');

    SupporterTier::query()->where('slug', 'gold')->first()->update(['is_active' => false]);

    expect(e30_tier($supporter))->toBe('silver');

    SupporterTier::query()->where('slug', 'gold')->first()->update(['minimum_accounting_amount_minor' => 99999999, 'is_active' => true]);
    expect(e30_tier($supporter))->toBe('silver');
});

test('AM3-28: the public player profile shows supporter recognition only for public, named support', function () {
    $user = e16User(['name' => 'لاعب داعم']);
    $program = e30_program();
    $supporter = e30_supporter(['user_id' => $user->id]);
    e30_confirmed($supporter, $program, '60');

    $this->actingAs(e16User())->get(route('players.show', $user))->assertOk()->assertSee('data-supporter-recognition', false)->assertSee('داعم · فضي');
});

test('AM3-29: private, suspended or fully anonymous supporters show no recognition (safe behaviour)', function () {
    $program = e30_program();
    $viewer = e16User();

    $private = e16User();
    e30_confirmed(e30_supporter(['user_id' => $private->id, 'is_public' => false]), $program, '60');
    $anonymous = e16User();
    e30_confirmed(e30_supporter(['user_id' => $anonymous->id]), $program, '60', ['is_anonymous' => true]);

    foreach ([$private, $anonymous] as $user) {
        $this->actingAs($viewer)->get(route('players.show', $user))->assertOk()->assertDontSee('data-supporter-recognition', false);
    }
});

test('AM3-30: the client cannot submit a tier; tiers are never stored on the supporter', function () {
    $supporter = e30_supporter(['tier' => 'diamond', 'tier_id' => 5, 'tier_slug' => 'diamond']);

    expect($supporter->getAttributes())->not->toHaveKeys(['tier', 'tier_id', 'tier_slug']);
    expect(e30_tier($supporter))->toBeNull();
    $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('supporter_profiles', 'tier'));
});
`````

### `tests/Feature/SponsorshipE30/_support.php`

`sha256: 4c542959a734715d8c99aad5f2488e8753b2e64fa023b192f7adc6a88216925e`

`````php
<?php

use App\Models\Campaign;
use App\Models\Season;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterService;

/*
| E30 (الرعاية) - مساعدات مشتركة. كل شيء على SQLite وكاش array؛ لا شبكة ولا بوابة دفع. كل تهيئة تمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات).
| تعتمد على مساعدات E28 (e28_*) و E16 (e16User).
*/

require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e30_seed')) {
    function e30_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** مدير (كل صلاحيات الرعاية). */
    function e30_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    /** مدير محتوى (عرض + إدارة بلا تأكيد مالي ولا خطة مكافآت). */
    function e30_manager(): User
    {
        $user = e16User();
        $user->assignRole('content-manager');

        return $user;
    }

    function e30_supporter(array $over = [], ?User $actor = null): SupporterProfile
    {
        return app(SupporterService::class)->create($actor ?? e30_admin(), $over + ['display_name' => 'داعم '.fake()->unique()->numerify('####'), 'type' => 'individual']);
    }

    function e30_company(array $over = []): SupporterProfile
    {
        return e30_supporter($over + ['display_name' => 'شركة '.fake()->unique()->numerify('####'), 'type' => 'organization']);
    }

    /** برنامج منصة (أو غيره) بالخدمة الرسمية؛ $activate يفعّله. */
    function e30_program(array $over = [], bool $activate = true, ?User $actor = null): SponsorshipProgram
    {
        $actor ??= e30_admin();
        $program = app(SponsorshipProgramService::class)->create($actor, $over + ['title' => 'برنامج '.fake()->unique()->numerify('####'), 'target_type' => 'platform']);

        return $activate ? app(SponsorshipProgramService::class)->activate($actor, $program) : $program;
    }

    /** تفعيل خطة التعزيز على برنامج (الخطوة بعملة المحاسبة). */
    function e30_boost(SponsorshipProgram $program, string|int $step = '100', int $perStep = 100, int $max = 1000, ?User $actor = null): SponsorshipProgram
    {
        return app(SponsorshipProgramService::class)->updateRewardPlan($actor ?? e30_admin(), $program->fresh(), ['enabled' => true, 'step' => $step, 'per_step_bps' => $perStep, 'max_bps' => $max]);
    }

    /** مساهمة معلّقة. */
    function e30_pending(SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount = '10', array $options = [], string $currency = 'USD'): SponsorshipContribution
    {
        return app(ContributionService::class)->record(e30_admin(), $supporter, $program, $amount, $currency, $options);
    }

    /** مساهمة مؤكَّدة (تسجيل ثم تأكيد). */
    function e30_confirmed(SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount = '10', array $options = []): SponsorshipContribution
    {
        $admin = e30_admin();
        $contribution = app(ContributionService::class)->record($admin, $supporter, $program, $amount, 'USD', $options);

        return app(ContributionService::class)->confirm($admin, $contribution);
    }

    function e30_refund(SponsorshipContribution $contribution, string $reason = 'استرداد تجريبي للاختبار'): SponsorshipContribution
    {
        return app(ContributionService::class)->refund(e30_admin(), $contribution->fresh(), $reason);
    }

    function e30_season(): Season
    {
        $campaign = Campaign::factory()->create(['is_active' => true]);
    
        return Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    }
    
    function e30_role(\App\Models\SponsorshipProgram $program, \App\Models\SupporterProfile $supporter, string $role)
    {
        return app(SponsorshipProgramService::class)->assignRole(e30_admin(), $program, $supporter, $role);
    }
}
`````


---

## B) MODIFIED FILES — ملفات كاملة (استبدال) (29 ملفًا)

### `.env.example`

`sha256: 2f0ad89f7605b32ff3108e50d23a33193b5019dd6615a4c5aa450ec8007d82f2`

`````
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb

# E21: Laravel Reverb (خادم WebSocket من الطرف الأول). عبّئ القيم محليًا/بالإنتاج: لا أسرار حقيقية هنا.
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"

# ==============================================================================
# E24: الأداء / التوسّع / الموثوقية / المراقبة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# ==============================================================================
# CACHE_STORE=database يتطلب جدولَي cache وcache_locks (تنشئهما migration 2026_10_18_000001 بعد php artisan migrate).
# توصية الإنتاج متعدد الخوادم (توصية بنية تحتية، غير مفروضة محليًا): CACHE_STORE=redis و SESSION_DRIVER=redis و QUEUE_CONNECTION=redis
# مع REDIS_CLIENT=phpredis؛ أبقِ الجلسات/الكاش/الطوابير على قواعد Redis (DB) منفصلة. المجدول onOneServer يحتاج كاشًا مشتركًا بين الخوادم.
# DB_QUEUE_RETRY_AFTER=90          # يجب أن يظل أكبر من timeout أي Job (80ث) كي لا تُلتقط مهمة ما زالت تعمل
# REDIS_QUEUE_RETRY_AFTER=90

# خلف موازن حِمل/وكيل عكسي: بدونها يرى التطبيق IP الوكيل بدل IP العميل (يفسد حدود المعدّل وبصمات الاحتيال).
# فارغ/غير معرَّف = لا وكلاء موثوقة (آمن افتراضيًا)؛ "*" = كل الوكلاء (فقط إن كان التطبيق غير قابل للوصول إلا عبر الموازن)؛ أو قائمة IP/CIDR مفصولة بفواصل.
# TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12

# المجدول: نفّذ كل مهمة على خادم واحد فقط عند تعدّد الخوادم (يحتاج كاشًا مشتركًا). على خادم واحد لا أثر له.
# PERF_SCHEDULER_ONE_SERVER=true

# تتبّع الجهاز (الاحتيال): مرة كل N ثانية لكل (مستخدم، IP، جهاز) بدل كل طلب. 0 = السلوك القديم (كل طلب).
# PERF_DEVICE_SIGHTING_THROTTLE=60

# مدد الكاش للعرض فقط (ثوانٍ، محدودة دائمًا). لا تُخزَّن بيانات صلاحيات أو أرصدة أو مكافآت.
# PERF_LEVEL_CATALOG_TTL=3600
# PERF_BRANDING_URLS_TTL=600
# PERF_LEADERBOARD_TTL=60

# المراقبة: معرّف الطلب X-Request-ID، وسجل الطلبات البطيئة (لا أجسام/كوكيز/رؤوس/سلسلة استعلام). 0 = تعطيل السجل البطيء.
# PERF_REQUEST_ID=true
# PERF_SLOW_REQUEST_MS=1500
# PERF_SLOW_REQUEST_SAMPLE=1.0
# فحص الجاهزية GET /ready (قاعدة البيانات + الكاش) ومهلته؛ الرمز اختياري (الرأس X-Health-Token). GET /up الأصلي للحياة فقط.
# PERF_READINESS_TIMEOUT_MS=1500
# PERF_READINESS_TOKEN=

# مهل اتصال PHP -> Reverb للبثّ المتزامن (ثوانٍ). قصيرة كي لا يحجز Reverb المعلّق عامل PHP-FPM ثلاثين ثانية.
# REVERB_HTTP_CONNECT_TIMEOUT=1
# REVERB_HTTP_TIMEOUT=3

# ==============================================================================
# E25: التشغيل بالإنتاج / الأمان / النشر / الاستعادة  (كلها اختيارية؛ الافتراضات تحفظ سلوك المنتج كما هو)
# القيم هنا placeholders فقط: لا أسرار حقيقية بهذا الملف ولا بأي ملف بالمستودع. أسرار الإنتاج من مدير أسرار المزوّد أو متغيرات بيئة محميّة (docs/environment-variables.md).
# ==============================================================================
# ملخص عقد الإنتاج (php artisan production:check يفحصه ويعطي PASS/WARN/FAIL بلا طباعة أي قيمة سرية):
#   APP_ENV=production  APP_DEBUG=false  APP_URL=https://<نطاقك>  SESSION_SECURE_COOKIE=true
#   DB_CONNECTION=mysql (أو pgsql)  QUEUE_CONNECTION=database|redis  MAIL_MAILER=smtp  LOG_STACK=daily
#   BROADCAST_CONNECTION=reverb مع REVERB_SCHEME=https و REVERB_HOST=<نطاق> و REVERB_ALLOWED_ORIGINS=<نطاقك>

# كوكي الجلسة: إلزامي true بالإنتاج على HTTPS (يمنع إرسالها عبر HTTP). محليًا اتركه غير مضبوط.
# SESSION_SECURE_COOKIE=true
# SESSION_HTTP_ONLY=true
# SESSION_SAME_SITE=lax

# هوية الإصدار (اختيارية): تُضبط عند كل نشر (SHA مختصر أو وسم). تظهر في /ready ومركز العمليات وكل سطر سجل (release). الصيغة [A-Za-z0-9._-] حتى 64 خانة.
# APP_RELEASE=
# RELEASE_SHA=

# السجلات: بالإنتاج استعمل daily مع احتفاظ محدود (القناة single بلا تدوير ولا حد حجم). LOG_LEVEL=info أو warning بدل debug.
# LOG_STACK=daily
# LOG_DAILY_DAYS=14

# Reverb: قيّد الأصول المسموحة لاتصالات WebSocket (أسماء مضيفين مفصولة بفواصل، بلا http://). الافتراضي * (يسمح للجميع).
# REVERB_ALLOWED_ORIGINS=ahjiyat.example.com

# أول مدير بالإنتاج فقط (AdminUserSeeder): لا كلمة مرور افتراضية بالإنتاج. ضعهما مؤقتًا بالبيئة لأول تشغيل ثم احذفهما فورًا. ≥ 12 خانة.
# ADMIN_BOOTSTRAP_EMAIL=
# ADMIN_BOOTSTRAP_PASSWORD=

# نبض المجدول وتنبيهات الطابور (ثوانٍ؛ كلها اختيارية).
# OPS_SCHEDULER_STALE_SECONDS=300
# OPS_SCHEDULER_DOWN_SECONDS=900
# OPS_READY_FAILS_ON_SCHEDULER=false     # true: /ready يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد)
# OPS_QUEUE_BACKLOG_WARN_SECONDS=900
# OPS_FAILED_JOBS_WARN=1

# الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج. استثناء واعٍ لعملية واحدة فقط (لا تضعه بملف .env الدائم):
# OPS_ALLOW_DESTRUCTIVE_COMMANDS=false

# E26 - أمان التطبيق (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/security-e26.md.
# SECURITY_LOGIN_MAX_ATTEMPTS=5              # محاولات دخول فاشلة لكل (بريد+IP) قبل القفل المؤقت
# SECURITY_LOGIN_DECAY_SECONDS=300           # مدة القفل بالثواني
# SECURITY_LOGIN_IP_MAX_ATTEMPTS=30          # سقف الفشل لكل IP (رش كلمات المرور). أعلى بكثير كي لا يضرّ شبكة مشتركة
# SECURITY_API_EXPIRY_MINUTES=43200       # عمر رمز API (30 يومًا). 0 = بلا انتهاء (غير مستحسن)
# SECURITY_CSP_BASELINE=true                 # CSP أساسي (frame-ancestors/base-uri/object-src) لا يمسّ تنفيذ السكربتات
# REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none  # أحداث العميل مغلقة (التطبيق لا يستعملها)
# SECURITY_TRUST_HOSTS=true                  # يقيّد Host بمضيف APP_URL بالإنتاج (يمنع تسميم روابط إعادة التعيين). تأكد أن APP_URL هو النطاق الفعلي
# SECURITY_TRUSTED_HOSTS=www.example.com     # مضيفات إضافية مسموحة (مفصولة بفواصل)

# E27 - البحث العام والاكتشاف والرائج والتوصيات (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/search-discovery-e27.md.
# SEARCH_DRIVER=database                     # السائق الوحيد حاليًا. قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع). محرك خارجي = تنفيذ جديد لـSearchBackend
# SEARCH_MIN_LENGTH=2                        # أقل طول للمصطلح (حروف بعد التطبيع)
# SEARCH_MIN_LENGTH_GUEST_PLAYERS=3          # الضيف يحتاج 3 أحرف للبحث عن لاعبين (يحدّ التخمين)
# SEARCH_PARTIAL_MAX_ID=10000                # أكبر معرّف بجدول يُسمح عنده بالمطابقة الجزئية (وسط الكلمة/الحروف العربية المتقاربة). فوقه: بادئة العمود فقط. هذا مؤشر الانتقال لمحرك بحث/FULLTEXT
# SEARCH_TRENDING_WINDOW_DAYS=7              # نافذة «الرائج»
# SEARCH_TRENDING_TTL=300                    # عمر لقطة الرائج بالثواني (معرّفات ودرجات فقط)
# SEARCH_TRENDING_MIN_PLAYERS=2              # أقل عدد لاعبين مميَّزين كي تظهر أحجية بالرائج

# E28 - منظومة المبدعين وأحجيات المجتمع (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/creator-ecosystem-e28.md.
# أحجيات المجتمع بلا مكافآت اقتصادية بأي إعداد (جواهر/XP = 0 ثابت بالكود، لا مفتاح بيئة له).
# CREATOR_PUZZLE_MAX_ATTEMPTS=3              # عدد محاولات اللاعب لكل أحجية مجتمع (سيرفري، لا يحدده المبدع)
# CREATOR_MAX_WORKS=100                      # سقف أعمال المبدع الواحد
# CREATOR_MAX_OPEN_DRAFTS=20                 # سقف المسودات المفتوحة لكل مبدع
# CREATOR_MAX_PENDING=5                      # سقف الأعمال قيد المراجعة معًا لكل مبدع
# CREATOR_IMAGE_MAX_KB=4096                  # أقصى حجم صورة أحجية (كيلوبايت). JPEG/PNG/WEBP فقط، لا SVG
# CREATOR_ANALYTICS_TTL=120                  # عمر لقطة تحليلات المبدع بالثواني (أرقام مجمَّعة فقط)
# CREATOR_TRENDING_MIN_PLAYERS=2             # أقل عدد لاعبين مميَّزين كي يظهر عمل بقسم «رائج من المجتمع»

# E29 - التفاعل المجتمعي: التقييمات والمفضلة ومتابعة المبدعين والمجموعات الشخصية والأعلى تقييمًا (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/community-engagement-e29.md.
# التفاعل بلا أي سلطة اقتصادية بأي إعداد (لا جواهر/XP/مهام/سلسلة/إنجازات/صدارة): لا مفتاح بيئة لذلك.
# ENGAGEMENT_RATING_DISPLAY_MIN=3            # أقل عدد تقييمات لإظهار «المتوسط» علنًا (تحته يُعرض العدد فقط)
# ENGAGEMENT_TOP_MIN_RATINGS=5               # أقل عدد تقييمات كي يدخل العمل ترتيب «الأعلى تقييمًا»
# ENGAGEMENT_TOP_PRIOR_MEAN=3.5              # المتوسط الافتراضي للانكماش البايزي: score = (sum + mean*weight) / (count + weight)
# ENGAGEMENT_TOP_PRIOR_WEIGHT=5              # وزن المتوسط الافتراضي (كم «تقييمًا وهميًا»)؛ كلما كبر احتاج الوصول للقمة تقييمات أكثر
# ENGAGEMENT_TOP_CACHE_TTL=300               # عمر لقطة الأعلى تقييمًا بالثواني (معرّفات ودرجات فقط؛ الظهور يُفحص حيًّا)
# ENGAGEMENT_CREATOR_MIN_RATINGS=5           # أقل مجموع تقييمات لإظهار ملخّص جودة المبدع بصفحته العامة
# ENGAGEMENT_MAX_FOLLOWS=500                 # سقف من يتابعهم المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTIONS=20              # سقف مجموعات المستخدم الواحد
# ENGAGEMENT_MAX_COLLECTION_ITEMS=100        # سقف عناصر المجموعة الواحدة
# ENGAGEMENT_NOTIFY_ON_REVISION=true         # إشعار المتابعين أيضًا عند اعتماد نسخة جديدة لعمل منشور سابقًا

# E30 - الرعاة والداعمون وتمويل المكافآت + سلاسل المبدعين (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/sponsorship-supporters-e30.md و docs/creator-series-e30.md.
# سلاسل المبدعين بلا أي سلطة اقتصادية بأي إعداد ولا يطالها تعزيز الرعاية: لا مفتاح بيئة لذلك.
# SPONSORSHIP_ENABLED=true                   # تعطيل شامل لواجهات الرعاية والتعزيز (التاريخ المالي يبقى كما هو)
# SPONSORSHIP_ACCOUNTING_CURRENCY=USD        # عملة المحاسبة الموحَّدة للمجاميع والمستويات والتعزيز
# SPONSORSHIP_MAX_AMOUNT_MINOR=1000000000    # سقف المبلغ الواحد بالوحدة الصغرى (حماية من أخطاء الإدخال)
# SPONSORSHIP_LOGO_DISK=public               # قرص شعارات الداعمين (JPEG/PNG/WEBP فقط)
# SPONSORSHIP_LOGO_MAX_KB=1024               # أقصى حجم شعار بالكيلوبايت
# SPONSORSHIP_CACHE_TTL=120                  # عمر لقطات اللوحات العامة بالثواني (معرّفات ومجاميع فقط؛ الظهور يُفحص حيًّا)
# SPONSORSHIP_DISPLAY_TTL=60                 # عمر لقطة شريط الرعاة بالثواني
# SPONSORSHIP_REWARD_BOOST_ENABLED=true      # تعزيز مكافآت المحتوى الرسمي فقط (false = لا تعزيز إطلاقًا)
# SPONSORSHIP_HARD_MAX_BOOST_BPS=5000        # السقف الصلب للتعزيز بالنقاط الأساسية (5000 = 50%) لا يتجاوزه أي برنامج
# SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES=60     # قفل خطة تعزيز المنافسة قبل بدئها بهذه المدة (دقائق)
# SPONSORSHIP_NOTIFICATIONS_ENABLED=true     # إشعارات الداعمين (تأكيد الدعم/تغيّر المستوى/بدء البرنامج)
# CREATOR_SERIES_ENABLED=true                # تعطيل سلاسل المبدعين (404) دون مساس بأحجيات المجتمع
# CREATOR_SERIES_MIN_ITEMS=2                 # أقل عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_ITEMS=25                # أقصى عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_PER_CREATOR=50          # سقف سلاسل المبدع الواحد غير المؤرشفة
# CREATOR_SERIES_MAX_PENDING=5               # سقف السلاسل قيد المراجعة معًا لكل مبدع
# CREATOR_SERIES_DISCOVERY_TTL=120           # عمر كاش معرّفات «أحدث السلاسل» (الظهور يُفحص حيًّا بكل عرض)
# CREATOR_SERIES_ANALYTICS_TTL=600           # عمر لقطة تحليلات سلاسل المبدع (أرقام مجمَّعة بلا هويات)
# CREATOR_SERIES_NOTIFY_FOLLOWERS=true       # إشعار المتابعين عند نشر سلسلة جديدة (دفعات، idempotent)
# CREATOR_SERIES_NOTIFY_ON_REVISION=true     # إشعار المتابعين عند اعتماد نسخة جديدة لسلسلة منشورة سابقًا
`````

### `app/Console/Commands/PruneCreatorMedia.php`

`sha256: 2aead416a580aca6de74d30f5537e98b432785a49c8aa50764f368314a578589`

`````php
<?php

namespace App\Console\Commands;

use App\Models\CreatorPuzzleRevision;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesRevision;
use App\Models\Puzzle;
use App\Services\Creators\CreatorMediaService;
use Illuminate\Console\Command;

class PruneCreatorMedia extends Command
{
    protected $signature = 'creators:prune-media {--hours=24 : مهلة أمان بالساعات قبل اعتبار الملف يتيمًا} {--dry-run : عرض ما سيُحذف دون حذف}';

    protected $description = 'E28: يحذف صور أحجيات المبدعين وأغلفة سلاسلهم اليتيمة (ملفات غير مرتبطة بأي نسخة ولا أحجية ولا سلسلة). لا يمسّ ملفًا مرتبطًا ولا ملفًا أحدث من مهلة الأمان. صحة النظام لا تعتمد عليه.';

    public function handle(CreatorMediaService $media): int
    {
        $grace = max(1, (int) $this->option('hours'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($grace)->getTimestamp();
        $directory = trim((string) config('creators.media.directory'), '/');
        $disk = $media->disk();

        $referenced = [];

        CreatorPuzzleRevision::query()->whereNotNull('image_path')->select('id', 'image_path')
            ->chunkById(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    $referenced[$row->image_path] = true;
                }
            });

        Puzzle::query()->where('origin', Puzzle::ORIGIN_COMMUNITY)->whereNotNull('image_path')->select('id', 'image_path')
            ->chunkById(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    $referenced[$row->image_path] = true;
                }
            });

        // E30: أغلفة السلاسل تُخزَّن بالمجلد نفسه عبر CreatorMediaService؛ بدون هذا تعدّها الأداة يتيمة وتحذف غلاف سلسلة منشورة.
        CreatorSeriesRevision::query()->whereNotNull('cover_path')->select('id', 'cover_path')
            ->chunkById(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    $referenced[$row->cover_path] = true;
                }
            });

        CreatorSeries::query()->whereNotNull('published_cover_path')->select('id', 'published_cover_path')
            ->chunkById(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    $referenced[$row->published_cover_path] = true;
                }
            });

        $removed = 0;

        foreach ($disk->allFiles($directory) as $path) {
            if (isset($referenced[$path]) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            if (! $dry) {
                $disk->delete($path);
            }

            $removed++;
        }

        $this->info(($dry ? 'سيُحذف ' : 'حُذف ').$removed.' ملفًا يتيمًا أقدم من '.$grace.' ساعة.');

        return self::SUCCESS;
    }
}
`````

### `app/Http/Controllers/Creators/CommunityPuzzleController.php`

`sha256: b5c6d2f62460c1390655ce8679decc462a8e9be9bd3ebcab02dfe73b705782de`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creators\ReportCommunityPuzzleRequest;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorPuzzle;
use App\Models\PuzzleCategory;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CommunityReportService;
use App\Services\CreatorSeries\CreatorSeriesNavigator;
use App\Services\Creators\CreatorException;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E28 - صفحات أحجيات المجتمع العامة واللعب والبلاغ. الظهور من is_visible وحدها (منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد وحسابه سليم):
 * أي عمل غير ظاهر = 404 للجميع (مسودة/مرفوض/مخفي/مؤرشف/معلَّق). المسار منفصل عن /puzzles الرسمي وكل اللعب عبر CommunityPlayService (بلا اقتصاد).
 */
class CommunityPuzzleController extends Controller
{
    public function __construct(
        protected CommunityPlayService $play,
        protected CommunityReportService $reports,
        protected GameTypeRegistry $games,
        protected EngagementViewerState $viewerState,
        protected CollectionService $collections,
        protected CreatorSeriesNavigator $seriesNavigator,
    ) {}

    public function index(Request $request)
    {
        $categorySlug = $request->query('category');
        $category = is_string($categorySlug) ? PuzzleCategory::query()->where('slug', $categorySlug)->where('is_active', true)->first(['id', 'name', 'slug']) : null;

        $works = CreatorPuzzle::query()->visible()
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count', 'ratings_count', 'ratings_sum', 'favorites_count')
            ->when($category, fn ($q) => $q->where('published_category_id', $category->id))
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedCategory:id,name'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'))->withQueryString();

        return view('community.index', [
            'works' => $works,
            'category' => $category,
            // E29: مفضلة المشاهد لصفحة البطاقات بدفعة واحدة (استعلام واحد للمستخدم المسجَّل، صفر للضيف).
            'savedIds' => $this->viewerState->favoriteIds($request->user(), $works->getCollection()->pluck('id')->all()),
            'categories' => PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Request $request, string $work)
    {
        $work = $this->visibleWork($work);
        $puzzle = $work->publishedPuzzle;
        abort_unless($puzzle !== null && $puzzle->isCommunity(), 404);

        $user = $request->user();
        $state = $this->play->state($user, $puzzle);
        $isOwner = $user !== null && $work->creator->user_id === $user->id;
        $canInteract = $user !== null && $user->hasVerifiedEmail() && ! $user->is_frozen;

        // E29: حالة المشاهد (تقييمي/محفوظ/جرّب) باستعلام واحد + منتقي المجموعات باستعلام واحد، ولغير المالك الموثَّق فقط. الضيف والمالك: صفر استعلامات إضافية.
        $viewer = $canInteract && ! $isOwner ? $this->viewerState->forWork($user, $work->id) : null;
        $collections = $viewer !== null ? $this->collections->pickerFor($user, $work->id) : collect();

        // E30: سياق السلسلة (?series=): يعمل فقط حين يوجد المعامل وكانت الأحجية عنصرًا في سلسلة ظاهرة؛ قراءة فقط ولا يغيّر اللعب ولا الاقتصاد.
        $seriesContext = $request->query->has('series') ? $this->seriesNavigator->context($request->query('series'), (int) $work->id) : null;

        return view('community.show', [
            'seriesContext' => $seriesContext,
            'viewer' => $viewer,
            'collections' => $collections,
            'work' => $work,
            'puzzle' => $puzzle,
            'state' => $state,
            'renderer' => $this->games->rendererFor($puzzle),
            'isOwner' => $isOwner,
            'canInteract' => $canInteract,
            'reasons' => CommunityPuzzleReport::REASONS,
        ]);
    }

    public function attempt(SolvePuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);
        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->play->attempt($request->user(), $work, (string) $request->input('answer', ''), $submission);
        } catch (CreatorException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['correct']) {
            return back()->with('success', 'إجابة صحيحة! أحسنت. (أحجيات المجتمع بلا جوائز اقتصادية.)');
        }

        return back()->with('error', $result['attempts_left'] > 0
            ? "إجابة غير صحيحة. تبقى لك {$result['attempts_left']} محاولة."
            : 'إجابة غير صحيحة. لقد استنفدت محاولاتك لهذه الأحجية.');
    }

    public function hint(Request $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            return back()->with('hint', $this->play->hint($work));
        } catch (CreatorException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function report(ReportCommunityPuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $this->reports->report($request->user(), $work, (string) $request->validated('reason'), $request->validated('details'));
        } catch (CreatorException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'وصلنا بلاغك، شكرًا لمساعدتك في إبقاء المنصة آمنة.');
    }

    /** عمل ظاهر للعامة وإلا 404. */
    protected function visibleWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->visible()->where('public_id', $publicId)
            ->with(['creator.user:id,name', 'publishedPuzzle', 'publishedCategory:id,name'])
            ->firstOrFail();
    }
}
`````

### `app/Http/Controllers/Creators/PublicCreatorController.php`

`sha256: da2a7e5681c516c7de9aebb4835726644d0080a7e006be36d412139bfe57d522`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Services\Engagement\CommunityQualityService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementViewerState;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use Illuminate\Http\Request;

/**
 * E28 - صفحة المبدع العامة /creators/{slug}. عامة للضيوف لكن لمبدع معتمَد وحسابه غير مجمَّد فقط (غيره 404).
 * تعرض: الاسم، الصورة الرمزية (هوية اللاعب القائمة)، النبذة، تاريخ الانضمام كمبدع، عدد المنشور، إجمالي اللاعبين، والأحجيات الظاهرة فقط (مرقَّمة).
 * لا بريد ولا هاتف ولا حالة إدارية ولا علامات احتيال ولا أي بيانات لاعب خاصة.
 */
class PublicCreatorController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected CreatorFollowService $follows,
        protected CommunityQualityService $quality,
        protected EngagementViewerState $viewerState,
        protected CreatorSeriesVisibilityService $seriesVisibility,
    ) {}

    public function show(Request $request, CreatorProfile $creator)
    {
        $creator->loadMissing('user:id,name,is_frozen');

        abort_unless($creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen, 404);

        $totals = CreatorPuzzle::query()->visible()->where('creator_profile_id', $creator->id)
            ->selectRaw('COUNT(*) as published, COALESCE(SUM(plays_count), 0) as plays, COALESCE(SUM(ratings_count), 0) as rated, COALESCE(SUM(ratings_sum), 0) as rated_sum')->first();

        $works = CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $creator->id)
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count', 'ratings_count', 'ratings_sum', 'favorites_count')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'));

        // E30: سلاسل المبدع الظاهرة فقط (الظهور حيًّا من CreatorSeriesVisibilityService: عنصر مخفي/مبدع معلَّق/مؤرشفة = غير موجودة). قسم مستقل لا يكسر الصفحة إن تعطّلت السلاسل.
        $series = collect();

        if ((bool) config('creator_series.enabled', true)) {
            try {
                $series = $this->seriesVisibility->visibleQuery()
                    ->where('creator_series.creator_profile_id', $creator->id)
                    ->select('creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_title', 'creator_series.published_items_count', 'creator_series.published_at')
                    ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
                    ->limit((int) config('creator_series.page_size', 12))->get();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $loadout = $this->loadouts->loadoutFor($creator->user);
        $user = $request->user();

        // E29: حالة زر المتابعة بقرار واحد (استعلام exists واحد للموثَّق فقط). لا هوية متابعين تُعرض؛ العدد المجمَّع (followers_count) علني.
        $followState = match (true) {
            $user === null => 'guest',
            $user->id === $creator->user_id => 'self',
            ! $user->hasVerifiedEmail() => 'verify',
            (bool) $user->is_frozen => 'frozen',
            $this->follows->isFollowing($user, $creator->id) => 'following',
            default => 'can_follow',
        };

        return view('creator.public', [
            'creator' => $creator,
            'avatar' => $loadout['avatar'] ?? null,
            'frame' => $loadout['profile_frame'] ?? null,
            'published' => (int) ($totals->published ?? 0),
            'plays' => (int) ($totals->plays ?? 0),
            'works' => $works,
            'seriesList' => $series,
            'followState' => $followState,
            'followers' => (int) $creator->followers_count,
            'quality' => $this->quality->creatorSummary((int) ($totals->rated ?? 0), (int) ($totals->rated_sum ?? 0)),
            'savedIds' => $this->viewerState->favoriteIds($user, $works->getCollection()->pluck('id')->all()),
        ]);
    }
}
`````

### `app/Http/Controllers/PlayerProfileController.php`

`sha256: 444c19471b129f8c3156c32b9b24491e4a588f5c5d16b54dc68c8d3d919e6fcf`

`````php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\PlayerIdentity\PlayerProfileService;
use App\Services\Competitive\CompetitiveStatsService;
use App\Services\Social\FriendshipService;
use Illuminate\Support\Facades\Auth;

class PlayerProfileController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected PlayerProfileService $stats,
        protected FriendshipService $friendships,
        protected CompetitiveStatsService $competitive,
    ) {}

    public function show(User $user)
    {
        abort_unless($user->profileViewableBy(Auth::user()), 404);

        $loadout = $this->loadouts->loadoutFor($user);
        $stats = $this->stats->safeStatsFor($user);

        $viewer = Auth::user();
        $isOwner = $viewer !== null && $viewer->id === $user->id;

        return view('players.show', [
            'player' => $user,
            'loadout' => $loadout,
            'stats' => $stats,
            'isOwner' => $isOwner,
            // E16: حالة العلاقة تُعرض للمسجَّل الموثَّق فقط (وليست لصاحب الملف)؛ وعدد الأصدقاء رقم مجمَّع بلا هويات.
            'friendRelation' => ($viewer !== null && ! $isOwner && $viewer->hasVerifiedEmail()) ? $this->friendships->relationBetween($viewer, $user) : null,
            'friendsCount' => $this->friendships->friendsCount($user),
            // E18: ملخص تنافسي مشتق (أرقام مجمَّعة من نتائج معتمَدة فقط).
            'competitive' => $this->competitive->eventStats($user),
            // E19: شارة الفريق (فريق عام مفعَّل فقط؛ الخاص/المعطَّل لا يُكشف).
            'team' => app(\App\Services\Teams\TeamMembershipService::class)->publicTeamFor($user),
            // E30: شارة «داعم» مشتقّة (علنية فقط وغير مجهولة)؛ معزولة الفشل ولا تعطّل الملف.
            'supporter' => $this->supporterBadge($user),
        ]);
    }

    /** @return array{name: string, slug: string, tier: string, badge_key: string}|null */
    protected function supporterBadge(User $user): ?array
    {
        try {
            return app(\App\Services\Sponsorship\SupporterRecognitionService::class)->forUser($user);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
`````

### `app/Providers/AppServiceProvider.php`

`sha256: 5b76110de47fc9385b63df88e452676e369e738f2250d24c2f0ef52bd9cc7341`

`````php
<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthorizationSafetyService;
use App\Services\Teams\TeamService;
use App\Support\Security\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use App\Support\ReleaseInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // E27: محرك البحث. السائق غير المعروف يسقط إلى database (لا يعطّل الموقع). محرك خارجي مستقبلي = تنفيذ جديد لـSearchBackend وفرع هنا.
        $this->app->singleton(\App\Services\Search\Contracts\SearchBackend::class, fn ($app) => match ((string) config('search.driver', 'database')) {
            default => $app->make(\App\Services\Search\DatabaseSearchBackend::class),
        });
    }

    public function boot(): void
    {
        // E22: قائمة المحادثات الجانبية لصفحة الغرفة (قراءة فقط، بلا إنشاء غرف عند العرض).
        \Illuminate\Support\Facades\View::composer('chat.room', fn ($view) => $view->with('sidebar', app(\App\Services\Chat\ChatSidebar::class)->for(auth()->user())));

        // E19-E20: قبل حذف أي حساب يُعالَج ما يملكه من فرق (نقل الملكية لأقدم مشرف/عضو، وإلا أرشفة): لا فريق يتيم بلا مالك.
        // فشل المعالجة لا يمنع الحذف (owner_id يصير NULL بقيد القاعدة والفريق يبقى قابلًا لتدخل الإدارة).
        User::deleting(function (User $user) {
            try {
                app(TeamService::class)->releaseOwnershipFor($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // E29: قبل حذف أي حساب تُنقَص عدّادات ما تفاعل معه (مفضلة/متابعات) فتبقى المجمَّعات دقيقة؛ الصفوف نفسها تتتابع بقيد القاعدة، والتقييمات تبقى مجهولة الهوية.
        // فشل التنظيف لا يمنع الحذف؛ community:reconcile-engagement يصلح أي انحراف.
        User::deleting(function (User $user) {
            try {
                app(\App\Services\Engagement\EngagementCleanup::class)->purgeUser($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Super Admin يتجاوز كل فحص can()/authorize() في التطبيق بالكامل -
        // Server-side حصراً. مصدر الفحص الوحيد AuthorizationSafetyService.
        Gate::before(function ($user, string $ability) {
            return app(AuthorizationSafetyService::class)->isSuperAdmin($user) ? true : null;
        });

        $this->registerRateLimiters();
        $this->registerOperationalGuards();
    }

    /**
     * E25: حواجز التشغيل بالإنتاج.
     *  - الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج: الترحيلات بالإنتاج تتقدّم للأمام فقط (forward-fix) والتراجع عن القاعدة غير آمن بعد كتابات الإصدار الجديد.
     *    استثناء واعٍ ومؤقت بمتغير بيئة لتلك العملية وحدها: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true (انظر docs/release-engineering.md). لا يُضاف إلى .env الدائم.
     *  - معرّف الإصدار (إن ضُبط) يُضاف لسياق كل سطر سجل (release) بجانب request_id من E24، لربط أي خطأ بالإصدار الذي أنتجه. لا أسرار فيه.
     */
    protected function registerOperationalGuards(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction() && ! config('operations.allow_destructive_commands', false));

        if (($release = ReleaseInfo::short()) !== null) {
            Log::shareContext(['release' => $release]);
        }
    }

    /**
     * E8: بنية Rate Limiting مركزية واحدة - بدل أرقام مبعثرة (throttle:20,1)
     * بكل Route مباشرة. المفتاح: user ID للمصادَق عليهم (بند 26 - نفس سلوك
     * Laravel الافتراضي أصلاً بمجموعات auth/verified)، أو IP للضيوف حصراً
     * (تسجيل/استرجاع كلمة سر - لا يوجد مستخدم مصادَق عليه بعد أصلاً).
     */
    protected function registerRateLimiters(): void
    {
        // E8: لم تكن محمية إطلاقاً سابقاً (بند 21) - تسجيل حساب جديد.
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
        });

        // E8: لم تكن محمية إطلاقاً سابقاً (بند 22) - منع email flooding.
        // E26: حدّ ثانٍ **لكل مستلم** (بريد مُجزَّأ، لا بريد خام بمفتاح المحدِّد) كي لا يُغرِق مهاجمٌ بعناوين IP متعددة صندوق ضحية واحدة.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinutes(10, 5)->by('pr-ip:'.$request->ip()),
                Limit::perHour(3)->by('pr-rcpt:'.Fingerprint::email($request->input('email'))),
            ];
        });

        // E26: تنفيذ إعادة التعيين نفسه (POST /reset-password) لم يكن محدودًا. الرمز عشوائي طويل، لكن لا سبب لقبول طرق غير محدودة.
        RateLimiter::for('password-reset-submit', function (Request $request) {
            return Limit::perMinutes(10, 10)->by('prs-ip:'.$request->ip());
        });

        // E26: تأكيد كلمة المرور (confirm-password) كان قابلًا للتخمين بلا حدّ داخل جلسة مسروقة. المفتاح المستخدم.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(6)->by('pc:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: تعديل بيانات الحساب الحسّاسة (الاسم/البريد/ظهور الملف) - أدنى بكثير من أي استخدام بشري.
        RateLimiter::for('account-sensitive', function (Request $request) {
            return Limit::perMinute(10)->by('acs:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: أفعال لعب خفيفة كانت بلا حدّ (شراء تلميح، انضمام تحدٍّ، إكمال/تأمل خطوة حملة).
        RateLimiter::for('game-actions', function (Request $request) {
            return Limit::perMinute(30)->by('ga:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('puzzle-hint', function (Request $request) {
            return Limit::perMinute(10)->by('ph:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: مصادقة قنوات البثّ الخاصة (/broadcasting/auth): اشتراك كل غرفة مرة واحدة عادةً؛ السقف يمنع استنزاف القاعدة باستعلامات التخويل.
        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(90)->by('bca:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: دخول API (إصدار رمز Sanctum) - حدّ لكل IP وحدّ لكل (بريد مُجزَّأ+IP). لا يتشارك مع محدّد دخول الويب.
        RateLimiter::for('api-login', function (Request $request) {
            return [
                Limit::perMinute(20)->by('apil-ip:'.$request->ip()),
                Limit::perMinutes(5, 5)->by('apil-acct:'.Fingerprint::email($request->input('email')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // أفعال اللعب (بند 24) - نفس الأرقام الحالية بالضبط، فقط مُسمّاة
        // ومركزية الآن. لا نخفّضها - قد تكسر لعبًا طبيعيًا سريعًا.
        RateLimiter::for('puzzle-attempt', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-start', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-reveal', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E14 (بند 606): تحويل نقر الإعلان - عام (قد يشمل زائرًا غير مسجَّل)، معدَّل معقول يمنع إساءة الاستخدام بلا إزعاج تصفُّح عادي.
        RateLimiter::for('ad-click', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // طلبات الاستبدال (بند 25) - نفس الحد الحالي: 5 كل ساعة.
        RateLimiter::for('redemption', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->user()?->id ?: $request->ip());
        });

                // E10: مشتريات المتجر الافتراضي - مفتاح المستخدم (لا IP، دائماً
        // مُصادَق عليه بهذه المرحلة).
        RateLimiter::for('store-purchase', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });

        // E16: الأصدقاء. إرسال الطلبات بالدقيقة وبالساعة (مع cooldown إعادة الإرسال داخل FriendshipService)؛ بقية الإجراءات
        // والبحث أوسع. المفتاح المستخدم المصادَق (لا IP). لا يمنع الاستخدام الطبيعي.
        RateLimiter::for('friend-requests', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(10)->by("friend-req-min:{$key}"), Limit::perHour(60)->by("friend-req-hour:{$key}")];
        });

        RateLimiter::for('friend-actions', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E17: إنشاء تحدٍّ (حد ضيق: يولّد إشعارًا لشخص آخر)، تسجيل المنافسات، ولعب/إرسال نتيجة، وبقية إجراءات التحديات.
        RateLimiter::for('friend-challenges', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(5)->by("fc-min:{$key}"), Limit::perHour(20)->by("fc-hour:{$key}")];
        });

        RateLimiter::for('competitive-register', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // E19: الفرق. إنشاء نادر؛ الدعوات/الطلبات تحت حد يمنع الإزعاج؛ إجراءات الإدارة والقبول معتدلة.
        RateLimiter::for('team-create', fn (Request $request) => [Limit::perMinute(2)->by('tc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('tch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-invite', fn (Request $request) => [Limit::perMinute(10)->by('ti:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('tih:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-join', fn (Request $request) => [Limit::perMinute(8)->by('tj:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('tjh:'.($request->user()?->id ?: $request->ip()))]);
        // E20: تحدّيات الفرق (الإنشاء نادر نسبيًا؛ القبول/الرفض/الإلغاء/الروستر معتدلة؛ اللعب بحد competitive-play القائم).
        RateLimiter::for('team-challenge-create', fn (Request $request) => [Limit::perMinute(4)->by('tcc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('tcch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-challenge-actions', fn (Request $request) => Limit::perMinute(30)->by('tca:'.($request->user()?->id ?: $request->ip())));
        // E21: دردشة. الإرسال حسب النوع (العامة أشد + حماية دفعات)، والبلاغ والتعديل/الحذف والقراءة بحدود منفصلة. المفتاح: المستخدم لا IP.
        RateLimiter::for('chat-dm-send', fn (Request $request) => Limit::perMinute(30)->by('cdm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-team-send', fn (Request $request) => Limit::perMinute(30)->by('ctm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-global-send', fn (Request $request) => [
            Limit::perMinute(10)->by('cgm:'.($request->user()?->id ?: $request->ip())),
            Limit::perSecond(3, 10)->by('cgb:'.($request->user()?->id ?: $request->ip())),     // دفعة: 3 رسائل/10 ثوانٍ
        ]);
        RateLimiter::for('chat-report', fn (Request $request) => [Limit::perMinute(5)->by('crm:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('crh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('chat-edit', fn (Request $request) => Limit::perMinute(30)->by('ced:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-moderate', fn (Request $request) => Limit::perMinute(30)->by('cmo:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-read', fn (Request $request) => Limit::perMinute(120)->by('crd:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('team-actions', fn (Request $request) => Limit::perMinute(40)->by('ta:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('competitive-play', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('player-search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
                // E27: البحث العام. الاقتراحات الفورية أوسع (تُطلَق أثناء الكتابة بعد تأخير 220ms) لكن الضيف أضيق بكثير من العضو (مفتاح IP) كي لا يُستعمل مسحًا لأسماء اللاعبين.
        // صفحة البحث الكاملة أضيق من الاقتراحات. الاكتشاف (القراءة فقط) بحدّ واسع. المفتاح المستخدم المصادَق لا IP (يمنع التفاف مفتاح IP المشترك).
        RateLimiter::for('global-search', fn (Request $request) => $request->user()
            ? Limit::perMinute(60)->by('gs:u:'.$request->user()->id)
            : Limit::perMinute(20)->by('gs:ip:'.$request->ip()));
        RateLimiter::for('search-suggest', fn (Request $request) => $request->user()
            ? [Limit::perMinute(120)->by('ss:u:'.$request->user()->id), Limit::perSecond(16, 2)->by('ssb:u:'.$request->user()->id)]
            : [Limit::perMinute(40)->by('ss:ip:'.$request->ip()), Limit::perSecond(10, 2)->by('ssb:ip:'.$request->ip())]);
        RateLimiter::for('discover', fn (Request $request) => Limit::perMinute(90)->by('dc:'.($request->user()?->id ?: $request->ip())));

        // E28: المبدعون. كلها بمفتاح المستخدم لا IP. الطلب/الإنشاء/الإرسال (ويشمل السحب وإنشاء نسخة جديدة كي لا يُغرَق الإشراف بدورات إرسال/إلغاء) والبلاغات بحدّين دقيقة/ساعة.
        RateLimiter::for('creator-apply', fn (Request $request) => [Limit::perMinute(2)->by('cap:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('caph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-draft-create', fn (Request $request) => [Limit::perMinute(5)->by('cdc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('cdch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-submit', fn (Request $request) => [Limit::perMinute(5)->by('csb:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('csbh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-report', fn (Request $request) => [Limit::perMinute(5)->by('cpr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('cprh:'.($request->user()?->id ?: $request->ip()))]);

        // E29: التفاعل المجتمعي. بمفتاح المستخدم لا IP (كلها خلف auth). حدّان: دقيقة (سرعة بشرية) + ساعة (سقف إغراق/نمط آلي).
        // التقييم/الحفظ يسمحان بتغيير الرأي والتنقل بين أعمال كثيرة، فالحدّ أوسع من المتابعة والمجموعات (أثرهما أثقل على بيانات الآخرين).
        RateLimiter::for('community-rating', fn (Request $request) => [Limit::perMinute(20)->by('crt:'.($request->user()?->id ?: $request->ip())), Limit::perHour(200)->by('crth:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-favorite', fn (Request $request) => [Limit::perMinute(30)->by('cfv:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('cfvh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-follow', fn (Request $request) => [Limit::perMinute(15)->by('cfl:'.($request->user()?->id ?: $request->ip())), Limit::perHour(100)->by('cflh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-actions', fn (Request $request) => [Limit::perMinute(30)->by('cca:'.($request->user()?->id ?: $request->ip())), Limit::perHour(240)->by('ccah:'.($request->user()?->id ?: $request->ip()))]);

        // E30: قراءات الرعاة والسلاسل العامة (صفحات عرض فقط) بحدّ معتدل لكل مستخدم/عنوان؛ إجراءات استوديو السلاسل تُعيد استعمال محدّدات المبدعين (creator-draft-create / creator-submit).
        RateLimiter::for('supporters-read', fn (Request $request) => Limit::perMinute(120)->by('spr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('series-read', fn (Request $request) => Limit::perMinute(120)->by('ser:'.($request->user()?->id ?: $request->ip())));

        // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
        RateLimiter::for('cosmetic-equip', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
        
        // E12: فجوة سابقة على هذه المرحلة - مجموعة Middleware الافتراضية 'api'
        // (المُطبَّقة تلقائيًا على كل مسار بـroutes/api.php) تتضمَّن throttle:api،
        // ولم يكن هذا الاسم مُعرَّفًا إطلاقًا (لم يُختَبر أي مسار API قبل الآن).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }

}
`````

### `app/Providers/Filament/AdminPanelProvider.php`

`sha256: 3b00d9d2b9d276fb31ac55dc2bade415b44fa6901cfd79c91880fc912babbdc7`

`````php
<?php

namespace App\Providers\Filament;
use App\Http\Middleware\SecurityHeaders;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // E22: ترتيب منطقي لمجموعات الإدارة (محتوى ← منافسات وفرق ← اقتصاد ← تفاعل ← إشراف وأمان ← تحليلات ونظام ← وصول). نصوص وترتيب فقط، بلا منطق ولا صلاحيات.
            ->navigationGroups(['الأحجيات', 'الحملات', 'المنافسات', 'الفرق', 'المتجر', 'الاقتصاد', 'الجواهر والاستبدال', 'التقدُّم', 'المشاركة', 'الإعلانات والرعايات', 'الرعاية والدعم', 'الإشراف', 'الأمان', 'التحليلات والنظام', 'إدارة الوصول'])
            ->login()
            ->brandName('أحجيات')
            // نفس تدرّج ألوان gem-tone المستخدم بباقي الموقع (app.css)
            ->colors([
                'primary' => Color::hex('#8b5cf6'), // amethyst
                'success' => Color::hex('#34d399'), // emerald
                'warning' => Color::hex('#fcd34d'), // gold
                'danger' => Color::hex('#fb7185'),  // rose
            ])
            // ثيم غامق دائم يطابق هوية الموقع، بدون خيار التبديل للفاتح
            ->darkMode(isForced: true)
            ->font('Cairo')
            // إصلاح علّة معروفة بـ Filament v3: القائمة الجانبية تُهيّأ "مفتوحة" افتراضياً
            // حتى على الموبايل (github.com/filamentphp/filament/issues/15056). نجبرها
            // تنغلق بكل تحميل صفحة على شاشة ضيقة - نفس سلوك أي قائمة موبايل منسدلة
            // طبيعية (ما يفترض تفضل مفتوحة بين تنقلات الصفحات أصلاً).
            // كمان نضيف زر إغلاق واضح جوا القائمة على الموبايل، لأن Filament
            // افتراضياً يعتمد فقط على "اضغط برّا القائمة لتسكرها" بدون زر صريح.
            //
            // Hotfix (2026-10-09): السبب الحقيقي لظهور الزر على Desktop لم يكن
            // تقصير Tailwind بتوليد lg:hidden (الكلاس هذا مُولَّد فعلاً - مستخدَم
            // بملفين blade آخرين بالمشروع) بل أن btn.style.cssText أدناه كان يضيف
            // display:flex كـ inline style، وinline style دائماً يتغلّب على أي
            // قاعدة class-based (بما فيها lg:hidden جوا @media) بصرف النظر عن
            // عرض الشاشة. الإصلاح: (أ) الأسلوب المفضَّل بالطلب - ما عاد الزر
            // يُنشأ أصلاً إلا لو matchMedia يطابق الموبايل فعلاً، مع الاستماع
            // لـresize لإضافته/حذفه ديناميكياً عند تغيّر حجم النافذة. (ب) خط دفاع
            // احتياطي - كل الـstyle انتقل لقاعدة CSS حقيقية (مش inline) مع
            // @media (min-width:1024px){ display:none !important } صريحة، فحتى
            // لو صار أي استثناء بمنطق JS تبقى القاعدة هذي الفيصل. تمت أيضاً
            // إزالة حرف ✕ الخام واستبداله بـSVG صغير (Heroicon x-mark) متناسق
            // مع Filament.
            ->renderHook(
                'panels::head.start',
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <style>
                        .ahjiyat-sidebar-close-btn {
                            position: absolute;
                            top: 1rem;
                            inset-inline-end: 1rem;
                            z-index: 40;
                            width: 2.25rem;
                            height: 2.25rem;
                            border-radius: 9999px;
                            background: rgba(255, 255, 255, .08);
                            color: #fff;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            border: 1px solid rgba(255, 255, 255, .15);
                            cursor: pointer;
                        }
                        .ahjiyat-sidebar-close-btn svg {
                            width: 1.1rem;
                            height: 1.1rem;
                        }
                        /* خط دفاع احتياطي: حتى لو تغيّر منطق الـJS مستقبلاً وصار
                           الزر يُنشأ بالخطأ على Desktop، هاي القاعدة تضمن إخفاءه. */
                        @media (min-width: 1024px) {
                            .ahjiyat-sidebar-close-btn {
                                display: none !important;
                            }
                        }
                    </style>
                    <script>
                        if (window.innerWidth < 1024) {
                            localStorage.setItem('isOpen', 'false');
                        }

                        document.addEventListener('DOMContentLoaded', function () {
                            var MOBILE_QUERY = '(max-width: 1023px)';

                            function removeSidebarCloseButton() {
                                var existing = document.querySelector('.ahjiyat-sidebar-close-btn');
                                if (existing) existing.remove();
                            }

                            function createSidebarCloseButton() {
                                var sidebar = document.querySelector('.fi-sidebar');
                                if (!sidebar || sidebar.querySelector('.ahjiyat-sidebar-close-btn')) return;

                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'ahjiyat-sidebar-close-btn';
                                btn.setAttribute('aria-label', 'إغلاق القائمة');
                                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 5.22a.75.75 0 0 1 1.06 0L10 8.94l3.72-3.72a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 0 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';
                                btn.addEventListener('click', function () {
                                    if (window.Alpine && window.Alpine.store('sidebar')) {
                                        window.Alpine.store('sidebar').close();
                                    }
                                });

                                sidebar.prepend(btn);
                            }

                            // الأسلوب المفضَّل (A): لا يُنشأ العنصر إطلاقاً إلا لو
                            // العرض فعلاً موبايل/تابلت - مش بس نخفيه بالـCSS بعد إنشائه.
                            function syncSidebarCloseButton() {
                                if (window.matchMedia(MOBILE_QUERY).matches) {
                                    createSidebarCloseButton();
                                } else {
                                    removeSidebarCloseButton();
                                }
                            }

                            syncSidebarCloseButton();
                            document.addEventListener('livewire:navigated', syncSidebarCloseButton);
                            window.addEventListener('resize', syncSidebarCloseButton);
                        });
                    </script>
                    HTML),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
           ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // E8: لوحة Filament لا ترث مجموعة web العامة تلقائياً - لها
                // Middleware Stack خاص بها معرَّف صراحة هنا فقط. اكتُشف
                // اختباريًا (SecurityHeadersTest) أن /admin كانت بلا Headers
                // أمان إطلاقاً رغم تسجيلها على مجموعة web بـbootstrap/app.php.
                SecurityHeaders::class,
            ])
            
            ->authMiddleware([Authenticate::class]);
    }
}
`````

### `app/Services/Competitive/Rewards/CompetitiveRewardDistributionService.php`

`sha256: b383aa1d4e29fc53915b39071118a1c302ea72681ea926e8f1b22bf1361bfe2c`

`````php
<?php

namespace App\Services\Competitive\Rewards;

use App\Events\CompetitiveRewardGranted;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Competitive\CompetitiveException;
use App\Services\Economy\CurrencyWalletService;
use App\Services\OperationalAuditService;
use App\Services\Progression\XpService;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * توزيع جوائز الأحداث التنافسية (E18-B). المصدر الوحيد: حدث **معتمَد نهائيًا** + مراكزه النهائية (final_rank، تُكتب مرة واحدة بالمنفِّذ ولا تتغير). الجائزة لا
 * تحدد الفائز ولا تغيّر نقاطًا أو مراكز: تقرأ فقط. لا شيء من العميل: لا rank ولا reward ولا amount.
 *
 * - مؤهَّل = نتيجة **صحيحة** (is_correct) بمركز نهائي. قاعدة مركز تحوي مركزه، وإلا قاعدة المشاركة إن وُجدت. جائزة واحدة لكل لاعب (UNIQUE بالقاعدة).
 * - لا منطق اقتصادي هنا: العملة بـCurrencyWalletService::creditPending (معلّقة بفترة الحجز المعتادة، بمفتاح دلالي)، XP بـXpService::grantXp (بمفتاح دلالي)،
 *   العنصر بـInventoryService::grant، الامتياز بـEntitlementService::grant. لا increment/decrement ولا تعديل رصيد مباشر. المرجع = الحدث (وسم المصدر).
 * - الذرية: ادّعاء الصف بـUPDATE شرطي (status='pending') داخل معاملة واحدة مع التسليم: عاملان على الصف نفسه ← واحد فقط ينجح ولو بقراءة قديمة؛
 *   وفشل التسليم يتراجع بالكامل (فلا نصف منحة) ثم يُسجَّل failed بدل أن يُسقط الحدث. العنصر/الامتياز غير Idempotent بنفسيهما، فحمايتهما هذه المعاملة.
 * - الدفعات: لا تحميل لكل المشاركين؛ كل دفعة ≤ chunk_size ومؤشرها معرّف النتيجة. لا معاملة ضخمة واحدة.
 * - إعادة المحاولة صريحة (الفاشل فقط، بسلطة وحماية معدّل)؛ الممنوح لا يُعاد منحه أبدًا.
 */
class CompetitiveRewardDistributionService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected XpService $xp,
        protected InventoryService $inventory,
        protected EntitlementService $entitlements,
        protected OperationalAuditService $audit,
    ) {}

    public function chunkSize(): int
    {
        return max(1, (int) config('competitive.rewards.chunk_size', 100));
    }

    /** معتمَد نهائيًا فقط: الملغاة والجارية وبلا اعتماد لا توزَّع. */
    public function isDistributable(CompetitiveEvent $event): bool
    {
        return $event->status === CompetitiveEvent::STATUS_COMPLETED && $event->finalized_at !== null;
    }

    /** @return Collection<int, CompetitiveRewardRule> */
    public function activeRules(CompetitiveEvent $event): Collection
    {
        return CompetitiveRewardRule::query()->where('competitive_event_id', $event->getKey())->where('is_active', true)
            ->with(['currency', 'storeItem'])->orderBy('sort_order')->orderBy('min_rank')->get();
    }

    /** القاعدة المنطبقة على نتيجة: مركز أولًا ثم المشاركة. نتيجة غير صحيحة أو بلا مركز نهائي: لا قاعدة. */
    public function ruleFor(Collection $rules, CompetitiveEventResult $result): ?CompetitiveRewardRule
    {
        if (! $result->is_correct || $result->final_rank === null) {
            return null;
        }

        return $rules->first(fn (CompetitiveRewardRule $r) => $r->appliesTo($result->final_rank))
            ?? $rules->firstWhere('kind', CompetitiveRewardRule::KIND_PARTICIPATION);
    }

    /**
     * يعالج دفعة واحدة من النتائج الصحيحة بعد المؤشر.
     *
     * @return array{count: int, last_id: int, done: bool, granted: int, failed: int}
     */
    public function distributeChunk(CompetitiveEvent $event, int $afterResultId = 0, ?int $size = null): array
    {
        $size ??= $this->chunkSize();
        $stats = ['count' => 0, 'last_id' => $afterResultId, 'done' => true, 'granted' => 0, 'failed' => 0];

        if (! $this->isDistributable($event) || ($rules = $this->activeRules($event))->isEmpty()) {
            return $stats;
        }

        $results = CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())
            ->where('is_correct', true)->whereNotNull('final_rank')->where('id', '>', $afterResultId)->orderBy('id')->limit($size)->get();

        foreach ($results as $result) {
            if (($rule = $this->ruleFor($rules, $result)) === null) {
                continue;
            }

            $grant = $this->plan($event, $result, $rule);

            if ($grant->status === CompetitiveRewardGrant::STATUS_PENDING) {
                match ($this->process($grant)) {
                    'granted' => $stats['granted']++,
                    'failed' => $stats['failed']++,
                    default => null,
                };
            }
        }

        $stats['count'] = $results->count();
        $stats['last_id'] = (int) ($results->last()?->getKey() ?? $afterResultId);
        $stats['done'] = $results->count() < $size;

        return $stats;
    }

    /** يخطّط الصف (pending) بلقطة الجائزة؛ insertOrIgnore على UNIQUE(event, user): لا ازدواج ولا تأثير لتكرار التشغيل. */
    public function plan(CompetitiveEvent $event, CompetitiveEventResult $result, CompetitiveRewardRule $rule): CompetitiveRewardGrant
    {
        DB::table('competitive_reward_grants')->insertOrIgnore([[
            'competitive_event_id' => $event->getKey(),
            'user_id' => $result->user_id,
            'competitive_event_result_id' => $result->getKey(),
            'competitive_reward_rule_id' => $rule->getKey(),
            'status' => CompetitiveRewardGrant::STATUS_PENDING,
            'final_rank' => $result->final_rank,
            'reward_type' => $rule->reward_type,
            'currency_id' => $rule->currency_id,
            'store_item_id' => $rule->store_item_id,
            'amount' => $rule->amount,
            // E30: لقطة تعزيز الرعاية من الخطة المقفلة قبل بدء المنافسة (الأساس `amount` لا يُمسّ). بلا خطة مقفلة = أصفار.
            ...app(\App\Services\Sponsorship\SponsorshipRewardService::class)->competitiveSnapshot($event, $rule),
            'reward_label' => Str::limit($rule->rewardLabel(), 150, ''),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        return CompetitiveRewardGrant::query()->where('competitive_event_id', $event->getKey())->where('user_id', $result->user_id)->firstOrFail();
    }

    /** @return 'granted'|'skipped'|'failed' */
    public function process(CompetitiveRewardGrant $grant): string
    {
        try {
            $claimed = DB::transaction(function () use ($grant) {
                // ادّعاء ذري: الوحيد الذي ينتقل pending → granted ينفّذ التسليم (عامل آخر أو صف ممنوح: affected = 0).
                $affected = CompetitiveRewardGrant::query()->whereKey($grant->getKey())->where('status', CompetitiveRewardGrant::STATUS_PENDING)
                    ->update(['status' => CompetitiveRewardGrant::STATUS_GRANTED, 'granted_at' => now(), 'failure_reason' => null, 'attempts' => DB::raw('attempts + 1')]);

                if ($affected !== 1) {
                    return false;
                }

                $this->deliver($grant->fresh());

                return true;
            });
        } catch (\Throwable $e) {
            // تراجعت المعاملة (لا نصف منحة): نسجّل الفشل خارجها بسبب مقتضب، فيبقى الحدث وبقية اللاعبين سليمين وقابلين لإعادة المحاولة.
            CompetitiveRewardGrant::query()->whereKey($grant->getKey())->where('status', CompetitiveRewardGrant::STATUS_PENDING)
                ->update(['status' => CompetitiveRewardGrant::STATUS_FAILED, 'failure_reason' => Str::limit($e->getMessage(), 240, ''), 'attempts' => DB::raw('attempts + 1')]);
            report($e);

            return 'failed';
        }

        if (! $claimed) {
            return 'skipped';
        }

        try {
            event(new CompetitiveRewardGranted($grant->getKey())); // بعد اكتمال المنح فقط؛ فشل الإشعار لا يمسّ الجائزة
        } catch (\Throwable $e) {
            report($e);
        }

        return 'granted';
    }

    /** التسليم عبر الخدمات الرسمية فقط. أي نقص بالتعريف يرمي استثناء (لا نجاح كاذب). */
    protected function deliver(CompetitiveRewardGrant $grant): void
    {
        $event = CompetitiveEvent::query()->findOrFail($grant->competitive_event_id);
        $user = User::query()->findOrFail($grant->user_id);
        $key = "competitive-reward:{$grant->competitive_event_id}:{$grant->user_id}";
        $reason = 'competitive_event_reward';

        match ($grant->reward_type) {
            CompetitiveRewardRule::TYPE_CURRENCY => $this->deliverCurrency($grant, $user, $event, $reason, $key),
            CompetitiveRewardRule::TYPE_XP => $this->xp->grantXp($user, $grant->amount, XpTransaction::TYPE_COMPETITIVE_REWARD, $reason, $event, $key),
            CompetitiveRewardRule::TYPE_STORE_ITEM => $this->deliverItem($user, StoreItem::query()->findOrFail($grant->store_item_id), $grant->amount, $reason, $event),
            default => throw new \RuntimeException('نوع جائزة غير مدعوم.'),
        };
    }

    /** الأساس أولًا بمنطقه القائم، ثم مكافأة الرعاية المحفوظة بلقطة الجائزة (معاملة منفصلة معزولة الفشل عبر SponsorshipRewardService). */
    protected function deliverCurrency(CompetitiveRewardGrant $grant, User $user, CompetitiveEvent $event, string $reason, string $key): void
    {
        $currency = Currency::query()->findOrFail($grant->currency_id);
        $this->wallets->creditPending($user, $currency, $grant->amount, $reason, $event, $key);

        if ((int) $grant->sponsor_bonus_amount > 0) {
            app(\App\Services\Sponsorship\SponsorshipRewardService::class)->grantCompetitiveBonus($grant, $user, $currency);
        }
    }

    protected function deliverItem(User $user, StoreItem $item, int $quantity, string $reason, CompetitiveEvent $event): void
    {
        match ($item->fulfillment_type) {
            StoreItem::FULFILLMENT_INVENTORY => $this->inventory->grant($user, $item, $quantity, $reason, $event),
            StoreItem::FULFILLMENT_ENTITLEMENT => $this->entitlements->grant($user, $item),
            default => throw new \RuntimeException('لا يمكن منح عنصر يدوي التسليم كجائزة تلقائية.'),
        };
    }

    /** @return array{eligible: int, pending: int, granted: int, failed: int} */
    public function counts(CompetitiveEvent $event): array
    {
        $by = CompetitiveRewardGrant::query()->where('competitive_event_id', $event->getKey())->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'eligible' => $this->eligibleCount($event),
            'pending' => (int) ($by[CompetitiveRewardGrant::STATUS_PENDING] ?? 0),
            'granted' => (int) ($by[CompetitiveRewardGrant::STATUS_GRANTED] ?? 0),
            'failed' => (int) ($by[CompetitiveRewardGrant::STATUS_FAILED] ?? 0),
        ];
    }

    /** عدد اللاعبين المؤهَّلين حسب القواعد الفعّالة (نطاقات المراكز المنفصلة + المشاركة لمن خارجها). لا توزيع هنا. */
    public function eligibleCount(CompetitiveEvent $event): int
    {
        $rules = $this->activeRules($event);

        if ($rules->isEmpty() || $event->status === CompetitiveEvent::STATUS_CANCELLED) {
            return 0;
        }

        $correct = fn () => CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())->where('is_correct', true)->whereNotNull('final_rank');
        $ranked = $rules->where('kind', CompetitiveRewardRule::KIND_RANK)->sum(fn ($r) => $correct()->whereBetween('final_rank', [$r->min_rank, $r->max_rank])->count());

        return $ranked + ($rules->contains('kind', CompetitiveRewardRule::KIND_PARTICIPATION) ? $correct()->count() - $ranked : 0);
    }

    /**
     * إعادة محاولة الفاشل فقط (إجراء صريح بتفويض وحماية معدّل). الممنوح لا يُمسّ. تدقيق واحد للعملية (لا تكرار لقيود الدفتر).
     *
     * @return array{requested: int, granted: int, failed: int}
     */
    public function retryFailed(CompetitiveEvent $event, User $actor): array
    {
        abort_unless($actor->can('retryRewards', $event), 403);

        $limiter = "competitive-rewards-retry:{$event->getKey()}";

        if (RateLimiter::tooManyAttempts($limiter, (int) config('competitive.rewards.retry_per_10_minutes', 5))) {
            throw new CompetitiveException('محاولات إعادة كثيرة. حاول بعد قليل.');
        }

        RateLimiter::hit($limiter, 600);

        $stats = ['requested' => 0, 'granted' => 0, 'failed' => 0];

        CompetitiveRewardGrant::query()->where('competitive_event_id', $event->getKey())->where('status', CompetitiveRewardGrant::STATUS_FAILED)
            ->chunkById($this->chunkSize(), function ($grants) use (&$stats) {
                foreach ($grants as $grant) {
                    $reset = CompetitiveRewardGrant::query()->whereKey($grant->getKey())->where('status', CompetitiveRewardGrant::STATUS_FAILED)
                        ->update(['status' => CompetitiveRewardGrant::STATUS_PENDING]);

                    if ($reset !== 1) {
                        continue;
                    }

                    $stats['requested']++;

                    match ($this->process($grant->fresh())) {
                        'granted' => $stats['granted']++,
                        'failed' => $stats['failed']++,
                        default => null,
                    };
                }
            });

        $this->audit->log('competitive_rewards_retry', $event, $stats, $actor);

        return $stats;
    }
}
`````

### `app/Services/Discovery/DiscoveryService.php`

`sha256: b402962438c67722c15b6cf56496c412687fbbeae231025d08a7ccdfff0fba36`

`````php
<?php

namespace App\Services\Discovery;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesDiscovery;
use App\Services\Creators\CommunityDiscovery;
use Illuminate\Support\Collection;

/**
 * E27-B: مركز الاكتشاف. يجمع أقسامًا **محدودة العدد** (لا ترقيم ولا قوائم مفتوحة) من الأنظمة القائمة بنفس شروط ظهورها العامة، ويضيف للمسجَّل «تابع» و«مقترح لك».
 * عدد الاستعلامات لا يتبع حجم البيانات (كل قسم استعلام محدود بـLIMIT). كل الأقسام عرض فقط: لا مكافآت ولا نقاط ولا كتابة.
 *
 * @phpstan-type Section array{key: string, title: string, icon: string, href: ?string, linkLabel: ?string, cards: list<DiscoverCard>}
 */
class DiscoveryService
{
    public function __construct(protected TrendingService $trending, protected RecommendationService $recommendations, protected CommunityDiscovery $community, protected CreatorSeriesDiscovery $series) {}

    /**
     * @return array{sections: list<array>, categories: Collection<int, PuzzleCategory>, personalized: bool}
     */
    public function hub(?User $viewer): array
    {
        $limit = max(1, (int) config('search.discover.section_limit', 8));
        $sections = [];
        $reco = $viewer !== null ? $this->recommendations->forUser($viewer) : null;

        if ($reco !== null) {
            $this->add($sections, 'continue', 'تابع من حيث توقفت', 'bolt', null, null, array_merge(
                $reco['continue_puzzles']->map(fn (Puzzle $p) => $this->puzzleCard($p, 'محاولاتك لم تنتهِ'))->all(),
                $reco['continue_campaigns']->map(fn (Campaign $c) => $this->campaignCard($c, 'تقدّمك محفوظ'))->all(),
            ));
            $this->add($sections, 'for-you', 'مقترحة لك', 'sparkles', route('puzzles.index'), 'كل الأحجيات',
                array_map(fn (array $r) => $this->puzzleCard($r['puzzle'], $r['reason']), $reco['puzzles']));
        }

        $this->add($sections, 'trending', 'الرائج هذا الأسبوع', 'bolt', route('puzzles.index'), 'كل الأحجيات',
            $this->trending->puzzles($limit)->map(fn (Puzzle $p) => $this->puzzleCard($p, $p->trending_players.' لاعب هذا الأسبوع'))->all());

        $daily = Puzzle::query()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
            ->where('is_daily_puzzle', true)->whereDate('daily_puzzle_date', today())->where('is_active', true)->limit(1)->get();
        $this->add($sections, 'daily', 'أحجية اليوم', 'calendar', null, null, $daily->map(fn (Puzzle $p) => $this->puzzleCard($p, 'تتجدّد يوميًا'))->all());

        $this->add($sections, 'new', 'جديد الأحجيات', 'puzzle', route('puzzles.index'), 'كل الأحجيات',
            Puzzle::query()->select('puzzles.id', 'puzzles.title', 'puzzles.difficulty', 'puzzles.puzzle_category_id')->with('category:id,name')
                ->where('puzzles.is_active', true)->orderByDesc('puzzles.id')->limit($limit)->get()
                ->map(fn (Puzzle $p) => $this->puzzleCard($p, null))->all());

        // E28: أحجيات المجتمع بأقسام مستقلة موسومة «من المجتمع» (لا تمتزج بالرسمي ولا تؤثر برائجه/توصياته). قسم فارغ لا يظهر.
        $community = $this->community->sections($viewer);
        $this->add($sections, 'community-trending', 'رائج من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['trending']);
        $this->add($sections, 'community-following', 'جديد من تتابعهم', 'friends', route('following.index'), 'كل ما ينشره من أتابعهم', $community['following']);
        $this->add($sections, 'community-top-rated', 'الأعلى تقييمًا من المجتمع', 'trophy', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['top_rated']);
        $this->add($sections, 'community-new', 'جديد من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['new']);
        $this->add($sections, 'community-for-you', 'مقترحة لك من المجتمع', 'sparkles', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['for_you']);

        // E30: سلاسل المجتمع (وسم «سلسلة من المجتمع»): قسمان مستقلان؛ CreatorSeriesDiscovery تعزل فشلها (فلا يكسر مركز الاكتشاف)، وقسم فارغ لا يظهر.
        $seriesSections = $this->series->sections($viewer);

        $this->add($sections, 'community-series-following', 'سلاسل جديدة ممّن تتابعهم', 'grid', route('following.index'), 'كل ما ينشره من أتابعهم', $seriesSections['following']);
        $this->add($sections, 'community-series', 'سلاسل من المجتمع', 'grid', null, null, $seriesSections['new']);

        $seasons = Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.id', 'seasons.slug', 'seasons.is_featured', 'campaigns.title as campaign_title')
            ->where('seasons.is_published', true)->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')
            ->limit((int) config('search.discover.season_limit', 4))->get();
        $this->add($sections, 'seasons', 'المواسم', 'calendar', route('seasons.index'), 'كل المواسم',
            $seasons->map(fn (Season $s) => new DiscoverCard((string) $s->getAttribute('campaign_title'), route('seasons.show', $s), 'calendar', $s->is_featured ? 'موسم مميّز' : null))->all());

        $this->add($sections, 'campaigns', 'حملات متاحة', 'flag', route('campaigns.index'), 'كل الحملات',
            $this->recommendations->listableCampaigns()->orderByDesc('campaigns.id')->limit((int) config('search.discover.campaign_limit', 6))->get()
                ->map(fn (Campaign $c) => $this->campaignCard($c, null))->all());

        $events = $reco !== null && $reco['competitions']->isNotEmpty() ? $reco['competitions'] : $this->trending->competitions((int) config('search.discover.event_limit', 6));
        $this->add($sections, 'competitions', $reco !== null ? 'منافسات لم تسجّل بها' : 'منافسات رائجة', 'trophy', route('competitions.index'), 'كل المنافسات',
            $events->map(fn (CompetitiveEvent $e) => $this->eventCard($e))->all());

        $this->add($sections, 'championships', 'بطولات الفرق', 'flag', route('team-championships.index'), 'كل البطولات',
            TeamChampionship::query()->select('team_championships.id', 'team_championships.slug', 'team_championships.title', 'team_championships.status', 'team_championships.starts_at', 'team_championships.ends_at')
                ->public()->where('team_championships.ends_at', '>', now())->orderBy('team_championships.starts_at')->orderByDesc('team_championships.id')->limit(4)->get()
                ->map(fn (TeamChampionship $c) => new DiscoverCard($c->title, route('team-championships.show', $c), 'flag', $c->starts_at?->format('Y-m-d'), $c->phase() === TeamChampionship::PHASE_LIVE ? 'مباشرة' : 'قريبًا', $c->phase() === TeamChampionship::PHASE_LIVE ? 'success' : 'warning'))->all());

        $teams = $reco !== null && $reco['teams']->isNotEmpty() ? $reco['teams'] : $this->trending->teams((int) config('search.discover.team_limit', 6));
        $this->add($sections, 'teams', $reco !== null && $reco['teams']->isNotEmpty() ? 'فرق تنضم إليها' : 'فرق صاعدة', 'team', route('teams.index'), 'كل الفرق',
            $teams->map(fn (Team $t) => new DiscoverCard($t->name, route('teams.show', $t), 'team', number_format((int) $t->members_count).' عضو'))->all());

        if ($reco !== null && $reco['campaigns']->isNotEmpty()) {
            $this->add($sections, 'new-campaigns', 'حملات لم تبدأها', 'flag', route('campaigns.index'), 'كل الحملات',
                $reco['campaigns']->map(fn (Campaign $c) => $this->campaignCard($c, null))->all());
        }

        $categories = PuzzleCategory::query()->select('id', 'name', 'slug')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(12)->get();

        return ['sections' => $sections, 'categories' => $categories, 'personalized' => $reco !== null];
    }

    /** @param list<array> $sections @param list<DiscoverCard> $cards */
    protected function add(array &$sections, string $key, string $title, string $icon, ?string $href, ?string $linkLabel, array $cards): void
    {
        if ($cards === []) {
            return;
        }

        $sections[] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'href' => $href, 'linkLabel' => $linkLabel, 'cards' => array_values($cards)];
    }

    protected function puzzleCard(Puzzle $puzzle, ?string $reason): DiscoverCard
    {
        $difficulty = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? null;
        $tone = ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$puzzle->difficulty] ?? 'neutral';

        return new DiscoverCard($puzzle->title, route('puzzles.show', $puzzle), 'puzzle', $puzzle->category?->name, $difficulty, $tone, $reason);
    }

    protected function campaignCard(Campaign $campaign, ?string $reason): DiscoverCard
    {
        return new DiscoverCard($campaign->title, route('campaigns.show', $campaign), 'flag', $campaign->ends_at ? 'تنتهي '.$campaign->ends_at->format('Y-m-d') : null, null, 'neutral', $reason);
    }

    protected function eventCard(CompetitiveEvent $event): DiscoverCard
    {
        $phase = $event->phase();

        return new DiscoverCard($event->title, route('competitions.show', $event), 'trophy', 'المشاركون: '.$event->participants_count,
            $phase === CompetitiveEvent::PHASE_LIVE ? 'مباشرة الآن' : ($phase === CompetitiveEvent::PHASE_UPCOMING ? 'قادمة' : 'منتهية'),
            $phase === CompetitiveEvent::PHASE_LIVE ? 'success' : ($phase === CompetitiveEvent::PHASE_UPCOMING ? 'warning' : 'neutral'));
    }
}
`````

### `app/Services/Notifications/NotificationType.php`

`sha256: 51f2f79b89a30150a153f7b52a4cc1ca9e70f2a8dd76cd970dea80c15ba88e77`

`````php
<?php

namespace App\Services\Notifications;

/**
 * سجل مغلق لأنواع الإشعارات المنفَّذة فعليًا (4). كل نوع يحدد فئته وأولويته وأيقونته ووجهته الداخلية
 * الوحيدة المسموحة ونصوصه - لا يأتي أي منها من الحدث أو من الإدارة أو من قاعدة البيانات.
 *
 * مصدر كل نوع (Event source → type):
 *  - AchievementUnlocked (حدث مجال جديد، يُطلَق بعد commit فتح الإنجاز) → achievement_unlocked
 *  - جدولة ساعية (ReEngagementService) على player_streaks → streak_at_risk
 *  - جدولة ساعية على player_streaks + مهام يومية قائمة → daily_quests_available
 *  - Illuminate\Auth\Events\PasswordReset (حدث موجود أصلًا) → security_password_reset
 *  - SeasonStarted (حدث مجال، SeasonLifecycleService: الموسم صار مباشرًا لأول مرة) → season_started
 *  - CampaignBecameAvailable (حدث مجال، CampaignLifecycleService: الحملة أُتيحت لأول مرة) → campaign_available
 *  - StageUnlockedForUser (حدث مجال لكل مستخدم، StageUnlockService بعد إكمال خطوة حقيقية) → stage_unlocked
 *  - CampaignCompletedForUser (حدث مجال لكل مستخدم، CampaignCompletionService بعد إكمال خطوة حقيقية) → campaign_completed،
 *    أو season_completed إن كانت الحملة مرتبطة بموسم منشور (القرار داخل المستمع: إشعار واحد فقط للإكمال نفسه)
 *  - LifecycleReminderService (مجدول ساعيًا، قراءة فقط) → season_ending_soon / campaign_ending_soon
 *  - FriendChallenge{Created,Accepted,Completed} (أحداث مجال بعد commit، FriendChallengeService) → friend_challenge_received/accepted/result_ready
 *  - CompetitiveRewardGranted (بعد منح فعلي ناجح بخدمة التوزيع) → competitive_reward_granted
 *  - TeamChallengeCreated/Accepted/Completed, TeamChampionshipStarted (الأمر الدوري)/Finalized (E20) → team_challenge_*, team_championship_* (التحدّيات: social؛ البطولات: competitive)
 *  - TeamInvitationCreated/Accepted, TeamJoinRequestCreated/Accepted, TeamMemberRemoved (أحداث مجال بعد commit بخدمات الفرق، E19) → team_* (فئة social؛ بلا إشعار للرفض)
 *  - CompetitiveLifecycleService (مجدول ساعيًا) → competitive_event_started/ending_soon؛ CompetitiveEventFinalized → competitive_event_result_ready
 *  - FriendRequestCreated / FriendshipAccepted (أحداث مجال بعد commit، FriendshipService) → friend_request_received / friend_request_accepted
 *  - اعتماد نشر (أول نشر أو نسخة جديدة) لمبدع له متابعون (E29، CreatorPublicationAnnouncer بعد الـcommit ← دفعات بالطابور) → creator_new_publication (فئة social؛ بلا إشعار على التقييم/المفضلة/اللعب)
 *  - E30 رعاية: ContributionService بعد الـcommit → support_confirmed / supporter_tier_changed (للداعم المرتبط بحساب فقط، بلا مبالغ ولا مراجع خاصة)؛ SponsorshipProgramService عند التفعيل → sponsorship_program_started (لداعمي البرنامج فقط)
 *  - E30 سلاسل: CreatorSeriesReviewService بعد الـcommit → series_approved / series_changes_requested / series_rejected / series_hidden (للمبدع)؛ اعتماد سلسلة لمبدع له متابعون (CreatorSeriesAnnouncer ← دفعات بالطابور) → creator_new_series (social)
 */
enum NotificationType: string
{
    case AchievementUnlocked = 'achievement_unlocked';
    case StreakAtRisk = 'streak_at_risk';
    case DailyQuestsAvailable = 'daily_quests_available';
    case SecurityPasswordReset = 'security_password_reset';
    case SeasonStarted = 'season_started';
    case CampaignAvailable = 'campaign_available';
    case StageUnlocked = 'stage_unlocked';
    case CampaignCompleted = 'campaign_completed';
    case SeasonCompleted = 'season_completed';
    case SeasonEndingSoon = 'season_ending_soon';
    case CampaignEndingSoon = 'campaign_ending_soon';
    case FriendRequestReceived = 'friend_request_received';
    case FriendRequestAccepted = 'friend_request_accepted';
    case FriendChallengeReceived = 'friend_challenge_received';
    case FriendChallengeAccepted = 'friend_challenge_accepted';
    case FriendChallengeResultReady = 'friend_challenge_result_ready';
    case CompetitiveEventStarted = 'competitive_event_started';
    case CompetitiveEventEndingSoon = 'competitive_event_ending_soon';
    case CompetitiveEventResultReady = 'competitive_event_result_ready';
    case CompetitiveRewardGranted = 'competitive_reward_granted';
    case TeamInvitationReceived = 'team_invitation_received';
    case TeamInvitationAccepted = 'team_invitation_accepted';
    case TeamJoinRequestReceived = 'team_join_request_received';
    case TeamJoinRequestAccepted = 'team_join_request_accepted';
    case TeamMemberRemoved = 'team_member_removed';
    case TeamChallengeReceived = 'team_challenge_received';
    case TeamChallengeAccepted = 'team_challenge_accepted';
    case TeamChallengeResultReady = 'team_challenge_result_ready';
    case TeamChampionshipStarted = 'team_championship_started';
    case TeamChampionshipResultReady = 'team_championship_result_ready';
    case CreatorApproved = 'creator_approved';
    case CreatorRejected = 'creator_rejected';
    case SubmissionApproved = 'submission_approved';
    case SubmissionChangesRequested = 'submission_changes_requested';
    case SubmissionRejected = 'submission_rejected';
    case PublicationHidden = 'publication_hidden';
    case CreatorNewPublication = 'creator_new_publication';
    case SupportConfirmed = 'support_confirmed';
    case SupporterTierChanged = 'supporter_tier_changed';
    case SponsorshipProgramStarted = 'sponsorship_program_started';
    case SeriesApproved = 'series_approved';
    case SeriesChangesRequested = 'series_changes_requested';
    case SeriesRejected = 'series_rejected';
    case SeriesHidden = 'series_hidden';
    case CreatorNewSeries = 'creator_new_series';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::AchievementUnlocked => NotificationCategory::Achievement,
            self::StreakAtRisk => NotificationCategory::Streak,
            self::DailyQuestsAvailable => NotificationCategory::Quest,
            self::SecurityPasswordReset => NotificationCategory::Security,
            self::SeasonStarted => NotificationCategory::Season,
            self::CampaignAvailable => NotificationCategory::Campaign,
            self::StageUnlocked => NotificationCategory::Campaign,
            self::CampaignCompleted => NotificationCategory::Campaign,
            self::SeasonCompleted, self::SeasonEndingSoon => NotificationCategory::Season,
            self::CampaignEndingSoon => NotificationCategory::Campaign,
            self::FriendRequestReceived, self::FriendRequestAccepted, self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady,
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved,
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady,
            self::CreatorApproved, self::CreatorRejected, self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden, self::CreatorNewPublication,
            self::SupportConfirmed, self::SupporterTierChanged, self::SponsorshipProgramStarted,
            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden, self::CreatorNewSeries => NotificationCategory::Social,
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted,
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => NotificationCategory::Competitive,
        };
    }

    /** تذكير "عودة" (يخضع لميزانية اليوم) - مقابل المعاملاتي (إنجاز) والأمان (لا ميزانية). */
    public function isReEngagement(): bool
    {
        return in_array($this, [self::StreakAtRisk, self::DailyQuestsAvailable, self::SeasonEndingSoon, self::CampaignEndingSoon, self::CompetitiveEventEndingSoon], true);
    }

    /** أعلى = أهم. اختيار الأولوية يتم بتقسيم الجمهور + الميزانية (راجع ReEngagementService). */
    public function priority(): int
    {
        return match ($this) {
            self::SecurityPasswordReset => 1000,
            self::StreakAtRisk => 100,
            self::DailyQuestsAvailable => 50,
            self::SeasonStarted => 20,
            self::CampaignAvailable => 15,
            self::StageUnlocked => 14,
            self::CampaignCompleted => 16,
            self::SeasonCompleted => 17,
            self::SeasonEndingSoon, self::CampaignEndingSoon => 70, // بين تحذير السلسلة (100) وتذكير المهام (50)
            self::FriendRequestReceived => 25,
            self::FriendRequestAccepted => 24,
            self::FriendChallengeReceived => 26,
            self::FriendChallengeAccepted => 23,
            self::FriendChallengeResultReady => 22,
            self::CompetitiveEventStarted => 21,
            self::CompetitiveEventEndingSoon => 70, // مع تذكيري النهاية الآخرين: بين تحذير السلسلة وتذكير المهام
            self::CompetitiveEventResultReady => 20,
            self::CompetitiveRewardGranted => 19,
            self::TeamInvitationReceived => 28,
            self::TeamJoinRequestReceived => 29,
            self::TeamInvitationAccepted => 13,
            self::TeamJoinRequestAccepted => 12,
            self::TeamMemberRemoved => 11,
            self::TeamChallengeReceived => 30,
            self::TeamChallengeAccepted => 18,
            self::TeamChallengeResultReady => 9,
            self::TeamChampionshipStarted => 8,
            self::TeamChampionshipResultReady => 7,
            self::AchievementUnlocked => 10,
            self::CreatorApproved => 6,
            self::CreatorRejected => 5,
            self::SubmissionApproved => 4,
            self::SubmissionChangesRequested => 3,
            self::SubmissionRejected => 2,
            self::PublicationHidden => 1,
            self::CreatorNewPublication => 27,
            self::SupportConfirmed => 31,
            self::SupporterTierChanged => 32,
            self::SponsorshipProgramStarted => 33,
            self::SeriesApproved => 34,
            self::SeriesChangesRequested => 35,
            self::SeriesRejected => 36,
            self::SeriesHidden => 37,
            self::CreatorNewSeries => 38,
        };
    }

    /** أيقونة من سجل مغلق (رموز نصية) - لا أيقونة تأتي من قاعدة البيانات. */
    public function icon(): string
    {
        return match ($this) {
            self::AchievementUnlocked => '🏆',
            self::StreakAtRisk => '🔥',
            self::DailyQuestsAvailable => '🎯',
            self::SecurityPasswordReset => '🛡️',
            self::SeasonStarted => '🏁',
            self::CampaignAvailable => '🧭',
            self::StageUnlocked => '🔓',
            self::CampaignCompleted => '✅',
            self::SeasonCompleted => '🏅',
            self::SeasonEndingSoon, self::CampaignEndingSoon => '⏳',
            self::FriendRequestReceived, self::FriendRequestAccepted => '🤝',
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => '⚔️',
            self::CompetitiveEventStarted, self::CompetitiveEventResultReady => '🏆',
            self::CompetitiveRewardGranted => '🎁',
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved => '👥',
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => '⚔️',
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => '🏆',
            self::CompetitiveEventEndingSoon => '⏳',
            self::CreatorApproved => '🎨',
            self::CreatorRejected => '📝',
            self::SubmissionApproved => '✅',
            self::SubmissionChangesRequested => '✍️',
            self::SubmissionRejected => '📝',
            self::PublicationHidden => '🚫',
            self::CreatorNewPublication => '🆕',
            self::SupportConfirmed => '💎',
            self::SupporterTierChanged => '🏅',
            self::SponsorshipProgramStarted => '📣',
            self::SeriesApproved => '📚',
            self::SeriesChangesRequested => '✍️',
            self::SeriesRejected => '📝',
            self::SeriesHidden => '🚫',
            self::CreatorNewSeries => '📚',
        };
    }

    /** أسماء المسارات الداخلية الوحيدة المسموحة لهذا النوع (الأولى = الافتراضية). لا URL خارجي أبدًا. */
    public function allowedRoutes(): array
    {
        return match ($this) {
            self::AchievementUnlocked => ['progress.show'],
            self::StreakAtRisk => ['puzzles.index'],
            self::DailyQuestsAvailable => ['quests.show'],
            self::SecurityPasswordReset => ['profile.edit'],
            self::SeasonStarted => ['seasons.show'],
            self::CampaignAvailable => ['campaigns.show'],
            self::StageUnlocked => ['campaigns.show', 'seasons.show'],
            self::CampaignCompleted => ['campaigns.show', 'seasons.show'],
            self::SeasonCompleted, self::SeasonEndingSoon => ['seasons.show'],
            self::CampaignEndingSoon => ['campaigns.show'],
            self::FriendRequestReceived, self::FriendRequestAccepted => ['friends.index'],
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => ['friends.challenges.show'],
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted => ['competitions.show'],
            self::TeamInvitationReceived => ['teams.invitations'],
            self::TeamInvitationAccepted, self::TeamJoinRequestReceived => ['teams.manage', 'teams.show'],
            self::TeamJoinRequestAccepted => ['teams.show'],
            self::TeamMemberRemoved => ['teams.index'],
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => ['teams.challenges.show'],
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => ['team-championships.show'],
            self::CreatorApproved => ['creator.dashboard'],
            self::CreatorRejected => ['creator.apply'],
            self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden => ['creator.puzzles.index'],
            self::CreatorNewPublication => ['community.puzzles.show'],
            self::SupportConfirmed, self::SupporterTierChanged => ['supporters.index', 'supporters.show'],
            self::SponsorshipProgramStarted => ['supporters.index'],
            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden => ['creator.series.index'],
            self::CreatorNewSeries => ['series.show'],
        };
    }

    public function defaultRoute(): string
    {
        return $this->allowedRoutes()[0];
    }

    public function title(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'إنجاز جديد: '.($params['name'] ?? ''),
            self::StreakAtRisk => 'حافظ على سلسلتك اليومية',
            self::DailyQuestsAvailable => 'مهام اليوم جاهزة',
            self::SecurityPasswordReset => 'أُعيد تعيين كلمة مرور حسابك',
            self::SeasonStarted => 'بدأ موسم: '.($params['name'] ?? ''),
            self::CampaignAvailable => 'حملة جديدة متاحة: '.($params['name'] ?? ''),
            self::StageUnlocked => 'تم فتح مرحلة جديدة لك: '.($params['stage'] ?? ''),
            self::CampaignCompleted => 'أكملت الحملة بنجاح: '.($params['campaign'] ?? ''),
            self::SeasonCompleted => 'أكملت الموسم بنجاح: '.($params['season'] ?? ''),
            self::SeasonEndingSoon => 'ينتهي الموسم قريبًا: '.($params['name'] ?? ''),
            self::CampaignEndingSoon => 'تنتهي الحملة قريبًا: '.($params['name'] ?? ''),
            self::FriendRequestReceived => 'أرسل لك '.($params['name'] ?? '').' طلب صداقة',
            self::FriendRequestAccepted => 'قبل '.($params['name'] ?? '').' طلب صداقتك',
            self::FriendChallengeReceived => 'تحدّاك '.($params['name'] ?? '').' في أحجية',
            self::FriendChallengeAccepted => 'قبل '.($params['name'] ?? '').' تحدّيك',
            self::FriendChallengeResultReady => 'نتيجة التحدي جاهزة',
            self::CompetitiveEventStarted => 'بدأت المنافسة: '.($params['title'] ?? ''),
            self::CompetitiveEventEndingSoon => 'تنتهي المنافسة قريبًا: '.($params['title'] ?? ''),
            self::CompetitiveEventResultReady => 'نتائج المنافسة جاهزة: '.($params['title'] ?? ''),
            self::CompetitiveRewardGranted => 'حصلت على جائزة '.($params['placement'] ?? '').' في '.($params['title'] ?? ''),
            self::TeamInvitationReceived => 'دعاك '.($params['name'] ?? '').' للانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamInvitationAccepted => 'انضم '.($params['name'] ?? '').' إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestReceived => 'طلب '.($params['name'] ?? '').' الانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestAccepted => 'قُبل طلب انضمامك إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamMemberRemoved => 'لم تعد عضوًا في فريق «'.($params['team'] ?? '').'»',
            self::TeamChallengeReceived => 'تحدّاكم فريق «'.($params['team'] ?? '').'» بمباراة',
            self::TeamChallengeAccepted => 'قَبِل فريق «'.($params['team'] ?? '').'» تحدّيكم',
            self::TeamChallengeResultReady => 'نتيجة مباراتكم ضد «'.($params['team'] ?? '').'» جاهزة',
            self::TeamChampionshipStarted => 'بدأت بطولة الفرق: '.($params['title'] ?? ''),
            self::TeamChampionshipResultReady => 'اعتُمدت نتائج بطولة الفرق: '.($params['title'] ?? ''),
            self::CreatorApproved => 'تمت الموافقة على انضمامك كمبدع',
            self::CreatorRejected => 'لم يُقبل طلب انضمامك كمبدع',
            self::SubmissionApproved => 'اعتُمدت أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionChangesRequested => 'طُلبت تعديلات على أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionRejected => 'لم تُعتمد أحجيتك: '.($params['title'] ?? ''),
            self::PublicationHidden => 'أُخفيت أحجيتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' أحجيته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' أحجية جديدة: '.($params['title'] ?? ''),
            self::SupportConfirmed => 'تم تأكيد دعمك للمنصة، شكرًا لك',
            self::SupporterTierChanged => ! empty($params['up'])
                ? 'ارتقى مستوى دعمك إلى: '.($params['tier'] ?? '')
                : 'تغيّر مستوى دعمك'.(! empty($params['tier']) ? ' إلى: '.$params['tier'] : ''),
            self::SponsorshipProgramStarted => 'بدأ برنامج رعاية: '.($params['title'] ?? ''),
            self::SeriesApproved => 'اعتُمدت سلسلتك: '.($params['title'] ?? ''),
            self::SeriesChangesRequested => 'طُلبت تعديلات على سلسلتك: '.($params['title'] ?? ''),
            self::SeriesRejected => 'لم تُعتمد سلسلتك: '.($params['title'] ?? ''),
            self::SeriesHidden => 'أُخفيت سلسلتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewSeries => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' سلسلته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' سلسلة جديدة: '.($params['title'] ?? ''),
        };
    }

    public function body(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'فتحتَ إنجازًا جديدًا. تصفّح تقدّمك لترى بقية الإنجازات.',
            self::StreakAtRisk => 'سلسلتك الحالية '.(int) ($params['streak'] ?? 0).' يومًا. حلّ أحجية واحدة قبل نهاية اليوم لتستمر.',
            self::DailyQuestsAvailable => 'مهام اليوم متاحة الآن. حلّ أحجية وتقدّم نحو أهدافك اليومية.',
            self::SecurityPasswordReset => 'إن لم تكن أنت من أعاد تعيينها، تواصل مع الدعم فورًا وراجع أمان حسابك.',
            self::SeasonStarted => 'الموسم متاح الآن ويمكنك الانضمام واللعب.',
            self::CampaignAvailable => 'الحملة متاحة الآن ويمكنك البدء بها.',
            self::StageUnlocked => 'مرحلة «'.($params['stage'] ?? '').'» من حملة «'.($params['campaign'] ?? '').'» أصبحت متاحة لك الآن.',
            self::CampaignCompleted => 'أنهيت جميع مراحل حملة «'.($params['campaign'] ?? '').'».',
            self::SeasonCompleted => 'أنهيت جميع مراحل موسم «'.($params['season'] ?? '').'».',
            self::SeasonEndingSoon => 'ينتهي موسم «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكماله.',
            self::CampaignEndingSoon => 'تنتهي حملة «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكمالها.',
            self::FriendRequestReceived => 'يمكنك قبول الطلب أو رفضه من صفحة الأصدقاء.',
            self::FriendRequestAccepted => 'أصبحتما صديقين.',
            self::FriendChallengeReceived => 'أحجية «'.($params['puzzle'] ?? '').'». يمكنك قبول التحدي أو رفضه من صفحة التحديات.',
            self::FriendChallengeAccepted => 'تحدّي أحجية «'.($params['puzzle'] ?? '').'» صار نشطًا، ويمكنك اللعب الآن.',
            self::FriendChallengeResultReady => 'تحدّي «'.($params['puzzle'] ?? '').'» ضد '.($params['name'] ?? '').': '.($params['outcome'] ?? '').'.',
            self::CompetitiveEventStarted => 'المنافسة التي سجّلت بها بدأت الآن، ويمكنك اللعب حتى انتهائها.',
            self::CompetitiveEventEndingSoon => 'لم تُرسل نتيجتك بعد. تنتهي المنافسة خلال '.($params['hours'] ?? '').' ساعة أو أقل.',
            self::CompetitiveEventResultReady => 'ترتيبك النهائي: '.($params['rank'] ?? '').'.',
            self::CompetitiveRewardGranted => 'مُنحت لك: '.($params['reward'] ?? '').'.',
            self::TeamInvitationReceived => 'يمكنك قبول الدعوة أو تجاهلها من صفحة دعواتك.',
            self::TeamInvitationAccepted => 'قَبِل دعوتك وأصبح عضوًا بالفريق.',
            self::TeamJoinRequestReceived => 'راجع الطلب من صفحة إدارة الفريق.',
            self::TeamJoinRequestAccepted => 'أهلًا بك في الفريق.',
            self::TeamMemberRemoved => 'يمكنك الانضمام إلى فريق آخر أو إنشاء فريقك من صفحة الفرق.',
            self::TeamChallengeReceived => 'اختر روستر فريقكم واقبل التحدّي قبل انتهاء المهلة.',
            self::TeamChallengeAccepted => 'قُفل الروستران، ويمكن للاعبي الروستر اللعب الآن.',
            self::TeamChallengeResultReady => ($params['outcome'] ?? 'اعتُمدت النتيجة').'.',
            self::TeamChampionshipStarted => 'تُحتسب نتائج أحداثها المعتمَدة بنقاط الترتيب.',
            self::TeamChampionshipResultReady => 'اطّلع على الترتيب النهائي وبطل البطولة.',
            self::CreatorApproved => 'يمكنك الآن إنشاء أحجيات المجتمع وإرسالها للمراجعة من استوديو المبدعين.',
            self::CreatorRejected => static::withNote('يمكنك مراجعة الملاحظة وإعادة التقديم لاحقًا.', $params),
            self::SubmissionApproved => 'أصبحت أحجيتك منشورة ضمن «من المجتمع» ويمكن للاعبين لعبها.',
            self::SubmissionChangesRequested => static::withNote('عدّل الأحجية وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SubmissionRejected => static::withNote('يمكنك إنشاء نسخة جديدة من استوديو المبدعين.', $params),
            self::PublicationHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من الأحجية ضمن «من المجتمع». أحجيات المجتمع بلا جوائز اقتصادية.'
                : 'أحجية جديدة ضمن «من المجتمع» من مبدع تتابعه. أحجيات المجتمع بلا جوائز اقتصادية.',
            self::SupportConfirmed => 'سُجّل دعمك وأُكّد من فريق المنصة. الدعم لا يمنحك أي أفضلية في اللعب أو المكافآت.',
            self::SupporterTierChanged => 'يظهر مستواك ضمن «الداعمون». المستوى يُحسب من صافي دعمك المؤكَّد.',
            self::SponsorshipProgramStarted => 'بدأ برنامج رعاية تشارك فيه كداعم. اطّلع على صفحة الداعمين.',
            self::SeriesApproved => 'أصبحت سلسلتك منشورة ضمن «من المجتمع» ويمكن للاعبين تتبّعها.',
            self::SeriesChangesRequested => static::withNote('عدّل السلسلة وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SeriesRejected => static::withNote('يمكنك إنشاء سلسلة جديدة من استوديو المبدعين.', $params),
            self::SeriesHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewSeries => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من السلسلة ضمن «من المجتمع». سلاسل المجتمع بلا جوائز اقتصادية.'
                : 'سلسلة أحجيات جديدة ضمن «من المجتمع» من مبدع تتابعه. سلاسل المجتمع بلا جوائز اقتصادية.',
        };
    }

    /** ملاحظة المراجع (نص عادي يُهرَّب عند العرض) تُلحق بنص الإشعار إن وُجدت؛ الغياب لا يكسر النص. */
    protected static function withNote(string $base, array $params): string
    {
        $note = trim((string) ($params['note'] ?? ''));

        return $note === '' ? $base : 'ملاحظة المراجع: '.$note.' — '.$base;
    }

    /** @return list<string> */
    public static function reEngagementKeys(?self $atLeast = null): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isReEngagement() && ($atLeast === null || $t->priority() >= $atLeast->priority())),
        ));
    }
}
`````

### `app/Services/PuzzleAttemptService.php`

`sha256: 883a3fa773d41c5624a02b547cf60c9f2eed48dd70cf53d2877ebea06a3f012a`

`````php
<?php

namespace App\Services;

use App\GameEngine\GameResult;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Progression\GameplayProgressionService;
use App\Services\Sponsorship\SponsorshipRewardService;
use Illuminate\Support\Facades\DB;

class PuzzleAttemptService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected CurrencyRegistry $currencies,
        protected FraudDetectionService $fraud,
        protected GameTypeRegistry $games,
        protected AttemptRewardResolver $rewards,
        protected GameplayProgressionService $progression,
    ) {}

    public function attempt(
        User $user,
        Puzzle $puzzle,
        string $submittedAnswer,
        bool $usedHint = false,
        array $submission = [],
        ?AttemptContext $context = null,
    ): array {
        $context ??= AttemptContext::none();

        // E28: أحجيات المجتمع بلا اقتصاد/تقدّم بالبناء: لعبها يمرّ حصرًا بـCommunityPlayService (جدول لعب منفصل). هذه الخدمة تمنح جواهر وXP وتغذّي المهام والسلسلة،
        // فنرفض أي أحجية مجتمع هنا مهما كان السياق (حملة/جلسة/تنافس) كحاجز أخير لا يعتمد على فلترة المنادي.
        if ($puzzle->isCommunity()) {
            throw new \RuntimeException('هذه الأحجية غير متاحة عبر هذا المسار.');
        }

        // E26: المسار العام المباشر (بلا سياق) كان يقبل محاولات ومكافآت على أحجية معطَّلة/مسودة/مسحوبة لمن يخمّن معرّفها. السياقات (حملة/جلسة/تنافسي) تفحص
        // التفعيل بخدماتها (CampaignPuzzleService/GameSessionService/CompetitiveEligibility) فلا نغيّر سلوك جلسة جارية بقرار إداري لاحق.
        if (! $context->isPresent() && ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

        $result = DB::transaction(function () use ($user, $puzzle, $submittedAnswer, $usedHint, $submission, $context) {
            $previousAttempts = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->lockForUpdate()
                ->count();

            if ($previousAttempts >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            if ($user->hasSolvedPuzzle($puzzle, $context)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $payload = $submission !== [] ? $submission : ['answer' => $submittedAnswer];

            $validatorPayload = $payload + ['_context' => ['user_id' => $user->id]];

            $gameResult = $this->games->validatorFor($puzzle)->check($puzzle, $validatorPayload);
            $isCorrect = $gameResult->correct;

            $puzzleAttempt = PuzzleAttempt::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                'attempt_number' => $previousAttempts + 1,
                'is_correct' => $isCorrect,
                'used_hint' => $usedHint,
                'submission_snapshot' => $payload,
                ...$context->toAttributes(),
            ]);

            $gemsAwarded = 0;
            $sponsorBonus = 0;

            if ($isCorrect) {
                ['base' => $gemsAwarded, 'bonus' => $sponsorBonus] = $this->awardGemsForSolve($user, $puzzle, $context, (int) $puzzleAttempt->id);
                $this->updateChallengeScoresIfAny($user, $puzzle);
            }

            return [
                'attempt' => $puzzleAttempt,
                'correct' => $isCorrect,
                'gems_awarded' => $gemsAwarded,
                // E30: مكافأة الرعاية الإضافية (معاملة منفصلة، غالبًا 0). المكافأة الأساسية أعلاه لا تتغير أبدًا.
                'sponsor_bonus' => $sponsorBonus,
                'attempts_left' => max(0, $puzzle->max_attempts - $puzzleAttempt->attempt_number),
            ];
        });

        if ($result['correct']) {
            $this->progression->afterPuzzleSolved($user, $result['attempt'], $puzzle, $context);
        }

        return $result;
    }

    /** @return array{base: int, bonus: int} */
    protected function awardGemsForSolve(User $user, Puzzle $puzzle, AttemptContext $context, int $attemptId = 0): array
    {
        $directive = $this->rewards->resolve($puzzle, $context);

        $currency = $directive->currency
            ?? $puzzle->rewardCurrency
            ?? $this->currencies->defaultEarnedCurrency();

        $isDefaultCurrency = $currency->is($this->currencies->defaultEarnedCurrency());

        $rawReward = $directive->useDefault
            ? $this->games->scorerFor($puzzle)->calculate($puzzle, new GameResult(correct: true))
            : $directive->amount;

        if ($isDefaultCurrency) {
            // E26: تسلسل قراءة السقف + الإيداع بقفل صف المحفظة (نحن داخل معاملة attempt()): طلبات متزامنة لأحجيات مختلفة كانت تتجاوز السقف اليومي معًا.
            $this->wallets->lockWallet($user, $currency);

            $dailyCap = config('gems.daily_earn_cap');
            $alreadyEarnedToday = $this->wallets->dailyEarnedToday($user, $currency);
            $reward = min($rawReward, max(0, $dailyCap - $alreadyEarnedToday));
        } else {
            $reward = $rawReward;
        }

        if ($reward <= 0) {
            return ['base' => 0, 'bonus' => 0];
        }

        $this->wallets->creditPending($user, $currency, $reward, "solved_puzzle:{$puzzle->id}", $puzzle);

        // E30: مكافأة الرعاية (إن وُجد برنامج تعزيز نشط منطبق) عبر البوابة المركزية الوحيدة: معاملة منفصلة، رسمي فقط، ضمن سقف اليوم، وفشلها معزول (لا يمسّ الأساس).
        $bonus = app(SponsorshipRewardService::class)->applyToSolve($user, $puzzle, $context, $currency, $reward, $attemptId);

        $this->fraud->evaluateAfterEarn($user);

        return ['base' => $reward, 'bonus' => $bonus];
    }

    public function purchaseHint(User $user, Puzzle $puzzle): string
    {
        // E26: لا تلميحات (ولا خصم جواهر) لأحجية غير مفعّلة. E28: ولا لأحجية مجتمع (تلميحها مجاني عبر CommunityPlayService).
        if ($puzzle->isCommunity() || ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

        if (blank($puzzle->hint)) {
            throw new \RuntimeException('لا يوجد تلميح متاح لهذه الأحجية.');
        }

        $this->wallets->debitAvailable(
            $user,
            $this->currencies->defaultEarnedCurrency(),
            (int) config('gems.hint_cost'),
            "hint:{$puzzle->id}",
            $puzzle,
        );

        return $puzzle->hint;
    }

    protected function updateChallengeScoresIfAny(User $user, Puzzle $puzzle): void
    {
        $openChallenges = $puzzle->challenges()
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get();

        foreach ($openChallenges as $challenge) {
            $participant = $challenge->participants()->where('user_id', $user->id)->first();

            if ($participant) {
                $participant->increment('score');
            }
        }
    }
}
`````

### `app/Services/Search/DatabaseSearchBackend.php`

`sha256: 2e33e8a996e4dcc82c2f2383a5c3c25b20310e50abcdb9db2cec120f22eada20`

`````php
<?php

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchBackend;
use App\Services\Search\Contracts\SearchSource;
use App\Services\Search\Sources\CampaignSource;
use App\Services\Search\Sources\ChampionshipSource;
use App\Services\Search\Sources\CommunitySource;
use App\Services\Search\Sources\CompetitionSource;
use App\Services\Search\Sources\CreatorSeriesSource;
use App\Services\Search\Sources\PlayerSource;
use App\Services\Search\Sources\PuzzleSource;
use App\Services\Search\Sources\SeasonSource;
use App\Services\Search\Sources\TeamSource;
use Illuminate\Support\Collection;

/**
 * E27: التنفيذ الحالي لـSearchBackend: مصادر Eloquent فوق قاعدة البيانات الحالية. لا محرك خارجي ولا Scout ولا ذكاء اصطناعي ولا بحث متجهي.
 */
final class DatabaseSearchBackend implements SearchBackend
{
    /** @var array<string, SearchSource> */
    private array $sources = [];

    public function __construct(PuzzleSource $puzzles, CampaignSource $campaigns, SeasonSource $seasons, CompetitionSource $competitions, ChampionshipSource $championships, TeamSource $teams, PlayerSource $players, CommunitySource $community, CreatorSeriesSource $creatorSeries)
    {
        foreach ([$puzzles, $community, $creatorSeries, $campaigns, $seasons, $competitions, $championships, $teams, $players] as $source) {
            $this->sources[$source->type()] = $source;
        }
    }

    public function types(): array
    {
        $configured = array_values(array_filter((array) config('search.types', []), fn ($t) => is_string($t) && isset($this->sources[$t])));

        return $configured !== [] ? $configured : array_keys($this->sources);
    }

    public function label(string $type): string
    {
        return $this->source($type)->label();
    }

    public function minLength(string $type, SearchContext $context): int
    {
        return $this->source($type)->minLength($context);
    }

    public function searchType(string $type, SearchQuery $query, SearchContext $context, int $limit, int $offset = 0): Collection
    {
        return $this->source($type)->search($query, $context, $limit, $offset);
    }

    private function source(string $type): SearchSource
    {
        return $this->sources[$type] ?? throw new \InvalidArgumentException('Unknown search type.');
    }
}
`````

### `app/Support/WalletReasonLabel.php`

`sha256: a678203bf756fd38241073c47c3d07738e84e6d5e824a5589ba90e2541ef19cf`

`````php
<?php

namespace App\Support;

/**
 * E22 (عرض فقط): يحوّل رمز سبب معاملة المحفظة إلى نص عربي مقروء للاعب.
 *
 * لا يغيّر شيئًا في المحفظة أو في تخزين السبب: الرمز الأصلي يبقى كما هو في قاعدة البيانات،
 * والأسباب غير المعروفة (نصوص حرة من الإدارة مثلًا) تُعرض كما هي حرفيًا.
 */
final class WalletReasonLabel
{
    /** @var array<string, string> بادئة الرمز ← النص المعروض */
    private const PREFIXES = [
        'solved_puzzle' => 'حلّ أحجية',
        'achievement' => 'مكافأة إنجاز',
        'store_purchase' => 'شراء من المتجر',
        'hint' => 'شراء تلميح',
        'redemption_request' => 'طلب استبدال',
        'sponsor_bonus' => 'مكافأة رعاية',
    ];

    /** @var array<string, string> رمز كامل ← النص المعروض */
    private const EXACT = [
        'competitive_event_reward' => 'جائزة منافسة',
    ];

    /**
     * @return array{label: string, raw: string, known: bool}
     */
    public static function describe(?string $reason): array
    {
        $raw = trim((string) $reason);

        if ($raw === '') {
            return ['label' => 'معاملة', 'raw' => '', 'known' => false];
        }

        if (isset(self::EXACT[$raw])) {
            return ['label' => self::EXACT[$raw], 'raw' => $raw, 'known' => true];
        }

        $head = strstr($raw, ':', true);
        $tail = $head === false ? '' : substr($raw, strlen($head) + 1);

        if ($head === 'quest') {
            $label = str_starts_with($tail, 'weekly') ? 'مهمة أسبوعية' : (str_starts_with($tail, 'daily') ? 'مهمة يومية' : 'إكمال مهمة');

            return ['label' => $label, 'raw' => $raw, 'known' => true];
        }

        if ($head === 'level' && ctype_digit($tail)) {
            return ['label' => 'ترقية إلى المستوى '.$tail, 'raw' => $raw, 'known' => true];
        }

        if ($head !== false && isset(self::PREFIXES[$head])) {
            return ['label' => self::PREFIXES[$head], 'raw' => $raw, 'known' => true];
        }

        return ['label' => $raw, 'raw' => $raw, 'known' => false];
    }
}
`````

### `config/permissions.php`

`sha256: e6cc7f43998c0001726d0256dde6a48f65c89fa2dd53b5fc70635f88e2551d24`

`````php
<?php

/**
 * سجل الصلاحيات المركزي (Permission Registry) - مصدر الحقيقة الوحيد لأي
 * صلاحية بالنظام. Admin لا يكتب أسماء صلاحيات حرة أبداً - فقط يختار من
 * هذه القائمة عبر واجهة الأدوار. لإضافة صلاحية جديدة مستقبلاً: أضف سطراً
 * هنا، شغّل `php artisan permissions:sync`، ستظهر تلقائياً بمصفوفة
 * الصلاحيات - بلا Migration وبلا تعديل RoleResource. راجع
 * docs/roles-permissions.md.
 *
 * تنبيه: هذا الملف "permissions.php" (بصيغة الجمع) - مختلف تماماً عن
 * "permission.php" (بالمفرد) الذي نشرته حزمة Spatie بأمر vendor:publish.
 * لا تخلط بينهما - كلاهما يبقى موجوداً بمجلد config/ بشكل منفصل.
 *
 * Convention: module.action (نقطة واحدة، أحرف صغيرة فقط).
 */
return [

    'dashboard' => [
        'label' => 'لوحة التحكم',
        'permissions' => [
            'admin.access' => 'الدخول إلى لوحة الإدارة',
        ],
    ],

    'users' => [
        'label' => 'المستخدمون',
        'permissions' => [
            'users.view' => 'عرض المستخدمين',
            'users.create' => 'إنشاء مستخدم',
            'users.update' => 'تعديل مستخدم',
            'users.delete' => 'حذف مستخدم',
            'users.freeze' => 'تجميد حساب مستخدم',
            'users.unfreeze' => 'رفع تجميد حساب مستخدم',
            'users.view_security' => 'عرض بيانات الأمان (أجهزة/جلسات/IP) لمستخدم',
            'users.view_wallet' => 'عرض محفظة مستخدم ضمن ملفه',
            'users.view_activity' => 'عرض نشاط اللعب والحملات لمستخدم',
            'users.manage_roles' => 'تعيين/إزالة أدوار مستخدم من ملفه',
        ],
    ],

    'roles' => [
        'label' => 'الأدوار والصلاحيات',
        'permissions' => [
            'roles.view' => 'عرض الأدوار',
            'roles.create' => 'إنشاء دور',
            'roles.update' => 'تعديل دور',
            'roles.delete' => 'حذف دور',
            'roles.assign' => 'تعيين الأدوار للمستخدمين',
        ],
    ],

    'settings' => [
        'label' => 'إعدادات المنصة',
        'permissions' => [
            'settings.view' => 'عرض الإعدادات',
            'settings.update' => 'تعديل الإعدادات',
        ],
    ],

    'seasons' => [
        'label' => 'المواسم الرسمية',
        'permissions' => [
            'seasons.view' => 'عرض المواسم',
            'seasons.create' => 'إنشاء موسم',
            'seasons.update' => 'تعديل موسم',
            'seasons.delete' => 'حذف موسم',
            'seasons.publish' => 'نشر موسم',
            'seasons.preview' => 'معاينة موسم غير منشور',
        ],
    ],

    'campaigns' => [
        'label' => 'الحملات (المراحل/البوابات/الخطوات)',
        'permissions' => [
            'campaigns.view' => 'عرض الحملات',
            'campaigns.create' => 'إنشاء حملة',
            'campaigns.update' => 'تعديل حملة ومحتواها (مراحل/بوابات/خطوات)',
            'campaigns.delete' => 'حذف حملة',
        ],
    ],

    'puzzles' => [
        'label' => 'الأحجيات',
        'permissions' => [
            'puzzles.view' => 'عرض الأحجيات',
            'puzzles.create' => 'إنشاء أحجية',
            'puzzles.update' => 'تعديل أحجية',
            'puzzles.delete' => 'حذف أحجية',
            'puzzles.publish' => 'تفعيل/تعطيل أحجية',
            'puzzles.manage_categories' => 'إدارة تصنيفات الأحجيات',
        ],
    ],

    'challenges' => [
        'label' => 'التحديات والبطولات',
        'permissions' => [
            'challenges.view' => 'عرض التحديات',
            'challenges.create' => 'إنشاء تحدٍّ',
            'challenges.update' => 'تعديل تحدٍّ',
            'challenges.delete' => 'حذف تحدٍّ',
        ],
    ],

    'wallet' => [
        'label' => 'الجواهر والمحفظة',
        'permissions' => [
            'wallet.view' => 'عرض سجل معاملات الجواهر',
            'wallet.adjust' => 'تعديل رصيد جواهر مستخدم يدوياً (إضافة/خصم)',
        ],
    ],

    'redemptions' => [
        'label' => 'طلبات الاستبدال',
        'permissions' => [
            'redemptions.view' => 'عرض طلبات الاستبدال',
            'redemptions.approve' => 'قبول طلب استبدال',
            'redemptions.reject' => 'رفض طلب استبدال',
        ],
    ],

    'fraud' => [
        'label' => 'الأمان ومكافحة الاحتيال',
        'permissions' => [
            'fraud.view' => 'عرض علامات الاحتيال',
            'fraud.resolve' => 'معالجة/إغلاق علامة احتيال',
        ],
    ],

    'security' => [
        'label' => 'إدارة الجلسات',
        'permissions' => [
            'security.sessions_view' => 'عرض جلسات دخول المستخدمين',
            'security.sessions_revoke' => 'إنهاء جلسة دخول مستخدم',
        ],
    ],

    'operations' => [
        'label' => 'مركز العمليات',
        'permissions' => [
            'operations.dashboard_view' => 'عرض مركز العمليات',
        ],
    ],

    'analytics' => [
        'label' => 'التحليلات والتقارير',
        'permissions' => [
            'analytics.view' => 'الدخول إلى مركز التحليلات (نظرة عامة)',
            'analytics.users' => 'عرض تحليلات المستخدمين',
            'analytics.puzzles' => 'عرض تحليلات الأحجيات',
            'analytics.campaigns' => 'عرض تحليلات الحملات والمواسم',
            'analytics.financial' => 'عرض تحليلات الجواهر والاستبدال',
            'analytics.security' => 'عرض تحليلات الأمان والاحتيال',
            'analytics.competitive' => 'عرض تحليلات المنافسات والجوائز التنافسية',
            'reports.export' => 'تصدير التقارير (CSV)',
        ],
    ],

    'economy' => [
        'label' => 'الاقتصاد والعملات',
        'permissions' => [
            'economy.currencies.view' => 'عرض العملات',
            'economy.currencies.create' => 'إنشاء عملة جديدة',
            'economy.currencies.update' => 'تعديل عملة (السياسات/الاسم/الأيقونة)',
            'economy.currencies.deactivate' => 'تفعيل/تعطيل عملة',
            'economy.packs.view' => 'عرض حزم العملات بالمتجر',
            'economy.packs.manage' => 'إنشاء/تعديل/تفعيل حزم العملات',
            'economy.transactions.view' => 'عرض سجل معاملات العملات (Ledger)',
        ],
    ],



    'store' => [
        'label' => 'المتجر والمخزون',
        'permissions' => [
            'store.items.view' => 'عرض عناصر المتجر',
            'store.items.create' => 'إنشاء عنصر متجر جديد',
            'store.items.update' => 'تعديل عنصر متجر (بيانات/تسعير)',
            'store.items.deactivate' => 'تفعيل/تعطيل عنصر متجر',
            'store.prices.manage' => 'إدارة خيارات أسعار عناصر المتجر',
            'store.purchases.view' => 'عرض عمليات شراء المتجر',
            'store.purchases.fulfill' => 'إنجاز طلب شراء يدوي',
            'store.purchases.refund' => 'استرجاع عملية شراء',
            'store.inventory.view' => 'عرض مخزون المستخدمين الشخصي',
            'store.entitlements.view' => 'عرض امتيازات المستخدمين',
        ],
    ],


    'system' => [
        'label' => 'النظام والتدقيق',
        'permissions' => [
            'system.audit_view' => 'عرض سجل تغييرات الصلاحيات',
        ],
    ],



        'progression' => [
        'label' => 'التقدُّم (XP والمستويات والإنجازات)',
        'permissions' => [
            'progression.achievements.view' => 'عرض الإنجازات',
            'progression.achievements.create' => 'إنشاء إنجاز جديد',
            'progression.achievements.update' => 'تعديل إنجاز',
            'progression.achievements.deactivate' => 'تفعيل/تعطيل إنجاز',
            'progression.levels.view' => 'عرض تعريفات المستويات',
            'progression.levels.create' => 'إنشاء مستوى جديد',
            'progression.levels.update' => 'تعديل مستوى',
            'progression.users.view' => 'عرض تقدُّم المستخدمين (XP/المستوى/الإنجازات)',
        ],
    ],

        'engagement' => [
        'label' => 'المشاركة (المهام والسلسلة اليومية)',
        'permissions' => [
            'engagement.quests.view' => 'عرض تعريفات المهام',
            'engagement.quests.create' => 'إنشاء مهمة جديدة',
            'engagement.quests.update' => 'تعديل مهمة',
            'engagement.quests.deactivate' => 'تفعيل/تعطيل مهمة',
            'engagement.users.view' => 'عرض مشاركة المستخدمين (المهام/السلسلة)',
        ],
    ],

    'advertising' => [
        'label' => 'الإعلانات (المواضع والرعاة)',
        'permissions' => [
            'ads.placements.manage' => 'إدارة مواضع الإعلان (تفعيل/تعطيل/إعدادات الجهاز)',
            'ads.campaigns.view' => 'عرض حملات الرعاة',
            'ads.campaigns.create' => 'إنشاء حملة راعٍ جديدة',
            'ads.campaigns.update' => 'تعديل حملة راعٍ',
            'ads.campaigns.review' => 'مراجعة واعتماد/رفض حملات الرعاة',
            'ads.campaigns.pause' => 'إيقاف/استئناف حملة راعٍ',
            'ads.settings.manage' => 'إدارة إعدادات الإعلانات العامة (مفتاح الإيقاف الشامل)',
            'ads.analytics.view' => 'عرض تحليلات الإعلانات',
        ],
    ],

    'chat' => [
        'label' => 'الدردشة',
        'permissions' => [
            'chat.reports.view' => 'عرض بلاغات الرسائل (الرسالة المبلَّغ عنها فقط)',
            'chat.moderate' => 'إخفاء/استعادة الرسائل وكتم/رفع كتم الدردشة العامة ومراجعة البلاغات',
        ],
    ],

    'team_championships' => [
        'label' => 'بطولات الفرق',
        'permissions' => [
            'team_championships.view' => 'عرض بطولات الفرق',
            'team_championships.manage' => 'إنشاء وتعديل المسودات وربط الأحداث',
            'team_championships.publish' => 'نشر البطولة وإلغاؤها واعتماد نتائجها',
        ],
    ],

    'team_challenges' => [
        'label' => 'تحدّيات الفرق',
        'permissions' => [
            'team_challenges.view' => 'فحص تحدّيات الفرق ونتائجها (عرض فقط)',
        ],
    ],

    'teams' => [
        'label' => 'الفرق',
        'permissions' => [
            'teams.view' => 'عرض الفرق وأعضائها (إدارة)',
            'teams.manage' => 'تدخل إداري: نقل ملكية فريق وإزالة عضو عبر الخدمة',
            'teams.deactivate' => 'تعطيل الفرق وإعادة تفعيلها',
        ],
    ],

    'competitive_events' => [
        'label' => 'المنافسات (E17)',
        'permissions' => [
            'competitive_events.view' => 'عرض المنافسات ونتائجها',
            'competitive_events.create' => 'إنشاء منافسة (مسوّدة)',
            'competitive_events.update' => 'تعديل مسوّدة منافسة',
            'competitive_events.delete' => 'حذف منافسة مسوّدة',
            'competitive_events.publish' => 'نشر منافسة',
            'competitive_events.cancel' => 'إلغاء منافسة',
            'competitive_events.finalize' => 'اعتماد النتائج النهائية يدويًا',
            'competitive_events.rewards.view' => 'عرض قواعد الجوائز وحالة توزيعها',
            'competitive_events.rewards.manage' => 'إدارة قواعد جوائز المنافسة (قبل بدئها)',
            'competitive_events.rewards.retry' => 'إعادة محاولة منح الجوائز الفاشلة',
        ],
    ],

    'creators' => [
        'label' => 'المبدعون وأحجيات المجتمع',
        'permissions' => [
            'creators.view' => 'عرض المبدعين وطلباتهم ومراجعات الأحجيات وبلاغات المجتمع',
            'creators.review' => 'مراجعة طلبات المبدعين والأحجيات المرسلة (اعتماد/طلب تعديل/رفض) ومعالجة البلاغات وإخفاء/استعادة المنشور',
            'creators.manage' => 'تعليق المبدعين واستعادتهم',
        ],
    ],

    'sponsorships' => [
        'label' => 'الرعاة والداعمون وتمويل المكافآت',
        'permissions' => [
            'sponsorships.view' => 'عرض ملفات الداعمين وبرامج الرعاية والمساهمات والأرقام المالية والتحليلات',
            'sponsorships.manage' => 'إنشاء/تعديل ملفات الداعمين والبرامج وأدوار الرعاة وتسجيل المساهمات ومستويات الداعمين',
            'sponsorships.confirm' => 'تأكيد المساهمات أو إلغاؤها أو استردادها (صلاحية مالية منفصلة)',
            'sponsorships.reward_plan' => 'ضبط خطة تعزيز المكافآت وقفل خطة المنافسات',
        ],
    ],

    'notifications' => [
        'label' => 'الإشعارات',
        'permissions' => [
            'notifications.settings.manage' => 'إدارة إعدادات الإشعارات العامة (تفعيل/ساعات التذكير/ميزانية التذكيرات)',
        ],
    ],

];
`````

### `config/search.php`

`sha256: 7614c3e8ad9b7438108d4d4eb52c882fbdd816da51241a0dccfa5449f0d884da`

`````php
<?php

/*
| E27 - البحث العام والاكتشاف والرائج والتوصيات.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. السائق الحالي database (بلا محرك بحث خارجي)؛ التبديل لمحرك آخر = تنفيذ جديد لـ SearchBackend (docs/search-discovery-e27.md).
*/
return [
    // database = LIKE/تطبيع على قاعدة البيانات الحالية. أي قيمة غير معروفة تسقط إلى database (لا تعطّل الموقع).
    'driver' => env('SEARCH_DRIVER', 'database'),

    // طول المصطلح بعد التطبيع (حروف لا بايتات). اللاعبون لهم حدّ أدنى أعلى للضيوف (يقلّل مسح الأسماء بالتخمين).
    'min_length' => (int) env('SEARCH_MIN_LENGTH', 2),
    'min_length_guest_players' => (int) env('SEARCH_MIN_LENGTH_GUEST_PLAYERS', 3),
    'max_length' => 64,
    'max_tokens' => 5,

    // النتائج: صفحة البحث الكاملة وتبويب النوع الواحد (ترقيم بسيط، بلا COUNT) وسقف عمق الصفحات.
    'all_per_type' => 5,
    'page_size' => 12,
    'max_page' => 5,
    'guest_max_page' => 3,

    // الاقتراحات الفورية (autocomplete): سقف لكل نوع وسقف كلي.
    'suggest_per_type' => 3,
    'suggest_total' => 9,

    // ترتيب الأنواع الافتراضي في النتائج (المجموعة التي فيها تطابق تام تتقدّم).
    'types' => ['puzzles', 'community', 'creator_series', 'campaigns', 'seasons', 'competitions', 'championships', 'teams', 'players'],

    // المطابقة الجزئية (بالوسط/بأي كلمة + طيّ الحروف العربية بـREPLACE) مسح كامل للجدول؛ لذا لا تُفعَّل إلا حين يكون أكبر معرّف بالجدول ≤ هذا الحدّ.
    // فوقه: بحث ببادئة العمود فقط (يستعمل الفهرس). هذا هو مؤشر الانتقال لمحرك بحث/FULLTEXT (انظر المستند).
    'partial_match_max_id' => (int) env('SEARCH_PARTIAL_MAX_ID', 10000),
    'partial_match_max_id_by_type' => [
        // 'players' => 20000,
    ],
    'partial_probe_ttl' => 600,

    // مراتب الصلة (الأصغر أعلى): تطابق تام ثم بادئة ثم بادئة كلمة ثم جزئي. لا تغيّرها دون تحديث الاختبارات.
    'rank_tiers' => ['exact' => 0, 'prefix' => 1, 'word_prefix' => 2, 'partial' => 3],

    'trending' => [
        'window_days' => (int) env('SEARCH_TRENDING_WINDOW_DAYS', 7),
        'cache_ttl' => (int) env('SEARCH_TRENDING_TTL', 300),
        // اللاعبون المميَّزون (لا عدد المحاولات): أوزان تراكمية لآخر يوم / 3 أيام / كامل النافذة، + وزن إضافي لمن حلّوا الأحجية.
        'recency_weights' => ['d1' => 4, 'd3' => 2, 'd7' => 1],
        'solver_weight' => 2,
        'min_unique_players' => (int) env('SEARCH_TRENDING_MIN_PLAYERS', 2),
        'limit' => 8,
        'pool' => 40,
        'binary_search_steps' => 40,
    ],

    'recommendations' => [
        'limit' => 8,
        'pool' => 60,
        'history_cap' => 500,
        'exploration_share' => 0.25,
        'new_content_days' => 14,
        'weights' => ['category' => 5.0, 'difficulty' => 2.0, 'popularity' => 1.5, 'novelty' => 1.0],
    ],

    'discover' => [
        'section_limit' => 8,
        'season_limit' => 4,
        'campaign_limit' => 6,
        'event_limit' => 6,
        'team_limit' => 6,
    ],
];
`````

### `database/seeders/RolesAndPermissionsSeeder.php`

`sha256: dca67f19cc12e01d4f892b90eebe87564be6d546a754b84573027fc67919dea0`

`````php
<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        collect(config('permissions', []))
            ->flatMap(fn($module) => array_keys($module['permissions'] ?? []))
            ->unique()
            ->each(fn($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->role('super-admin', 'المدير الأعلى', 'وصول كامل غير مقيَّد لكل أجزاء المنصة - محمي، لا يمكن حذفه.', true, '#f59e0b', 0, []);
        $this->role('administrator', 'مدير عام', 'وصول كامل لكل الوحدات الوظيفية.', true, '#8b5cf6', 1, $this->allPermissionNames());

        $this->role('content-manager', 'مدير محتوى', 'إدارة المواسم والحملات والأحجيات فقط.', true, '#22d3ee', 2, [
            'admin.access',
            'seasons.view',
            'seasons.create',
            'seasons.update',
            'seasons.delete',
            'seasons.publish',
            'seasons.preview',
            'campaigns.view',
            'campaigns.create',
            'campaigns.update',
            'campaigns.delete',
            'puzzles.view',
            'puzzles.create',
            'puzzles.update',
            'puzzles.delete',
            'puzzles.publish',
            'puzzles.manage_categories',
        ]);

        $this->role('moderator', 'مشرف', 'مراقبة المستخدمين ومكافحة الاحتيال والتحديات.', true, '#fb7185', 3, [
            'admin.access',
            'users.view',
            'fraud.view',
            'fraud.resolve',
            'challenges.view',
            'challenges.update',
        ]);

        $this->role('support', 'دعم فني', 'عرض المستخدمين وطلبات الاستبدال فقط.', true, '#34d399', 4, [
            'admin.access',
            'users.view',
            'redemptions.view',
        ]);

        $this->role('player', 'لاعب', 'الدور الافتراضي لأي مستخدم جديد - بلا وصول للوحة الإدارة.', true, '#64748b', 99, []);

        $this->grantIfMissing('administrator', [
            'users.freeze',
            'users.unfreeze',
            'users.view_security',
            'users.view_wallet',
            'users.view_activity',
            'users.manage_roles',
            'wallet.adjust',
            'security.sessions_view',
            'security.sessions_revoke',
            'operations.dashboard_view',
            'analytics.view',
            'analytics.users',
            'analytics.puzzles',
            'analytics.campaigns',
            'analytics.financial',
            'analytics.security',
            'reports.export',
            'economy.currencies.view',
            'economy.currencies.create',
            'economy.currencies.update',
            'economy.currencies.deactivate',
            'economy.packs.view',
            'economy.packs.manage',
            'economy.transactions.view',
            'store.items.view',
            'store.items.create',
            'store.items.update',
            'store.items.deactivate',
            'store.prices.manage',
            'store.purchases.view',
            'store.purchases.fulfill',
            'store.purchases.refund',
            'store.inventory.view',
            'store.entitlements.view',

        ]);

        $this->grantIfMissing('administrator', ['creators.view', 'creators.review', 'creators.manage']);

        $this->grantIfMissing('moderator', ['creators.view', 'creators.review']);

        // E30: فصل مالي. المدير العام يملك كل شيء؛ مدير المحتوى يعرض ويدير الملفات والبرامج فقط (لا تأكيد/استرداد ولا خطة مكافآت)؛ الدعم والمشرف بلا صلاحيات رعاية.
        $this->grantIfMissing('administrator', ['sponsorships.view', 'sponsorships.manage', 'sponsorships.confirm', 'sponsorships.reward_plan']);
        $this->grantIfMissing('content-manager', ['sponsorships.view', 'sponsorships.manage']);

        $this->grantIfMissing('moderator', [
            'users.view_security',
            'users.view_activity',
            'operations.dashboard_view',
            'analytics.view',
            'analytics.security',
        ]);

        $this->grantIfMissing('support', [
            'users.view_activity',
            'store.purchases.view',
            'store.purchases.fulfill',
            'store.inventory.view',
            'progression.users.view',
            'engagement.users.view',
            'ads.campaigns.view',
            'ads.analytics.view',
        ]);

        /**
         * E14 (بند 598 - قرار موثَّق): content-manager يملك create/update/pause
         * لكن عمدًا بلا ads.campaigns.review - هذا يمنع الاعتماد الذاتي
         * (Self-Approval) بشكل طبيعي عبر فصل الأدوار القائم أصلًا (المراجعة
         * تبقى حصرية لـadministrator، صاحب allPermissionNames() الكاملة)،
         * دون كسر أي سير عمل فعلي بنموذج التوظيف الحالي الصغير بالمشروع.
         */
        $this->grantIfMissing('content-manager', [
            'ads.campaigns.view',
            'ads.campaigns.create',
            'ads.campaigns.update',
            'ads.campaigns.pause',
            'ads.analytics.view',
        ]);

        $this->grantIfMissing('content-manager', [
            'analytics.view',
            'analytics.puzzles',
            'analytics.campaigns',
            'economy.currencies.view',
            'store.items.view',
            'store.items.create',
            'store.items.update',
            'store.prices.manage',
            'progression.achievements.view',
            'progression.achievements.create',
            'progression.achievements.update',
            'progression.levels.view',
            'progression.levels.create',
            'progression.levels.update',
            'engagement.quests.view',
            'engagement.quests.create',
            'engagement.quests.update',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        \Illuminate\Support\Facades\Artisan::call('rbac:migrate-legacy-roles');
    }

    protected function allPermissionNames(): array
    {
        return Permission::query()->where('guard_name', 'web')->pluck('name')->all();
    }

    protected function role(string $name, string $labelAr, string $description, bool $isSystem, string $color, int $sortOrder, array $permissionNames): void
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['label_ar' => $labelAr, 'description' => $description, 'is_system' => $isSystem, 'color' => $color, 'sort_order' => $sortOrder],
        );

        if ($role->wasRecentlyCreated && !empty($permissionNames)) {
            $models = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($models);
        }
    }

    protected function grantIfMissing(string $roleName, array $permissionNames): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (!$role) {
            return;
        }

        $models = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissionNames)->get();
        $role->givePermissionTo($models);
    }
}
`````

### `resources/views/campaigns/show.blade.php`

`sha256: 57fa860b8c2859e3b94226666eebfea269c156b60998e6f19d4044867d4f3464`

`````blade
@extends('layouts.app')

@section('title', $campaign->title)

@section('content')
    <div class="max-w-4xl mx-auto">
        <a href="{{ route('campaigns.index') }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
            → كل الحملات
        </a>

        <x-sponsor-strip type="campaign" :target-id="$campaign->id" class="mb-4" />

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            @if ($campaign->cover_image)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($campaign->cover_image) }}"
                         alt="{{ $campaign->title }}" class="w-full h-auto">
                </div>
            @endif

            <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-3">{{ $campaign->title }}</h1>

            @if ($campaign->description)
                <p class="text-slate-400 mb-6">{{ $campaign->description }}</p>
            @endif

            @auth
                <div class="mb-8">
                    <div class="flex items-center justify-between text-sm font-bold text-slate-400 mb-2">
                        <span>تقدّمك</span>
                        <span class="text-gold">{{ $percentage }}٪</span>
                    </div>
                    <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $percentage }}%"></div>
                    </div>
                </div>

                @if ($currentStep)
                    <a href="{{ route('campaigns.steps.show', [$campaign, $currentStep]) }}"
                       class="btn-gem !py-3 !px-6 inline-flex items-center gap-2 mb-8">
                        متابعة: {{ $currentStep->title }} ←
                    </a>
                @else
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold mb-8">
                        🎉 أكملت هذه الحملة بالكامل!
                    </div>
                @endif
            @else
                <div class="rounded-xl border border-amethyst/30 bg-amethyst/10 text-white px-5 py-4 font-bold mb-8 flex items-center justify-between gap-3">
                    <span>سجّل الدخول لبدء هذه الحملة وتتبّع تقدّمك</span>
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-4 shrink-0">تسجيل الدخول</a>
                </div>
            @endauth

            <div class="space-y-8">
                @foreach ($stagesView as $stageView)
                    <div class="anim-fade-up">
                        <h2 class="font-display font-black text-lg text-white mb-3 flex items-center gap-2">
                            @unless ($stageView->unlocked)
                                <span class="text-slate-500">🔒</span>
                            @endunless
                            {{ $stageView->model->title }}
                        </h2>

                        <div class="space-y-3 ps-4 border-s-2 border-white/5">
                            @foreach ($stageView->gates as $gateView)
                                <div class="rounded-xl border border-white/10 p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="font-bold text-white">{{ $gateView->model->title }}</span>
                                        <x-campaign-state-badge :state="$gateView->state" />
                                    </div>

                                    @if ($gateView->state === \App\Services\CampaignProgressService::STATE_NOT_QUALIFIED)
                                        <p class="text-xs text-slate-400 mb-3">
                                            اكتملت هذه المرحلة، لكن لم يتم التأهل للمرحلة التالية.
                                        </p>
                                    @endif

                                    <div class="grid gap-2">
                                        @foreach ($gateView->steps as $stepView)
                                            @if ($stepView->state === \App\Services\CampaignProgressService::STATE_LOCKED)
                                                <div class="flex items-center gap-2 text-sm text-slate-500 px-3 py-2 rounded-lg bg-white/5">
                                                    <span>🔒</span> خطوة مقفلة
                                                </div>
                                            @else
                                                <a href="{{ route('campaigns.steps.show', [$campaign, $stepView->model]) }}"
                                                   class="flex items-center justify-between gap-2 text-sm px-3 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition">
                                                    <span class="text-white">{{ $stepView->model->title }}</span>
                                                    <x-campaign-state-badge :state="$stepView->state" small />
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
`````

### `resources/views/community/show.blade.php`

`sha256: 10108180c94af5bd52d0724088f23a26ad4d4c49123c9c74419f48d1987eaf63`

`````blade
@extends('layouts.app')

@section('title', $puzzle->title.' - من المجتمع')
@section('content_width', 'max-w-3xl')

{{--
    E28: صفحة لعب أحجية مجتمع. الوسم «من المجتمع» وتصريح «بلا جوائز» ظاهران دائمًا. اللعب عبر مسار المجتمع المنفصل (لا /puzzles الرسمي) والمدقّق الرسمي نفسه.
    لا جواهر ولا XP ولا مكافآت ولا مهام ولا سلسلة. المبدع لا يلعب أحجيته (يعاينها). نصوص المبدع مهرَّبة.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('community.puzzles.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />أحجيات المجتمع
        </a>

        @if (! empty($seriesContext))
            {{-- E30: شريط سياق السلسلة (تنقّل فقط): الموضع والسابق والتالي. لا يغيّر اللعب ولا يمنح شيئًا. --}}
            <nav aria-label="سياق السلسلة" data-series-context class="glass rounded-2xl px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-sm anim-fade-up">
                <a href="{{ $seriesContext['url'] }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst break-words">سلسلة: {{ $seriesContext['title'] }} · {{ $seriesContext['position'] }} من {{ $seriesContext['total'] }}</a>
                <span class="flex items-center gap-2">
                    @if ($seriesContext['previous']) <a href="{{ route('community.puzzles.show', ['work' => $seriesContext['previous']['public_id'], 'series' => $seriesContext['series']]) }}" class="btn-secondary !py-1.5 !px-3 text-xs" rel="prev">السابقة</a> @endif
                    @if ($seriesContext['next']) <a href="{{ route('community.puzzles.show', ['work' => $seriesContext['next']['public_id'], 'series' => $seriesContext['series']]) }}" class="btn-secondary !py-1.5 !px-3 text-xs" rel="next">التالية</a> @endif
                </span>
            </nav>
        @endif

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                @if ($work->publishedCategory) <span class="chip !py-1 !px-3">{{ $work->publishedCategory->name }}</span> @endif
                <x-difficulty-badge :difficulty="$puzzle->difficulty" />
                <x-status-badge tone="neutral" :small="true">بلا جوائز</x-status-badge>
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $puzzle->title }}</h1>
            <p class="text-xs text-slate-400 mb-4">
                بقلم <a href="{{ route('creators.show', $work->creator->slug) }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $work->creator->user->name }}</a>
                · {{ number_format($work->plays_count) }} لاعب
            </p>

            <p class="font-display font-black text-xl md:text-2xl text-white mb-4 leading-snug break-words whitespace-pre-line">{{ $puzzle->prompt }}</p>

            @if ($puzzle->image_path)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif

            @guest
                <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <span>سجّل الدخول لتلعب هذه الأحجية وتحفظ حالة حلّك.</span>
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                </div>
            @else
                @if ($isOwner)
                    <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200" role="status">هذه أحجيتك. لا تُحتسب لك محاولات عليها؛ جرّبها من المعاينة في الاستوديو.
                        <a href="{{ route('creator.puzzles.preview', $work->public_id) }}" class="text-amethyst font-bold underline">افتح المعاينة</a></div>
                @elseif (! $canInteract)
                    <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-gold font-bold" role="status">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتلعب أحجيات المجتمع.</div>
                @elseif ($state['solved'])
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold flex items-center gap-2" role="status"><x-ui-icon name="check-circle" class="w-5 h-5" />أحسنت! سبق أن حللت هذه الأحجية.</div>
                @elseif ($state['left'] <= 0)
                    <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 font-bold flex items-center gap-2" role="status"><x-ui-icon name="alert" class="w-5 h-5" />استنفدت جميع محاولاتك المسموحة لهذه الأحجية.</div>
                @else
                    <form method="POST" action="{{ route('community.puzzles.attempt', $work->public_id) }}" class="mt-2">
                        @csrf
                        @include($renderer)

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-bold text-slate-400">محاولة {{ $state['used'] + 1 }} من {{ $state['max'] }}</span>
                            <button type="submit" class="btn-gem !py-3 !px-6">إرسال الإجابة</button>
                        </div>
                    </form>

                    @if ($puzzle->hint)
                        <form method="POST" action="{{ route('community.puzzles.hint', $work->public_id) }}" class="mt-6 pt-6 border-t border-white/5">
                            @csrf
                            <button type="submit" class="text-sm font-bold text-slate-300 hover:text-gold transition motion-reduce:transition-none underline decoration-gold decoration-2 underline-offset-4 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إظهار التلميح (مجاني)</button>
                        </form>
                    @endif
                @endif
            @endguest
        </div>

        {{-- E29: التقييم والمفضلة والمجموعات (المتوسط والعدّادات علنية للجميع؛ التفاعل للموثَّق غير المجمَّد وغير المالك) --}}
        @include('engagement._work_panel', ['work' => $work, 'isOwner' => $isOwner, 'canInteract' => $canInteract, 'viewer' => $viewer, 'collections' => $collections])

        @auth
            @if (! $isOwner && $canInteract)
                <details class="glass rounded-2xl p-5 anim-fade-up">
                    <summary class="cursor-pointer text-sm font-bold text-slate-300 flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="flag" class="w-4 h-4" />الإبلاغ عن هذه الأحجية</summary>
                    <form method="POST" action="{{ route('community.puzzles.report', $work->public_id) }}" class="mt-4 space-y-3">
                        @csrf
                        <div>
                            <label for="report-reason" class="block text-xs font-bold text-slate-300 mb-1">السبب</label>
                            <select id="report-reason" name="reason" required class="input-gem">
                                @foreach ($reasons as $key => $label)
                                    <option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="report-details" class="block text-xs font-bold text-slate-300 mb-1">تفاصيل (اختياري)</label>
                            <textarea id="report-details" name="details" rows="3" maxlength="1000" class="input-gem">{{ old('details') }}</textarea>
                        </div>
                        <button type="submit" class="btn-secondary !py-2 !px-5 text-sm">إرسال البلاغ</button>
                    </form>
                </details>
            @endif
        @endauth
    </div>
@endsection
`````

### `resources/views/competitions/show.blade.php`

`sha256: 314a4808130e5db7c249143c10e943ebb5cf7e817eb36de8b4da7334c69c45ab`

`````blade
@extends('layouts.app')

@section('title', $event->title)

@section('content')
    @php
        $phase = $event->phase();
        $phaseLabel = ['upcoming' => 'قادمة', 'live' => 'مباشرة الآن', 'ended' => 'انتهت (بانتظار اعتماد النتائج)', 'completed' => 'انتهت · النتائج نهائية', 'cancelled' => 'مُلغاة'][$phase] ?? '';
        $cap = (int) ($event->puzzle->time_limit_seconds ?: config('competitive.default_time_cap_seconds', 600));
    @endphp

    <div class="max-w-3xl mx-auto space-y-6">
        <a href="{{ route('competitions.index') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition anim-fade-up">→ كل المنافسات</a>

        <x-sponsor-strip type="competitive_event" :target-id="$event->id" />

        <div class="glass rounded-3xl p-6 md:p-8 anim-fade-up">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="chip !py-1 !px-3 text-xs {{ $phase === 'live' ? '!text-emerald-400' : '' }}">{{ $phaseLabel }}</span>
                @if ($event->is_featured)<span class="text-xs font-black text-gold">⭐ مميزة</span>@endif
            </div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white leading-tight">{{ $event->title }}</h1>
            @if ($event->description)
                <p class="text-slate-300 mt-3 whitespace-pre-line">{{ $event->description }}</p>
            @endif

            <dl class="grid gap-3 sm:grid-cols-2 mt-6 text-sm">
                <div><dt class="text-slate-500">البداية</dt><dd class="text-white font-bold">{{ $event->starts_at->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="text-slate-500">النهاية</dt><dd class="text-white font-bold">{{ $event->ends_at->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="text-slate-500">المشاركون</dt><dd class="text-white font-bold">{{ $event->participants_count }}@if ($event->max_participants !== null) / {{ $event->max_participants }}@endif</dd></div>
                <div><dt class="text-slate-500">الأحجية</dt><dd class="text-white font-bold">{{ $event->puzzle->title }} <x-difficulty-badge :difficulty="$event->puzzle->difficulty" /></dd></div>
            </dl>

            <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-xs text-slate-300 space-y-1" aria-label="قواعد المنافسة">
                <p class="font-black text-white text-sm mb-1">القواعد</p>
                <p>• محاولة واحدة لكل مشارك، تبدأ من لحظة ضغطك «ابدأ» ويقيس السيرفر زمنها.</p>
                <p>• لا تلميحات ولا رسوم دخول ولا أي أفضلية مشتراة.</p>
                <p>• النقاط: إجابة خاطئة = 0؛ صحيحة = 1000 + مكافأة سرعة حتى 1000 تتناقص حتى {{ $cap }} ثانية.</p>
                <p>• التعادل يُحسم بالسرعة ثم بوقت الإكمال. الترتيب يحسبه السيرفر ولا يُعتمد أي رقم من المتصفح.</p>
            </div>

            @if ($event->rewardRules->isNotEmpty())
                <section class="mt-6" aria-labelledby="rewards-title">
                    <h2 id="rewards-title" class="font-display font-black text-white text-base mb-2">الجوائز</h2>
                    <ul class="grid gap-2 sm:grid-cols-2">
                        @foreach ($event->rewardRules as $rule)
                            <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm flex items-center justify-between gap-2">
                                <span class="text-slate-300">{{ $rule->placementLabel() }}</span>
                                <span class="font-bold text-gold">🎁 {{ $rule->rewardLabel() }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($myGrant)
                        <p class="text-sm text-emerald-400 mt-3">جائزتك: <strong>{{ $myGrant->reward_label }}</strong> (المركز {{ $myGrant->final_rank }})</p>
                    @endif
                </section>
            @endif

            <div class="mt-6">
                @switch($state)
                    @case('guest')
                        <a href="{{ route('login') }}" class="btn-gem !py-2.5 !px-5 text-sm">سجّل الدخول للمشاركة</a>
                        @break
                    @case('register')
                        <form method="POST" action="{{ route('competitions.register', $event) }}">@csrf
                            <button type="submit" class="btn-gem !py-2.5 !px-5 text-sm" aria-label="التسجيل في المنافسة {{ $event->title }}">سجّل في المنافسة</button>
                        </form>
                        @break
                    @case('registered')
                        <div class="flex flex-wrap items-center gap-3">
                            <x-status-badge tone="success" icon="check-circle">أنت مسجَّل — تبدأ في {{ $event->starts_at->format('Y-m-d H:i') }}</x-status-badge>
                            <form method="POST" action="{{ route('competitions.leave', $event) }}">@csrf @method('DELETE')
                                <button type="submit" class="btn-secondary !min-h-9 !px-4 !py-1.5 !text-xs" aria-label="الانسحاب من المنافسة">انسحاب</button>
                            </form>
                        </div>
                        @break
                    @case('play')
                        <form method="POST" action="{{ route('competitions.start', $event) }}">@csrf
                            <button type="submit" class="btn-gem !py-2.5 !px-5 text-sm" aria-label="بدء المحاولة الوحيدة">ابدأ المحاولة (لا يمكن إعادتها)</button>
                        </form>
                        @break
                    @case('resume')
                        <a href="{{ route('competitions.play', $event) }}" class="btn-gem !py-2.5 !px-5 text-sm">تابع محاولتك الجارية</a>
                        @break
                    @case('completed')
                        <x-status-badge tone="success" icon="check-circle">سُجّلت نتيجتك</x-status-badge>
                        @break
                    @case('full')
                        <x-status-badge tone="danger" icon="no-symbol">اكتمل عدد المشاركين</x-status-badge>
                        @break
                    @case('registration_closed')
                        <x-status-badge tone="neutral" icon="lock">التسجيل غير متاح الآن</x-status-badge>
                        @break
                    @case('cancelled')
                        <x-status-badge tone="danger" icon="no-symbol">أُلغيت هذه المنافسة</x-status-badge>
                        @break
                    @default
                        <x-status-badge tone="neutral">انتهت المنافسة</x-status-badge>
                @endswitch
            </div>
        </div>

        @if ($hasTeams)
            <nav class="flex gap-2 mb-3" aria-label="عرض الترتيب">
                <a href="{{ route('competitions.show', $event) }}" class="chip {{ $tab === 'players' ? '!border-amethyst !text-white' : '' }}" @if ($tab === 'players') aria-current="page" @endif>اللاعبون</a>
                <a href="{{ route('competitions.show', [$event, 'tab' => 'teams']) }}" class="chip {{ $tab === 'teams' ? '!border-amethyst !text-white' : '' }}" @if ($tab === 'teams') aria-current="page" @endif>الفرق</a>
            </nav>
        @endif

        @if ($tab === 'teams')
            @include('competitions._teams-standings', ['standings' => $teamStandings])
        @else
            @include('competitions._leaderboard', ['board' => $board, 'event' => $event, 'scope' => $scope])
        @endif
    </div>
@endsection
`````

### `resources/views/creator/dashboard.blade.php`

`sha256: d9cd6ab4fa67703580e8e38949c776d9156c0e7a6e35828eb88f0acd67540d34`

`````blade
@extends('layouts.app')

@section('title', 'استوديو المبدع')

@section('content')
    <div class="space-y-8">
        <x-page-header title="استوديو المبدع" icon="palette" subtitle="أنشئ أحجيات المجتمع وتابع حالتها وأرقام لعبها." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creators.show', $profile->slug) }}" class="btn-secondary !py-2 !px-4 text-sm">صفحتي العامة</a>
                @if (config('creator_series.enabled', true)) {{-- E30: رابط واحد فقط للوحة السلاسل؛ لا أي استعلام إضافي بلوحة الأحجيات. --}}
                    <a href="{{ route('creator.series.index') }}" class="btn-secondary !py-2 !px-4 text-sm">سلاسلي</a>
                @endif
                <a href="{{ route('creator.puzzles.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />أحجية جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400 anim-fade-up">
            أحجيات المجتمع بلا جوائز اقتصادية: لا جواهر ولا نقاط خبرة لك أو للاعبين، وتظهر دائمًا بوسم «من المجتمع».
        </div>

        <section aria-label="أرقام أحجياتك" class="grid grid-cols-2 lg:grid-cols-5 gap-3 anim-fade-up">
            <x-stat-card label="أحجيات منشورة" :value="number_format($stats['published'])" icon="puzzle" />
            <x-stat-card label="لاعبون" :value="number_format($stats['players'])" icon="friends" />
            <x-stat-card label="محاولات" :value="number_format($stats['attempts'])" icon="chart" />
            <x-stat-card label="نسبة الإكمال" :value="$stats['completion_rate'] === null ? '—' : $stats['completion_rate'].'%'" icon="check-circle" />
            <x-stat-card :label="'محاولات آخر '.$stats['recent_days'].' أيام'" :value="number_format($stats['recent_attempts'])" icon="bolt" />
        </section>

        {{-- E29: تفاعل المجتمع مع أعمالك (أرقام مجمَّعة فقط: لا أسماء متابعين ولا مقيِّمين). المتوسط يُخفى تحت الحدّ الأدنى للعيّنة. --}}
        <section aria-label="تفاعل المجتمع" class="grid grid-cols-2 lg:grid-cols-4 gap-3 anim-fade-up">
            <x-stat-card label="متابعون" :value="number_format($stats['followers'])" icon="friends" />
            <x-stat-card label="حفظوا أعمالك" :value="number_format($stats['favorites_received'])" icon="sparkles" />
            <x-stat-card label="تقييمات" :value="number_format($stats['ratings_count'])" icon="chart" />
            <x-stat-card label="متوسط التقييم" :value="$stats['average_rating'] === null ? '—' : number_format($stats['average_rating'], 1).' من 5'" icon="trophy" tone="gold" />
        </section>
        @if ($stats['top_rated_work'])
            <p class="text-sm text-slate-300 anim-fade-up">
                الأعلى تقييمًا: <a href="{{ route('community.puzzles.show', $stats['top_rated_work']['public_id']) }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $stats['top_rated_work']['title'] }}</a>
                <span class="text-slate-400">({{ number_format($stats['top_rated_work']['average'], 1) }} من 5 · {{ number_format($stats['top_rated_work']['count']) }} تقييم)</span>
            </p>
        @endif

        <section aria-label="أعمالي" class="anim-fade-up">
            <x-section-header title="أحجياتي" :href="route('creator.puzzles.index')" linkLabel="عرض الكل" />
            <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 mb-4">
                @foreach (['draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                    <a href="{{ route('creator.puzzles.index', ['status' => $key]) }}" class="chip !py-1.5 !px-3 text-xs">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
                @endforeach
            </nav>

            @forelse ($recent as $work)
                @include('creator.puzzles._row', ['work' => $work])
            @empty
                <x-empty-state icon="palette" title="لم تنشئ أحجية بعد" message="ابدأ بمسودة جديدة، ثم أرسلها للمراجعة لتُنشر للمجتمع." :action="route('creator.puzzles.create')" actionLabel="أحجية جديدة" />
            @endforelse
        </section>
    </div>
@endsection
`````

### `resources/views/creator/public.blade.php`

`sha256: 2cf6b24e6d8a5687eb5f506b8ba0ffd2cd2687ff974c692bff5d666f7b7500c6`

`````blade
@extends('layouts.app')

@section('title', $creator->user->name.' - مبدع')
@section('content_width', 'max-w-5xl')

{{-- E28: صفحة المبدع العامة. اسم + صورة رمزية + نبذة + تاريخ الانضمام كمبدع + أرقام مجمَّعة + أحجياته الظاهرة فقط. بلا بريد/هاتف/حالة إدارية/بيانات خاصة. --}}
@section('content')
    <div class="space-y-6">
        <section class="glass rounded-3xl p-6 md:p-8 flex flex-col sm:flex-row items-center sm:items-start gap-5 anim-fade-up" aria-labelledby="creator-name">
            <x-player-avatar :avatar="$avatar" :frame="$frame" :name="$creator->user->name" size="xl" />
            <div class="min-w-0 text-center sm:text-start">
                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                    <h1 id="creator-name" class="font-display font-black text-2xl md:text-3xl text-white break-words">{{ $creator->user->name }}</h1>
                    <x-status-badge tone="primary" icon="palette">مبدع</x-status-badge>
                </div>
                @if ($creator->bio)
                    <p class="mt-2 text-sm text-slate-300 break-words whitespace-pre-line max-w-2xl">{{ $creator->bio }}</p>
                @endif
                @if ($creator->approved_at)
                    <p class="mt-2 text-xs text-slate-400">مبدع منذ {{ $creator->approved_at->translatedFormat('F Y') }}</p>
                @endif

                {{-- E29: المتابعة. العدد المجمَّع علني؛ هوية المتابعين لا تُعرض. الزر يتبع حالة المشاهد (ضيف/مالك/غير موثَّق/مجمَّد/يتابع/يمكنه المتابعة). --}}
                <div class="mt-3 flex flex-wrap items-center justify-center sm:justify-start gap-3">
                    <span class="text-sm font-bold text-slate-300">{{ number_format($followers) }} {{ $followers === 1 ? 'متابع' : 'متابعين' }}</span>
                    @switch($followState)
                        @case('can_follow')
                            <form method="POST" action="{{ route('creators.follow', $creator->slug) }}">
                                @csrf
                                <button type="submit" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-2"><x-engagement-icon name="user-plus" class="w-4 h-4" />متابعة</button>
                            </form>
                            @break
                        @case('following')
                            <form method="POST" action="{{ route('creators.unfollow', $creator->slug) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary !py-2 !px-5 text-sm inline-flex items-center gap-2" aria-label="إلغاء متابعة هذا المبدع"><x-ui-icon name="check" class="w-4 h-4" />تتابعه</button>
                            </form>
                            @break
                        @case('guest')
                            <a href="{{ route('login') }}" class="btn-secondary !py-2 !px-5 text-sm">سجّل الدخول للمتابعة</a>
                            @break
                        @case('verify')
                            <span class="text-xs font-bold text-gold">وثّق بريدك الإلكتروني لتتمكن من المتابعة.</span>
                            @break
                        @case('frozen')
                            <span class="text-xs font-bold text-gold">حسابك مجمَّد؛ لا يمكن المتابعة حاليًا.</span>
                            @break
                    @endswitch
                </div>
                @if (session('engagement_status'))
                    <p role="status" class="mt-2 text-xs font-bold text-emerald">{{ session('engagement_status') }}</p>
                @endif
            </div>
        </section>

        <section aria-label="أرقام المبدع" class="grid grid-cols-2 {{ $quality ? 'md:grid-cols-3' : '' }} gap-3 anim-fade-up d-1">
            <x-stat-card label="أحجيات منشورة" :value="number_format($published)" icon="puzzle" />
            <x-stat-card label="إجمالي اللاعبين" :value="number_format($plays)" icon="friends" />
            @if ($quality)
                {{-- E29: ملخّص الجودة يظهر فقط ببلوغ حدّ أدنى من التقييمات على أعماله الظاهرة (لا «5.0» من عيّنة صغيرة). --}}
                <x-stat-card label="متوسط تقييم أعماله" :value="number_format($quality['average'], 1).' من 5'" icon="sparkles" tone="gold" :hint="number_format($quality['count']).' تقييم'" />
            @endif
        </section>

        @if ($seriesList->isNotEmpty())
            <section aria-label="سلاسل المبدع" class="anim-fade-up d-2" data-creator-series>
                <x-section-header title="سلاسله" />
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($seriesList as $series)
                        @include('series._card', ['series' => $series])
                    @endforeach
                </div>
            </section>
        @endif

        <section aria-label="أحجيات المبدع" class="anim-fade-up d-2">
            <x-section-header title="أحجياته" />
            @if ($works->isEmpty())
                <x-empty-state icon="puzzle" title="لا أحجيات منشورة حاليًا" message="سيظهر هنا ما ينشره هذا المبدع بعد اعتماده من الإشراف." />
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($works as $work)
                        @include('community._card', ['work' => $work, 'savedIds' => $savedIds ?? []])
                    @endforeach
                </div>
                <div class="flex justify-center mt-6">{{ $works->links() }}</div>
            @endif
        </section>
    </div>
@endsection
`````

### `resources/views/players/show.blade.php`

`sha256: 5ba52a9110c9c6bae456ceda75d6a972d4d714850d415a99801ffb1da804d3a3`

`````blade
@extends('layouts.app')

@section('title', $player->name)
@section('content_width', 'max-w-4xl')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    @php
        use App\Services\Social\FriendRelation;
        $slot = fn (string $s) => $loadout[$s];
        $canMessage = $friendRelation === FriendRelation::Friends;
        $hasIdentity = collect($loadout)->filter()->isNotEmpty();
    @endphp

    <div class="space-y-5">
        {{-- E22: غلاف الملف: الخلفية المجهَّزة = الغلاف، الأفاتار متداخل، والإجراءات منظَّمة (صاحب الملف: تخصيص الهوية + تعديل الحساب | زائر: مراسلة + علاقة الصداقة). --}}
        <x-profile-hero id="profile-hero" class="anim-fade-up"
            :name="$player->name" :avatar="$slot(\App\Models\StoreItem::SLOT_AVATAR)" :frame="$slot(\App\Models\StoreItem::SLOT_FRAME)" :badge="$slot(\App\Models\StoreItem::SLOT_BADGE)"
            :title="$slot(\App\Models\StoreItem::SLOT_TITLE)" :background="$slot(\App\Models\StoreItem::SLOT_BACKGROUND)"
            :level="$stats['current_level']" :team="$team" :friends-count="$friendsCount">
            <x-slot:actions>
                @if ($isOwner)
                    <a href="{{ route('profile.customize') }}" data-cta="customize-identity" class="btn-gem !py-2.5 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <x-ui-icon name="palette" class="w-4 h-4" /> تخصيص الهوية
                    </a>
                    <a href="{{ route('profile.edit') }}" data-cta="edit-account" class="chip !py-2 inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <x-ui-icon name="settings" class="w-4 h-4" /> تعديل الحساب
                    </a>
                @elseif ($friendRelation !== null)
                    @if ($canMessage)
                        <a href="{{ route('messages.direct', $player) }}" data-cta="message" class="btn-gem !py-2.5 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                            <x-ui-icon name="chat" class="w-4 h-4" /> مراسلة
                        </a>
                    @endif
                @endif
            </x-slot:actions>
        </x-profile-hero>

        {{-- E30: تقدير الداعم (شارة مشتقّة من الدعم المؤكَّد غير المجهول؛ ليست عنصر هوية قابلًا للشراء ولا تمنح أي أفضلية). --}}
        @if (! empty($supporter))
            <p class="anim-fade-up" data-supporter-recognition>
                <a href="{{ route('supporters.show', $supporter['slug']) }}" class="inline-flex items-center gap-1.5 rounded-full border border-gold/30 bg-gold/10 text-gold px-3 py-1 text-xs font-black focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                    <x-ui-icon name="sparkles" class="w-3.5 h-3.5" />داعم · {{ $supporter['tier'] }}
                </a>
            </p>
        @endif

        @if (! $isOwner && $friendRelation !== null)
            <section class="glass rounded-2xl px-4 py-3 anim-fade-up" aria-label="العلاقة مع {{ $player->name }}">
                @include('friends._actions', ['other' => $player, 'relation' => $friendRelation, 'withBlock' => true])
            </section>
        @endif

        @if ($isOwner && ! $player->isProfilePublic())
            <div class="flex items-start gap-3 rounded-2xl bg-white/5 border border-white/10 text-slate-300 text-xs font-bold px-4 py-3 anim-fade-up" role="note">
                <x-ui-icon name="lock" class="w-4 h-4 shrink-0 mt-0.5" />
                <p>هذه معاينة لملفك الشخصي - غير مرئي للآخرين حاليًا ({{ $player->isProfilePrivate() ? 'خاص' : 'للأعضاء فقط' }}).
                    <a href="{{ route('profile.edit') }}#privacy" class="text-amethyst underline">تغيير الخصوصية</a></p>
            </div>
        @endif

        @if ($isOwner && ! $hasIdentity)
            <div class="rounded-2xl border border-amethyst/30 bg-amethyst/10 px-5 py-4 flex flex-wrap items-center justify-between gap-3 anim-fade-up">
                <p class="text-sm font-bold text-white min-w-0">ملفك ما زال بهويته الافتراضية. أضف صورة رمزية وغلافًا ولقبًا يعبّر عنك.</p>
                <a href="{{ route('profile.customize') }}" class="chip !py-2 shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">ابدأ التخصيص</a>
            </div>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 anim-fade-up d-1">
            @foreach ([['puzzles_solved', 'أحجية محلولة'], ['challenges_participated', 'تحدٍّ شارك به'], ['gate_qualifications', 'تأهُّل ضمن حملات'], ['achievements_unlocked', 'إنجاز مفتوح']] as [$key, $label])
                <div class="puzzle-card text-center !p-4">
                    <span class="block font-display font-black text-2xl text-white">{{ $stats[$key] }}</span>
                    <span class="text-xs text-slate-400">{{ $label }}</span>
                </div>
            @endforeach
        </div>

        {{-- E18: ملخص المنافسات (أرقام مجمَّعة من نتائج معتمَدة) + رابط خزانة الجوائز والتاريخ --}}
        <section class="glass rounded-2xl p-5 anim-fade-up" aria-labelledby="competitive-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="competitive-title" class="font-display font-black text-lg text-white">المنافسات</h2>
                <a href="{{ route('players.competitive', $player) }}" class="chip text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="خزانة الجوائز وسجل منافسات {{ $player->name }}">خزانة الجوائز والسجل ←</a>
            </div>
            @if ($team)
                <p class="mt-3 text-sm text-slate-300">الفريق: <a href="{{ route('teams.show', $team) }}" class="font-bold text-white hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $team->name }}</a></p>
            @endif
            <dl class="grid grid-cols-3 gap-3 mt-3 text-center">
                <div><dd class="font-display font-black text-xl text-gold">{{ $competitive['events_won'] }}</dd><dt class="text-xs text-slate-400">فوز</dt></div>
                <div><dd class="font-display font-black text-xl text-white">{{ $competitive['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة الأوائل</dt></div>
                <div><dd class="font-display font-black text-xl text-white">{{ $competitive['best_rank'] ?? '—' }}</dd><dt class="text-xs text-slate-400">أفضل مركز</dt></div>
            </dl>
        </section>
    </div>
@endsection
`````

### `resources/views/seasons/show.blade.php`

`sha256: c194c7b8a77b71ff342275e08f03a2a879091493b9f413a749d6be2d4d684486`

`````blade
@extends('layouts.app')

@section('title', $campaign->title)

@section('content')
    <div class="max-w-4xl mx-auto space-y-8">
        @if ($isAdmin && ! $season->is_published)
            <div class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-5 py-3 font-bold text-sm anim-fade-up">
                👁️ وضع المعاينة — غير منشور للجمهور بعد.
            </div>
        @endif

        <x-season-hero
            :season="$season"
            :campaign="$campaign"
            :availability-label="$availabilityLabel"
            :current-step="$currentStep"
            :percentage="$percentage"
            :campaign-available="$campaignAvailable"
        />

        {{-- E30: «برعاية» (بيانات منظَّمة معزولة الفشل؛ بلا رعاة = لا إخراج). --}}
        <x-sponsor-strip type="season" :target-id="$season->id" />

        @auth
            @if ($campaignAvailable && $currentStep)
                <x-mission-card
                    :campaign="$campaign"
                    :step="$currentStep"
                    :mission-number="$currentStepMissionNumber"
                />
            @endif
        @endauth

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up">
            <h2 class="font-display font-black text-xl text-white mb-6">خريطة الرحلة</h2>
            <x-story-map :campaign="$campaign" :stages-view="$stagesView" />
        </div>
    </div>
@endsection
`````

### `routes/console.php`

`sha256: 40efb3746777debf9c69b1ed8acc45df9574f63ac5d93625cf8a7af56e07e3b4`

`````php
<?php

use App\Services\PendingGemReleaseService;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
 * E24: كل المهام المجدولة تمرّ من هنا بنفس القاعدتين:
 *  - withoutOverlapping($minutes): كان الافتراضي 24 ساعة، فانهيار عملية أثناء التشغيل (OOM/نشر/قتل) كان يجمّد مهمة ساعية يومًا كاملًا.
 *    القيم أدناه أطول بكثير من زمن التشغيل الطبيعي (أمان ضد التداخل) وأقصر من اليوم (تعافٍ تلقائي).
 *  - onOneServer(): عند تشغيل المجدول على أكثر من خادم (نشر أفقي) ينفّذ خادم واحد فقط كل تشغيل. يحتاج كاشًا مشتركًا (Redis/قاعدة بيانات)؛ على خادم واحد بلا أي أثر.
 *    يُعطَّل بـ PERF_SCHEDULER_ONE_SERVER=false إن لم يتوفّر كاش مشترك بعد.
 */
$guard = function (Event $event, int $lockMinutes): Event {
    $event->withoutOverlapping($lockMinutes);

    return config('performance.scheduler.one_server', true) ? $event->onOneServer() : $event;
};

// تحويل الجواهر المعلقة إلى متاحة يومياً بعد انتهاء فترة التعليق (config('gems.pending_hold_days'))
// المنطق في PendingGemReleaseService: استعلامات مجمّعة لكل دفعة، والتحرير الفعلي حصرًا عبر GemWalletService.
$guard(Schedule::call(fn () => app(PendingGemReleaseService::class)->run())->daily()->name('release-pending-gems'), 360);

// تذكيرات "ينتهي قريبًا" للمواسم والحملات المستقلة (قراءة فقط) - ساعيًا، وقبل تذكيرات العودة بنفس الساعة (ترتيب التسجيل)
// كي يسبق ينتهي-قريبًا تذكير المهام الأدنى أولوية. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('notifications:dispatch-lifecycle-reminders')->hourly()->name('notifications-lifecycle-reminders'), 120);

// E15: تذكيرات العودة (تحذير السلسلة + مهام اليوم) - ساعيًا. غيابه لا يكسر شيئًا: لا تصل التذكيرات فقط.
$guard(Schedule::command('competitive:process-lifecycle')->hourly()->name('competitive-process-lifecycle'), 120);

// E19: دعوات الفرق، عدّادات الأعضاء، وترتيب الفرق المتأخر (بعد اعتماد المنافسات).
$guard(Schedule::command('teams:process-lifecycle')->hourly()->name('teams-process-lifecycle'), 120);

$guard(Schedule::command('notifications:dispatch-reengagement')->hourly()->name('notifications-reengagement'), 120);

// E15: تنظيف المقروء القديم يوميًا - صحة النظام لا تعتمد عليه.
$guard(Schedule::command('notifications:prune')->daily()->name('notifications-prune'), 360);

// التقاط بدء المواسم الناتج عن مرور الوقت. غيابه لا يكسر شيئًا: البدء بالحفظ الإداري يُلتقَط فورًا بخطافات النماذج.
$guard(Schedule::command('seasons:sync-live-state')->everyFiveMinutes()->name('seasons-sync-live-state'), 30);

// التقاط إتاحة الحملات الناتجة عن مرور الوقت. غيابه لا يكسر شيئًا: الإتاحة بالحفظ الإداري تُلتقَط فورًا بخطاف النموذج.
$guard(Schedule::command('campaigns:sync-availability')->everyFiveMinutes()->name('campaigns-sync-availability'), 30);

// E28: تنظيف صور أحجيات المبدعين اليتيمة أسبوعيًا (ملف بلا نسخة ولا أحجية يرتبط بها، وأقدم من مهلة الأمان). غيابه لا يكسر شيئًا: يبقى الملف اليتيم دون أثر على المنصة.
$guard(Schedule::command('creators:prune-media')->weekly()->name('creators-prune-media'), 120);

// E30: قفل خطط تعزيز مكافآت المنافسات القريبة من البدء (لقطة التمويل قبل البدء؛ المساهمات المتأخرة لا تغيّر جوائز المنافسة الجارية). غيابه = لا تعزيز لتلك المنافسات (فشل مغلق) لا أكثر.
$guard(Schedule::command('sponsorship:lock-event-plans')->everyFiveMinutes()->name('sponsorship-lock-event-plans'), 30);

// E25: نبض المجدول - يكتب الطابع الزمني بالكاش الافتراضي كل دقيقة؛ عمره = صحة المجدول (مركز العمليات، production:check، وعتبة /ready الاختيارية).
// يمرّ من $guard مثل بقية المهام (قاعدة E24: لا مهمة بلا قفل تداخل محدود ولا onOneServer). قفل 5 دقائق يكفي لعملية بطولها ميلي ثوانٍ وينتهي وحده بعد أي انهيار.
// يُسجَّل أخيرًا كي لا يغيّر ترتيب المهام القائمة.
$guard(Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute()->name('scheduler-heartbeat'), 5);
`````

### `routes/web.php`

`sha256: f45f3c5838ef1e97269d0df9e50b3475d9c61ffb8db1cb84fd947153fded46de`

`````php
<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignStepController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\ProfileCustomizationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PuzzleController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SeasonController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// E27: البحث العام والاكتشاف (قراءة فقط، عامة للضيوف). /search/suggest نقطة JSON للاقتراحات الفورية بمحدّد معدّل أضيق للضيف.
Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->middleware('throttle:global-search')->name('search.index');
Route::get('/search/suggest', [\App\Http\Controllers\SearchController::class, 'suggest'])->middleware('throttle:search-suggest')->name('search.suggest');
Route::get('/discover', [\App\Http\Controllers\DiscoverController::class, 'index'])->middleware('throttle:discover')->name('discover.index');

Route::get('/puzzles', [PuzzleController::class, 'index'])->name('puzzles.index');
Route::get('/puzzles/category/{category:slug}', [PuzzleController::class, 'index'])->name('puzzles.category');
Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show'])->name('puzzles.show');

Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

Route::get('/store', [StoreController::class, 'index'])->name('store.index');
Route::get('/store/items/{item:slug}', [StoreController::class, 'show'])->name('store.items.show');

Route::get('/challenges', [ChallengeController::class, 'index'])->name('challenges.index');

// E17: المنافسات العامة (قراءة فقط) - قائمة وصفحة حدث مع الترتيب
Route::get('/competitions', [\App\Http\Controllers\CompetitionController::class, 'index'])->name('competitions.index');
// E19: الفرق. الصفحات الحرفية (create/mine/invitations/leaderboard) تسبق {team:slug} حتى لا يلتقطها المسار الديناميكي (والمعرّفات محجوزة بـconfig/teams.php).
Route::get('/teams', [\App\Http\Controllers\TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/leaderboard', [\App\Http\Controllers\TeamController::class, 'leaderboard'])->name('teams.leaderboard');
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/create', [\App\Http\Controllers\TeamController::class, 'create'])->name('teams.create');
    Route::get('/teams/mine', [\App\Http\Controllers\TeamController::class, 'mine'])->name('teams.mine');
    Route::get('/teams/invitations', [\App\Http\Controllers\TeamMembershipController::class, 'invitations'])->name('teams.invitations');
});
// E20: تحدّيات الفرق (حرفية قبل {team:slug}) وبطولات الفرق. الصفحات الحرفية بين الفرق: challenges محجوز كمعرّف.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'index'])->name('teams.challenges.index');
    Route::get('/teams/challenges/create', [\App\Http\Controllers\TeamChallengeController::class, 'create'])->name('teams.challenges.create');
});
Route::get('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'show'])->name('teams.challenges.show');
Route::get('/team-championships', [\App\Http\Controllers\TeamChampionshipController::class, 'index'])->name('team-championships.index');
Route::get('/team-championships/{championship:slug}', [\App\Http\Controllers\TeamChampionshipController::class, 'show'])->name('team-championships.show');
// E21: الدردشة (مباشرة/فريق/عامة) + عمليات الرسائل. كلها لموثَّق غير مجمَّد؛ التعديلات POST/PATCH/DELETE فقط (لا GET يغيّر حالة). /teams/{team}/chat لا يتعارض مع /teams/{team}.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/messages', [\App\Http\Controllers\Chat\DirectMessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{user:public_id}', [\App\Http\Controllers\Chat\DirectMessageController::class, 'show'])->name('messages.direct');
    Route::post('/messages/{user:public_id}', [\App\Http\Controllers\Chat\DirectMessageController::class, 'send'])->middleware('throttle:chat-dm-send')->name('messages.direct.send');
    Route::get('/teams/{team:slug}/chat', [\App\Http\Controllers\Chat\TeamChatController::class, 'show'])->name('teams.chat');
    Route::post('/teams/{team:slug}/chat', [\App\Http\Controllers\Chat\TeamChatController::class, 'send'])->middleware('throttle:chat-team-send')->name('teams.chat.send');
    Route::get('/community/chat', [\App\Http\Controllers\Chat\CommunityChatController::class, 'show'])->name('community.chat');
    Route::post('/community/chat', [\App\Http\Controllers\Chat\CommunityChatController::class, 'send'])->middleware('throttle:chat-global-send')->name('community.chat.send');

    Route::get('/chat/threads/{thread:public_id}/messages', [\App\Http\Controllers\Chat\ChatMessageController::class, 'older'])->name('chat.messages');
    Route::post('/chat/threads/{thread:public_id}/read', [\App\Http\Controllers\Chat\ChatMessageController::class, 'read'])->middleware('throttle:chat-read')->name('chat.read');
    Route::patch('/chat/messages/{message}', [\App\Http\Controllers\Chat\ChatMessageController::class, 'update'])->middleware('throttle:chat-edit')->name('chat.messages.update');
    Route::delete('/chat/messages/{message}', [\App\Http\Controllers\Chat\ChatMessageController::class, 'destroy'])->middleware('throttle:chat-edit')->name('chat.messages.destroy');
    Route::post('/chat/messages/{message}/report', [\App\Http\Controllers\Chat\ChatMessageController::class, 'report'])->middleware('throttle:chat-report')->name('chat.messages.report');
    Route::post('/chat/messages/{message}/hide', [\App\Http\Controllers\Chat\ChatMessageController::class, 'hide'])->middleware('throttle:chat-moderate')->name('chat.messages.hide');
    Route::post('/chat/messages/{message}/restore', [\App\Http\Controllers\Chat\ChatMessageController::class, 'restore'])->middleware('throttle:chat-moderate')->name('chat.messages.restore');
});
Route::get('/teams/{team:slug}', [\App\Http\Controllers\TeamController::class, 'show'])->name('teams.show');

// E18: قاعة الأمجاد (حرفية قبل {event:slug} حتى لا يلتقطها المسار الديناميكي)
Route::get('/competitions/hall-of-fame', [\App\Http\Controllers\CompetitionHallOfFameController::class, 'index'])->name('competitions.hall-of-fame');
Route::get('/competitions/{event:slug}', [\App\Http\Controllers\CompetitionController::class, 'show'])->name('competitions.show');
Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');

Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');

Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
Route::get('/campaigns/{campaign:slug}', [CampaignController::class, 'show'])->name('campaigns.show');

Route::get('/seasons', [SeasonController::class, 'index'])->name('seasons.index');
Route::get('/seasons/{season:slug}', [SeasonController::class, 'show'])->name('seasons.show');

Route::get('/players/{user:public_id}', [PlayerProfileController::class, 'show'])->name('players.show');
Route::get('/players/{user:public_id}/competitive', [\App\Http\Controllers\PlayerCompetitiveController::class, 'show'])->name('players.competitive');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:registration');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset-submit')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:email-verification'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification')->name('verification.send');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:account-sensitive')->name('profile.update');

    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:password-confirm');

    Route::middleware(['verified', 'account.active'])->group(function () {
        // E19: إجراءات الفرق (POST/PATCH/DELETE فقط عدا صفحة الإدارة). التفويض من الدور الفعلي بالقاعدة، لا من الطلب.
        // {user:public_id} ليس ابنًا للفريق بعلاقة (Team::users) فيُعطَّل الربط المحصور هناك والخدمة تتحقق من العضوية؛ أما {invitation}/{joinRequest} فمحصوران بعلاقتي الفريق: 404 تلقائي لمورد فريق آخر.
        Route::post('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'store'])->middleware('throttle:team-challenge-create')->name('teams.challenges.store');
        Route::post('/teams/challenges/{challenge:public_id}/accept', [\App\Http\Controllers\TeamChallengeController::class, 'accept'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.accept');
        Route::post('/teams/challenges/{challenge:public_id}/decline', [\App\Http\Controllers\TeamChallengeController::class, 'decline'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.decline');
        Route::delete('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'cancel'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.cancel');
        Route::put('/teams/challenges/{challenge:public_id}/roster', [\App\Http\Controllers\TeamChallengeController::class, 'roster'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.roster');
        Route::post('/teams/challenges/{challenge:public_id}/start', [\App\Http\Controllers\TeamChallengePlayController::class, 'start'])->middleware('throttle:competitive-play')->name('teams.challenges.start');
        Route::post('/teams/challenges/{challenge:public_id}/submit', [\App\Http\Controllers\TeamChallengePlayController::class, 'submit'])->middleware('throttle:competitive-play')->name('teams.challenges.submit');
        Route::post('/teams', [\App\Http\Controllers\TeamController::class, 'store'])->middleware('throttle:team-create')->name('teams.store');
        Route::post('/teams/invitations/{invitation:public_id}/accept', [\App\Http\Controllers\TeamMembershipController::class, 'accept'])->middleware('throttle:team-actions')->name('teams.invitations.accept');
        Route::post('/teams/invitations/{invitation:public_id}/decline', [\App\Http\Controllers\TeamMembershipController::class, 'decline'])->middleware('throttle:team-actions')->name('teams.invitations.decline');
        Route::post('/teams/{team:slug}/join', [\App\Http\Controllers\TeamMembershipController::class, 'join'])->middleware('throttle:team-join')->name('teams.join');
        Route::post('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'requestJoin'])->middleware('throttle:team-join')->name('teams.requests.store');
        Route::delete('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'cancelRequest'])->middleware('throttle:team-actions')->name('teams.requests.cancel');
        Route::delete('/teams/{team:slug}/membership', [\App\Http\Controllers\TeamMembershipController::class, 'leave'])->middleware('throttle:team-actions')->name('teams.leave');
        Route::get('/teams/{team:slug}/manage', [\App\Http\Controllers\TeamManagementController::class, 'manage'])->name('teams.manage');
        Route::patch('/teams/{team:slug}', [\App\Http\Controllers\TeamManagementController::class, 'update'])->middleware('throttle:team-actions')->name('teams.update');
        Route::post('/teams/{team:slug}/deactivate', [\App\Http\Controllers\TeamManagementController::class, 'deactivate'])->middleware('throttle:team-actions')->name('teams.deactivate');
        Route::post('/teams/{team:slug}/invitations/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'invite'])->middleware('throttle:team-invite')->withoutScopedBindings()->name('teams.invitations.store');
        Route::delete('/teams/{team:slug}/invitations/{invitation:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'cancelInvitation'])->middleware('throttle:team-actions')->name('teams.invitations.cancel');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/accept', [\App\Http\Controllers\TeamManagementController::class, 'acceptRequest'])->middleware('throttle:team-actions')->name('teams.requests.accept');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/decline', [\App\Http\Controllers\TeamManagementController::class, 'declineRequest'])->middleware('throttle:team-actions')->name('teams.requests.decline');
        Route::delete('/teams/{team:slug}/members/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'removeMember'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.remove');
        Route::patch('/teams/{team:slug}/members/{user:public_id}/role', [\App\Http\Controllers\TeamManagementController::class, 'changeRole'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.role');
        Route::post('/teams/{team:slug}/transfer/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'transfer'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.transfer');

        Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('puzzles.attempt');
        Route::post('/puzzles/{puzzle}/hint', [PuzzleController::class, 'hint'])
            ->middleware('throttle:puzzle-hint')->name('puzzles.hint');

        Route::post('/puzzles/{puzzle}/session', [GameSessionController::class, 'store'])
            ->middleware('throttle:game-session-start')->name('game-sessions.start');
        Route::post('/game-sessions/{session}/reveal', [GameSessionController::class, 'reveal'])
            ->middleware('throttle:game-session-reveal')->name('game-sessions.reveal');

        Route::post('/challenges/{challenge}/join', [ChallengeController::class, 'join'])
            ->middleware('throttle:game-actions')->name('challenges.join');

        Route::get('/campaigns/{campaign:slug}/steps/{step}', [CampaignStepController::class, 'show'])
            ->name('campaigns.steps.show');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/complete', [CampaignStepController::class, 'complete'])
            ->middleware('throttle:game-actions')->name('campaigns.steps.complete');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/reflect', [CampaignStepController::class, 'reflect'])
            ->middleware('throttle:game-actions')->name('campaigns.steps.reflect');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/attempt', [CampaignStepController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('campaigns.steps.attempt');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/session', [CampaignStepController::class, 'startSession'])
            ->middleware('throttle:game-session-start')->name('campaigns.steps.session');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

        Route::get('/redemption', [RedemptionController::class, 'index'])->name('redemption.index');
        Route::get('/redemption/create', [RedemptionController::class, 'create'])->name('redemption.create');
        Route::post('/redemption', [RedemptionController::class, 'store'])
            ->middleware('throttle:redemption')->name('redemption.store');

        Route::post('/store/items/{item}/purchase', [StoreController::class, 'purchase'])
            ->middleware('throttle:store-purchase')->name('store.items.purchase');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

        Route::get('/profile/customize', [ProfileCustomizationController::class, 'edit'])->name('profile.customize');
        Route::post('/profile/cosmetics/{item}/equip', [ProfileCustomizationController::class, 'equip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.equip');
        Route::delete('/profile/cosmetics/{slot}/unequip', [ProfileCustomizationController::class, 'unequip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.unequip');
        Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
            ->middleware('throttle:account-sensitive')->name('profile.visibility.update');

        // E12: صفحة التقدُّم - قراءة فقط، لا Mutation عبر أي مسار هنا.
        Route::get('/progress', [\App\Http\Controllers\PlayerProgressionController::class, 'show'])->name('progress.show');
        Route::get('/quests', [\App\Http\Controllers\PlayerQuestsController::class, 'show'])->name('quests.show');

        // E15: مركز الإشعارات. GET = عرض فقط. كل تعديل POST/PUT/DELETE بـCSRF وبملكية صارمة داخل المتحكّم.
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{id}/open', [\App\Http\Controllers\NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->whereUuid('id')->name('notifications.destroy');

        // E16: الأصدقاء والحظر. GET = عرض فقط. كل تعديل POST/PATCH/DELETE بـCSRF؛ الطرف الآخر بـpublic_id من المسار، والطرف الحالي
        // دائمًا المستخدم المصادَق (لا user_id من النموذج). إرسال الطلبات بتحديد معدّل ثنائي.
        Route::prefix('friends')->name('friends.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FriendsController::class, 'index'])->name('index');
            Route::get('/search', [\App\Http\Controllers\PlayerSearchController::class, 'index'])->middleware('throttle:player-search')->name('search');
            Route::patch('/settings', [\App\Http\Controllers\FriendsController::class, 'updateSettings'])->middleware('throttle:friend-actions')->name('settings');

            Route::post('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'store'])->middleware('throttle:friend-requests')->name('requests.store');
            Route::post('/requests/{user:public_id}/accept', [\App\Http\Controllers\FriendRequestController::class, 'accept'])->middleware('throttle:friend-actions')->name('requests.accept');
            Route::post('/requests/{user:public_id}/decline', [\App\Http\Controllers\FriendRequestController::class, 'decline'])->middleware('throttle:friend-actions')->name('requests.decline');
            Route::delete('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'cancel'])->middleware('throttle:friend-actions')->name('requests.cancel');
            Route::delete('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'destroy'])->middleware('throttle:friend-actions')->name('blocks.destroy');
            Route::post('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'store'])->middleware('throttle:friend-actions')->name('blocks.store');
            Route::delete('/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'removeFriend'])->middleware('throttle:friend-actions')->name('remove');
        });

        // E17-A: تحدّيات الأصدقاء. الخصم بـpublic_id من المسار والتحدي بـpublic_id (ULID)، والمصادَق هو الطرف دائمًا. GET = عرض فقط.
        Route::prefix('friends/challenges')->name('friends.challenges.')->group(function () {
            $c = \App\Http\Controllers\FriendChallengeController::class;

            Route::get('/', [$c, 'index'])->name('index');
            Route::get('/new/{user:public_id}', [$c, 'create'])->name('create');
            Route::post('/new/{user:public_id}', [$c, 'store'])->middleware('throttle:friend-challenges')->name('store');
            Route::get('/{challenge:public_id}', [$c, 'show'])->name('show');
            Route::post('/{challenge:public_id}/accept', [$c, 'accept'])->middleware('throttle:friend-actions')->name('accept');
            Route::post('/{challenge:public_id}/decline', [$c, 'decline'])->middleware('throttle:friend-actions')->name('decline');
            Route::delete('/{challenge:public_id}', [$c, 'cancel'])->middleware('throttle:friend-actions')->name('cancel');
            Route::post('/{challenge:public_id}/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::post('/{challenge:public_id}/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });

        // E17-B/C: تسجيل ولعب المنافسات. الحدث بـslug من المسار؛ لا score/winner/rank من العميل.
        Route::prefix('competitions/{event:slug}')->name('competitions.')->group(function () {
            $c = \App\Http\Controllers\CompetitionPlayController::class;

            Route::post('/register', [$c, 'register'])->middleware('throttle:competitive-register')->name('register');
            Route::delete('/register', [$c, 'leave'])->middleware('throttle:competitive-register')->name('leave');
            Route::post('/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::get('/play', [$c, 'play'])->name('play');
            Route::post('/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });


    });





});
// E14 (بند 605): تحويل نقر إعلان - عام، معرِّف Creative فقط، لا رابط من الاستعلام.
Route::get('/ads/click/{creative}', \App\Http\Controllers\AdClickController::class)
    ->middleware('throttle:ad-click')->name('ads.click');

// E28: منظومة المبدعين وأحجيات المجتمع (ملف مسارات مستقل بالسياق web نفسه).
require __DIR__.'/creator.php';
require __DIR__.'/engagement.php';
// E30: الداعمون والرعاة (قراءة عامة) وسلاسل المبدعين.
require __DIR__.'/sponsorship.php';
require __DIR__.'/series.php';
`````

### `tests/Feature/DiscoveryE27/E27PerformanceTest.php`

`sha256: ee611e29e4cd6aa714b31f12bc15d407f4821e104ed8a32604b8d9f9dcbb3020`

`````php
<?php

require_once __DIR__.'/_support.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأداء. المبدأ: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1)، السقوف مقيسة (لا مخمَّنة)، الرائج مخزَّن ولا يمسح أسخن جدول،
| والبحث بالبادئة قابل لاستعمال الفهرس. SQLite لا يقيس زمن MySQL؛ لذا الاختبارات هنا بنيوية (عدد، خطة، شكل SQL) لا زمنية.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_grow')) {
    /** يضيف $count عنصرًا من كل نوع قابل للبحث، كلها تطابق «نمط». */
    function e27_grow(int $from, int $to): void
    {
        $category = \App\Models\PuzzleCategory::query()->first() ?? e27_category('نمط');

        for ($i = $from + 1; $i <= $to; $i++) {
            e27_puzzle('نمط أحجية '.$i, ['puzzle_category_id' => $category->id]);
            e27_campaign('نمط حملة '.$i);
            e27_season('نمط موسم '.$i);
            e27_user(['name' => 'نمط لاعب '.$i]);
            e19Team(null, ['name' => 'نمط فريق '.$i]);
            e17Event(['title' => 'نمط منافسة '.$i]);
        }
    }

    /** @return array<string, callable> نقاط الدخول المقيسة. */
    function e27_endpoints($test, $viewer): array
    {
        return [
            'suggest guest' => fn () => $test->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'suggest player' => fn () => $test->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'نمط']))->assertOk(),
            'search all (player)' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search all (guest)' => fn () => $test->get(route('search.index', ['q' => 'نمط']))->assertOk(),
            'search players page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'players']))->assertOk(),
            'search puzzles page' => fn () => $test->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk(),
            'discover guest' => fn () => $test->get(route('discover.index'))->assertOk(),
            'discover player' => fn () => $test->actingAs($viewer)->get(route('discover.index'))->assertOk(),
        ];
    }
}

test('P1: query counts do not grow with the data - every endpoint runs the same number of queries on 3 rows per table as on 40 (no N+1)', function () {
    $viewer = e27_user();
    e27_grow(0, 3);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();   // تسخين الكاش (الرائج، مجسّ الحجم)
    }
    $small = array_map(fn ($run) => e27_queries($run), $endpoints);

    e27_grow(3, 40);
    $large = array_map(fn ($run) => e27_queries($run), $endpoints);

    expect($large)->toBe($small);
});

test('P2: measured query ceilings hold on a warm cache - search and discovery add a bounded, small number of queries over a plain page', function () {
    $viewer = e27_user();
    e27_grow(0, 12);
    $endpoints = e27_endpoints($this, $viewer);
    foreach ($endpoints as $run) {
        $run();
    }
    $baseGuest = e27_queries(fn () => $this->get('/terms')->assertOk());
    $basePlayer = e27_queries(fn () => $this->actingAs($viewer)->get('/terms')->assertOk());
    $count = fn (string $name) => e27_queries($endpoints[$name]);

    // JSON بلا إطار صفحة: مصدر لكل نوع (9 منذ E30: +1 لسلاسل المجتمع بعد +1 لأحجيات المجتمع بـE28) + تحميل التصنيف + الحظر للمسجَّل. مقيس 12 (ضيف) و12 (لاعب).
    expect($count('suggest guest'))->toBeLessThanOrEqual(12)->and($count('suggest player'))->toBeLessThanOrEqual(13);
    // صفحات كاملة: فوق قاعدة الصفحة العادية بنفس المشاهد (مقيس: ضيف +7، لاعب +7 أو أقل).
    expect($count('search all (guest)') - $baseGuest)->toBeLessThanOrEqual(12)
        ->and($count('search all (player)') - $basePlayer)->toBeLessThanOrEqual(12)
        ->and($count('search players page') - $basePlayer)->toBeLessThanOrEqual(5)
        ->and($count('search puzzles page') - $basePlayer)->toBeLessThanOrEqual(5);
    // الاكتشاف: قسم بكل استعلام محدود (مقيس 22). +1 منذ E30: قسم «سلاسل جديدة ممّن تتابعهم» (استعلام واحد محدود للمسجَّل؛ المنفرد من الكاش) - ويُقاس هنا بجلسة اللاعب الباقية من النقطة السابقة.
    expect($count('discover guest'))->toBeLessThanOrEqual(27)->and($count('discover player'))->toBeLessThanOrEqual(29);
});

test('P3: a cold cache costs a few extra queries once - then nothing until it expires', function () {
    $viewer = e27_user();
    e27_grow(0, 5);
    $endpoints = e27_endpoints($this, $viewer);

    e27_cold();
    $cold = e27_queries($endpoints['suggest guest']);
    $warm = e27_queries($endpoints['suggest guest']);
    expect($cold)->toBeLessThanOrEqual(19)->and($warm)->toBeLessThan($cold)->and($cold - $warm)->toBeLessThanOrEqual(9);   // مجسّ الحجم لكل نوع مرة واحدة (9 أنواع منذ E30)

    e27_cold();
    expect(e27_queries($endpoints['discover guest']))->toBeLessThanOrEqual(30);
});

test('P4: the cold trending computation is logarithmic in the number of attempts and the warm one is free', function () {
    $puzzle = e27_puzzle('أحجية القياس');
    $users = array_map(fn () => e27_user(), range(1, 20));
    $rows = 400;
    foreach (range(1, $rows) as $n) {
        e27_attempt($users[$n % 20], $puzzle, $n % 7 === 0, $n <= 200 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    $cold = e27_queries(fn () => e27_trending()->puzzleScores());
    $warm = e27_queries(fn () => e27_trending()->puzzleScores());

    // min + max + قيمة الأخير + الاستعلام الرئيسي + خطوات البحث الثنائي (≤ log2(صفوف)) + هامش خطوة.
    expect($cold)->toBeLessThanOrEqual(5 + (int) ceil(log($rows, 2)))->and($warm)->toBe(0);
    expect(e27_trending()->puzzleScores()[$puzzle->id]['players'])->toBe(20);   // النتيجة صحيحة: الصفوف القديمة (قبل 12 يومًا) لم تُحتسب
});

test('P5: the trending query is a bounded range on the primary key - never a full scan of the attempts table', function () {
    $puzzle = e27_puzzle('أحجية الخطة');
    $users = array_map(fn () => e27_user(), range(1, 10));
    foreach (range(1, 120) as $n) {
        e27_attempt($users[$n % 10], $puzzle, false, $n <= 60 ? now()->subDays(12) : now()->subHours(3), $n);
    }
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_trending()->puzzleScores();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $main = collect($log)->first(fn ($q) => str_contains($q['query'], 'COUNT(DISTINCT'));
    expect($main)->not->toBeNull();
    // شكل الاستعلام: مدى على المعرّف + حد زمني + محاولات مستقلة فقط.
    expect($main['query'])->toContain('"a"."id" >= ?')->toContain('"a"."created_at" >= ?')->toContain('"a"."context_type" is null');

    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$main['query'], $main['bindings']))->pluck('detail')->all();
    $attempts = array_values(array_filter($plan, fn ($line) => str_contains($line, ' a ') || str_starts_with($line, 'SCAN a') || str_starts_with($line, 'SEARCH a')));

    expect($attempts)->not->toBe([]);
    foreach ($attempts as $line) {
        expect($line)->toStartWith('SEARCH a')->not->toContain('SCAN');   // SEARCH بفهرس/مفتاح مع شرط rowid>? = مدى، لا مسح كامل
        expect($line)->toContain('rowid>?');
    }
});

test('P6: beyond the partial-match limit the filter is a bare-column prefix LIKE - nothing wraps the column, so a name/title index can serve it', function () {
    config(['search.partial_match_max_id' => 0]);
    e27_grow(0, 1);   // صف في كل جدول مصدر: الجدول الفارغ رخيص المسح فيبقى بالوضع الكامل عمدًا، والمقصود هنا الوضع الكبير
    e27_cold();

    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_suggest($this, 'نمط', e27_user());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $tiered = collect($log)->filter(fn ($q) => str_contains($q['query'], 'search_tier') && ! str_contains($q['query'], 'from "team_championships"') && ! str_contains($q['query'], 'from "creator_puzzles"') && ! str_contains($q['query'], 'from "creator_series"'));   // لا بطولات ولا أحجيات/سلاسل مجتمع بالبيانات: جداول فارغة (وضعها الكبير مفحوص في CreatorE28 وCreatorSeriesE30)
    expect($tiered->count())->toBe(6);
    foreach ($tiered as $q) {
        $where = substr($q['query'], (int) strpos($q['query'], ' from "'));

        expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->not->toContain('like ?  ')->and($where)->toMatch('/like \? escape \'!\'/');
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding) && str_contains($binding, '%')) {
                expect($binding)->not->toStartWith('%');   // لا «%x» في الوضع الكبير: كل نمط بادئة
            }
        }
    }

    $puzzles = $tiered->first(fn ($q) => str_contains($q['query'], 'from "puzzles"'));
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$puzzles['query'], $puzzles['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('puzzles_active_title_search_index');   // SQLite لا يحوّل LIKE إلى مدى (غير حساس للحالة)؛ MySQL بترتيب utf8mb4 يفعل — يُتحقق منه بـEXPLAIN محليًا (docs)
});

test('P7: the two search indexes exist, are reversible, and nothing was added to the hot attempts table or the small tables', function () {
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    foreach (Schema::getIndexes('puzzle_attempts') as $index) {
        expect($index['columns'])->not->toContain('created_at');   // قرار E24/E27: لا فهرس زمني على أسخن جدول
    }
    foreach (['campaigns', 'seasons', 'competitive_events', 'team_championships', 'teams'] as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            expect($index['name'])->not->toContain('search');
        }
    }

    $migration = require base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php');
    $migration->down();
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeFalse()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeFalse();
    $migration->up();
    $migration->up();   // آمن لإعادة التشغيل
    expect(Schema::hasIndex('users', 'users_name_search_index'))->toBeTrue()->and(Schema::hasIndex('puzzles', 'puzzles_active_title_search_index'))->toBeTrue();

    $source = file_get_contents(base_path('database/migrations/2026_10_19_000001_add_e27_search_indexes.php'));
    expect(substr_count($source, '->index('))->toBe(2)->and($source)->not->toContain('Schema::create(')->not->toContain('DB::')->not->toContain('->fullText(');
});

test('P8: responses are bounded however many rows match - suggest nine items, a page twelve, depth five, groups five', function () {
    e27_grow(0, 40);
    $viewer = e27_user();

    $json = e27_suggest($this, 'نمط', $viewer);
    expect(collect($json['groups'])->sum(fn ($g) => count($g['items'])))->toBeLessThanOrEqual(9)
        ->and(collect($json['groups'])->every(fn ($g) => count($g['items']) <= 3))->toBeTrue()
        ->and(strlen(json_encode($json)))->toBeLessThan(6000);

    $all = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط']))->assertOk()->getContent());
    foreach ($all->query('//main//section[@aria-label]') as $section) {
        expect($all->query('.//ul/li', $section)->length)->toBeLessThanOrEqual(5);
    }

    $page = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles']))->assertOk()->getContent());
    expect($page->query('//main//ul/li')->length)->toBe(12)->and($page->query('//a[@rel="next"]')->length)->toBe(1);

    $last = uiXpath($this->actingAs($viewer)->get(route('search.index', ['q' => 'نمط', 'type' => 'puzzles', 'page' => 5]))->assertOk()->getContent());
    expect($last->query('//a[@rel="next"]')->length)->toBe(0)->and($last->query('//main//ul/li')->length)->toBeLessThanOrEqual(12);
});

test('P9: input size is bounded before it reaches the database - a ten-thousand-character term never produces a long binding', function () {
    $viewer = e27_user();
    e27_puzzle('أحجية');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($viewer)->get(route('search.index', ['q' => str_repeat('أحجية ', 2000)]))->assertOk();
    $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => str_repeat('ب', 10000)]))->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $longest = 0;
    foreach ($log as $q) {
        if (! str_contains($q['query'], 'search_tier')) {
            continue;
        }
        foreach ($q['bindings'] as $binding) {
            if (is_string($binding)) {
                $longest = max($longest, mb_strlen($binding));
            }
        }
    }

    expect($longest)->toBeGreaterThan(0)->and($longest)->toBeLessThanOrEqual(70);   // 64 حرفًا كحد أقصى + محارف النمط
});

test('P10: recommendations cost the same queries for a short history as for a long one, and the history read is capped', function () {
    $category = e27_category('تاريخ طويل');
    $short = e27_user();
    $long = e27_user();
    e27_pool($category, 12, 'POOL');

    foreach (e27_pool($category, 3, 'S') as $i => $puzzle) {
        e27_attempt($short, $puzzle, $i === 0, now()->subDays(2));
    }
    foreach (e27_pool($category, 60, 'L') as $i => $puzzle) {
        e27_attempt($long, $puzzle, $i % 3 === 0, now()->subDays(2));
    }
    e27_trending()->puzzleScores();   // تسخين

    $countShort = e27_queries(fn () => e27_reco($short));
    $countLong = e27_queries(fn () => e27_reco($long));
    expect($countLong)->toBe($countShort)->and($countLong)->toBeLessThanOrEqual(14);

    config(['search.recommendations.history_cap' => 5]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    e27_reco($long);
    $history = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'group by "puzzle_id"'));
    DB::disableQueryLog();

    expect($history['query'])->toContain('limit 5');
});
`````

### `tests/Feature/DiscoveryE27/E27SecurityTest.php`

`sha256: 50542b8f7db784ef09d0c569cbf74e8956ea082d9db583fae965e39bac1c9577`

`````php
<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
| E27-E - الأمان: تحديد المعدّل (ضيف/مسجَّل، مفتاحه المستخدم لا IP فقط)، حقن SQL، التعداد، البيانات الحساسة، المخرجات، وتدقيق ساكن للشيفرة الجديدة.
| الاستعلامات الخبيثة هنا مدخلات اختبار محلية على قاعدة اختبار؛ لا شبكة ولا هدف حقيقي.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e27_limits')) {
    /** @return list<\Illuminate\Cache\RateLimiting\Limit> */
    function e27_limits(string $name, ?User $user = null, string $ip = '203.0.113.7'): array
    {
        $request = Request::create('/search', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => $user);
        $limit = (RateLimiter::limiter($name))($request);

        return is_array($limit) ? array_values($limit) : [$limit];
    }

    /** @return list<string> ملفات الشيفرة الجديدة التي تُدقَّق ساكنًا (بلا الاختبارات). */
    function e27_code_files(): array
    {
        return array_merge(
            e26_php_files(['app/Services/Search', 'app/Services/Discovery']),
            [base_path('app/Http/Controllers/SearchController.php'), base_path('app/Http/Controllers/DiscoverController.php'), base_path('app/Http/Requests/SearchRequest.php')],
        );
    }
}

test('S1: the limiters are keyed per user for players and per IP for guests, with a per-minute cap and a short burst cap', function () {
    $a = e27_user();
    $b = e27_user();

    [$guest] = e27_limits('global-search');
    [$userA] = e27_limits('global-search', $a);
    [$userB] = e27_limits('global-search', $b);
    expect([$guest->maxAttempts, $guest->decaySeconds])->toBe([20, 60])
        ->and([$userA->maxAttempts, $userA->decaySeconds])->toBe([60, 60])
        ->and($userA->key)->not->toBe($userB->key)->and($userA->key)->not->toBe($guest->key)
        ->and($guest->key)->not->toBe(e27_limits('global-search', null, '203.0.113.8')[0]->key);

    [$gMinute, $gBurst] = e27_limits('search-suggest');
    [$uMinute, $uBurst] = e27_limits('search-suggest', $a);
    expect([$gMinute->maxAttempts, $gMinute->decaySeconds, $gBurst->maxAttempts, $gBurst->decaySeconds])->toBe([40, 60, 10, 2])
        ->and([$uMinute->maxAttempts, $uMinute->decaySeconds, $uBurst->maxAttempts, $uBurst->decaySeconds])->toBe([120, 60, 16, 2])
        ->and($uMinute->key)->not->toBe($uBurst->key);

    [$discoverGuest] = e27_limits('discover');
    [$discoverUser] = e27_limits('discover', $a);
    expect([$discoverGuest->maxAttempts, $discoverGuest->decaySeconds])->toBe([90, 60])->and($discoverUser->key)->not->toBe($discoverGuest->key);
});

test('S2: routes are GET-only, carry their limiter, and expose no write verb for search or discovery', function () {
    $expected = ['search.index' => 'global-search', 'search.suggest' => 'search-suggest', 'discover.index' => 'discover'];

    foreach ($expected as $name => $limiter) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()->and($route->methods())->toBe(['GET', 'HEAD'])->and($route->gatherMiddleware())->toContain('throttle:'.$limiter);
    }

    $bad = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => preg_match('#^(search|discover)(/|$)#', $r->uri()) && array_diff($r->methods(), ['GET', 'HEAD']) !== []);
    expect($bad->count())->toBe(0);
});

test('S3: a guest hammering the search page is stopped at the 21st request while a signed-in player on the same address is unaffected', function () {
    $statuses = e26_hammer($this, 'get', route('search.index', ['q' => 'نص']), 21);

    expect(e26_first_429($statuses))->toBe(21);
    $this->actingAs(e27_user())->get(route('search.index', ['q' => 'نص']))->assertOk();
});

test('S4: the suggest burst limits stop rapid typing floods - guests at the 11th request in two seconds, players at the 17th - with Retry-After', function () {
    $guest = e26_hammer($this, 'get', route('search.suggest', ['q' => 'نص']), 12, [], true);
    expect(e26_first_429($guest))->toBe(11);
    $this->getJson(route('search.suggest', ['q' => 'نص']))->assertStatus(429)->assertHeader('Retry-After');

    $player = e27_user();
    $this->actingAs($player);
    $statuses = e26_hammer($this, 'get', route('search.suggest', ['q' => 'نص']), 18, [], true);
    expect(e26_first_429($statuses))->toBe(17);

    // لاعب آخر من العنوان نفسه لا يتأثر (المفتاح هو المستخدم لا العنوان).
    $this->actingAs(e27_user())->getJson(route('search.suggest', ['q' => 'نص']))->assertOk();
});

test('S5: injection-shaped input is inert on every search entry point - no SQL error, no leaked internals, no table touched', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    e27_puzzle('Ordinary Puzzle');
    e27_user(['name' => 'Ordinary Person']);
    e19Team(null, ['name' => 'Ordinary Team']);
    $viewer = e27_user();
    $counts = fn () => [Puzzle::count(), User::count(), DB::table('teams')->count(), DB::table('puzzle_attempts')->count()];
    $before = $counts();
    $payloads = ["' OR '1'='1", "'; DROP TABLE puzzles; --", '" OR ""="', "1) UNION SELECT email,password FROM users --", "%' ESCAPE '!' OR 1=1 --", '%', '_', '___', '\\', '!%', "\0", "Robert'); DROP TABLE users;--", '١٢٣٪', '${7*7}', '{{7*7}}', '<?php phpinfo(); ?>'];

    foreach ($payloads as $payload) {
        foreach ([[route('search.index'), ['q' => $payload]], [route('search.index'), ['q' => $payload, 'type' => 'players']], [route('search.index'), ['q' => 'نص', 'type' => $payload]], [route('search.suggest'), ['q' => $payload]]] as [$url, $query]) {
            foreach ([$this, $this->actingAs($viewer)] as $caller) {
                $response = $caller->get($url.'?'.http_build_query($query), ['Accept' => str_contains($url, 'suggest') ? 'application/json' : 'text/html']);
                $body = $response->getContent();

                expect($response->status())->toBeLessThan(500)->and($body)->not->toContain('SQLSTATE')->not->toContain('Illuminate\\')->not->toContain('Stack trace');
            }
        }
    }

    expect($counts())->toBe($before);
    expect(e27_titles(e27_suggest($this, '%')))->toBe([])->and(e27_titles(e27_suggest($this, '___')))->toBe([])->and(e27_titles(e27_suggest($this, "' OR '1'='1")))->toBe([]);
});

test('S6: names cannot be enumerated - wildcards and short probes find no players, and a hidden player looks exactly like a missing one', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    e27_user(['name' => 'Samir Public']);
    e27_user(['name' => 'Samir Hidden', 'profile_visibility' => User::VISIBILITY_PRIVATE]);
    $viewer = e27_user(['name' => 'Searcher']);

    foreach (['%', '%%%', '___', '_', 'S', 'Sa', '%am'] as $probe) {
        expect(e27_group(e27_suggest($this, $probe), 'players'))->toBe([], "guest probe {$probe}");
    }
    expect(e27_group(e27_suggest($this, 'Sa', $viewer), 'players'))->toBe(['Samir Public'])->and(e27_group(e27_suggest($this, '%', $viewer), 'players'))->toBe([]);

    $hidden = e27_suggest($this, 'Samir Hidden', $viewer);
    $missing = e27_suggest($this, 'Zzzzz Nobody', $viewer);
    expect(array_diff_key($hidden, ['query' => 1, 'all_url' => 1]))->toBe(array_diff_key($missing, ['query' => 1, 'all_url' => 1]));
});

test('S7: no secret ever reaches a response - emails, password hashes, tokens, answers, hints and answer hashes stay out of JSON and HTML', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $secretUser = e27_user(['name' => 'سلمان الظاهر', 'email' => 'salman-secret@example.test']);
    $puzzle = e27_puzzle('أحجية سلمان', ['answer_raw' => 'إجابة-سرية-فريدة', 'hint' => 'تلميح-سري-فريد']);
    $viewer = e27_user();
    $needles = ['salman-secret', 'example.test', (string) $secretUser->password, (string) $secretUser->remember_token, 'إجابة-سرية-فريدة', 'تلميح-سري-فريد', (string) $puzzle->answer_hash, 'two_factor', 'api_token'];

    $bodies = [
        $this->getJson(route('search.suggest', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'سلمان']))->getContent(),
        $this->actingAs($viewer)->get(route('search.index', ['q' => 'سلمان', 'type' => 'players']))->getContent(),
        $this->actingAs($viewer)->get(route('discover.index'))->getContent(),
        $this->get(route('discover.index'))->getContent(),
    ];

    foreach ($bodies as $body) {
        foreach (array_filter($needles) as $needle) {
            // JSON يهرّب الحروف غير اللاتينية (\uXXXX): نفحص الصيغتين كي لا يكون الفحص فارغًا للأسرار العربية.
            expect($body)->not->toContain($needle)->not->toContain(trim(json_encode($needle), '"'));
        }
    }
    expect(e27_titles(json_decode($bodies[0], true)))->toContain('أحجية سلمان')->toContain('سلمان الظاهر');
});

test('S8: player results are privacy-shaped at the query level - the SELECT list is a short allow-list with no email, password, token or wallet column', function () {
    e27_user(['name' => 'ندى الاختبار']);
    $viewer = e27_user();
    $sql = collect(e27_sql(fn () => $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'ندى']))->assertOk()))->filter(fn ($q) => str_contains($q, 'search_tier'));

    expect($sql->count())->toBeGreaterThanOrEqual(7);   // نوع واحد لكل مصدر
    foreach ($sql as $query) {
        preg_match('/^select (.*?), CASE WHEN/s', $query, $m);
        $columns = $m[1] ?? '';

        expect($columns)->not->toBe('')->not->toContain('*')->not->toMatch('/email|password|remember_token|two_factor|api_token|phone|balance|wallet|answer/i');
        // الجدول الرئيسي للاستعلام (أول from) لا أي ذِكر لـusers داخل استعلام فرعي: مصدر سلاسل E30 يفحص حساب المبدع بـEXISTS ولا يُرجع أعمدة users.
        preg_match('/ from "([a-z_]+)"/', $query, $main);
        if (($main[1] ?? null) === 'users') {
            expect($columns)->toBe('"users"."id", "users"."name", "users"."public_id", "users"."profile_visibility"');
        }
    }
});

test('S9: no trace of searches is stored - no new tables, no query-bearing cache keys, no logging in the search code', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (['search_queries', 'search_logs', 'search_events', 'search_analytics', 'discovery_events', 'recommendation_cache', 'user_search_history'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    $migrations = array_filter(glob(base_path('database/migrations/*.php')), fn ($f) => preg_match('/e27|search/i', basename($f)));
    expect($migrations)->toHaveCount(1);
    foreach ($migrations as $file) {
        expect(file_get_contents($file))->not->toContain('Schema::create(')->not->toContain('->fullText(');
    }

    $sentinel = 'مصطلح-فريد-للبحث-'.bin2hex(random_bytes(3));
    $this->getJson(route('search.suggest', ['q' => $sentinel]))->assertOk();
    $this->get(route('search.index', ['q' => $sentinel]))->assertOk();
    $store = \Illuminate\Support\Facades\Cache::getStore();
    $property = new ReflectionProperty($store, 'storage');
    $property->setAccessible(true);
    expect(json_encode(array_keys($property->getValue($store))))->not->toContain('مصطلح-فريد');

    foreach (e27_code_files() as $file) {
        expect(preg_match('/\bLog::|\blogger\(|\breport\(|\binfo\(|\bwarning\(|\berror_log\(|\bdump\(|\bdd\(|\bvar_dump\(|\bprint_r\(/', e26_php_code($file)))->toBe(0, e26_relative($file));
    }
});

test('S10: query values only travel as bindings - the raw SQL fragments in the search sources are built from validated identifiers and constants', function () {
    $source = e26_php_code(base_path('app/Services/Search/Sources/EloquentSource.php'));

    // الأسطر التي تبني نص SQL لا تذكر مدخل المستخدم؛ القيم تذهب في مصفوفات الربط فقط.
    preg_match_all('/\$(?:rawLike|foldLike|tierSql)\s*=\s*[^;]+;/', $source, $assignments);
    expect($assignments[0])->not->toBe([]);
    foreach ($assignments[0] as $line) {
        expect($line)->not->toContain('$query')->not->toContain('$term')->not->toContain('request(')->not->toContain('$_GET');
    }
    expect($source)->toContain('TextFold::identifier(')->toContain('TextFold::sqlFold(');

    foreach (e27_code_files() as $file) {
        if (str_ends_with($file, 'EloquentSource.php') || str_ends_with($file, 'TextFold.php')) {
            continue;
        }
        // أي استدعاء خام آخر: نص ثابت بلا استيفاء متغيّرات.
        preg_match_all('/(?:whereRaw|orWhereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::raw)\(\s*(["\'])(.*?)\1/s', e26_php_code($file), $raw, PREG_SET_ORDER);
        foreach ($raw as $match) {
            expect($match[2])->not->toMatch('/\$[a-zA-Z_]|\{\$/', e26_relative($file).': '.$match[2]);
        }
    }

    foreach (['app/Services/Search/TextFold.php'] as $relative) {
        expect(e26_php_code(base_path($relative)))->toContain('/^[a-z_][a-z0-9_]*(\\.[a-z_][a-z0-9_]*)?$/');
    }
});

test('S11: the search and discovery code is read-only - no create/insert/update/delete/save/increment and no raw writes', function () {
    foreach (e27_code_files() as $file) {
        $code = e26_php_code($file);

        expect(preg_match('/->(?:create|insert|insertGetId|insertOrIgnore|update|updateOrCreate|upsert|delete|forceDelete|save|saveQuietly|increment|decrement|touch|forceFill|fill)\(|::(?:create|insert|upsert)\(|DB::(?:insert|update|delete|statement|unprepared)\(/', $code))->toBe(0, e26_relative($file));
    }
});

test('S12: trending and recommendations are separated from every reward system - no economy, XP, quest, streak, achievement, redemption, odds or score class is referenced', function () {
    foreach (e27_code_files() as $file) {
        $code = e26_php_code($file);

        expect(preg_match('/\b(?:Wallet\w*|Currency\w*|Xp\w*|Redemption\w*|Quest\w*|Streak\w*|Achievement\w*|Purchase\w*|Economy\w*|\w*RewardService|\w*Odds\w*|\w*Qualification\w*|Sponsor\w*|AdPlacement\w*|Payment\w*|Stripe\w*)\b/', $code, $m))->toBe(0, e26_relative($file).' -> '.($m[0] ?? ''));
    }
});

test('S13: puzzle search is title-only - the search layer never mentions answers, hints, choices, solution data, game config or the prompt', function () {
    foreach (e26_php_files(['app/Services/Search']) as $file) {
        expect(preg_match('/answer_hash|answer_raw|answer_normal|\bhint\b|solution_data|choices|game_config|\bprompt\b|image_path/i', e26_php_code($file)))->toBe(0, e26_relative($file));
    }
});

test('S14: no external search engine, vector store or AI dependency was added - composer and npm manifests stay clean', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $npm = json_decode((string) file_get_contents(base_path('package.json')), true);
    $deps = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? [], $npm['dependencies'] ?? [], $npm['devDependencies'] ?? []));

    foreach ($deps as $dependency) {
        expect($dependency)->not->toMatch('/scout|meilisearch|algolia|typesense|elastic|opensearch|solr|sphinx|tntsearch|fuse\.?js|lunr|flexsearch|minisearch|pgvector|qdrant|pinecone|weaviate|openai|anthropic|langchain|transformers/i');
    }
    expect(config('search.driver'))->toBe('database');
});

test('S15: the search backend is an abstraction - an unknown driver falls back to the database one instead of breaking the site', function () {
    config(['search.driver' => 'some-future-engine']);
    app()->forgetInstance(\App\Services\Search\Contracts\SearchBackend::class);

    expect(app(\App\Services\Search\Contracts\SearchBackend::class))->toBeInstanceOf(\App\Services\Search\DatabaseSearchBackend::class);
});

test('S16: self-play and fake accounts cannot manufacture trending - thirty unverified accounts and one account with a thousand attempts rank nothing', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $target = e27_puzzle('أحجية مستهدفة');
    foreach (range(1, 30) as $i) {
        e27_attempt(e27_user(['email_verified_at' => null]), $target, true, now()->subHour());
    }
    $bot = e27_user();
    DB::table('puzzle_attempts')->insert(array_map(fn ($n) => ['user_id' => $bot->id, 'puzzle_id' => $target->id, 'attempt_number' => $n, 'is_correct' => false, 'used_hint' => false, 'context_type' => null, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()], range(1, 1000)));

    expect(e27_trending()->puzzleScores())->toBe([]);
    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    expect(uiXpath($html)->query('//section[@data-discover-section="trending"]')->length)->toBe(0);
});
`````

### `tests/Feature/EngagementE29/E29PerformanceTest.php`

`sha256: 5b60890b7787744a2de5beda3daad72a122e0626771697be2eba9c8455b23e80`

`````php
<?php

use App\Models\CreatorProfile;
use App\Services\Engagement\CreatorFollowService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

/*
| E29 - ميزانيات الاستعلام. الأرقام المثبَّتة قياسات فعلية (لا تقديرية) مع هامش صغير؛ تتبع الحالة لا حجم البيانات:
| ما يهم بالاختبارات: **تساوي** العدد بين حجمين مختلفين (لا N+1) + سقف مطلق يلتقط أي انجراف كبير مستقبلًا.
*/

test('AA11-105: the puzzle page query count is bounded and independent of rating volume', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);
    $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk();   // تسخين
    $few = e27_queries(fn () => $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk());

    e29_ratings($work, array_fill(0, 25, 4));
    $many = e27_queries(fn () => $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk());
    $this->app['auth']->forgetGuards();
    $guest = e27_queries(fn () => $this->get(route('community.puzzles.show', $work->public_id))->assertOk());

    // القياس الفعلي: لاعب 15 (E28 كان يقيس 17 بحالة محلولة)، ضيف 5 = رقم E28 نفسه: التفاعل لا يضيف استعلامًا واحدًا للضيف (المتوسط والعدّادات أعمدة العمل نفسه).
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(17)->and($guest)->toBe(5);
});

test('AA11-106: the favorites list is bounded (same queries for 2 and 12 items)', function () {
    $user = e16User();
    $creator = e28_creator();
    foreach (range(1, 2) as $i) { e29_favorite($user, e29_work($creator)); }
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();
    $two = e27_queries(fn () => $this->get(route('favorites.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_favorite($user, e29_work(e28_creator())); }
    $twelve = e27_queries(fn () => $this->get(route('favorites.index'))->assertOk());

    expect($twelve)->toBe($two)->and($twelve)->toBeLessThanOrEqual(14);
});

test('AA11-107: the following feed is bounded (same queries for 2 and 12 items)', function () {
    $user = e16User();
    foreach (range(1, 2) as $i) { e29_work($c = e28_creator()); e29_follow($user, $c); }
    $this->actingAs($user)->get(route('following.index'))->assertOk();
    $two = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_work($c = e28_creator()); e29_follow($user, $c); }
    $twelve = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    expect($twelve)->toBe($two)->and($twelve)->toBeLessThanOrEqual(14);
});

test('AA11-108: the creator page is bounded for guests and signed-in viewers', function () {
    $creator = e28_creator();
    $slug = e29_profile($creator)->slug;
    e29_work($creator);
    $viewer = e16User();
    $this->get(route('creators.show', $slug))->assertOk();
    $guestOne = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());
    $this->actingAs($viewer)->get(route('creators.show', $slug))->assertOk();
    $userOne = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());

    foreach (range(1, 10) as $i) { e29_favorite($viewer, e29_work($creator)); }
    $userMany = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());
    $this->app['auth']->forgetGuards();

    // الضيف 8 = رقم E28 (7) + استعلام E30 واحد محدود لقسم «سلاسله» (قاعدة الظهور المركزية، LIMIT ثابت) - لا استعلام تفاعل للضيف؛ المسجَّل +استعلامان فقط فوق الجرس/التقدّم: حالة المتابعة (exists) + مفضلة البطاقات (دفعة واحدة).
    expect($userMany)->toBe($userOne)->and($userMany)->toBeLessThanOrEqual(18)->and($guestOne)->toBe(8);
});

test('AA11-109: 1 vs 12 community cards cost the same queries (no N+1), saved state is one batched query', function () {
    $creator = e28_creator();
    $viewer = e16User();
    e29_favorite($viewer, e29_work($creator));
    $this->actingAs($viewer)->get(route('community.puzzles.index'))->assertOk();
    $one = e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());

    foreach (range(1, 11) as $i) { $w = e29_work(e28_creator()); $i % 2 ? e29_favorite($viewer, $w) : e29_ratings($w, [5, 4]); }
    $twelve = e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());

    expect($twelve)->toBe($one)->and($twelve)->toBeLessThanOrEqual(16);
});

test('AA11-110: the discover page query count is bounded with engagement signals present', function () {
    $viewer = e16User();
    $creator = e28_creator();
    e29_follow($viewer, $creator);
    e29_work($creator);
    foreach (range(1, 6) as $i) { $w = e29_work(e28_creator()); e29_ratings($w, [5, 5, 4, 5, 5]); e29_favorite($viewer, $w); }
    e27_cold();
    $this->actingAs($viewer)->get(route('discover.index'))->assertOk();
    $warm = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());
    e27_cold();
    $cold = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());

    // القياس: دافئ 27، بارد 34 لمستخدم مسجَّل لديه متابعة ومفضلة وأعمال مقيَّمة. (+3 فوق E28: الأعلى تقييمًا، من أتابعهم، تفضيلات المشاهد.)
    expect($warm)->toBeLessThanOrEqual(30)->and($cold)->toBeLessThanOrEqual(38);
});

test('AA11-111: the star control and engagement panel render for a player without extra per-card work', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect($html)->toContain('id="engagement"')->toContain('role="radiogroup"')->toContain('name="rating"')->toContain('احفظ في المفضلة')->toContain('أضف إلى مجموعة');
    expect(substr_count($html, 'type="radio" name="rating"'))->toBe(5);
});
`````

### `tests/Feature/UiE22/AdminUiTest.php`

`sha256: 511d4ce94cb1220a965dd5ebca0209735da1546a7f3982d19545e5c92f69f2fb`

`````php
<?php

require_once __DIR__.'/UiTestHelpers.php';

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

function uiSuperAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    return $admin;
}

const UI_ADMIN_GROUP_ORDER = [
    'الأحجيات', 'الحملات', 'المنافسات', 'الفرق', 'المتجر', 'الاقتصاد', 'الجواهر والاستبدال', 'التقدُّم',
    'المشاركة', 'الإعلانات والرعايات', 'الرعاية والدعم', 'الإشراف', 'الأمان', 'التحليلات والنظام', 'إدارة الوصول',
];

test('A1: the admin sidebar lists its navigation groups in the logical E22 order', function () {
    $html = $this->actingAs(uiSuperAdmin())->get('/admin')->assertOk()->getContent();
    preg_match_all('/data-group-label="([^"]+)"/u', $html, $m);

    expect($m[1])->toBe(UI_ADMIN_GROUP_ORDER);
});

test('A2: the analytics and operations centers render in their new group, and ordinary players are refused', function () {
    $admin = uiSuperAdmin();

    foreach (['/admin/analytics', '/admin/operations'] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }

    $player = User::factory()->create();
    $player->assignRole('player');

    foreach (['/admin/analytics', '/admin/operations', '/admin/chat-message-reports', '/admin/teams'] as $url) {
        $this->actingAs($player)->get($url)->assertForbidden();
    }
});

test('A3: regrouped resources keep their permissions and only change their navigation group', function () {
    expect(\App\Filament\Resources\ChatMessageReportResource::getNavigationGroup())->toBe('الإشراف')
        ->and(\App\Filament\Resources\ChatMuteResource::getNavigationGroup())->toBe('الإشراف')
        ->and(\App\Filament\Resources\TeamResource::getNavigationGroup())->toBe('الفرق')
        ->and(\App\Filament\Resources\TeamChallengeResource::getNavigationGroup())->toBe('الفرق')
        ->and(\App\Filament\Resources\TeamChampionshipResource::getNavigationGroup())->toBe('الفرق')
        ->and(\App\Filament\Resources\CompetitiveEventResource::getNavigationGroup())->toBe('المنافسات')
        ->and(\App\Filament\Pages\AnalyticsCenter::getNavigationGroup())->toBe('التحليلات والنظام')
        ->and(\App\Filament\Pages\OperationsCenter::getNavigationGroup())->toBe('التحليلات والنظام');

    $role = Role::create(['name' => 'bare-entry', 'guard_name' => 'web']);
    $role->givePermissionTo('admin.access');
    $bare = User::factory()->create();
    $bare->assignRole('bare-entry');

    $this->actingAs($bare)->get('/admin')->assertOk();

    foreach (['/admin/chat-message-reports', '/admin/teams', '/admin/competitive-events', '/admin/analytics', '/admin/operations'] as $url) {
        $this->actingAs($bare)->get($url)->assertForbidden();
    }
});
`````


---

## C) MIGRATIONS (جديدة فقط — لا تعديل على أي ترحيل قائم)

### `database/migrations/2026_10_22_000001_create_sponsorship_tables.php`

`sha256: fe505bfd0efbb8b946db950fc70298d0d7bef1aa7a91b2d3e71575d97ae4aa99`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E30 (A) - منظومة الرعاة والداعمين وتمويل المكافآت. ستة جداول بحدّ أدنى لما يلزم فعلًا (أسماء جديدة كي لا تتصادم مع محرّك إعلانات E14: sponsor_campaigns/sponsor_creatives):
 *
 *  supporter_tiers              مستويات الداعمين (تُدار من قاعدة البيانات، تُشتقّ منها مستويات الداعم من صافي دعمه المؤكَّد؛ لا قيمة مخزَّنة بالملف).
 *  supporter_profiles           ملف داعم (فرد/مؤسسة) علني اختياريًا؛ user_id اختياري (فريد). لا تفاصيل دفع هنا أبدًا.
 *  sponsorship_programs         برنامج رعاية لهدف رسمي (platform/season/campaign/competitive_event) + خطة تعزيز المكافآت.
 *  sponsorship_program_roles    دور الداعم ضمن البرنامج (supporter/sponsor/main_sponsor). main_marker فريد ⇒ راعٍ رئيسي واحد لكل برنامج بقيد قاعدة البيانات.
 *  sponsorship_contributions    المساهمة: المبلغ الأصلي + عملته + المعادل المحاسبي المسجَّل عند التأكيد. المبالغ أعداد صحيحة (وحدة صغرى)؛ لا floats.
 *  sponsorship_reward_bonuses   دفتر مكافآت الرعاية الممنوحة فعلًا (مفتاح مصدر فريد ⇒ لا منح مزدوج) بلقطة (الأساس/النقاط الأساسية/المكافأة/البرنامج).
 *  sponsorship_event_reward_plans  خطة تعزيز منافسة مقفلة قبل بدئها (لقطة التمويل والنسبة): المساهمات المتأخرة لا تغيّرها.
 *
 * لا حذف تتابعي للتاريخ المالي: contributions → profiles/programs restrictOnDelete. أسماء الفهارس صريحة (حدّ MySQL: 64 حرفًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supporter_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 40)->unique();
            $table->unsignedBigInteger('minimum_accounting_amount_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('badge_key', 24)->default('supporter');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'minimum_accounting_amount_minor'], 'supporter_tiers_active_min_index');
        });

        Schema::create('supporter_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('type', 16)->default('individual');
            $table->string('display_name', 120);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('website_url')->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_public')->default(true);
            $table->boolean('anonymous_by_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'is_public', 'type'], 'supporter_profiles_public_index');
        });

        Schema::create('sponsorship_programs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('goal_amount_minor')->nullable();
            $table->char('accounting_currency', 3);
            $table->boolean('show_public_amounts')->default(false);
            $table->boolean('show_goal_progress')->default(false);

            // خطة تعزيز المكافآت (منطقها كله في SponsorshipRewardService). 100 bps = 1%.
            $table->boolean('reward_boost_enabled')->default(false);
            $table->unsignedBigInteger('funding_step_minor')->nullable();
            $table->unsignedInteger('boost_per_step_bps')->default(0);
            $table->unsignedInteger('max_boost_bps')->default(0);
            $table->timestamp('reward_plan_locked_at')->nullable();
            $table->foreignId('reward_plan_locked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'target_type', 'target_id'], 'sponsorship_programs_target_index');
            $table->index(['target_type', 'target_id'], 'sponsorship_programs_target_lookup_index');
            $table->index(['status', 'reward_boost_enabled', 'target_type', 'target_id'], 'sponsorship_programs_boost_index');
        });

        Schema::create('sponsorship_program_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->cascadeOnDelete();
            $table->foreignId('supporter_profile_id')->constrained('supporter_profiles')->cascadeOnDelete();
            $table->string('role', 16)->default('supporter');
            // = معرّف البرنامج للراعي الرئيسي وإلا NULL: فريد ⇒ راعٍ رئيسي واحد لكل برنامج (NULL لا تتعارض).
            $table->unsignedBigInteger('main_marker')->nullable()->unique('sponsorship_roles_main_unique');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sponsorship_program_id', 'supporter_profile_id'], 'sponsorship_roles_pair_unique');
            $table->index('supporter_profile_id', 'sponsorship_roles_supporter_index');
        });

        Schema::create('sponsorship_contributions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('supporter_profile_id')->constrained('supporter_profiles')->restrictOnDelete();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();

            $table->unsignedBigInteger('original_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('accounting_amount_minor')->nullable(); // يُسجَّل عند التأكيد
            $table->char('accounting_currency', 3)->nullable();

            $table->string('status', 16)->default('pending');
            $table->boolean('is_anonymous')->default(false);
            $table->string('reference', 120)->nullable();
            $table->string('provider', 32)->nullable();            // مستقبلًا: مزوّد دفع خارجي (غير مستعمل الآن)
            $table->string('external_reference', 120)->nullable(); // مستقبلًا: مرجع المزوّد
            $table->text('notes')->nullable();                     // ملاحظات إدارية خاصة: لا تُعرض للعامة أبدًا

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_note', 255)->nullable();
            $table->timestamps();

            // لوحات الصدارة/المجاميع: صافي المؤكَّد بتجميع واحد مغطّى بالفهرس.
            $table->index(['status', 'supporter_profile_id', 'is_anonymous', 'accounting_amount_minor'], 'sponsorship_contrib_leaderboard_index');
            $table->index(['sponsorship_program_id', 'status', 'supporter_profile_id'], 'sponsorship_contrib_program_index');
            $table->index(['status', 'confirmed_at'], 'sponsorship_contrib_period_index');
            $table->index(['supporter_profile_id', 'status'], 'sponsorship_contrib_supporter_index');
            $table->unique(['provider', 'external_reference'], 'sponsorship_contrib_provider_ref_unique');
        });

        Schema::create('sponsorship_reward_bonuses', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 160)->unique();
            $table->foreignId('sponsorship_program_id')->constrained('sponsorship_programs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context_type', 24)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->unsignedBigInteger('base_amount');
            $table->unsignedInteger('boost_bps');
            $table->unsignedBigInteger('calculated_bonus_amount'); // قبل قصّ السقف اليومي
            $table->unsignedBigInteger('bonus_amount');            // ما مُنح فعلًا
            $table->unsignedBigInteger('final_amount');            // الأساس + المكافأة
            $table->unsignedBigInteger('funding_snapshot_minor');
            $table->unsignedBigInteger('currency_transaction_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sponsorship_program_id', 'created_at'], 'sponsorship_bonus_program_index');
            $table->index(['user_id', 'created_at'], 'sponsorship_bonus_user_index');
        });

        Schema::create('sponsorship_event_reward_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitive_event_id')->unique('sponsorship_event_plans_event_unique')->constrained('competitive_events')->cascadeOnDelete();
            $table->foreignId('sponsorship_program_id')->nullable()->constrained('sponsorship_programs')->nullOnDelete();
            $table->unsignedBigInteger('funding_snapshot_minor')->default(0);
            $table->unsignedBigInteger('funding_step_minor')->default(0);
            $table->unsignedInteger('boost_bps')->default(0);
            $table->timestamp('locked_at');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // المستويات الافتراضية (قابلة للتعديل كليًا من الإدارة). العتبات بالوحدة الصغرى لعملة المحاسبة.
        $now = now();
        foreach ([
            ['داعم', 'supporter', 500, 10, 'supporter'],
            ['فضي', 'silver', 5000, 20, 'silver'],
            ['ذهبي', 'gold', 25000, 30, 'gold'],
            ['بلاتيني', 'platinum', 100000, 40, 'platinum'],
            ['ماسي', 'diamond', 500000, 50, 'diamond'],
        ] as [$name, $slug, $min, $sort, $badge]) {
            DB::table('supporter_tiers')->insertOrIgnore([
                'name' => $name, 'slug' => $slug, 'minimum_accounting_amount_minor' => $min, 'sort_order' => $sort,
                'badge_key' => $badge, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_event_reward_plans');
        Schema::dropIfExists('sponsorship_reward_bonuses');
        Schema::dropIfExists('sponsorship_contributions');
        Schema::dropIfExists('sponsorship_program_roles');
        Schema::dropIfExists('sponsorship_programs');
        Schema::dropIfExists('supporter_profiles');
        Schema::dropIfExists('supporter_tiers');
    }
};
`````

### `database/migrations/2026_10_22_000002_add_sponsor_snapshot_to_competitive_reward_grants.php`

`sha256: 2993e2d6ff304f842fd9b9b0bdcbafdc48d1500d05fa363378f4adee524c90f2`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E30 (A) - لقطة تعزيز الرعاية على جائزة المنافسة وقت التخطيط (بعد اعتماد المنافسة): البرنامج + النقاط الأساسية + المكافأة المحسوبة.
 * القيم تأتي من خطة المنافسة المقفلة قبل بدئها (sponsorship_event_reward_plans) لا من تمويل لحظي؛ الجائزة الأساسية (amount) لا تُمسّ إطلاقًا.
 * أعمدة اسمية بلا FK (لقطة تاريخية: حذف/تعديل برنامج لا يمحو ما مُنح). الصفوف القديمة = 0 (لا تعزيز).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitive_reward_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('sponsor_program_id')->nullable()->after('amount');
            $table->unsignedInteger('sponsor_bonus_bps')->default(0)->after('sponsor_program_id');
            $table->unsignedInteger('sponsor_bonus_amount')->default(0)->after('sponsor_bonus_bps');
        });
    }

    public function down(): void
    {
        Schema::table('competitive_reward_grants', function (Blueprint $table) {
            $table->dropColumn(['sponsor_program_id', 'sponsor_bonus_bps', 'sponsor_bonus_amount']);
        });
    }
};
`````

### `database/migrations/2026_10_22_000003_create_creator_series_tables.php`

`sha256: 3d2730b527f3a7b3f1806cef2eef243e15e3c37de23642f0f9095fd7019d4301`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E30 (B) - سلاسل المبدعين: قائمة مرتّبة من أحجيات مجتمع منشورة للمبدع نفسه. ثلاثة جداول بحدّ أدنى:
 *
 *  creator_series            هوية السلسلة الثابتة (public_id + slug) + لقطة النشر الحالية + الإخفاء/الأرشفة. لا عمود is_visible مخزَّن: الظهور يُشتقّ حيًّا
 *                            (منشورة ∧ غير مخفية/مؤرشفة ∧ مبدع معتمَد وحسابه سليم ∧ كل عناصر النسخة المنشورة ظاهرة الآن) في CreatorSeriesVisibilityService،
 *                            فإخفاء أحجية أو تعليق مبدع يخفي السلسلة فورًا ويستعيدها فورًا بلا مصالحة ولا حالة قديمة.
 *  creator_series_revisions  كل نسخة: العنوان/الوصف/الغلاف + سير العمل (draft → pending_review → approved/changes_requested/rejected) + المراجعة.
 *                            تعديل سلسلة منشورة = نسخة جديدة تُراجع؛ المنشورة تبقى كما هي حتى اعتماد الجديدة (لا تعديل صامت).
 *  creator_series_items      عناصر النسخة بترتيب صريح: unique(النسخة، العمل) و unique(النسخة، الموضع).
 *
 * لا اقتصاد: لا أعمدة جواهر/XP/جوائز. أعمدة latest/published_revision_id بلا FK (دورة FK)، والخدمات تملك سلامتها. أسماء الفهارس صريحة (حدّ MySQL 64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_series', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100)->unique();

            $table->unsignedBigInteger('latest_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();

            // لقطة النشر (null قبل أول نشر): بطاقات البحث والاكتشاف تقرؤها من هذا الجدول وحده بلا ضمّ للنسخ.
            $table->string('published_title')->nullable();
            $table->string('published_cover_path')->nullable();
            $table->unsignedSmallInteger('published_items_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 24)->nullable();
            $table->text('hidden_note')->nullable();
            $table->timestamps();

            $table->index(['creator_profile_id', 'id'], 'creator_series_owner_index');
            $table->index(['creator_profile_id', 'published_at'], 'creator_series_creator_public_index');
            $table->index(['hidden_at', 'archived_at', 'published_at'], 'creator_series_public_index');
            $table->index('published_title', 'creator_series_title_index');
        });

        Schema::create('creator_series_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_series_id')->constrained('creator_series')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');
            $table->string('status', 20)->default('draft');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['creator_series_id', 'revision_number'], 'creator_series_revisions_number_unique');
            $table->index(['status', 'submitted_at'], 'creator_series_revisions_queue_index');
        });

        Schema::create('creator_series_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_series_revision_id')->constrained('creator_series_revisions')->cascadeOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained('creator_puzzles')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['creator_series_revision_id', 'creator_puzzle_id'], 'creator_series_items_work_unique');
            $table->unique(['creator_series_revision_id', 'position'], 'creator_series_items_position_unique');
            $table->index('creator_puzzle_id', 'creator_series_items_work_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_series_items');
        Schema::dropIfExists('creator_series_revisions');
        Schema::dropIfExists('creator_series');
    }
};
`````

الجداول: `supporter_tiers`، `supporter_profiles`، `sponsorship_programs`، `sponsorship_program_roles`، `sponsorship_contributions`، `sponsorship_reward_bonuses`، `sponsorship_event_reward_plans`؛ أعمدة لقطة على `competitive_reward_grants` (`sponsor_program_id`، `sponsor_bonus_bps`، `sponsor_bonus_amount`)؛ و`creator_series`، `creator_series_revisions`، `creator_series_items`. كل `down()` يعكس ما أضافه. لا بيانات تُحذف ولا ترحيل قديم يُعدَّل.


---

## D) ENV / CONFIG — الأسطر المضافة (كلها اختيارية بافتراضيات آمنة، معلَّق عليها بـ`#`)

```dotenv
# E30 - الرعاة والداعمون وتمويل المكافآت + سلاسل المبدعين (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/sponsorship-supporters-e30.md و docs/creator-series-e30.md.
# سلاسل المبدعين بلا أي سلطة اقتصادية بأي إعداد ولا يطالها تعزيز الرعاية: لا مفتاح بيئة لذلك.
# SPONSORSHIP_ENABLED=true                   # تعطيل شامل لواجهات الرعاية والتعزيز (التاريخ المالي يبقى كما هو)
# SPONSORSHIP_ACCOUNTING_CURRENCY=USD        # عملة المحاسبة الموحَّدة للمجاميع والمستويات والتعزيز
# SPONSORSHIP_MAX_AMOUNT_MINOR=1000000000    # سقف المبلغ الواحد بالوحدة الصغرى (حماية من أخطاء الإدخال)
# SPONSORSHIP_LOGO_DISK=public               # قرص شعارات الداعمين (JPEG/PNG/WEBP فقط)
# SPONSORSHIP_LOGO_MAX_KB=1024               # أقصى حجم شعار بالكيلوبايت
# SPONSORSHIP_CACHE_TTL=120                  # عمر لقطات اللوحات العامة بالثواني (معرّفات ومجاميع فقط؛ الظهور يُفحص حيًّا)
# SPONSORSHIP_DISPLAY_TTL=60                 # عمر لقطة شريط الرعاة بالثواني
# SPONSORSHIP_REWARD_BOOST_ENABLED=true      # تعزيز مكافآت المحتوى الرسمي فقط (false = لا تعزيز إطلاقًا)
# SPONSORSHIP_HARD_MAX_BOOST_BPS=5000        # السقف الصلب للتعزيز بالنقاط الأساسية (5000 = 50%) لا يتجاوزه أي برنامج
# SPONSORSHIP_EVENT_LOCK_LEAD_MINUTES=60     # قفل خطة تعزيز المنافسة قبل بدئها بهذه المدة (دقائق)
# SPONSORSHIP_NOTIFICATIONS_ENABLED=true     # إشعارات الداعمين (تأكيد الدعم/تغيّر المستوى/بدء البرنامج)
# CREATOR_SERIES_ENABLED=true                # تعطيل سلاسل المبدعين (404) دون مساس بأحجيات المجتمع
# CREATOR_SERIES_MIN_ITEMS=2                 # أقل عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_ITEMS=25                # أقصى عدد عناصر للسلسلة
# CREATOR_SERIES_MAX_PER_CREATOR=50          # سقف سلاسل المبدع الواحد غير المؤرشفة
# CREATOR_SERIES_MAX_PENDING=5               # سقف السلاسل قيد المراجعة معًا لكل مبدع
# CREATOR_SERIES_DISCOVERY_TTL=120           # عمر كاش معرّفات «أحدث السلاسل» (الظهور يُفحص حيًّا بكل عرض)
# CREATOR_SERIES_ANALYTICS_TTL=600           # عمر لقطة تحليلات سلاسل المبدع (أرقام مجمَّعة بلا هويات)
# CREATOR_SERIES_NOTIFY_FOLLOWERS=true       # إشعار المتابعين عند نشر سلسلة جديدة (دفعات، idempotent)
# CREATOR_SERIES_NOTIFY_ON_REVISION=true     # إشعار المتابعين عند اعتماد نسخة جديدة لسلسلة منشورة سابقًا
```

ملفات الإعداد الجديدة: `config/sponsorship.php`، `config/creator_series.php` (قسم A). وتعديلات: `config/permissions.php` (صلاحيات `sponsorships.*`)، `config/search.php` (نوع `creator_series`).


---

## E) LOCAL COMMANDS (بلا أي أمر git)

```powershell
# من جذر المشروع بعد نسخ الملفات:
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder     # صلاحيات sponsorships.* (آمن لإعادة التشغيل)
php artisan optimize:clear
php artisan test tests/Feature/SponsorshipE30
php artisan test tests/Feature/CreatorSeriesE30
php artisan test tests/Feature/EngagementE29
php artisan test tests/Feature/CreatorE28
php artisan test tests/Feature/DiscoveryE27
php artisan test tests/Feature/SecurityE26
php artisan sponsorship:lock-event-plans --help           # الأمر مجدول كل 5 دقائق (تأكد أن المجدول يعمل)
# اختياري (بيئة محلية فقط، يرفض الإنتاج):
php artisan db:seed --class=DemoE30Seeder
```

لا `composer install` ولا `composer update` ولا `npm run build` (لم تتغير أصول الواجهة). لا `php artisan test` بلا مسار.

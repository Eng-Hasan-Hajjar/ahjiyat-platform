# E28 — DELIVERY PACK (A–E)

NO GIT COMMANDS. أساس المقارنة: remote `main` = `7e2dc7d` (E27). هذا الملف خارج المستودع عمدًا (لا يُنسخ إليه). لا حزم جديدة (0 Composer / 0 npm)، `composer.lock` و`composer.json` و`package*.json` لم تُمسّ.

الملفات: **81 جديدًا** + **26 معدَّلًا** (كاملة). الأسطر `sha256` للتحقق بعد النسخ: `sha256sum <path>`.

## الفهرس

- A) NEW FILES (محتوى كامل)
- B) MODIFIED FILES (ملفات كاملة)
- C) MIGRATIONS (جديدة فقط)
- D) ENV LINES
- E) LOCAL COMMANDS


---

## A) NEW FILES — المسار + المحتوى الكامل (81 ملفًا)

### `app/Console/Commands/PruneCreatorMedia.php`

`sha256: 122c3b7f917c744ff113130bf5b9d69fa502dd3176b67a8360c5571ab7962667`

`````php
<?php

namespace App\Console\Commands;

use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Services\Creators\CreatorMediaService;
use Illuminate\Console\Command;

class PruneCreatorMedia extends Command
{
    protected $signature = 'creators:prune-media {--hours=24 : مهلة أمان بالساعات قبل اعتبار الملف يتيمًا} {--dry-run : عرض ما سيُحذف دون حذف}';

    protected $description = 'E28: يحذف صور أحجيات المبدعين اليتيمة (ملفات غير مرتبطة بأي نسخة ولا أحجية). لا يمسّ ملفًا مرتبطًا ولا ملفًا أحدث من مهلة الأمان. صحة النظام لا تعتمد عليه.';

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

### `app/Filament/Resources/CommunityPuzzleReportResource.php`

`sha256: 77b71894d07719fca6ec92d6c50f7e29a700fd1c9f07a7077a97b04f588939bd`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommunityPuzzleReportResource\Pages;
use App\Models\CommunityPuzzleReport;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorException;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E28 - بلاغات أحجيات المجتمع. تعرض العنوان المنشور والسبب والمبلِّغ (الاسم فقط) والتفاصيل؛ لا بريد ولا محتوى خاص. الإجراءات (رفض/معالجة/معالجة مع إخفاء) عبر CommunityReportService
 * بصلاحية creators.review (تدقيق + ذرّية). لا إنشاء ولا تعديل خام ولا حذف (تُنشأ البلاغات من اللاعبين فقط).
 */
class CommunityPuzzleReportResource extends Resource
{
    protected static ?string $model = CommunityPuzzleReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'بلاغات أحجيات المجتمع';

    protected static ?string $modelLabel = 'بلاغ أحجية';

    protected static ?string $pluralModelLabel = 'بلاغات أحجيات المجتمع';

    protected static ?int $navigationSort = 82;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['work:id,public_id,published_title,hidden_at,hidden_reason,is_visible', 'reporter:id,name']))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption((int) config('creators.moderation_page_size', 20))
            ->paginationPageOptions([10, (int) config('creators.moderation_page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['open' => 'warning', 'actioned' => 'success'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('work.published_title')->label('الأحجية')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->formatStateUsing(fn ($state) => CommunityPuzzleReport::REASONS[$state] ?? $state),
                Tables\Columns\TextColumn::make('reporter.name')->label('المُبلِّغ'),
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج'])->default(CommunityPuzzleReport::STATUS_OPEN),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('open')->label('فتح الأحجية')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (CommunityPuzzleReport $r) => route('community.puzzles.show', $r->work->public_id), shouldOpenInNewTab: true)
                    ->visible(fn (CommunityPuzzleReport $r) => (bool) $r->work?->is_visible),
                self::closeAction('dismiss', 'رفض البلاغ', 'gray', fn (CommunityReportService $s, CommunityPuzzleReport $r, ?string $n) => $s->dismiss($r, auth()->user(), $n), 'رُفض البلاغ.'),
                self::closeAction('action', 'تمت المعالجة', 'success', fn (CommunityReportService $s, CommunityPuzzleReport $r, ?string $n) => $s->action($r, auth()->user(), $n), 'حُسم البلاغ.'),
                Tables\Actions\Action::make('hide')->label('معالجة وإخفاء المنشور')->color('danger')->icon('heroicon-o-eye-slash')
                    ->form([Forms\Components\Textarea::make('note')->label('السبب (إلزامي، يظهر للمبدع)')->required()->maxLength((int) config('creators.limits.reason_max'))])
                    ->visible(fn (CommunityPuzzleReport $r) => $r->status === CommunityPuzzleReport::STATUS_OPEN && (auth()->user()?->can('creators.review') ?? false))
                    ->action(fn (CommunityPuzzleReport $record, array $data) => self::guard(fn () => app(CommunityReportService::class)->actionAndHide($record, auth()->user(), (string) $data['note']), 'أُخفي المنشور وحُسمت البلاغات المفتوحة عليه.')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('البلاغ')->columns(3)->schema([
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel($state)),
                Infolists\Components\TextEntry::make('reason')->label('السبب')->formatStateUsing(fn ($state) => CommunityPuzzleReport::REASONS[$state] ?? $state),
                Infolists\Components\TextEntry::make('reporter.name')->label('المُبلِّغ'),
                Infolists\Components\TextEntry::make('work.published_title')->label('الأحجية')->placeholder('—'),
                Infolists\Components\TextEntry::make('created_at')->label('وقت البلاغ')->dateTime('Y-m-d H:i'),
                Infolists\Components\TextEntry::make('handled_at')->label('وقت المعالجة')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('details')->label('تفاصيل المُبلِّغ')->placeholder('—')->columnSpanFull(),
                Infolists\Components\TextEntry::make('handler_note')->label('ملاحظة المعالج')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    /** @param \Closure(CommunityReportService, CommunityPuzzleReport, ?string): mixed $call */
    protected static function closeAction(string $name, string $label, string $color, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->form([Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength((int) config('creators.limits.reason_max'))])
            ->visible(fn (CommunityPuzzleReport $r) => $r->status === CommunityPuzzleReport::STATUS_OPEN && (auth()->user()?->can('creators.review') ?? false))
            ->action(fn (CommunityPuzzleReport $record, array $data) => self::guard(fn () => $call(app(CommunityReportService::class), $record, $data['note'] ?? null), $ok));
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
        return ['index' => Pages\ListCommunityPuzzleReports::route('/'), 'view' => Pages\ViewCommunityPuzzleReport::route('/{record}')];
    }
}
`````

### `app/Filament/Resources/CommunityPuzzleReportResource/Pages/ListCommunityPuzzleReports.php`

`sha256: 53013c93ff2a5fd8ceb764caa9a17cd6edb4c3cca47b564ac3344cad19dca05e`

`````php
<?php

namespace App\Filament\Resources\CommunityPuzzleReportResource\Pages;

use App\Filament\Resources\CommunityPuzzleReportResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunityPuzzleReports extends ListRecords
{
    protected static string $resource = CommunityPuzzleReportResource::class;
}
`````

### `app/Filament/Resources/CommunityPuzzleReportResource/Pages/ViewCommunityPuzzleReport.php`

`sha256: 21a4acf550939ceca9aae11c85537527c2f47ac309394f89b3d17c8c5a6843ba`

`````php
<?php

namespace App\Filament\Resources\CommunityPuzzleReportResource\Pages;

use App\Filament\Resources\CommunityPuzzleReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunityPuzzleReport extends ViewRecord
{
    protected static string $resource = CommunityPuzzleReportResource::class;
}
`````

### `app/Filament/Resources/CreatorProfileResource.php`

`sha256: affbcc182a3f27885146e5d79a3946a1b798454774f0f5c4e4ae174925ffd867`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CreatorProfileResource\Pages;
use App\Models\CreatorProfile;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorException;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E28 - ملفات المبدعين وطلبات الانضمام. عرض بصلاحية creators.view؛ الاعتماد/الرفض بـcreators.review والتعليق/الاستعادة بـcreators.manage، وكلها عبر CreatorApplicationService
 * (تدقيق + إشعار + مصالحة ظهور المنشورات). لا إنشاء ولا تعديل خام ولا حذف. لا بريد ولا هاتف: الاسم ومعرّف المستخدم فقط.
 */
class CreatorProfileResource extends Resource
{
    protected static ?string $model = CreatorProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'المبدعون';

    protected static ?string $modelLabel = 'مبدع';

    protected static ?string $pluralModelLabel = 'المبدعون وطلباتهم';

    protected static ?int $navigationSort = 80;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['pending' => 'طلب معلَّق', 'approved' => 'معتمَد', 'suspended' => 'معلَّق (موقوف)', 'rejected' => 'مرفوض'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query
                ->select('creator_profiles.*')
                ->with('user:id,name')
                ->withCount(['puzzles as visible_count' => fn ($q) => $q->where('is_visible', true)]))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption((int) config('creators.moderation_page_size', 20))
            ->paginationPageOptions([10, (int) config('creators.moderation_page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('المعرّف'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['approved' => 'success', 'pending' => 'warning', 'suspended' => 'danger', 'rejected' => 'gray'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('visible_count')->label('منشورة'),
                Tables\Columns\TextColumn::make('submitted_at')->label('قُدِّم')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('approved_at')->label('معتمَد منذ')->dateTime('Y-m-d')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(array_combine(CreatorProfile::STATUSES, array_map(fn ($s) => self::statusLabel($s), CreatorProfile::STATUSES))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::noteAction('approve', 'اعتماد', 'success', false, CreatorProfile::STATUS_PENDING, 'creators.review', 'اعتُمد المبدع.',
                    fn (CreatorApplicationService $s, CreatorProfile $r, ?string $note) => $s->approve($r, auth()->user(), $note)),
                self::noteAction('reject', 'رفض الطلب', 'danger', true, CreatorProfile::STATUS_PENDING, 'creators.review', 'رُفض الطلب وأُبلغ صاحبه.',
                    fn (CreatorApplicationService $s, CreatorProfile $r, ?string $note) => $s->reject($r, auth()->user(), (string) $note)),
                self::noteAction('suspend', 'تعليق المبدع', 'warning', true, CreatorProfile::STATUS_APPROVED, 'creators.manage', 'عُلِّق المبدع وأُخفيت منشوراته.',
                    fn (CreatorApplicationService $s, CreatorProfile $r, ?string $note) => $s->suspend($r, auth()->user(), (string) $note)),
                self::noteAction('restore', 'استعادة المبدع', 'success', false, CreatorProfile::STATUS_SUSPENDED, 'creators.manage', 'استُعيد المبدع وعادت منشوراته المؤهَّلة.',
                    fn (CreatorApplicationService $s, CreatorProfile $r, ?string $note) => $s->restore($r, auth()->user(), $note)),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('المبدع')->columns(3)->schema([
                Infolists\Components\TextEntry::make('user.name')->label('الاسم'),
                Infolists\Components\TextEntry::make('user_id')->label('معرّف المستخدم'),
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel($state)),
                Infolists\Components\TextEntry::make('submitted_at')->label('تاريخ الطلب')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('approved_at')->label('أول اعتماد')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('reviewed_at')->label('آخر قرار')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('bio')->label('النبذة')->columnSpanFull()->placeholder('—'),
                Infolists\Components\TextEntry::make('review_note')->label('ملاحظة القرار')->columnSpanFull()->placeholder('—'),
            ]),
        ]);
    }

    /** @param \Closure(CreatorApplicationService, CreatorProfile, ?string): mixed $call */
    protected static function noteAction(string $name, string $label, string $color, bool $noteRequired, string $fromStatus, string $permission, string $ok, \Closure $call): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->form([Forms\Components\Textarea::make('note')->label($noteRequired ? 'السبب (إلزامي، يظهر للمبدع)' : 'ملاحظة (اختياري)')->required($noteRequired)->maxLength((int) config('creators.limits.reason_max'))])
            ->visible(fn (CreatorProfile $r) => $r->status === $fromStatus && (auth()->user()?->can($permission) ?? false))
            ->action(function (CreatorProfile $record, array $data) use ($call, $ok) {
                try {
                    $call(app(CreatorApplicationService::class), $record, $data['note'] ?? null);
                    Notification::make()->success()->title($ok)->send();
                } catch (CreatorException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCreatorProfiles::route('/'), 'view' => Pages\ViewCreatorProfile::route('/{record}')];
    }
}
`````

### `app/Filament/Resources/CreatorProfileResource/Pages/ListCreatorProfiles.php`

`sha256: 8576e0cc1020dc389876c0b8023139a2cf5f4cf3b6ae4130f5250f59116fa994`

`````php
<?php

namespace App\Filament\Resources\CreatorProfileResource\Pages;

use App\Filament\Resources\CreatorProfileResource;
use App\Filament\Widgets\CreatorEcosystemStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListCreatorProfiles extends ListRecords
{
    protected static string $resource = CreatorProfileResource::class;

    protected function getHeaderWidgets(): array
    {
        return [CreatorEcosystemStatsWidget::class];
    }
}
`````

### `app/Filament/Resources/CreatorProfileResource/Pages/ViewCreatorProfile.php`

`sha256: 342db0d96d215f2404f0c080bd2fd71979d0c8184c8616cb180ad0b2a236cfb3`

`````php
<?php

namespace App\Filament\Resources\CreatorProfileResource\Pages;

use App\Filament\Resources\CreatorProfileResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCreatorProfile extends ViewRecord
{
    protected static string $resource = CreatorProfileResource::class;
}
`````

### `app/Filament/Resources/CreatorPuzzleRevisionResource.php`

`sha256: c88d4d7af3f8fd52c0589c4d33796b7f990c90083b4f200b415b156cf213c9e1`

`````php
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
`````

### `app/Filament/Resources/CreatorPuzzleRevisionResource/Pages/ListCreatorPuzzleRevisions.php`

`sha256: 928eff70c152dcc4e8bbe50445e393fd812f2b3a659ab577433524ee4dfe2963`

`````php
<?php

namespace App\Filament\Resources\CreatorPuzzleRevisionResource\Pages;

use App\Filament\Resources\CreatorPuzzleRevisionResource;
use App\Filament\Widgets\CreatorEcosystemStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListCreatorPuzzleRevisions extends ListRecords
{
    protected static string $resource = CreatorPuzzleRevisionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [CreatorEcosystemStatsWidget::class];
    }
}
`````

### `app/Filament/Resources/CreatorPuzzleRevisionResource/Pages/ViewCreatorPuzzleRevision.php`

`sha256: 8cc195f5df6eceb72b69dbdcbacecd684f62b915ea01ec06599836387d580ab0`

`````php
<?php

namespace App\Filament\Resources\CreatorPuzzleRevisionResource\Pages;

use App\Filament\Resources\CreatorPuzzleRevisionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCreatorPuzzleRevision extends ViewRecord
{
    protected static string $resource = CreatorPuzzleRevisionResource::class;
}
`````

### `app/Filament/Widgets/CreatorEcosystemStatsWidget.php`

`sha256: 5dc76fb62ea03bf984f4de7d896436d6a29911a14ab42ee434685680ef7b0996`

`````php
<?php

namespace App\Filament\Widgets;

use App\Services\Creators\CreatorAnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E28 - ملخص منظومة المبدعين لرأس صفحات الإشراف (ليس بالصفحة الرئيسية: غير مكتشَف تلقائيًا). أرقام مجمَّعة مخزَّنة دقيقة؛ لا هوية لاعبين.
 */
class CreatorEcosystemStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('creators.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(CreatorAnalyticsService::class)->adminSummary();

        return [
            Stat::make('مبدعون معتمدون', number_format($s['approved_creators']))->icon('heroicon-o-paint-brush')->color('primary'),
            Stat::make('طلبات انضمام معلَّقة', number_format($s['pending_applications']))->icon('heroicon-o-user-plus')->color($s['pending_applications'] > 0 ? 'warning' : 'success'),
            Stat::make('أحجيات بانتظار المراجعة', number_format($s['pending_submissions']))->icon('heroicon-o-clipboard-document-check')->color($s['pending_submissions'] > 0 ? 'warning' : 'success'),
            Stat::make('أحجيات مجتمع منشورة', number_format($s['published_puzzles']))->icon('heroicon-o-puzzle-piece')->color('primary'),
            Stat::make('إجمالي محاولات المجتمع', number_format($s['community_plays']))->icon('heroicon-o-play')->color('primary'),
            Stat::make('بلاغات مفتوحة', number_format($s['open_reports']))->icon('heroicon-o-flag')->color($s['open_reports'] > 0 ? 'danger' : 'success'),
        ];
    }
}
`````

### `app/Http/Controllers/Creators/CommunityPuzzleController.php`

`sha256: df6ef87d3c36790cb4bf3554cfbac32bbd96026d7de0dfb2599dc96a7aa0f32f`

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
use App\Services\Creators\CreatorException;
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
    ) {}

    public function index(Request $request)
    {
        $categorySlug = $request->query('category');
        $category = is_string($categorySlug) ? PuzzleCategory::query()->where('slug', $categorySlug)->where('is_active', true)->first(['id', 'name', 'slug']) : null;

        $works = CreatorPuzzle::query()->visible()
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count')
            ->when($category, fn ($q) => $q->where('published_category_id', $category->id))
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedCategory:id,name'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'))->withQueryString();

        return view('community.index', [
            'works' => $works,
            'category' => $category,
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

        return view('community.show', [
            'work' => $work,
            'puzzle' => $puzzle,
            'state' => $state,
            'renderer' => $this->games->rendererFor($puzzle),
            'isOwner' => $user !== null && $work->creator->user_id === $user->id,
            'canInteract' => $user !== null && $user->hasVerifiedEmail() && ! $user->is_frozen,
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

### `app/Http/Controllers/Creators/CreatorApplicationController.php`

`sha256: a915ab92036b7d7ece0ccf7b8cd6a24ff8ba536fe5a306cb0b0db1ef56d8b909`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Requests\Creators\CreatorApplyRequest;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E28 - طلب الانضمام للمبدعين: نبذة واحدة فقط (لا بيانات حساسة). بوابة auth+verified+account.active بالمسار؛ الأهلية والتكرار بالخدمة. */
class CreatorApplicationController extends CreatorController
{
    public function __construct(protected CreatorApplicationService $applications, protected CreatorEligibility $eligibility) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return view('creator.apply', [
            'profile' => $this->eligibility->profileFor($user),
            'denial' => $this->eligibility->applyDenial($user),
        ]);
    }

    public function store(CreatorApplyRequest $request): RedirectResponse
    {
        return $this->perform(
            fn () => $this->applications->apply($request->user(), (string) $request->validated('bio')),
            'تم إرسال طلبك، وسنراجعه قريبًا.',
            fn () => redirect()->route('creator.apply'),
        );
    }
}
`````

### `app/Http/Controllers/Creators/CreatorController.php`

`sha256: 752e1241b626ba21ad4808550a2a32565b9d458e23d10377d9bd7ee6d9ca3ba5`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Services\Creators\CreatorException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E28 - أساس متحكمات المبدعين: ملف المبدع من الميدلوير، وتحويل أخطاء المجال المتوقَّعة لرسالة عربية بدل 500. */
abstract class CreatorController extends Controller
{
    protected function profile(Request $request): CreatorProfile
    {
        return $request->attributes->get('creator_profile');
    }

    /**
     * ينفّذ العملية؛ خطأ مجال متوقَّع (CreatorException) يرجع للصفحة برسالته، وإلا يمرّ النجاح عبر $redirect (يتلقى نتيجة العملية).
     */
    protected function perform(Closure $operation, string $success, ?Closure $redirect = null): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (CreatorException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return ($redirect ? $redirect($result) : back())->with('success', $success);
    }
}
`````

### `app/Http/Controllers/Creators/CreatorDashboardController.php`

`sha256: e4a18e22ff4963b021a6935f9b485be29c83704156cc49c0a2cd402517f2897d`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Services\Creators\CreatorAnalyticsService;
use App\Services\Creators\CreatorPuzzleService;
use Illuminate\Http\Request;

/** E28 - لوحة المبدع: عدّادات الحالات (استعلام مجمَّع واحد) + تحليلات مخزَّنة قصيرًا + آخر الأعمال. بلا أرباح/محفظة. */
class CreatorDashboardController extends CreatorController
{
    public function __construct(protected CreatorPuzzleService $puzzles, protected CreatorAnalyticsService $analytics) {}

    public function index(Request $request)
    {
        $profile = $this->profile($request);

        return view('creator.dashboard', [
            'profile' => $profile,
            'counts' => $this->puzzles->bucketCounts($profile),
            'stats' => $this->analytics->forCreator($profile),
            'recent' => $this->puzzles->recent($profile, 5),
        ]);
    }
}
`````

### `app/Http/Controllers/Creators/CreatorPuzzleController.php`

`sha256: f0201cb0304c4e408e116a28a7922ee2061b2be79fcf84a3835fb891855174ef`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Requests\Creators\StoreCreatorPuzzleRequest;
use App\Http\Requests\Creators\UpdateCreatorPuzzleRequest;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\PuzzleCategory;
use App\Services\Creators\CreatorContent;
use App\Services\Creators\CreatorPreviewService;
use App\Services\Creators\CreatorPuzzleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E28 - استوديو المبدع. كل مسار عمل يستقبل public_id نصًا (لا ربط نموذج ضمني) ويُحلّ عبر استعلام محصور بالمالك: عمل مبدع آخر = 404 لا 403 (لا كشف وجود).
 * الصلاحية الفعلية والحدود والملكية في CreatorPuzzleService؛ هنا تنسيق HTTP فقط.
 */
class CreatorPuzzleController extends CreatorController
{
    public function __construct(
        protected CreatorPuzzleService $puzzles,
        protected CreatorPreviewService $previews,
    ) {}

    public function index(Request $request)
    {
        $profile = $this->profile($request);
        $bucket = in_array($request->query('status'), CreatorPuzzleService::BUCKETS, true) ? (string) $request->query('status') : 'all';

        return view('creator.puzzles.index', [
            'works' => $this->puzzles->paginate($profile, $bucket),
            'counts' => $this->puzzles->bucketCounts($profile),
            'bucket' => $bucket,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $request->query('kind');

        return view('creator.puzzles.create', [
            'categories' => $this->categories(),
            'kinds' => (array) config('creators.kinds'),
            'kind' => is_string($kind) && in_array($kind, CreatorContent::kinds(), true) ? $kind : null,
        ]);
    }

    public function store(StoreCreatorPuzzleRequest $request): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->createDraft($request->user(), $request->safe()->except('image'), $request->file('image')),
            'تم حفظ المسودة.',
            fn ($revision) => redirect()->route('creator.puzzles.edit', $revision->work()->value('public_id')),
        );
    }

    public function edit(Request $request, string $work)
    {
        $work = $this->puzzles->ownedWork($this->profile($request), $work);
        $revision = $work->latestRevision()->firstOrFail();

        return view('creator.puzzles.edit', [
            'work' => $work,
            'revision' => $revision,
            'content' => (array) $revision->content,
            'categories' => $this->categories(),
            'kindConfig' => CreatorContent::kindConfig((string) $revision->kind),
        ]);
    }

    public function update(UpdateCreatorPuzzleRequest $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->updateDraft($request->user(), $work, $request->safe()->except('image'), $request->file('image')),
            'تم حفظ التعديلات.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function destroy(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->destroy($request->user(), $work),
            'تم الحذف.',
            fn () => redirect()->route('creator.puzzles.index'),
        );
    }

    public function destroyImage(Request $request, string $work): RedirectResponse
    {
        return $this->perform(fn () => $this->puzzles->removeImage($request->user(), $work), 'تم حذف الصورة.');
    }

    public function submit(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->submit($request->user(), $work),
            'أُرسلت الأحجية للمراجعة.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function withdraw(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->withdraw($request->user(), $work),
            'سُحبت الأحجية من المراجعة وعادت مسودة.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function revise(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->revise($request->user(), $work),
            'أُنشئت نسخة جديدة للتعديل. النسخة المنشورة تبقى متاحة حتى اعتماد الجديدة.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function archive(Request $request, string $work): RedirectResponse
    {
        return $this->perform(fn () => $this->puzzles->archive($request->user(), $work), 'تمت أرشفة الأحجية وأُخفيت من العامة.');
    }

    public function unarchive(Request $request, string $work): RedirectResponse
    {
        return $this->perform(fn () => $this->puzzles->unarchive($request->user(), $work), 'أُلغيت الأرشفة.');
    }

    public function preview(Request $request, string $work)
    {
        $work = $this->puzzles->ownedWork($this->profile($request), $work);
        $revision = $work->latestRevision()->firstOrFail();

        return view('creator.puzzles.preview', [
            'work' => $work,
            'revision' => $revision,
            'checkRoute' => route('creator.puzzles.preview.check', $work->public_id),
            'backRoute' => route('creator.puzzles.edit', $work->public_id),
            'solution' => null,
        ] + $this->previews->view($revision));
    }

    /** يتحقق بالمدقّق الرسمي نفسه ويعرض صحيح/خاطئ فقط: بلا تخزين محاولة ولا جواهر ولا XP ولا مهام ولا تقدّم. */
    public function previewCheck(SolvePuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->puzzles->ownedWork($this->profile($request), $work);
        $revision = $work->latestRevision()->firstOrFail();

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        $result = $this->previews->check($revision, (string) $request->input('answer', ''), $submission, $request->user()->id);

        return back()->with($result->correct ? 'success' : 'error', $result->correct ? 'إجابة صحيحة (معاينة: لا تُحتسب ولا مكافآت).' : 'إجابة غير صحيحة (معاينة).');
    }

    protected function categories()
    {
        return PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);
    }
}
`````

### `app/Http/Controllers/Creators/CreatorReviewPreviewController.php`

`sha256: fd415aa4cd2982646ff9601f1d96c91b1a35c863f2b6602af5b6dc29a4828012`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\CreatorPuzzleRevision;
use App\Services\Creators\CreatorPreviewService;
use Illuminate\Http\RedirectResponse;

/**
 * E28 - معاينة المراجع لنسخة مرسلة (creators.review بالمسار). المحرك والعارض نفسهما + لوحة حلّ للمراجع فقط (G3). لا تخزين محاولات ولا مكافآت.
 * الرابط غير عام ولا مفهرَس (noindex بالعرض). النسخ المسودة التي لم تُرسل بعد لا تُعاين من هنا (المراجع يرى المرسَل وما بعده فقط).
 */
class CreatorReviewPreviewController extends Controller
{
    public function __construct(protected CreatorPreviewService $previews) {}

    public function show(CreatorPuzzleRevision $revision)
    {
        $this->assertReviewable($revision);
        $revision->loadMissing('work.creator.user:id,name', 'category:id,name');

        $content = (array) $revision->content;
        $solution = match ($revision->kind) {
            'sequence' => ['الترتيب الصحيح (كما أدخله المبدع)' => array_values((array) ($content['items'] ?? []))],
            'memory' => ['أزواج البطاقات' => array_values((array) ($content['faces'] ?? []))],
            'multiple_choice' => ['الجواب الصحيح' => (string) ($content['answer'] ?? ''), 'الاختيارات' => array_values((array) ($content['choices'] ?? []))],
            default => ['الجواب الصحيح' => (string) ($content['answer'] ?? '')],
        };

        return view('creator.puzzles.preview', [
            'work' => $revision->work,
            'revision' => $revision,
            'checkRoute' => route('community.review.check', $revision),
            'backRoute' => null,
            'solution' => $solution,
        ] + $this->previews->view($revision));
    }

    public function check(SolvePuzzleRequest $request, CreatorPuzzleRevision $revision): RedirectResponse
    {
        $this->assertReviewable($revision);

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        $result = $this->previews->check($revision, (string) $request->input('answer', ''), $submission, $request->user()->id);

        return back()->with($result->correct ? 'success' : 'error', $result->correct ? 'إجابة صحيحة (معاينة مراجع).' : 'إجابة غير صحيحة (معاينة مراجع).');
    }

    protected function assertReviewable(CreatorPuzzleRevision $revision): void
    {
        abort_if($revision->status === CreatorPuzzleRevision::STATUS_DRAFT, 404);
    }
}
`````

### `app/Http/Controllers/Creators/PublicCreatorController.php`

`sha256: 26058e43b14eb9241067541fdbe916a82da5b938f6346786ed1aed9f50996e27`

`````php
<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use Illuminate\Http\Request;

/**
 * E28 - صفحة المبدع العامة /creators/{slug}. عامة للضيوف لكن لمبدع معتمَد وحسابه غير مجمَّد فقط (غيره 404).
 * تعرض: الاسم، الصورة الرمزية (هوية اللاعب القائمة)، النبذة، تاريخ الانضمام كمبدع، عدد المنشور، إجمالي اللاعبين، والأحجيات الظاهرة فقط (مرقَّمة).
 * لا بريد ولا هاتف ولا حالة إدارية ولا علامات احتيال ولا أي بيانات لاعب خاصة.
 */
class PublicCreatorController extends Controller
{
    public function __construct(protected CosmeticLoadoutService $loadouts) {}

    public function show(Request $request, CreatorProfile $creator)
    {
        $creator->loadMissing('user:id,name,is_frozen');

        abort_unless($creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen, 404);

        $totals = CreatorPuzzle::query()->visible()->where('creator_profile_id', $creator->id)
            ->selectRaw('COUNT(*) as published, COALESCE(SUM(plays_count), 0) as plays')->first();

        $works = CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $creator->id)
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'));

        $loadout = $this->loadouts->loadoutFor($creator->user);

        return view('creator.public', [
            'creator' => $creator,
            'avatar' => $loadout['avatar'] ?? null,
            'frame' => $loadout['profile_frame'] ?? null,
            'published' => (int) ($totals->published ?? 0),
            'plays' => (int) ($totals->plays ?? 0),
            'works' => $works,
        ]);
    }
}
`````

### `app/Http/Middleware/EnsureCreatorStudioAccess.php`

`sha256: 7c9ce0e8cbf798620dc068c4df043b529cb7520a199863230f542fe63de16635`

`````php
<?php

namespace App\Http\Middleware;

use App\Services\Creators\CreatorEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E28 - بوابة استوديو المبدعين: ملف مبدع معتمَد + بريد موثَّق + حساب غير مجمَّد (CreatorEligibility، نفس مصدر الحقيقة الذي تفحصه الخدمات).
 * غير المؤهَّل يُحوَّل لصفحة الطلب بسبب واضح (JSON: 403). الملف يُمرَّر بسمات الطلب كي لا يُستعلم مرتين. لا يتطلب أي صلاحية إدارة.
 * يُوضع بعد auth/verified/account.active.
 */
class EnsureCreatorStudioAccess
{
    public function __construct(protected CreatorEligibility $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $reason = $this->eligibility->studioDenial($user);

        if ($reason !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $reason], 403);
            }

            return redirect()->route('creator.apply')->with('error', $reason);
        }

        $request->attributes->set('creator_profile', $this->eligibility->profileFor($user));

        return $next($request);
    }
}
`````

### `app/Http/Requests/Creators/CreatorApplyRequest.php`

`sha256: 80f18aa0d2b9f3b0eb7abe866c6b1581c4e1c971f0c177126a365e9c2a6cb658`

`````php
<?php

namespace App\Http\Requests\Creators;

use App\Rules\NoMarkup;
use Illuminate\Foundation\Http\FormRequest;

class CreatorApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $l = config('creators.limits');

        return ['bio' => ['required', 'string', 'min:'.$l['bio_min'], 'max:'.$l['bio_max'], new NoMarkup]];
    }

    public function attributes(): array
    {
        return ['bio' => 'النبذة'];
    }
}
`````

### `app/Http/Requests/Creators/CreatorContentRequest.php`

`sha256: 292f398a16c8975ea68cd476deff29d635e0af55f0482dfd68db2c26f06eedcc`

`````php
<?php

namespace App\Http\Requests\Creators;

use App\Services\Creators\CreatorContent;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E28 - أساس طلبات التأليف. (1) قواعد النوع من CreatorContent (مصدر واحد مع إعادة التحقق عند الإرسال/الاعتماد).
 * (2) حقول السلطة الممنوعة (مكافآت/يومية/حملات/منافسات/renderer/validation_type/score_mode/game_type...) وجودها بالطلب يُرفض صراحة لا يُتجاهل.
 * (3) القوائم تصل نصًا بسطر لكل عنصر وتُحوَّل لمصفوفات قبل التحقق.
 */
abstract class CreatorContentRequest extends FormRequest
{
    abstract protected function kind(): string;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['choices', 'items', 'faces'] as $key) {
            if ($this->exists($key)) {
                $merge[$key] = CreatorContent::lines($this->input($key));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return app(CreatorContent::class)->rules($this->kind());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) config('creators.forbidden_fields') as $field) {
                if ($this->exists($field)) {
                    $v->errors()->add($field, 'لا يمكنك تحديد هذا الحقل؛ يحدّده النظام.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'title' => 'العنوان', 'puzzle_category_id' => 'التصنيف', 'difficulty' => 'الصعوبة', 'prompt' => 'نص الأحجية', 'hint' => 'التلميح',
            'answer' => 'الجواب', 'choices' => 'الاختيارات', 'choices.*' => 'الاختيار', 'items' => 'العناصر', 'items.*' => 'العنصر', 'faces' => 'البطاقات', 'faces.*' => 'البطاقة', 'image' => 'الصورة',
        ];
    }
}
`````

### `app/Http/Requests/Creators/ReportCommunityPuzzleRequest.php`

`sha256: f0cc4d79736365596cf0e27276ed5b6c0be43217ea93a4c3800ab7b52bba3f04`

`````php
<?php

namespace App\Http\Requests\Creators;

use App\Models\CommunityPuzzleReport;
use App\Rules\NoMarkup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportCommunityPuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(array_keys(CommunityPuzzleReport::REASONS))],
            'details' => ['nullable', 'string', 'max:'.config('creators.limits.report_details_max'), new NoMarkup],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'سبب البلاغ', 'details' => 'التفاصيل'];
    }
}
`````

### `app/Http/Requests/Creators/StoreCreatorPuzzleRequest.php`

`sha256: 7d95356ede4284ff79b3f12eceaffe7343cb6ee641f1c6390256f2f78315c8f4`

`````php
<?php

namespace App\Http\Requests\Creators;

use App\Services\Creators\CreatorContent;
use Illuminate\Validation\Rule;

class StoreCreatorPuzzleRequest extends CreatorContentRequest
{
    protected function kind(): string
    {
        return (string) $this->input('kind');
    }

    public function rules(): array
    {
        return ['kind' => ['required', 'string', Rule::in(CreatorContent::kinds())]] + parent::rules();
    }

    public function attributes(): array
    {
        return parent::attributes() + ['kind' => 'نوع الأحجية'];
    }

    public function messages(): array
    {
        return ['kind.in' => 'نوع الأحجية غير مدعوم.'];
    }
}
`````

### `app/Http/Requests/Creators/UpdateCreatorPuzzleRequest.php`

`sha256: 8514a9f89bfefdeca40982019b81e4aa34bba9ec7e8dfe7bb6da1695f7d3b66b`

`````php
<?php

namespace App\Http\Requests\Creators;

use App\Services\Creators\CreatorPuzzleService;

/** النوع ثابت بعد الإنشاء: يُقرأ من آخر نسخة للعمل المملوك فعلًا (استعلام محصور بالمالك: عمل غيرك = 404 قبل أي تحقق). */
class UpdateCreatorPuzzleRequest extends CreatorContentRequest
{
    protected function kind(): string
    {
        $profile = $this->attributes->get('creator_profile');
        $work = app(CreatorPuzzleService::class)->ownedWork($profile, (string) $this->route('work'));

        return (string) $work->latestRevision()->value('kind');
    }
}
`````

### `app/Models/CommunityPuzzleAttempt.php`

`sha256: 4542c7548e174eb8883971daa62274e35108d6c39f92f13efbb5b24c73d1918d`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E28 - سجل لعب المجتمع، منفصل عمدًا عن puzzle_attempts: لا يغذّي الاقتصاد/XP/المهام/السلسلة/الإنجازات/لوحة الصدارة/التأهيل بالبناء.
 * للتجربة (حالة الحل/المحاولات) وتحليلات المبدع والرائج فقط. تكتبه CommunityPlayService وحدها.
 */
class CommunityPuzzleAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }
}
`````

### `app/Models/CommunityPuzzleReport.php`

`sha256: 768d00c0516302b67568fd102ea057f87fa3fc72a1a8cd1b8fa7933920754ae0`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E28 - بلاغ عن أحجية مجتمع منشورة. بلاغ مفتوح واحد لكل مستخدم/عمل: open_marker=1 عند الفتح وNULL عند الإغلاق (قيد unique بقاعدة البيانات).
 * لا تعيين جماعي: تكتبه CommunityReportService.
 */
class CommunityPuzzleReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    public const REASONS = [
        'inappropriate' => 'محتوى غير لائق',
        'copyright' => 'انتهاك حقوق نشر',
        'misleading' => 'مضلِّل',
        'broken' => 'الأحجية لا تعمل/خطأ بالحل',
        'spam' => 'محتوى مزعج',
        'other' => 'سبب آخر',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? self::REASONS['other'];
    }
}
`````

### `app/Models/CreatorProfile.php`

`sha256: 67bcd62ad161eb99ee4b818e3ac2547157fa033947e722e66f42f32e209f985b`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E28 - ملف المبدع (1:1 مع المستخدم). مصدر الحقيقة لأهلية الاستوديو: status = approved + حساب مفعَّل/غير مجمَّد (تفحصه CreatorEligibility).
 * لا تعيين جماعي: كل كتابة تمرّ بـCreatorApplicationService (التدقيق والإشعار والمصالحة معًا).
 */
class CreatorProfile extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_SUSPENDED, self::STATUS_REJECTED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function puzzles(): HasMany
    {
        return $this->hasMany(CreatorPuzzle::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
`````

### `app/Models/CreatorPuzzle.php`

`sha256: 06d9f739e8346f6ebd98ec2eb6f687e9a2981859f10db026c29cd8f47e2699dc`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E28 - العمل الإبداعي: هوية ثابتة (public_id) + لقطة النشر الحالية + الإخفاء/الأرشفة. المحتوى نفسه بالنسخ (CreatorPuzzleRevision).
 *
 * is_visible مشتقّة آليًا عند كل حفظ = (منشور) ∧ (غير مخفي) ∧ (غير مؤرشف): مصدر واحد للحقيقة تقرؤه كل الأسطح العامة (البحث/الاكتشاف/الصفحات)
 * بشرط واحد مفهرس بلا ضمّ. لا تُكتب يدويًا أبدًا. مصالحة التعليق/التجميد تضبط hidden_* عبر CreatorVisibilityService.
 */
class CreatorPuzzle extends Model
{
    public const HIDDEN_MODERATION = 'moderation';

    public const HIDDEN_CREATOR_SUSPENDED = 'creator_suspended';

    public const HIDDEN_ACCOUNT_FROZEN = 'account_frozen';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CreatorPuzzle $work) {
            $work->public_id ??= (string) Str::ulid();
        });

        static::saving(function (CreatorPuzzle $work) {
            $work->is_visible = $work->published_puzzle_id !== null
                && $work->hidden_at === null
                && $work->archived_at === null;
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'archived_at' => 'datetime',
            'hidden_at' => 'datetime',
            'is_visible' => 'boolean',
            'plays_count' => 'integer',
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
        return $this->hasMany(CreatorPuzzleRevision::class);
    }

    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzleRevision::class, 'latest_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzleRevision::class, 'published_revision_id');
    }

    public function publishedPuzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class, 'published_puzzle_id');
    }

    public function publishedCategory(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'published_category_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommunityPuzzleReport::class);
    }

    public function scopeVisible($query)
    {
        return $query->where($query->getModel()->getTable().'.is_visible', true);
    }

    public function isPublished(): bool
    {
        return $this->published_puzzle_id !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
`````

### `app/Models/CreatorPuzzleRevision.php`

`sha256: 7699dd73a8c65e6a05cf5884c97ef1f1a073a40f4a42060e41c3364f74d0067d`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E28 - نسخة من عمل المبدع: حمولة التأليف + حالة سير العمل + المراجعة + ربط الأحجية القانونية (puzzle_id) عند الاعتماد.
 *
 * content مشفَّرة (encrypted:array) لأنها تحوي الجواب الخام (يحتاجه المبدع والمراجع فقط) ومخفية من التسلسل (hidden) فلا تتسرّب بأي JSON.
 * النسخة المعتمدة والمرفوضة جامدتان: أي تعديل عليهما يُرفض بالنموذج (سجل التاريخ لا يُعاد كتابته).
 */
class CreatorPuzzleRevision extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_CHANGES_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $guarded = ['*'];

    protected $hidden = ['content'];

    protected static function booted(): void
    {
        static::updating(function (CreatorPuzzleRevision $revision) {
            if (in_array($revision->getOriginal('status'), [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                $changed = array_diff(array_keys($revision->getDirty()), ['updated_at']);

                if ($changed !== []) {
                    throw new \LogicException('النسخ المعتمدة والمرفوضة جامدة ولا تُعدَّل.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'content' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'puzzle_category_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
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

    /** رابط صورة الأحجية (يضعه المخدّم وحده من رفع صالح). */
    public function imageUrl(): ?string
    {
        return app(\App\Services\Creators\CreatorMediaService::class)->url($this->image_path);
    }
}
`````

### `app/Policies/CommunityPuzzleReportPolicy.php`

`sha256: 67f23a8d78ad0cc00acb9ca82fac097b97232e6086bc0df629b8019302aeb3ea`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E28 - بلاغات أحجيات المجتمع: العرض بـcreators.view والإجراءات (اعتماد/رفض/إخفاء/تعليق) بصلاحياتها داخل الخدمات المدقَّقة (creators.review / creators.manage). بالصلاحيات لا بأسماء الأدوار.
 * لا إنشاء ولا تعديل خام ولا حذف من لوحة الإدارة: التاريخ وسجل التدقيق لا يُمحيان، وكل تغيير حالة يمرّ بالخدمة (تدقيق + إشعار + مصالحة ظهور).
 */
class CommunityPuzzleReportPolicy
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

### `app/Policies/CreatorProfilePolicy.php`

`sha256: 7954b2d45e4eba27c6366cd4c430518754be3751c784be8ae0ac493594f5d212`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E28 - ملفات المبدعين وطلباتهم: العرض بـcreators.view والإجراءات (اعتماد/رفض/إخفاء/تعليق) بصلاحياتها داخل الخدمات المدقَّقة (creators.review / creators.manage). بالصلاحيات لا بأسماء الأدوار.
 * لا إنشاء ولا تعديل خام ولا حذف من لوحة الإدارة: التاريخ وسجل التدقيق لا يُمحيان، وكل تغيير حالة يمرّ بالخدمة (تدقيق + إشعار + مصالحة ظهور).
 */
class CreatorProfilePolicy
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

### `app/Policies/CreatorPuzzleRevisionPolicy.php`

`sha256: 90e8e75fb07630ddfed9c698a915a60c66988b9fea66f77e6b55a2e684e326ec`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E28 - الأحجيات المرسلة للمراجعة (نسخ المبدعين): العرض بـcreators.view والإجراءات (اعتماد/رفض/إخفاء/تعليق) بصلاحياتها داخل الخدمات المدقَّقة (creators.review / creators.manage). بالصلاحيات لا بأسماء الأدوار.
 * لا إنشاء ولا تعديل خام ولا حذف من لوحة الإدارة: التاريخ وسجل التدقيق لا يُمحيان، وكل تغيير حالة يمرّ بالخدمة (تدقيق + إشعار + مصالحة ظهور).
 */
class CreatorPuzzleRevisionPolicy
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

### `app/Rules/NoMarkup.php`

`sha256: b38cc772c51e9b04f6867d43c64b594898cc0a545d021ddce502241bb360e00e`

`````php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * E28 - محتوى المبدع نص عادي فقط. يُرفض عند الإدخال: أي وسم HTML/تعليق/XML/معالجة (<script، <img، <!--، <?php ...) وأي محرف تحكّم أو تحكّم اتجاهي مخادع (RLO/LRO...).
 * الدفاع الثاني عند العرض: Blade {{ }} المهرَّب دائمًا (لا {!! !!} لمحتوى المبدع). لا Blade/JS/CSS/iframe يصل لأي عارض.
 */
class NoMarkup implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('/<\s*[a-z\/!?]/i', $value) === 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1) {
            $fail('لا يُسمح بالوسوم أو الأكواد أو المحارف الخاصة في :attribute؛ اكتب نصًا عاديًا فقط.');
        }
    }
}
`````

### `app/Services/Creators/CommunityDiscovery.php`

`sha256: e86e9d6db0d6cca0b7b9fd3395c9eee8c37de39f918b5c76fa1997637f9f80e5`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Discovery\DiscoverCard;
use App\Support\CachedSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E28 / E27 - أحجيات المجتمع بالاكتشاف: رائج + جديد + مقترحة للمسجَّل، **بسقف أحجيات لكل مبدع** (تنوّع المبدعين: مبدع غزير لا يحتكر القسم).
 *
 * قواعد ثابتة:
 *  - يقرأ فقط الأعمال الظاهرة (is_visible) في كل طلب؛ الكاش يحمل **معرّفات ودرجات فقط** (لا أسماء ولا بيانات لاعبين)، فإخفاء الإشراف أو تعليق المبدع يظهر فورًا ولو بقيت اللقطة بالكاش.
 *  - الرائج = لاعبون **مميَّزون** (لا محاولات) بحسابات موثَّقة غير مجمَّدة خلال نافذة E27 نفسها، بحدّ أدنى من اللاعبين؛ جدول community_puzzle_attempts وحده (لا puzzle_attempts).
 *    فلا تأثير لأي شيء هنا على الرائج الرسمي ولا على توصيات الأحجيات الرسمية، ولا على الاقتصاد/XP/المهام (عرض فقط).
 *  - عدد الاستعلامات ثابت لا يتبع حجم البيانات: جديد (1) + رائج (1 إن وُجدت لقطة) + للمسجَّل: سجلّه (1). الحساب الثقيل مخزَّن (TTL مثل رائج E27).
 *  - لا مكافآت ولا رعاية ولا ترتيب مدفوع. المبدع لا تُقترح عليه أعماله.
 */
class CommunityDiscovery
{
    /**
     * @return array{trending: list<DiscoverCard>, new: list<DiscoverCard>, for_you: list<DiscoverCard>}
     */
    public function sections(?User $viewer): array
    {
        $limit = max(1, (int) config('creators.discovery.section_limit', 8));
        $cap = max(1, (int) config('creators.discovery.per_creator_cap', 2));
        $pool = $this->newestPool();

        $trending = $this->trendingWorks($limit, $cap);
        $trendingIds = $trending->pluck('id')->all();

        $new = $this->capPerCreator($pool->reject(fn ($w) => in_array($w->id, $trendingIds, true)), $cap, $limit);

        $forYou = collect();

        if ($viewer !== null) {
            $seen = CommunityPuzzleAttempt::query()->where('user_id', $viewer->id)->distinct()
                ->limit((int) config('search.recommendations.history_cap', 500))->pluck('creator_puzzle_id')->all();
            $shown = array_merge($trendingIds, $new->pluck('id')->all(), $seen);

            $forYou = $this->capPerCreator(
                $pool->reject(fn ($w) => in_array($w->id, $shown, true) || (int) $w->creator_user_id === $viewer->id)->sort(fn ($a, $b) => [(int) $b->plays_count, (int) $b->id] <=> [(int) $a->plays_count, (int) $a->id])->values(),
                $cap,
                min($limit, (int) config('creators.discovery.reco_limit', 4)),
            );
        }

        return [
            'trending' => $trending->map(fn ($w) => $this->card($w, $w->trending_players.' لاعب هذا الأسبوع'))->values()->all(),
            'new' => $new->map(fn ($w) => $this->card($w, null))->values()->all(),
            'for_you' => $forYou->map(fn ($w) => $this->card($w, 'لم تجرّبها بعد'))->values()->all(),
        ];
    }

    /** أحدث الأعمال الظاهرة (مجمَّع قراءة واحد بضمّ التصنيف واسم المبدع: لا N+1). */
    protected function newestPool(): Collection
    {
        return $this->visibleQuery()
            ->orderByDesc('creator_puzzles.published_at')->orderByDesc('creator_puzzles.id')
            ->limit(max(1, (int) config('creators.discovery.reco_pool', 40)))->get();
    }

    /** @return Collection<int, CreatorPuzzle> أعمال ظاهرة رائجة بترتيب الدرجة، بسقف لكل مبدع؛ كل عنصر يحمل trending_players. */
    protected function trendingWorks(int $limit, int $cap): Collection
    {
        $scores = $this->trendingScores();

        if ($scores === []) {
            return collect();
        }

        $works = $this->visibleQuery()->whereIn('creator_puzzles.id', array_keys($scores))->get()->keyBy('id');

        $ordered = collect($scores)
            ->filter(fn (array $row, int $id) => $works->has($id))
            ->map(function (array $row, int $id) use ($works) {
                $work = $works->get($id);
                $work->setAttribute('trending_players', $row['players']);

                return $work;
            })
            ->values();

        return $this->capPerCreator($ordered, $cap, $limit);
    }

    /**
     * معرّفات + درجات فقط (لا بيانات لاعبين). الدرجة = لاعبون مميَّزون + وزن إضافي لمن حلّوا.
     *
     * @return array<int, array{players: int, score: int}> مرتبة بالدرجة ثم الأحدث.
     */
    public function trendingScores(): array
    {
        return CachedSnapshot::remember('discover.community.trending', (int) config('search.trending.cache_ttl', 300), function () {
            $days = max(1, (int) config('search.trending.window_days', 7));
            $min = max(1, (int) config('creators.discovery.trending_min_players', 2));
            $solverWeight = (int) config('search.trending.solver_weight', 2);

            $rows = DB::table('community_puzzle_attempts as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.created_at', '>=', now()->subDays($days))
                ->whereNotNull('u.email_verified_at')->where('u.is_frozen', false)
                ->groupBy('a.creator_puzzle_id')
                ->selectRaw('a.creator_puzzle_id as id, COUNT(DISTINCT a.user_id) as players, COUNT(DISTINCT CASE WHEN a.is_correct THEN a.user_id END) as solvers')
                ->havingRaw('COUNT(DISTINCT a.user_id) >= ?', [$min])
                ->get();

            return $rows
                ->map(fn ($r) => ['id' => (int) $r->id, 'players' => (int) $r->players, 'score' => (int) $r->players + $solverWeight * (int) $r->solvers])
                ->sort(fn ($a, $b) => [$b['score'], $b['id']] <=> [$a['score'], $a['id']])
                ->take(max(1, (int) config('creators.discovery.reco_pool', 40)))
                ->mapWithKeys(fn ($r) => [$r['id'] => ['players' => $r['players'], 'score' => $r['score']]])
                ->all();
        });
    }

    protected function visibleQuery(): Builder
    {
        return CreatorPuzzle::query()->visible()
            ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_puzzles.creator_profile_id')
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->leftJoin('puzzle_categories', 'puzzle_categories.id', '=', 'creator_puzzles.published_category_id')
            ->select(
                'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.creator_profile_id', 'creator_puzzles.published_title',
                'creator_puzzles.published_difficulty', 'creator_puzzles.plays_count', 'creator_puzzles.published_at',
                'creator_profiles.user_id as creator_user_id', 'users.name as creator_name', 'puzzle_categories.name as category_name',
            );
    }

    /** يحافظ على الترتيب ويتجاوز ما فاق سقف المبدع الواحد حتى يبلغ $limit. */
    protected function capPerCreator(Collection $works, int $cap, int $limit): Collection
    {
        $perCreator = [];

        return $works->filter(function ($work) use (&$perCreator, $cap) {
            $perCreator[$work->creator_profile_id] = ($perCreator[$work->creator_profile_id] ?? 0) + 1;

            return $perCreator[$work->creator_profile_id] <= $cap;
        })->take($limit)->values();
    }

    protected function card($work, ?string $reason): DiscoverCard
    {
        $difficulty = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$work->published_difficulty] ?? null;
        $by = 'بقلم '.$work->creator_name;

        return new DiscoverCard(
            (string) $work->published_title,
            route('community.puzzles.show', $work->public_id),
            'friends',
            $work->category_name ? $work->category_name.' · '.$by : $by,
            'من المجتمع'.($difficulty ? ' · '.$difficulty : ''),
            'primary',
            $reason,
        );
    }
}
`````

### `app/Services/Creators/CommunityPlayService.php`

`sha256: 389cd91d6ff63558da1ac53f3ab86d54ca461cbb5421be347eae7a9ec16e8a75`

`````php
<?php

namespace App\Services\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E28 - لعب أحجيات المجتمع. **لا اقتصاد ولا تقدّم بالبناء**: هذه الخدمة لا تلمس PuzzleAttemptService ولا المحافظ ولا XP ولا المهام ولا السلسلة ولا الإنجازات ولا التأهيل ولا المنافسات،
 * ولا تكتب إلى puzzle_attempts؛ تكتب فقط إلى community_puzzle_attempts (حالة الحل/المحاولات + تحليلات المبدع + الرائج).
 * التحقق من الجواب = المدقّق الرسمي نفسه (GameTypeRegistry::validatorFor) فلا مدقّق ثانٍ. المحاولات لكل نسخة قانونية (puzzle_id): النسخة الجديدة المعتمدة لا تُعيد كتابة تاريخ القديمة.
 * التلميح مجاني (لا خصم جواهر). المبدع لا يلعب أحجيته (يعاينها)، فلا يضخّم أرقامه.
 */
class CommunityPlayService
{
    public function __construct(protected GameTypeRegistry $games) {}

    /** @return array{used:int, solved:bool, max:int, left:int} */
    public function state(?User $user, Puzzle $puzzle): array
    {
        $max = (int) $puzzle->max_attempts;

        if ($user === null) {
            return ['used' => 0, 'solved' => false, 'max' => $max, 'left' => $max];
        }

        $row = CommunityPuzzleAttempt::query()
            ->where('user_id', $user->id)->where('puzzle_id', $puzzle->id)
            ->selectRaw('COUNT(*) as used, COALESCE(MAX(CASE WHEN is_correct THEN 1 ELSE 0 END), 0) as solved')
            ->first();

        $used = (int) ($row->used ?? 0);

        return ['used' => $used, 'solved' => (bool) ($row->solved ?? false), 'max' => $max, 'left' => max(0, $max - $used)];
    }

    /** @return array{correct: bool, attempts_left: int} */
    public function attempt(User $user, CreatorPuzzle $work, string $answer, array $submission = []): array
    {
        $puzzle = $this->playablePuzzle($work);

        if ($work->creator->user_id === $user->id) {
            throw new CreatorException('هذه أحجيتك؛ استخدم المعاينة من استوديو المبدعين.');
        }

        return DB::transaction(function () use ($user, $work, $puzzle, $answer, $submission) {
            $rows = CommunityPuzzleAttempt::query()
                ->where('user_id', $user->id)->where('puzzle_id', $puzzle->id)
                ->lockForUpdate()->get(['id', 'is_correct']);

            if ($rows->contains('is_correct', true)) {
                throw new CreatorException('سبق أن حللت هذه الأحجية.');
            }

            if ($rows->count() >= $puzzle->max_attempts) {
                throw new CreatorException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            $payload = $submission !== [] ? $submission : ['answer' => $answer];
            $correct = $this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => $user->id]])->correct;

            $firstForWork = ! CommunityPuzzleAttempt::query()->where('creator_puzzle_id', $work->id)->where('user_id', $user->id)->exists();

            $attempt = new CommunityPuzzleAttempt;
            $attempt->forceFill([
                'user_id' => $user->id,
                'creator_puzzle_id' => $work->id,
                'puzzle_id' => $puzzle->id,
                'attempt_number' => $rows->count() + 1,
                'is_correct' => $correct,
            ])->save();

            if ($firstForWork) {
                CreatorPuzzle::query()->whereKey($work->id)->increment('plays_count'); // لاعب مميَّز واحد لكل عمل؛ تحديث ذرّي بلا أحداث نموذج
            }

            return ['correct' => $correct, 'attempts_left' => max(0, (int) $puzzle->max_attempts - ($rows->count() + 1))];
        });
    }

    /** التلميح مجاني لأحجيات المجتمع (لا محفظة ولا عملة). */
    public function hint(CreatorPuzzle $work): string
    {
        $puzzle = $this->playablePuzzle($work);

        if (blank($puzzle->hint)) {
            throw new CreatorException('لا يوجد تلميح لهذه الأحجية.');
        }

        return (string) $puzzle->hint;
    }

    protected function playablePuzzle(CreatorPuzzle $work): Puzzle
    {
        $puzzle = $work->is_visible ? $work->publishedPuzzle : null;

        if ($puzzle === null || ! $puzzle->isCommunity()) {
            throw new CreatorException('هذه الأحجية غير متاحة حاليًا.');
        }

        $work->loadMissing('creator');

        return $puzzle;
    }
}
`````

### `app/Services/Creators/CommunityReportService.php`

`sha256: 18e97a4fe169defdd45abf7ea2645f9119bac9a040da8ce5ed98c7afc57c60dc`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleReport;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E28 - بلاغات أحجيات المجتمع. الأسباب مغلقة (CommunityPuzzleReport::REASONS). بلاغ مفتوح واحد لكل مستخدم/عمل (قيد قاعدة بيانات عبر open_marker)، ولا بلاغ على أحجيتك.
 * المعالجة (creators.review): رفض البلاغ / إجراء (اتُّخذ إجراء) / إجراء مع إخفاء المنشور فورًا (ويُغلق كل بلاغات العمل المفتوحة). كلها مدقَّقة.
 */
class CommunityReportService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected CreatorVisibilityService $visibility,
    ) {}

    public function report(User $reporter, CreatorPuzzle $work, string $reason, ?string $details): CommunityPuzzleReport
    {
        if (! array_key_exists($reason, CommunityPuzzleReport::REASONS)) {
            throw new CreatorException('سبب البلاغ غير صالح.');
        }

        if (! $work->is_visible) {
            throw new CreatorException('هذه الأحجية غير متاحة.');
        }

        $work->loadMissing('creator');

        if ($work->creator->user_id === $reporter->id) {
            throw new CreatorException('لا يمكنك الإبلاغ عن أحجيتك.');
        }

        $details = $details === null ? null : trim(preg_replace('/\s+/u', ' ', $details) ?? '');
        $details = $details === '' ? null : $details;

        if ($details !== null && mb_strlen($details) > (int) config('creators.limits.report_details_max')) {
            throw new CreatorException('تفاصيل البلاغ أطول من المسموح.');
        }

        $exists = CommunityPuzzleReport::query()->where('creator_puzzle_id', $work->id)->where('reporter_id', $reporter->id)->where('status', CommunityPuzzleReport::STATUS_OPEN)->exists();

        if ($exists) {
            throw new CreatorException('لديك بلاغ مفتوح على هذه الأحجية بالفعل.');
        }

        try {
            $report = new CommunityPuzzleReport;
            $report->forceFill([
                'creator_puzzle_id' => $work->id,
                'puzzle_id' => $work->published_puzzle_id,
                'reporter_id' => $reporter->id,
                'reason' => $reason,
                'details' => $details,
                'status' => CommunityPuzzleReport::STATUS_OPEN,
                'open_marker' => 1,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new CreatorException('لديك بلاغ مفتوح على هذه الأحجية بالفعل.');
        }

        return $report;
    }

    public function dismiss(CommunityPuzzleReport $report, User $actor, ?string $note = null): CommunityPuzzleReport
    {
        return $this->close($report, $actor, CommunityPuzzleReport::STATUS_DISMISSED, $note, 'community_report_dismissed');
    }

    public function action(CommunityPuzzleReport $report, User $actor, ?string $note = null): CommunityPuzzleReport
    {
        return $this->close($report, $actor, CommunityPuzzleReport::STATUS_ACTIONED, $note, 'community_report_actioned');
    }

    /** إجراء + إخفاء المنشور فورًا؛ يُغلق كل البلاغات المفتوحة على العمل كمُعالَجة. الإخفاء أولًا (معاملته وإشعاره بعد commit الخاص) ثم إغلاق البلاغ. */
    public function actionAndHide(CommunityPuzzleReport $report, User $actor, string $note): CommunityPuzzleReport
    {
        abort_unless($actor->can('creators.review'), 403);

        $note = trim($note);

        if (mb_strlen($note) < (int) config('creators.limits.note_min')) {
            throw new CreatorException('اكتب سببًا واضحًا للإخفاء.');
        }

        if ($report->fresh()?->status !== CommunityPuzzleReport::STATUS_OPEN) {
            throw new CreatorException('عولج هذا البلاغ بالفعل.');
        }

        $work = CreatorPuzzle::query()->findOrFail($report->creator_puzzle_id);

        if (! ($work->isHidden() && $work->hidden_reason === CreatorPuzzle::HIDDEN_MODERATION)) {
            $this->visibility->hide($work, $actor, $note);
        }

        $closed = $this->close($report, $actor, CommunityPuzzleReport::STATUS_ACTIONED, $note, 'community_report_actioned', ['hide' => true]);

        // بقية البلاغات المفتوحة على العمل نفسه تُغلق تباعًا (الإخفاء عالجها جميعًا).
        CommunityPuzzleReport::query()
            ->where('creator_puzzle_id', $work->id)->where('status', CommunityPuzzleReport::STATUS_OPEN)
            ->update(['status' => CommunityPuzzleReport::STATUS_ACTIONED, 'open_marker' => null, 'handled_by' => $actor->id, 'handled_at' => now(), 'handler_note' => 'أُغلق مع إخفاء المنشور.']);

        return $closed;
    }

    protected function close(CommunityPuzzleReport $report, User $actor, string $status, ?string $note, string $auditAction, array $extra = []): CommunityPuzzleReport
    {
        abort_unless($actor->can('creators.review'), 403);

        $note = filled($note) ? trim((string) $note) : null;

        return DB::transaction(function () use ($report, $actor, $status, $note, $auditAction, $extra) {
            $locked = CommunityPuzzleReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($locked->status !== CommunityPuzzleReport::STATUS_OPEN) {
                throw new CreatorException('عولج هذا البلاغ بالفعل.');
            }

            $locked->forceFill([
                'status' => $status,
                'open_marker' => null,
                'handled_by' => $actor->id,
                'handled_at' => now(),
                'handler_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, ['work_id' => $locked->creator_puzzle_id, 'reason' => $locked->reason, 'note' => $note] + $extra, $actor);

            return $locked;
        });
    }
}
`````

### `app/Services/Creators/CreatorAnalyticsService.php`

`sha256: 2582bd5761c87c67b059180c15556f2d661ba704d79ba920bc46f7d065c48be3`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use Illuminate\Support\Facades\Cache;

/**
 * E28 - تحليلات المبدع (لوحته) + ملخص إداري متواضع. لا أرباح ولا محفظة ولا دفع ولا مرتبة.
 * بلا COUNT لكل بطاقة: المبدع محدود بسقف أعمال، فاستعلامان مجمَّعان فقط (إجماليات + نشاط حديث لكل عمل) على فهرس (creator_puzzle_id, user_id)، ومخزَّنان قصيرًا.
 * لا هوية لاعبين: أرقام مجمَّعة فقط (خصوصية).
 */
class CreatorAnalyticsService
{
    /** @return array{published:int, players:int, attempts:int, completion_rate:int|null, recent_attempts:int, recent_days:int, per_work: array<int, array{recent:int}>} */
    public function forCreator(CreatorProfile $profile): array
    {
        return Cache::remember('creators.analytics.'.$profile->id, now()->addSeconds((int) config('creators.analytics.cache_ttl')), function () use ($profile) {
            $days = (int) config('creators.analytics.recent_days');
            $workIds = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->pluck('id')->all();
            $published = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->whereNotNull('published_puzzle_id')->count();

            if ($workIds === []) {
                return ['published' => 0, 'players' => 0, 'attempts' => 0, 'completion_rate' => null, 'recent_attempts' => 0, 'recent_days' => $days, 'per_work' => []];
            }

            $totals = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)
                ->selectRaw('COUNT(*) as attempts, COUNT(DISTINCT user_id) as players, COUNT(DISTINCT CASE WHEN is_correct THEN user_id END) as solvers')
                ->first();

            $recent = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('creator_puzzle_id, COUNT(*) as recent')->groupBy('creator_puzzle_id')->pluck('recent', 'creator_puzzle_id');

            $players = (int) ($totals->players ?? 0);

            return [
                'published' => $published,
                'players' => $players,
                'attempts' => (int) ($totals->attempts ?? 0),
                'completion_rate' => $players > 0 ? (int) round(((int) $totals->solvers / $players) * 100) : null,
                'recent_attempts' => (int) $recent->sum(),
                'recent_days' => $days,
                'per_work' => $recent->map(fn ($n) => ['recent' => (int) $n])->all(),
            ];
        });
    }

    /** ملخص إداري صغير مخزَّن دقيقة (مبدعون/طلبات/مراجعات/منشورات/لعب/بلاغات). */
    public function adminSummary(): array
    {
        return Cache::remember('creators.admin.summary', now()->addSeconds(60), fn () => [
            'approved_creators' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->count(),
            'pending_applications' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_PENDING)->count(),
            'pending_submissions' => CreatorPuzzleRevision::query()->where('status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW)->count(),
            'published_puzzles' => CreatorPuzzle::query()->where('is_visible', true)->count(),
            'community_plays' => CommunityPuzzleAttempt::query()->count(),
            'open_reports' => CommunityPuzzleReport::query()->where('status', CommunityPuzzleReport::STATUS_OPEN)->count(),
        ]);
    }
}
`````

### `app/Services/Creators/CreatorApplicationService.php`

`sha256: 94dfaba460f59a95d24b0a9a57e9fb42f2f2a89ca915cf75aa9bc34336fa61ad`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E28 - دورة حياة ملف المبدع: تقديم → اعتماد/رفض، ثم تعليق/استعادة. كل انتقال: قفل الصف + فحص الحالة المتوقَّعة (لا انتقال مزدوج بتزامن) + تدقيق + مصالحة ظهور المنشورات.
 * الصلاحيات من RBAC الحالي (creators.review: اعتماد/رفض، creators.manage: تعليق/استعادة) وتُفحص هنا لا بالواجهة وحدها.
 * إشعار الاعتماد/الرفض بعد الـcommit ولا يؤثر فشله على الحالة.
 */
class CreatorApplicationService
{
    public function __construct(
        protected CreatorEligibility $eligibility,
        protected OperationalAuditService $audit,
        protected CreatorVisibilityService $visibility,
        protected CreatorNotifier $notifier,
    ) {}

    public function apply(User $user, string $bio): CreatorProfile
    {
        if (($denial = $this->eligibility->applyDenial($user)) !== null) {
            throw new CreatorException($denial);
        }

        $bio = $this->cleanBio($bio);

        try {
            $profile = DB::transaction(function () use ($user, $bio) {
                $existing = CreatorProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();

                if ($existing !== null && ! $existing->isRejected()) {
                    throw new CreatorException('لديك طلب سابق لم يُغلق.');
                }

                $profile = $existing ?? new CreatorProfile;
                $profile->forceFill([
                    'user_id' => $user->id,
                    'slug' => $existing?->slug ?? $this->uniqueSlug($user),
                    'bio' => $bio,
                    'status' => CreatorProfile::STATUS_PENDING,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'review_note' => null,
                ])->save();

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان لنفس المستخدم: قيد user_id الفريد يحسمها؛ الخاسر يرى رسالة الطلب القائم.
            throw new CreatorException('لديك طلب قيد المراجعة بالفعل.');
        }

        $user->unsetRelation('creatorProfile');

        return $profile;
    }

    public function approve(CreatorProfile $profile, User $actor, ?string $note = null): CreatorProfile
    {
        $this->assertCan($actor, 'creators.review');

        $profile = $this->transition($profile, [CreatorProfile::STATUS_PENDING], CreatorProfile::STATUS_APPROVED, $actor, $note, 'creator_approved');
        $this->notifier->creatorApproved($profile);

        return $profile;
    }

    public function reject(CreatorProfile $profile, User $actor, string $note): CreatorProfile
    {
        $this->assertCan($actor, 'creators.review');
        $note = $this->requireNote($note);

        $profile = $this->transition($profile, [CreatorProfile::STATUS_PENDING], CreatorProfile::STATUS_REJECTED, $actor, $note, 'creator_rejected');
        $this->notifier->creatorRejected($profile);

        return $profile;
    }

    public function suspend(CreatorProfile $profile, User $actor, string $note): CreatorProfile
    {
        $this->assertCan($actor, 'creators.manage');
        $note = $this->requireNote($note);

        return $this->transition($profile, [CreatorProfile::STATUS_APPROVED], CreatorProfile::STATUS_SUSPENDED, $actor, $note, 'creator_suspended');
    }

    public function restore(CreatorProfile $profile, User $actor, ?string $note = null): CreatorProfile
    {
        $this->assertCan($actor, 'creators.manage');

        return $this->transition($profile, [CreatorProfile::STATUS_SUSPENDED], CreatorProfile::STATUS_APPROVED, $actor, $note, 'creator_restored');
    }

    /** @param list<string> $from */
    protected function transition(CreatorProfile $profile, array $from, string $to, User $actor, ?string $note, string $auditAction): CreatorProfile
    {
        $profile = DB::transaction(function () use ($profile, $from, $to, $actor, $note, $auditAction) {
            $locked = CreatorProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if (! in_array($locked->status, $from, true)) {
                throw new CreatorException('لا يمكن تنفيذ هذا الإجراء على الحالة الحالية للمبدع.');
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $to,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'approved_at' => $to === CreatorProfile::STATUS_APPROVED ? ($locked->approved_at ?? now()) : $locked->approved_at,
            ])->save();

            $this->audit->log($auditAction, $locked, ['creator_user_id' => $locked->user_id, 'from' => $previous, 'to' => $to, 'note' => $locked->review_note], $actor);

            return $locked;
        });

        // المصالحة بعد الـcommit: تعليق/استعادة تُخفي/تُظهر منشورات المبدع. فشلها لا يُلغي الحالة (تعيدها أي مصالحة لاحقة).
        $this->visibility->reconcile($profile);

        return $profile->refresh();
    }

    protected function cleanBio(string $bio): string
    {
        $bio = trim(preg_replace('/\s+/u', ' ', $bio) ?? '');
        $min = (int) config('creators.limits.bio_min');
        $max = (int) config('creators.limits.bio_max');

        if (mb_strlen($bio) < $min || mb_strlen($bio) > $max) {
            throw new CreatorException("عرّف بنفسك وبسبب رغبتك بالانضمام ({$min}–{$max} حرفًا).");
        }

        return $bio;
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creators.limits.note_min');
        $max = (int) config('creators.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }

    protected function assertCan(User $actor, string $permission): void
    {
        abort_unless($actor->can($permission), 403);
    }

    /** معرّف رابط آمن ASCII فريد (الاسم العربي يُنقل حرفيًا لاتينيًا؛ الفارغ يصير creator). بلا بيانات خاصة: لا بريد ولا رقم داخلي. */
    protected function uniqueSlug(User $user): string
    {
        $base = Str::slug(Str::ascii((string) $user->name)) ?: 'creator';
        $base = Str::limit($base, 60, '');

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (CreatorProfile::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
`````

### `app/Services/Creators/CreatorContent.php`

`sha256: b4b16028cf043fc998fe2d400f4a0ff69649fd77ae677e3ce22878ef79df8366`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorPuzzleRevision;
use App\Models\PuzzleCategory;
use App\Rules\NoMarkup;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * E28 - مخطط حمولة التأليف لكل نوع مسموح (قائمة السماح بـconfig/creators.php): القواعد + التطبيع. مصدر واحد تستعمله نماذج الطلبات (الإدخال) وإعادة التحقق عند الإرسال/الاعتماد (المخزَّن).
 * المبدع يتحكم بالعنوان/التصنيف/الصعوبة/النص/التلميح/الاختيارات/الجواب/الصورة وإعدادات اللعبة المهيكلة فقط؛ renderer/validation_type/score_mode يشتقّها المحرك (GameTypeRegistry) بمادّة CreatorPuzzleMaterializer.
 */
class CreatorContent
{
    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys((array) config('creators.kinds'));
    }

    public static function kindConfig(string $kind): ?array
    {
        return config("creators.kinds.{$kind}");
    }

    /** قواعد الحقول المشتركة + حقول النوع. $stored=true: بلا حقل رفع الصورة (إعادة تحقق من حمولة محفوظة). */
    public function rules(string $kind, bool $stored = false): array
    {
        $l = (array) config('creators.limits');
        $media = (array) config('creators.media');

        $rules = [
            'title' => ['required', 'string', 'min:'.$l['title_min'], 'max:'.$l['title_max'], new NoMarkup],
            'puzzle_category_id' => ['required', 'integer', Rule::exists('puzzle_categories', 'id')->where('is_active', true)],
            'difficulty' => ['required', Rule::in(config('creators.difficulties'))],
            'prompt' => ['required', 'string', 'min:'.$l['prompt_min'], 'max:'.$l['prompt_max'], new NoMarkup],
            'hint' => ['nullable', 'string', 'max:'.$l['hint_max'], new NoMarkup],
        ];

        $rules += match ($kind) {
            'text' => ['answer' => $this->answerRules()],
            'image' => ['answer' => $this->answerRules()],
            'multiple_choice' => [
                'answer' => $this->answerRules(),
                'choices' => ['required', 'array', 'min:'.$l['choices_min'], 'max:'.$l['choices_max']],
                'choices.*' => ['required', 'string', 'max:'.$l['choice_max'], 'distinct:ignore_case', new NoMarkup],
            ],
            'sequence' => [
                'items' => ['required', 'array', 'min:'.$l['sequence_min'], 'max:'.$l['sequence_max']],
                'items.*' => ['required', 'string', 'max:'.$l['sequence_item_max'], 'distinct:ignore_case', new NoMarkup],
            ],
            'memory' => [
                'faces' => ['required', 'array', 'min:'.$l['memory_min'], 'max:'.$l['memory_max']],
                'faces.*' => ['required', 'string', 'max:'.$l['memory_face_max'], 'distinct:ignore_case', new NoMarkup],
            ],
            default => [],
        };

        if (! $stored && (self::kindConfig($kind)['media'] ?? false)) {
            $rules['image'] = [
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:'.implode(',', array_keys($media['mimes'])), 'max:'.$media['max_kb'],
                'dimensions:min_width='.$media['min_dimension'].',min_height='.$media['min_dimension'].',max_width='.$media['max_dimension'].',max_height='.$media['max_dimension'],
            ];
        }

        return $rules;
    }

    protected function answerRules(): array
    {
        return ['required', 'string', 'max:'.config('creators.limits.answer_max'), new NoMarkup];
    }

    /** حمولة التأليف المطبَّعة (تُشفَّر بالنسخة) من مدخلات مُتحقَّق منها. */
    public function normalize(string $kind, array $input): array
    {
        $content = ['prompt' => $this->clean($input['prompt'] ?? ''), 'hint' => $this->nullable($input['hint'] ?? null)];

        switch ($kind) {
            case 'text':
            case 'image':
                $content['answer'] = $this->clean($input['answer'] ?? '');
                break;
            case 'multiple_choice':
                $content['choices'] = array_values(array_map(fn ($c) => $this->clean($c), (array) ($input['choices'] ?? [])));
                $content['answer'] = $this->clean($input['answer'] ?? '');
                break;
            case 'sequence':
                $content['items'] = array_values(array_map(fn ($c) => $this->clean($c), (array) ($input['items'] ?? [])));
                break;
            case 'memory':
                $content['faces'] = array_values(array_map(fn ($c) => $this->clean($c), (array) ($input['faces'] ?? [])));
                break;
        }

        return $content;
    }

    /** يحوّل نص «سطر لكل عنصر» إلى قائمة (يُستعمل قبل التحقق). */
    public static function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value), fn ($v) => $v !== '' && $v !== null));
        }

        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), fn ($v) => $v !== ''));
    }

    /**
     * جاهزية الإرسال/الاعتماد: إعادة تحقق كاملة من الحمولة **المخزَّنة** (دفاع ثانٍ: لا نثق بأن الحفظ السابق مرّ بالقواعد نفسها) +
     * شرط خاص: نوع الصورة يتطلب صورة موجودة فعلًا + الجواب ضمن الاختيارات.
     *
     * @throws CreatorException
     */
    public function assertReady(CreatorPuzzleRevision $revision): void
    {
        $kind = (string) $revision->kind;

        if (! in_array($kind, self::kinds(), true)) {
            throw new CreatorException('نوع الأحجية غير مدعوم.');
        }

        $content = (array) $revision->content;
        $data = [
            'title' => $revision->title,
            'puzzle_category_id' => $revision->puzzle_category_id,
            'difficulty' => $revision->difficulty,
        ] + $content;

        $validator = Validator::make($data, $this->rules($kind, stored: true));

        if ($validator->fails()) {
            throw new CreatorException((string) $validator->errors()->first());
        }

        if ($kind === 'multiple_choice' && ! in_array((string) ($content['answer'] ?? ''), (array) ($content['choices'] ?? []), true)) {
            throw new CreatorException('الجواب الصحيح يجب أن يكون أحد الاختيارات.');
        }

        if ((self::kindConfig($kind)['media'] ?? false)) {
            if (blank($revision->image_path) || ! Storage::disk((string) config('creators.media.disk'))->exists((string) $revision->image_path)) {
                throw new CreatorException('أضف صورة للأحجية قبل الإرسال.');
            }
        } elseif (filled($revision->image_path)) {
            throw new CreatorException('هذا النوع لا يدعم الصور.');
        }

        if (PuzzleCategory::query()->whereKey($revision->puzzle_category_id)->where('is_active', true)->doesntExist()) {
            throw new CreatorException('التصنيف المختار غير متاح.');
        }
    }

    protected function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    protected function nullable(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value === '' ? null : $value;
    }
}
`````

### `app/Services/Creators/CreatorEligibility.php`

`sha256: 3471b12d4a7ef63117ce6c12d0a52a2f6a9cd949eba08b8d7a89c4212cf23327`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\User;

/**
 * E28 - مصدر حقيقة واحد لأهلية الاستوديو: ملف مبدع معتمَد + بريد موثَّق + حساب غير مجمَّد. ليست صلاحية إدارة: المبدع لا يحتاج admin.access.
 * كل بوابة (ميدلوير، خدمات الكتابة) تمرّ من هنا، فلا تتباعد القواعد.
 */
class CreatorEligibility
{
    public function profileFor(?User $user): ?CreatorProfile
    {
        return $user?->creatorProfile;
    }

    /** سبب رفض الاستوديو بالعربية، أو null إن كان المستخدم مؤهَّلًا. */
    public function studioDenial(?User $user): ?string
    {
        if ($user === null) {
            return 'سجّل الدخول أولًا.';
        }

        if (! $user->hasVerifiedEmail()) {
            return 'وثّق بريدك الإلكتروني أولًا.';
        }

        if ($user->is_frozen) {
            return 'تم تقييد حسابك مؤقتًا.';
        }

        $profile = $this->profileFor($user);

        return match (true) {
            $profile === null => 'قدّم طلب الانضمام للمبدعين أولًا.',
            $profile->isPending() => 'طلبك قيد المراجعة.',
            $profile->isRejected() => 'لم يُقبل طلبك السابق. يمكنك إعادة التقديم.',
            $profile->isSuspended() => 'تم تعليق صلاحيات المبدع لحسابك.',
            default => null,
        };
    }

    public function canUseStudio(?User $user): bool
    {
        return $this->studioDenial($user) === null;
    }

    /** يُعيد ملف المبدع المعتمَد أو يرمي بسبب الرفض (للخدمات: لا كتابة بدونه). */
    public function studioProfile(User $user): CreatorProfile
    {
        $reason = $this->studioDenial($user);

        if ($reason !== null) {
            throw new CreatorException($reason);
        }

        return $this->profileFor($user);
    }

    /** سبب عدم أهلية التقديم كمبدع، أو null. المرفوض يعيد التقديم؛ الباقي (قيد المراجعة/معتمَد/معلَّق) لا. */
    public function applyDenial(?User $user): ?string
    {
        if ($user === null) {
            return 'سجّل الدخول أولًا.';
        }

        if (! $user->hasVerifiedEmail()) {
            return 'وثّق بريدك الإلكتروني أولًا.';
        }

        if ($user->is_frozen) {
            return 'تم تقييد حسابك مؤقتًا.';
        }

        $profile = $this->profileFor($user);

        return match (true) {
            $profile === null, $profile->isRejected() => null,
            $profile->isPending() => 'لديك طلب قيد المراجعة بالفعل.',
            $profile->isApproved() => 'أنت مبدع معتمَد بالفعل.',
            default => 'تم تعليق صلاحيات المبدع لحسابك؛ لا يمكن تقديم طلب جديد.',
        };
    }
}
`````

### `app/Services/Creators/CreatorException.php`

`sha256: 9084b5dffb8da2490a326f58eff91a2a6f4a9108b395519d347baec20c59546c`

`````php
<?php

namespace App\Services\Creators;

use RuntimeException;

/** E28 - خطأ مجال متوقَّع (رسالته عربية آمنة للعرض). الأخطاء غير المتوقعة لا تستعمل هذا الصنف. */
class CreatorException extends RuntimeException {}
`````

### `app/Services/Creators/CreatorMediaService.php`

`sha256: 9b996fdbd1bb4a82bc71128e170ff3c8aaca4bb7f67ad9125e07e1f7278b841e`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E28 - رفع صور أحجيات المبدعين على قرص التخزين الحالي (config/creators.php media.disk). لا بنية تخزين جديدة.
 *  - الصيغ JPEG/PNG/WEBP فقط، بالنوع الفعلي للمحتوى (finfo + getimagesize) لا باسم الملف ولا ترويسة العميل. SVG مرفوض بالبناء (ليس بقائمة الأنواع).
 *  - اسم عشوائي يولّده المخدّم (UUID + لاحقة من النوع المكتشف) داخل مجلد المالك: اسم العميل لا يدخل بالمسار إطلاقًا (لا اجتياز مسارات).
 *  - الملكية: الحذف/الاستبدال لا يطال إلا ملفات داخل مجلد المبدع نفسه (ownedBy) ولا يقبل مسارًا من العميل أصلًا (المسار يأتي من النسخة المحفوظة).
 *  - التنظيف: أي استبدال/حذف نسخة يحذف ملفها؛ المتبقي اليتيم (فشل نادر) يعالجه الأمر creators:prune-media.
 */
class CreatorMediaService
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('creators.media.disk'));
    }

    /** رابط عرض صورة (قرص الوسائط المضبوط). */
    public function url(?string $path): ?string
    {
        return blank($path) ? null : $this->disk()->url($path);
    }

    public function directoryFor(CreatorProfile $owner): string
    {
        return trim((string) config('creators.media.directory'), '/').'/'.$owner->id;
    }

    /** @throws CreatorException */
    public function store(UploadedFile $file, CreatorProfile $owner): string
    {
        $allowed = (array) config('creators.media.mimes');
        $path = $file->getRealPath();
        $mime = $path ? (string) mime_content_type($path) : '';
        $info = $path ? @getimagesize($path) : false;

        if (! isset($allowed[$mime]) || $info === false || ! isset($allowed[$info['mime'] ?? '']) || $info['mime'] !== $mime) {
            throw new CreatorException('صيغة الصورة غير مدعومة. المسموح: JPEG أو PNG أو WEBP.');
        }

        $name = Str::uuid()->toString().'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directoryFor($owner), $file, $name);

        if ($stored === false) {
            throw new CreatorException('تعذّر حفظ الصورة، حاول لاحقًا.');
        }

        return $stored;
    }

    public function ownedBy(?string $path, CreatorProfile $owner): bool
    {
        if (blank($path) || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, $this->directoryFor($owner).'/');
    }

    /** يحذف ملف المالك فقط (تجاهل صامت لغيره/لغير الموجود). */
    public function delete(?string $path, CreatorProfile $owner): void
    {
        if ($this->ownedBy($path, $owner) && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /** نسخة مستقلة من ملف المالك لنسخة جديدة (كل نسخة تملك ملفها، فحذف مسودة لا يمسّ المنشور). */
    public function copy(?string $path, CreatorProfile $owner): ?string
    {
        if (! $this->ownedBy($path, $owner) || ! $this->disk()->exists($path)) {
            return null;
        }

        $target = $this->directoryFor($owner).'/'.Str::uuid()->toString().'.'.pathinfo($path, PATHINFO_EXTENSION);

        return $this->disk()->copy($path, $target) ? $target : null;
    }
}
`````

### `app/Services/Creators/CreatorNotifier.php`

`sha256: a8635e49cf1df0eb93f9e92fbed61c864d107b1427276306b4c9bd3b250c21ae`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E28 - إشعارات المبدع (E15). تُستدعى دائمًا **بعد** نجاح تغيير الحالة (بعد الـcommit) وفشلها لا يُرجع أي حالة: إخفاق الإشعار لا يتراجع عنه الاعتماد/النشر.
 * مفاتيح idempotency دلالية (حدث واحد = إشعار واحد). لا إشعار على اللعب أبدًا.
 */
class CreatorNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function creatorApproved(CreatorProfile $profile): void
    {
        $this->send($profile, NotificationType::CreatorApproved, [], 'creator_approved:'.$profile->id.':'.($profile->reviewed_at?->timestamp ?? 0));
    }

    public function creatorRejected(CreatorProfile $profile): void
    {
        $this->send($profile, NotificationType::CreatorRejected, ['note' => $profile->review_note], 'creator_rejected:'.$profile->id.':'.($profile->reviewed_at?->timestamp ?? 0));
    }

    public function submissionApproved(CreatorPuzzleRevision $revision): void
    {
        $this->send($revision->work->creator, NotificationType::SubmissionApproved, ['title' => $revision->title], 'submission_approved:'.$revision->id);
    }

    public function changesRequested(CreatorPuzzleRevision $revision): void
    {
        $this->send($revision->work->creator, NotificationType::SubmissionChangesRequested, ['title' => $revision->title, 'note' => $revision->review_note], 'submission_changes_requested:'.$revision->id.':'.($revision->reviewed_at?->timestamp ?? 0));
    }

    public function submissionRejected(CreatorPuzzleRevision $revision): void
    {
        $this->send($revision->work->creator, NotificationType::SubmissionRejected, ['title' => $revision->title, 'note' => $revision->review_note], 'submission_rejected:'.$revision->id);
    }

    public function publicationHidden(CreatorPuzzle $work): void
    {
        $this->send($work->creator, NotificationType::PublicationHidden, ['title' => (string) $work->published_title], 'publication_hidden:'.$work->id.':'.($work->hidden_at?->timestamp ?? 0));
    }

    protected function send(CreatorProfile $profile, NotificationType $type, array $params, string $key): void
    {
        try {
            $user = $profile->user;

            if ($user !== null) {
                $this->dispatcher->dispatch($user, $type, $params, $key);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Services/Creators/CreatorPreviewService.php`

`sha256: 693b4e52bc0ff1e23f4ec62e84f2e22b52a88b06f341422df4d83ff9d7a118bc`

`````php
<?php

namespace App\Services\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;

/**
 * E28 - معاينة المبدع/المراجع: **المحرك والعارض والمدقّق نفسها** على أحجية غير محفوظة تُبنى من النسخة (CreatorPuzzleMaterializer::build) بلا أي كتابة.
 * لا محاولات ولا جواهر ولا XP ولا مهام ولا تقدّم ولا تسجيل لعب: النتيجة تُعرض فقط. الرابط غير عام (مالك/مراجع) ولا يُفهرَس.
 */
class CreatorPreviewService
{
    public function __construct(
        protected CreatorPuzzleMaterializer $materializer,
        protected GameTypeRegistry $games,
    ) {}

    /** @return array{puzzle: Puzzle, renderer: string} */
    public function view(CreatorPuzzleRevision $revision): array
    {
        $puzzle = $this->materializer->build($revision);

        return ['puzzle' => $puzzle, 'renderer' => $this->games->rendererFor($puzzle)];
    }

    public function check(CreatorPuzzleRevision $revision, string $answer, array $submission, ?int $userId): PreviewResult
    {
        $puzzle = $this->materializer->build($revision);
        $payload = $submission !== [] ? $submission : ['answer' => $answer];

        return new PreviewResult($this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => $userId]])->correct);
    }
}
`````

### `app/Services/Creators/CreatorPuzzleMaterializer.php`

`sha256: 792c5dffafb8fd54ca672fc546b9386c71fc0cb822ff7cd23210d01079bcf5aa`

`````php
<?php

namespace App\Services\Creators;

use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\SequenceTokens;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;

/**
 * E28 - يحوّل نسخة تأليف إلى أحجية قانونية (Puzzle) بالمحرك القائم نفسه: لا محرك ثانٍ ولا مدقّق ثانٍ.
 *  - renderer/validation_type/score_mode/game_type تُشتقّ حصرًا من Definition النوع (GameTypeRegistry) لا من المبدع.
 *  - الحفظ يمرّ بـPuzzle::saving → GameTypeRegistry::prepareForSave (تطبيع solution_data/cards) كأي أحجية، وبحارس النموذج الذي يفرض origin=community
 *    ومكافأة 0/XP 0/غير مفعّلة/بلا يومية على كل أحجية مجتمع.
 *  - بعد الحفظ فحص ذاتي: المدقّق الرسمي نفسه يجب أن يقبل حلّ المبدع، وإلا يفشل الاعتماد (rollback) فلا يُنشر ما لا يُحلّ.
 */
class CreatorPuzzleMaterializer
{
    public function __construct(protected GameTypeRegistry $games) {}

    /** أحجية غير محفوظة (للمعاينة وللمادّة): مُطبَّعة بالمحرك. */
    public function build(CreatorPuzzleRevision $revision): Puzzle
    {
        $cfg = CreatorContent::kindConfig((string) $revision->kind) ?? throw new CreatorException('نوع الأحجية غير مدعوم.');
        $content = (array) $revision->content;
        $definition = $this->games->definitionFor($cfg['game_type']);

        $puzzle = new Puzzle;
        $puzzle->fill([
            'puzzle_category_id' => $revision->puzzle_category_id,
            'title' => $revision->title,
            'type' => $cfg['type'],
            'difficulty' => $revision->difficulty,
            'prompt' => $content['prompt'] ?? '',
            'hint' => $content['hint'] ?? null,
            'max_attempts' => (int) config('creators.puzzle.max_attempts'),
            'gem_reward' => (int) config('creators.puzzle.gem_reward'),
            'xp_reward' => (int) config('creators.puzzle.xp_reward'),
            'is_active' => false,
            'game_type' => $cfg['game_type'],
            'renderer' => $definition->renderer(),
            'validation_type' => $definition->validationType(),
            'score_mode' => $definition->scoreMode(),
        ]);

        switch ($revision->kind) {
            case 'text':
                $puzzle->answer_raw = (string) ($content['answer'] ?? '');
                break;
            case 'image':
                $puzzle->answer_raw = (string) ($content['answer'] ?? '');
                $puzzle->image_path = $revision->image_path;
                break;
            case 'multiple_choice':
                $puzzle->choices = array_values((array) ($content['choices'] ?? []));
                $puzzle->answer_raw = (string) ($content['answer'] ?? '');
                break;
            case 'sequence':
                $puzzle->game_config = ['items' => array_values((array) ($content['items'] ?? []))];
                break;
            case 'memory':
                $puzzle->game_config = ['faces' => array_values((array) ($content['faces'] ?? []))];
                break;
        }

        $puzzle->forceFill(['origin' => Puzzle::ORIGIN_COMMUNITY]);
        $this->games->prepareForSave($puzzle);

        return $puzzle;
    }

    /** @throws CreatorException */
    public function materialize(CreatorPuzzleRevision $revision): Puzzle
    {
        $puzzle = $this->build($revision);
        $puzzle->save();

        if (! $this->solvesWithOfficialValidator($puzzle, (array) $revision->content, (string) $revision->kind)) {
            throw new CreatorException('الحل الذي أدخله المبدع لا يجتاز التحقق الرسمي للأحجية.');
        }

        return $puzzle;
    }

    /** هل يقبل المدقّق الرسمي حلّ المبدع نفسه؟ (تستعمله المعاينة أيضًا لتحذير المراجع). */
    public function solvesWithOfficialValidator(Puzzle $puzzle, array $content, string $kind): bool
    {
        $payload = match ($kind) {
            'sequence' => ['order' => SequenceTokens::expectedOrder($puzzle)],
            'memory' => ['matches' => $this->memoryPairs($puzzle)],
            default => ['answer' => (string) ($content['answer'] ?? '')],
        };

        return $this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => null]])->correct;
    }

    /** @return list<array{0:int,1:int}> */
    protected function memoryPairs(Puzzle $puzzle): array
    {
        $byFace = [];

        foreach ((array) ($puzzle->game_config['cards'] ?? []) as $card) {
            $byFace[$card['face']][] = $card['id'];
        }

        return array_values(array_map(fn ($ids) => [$ids[0], $ids[1] ?? $ids[0]], $byFace));
    }
}
`````

### `app/Services/Creators/CreatorPuzzleService.php`

`sha256: f419e2d7e20335c696f2b86302d226f6ec960ac78b28b95f58e60c8266f57fa6`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * E28 - دورة تأليف المبدع: مسودة ← إرسال ← (طلب تعديل ↔ إعادة إرسال | اعتماد | رفض)، ثم نسخة جديدة بعد النشر، أرشفة، حذف.
 *
 * كل عملية: (1) المؤهَّل = ملف معتمَد + بريد موثَّق + غير مجمَّد (CreatorEligibility) فالمعلَّق/المجمَّد لا ينشئ ولا يعدّل ولا يرسل،
 * (2) الملكية بالاستعلام نفسه (creator_profile_id) فعمل غيرك = 404 كأنه غير موجود (لا IDOR)، (3) قفل صف النسخة بمعاملة فلا تعارض تزامن،
 * (4) النسخة المنشورة/المرفوضة/المرسَلة لا تُعدَّل أبدًا: التعديل بعد النشر = نسخة جديدة (revise) تبقى القديمة حيّة حتى اعتماد الجديدة.
 */
class CreatorPuzzleService
{
    public const BUCKETS = ['all', 'draft', 'pending', 'changes', 'published', 'rejected', 'archived'];

    public function __construct(
        protected CreatorEligibility $eligibility,
        protected CreatorContent $content,
        protected CreatorMediaService $media,
    ) {}

    // ───────────── القراءة ─────────────

    /** عمل المبدع نفسه فقط (404 لغيره). */
    public function ownedWork(CreatorProfile $profile, string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->where('public_id', $publicId)->firstOrFail();
    }

    /** قائمة أعمال المبدع بتصفية الحالة (مشتقّة من آخر نسخة + الأرشفة) وترقيم؛ بلا أعمدة المحتوى المشفَّر. */
    public function paginate(CreatorProfile $profile, string $bucket = 'all'): LengthAwarePaginator
    {
        $query = CreatorPuzzle::query()
            ->select('creator_puzzles.*')
            ->where('creator_puzzles.creator_profile_id', $profile->id)
            ->leftJoin('creator_puzzle_revisions as lr', 'lr.id', '=', 'creator_puzzles.latest_revision_id');

        match ($bucket) {
            'draft' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_DRAFT),
            'pending' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW),
            'changes' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED),
            'published' => $query->whereNull('creator_puzzles.archived_at')->whereNotNull('creator_puzzles.published_puzzle_id'),
            'rejected' => $query->whereNull('creator_puzzles.archived_at')->where('lr.status', CreatorPuzzleRevision::STATUS_REJECTED),
            'archived' => $query->whereNotNull('creator_puzzles.archived_at'),
            default => null,
        };

        return $query
            ->with(['latestRevision' => fn ($q) => $q->select('id', 'creator_puzzle_id', 'revision_number', 'status', 'kind', 'title', 'puzzle_category_id', 'difficulty', 'review_note', 'reviewed_at', 'submitted_at'), 'latestRevision.category:id,name'])
            ->orderByDesc('creator_puzzles.id')
            ->paginate((int) config('creators.page_size'))
            ->withQueryString();
    }

    /** آخر أعمال المبدع للوحة (بلا ترقيم، سقف صغير). */
    public function recent(CreatorProfile $profile, int $limit = 5)
    {
        return CreatorPuzzle::query()
            ->where('creator_profile_id', $profile->id)
            ->with(['latestRevision' => fn ($q) => $q->select('id', 'creator_puzzle_id', 'revision_number', 'status', 'kind', 'title', 'puzzle_category_id', 'difficulty', 'review_note', 'reviewed_at', 'submitted_at'), 'latestRevision.category:id,name'])
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** عدّادات حاويات اللوحة باستعلام واحد (المبدع محدود بسقف أعمال، فالتجميع صغير ومفهرس بالمالك). */
    public function bucketCounts(CreatorProfile $profile): array
    {
        $row = CreatorPuzzle::query()
            ->leftJoin('creator_puzzle_revisions as lr', 'lr.id', '=', 'creator_puzzles.latest_revision_id')
            ->where('creator_puzzles.creator_profile_id', $profile->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as draft', [CreatorPuzzleRevision::STATUS_DRAFT])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as pending', [CreatorPuzzleRevision::STATUS_PENDING_REVIEW])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as changes', [CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND creator_puzzles.published_puzzle_id IS NOT NULL THEN 1 ELSE 0 END) as published')
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NULL AND lr.status = ? THEN 1 ELSE 0 END) as rejected', [CreatorPuzzleRevision::STATUS_REJECTED])
            ->selectRaw('SUM(CASE WHEN creator_puzzles.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived')
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

    // ───────────── الكتابة ─────────────

    /** @param array<string, mixed> $data مدخلات مُتحقَّق منها (kind, title, puzzle_category_id, difficulty, ...حقول النوع) */
    public function createDraft(User $user, array $data, ?UploadedFile $image = null): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $kind = (string) ($data['kind'] ?? '');

        if (! in_array($kind, CreatorContent::kinds(), true)) {
            throw new CreatorException('نوع الأحجية غير مدعوم.');
        }

        $imagePath = null;

        try {
            return DB::transaction(function () use ($profile, $kind, $data, $image, &$imagePath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first(); // يسلسل فحص السقوف للمبدع الواحد
                $this->assertCaps($profile, newWork: true);

                $work = new CreatorPuzzle;
                $work->forceFill(['creator_profile_id' => $profile->id])->save();

                if ($image !== null && (CreatorContent::kindConfig($kind)['media'] ?? false)) {
                    $imagePath = $this->media->store($image, $profile);
                }

                $revision = new CreatorPuzzleRevision;
                $revision->forceFill([
                    'creator_puzzle_id' => $work->id,
                    'revision_number' => 1,
                    'status' => CreatorPuzzleRevision::STATUS_DRAFT,
                    'kind' => $kind,
                    'title' => trim((string) $data['title']),
                    'puzzle_category_id' => (int) $data['puzzle_category_id'],
                    'difficulty' => (string) $data['difficulty'],
                    'content' => $this->content->normalize($kind, $data),
                    'image_path' => $imagePath,
                ])->save();

                $work->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($imagePath, $profile);

            throw $e;
        }
    }

    public function updateDraft(User $user, string $publicId, array $data, ?UploadedFile $image = null, bool $removeImage = false): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);
        $newPath = null;
        $oldPath = null;

        try {
            $revision = DB::transaction(function () use ($work, $profile, $data, $image, $removeImage, &$newPath, &$oldPath) {
                $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

                if (! $revision->isEditable()) {
                    throw new CreatorException($revision->isPendingReview() ? 'الأحجية قيد المراجعة؛ اسحبها أولًا لتعديلها.' : 'لا يمكن تعديل هذه النسخة؛ أنشئ نسخة جديدة.');
                }

                $kind = (string) $revision->kind;
                $supportsImage = (bool) (CreatorContent::kindConfig($kind)['media'] ?? false);
                $path = $revision->image_path;

                if ($supportsImage && $image !== null) {
                    $newPath = $this->media->store($image, $profile);
                    $oldPath = $path;
                    $path = $newPath;
                } elseif ($supportsImage && $removeImage) {
                    $oldPath = $path;
                    $path = null;
                }

                $revision->forceFill([
                    'title' => trim((string) $data['title']),
                    'puzzle_category_id' => (int) $data['puzzle_category_id'],
                    'difficulty' => (string) $data['difficulty'],
                    'content' => $this->content->normalize($kind, $data),
                    'image_path' => $path,
                ])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($newPath, $profile);

            throw $e;
        }

        $this->media->delete($oldPath, $profile); // بعد الـcommit: الملف القديم لا يُحذف قبل نجاح الاستبدال

        return $revision;
    }

    /** حذف الصورة وحدها من مسودة (مسار مستقل للتحقق من الملكية). */
    public function removeImage(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        $oldPath = null;

        $revision = DB::transaction(function () use ($work, &$oldPath) {
            $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if (! $revision->isEditable()) {
                throw new CreatorException('لا يمكن تعديل هذه النسخة الآن.');
            }

            $oldPath = $revision->image_path;
            $revision->forceFill(['image_path' => null])->save();

            return $revision;
        });

        $this->media->delete($oldPath, $profile);

        return $revision;
    }

    public function submit(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        return DB::transaction(function () use ($work, $profile) {
            CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if (! $revision->isEditable()) {
                throw new CreatorException('هذه النسخة أُرسلت بالفعل أو لا يمكن إرسالها.');
            }

            $this->content->assertReady($revision);

            $pending = CreatorPuzzleRevision::query()
                ->join('creator_puzzles', 'creator_puzzles.id', '=', 'creator_puzzle_revisions.creator_puzzle_id')
                ->where('creator_puzzles.creator_profile_id', $profile->id)
                ->where('creator_puzzle_revisions.status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW)
                ->count();

            if ($pending >= (int) config('creators.caps.pending_review')) {
                throw new CreatorException('وصلت للحد الأقصى من الأحجيات قيد المراجعة؛ انتظر نتيجة مراجعتها.');
            }

            $revision->forceFill([
                'status' => CreatorPuzzleRevision::STATUS_PENDING_REVIEW,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            return $revision;
        });
    }

    /** سحب إرسال قيد المراجعة إلى مسودة (يخضع لمحدّد creator-submit كي لا تُغرَق المراجعة بدورات إرسال/سحب). */
    public function withdraw(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        return DB::transaction(function () use ($work) {
            $revision = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if (! $revision->isPendingReview()) {
                throw new CreatorException('لا يوجد إرسال قيد المراجعة لسحبه.');
            }

            $revision->forceFill(['status' => CreatorPuzzleRevision::STATUS_DRAFT, 'submitted_at' => null])->save();

            return $revision;
        });
    }

    /** نسخة جديدة مسودة (تعديل بعد النشر/إعادة بعد الرفض) من آخر نسخة نهائية؛ المنشورة الحالية تبقى حيّة بلا مساس. */
    public function revise(User $user, string $publicId): CreatorPuzzleRevision
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        $copiedPath = null;

        try {
            return DB::transaction(function () use ($work, $profile, &$copiedPath) {
                CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
                $latest = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

                if (! in_array($latest->status, [CreatorPuzzleRevision::STATUS_APPROVED, CreatorPuzzleRevision::STATUS_REJECTED], true)) {
                    throw new CreatorException('لديك نسخة مفتوحة بالفعل لهذه الأحجية.');
                }

                $this->assertCaps($profile, newWork: false);

                $copiedPath = $this->media->copy($latest->image_path, $profile);

                $revision = new CreatorPuzzleRevision;
                $revision->forceFill([
                    'creator_puzzle_id' => $work->id,
                    'revision_number' => (int) CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->max('revision_number') + 1,
                    'status' => CreatorPuzzleRevision::STATUS_DRAFT,
                    'kind' => $latest->kind,
                    'title' => $latest->title,
                    'puzzle_category_id' => $latest->puzzle_category_id,
                    'difficulty' => $latest->difficulty,
                    'content' => $latest->content,
                    'image_path' => $copiedPath,
                ])->save();

                $work->forceFill(['latest_revision_id' => $revision->id])->save();

                return $revision;
            });
        } catch (\Throwable $e) {
            $this->media->delete($copiedPath, $profile);

            throw $e;
        }
    }

    /**
     * حذف: عمل لم يُنشر قط يُحذف كاملًا (مع ملفاته)؛ عمل منشور لا يُحذف أبدًا (الأرشفة) لكن مسودة التعديل المفتوحة عليه تُحذف وحدها.
     * قيد المراجعة لا يُحذف (يُسحب أولًا).
     */
    public function destroy(User $user, string $publicId): void
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);
        $paths = [];

        DB::transaction(function () use ($work, &$paths) {
            $latest = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($work->latest_revision_id);

            if ($latest->isPendingReview()) {
                throw new CreatorException('اسحب الأحجية من المراجعة أولًا.');
            }

            if ($work->isPublished()) {
                if (! $latest->isEditable()) {
                    throw new CreatorException('الأحجية المنشورة لا تُحذف؛ يمكنك أرشفتها.');
                }

                $paths[] = $latest->image_path;
                $latest->delete();

                $previous = CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->orderByDesc('revision_number')->first();
                $work->forceFill(['latest_revision_id' => $previous?->id])->save();

                return;
            }

            $paths = CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->pluck('image_path')->all();
            $work->delete();
        });

        foreach ($paths as $path) {
            $this->media->delete($path, $profile);
        }
    }

    public function archive(User $user, string $publicId): CreatorPuzzle
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        if (! $work->isPublished()) {
            throw new CreatorException('الأرشفة للأحجيات المنشورة فقط.');
        }

        if ($work->isArchived()) {
            return $work;
        }

        $work->forceFill(['archived_at' => now()])->save();

        return $work;
    }

    public function unarchive(User $user, string $publicId): CreatorPuzzle
    {
        $profile = $this->eligibility->studioProfile($user);
        $work = $this->ownedWork($profile, $publicId);

        if ($work->isArchived()) {
            $work->forceFill(['archived_at' => null])->save();
        }

        return $work;
    }

    protected function assertCaps(CreatorProfile $profile, bool $newWork): void
    {
        if ($newWork && CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->count() >= (int) config('creators.caps.works')) {
            throw new CreatorException('وصلت للحد الأقصى من الأعمال. احذف مسودة أو أرشف عملًا قديمًا.');
        }

        $open = CreatorPuzzleRevision::query()
            ->join('creator_puzzles', 'creator_puzzles.id', '=', 'creator_puzzle_revisions.creator_puzzle_id')
            ->where('creator_puzzles.creator_profile_id', $profile->id)
            ->whereIn('creator_puzzle_revisions.status', [CreatorPuzzleRevision::STATUS_DRAFT, CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED])
            ->count();

        if ($open >= (int) config('creators.caps.open_drafts')) {
            throw new CreatorException('لديك مسودات كثيرة مفتوحة؛ أرسل بعضها للمراجعة أو احذفها أولًا.');
        }
    }
}
`````

### `app/Services/Creators/CreatorReviewService.php`

`sha256: af388dcc50a10c05dd878d435ac62dda1805f6590d776b5caab7c33f43f2fb3c`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E28 - مراجعة الأحجيات المرسلة. المراجع بصلاحية creators.review فقط (RBAC)، ولا يعدّل محتوى المبدع بصمت: الخيارات اعتماد/طلب تعديل/رفض.
 *
 * الاعتماد ذرّي: قفل النسخة والعمل ← إعادة تحقق كاملة من الحمولة ← مادّة الأحجية القانونية بالمحرك + فحص ذاتي بالمدقّق الرسمي ← ربط النسخة بالأحجية ← تحويل لقطة النشر ← تدقيق.
 * أي فشل بأي خطوة يُرجع كل شيء (لا نشر لما لا يُحلّ ولا نشر نصف مكتمل). الإشعار بعد الـcommit ولا يتراجع الاعتماد بفشله.
 * النسخة القديمة المنشورة لا تُحذف ولا تُعدَّل: تبقى أحجيتها القانونية القديمة وكل محاولاتها؛ لقطة العمل فقط تتحول للنسخة الجديدة.
 * ملاحظة: طلب التعديل والرفض يشترطان سببًا مكتوبًا (يظهر للمبدع بالاستوديو وبالإشعار).
 */
class CreatorReviewService
{
    public function __construct(
        protected CreatorContent $content,
        protected CreatorPuzzleMaterializer $materializer,
        protected OperationalAuditService $audit,
        protected CreatorNotifier $notifier,
    ) {}

    public function approve(CreatorPuzzleRevision $revision, User $actor, ?string $note = null): CreatorPuzzleRevision
    {
        $this->assertReviewer($actor);

        $approved = DB::transaction(function () use ($revision, $actor, $note) {
            $locked = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($locked->creator_puzzle_id);
            $profile = CreatorProfile::query()->with('user')->findOrFail($work->creator_profile_id);

            if (! $profile->isApproved() || (bool) $profile->user?->is_frozen) {
                throw new CreatorException('لا يمكن اعتماد أحجية مبدع معلَّق أو حسابه مقيَّد.');
            }

            $this->content->assertReady($locked);
            $puzzle = $this->materializer->materialize($locked);

            $locked->forceFill([
                'status' => CreatorPuzzleRevision::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => filled($note) ? trim((string) $note) : null,
                'puzzle_id' => $puzzle->id,
            ])->save();

            $work->forceFill([
                'published_revision_id' => $locked->id,
                'published_puzzle_id' => $puzzle->id,
                'published_title' => $locked->title,
                'published_category_id' => $locked->puzzle_category_id,
                'published_difficulty' => $locked->difficulty,
                'published_at' => now(),
                'first_published_at' => $work->first_published_at ?? now(),
            ])->save();

            $this->audit->log('creator_submission_approved', $locked, [
                'work' => $work->public_id,
                'revision' => $locked->revision_number,
                'puzzle_id' => $puzzle->id,
                'title' => $locked->title,
            ], $actor);

            return $locked;
        });

        $this->notifier->submissionApproved($approved->load('work.creator.user'));

        return $approved;
    }

    public function requestChanges(CreatorPuzzleRevision $revision, User $actor, string $note): CreatorPuzzleRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorPuzzleRevision::STATUS_CHANGES_REQUESTED, 'creator_submission_changes_requested');
        $this->notifier->changesRequested($revision->load('work.creator.user'));

        return $revision;
    }

    public function reject(CreatorPuzzleRevision $revision, User $actor, string $note): CreatorPuzzleRevision
    {
        $revision = $this->close($revision, $actor, $note, CreatorPuzzleRevision::STATUS_REJECTED, 'creator_submission_rejected');
        $this->notifier->submissionRejected($revision->load('work.creator.user'));

        return $revision;
    }

    protected function close(CreatorPuzzleRevision $revision, User $actor, string $note, string $status, string $auditAction): CreatorPuzzleRevision
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        return DB::transaction(function () use ($revision, $actor, $note, $status, $auditAction) {
            $locked = CreatorPuzzleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertPending($locked);

            $locked->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, ['work' => $locked->work()->value('public_id'), 'revision' => $locked->revision_number, 'title' => $locked->title, 'note' => $note], $actor);

            return $locked;
        });
    }

    protected function assertPending(CreatorPuzzleRevision $revision): void
    {
        if (! $revision->isPendingReview()) {
            throw new CreatorException('هذه النسخة ليست قيد المراجعة (ربما عولجت للتو).');
        }
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);
        $min = (int) config('creators.limits.note_min');
        $max = (int) config('creators.limits.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorException("اكتب سببًا واضحًا ({$min}–{$max} حرفًا).");
        }

        return $note;
    }
}
`````

### `app/Services/Creators/CreatorVisibilityService.php`

`sha256: 3d366cd96b02a854599872cd17c856e952d04fc4d4141eae7b03149f3f44c03e`

`````php
<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E28 - كل ما يخصّ ظهور المنشور للعامة بمكان واحد:
 *  - إخفاء/استعادة إشرافي فوري (يُبقي التاريخ كله؛ سبب 'moderation' لا تلغيه إلا استعادة إشرافية).
 *  - مصالحة التعليق/التجميد: مبدع غير معتمَد أو حساب مجمَّد = منشوراته تُخفى (سبب creator_suspended/account_frozen) وتعود عند الرفع.
 *    idempotent: تُستدعى بعد كل تغيّر حالة (تعليق/استعادة/اعتماد/تجميد/رفع تجميد) ولا تمسّ المخفي إشرافيًا.
 * is_visible تُشتقّ بحفظ النموذج نفسه، فكل المسارات تمرّ بالحفظ (لا تحديث جماعي خام).
 */
class CreatorVisibilityService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected CreatorNotifier $notifier,
    ) {}

    public function hide(CreatorPuzzle $work, User $actor, string $note): CreatorPuzzle
    {
        $this->assertReviewer($actor);
        $note = $this->requireNote($note);

        $work = DB::transaction(function () use ($work, $actor, $note) {
            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($work->id);

            if (! $work->isPublished()) {
                throw new CreatorException('لا يوجد منشور لإخفائه.');
            }

            if ($work->isHidden() && $work->hidden_reason === CreatorPuzzle::HIDDEN_MODERATION) {
                throw new CreatorException('هذا المنشور مخفي بالفعل.');
            }

            $work->forceFill([
                'hidden_at' => now(),
                'hidden_by' => $actor->id,
                'hidden_reason' => CreatorPuzzle::HIDDEN_MODERATION,
                'hidden_note' => $note,
            ])->save();

            $this->audit->log('community_puzzle_hidden', $work, ['title' => $work->published_title, 'note' => $note], $actor);

            return $work;
        });

        $this->notifier->publicationHidden($work->loadMissing('creator.user'));

        return $work;
    }

    public function restore(CreatorPuzzle $work, User $actor): CreatorPuzzle
    {
        $this->assertReviewer($actor);

        return DB::transaction(function () use ($work, $actor) {
            $work = CreatorPuzzle::query()->lockForUpdate()->findOrFail($work->id);

            if ($work->hidden_reason !== CreatorPuzzle::HIDDEN_MODERATION) {
                throw new CreatorException('هذا المنشور غير مخفي إشرافيًا.');
            }

            $work->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null, 'hidden_note' => null])->save();
            $this->audit->log('community_puzzle_restored', $work, ['title' => $work->published_title], $actor);

            // مبدع معلَّق/حساب مجمَّد يبقى منشوره مخفيًا بسببه الخاص.
            $this->reconcile($work->creator()->first());

            return $work->refresh();
        });
    }

    /** يضبط إخفاء كل منشورات المبدع بحسب حالته وحالة حسابه. آمن للتكرار. */
    public function reconcile(?CreatorProfile $profile): void
    {
        if ($profile === null) {
            return;
        }

        $profile->loadMissing('user');
        $reason = match (true) {
            ! $profile->isApproved() => CreatorPuzzle::HIDDEN_CREATOR_SUSPENDED,
            (bool) $profile->user?->is_frozen => CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN,
            default => null,
        };

        CreatorPuzzle::query()
            ->where('creator_profile_id', $profile->id)
            ->whereNotNull('published_puzzle_id')
            ->chunkById(100, function ($works) use ($reason) {
                foreach ($works as $work) {
                    if ($reason !== null && $work->hidden_at === null) {
                        $work->forceFill(['hidden_at' => now(), 'hidden_by' => null, 'hidden_reason' => $reason, 'hidden_note' => null])->save();
                    } elseif ($reason === null && in_array($work->hidden_reason, [CreatorPuzzle::HIDDEN_CREATOR_SUSPENDED, CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN], true)) {
                        $work->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null, 'hidden_note' => null])->save();
                    } elseif ($reason !== null && $work->hidden_reason !== CreatorPuzzle::HIDDEN_MODERATION && $work->hidden_reason !== $reason) {
                        $work->forceFill(['hidden_reason' => $reason])->save(); // تبدّل السبب (تعليق ← تجميد) دون كشف المنشور
                    }
                }
            });
    }

    public function reconcileForUser(User $user): void
    {
        $this->reconcile($user->creatorProfile()->first());
    }

    protected function assertReviewer(User $actor): void
    {
        abort_unless($actor->can('creators.review'), 403);
    }

    protected function requireNote(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) < (int) config('creators.limits.note_min') || mb_strlen($note) > (int) config('creators.limits.note_max')) {
            throw new CreatorException('اكتب سببًا واضحًا ('.config('creators.limits.note_min').'–'.config('creators.limits.note_max').' حرفًا).');
        }

        return $note;
    }
}
`````

### `app/Services/Creators/PreviewResult.php`

`sha256: 51046183c9c971065b2f23fc802ff361df56ad9c203c78176be8d9ef1abe6895`

`````php
<?php

namespace App\Services\Creators;

/** E28 - نتيجة معاينة: صحيحة/خاطئة فقط (بلا مكافآت ولا تخزين). */
final class PreviewResult
{
    public function __construct(public readonly bool $correct) {}
}
`````

### `app/Services/Search/Sources/CommunitySource.php`

`sha256: 27949938cea5a85ee703d239070d114bbf7ed161b00409ecb09b7c9bf6a91571`

`````php
<?php

namespace App\Services\Search\Sources;

use App\Models\CreatorPuzzle;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E28 / E27 - أحجيات المجتمع: **نوع بحث مستقل** بوسم «من المجتمع» (لا يختلط بالأحجيات الرسمية ولا يرث ترتيبها). قاعدة الظهور = is_visible وحدها
 * (منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد بحساب سليم) — نفس شرط صفحة /community/puzzles العامة، فالمسودات والمرفوضة والمخفية والمؤرشفة والمعلَّقة لا تُكتشف أبدًا.
 * البحث بعنوان المنشور **فقط** (published_title): لا يلمس المحتوى المشفَّر ولا الجواب ولا التلميح ولا الاختيارات، فلا يصلح «أوراكل» لتخمين الجواب.
 * الأعمدة مختارة صراحة وبضمّ واحد (التصنيف + اسم المبدع) فلا N+1 ولا استعلامات إضافية. لا يُكشَف معرّف داخلي: الرابط بمعرّف ULID عام.
 */
class CommunitySource extends EloquentSource
{
    private const DIFFICULTY = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public function type(): string
    {
        return 'community';
    }

    public function label(): string
    {
        return 'أحجيات المجتمع';
    }

    public function icon(): string
    {
        return 'friends';
    }

    protected function table(): string
    {
        return 'creator_puzzles';
    }

    protected function column(): string
    {
        return 'creator_puzzles.published_title';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return CreatorPuzzle::query()->visible()
            ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_puzzles.creator_profile_id')
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->leftJoin('puzzle_categories', 'puzzle_categories.id', '=', 'creator_puzzles.published_category_id')
            ->select(
                'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.published_title', 'creator_puzzles.published_difficulty',
                'users.name as creator_name', 'puzzle_categories.name as category_name',
            );
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderByDesc('creator_puzzles.plays_count')->orderByDesc('creator_puzzles.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var CreatorPuzzle $model */
        $by = 'بقلم '.$model->getAttribute('creator_name');
        $category = $model->getAttribute('category_name');
        $difficulty = self::DIFFICULTY[$model->published_difficulty] ?? null;

        return new SearchResult(
            type: $this->type(),
            title: (string) $model->published_title,
            url: route('community.puzzles.show', $model->public_id),
            subtitle: ($category ? $category.' · ' : '').$by,
            badge: 'من المجتمع'.($difficulty ? ' · '.$difficulty : ''),
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
`````

### `config/creators.php`

`sha256: ae4d39b98297fb053b6add4248756d282a4157d2b64452c98fc569a312b04173`

`````php
<?php

/*
| E28 - منظومة المبدعين ونشر أحجيات المجتمع.
| كل القيم قابلة للضبط بالبيئة أو هنا؛ لا أسرار. لا مكافآت اقتصادية لأحجيات المجتمع بأي إعداد (انظر docs/creator-ecosystem-e28.md).
*/
return [

    /*
    | قائمة السماح بأنواع الأحجيات التي يستطيع المبدع إنشاءها. كل مفتاح يربط بتعريف المحرك القائم (game_type) ونوع الأحجية الكلاسيكي (type).
    | المبدع لا يختار renderer ولا validation_type ولا score_mode: GameTypeRegistry::defaultsFor() يشتقّها سيرفريًا من game_type.
    | أنواع تحتاج GameSession أو محرر نقاط (spot_difference) مؤجَّلة عمدًا: انظر 'deferred' والمستند.
    */
    'kinds' => [
        'text' => ['label' => 'جواب كتابي', 'type' => 'text', 'game_type' => null, 'media' => false],
        'multiple_choice' => ['label' => 'اختيار من متعدد', 'type' => 'multiple_choice', 'game_type' => null, 'media' => false],
        'image' => ['label' => 'أحجية بصورة', 'type' => 'image', 'game_type' => null, 'media' => true],
        'sequence' => ['label' => 'ترتيب تسلسل', 'type' => 'text', 'game_type' => 'sequence', 'media' => false],
        'memory' => ['label' => 'بطاقات الذاكرة', 'type' => 'text', 'game_type' => 'memory', 'media' => false],
    ],

    'deferred' => [
        'spot_difference' => 'يحتاج GameSession ومحرر نقاط الفروق وصورتين مرتبطتين؛ مؤجَّل إلى مرحلة لاحقة.',
    ],

    'difficulties' => ['easy', 'medium', 'hard'],

    /*
    | حقول سلطة ممنوعة على المبدع مهما كانت: وجودها بالطلب (حتى فارغة) يُرفض بخطأ تحقق صريح بدل تجاهلها بصمت (يكشف محاولات التلاعب ويثبتها الاختبارات).
    | كلها إما اقتصاد/تقدّم/ربط رسمي (مكافآت، يومية، حملات، منافسات) أو مشتقّات المحرك السيرفرية (renderer/validation_type/score_mode/game_type) أو حقول الحالة والملكية.
    */
    'forbidden_fields' => [
        'gem_reward', 'xp_reward', 'reward_currency_id', 'is_daily_puzzle', 'daily_puzzle_date', 'is_active',
        'campaign_id', 'campaign_step_id', 'campaign_gate_id', 'season_id', 'competition_id', 'competitive_event_id', 'challenge_id',
        'renderer', 'validation_type', 'score_mode', 'game_type', 'game_config', 'solution_data', 'answer_hash', 'answer_raw',
        'max_attempts', 'time_limit_seconds', 'origin', 'type', 'image_path', 'status', 'creator_profile_id', 'puzzle_id',
        'hidden_at', 'archived_at', 'is_visible', 'published_at', 'reviewed_by', 'review_note',
    ],

    // حدود المحتوى (حروف). النصوص تُخزَّن نصًا عاديًا وتُعرض بـBlade المهرَّب؛ ووسوم HTML مرفوضة عند الإدخال أصلًا.
    'limits' => [
        'title_min' => 3,
        'title_max' => 100,
        'prompt_min' => 10,
        'prompt_max' => 600,
        'hint_max' => 200,
        'answer_max' => 100,
        'bio_min' => 20,
        'bio_max' => 600,
        'reason_max' => 600,
        'choice_max' => 80,
        'choices_min' => 2,
        'choices_max' => 6,
        'sequence_min' => 3,
        'sequence_max' => 8,
        'sequence_item_max' => 80,
        'memory_min' => 3,
        'memory_max' => 12,
        'memory_face_max' => 24,
        'note_min' => 5,
        'note_max' => 500,
        'report_details_max' => 500,
    ],

    // ثوابت سيرفرية لأحجية المجتمع (لا يتحكم بها المبدع): بلا مكافأة، بلا مؤقّت، عدد محاولات ثابت.
    'puzzle' => [
        'max_attempts' => (int) env('CREATOR_PUZZLE_MAX_ATTEMPTS', 3),
        'gem_reward' => 0,
        'xp_reward' => 0,
    ],

    // سقوف لكل مبدع تمنع الإغراق (حدود أخرى بالمحدِّدات المسمّاة في AppServiceProvider).
    'caps' => [
        'works' => (int) env('CREATOR_MAX_WORKS', 100),
        'open_drafts' => (int) env('CREATOR_MAX_OPEN_DRAFTS', 20),
        'pending_review' => (int) env('CREATOR_MAX_PENDING', 5),
    ],

    'media' => [
        'disk' => 'public',
        'directory' => 'community/puzzles',
        'max_kb' => (int) env('CREATOR_IMAGE_MAX_KB', 4096),
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'max_dimension' => 4096,
        'min_dimension' => 100,
    ],

    'page_size' => 12,
    'moderation_page_size' => 20,

    'analytics' => [
        'cache_ttl' => (int) env('CREATOR_ANALYTICS_TTL', 120),
        'recent_days' => 7,
        'recent_limit' => 10,
    ],

    // E27: أحجيات المجتمع بالاكتشاف. سقف أحجيات المبدع الواحد بالقسم والتوصيات (تنوّع المبدعين).
    'discovery' => [
        'section_limit' => 8,
        'per_creator_cap' => 2,
        'reco_limit' => 4,
        'reco_pool' => 40,
        'trending_min_players' => (int) env('CREATOR_TRENDING_MIN_PLAYERS', 2),
    ],
];
`````

### `database/migrations/2026_10_20_000001_create_creator_ecosystem_tables.php`

`sha256: d94bd69759a951b5e8563286f309002df6a163318e6d70dd0f0e1d639bd2a868`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E28 - منظومة المبدعين وأحجيات المجتمع. خمسة جداول بحدّ أدنى لما يلزم فعلًا:
 *
 *  creator_profiles           ملف مبدع واحد لكل مستخدم (unique user_id) + slug فريد + حالة الطلب/الاعتماد.
 *  creator_puzzles            العمل الإبداعي (الهوية الثابتة + لقطة النشر الحالية + الإخفاء/الأرشفة). is_visible مشتقّة آليًا بالنموذج وهي أساس فهارس البحث/الاكتشاف.
 *  creator_puzzle_revisions   كل نسخة: حمولة التأليف (مشفَّرة) + حالة سير العمل + المراجعة + ربط الأحجية القانونية (puzzle_id فريد) عند الاعتماد. هي سجل التاريخ.
 *  community_puzzle_attempts  سجل لعب المجتمع **منفصل عمدًا عن puzzle_attempts**: لا يغذّي الاقتصاد/XP/المهام/السلسلة/الإنجازات/لوحة الصدارة/التأهيل بالبناء.
 *  community_puzzle_reports   بلاغات الأحجيات المنشورة. open_marker يجعل «بلاغ مفتوح واحد لكل مستخدم/عمل» قيدًا بقاعدة البيانات (NULL عند الإغلاق فلا يتعارض).
 *
 * مؤشرات latest_revision_id / published_revision_id مجرد أعمدة بلا FK (دورة FK بين العمل والنسخ)، والخدمات تملك سلامتها؛ كل علاقات الملكية الأخرى قيود FK فعلية.
 * لا حذف تتابعي للأحجيات القانونية: puzzle_id بالنسخ وpublished_puzzle_id بالعمل restrictOnDelete (التاريخ لا يُمحى).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->text('bio')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('approved_at')->nullable(); // أول اعتماد (تاريخ الانضمام كمبدع) ولا يُكتب فوقه
            $table->timestamps();

            $table->index(['status', 'submitted_at'], 'creator_profiles_status_submitted_index');
        });

        Schema::create('creator_puzzles', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('latest_revision_id')->nullable();
            $table->unsignedBigInteger('published_revision_id')->nullable();

            // لقطة النشر الحالية (null قبل أول نشر). البحث والاكتشاف يقرأونها من هذا الجدول وحده بلا ضمّ.
            $table->foreignId('published_puzzle_id')->nullable()->constrained('puzzles')->restrictOnDelete();
            $table->string('published_title')->nullable();
            $table->foreignId('published_category_id')->nullable()->constrained('puzzle_categories')->nullOnDelete();
            $table->string('published_difficulty', 16)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 24)->nullable();
            $table->text('hidden_note')->nullable();

            $table->boolean('is_visible')->default(false);
            $table->unsignedInteger('plays_count')->default(0); // لاعبون مميَّزون للعمل (أول محاولة للمستخدم على أي نسخة): عرض فقط
            $table->timestamps();

            $table->index(['creator_profile_id', 'id'], 'creator_puzzles_owner_index');
            $table->index(['creator_profile_id', 'is_visible', 'published_at'], 'creator_puzzles_public_creator_index');
            $table->index(['is_visible', 'published_at'], 'creator_puzzles_visible_new_index');
            $table->index(['is_visible', 'published_title'], 'creator_puzzles_visible_title_index');
        });

        Schema::create('creator_puzzle_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');
            $table->string('status', 20)->default('draft');

            $table->string('kind', 24);
            $table->string('title');
            $table->foreignId('puzzle_category_id')->nullable()->constrained('puzzle_categories')->nullOnDelete();
            $table->string('difficulty', 16);
            $table->text('content'); // encrypted:array - الحمولة المهيكلة (prompt/hint/answer/choices/items/faces). لا تصل لأي عرض عام.
            $table->string('image_path')->nullable(); // يضعه المخدّم وحده من رفع صالح؛ لا قيمة من العميل

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();

            $table->foreignId('puzzle_id')->nullable()->unique()->constrained('puzzles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['creator_puzzle_id', 'revision_number'], 'creator_revisions_number_unique');
            $table->index(['status', 'submitted_at'], 'creator_revisions_queue_index');
        });

        Schema::create('community_puzzle_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->boolean('is_correct')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'puzzle_id'], 'community_attempts_user_version_index');
            $table->index(['creator_puzzle_id', 'user_id'], 'community_attempts_work_user_index');
            $table->index('created_at', 'community_attempts_created_index');
        });

        Schema::create('community_puzzle_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_puzzle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->nullable()->constrained('puzzles')->nullOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 16);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedTinyInteger('open_marker')->nullable()->default(1);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('handler_note')->nullable();
            $table->timestamps();

            $table->unique(['creator_puzzle_id', 'reporter_id', 'open_marker'], 'community_reports_one_open_unique');
            $table->index(['status', 'created_at'], 'community_reports_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_puzzle_reports');
        Schema::dropIfExists('community_puzzle_attempts');
        Schema::dropIfExists('creator_puzzle_revisions');
        Schema::dropIfExists('creator_puzzles');
        Schema::dropIfExists('creator_profiles');
    }
};
`````

### `database/migrations/2026_10_20_000002_add_origin_to_puzzles_table.php`

`sha256: ebea99b0a18c3e06131450f28d29ecc3f7696da2b33a8edbe0344d1d14315201`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E28 - مصدر الأحجية (official | community) كحقيقة سيرفرية على الصف نفسه، لا من الرابط ولا من علاقة جانبية.
 * الصفوف الحالية رسمية بالافتراضي (لا تعبئة لاحقة). أحجيات المجتمع المنشورة صفوف مشتقَّة بالمحرك نفسه بـorigin=community وis_active=false دائمًا،
 * فكل مسار رسمي يفلتر is_active (القوائم، البحث، الرائج، التوصيات، اليومية، API) يستبعدها تلقائيًا (رفض افتراضي).
 * بلا فهرس: الفلترة بـorigin مقتصرة على لوحة الإدارة (جدول صغير) وفحوص نقطة واحدة بالمفتاح.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('puzzles', 'origin')) {
            Schema::table('puzzles', function (Blueprint $table) {
                $table->string('origin', 16)->default('official');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('puzzles', 'origin')) {
            Schema::table('puzzles', function (Blueprint $table) {
                $table->dropColumn('origin');
            });
        }
    }
};
`````

### `database/seeders/DemoCreatorSeeder.php`

`sha256: 547dddc02533b204b3ca681d6765802bea32d2f226f0806fdf70c2f6181a29ab`

`````php
<?php

namespace Database\Seeders;

use App\Models\CommunityPuzzleReport;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Creators\CreatorVisibilityService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E28 - بيانات Demo لمنظومة المبدعين وأحجيات المجتمع.   php artisan db:seed --class=DemoCreatorSeeder
 *
 * - **مستقلة عن DemoQaSeeder** (لا تُستدعى منه): أرقام DemoQa المثبّتة بالاختبارات لا تتغير. اختيارية، وترفض الإنتاج.
 * - **بالخدمات الرسمية فقط** (طلب انضمام، مسودات، إرسال، اعتماد، إخفاء، بلاغ، لعب): لا كتابة مباشرة بحالة أو بأحجية قانونية ولا بأي رصيد/XP.
 *   أحجيات المجتمع بلا مكافآت بالبناء، فلا يصل شيء من هنا للمحافظ ولا المهام ولا الصدارة.
 * - **Idempotent**: تُعاد دون تضاعف (مستخدمون بالبريد، أعمال بالعنوان لكل مبدع).
 * - حسابات تجريبية بكلمة المرور المعروفة (.test): للبيئات المحلية/الاختبارية فقط.
 */
class DemoCreatorSeeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public function run(): void
    {
        $this->refuseInProduction('DemoCreatorSeeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $category = PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->first()
            ?? PuzzleCategory::query()->create(['name' => 'متنوعة', 'slug' => 'misc', 'sort_order' => 99, 'is_active' => true]);

        $reviewer = $this->user('demo-reviewer@creators.test', 'مراجع المجتمع');
        $reviewer->syncRoles(['moderator']);

        $applications = app(CreatorApplicationService::class);
        $puzzles = app(CreatorPuzzleService::class);
        $review = app(CreatorReviewService::class);

        // مبدع معتمَد بثلاث أحجيات منشورة (نص، اختيار، ترتيب) + نسخة حيّة مخفية إشرافيًا.
        $nour = $this->user('demo-creator-nour@creators.test', 'نور المبدعة');
        $this->ensureApproved($nour, 'أحب صناعة ألغاز قصيرة تحفّز التفكير وتناسب الجميع.', $reviewer, $applications);

        $published = [];
        $published[] = $this->publish($nour, $reviewer, $puzzles, $review, 'ما الذي يكتب ولا يقرأ؟', ['kind' => 'text', 'difficulty' => 'easy', 'prompt' => 'لغز قصير: ما الشيء الذي يكتب ولا يقرأ؟', 'hint' => 'تجده في المكتب', 'answer' => 'القلم'], $category);
        $published[] = $this->publish($nour, $reviewer, $puzzles, $review, 'أي هذه الكواكب أكبر؟', ['kind' => 'multiple_choice', 'difficulty' => 'medium', 'prompt' => 'اختر الكوكب الأكبر في المجموعة الشمسية.', 'choices' => ['المشتري', 'زحل', 'الأرض', 'المريخ'], 'answer' => 'المشتري'], $category);
        $published[] = $this->publish($nour, $reviewer, $puzzles, $review, 'رتّب فصول السنة', ['kind' => 'sequence', 'difficulty' => 'easy', 'prompt' => 'رتّب فصول السنة بدءًا من الربيع.', 'items' => ['الربيع', 'الصيف', 'الخريف', 'الشتاء']], $category);
        $hidden = $this->publish($nour, $reviewer, $puzzles, $review, 'أحجية أخفاها الإشراف', ['kind' => 'text', 'difficulty' => 'hard', 'prompt' => 'لغز تجريبي أُخفي لأغراض العرض.', 'answer' => 'سر'], $category);

        if ($hidden && ! $hidden->isHidden()) {
            app(CreatorVisibilityService::class)->hide($hidden, $reviewer, 'مخفية لعرض حالة الإخفاء بالواجهة التجريبية.');
        }

        // مبدع ثانٍ: معتمَد بأعمال بحالات مختلفة (قيد المراجعة، تحتاج تعديلات، مرفوضة، مسودة) + منشورة واحدة.
        $sami = $this->user('demo-creator-sami@creators.test', 'سامي المبدع');
        $this->ensureApproved($sami, 'مهتم بألغاز الذاكرة والمنطق، وأشارك ما أجمعه من ألعاب العائلة.', $reviewer, $applications);
        $published[] = $this->publish($sami, $reviewer, $puzzles, $review, 'بطاقات الفواكه', ['kind' => 'memory', 'difficulty' => 'easy', 'prompt' => 'اقلب البطاقات وطابق الأزواج المتشابهة.', 'faces' => ['تفاح', 'موز', 'عنب', 'خوخ']], $category);

        $this->draft($sami, $puzzles, 'مسودة قيد الكتابة', ['kind' => 'text', 'difficulty' => 'easy', 'prompt' => 'لغز لم يكتمل بعد، ما هو؟', 'answer' => 'سؤال'], $category);
        $this->submitted($sami, $puzzles, 'بانتظار المراجعة', ['kind' => 'text', 'difficulty' => 'medium', 'prompt' => 'ما الذي له أسنان ولا يعض؟', 'answer' => 'المشط'], $category);
        $changes = $this->submitted($sami, $puzzles, 'تحتاج تعديلات', ['kind' => 'text', 'difficulty' => 'medium', 'prompt' => 'لغز يحتاج توضيح السؤال أكثر.', 'answer' => 'غموض'], $category);
        $rejected = $this->submitted($sami, $puzzles, 'أحجية مرفوضة', ['kind' => 'text', 'difficulty' => 'hard', 'prompt' => 'لغز لا يناسب المنصة ويتجاوز الإرشادات.', 'answer' => 'ممنوع'], $category);

        if ($changes && $changes->status === CreatorPuzzleRevision::STATUS_PENDING_REVIEW) {
            $review->requestChanges($changes, $reviewer, 'وضّح صياغة السؤال وحدّد المقصود بالإجابة.');
        }

        if ($rejected && $rejected->status === CreatorPuzzleRevision::STATUS_PENDING_REVIEW) {
            $review->reject($rejected, $reviewer, 'لا يتوافق مع إرشادات النشر.');
        }

        // مبدع بطلب انضمام معلَّق.
        $layla = $this->user('demo-applicant-layla@creators.test', 'ليلى المتقدّمة');

        if (! CreatorProfile::query()->where('user_id', $layla->id)->exists()) {
            $applications->apply($layla, 'أرغب في الانضمام لنشر ألغاز لغوية وكلمات متقاطعة مبسّطة للمجتمع.');
        }

        // لاعبون ولعب حقيقي (بالخدمة، في جدول المجتمع المنفصل) + بلاغ مفتوح، فتظهر الأرقام والرائج.
        $players = [$this->user('demo-player-a@creators.test', 'لاعب أ'), $this->user('demo-player-b@creators.test', 'لاعب ب'), $this->user('demo-player-c@creators.test', 'لاعب ج')];
        $this->play($players, array_filter($published));

        $reportable = collect($published)->filter()->first(fn (CreatorPuzzle $w) => $w->fresh()->is_visible);

        if ($reportable !== null && ! CommunityPuzzleReport::query()->where('creator_puzzle_id', $reportable->id)->where('reporter_id', $players[2]->id)->exists()) {
            app(CommunityReportService::class)->report($players[2], $reportable->fresh(), 'broken', 'تجربة بلاغ تجريبي للعرض فقط.');
        }

        $this->command?->info('DemoCreatorSeeder: مبدعون ('.CreatorProfile::query()->count().')، أعمال ('.CreatorPuzzle::query()->count().'). كلمة مرور الحسابات التجريبية: '.self::PASSWORD);
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

    protected function ensureApproved(User $user, string $bio, User $reviewer, CreatorApplicationService $applications): void
    {
        $profile = CreatorProfile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            $profile = $applications->apply($user, $bio);
        }

        if ($profile->status === CreatorProfile::STATUS_PENDING) {
            $applications->approve($profile, $reviewer, 'مرحبًا بك مبدعًا.');
        }
    }

    protected function existingWork(User $user, string $title): ?CreatorPuzzle
    {
        return CreatorPuzzle::query()
            ->whereHas('creator', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('revisions', fn ($q) => $q->where('title', $title))
            ->first();
    }

    protected function draft(User $user, CreatorPuzzleService $puzzles, string $title, array $data, PuzzleCategory $category): ?CreatorPuzzleRevision
    {
        if ($this->existingWork($user, $title) !== null) {
            return null;
        }

        return $puzzles->createDraft($user, $data + ['title' => $title, 'puzzle_category_id' => $category->id]);
    }

    protected function submitted(User $user, CreatorPuzzleService $puzzles, string $title, array $data, PuzzleCategory $category): ?CreatorPuzzleRevision
    {
        $revision = $this->draft($user, $puzzles, $title, $data, $category);

        return $revision === null ? null : $puzzles->submit($user, $revision->work->public_id);
    }

    /** ينشر العمل (مسودة ← إرسال ← اعتماد) أو يعيد الموجود. */
    protected function publish(User $user, User $reviewer, CreatorPuzzleService $puzzles, CreatorReviewService $review, string $title, array $data, PuzzleCategory $category): ?CreatorPuzzle
    {
        if (($existing = $this->existingWork($user, $title)) !== null) {
            return $existing;
        }

        $submitted = $this->submitted($user, $puzzles, $title, $data, $category);
        $review->approve($submitted, $reviewer, 'معتمدة للعرض التجريبي.');

        return $submitted->work()->first();
    }

    /** @param list<User> $players @param iterable<CreatorPuzzle> $works */
    protected function play(array $players, iterable $works): void
    {
        $play = app(CommunityPlayService::class);

        foreach ($works as $work) {
            $work = $work->fresh();

            if (! $work->is_visible || $work->plays_count > 0) {
                continue;
            }

            foreach ($players as $i => $player) {
                try {
                    $play->attempt($player, $work, $i === 0 ? '__غير صحيحة__' : '', []);
                } catch (\Throwable) {
                    // عمل نوعه لا يقبل إجابة نصية خام (ترتيب/ذاكرة) أو انتهت محاولاته: لا يهم للعرض.
                }
            }
        }
    }
}
`````

### `docs/creator-ecosystem-e28.md`

`sha256: fc73dedad76aeb47107a33c13c941a32c1bb3f69218ea2f9b5ff443f22a3c47b`

`````markdown
# E28 — منظومة المبدعين، نشر أحجيات المجتمع والإشراف عليها

المبدع المعتمَد ينشئ أحجيات مجتمع من **استوديو المبدع** (Blade + Alpine + Tailwind، وليس Filament)، يرسلها للمراجعة، فتُنشر بعد اعتماد مراجع. تظهر في صفحة المبدع العامة وفي البحث والاكتشاف (E27) بوسم **«من المجتمع»** المميِّز عن الرسمي.

> **القاعدة الأولى: أحجيات المجتمع بلا أي أثر اقتصادي.** لا جواهر، لا XP، لا مهام، لا سلسلة، لا إنجازات، لا تأهيل حملات، لا منافسات، لا لوحة صدارة رسمية. هذا مفروض **بالبناء** (أكثر من طبقة)، لا بالاتفاق.

## 1. العزل بالبناء (Isolation by construction)

| الطبقة | ما يمنع الأثر |
|---|---|
| **جدول لعب منفصل** | اللعب يُسجَّل في `community_puzzle_attempts` (لا `puzzle_attempts`). فلا شيء من محرّكات المكافآت/المهام/السلسلة/الإنجازات/الصدارة/التأهيل يراه أصلًا. |
| **أحجية قانونية بأصل `community`** | كل نسخة معتمَدة تُنتج صفّ `puzzles` بـ`origin='community'`, `is_active=false`, ومكافآت صفر. حقل `origin` غير قابل للإسناد الجماعي (`forceFill` فقط). |
| **حراسة النموذج** | `Puzzle` يُجبر المكافآت صفرًا عند إنشاء صف مجتمع، ويمنع تعديله وحذفه (`LogicException`)؛ التاريخ لا يُعاد كتابته. |
| **حراسة المحرّكات** | `PuzzleAttemptService::attempt/purchaseHint` و`GameSessionService::start` يرفضان أحجية مجتمع (`RuntimeException`)؛ `CompetitiveEligibility` يعيد «أحجيات المجتمع لا تصلح للمنافسات.»؛ مسارات `/puzzles` وAPI تعيد 404؛ منتقيات لوحة الإدارة تقرأ `Puzzle::official()`. |
| **لا محرّك ثانٍ** | التحقق من الجواب بـ`GameTypeRegistry::validatorFor($puzzle)->check()` نفسه، والعرض بقوالب `games.legacy-input` / `games.sequence` / `games.memory` نفسها. لا `CommunityPuzzleEngine` ولا مدقّق ثانٍ. |

## 2. نموذج البيانات (ترحيلان جديدان فقط)

- `2026_10_20_000001_create_creator_ecosystem_tables`: `creator_profiles`، `creator_puzzles` (العمل)، `creator_puzzle_revisions` (النسخ)، `community_puzzle_attempts`، `community_puzzle_reports`.
- `2026_10_20_000002_add_origin_to_puzzles_table`: عمود `origin` (`official` افتراضيًا | `community`) بفهرس.

**`creator_profiles`**: `user_id` فريد، `slug` فريد (ASCII آمن، بلا بريد ولا رقم داخلي)، `bio`، `status` (`pending|approved|rejected|suspended`)، بيانات المراجعة (`reviewed_by/at`, `review_note`)، `approved_at` (تاريخ الانضمام، لا يُكتب فوقه).

**`creator_puzzles` (العمل)**: `public_id` ULID (المعرّف الوحيد في الروابط)، مؤشرا `latest_revision_id` / `published_revision_id`، **لقطة النشر** (`published_puzzle_id`, `published_title`, `published_category_id`, `published_difficulty`, `published_at`)، `archived_at`، `hidden_at/by/reason/note`، `is_visible` (مشتقّ في حدث الحفظ)، `plays_count` (لاعبون مميَّزون، عرض فقط).

**`creator_puzzle_revisions`**: `revision_number`، `status` (`draft|pending_review|changes_requested|approved|rejected`)، `kind`، `title`، `difficulty`، **`content` مشفَّر** (prompt/hint/answer/choices/items/faces)، `image_path` (يضعه المخدّم وحده)، بيانات المراجعة، `puzzle_id` فريد (الأحجية القانونية لهذه النسخة).

**الفهارس**: `(status, submitted_at)` لطابور المراجعة؛ `(is_visible, published_at)` للجديد؛ `(is_visible, published_title)` لبحث العنوان بالبادئة؛ `(creator_profile_id, is_visible, published_at)` لصفحة المبدع؛ `(user_id, puzzle_id)` و`(creator_puzzle_id, user_id)` و`created_at` للعب؛ `open_marker` يجعل «بلاغ مفتوح واحد لكل مستخدم/عمل» قيدًا بقاعدة البيانات. `puzzle_id` بالنسخ و`published_puzzle_id` بالعمل `restrictOnDelete` (لا حذف تتابعي للتاريخ).

## 3. الأهلية والصلاحيات

**الأهلية للاستوديو** (`CreatorEligibility`): ملف مبدع **معتمَد** + بريد **موثَّق** + حساب **غير مجمَّد**. ليست صلاحية إدارة.

**صلاحيات الإشراف** (RBAC، `config/permissions.php`):

| الصلاحية | الدور الافتراضي | تتيح |
|---|---|---|
| `creators.view` | administrator، moderator | عرض المبدعين والنسخ والبلاغات بلوحة الإدارة |
| `creators.review` | administrator، moderator | اعتماد/طلب تعديل/رفض النسخ، إخفاء/استعادة المنشور، معالجة البلاغات، معاينة المراجع |
| `creators.manage` | administrator | اعتماد/رفض/تعليق/استعادة **المبدعين أنفسهم** |

كل إجراء إداري يُدقَّق بـ`OperationalAuditService` (من فعل، على ماذا، بأي ملاحظة).

## 4. التدفقات

**الانضمام**: لاعب موثَّق ← `POST /creator/apply` (نبذة 20–600 حرف) ← `pending` (طلب مفتوح واحد) ← مراجع بـ`creators.manage` يعتمد/يرفض بسبب إلزامي ← إشعار ← عند الرفض يمكن إعادة التقديم. **التعليق** (`suspended`) يُخفي كل منشورات المبدع فورًا؛ **الاستعادة** تُعيدها (ما لم يخفِها الإشراف يدويًا لسبب آخر).

**النشر**:
```
مسودة ─(إرسال)→ قيد المراجعة ─(اعتماد)→ منشورة
   ↑                 ├─(طلب تعديل)→ تحتاج تعديلات ─(تعديل + إعادة إرسال)→ قيد المراجعة
   └─(سحب)───────────┘└─(رفض)→ مرفوضة (مجمَّدة؛ نسخة جديدة تبدأ من العمل)
```
المسودة تُعدَّل وتُحذف بحرية؛ المرسَلة مقفلة حتى السحب؛ المعتمَدة والمرفوضة **غير قابلة للتعديل** (نموذجًا وخدمةً ومسارًا).

**التعديل بعد النشر (Versioning)**: «مراجعة جديدة» تُنشئ نسخة جديدة (مسودة) من آخر نسخة؛ **المنشورة الحالية تبقى حيّة** ومتاحة للاعبين حتى تُعتمد الجديدة؛ عند الاعتماد تُنتج أحجية قانونية **جديدة** (لا تعديل القديمة) وتنتقل لقطة النشر إليها. المحاولات التاريخية تبقى على `puzzle_id` القديم، ولا تُمسّ.

**الظهور**: `is_visible` = منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد ∧ حساب غير مجمَّد. `CreatorVisibilityService::reconcile` يُستدعى بعد تعليق/استعادة المبدع، واعتماده، وتجميد/فكّ تجميد حسابه. أسباب الإخفاء: `moderation | creator_suspended | account_frozen`.

## 5. أنواع الأحجيات وحقول السلطة

**المدعوم**: جواب كتابي `text`، اختيار من متعدد `multiple_choice`، أحجية بصورة `image`، ترتيب تسلسل `sequence`، بطاقات ذاكرة `memory`. **المؤجَّل**: `spot_difference` (يحتاج GameSession ومحرر نقاط وصورتين).

المبدع **لا يختار** renderer ولا validation_type ولا score_mode ولا game_type: `GameTypeRegistry::defaultsFor()` يشتقّها مخدّميًا. **قائمة الحقول الممنوعة** (`config/creators.php › forbidden_fields`): مجرّد وجودها بالطلب — حتى فارغة — يُرفض بخطأ تحقق صريح (مكافآت، يومية، حملات/مواسم/منافسات/تحديات، renderer/validation_type/score_mode/game_type/game_config/solution_data/answer_hash، حالة وملكية وإخفاء…). النصوص تُرفض عند الإدخال إن احتوت وسوم HTML (`NoMarkup`) وتُعرض دائمًا بـBlade المهرَّب.

## 6. الأمان

- **IDOR**: كل مسار `{work}` يُحلّ بمعرّف ULID عام **واستعلام محصور بالمالك** (غير المالك: 404، بلا أثر). معرّف رقمي تسلسلي لا يحلّ شيئًا.
- **الوسائط**: JPEG/PNG/WEBP فقط بفحص MIME حقيقي + `getimagesize` (لا SVG ولا ملف PHP متنكّر)، حدّ حجم وأبعاد، اسم UUID من المخدّم داخل مجلد المالك (`community/puzzles/{profile_id}/`)، اسم العميل لا يصل للمسار أبدًا. `ownedBy()` يرفض `..` و`\0` وأي مسار خارج مجلد المالك؛ الحذف/النسخ لا يمسّان ملف غيره.
- **حماية الحل**: الجواب والتلميح والاختيارات الصحيحة **لا تصل أي صفحة عامة** ولا JSON بحث/اكتشاف. لوحة الحل في المعاينة تظهر **للمراجع فقط** (`creators.review`)، ولا تظهر لصاحب العمل. محتوى النسخة مشفَّر ولا يُحمَّل لقوائم الطابور.
- **CSRF**: كل مسارات E28 داخل مجموعة `web`.
- **حدود المعدل**: `creator-apply` (2/د، 5/س)، `creator-draft-create` (5/د، 30/س)، `creator-submit` (5/د، 20/س؛ تشمل السحب و«مراجعة جديدة»)، `community-report` (5/د، 20/س)، و`puzzle-attempt` / `puzzle-hint` القائمة للعب والمعاينة.
- **سقوف لكل مبدع** (`CREATOR_MAX_WORKS`, `CREATOR_MAX_OPEN_DRAFTS`, `CREATOR_MAX_PENDING`).
- **المعاينة** (`/creator/puzzles/{work}/preview`، `/community/review/{revision}`): غير عامة وغير مفهرَسة (`noindex`)، بالمدقّق والعارض نفسيهما، **بلا أي تسجيل**.

## 7. مسارات التطبيق

عام: `GET /creators/{slug}`، `GET /community/puzzles`، `GET /community/puzzles/{ulid}`.
لاعب موثَّق غير مجمَّد: `POST /community/puzzles/{ulid}/{attempt|hint|report}`، `GET|POST /creator/apply`.
استوديو (مبدع معتمَد): `/creator` (لوحة)، `/creator/puzzles` (قائمة مرقَّمة بتصفية الحالة)، `create`، `store`، `edit/update/destroy`، `image.destroy`، `preview` + `preview.check`، `submit/withdraw/revise/archive/unarchive`.
مراجع (`creators.review`): `GET /community/review/{revision}`، `POST …/check`.
لوحة الإدارة (Filament، مجموعة «الإشراف»): `CreatorProfileResource`، `CreatorPuzzleRevisionResource` (طابور مرقَّم بحجم `moderation_page_size`)، `CommunityPuzzleReportResource`، ومخطط إحصاءات `CreatorEcosystemStatsWidget`.

## 8. البلاغات والإشراف

أسباب البلاغ (قائمة مغلقة): `inappropriate | copyright | misleading | broken | spam | other`. بلاغ مفتوح واحد لكل مستخدم/عمل (خدميًا وبقيد قاعدة بيانات)، ولا يبلّغ المبدع عن أحجيته. المراجع: **رفض** البلاغ، **تمت المعالجة**، أو **معالجة + إخفاء المنشور** (يحسم كل البلاغات المفتوحة عليه). إخفاء/استعادة المنشور يُشعر المبدع ويُدقَّق.

**أحداث التدقيق**: `creator_approved`، `creator_rejected`، `creator_suspended`، `creator_restored`، `creator_submission_approved`، `creator_submission_changes_requested`، `creator_submission_rejected`، `community_puzzle_hidden`، `community_puzzle_restored`، `community_report_dismissed`، `community_report_actioned`.

**الإشعارات** (فئة «اجتماعي»، تُرسل بعد الـcommit وفشلها لا يُلغي الإجراء): `creator_approved`، `creator_rejected`، `submission_approved`، `submission_changes_requested`، `submission_rejected`، `publication_hidden`.

## 9. البحث والاكتشاف (E27)

- **البحث**: نوع مستقل `community` («أحجيات المجتمع»، شارة «من المجتمع · الصعوبة»، رابط `/community/puzzles/{ulid}`)، يبحث بـ`published_title` **وحده** (لا محتوى ولا جواب ولا تلميح: لا «أوراكل» لتخمين الجواب)، بنفس قاعدة الظهور العامة، وبفهرس `(is_visible, published_title)` في الوضع الكبير. +1 استعلام لكل بحث.
- **الاكتشاف**: أقسام `community-trending` و`community-new` و`community-for-you` (للمسجَّل) تظهر فقط حين تُوجد نتائج؛ سقف **عملين لكل مبدع** بكل قسم (تنوّع المبدعين)؛ «مقترحة لك» تستبعد المعروض والملعوب وأعمال المشاهد نفسه. **الرائج** = لاعبون **مميَّزون** بحسابات موثَّقة غير مجمَّدة خلال نافذة E27، بحد أدنى `CREATOR_TRENDING_MIN_PLAYERS`، والكاش يحمل **معرّفات ودرجات فقط** (إخفاء الإشراف يسري فورًا). لا تأثير على رائج/توصيات الأحجيات الرسمية.

## 10. التحليلات (بلا مال)

لوحة المبدع: منشورة، لاعبون مميَّزون، محاولات، نسبة الإكمال، نشاط آخر 7 أيام — استعلامان مجمَّعان فقط، مخزَّنان (`CREATOR_ANALYTICS_TTL`، 120ث)، أرقام مجمَّعة بلا هوية لاعبين. الإداري: مبدعون/طلبات/مراجعات/منشورات/لعب/بلاغات (كاش 60ث). **لا أرباح، ولا محفظة، ولا رعاية، ولا ترقية رسمية.**

## 11. الأداء (مقيس على SQLite)

لوحة المبدع 19 استعلامًا (ثابتة بين 3 و25 عملًا)، قائمة الاستوديو 12، صفحة المبدع العامة 7، قائمة المجتمع 6، صفحة العمل 5 (ضيف) / 17 (لاعب)، طابور Filament 6 (ثابت بين 5 و25 في الانتظار، مرقَّم بـ20). البحث: +1 استعلام للمصدر الجديد (سقف suggest لضيف 11). كل الاختبارات بنيوية (عدد/شكل)، لا زمنية.

## 12. التشغيل

- **ENV** (كلها اختيارية، بقيم افتراضية، ومعلَّقة بـ`.env.example`): `CREATOR_PUZZLE_MAX_ATTEMPTS`=3، `CREATOR_MAX_WORKS`=100، `CREATOR_MAX_OPEN_DRAFTS`=20، `CREATOR_MAX_PENDING`=5، `CREATOR_IMAGE_MAX_KB`=4096، `CREATOR_ANALYTICS_TTL`=120، `CREATOR_TRENDING_MIN_PLAYERS`=2.
- **أمر**: `php artisan creators:prune-media [--hours=24] [--dry-run]` — يحذف صور المبدعين **اليتيمة** الأقدم من مهلة الأمان فقط (لا مرتبطًا ولا حديثًا)؛ مجدول أسبوعيًا (`withoutOverlapping` + `onOneServer`). صحة النظام لا تعتمد عليه.
- **Demo اختياري** (يرفض الإنتاج، idempotent، بالخدمات الرسمية فقط، لا يمسّ محافظ/XP/محاولات رسمية): `php artisan db:seed --class=DemoCreatorSeeder`. حسابات `demo-*@creators.test` بكلمة المرور `password` للبيئات المحلية فقط.
- **الاختبارات**: `php artisan test tests/Feature/CreatorE28` (أرقام الاختبارات والحالات بالتقرير).

## 13. حدود معروفة وقرارات مؤجَّلة

- صور المسودات على القرص `public` بأسماء UUID غير قابلة للتخمين (لا رابط موقَّع)؛ لا تجريد EXIF (يُنصح بإضافته عند توفر معالج صور مناسب).
- `spot_difference` مؤجَّل. لا مكافآت ولا اشتراكات ولا رعاية ولا ترقية رسمية للأحجيات المجتمعية (قرارات منتج لمرحلة لاحقة، لا تتطلب كسر العزل: أي ترقية لاحقة ستكون **نسخة رسمية جديدة** وليس تحويل صف مجتمع).
- `plays_count` عدّاد ساخن بزيادة صف (مقبول بحجم MVP؛ يتحوّل لتجميع دوري إن لزم).
- لا فحص آلي لمحتوى المبدع (نصوص/صور): الاعتماد البشري هو الضابط، والبلاغات خط الدفاع الثاني.
`````

### `resources/views/community/_card.blade.php`

`sha256: b67dda4f9d869e530ff3e9f89f97da1d151b15ca6ac035880cb0eb4590bb5e06`

`````blade
@php
    /** @var \App\Models\CreatorPuzzle $work */
    $showCreator = $showCreator ?? false;
    $creatorName = $showCreator && $work->relationLoaded('creator') && $work->creator?->relationLoaded('user') ? $work->creator->user?->name : null;
@endphp
{{-- E28: بطاقة أحجية مجتمع. وسم «من المجتمع» ثابت دائمًا (يميّزها عن الرسمي)، وتصريح أنها بلا جوائز. البطاقة كلها رابط واحد. نصوص مهرَّبة ولا قراءة علاقات غير محمَّلة (لا N+1). --}}
<a href="{{ route('community.puzzles.show', $work->public_id) }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="puzzle" class="w-5 h-5" /></span>
        <x-status-badge tone="primary" :small="true" icon="friends">من المجتمع</x-status-badge>
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $work->published_title }}</h3>
    <p class="text-xs text-slate-400 mt-1">
        @if ($work->publishedCategory) {{ $work->publishedCategory->name }} @endif
        @if ($creatorName) · {{ $creatorName }} @endif
    </p>
    <div class="mt-auto pt-3 flex flex-wrap items-center gap-2">
        @if ($work->published_difficulty) <x-difficulty-badge :difficulty="$work->published_difficulty" :small="true" /> @endif
        <span class="text-[11px] font-bold text-slate-400">{{ number_format($work->plays_count) }} لاعب</span>
    </div>
</a>
`````

### `resources/views/community/index.blade.php`

`sha256: c3431c47c60eb3cbe856c0409226842a2666812bf67b61253ca0224b3aeff033`

`````blade
@extends('layouts.app')

@section('title', 'أحجيات المجتمع')
@section('content_width', 'max-w-6xl')

{{-- E28: قائمة أحجيات المجتمع المنشورة (الظاهرة فقط). مفصولة عن الرسمي بمسار ووسم؛ بلا جوائز اقتصادية. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="أحجيات المجتمع" icon="friends" subtitle="أحجيات صنعها لاعبون مبدعون وراجعها فريق الإشراف. بلا جواهر أو نقاط خبرة؛ للمتعة والتحدي فقط." class="anim-fade-up !mb-0">
            <x-slot:actions>
                @if (auth()->user()?->email_verified_at !== null)
                    <a href="{{ route('creator.apply') }}" class="btn-secondary !py-2 !px-4 text-sm">كن مبدعًا</a>
                @endif
                <a href="{{ route('puzzles.index') }}" class="chip !py-2 text-sm">الأحجيات الرسمية</a>
            </x-slot:actions>
        </x-page-header>

        <nav aria-label="تصفية حسب التصنيف" class="flex flex-wrap gap-2 anim-fade-up">
            <a href="{{ route('community.puzzles.index') }}" @if (! $category) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $category ? '' : 'chip-active' }}">الكل</a>
            @foreach ($categories as $cat)
                <a href="{{ route('community.puzzles.index', ['category' => $cat->slug]) }}" @if ($category && $category->id === $cat->id) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $category && $category->id === $cat->id ? 'chip-active' : '' }}">{{ $cat->name }}</a>
            @endforeach
        </nav>

        @if ($works->isEmpty())
            <x-empty-state icon="puzzle" title="لا أحجيات مجتمع هنا بعد" message="كن أول من ينشر أحجية، أو جرّب تصنيفًا آخر." :action="route('puzzles.index')" actionLabel="الأحجيات الرسمية" />
        @else
            <section aria-label="أحجيات المجتمع" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    @include('community._card', ['work' => $work, 'showCreator' => true])
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
`````

### `resources/views/community/show.blade.php`

`sha256: 03a26fd6d9d2945027e9f10e09dd91827ce55ff023c05046d33f863bb2090fdc`

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

### `resources/views/components/creator-status.blade.php`

`sha256: 9425c69a11c510793c7a4f6e3ebe18c6e1cc217ea434892fce7943cdf7c6d351`

`````blade
@props(['status', 'small' => false])

{{--
    E28: شارة حالة نسخة تأليف (مسودة/قيد المراجعة/تحتاج تعديلات/منشورة/مرفوضة) بمكوّن الشارة الموحَّد (E23). النص دائمًا موجود (لا اعتماد على اللون وحده).
--}}
@php
    $map = [
        'draft' => ['neutral', 'مسودة'],
        'pending_review' => ['warning', 'قيد المراجعة'],
        'changes_requested' => ['warning', 'تحتاج تعديلات'],
        'approved' => ['success', 'منشورة'],
        'rejected' => ['danger', 'مرفوضة'],
        'archived' => ['neutral', 'مؤرشفة'],
        'hidden' => ['danger', 'مخفية من الإشراف'],
    ];
    [$tone, $label] = $map[$status] ?? ['neutral', $status];
@endphp
<x-status-badge :tone="$tone" :small="$small">{{ $label }}</x-status-badge>
`````

### `resources/views/creator/apply.blade.php`

`sha256: abd3943177ced42d1a815de7efc80ca5e4b5102d8fa228969d2870cac8f87bf5`

`````blade
@extends('layouts.app')

@section('title', 'انضم كمبدع')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="انضم كمبدع" icon="palette" subtitle="أنشئ أحجيات للمجتمع، وأرسلها للمراجعة، وابنِ صفحتك كمبدع." :back="route('community.puzzles.index')" backLabel="من المجتمع" class="anim-fade-up !mb-0" />

        @if ($profile?->isApproved())
            <div class="glass rounded-3xl p-6 anim-fade-up">
                <x-status-badge tone="success" icon="check-circle">مبدع معتمَد</x-status-badge>
                <p class="text-sm text-slate-300 mt-3">أنت مبدع معتمَد. ابدأ بإنشاء أحجياتك من الاستوديو.</p>
                <a href="{{ route('creator.dashboard') }}" class="btn-gem !py-2.5 !px-6 text-sm mt-4 inline-flex">استوديو المبدع</a>
            </div>
        @elseif ($profile?->isPending())
            <div class="glass rounded-3xl p-6 anim-fade-up">
                <x-status-badge tone="warning" icon="info">طلبك قيد المراجعة</x-status-badge>
                <p class="text-sm text-slate-300 mt-3">أرسلت طلبك في {{ $profile->submitted_at?->translatedFormat('j F Y') }}. سنُعلمك بالنتيجة عبر الإشعارات.</p>
            </div>
        @elseif ($profile?->isSuspended())
            <div class="glass rounded-3xl p-6 anim-fade-up">
                <x-status-badge tone="danger" icon="alert">صلاحيات المبدع معلَّقة</x-status-badge>
                <p class="text-sm text-slate-300 mt-3">تم تعليق صلاحيات المبدع لحسابك، وأُخفيت أحجياتك المنشورة مؤقتًا. تواصل مع الدعم لمزيد من التفاصيل.</p>
            </div>
        @else
            @if ($profile?->isRejected())
                <div class="rounded-2xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 text-sm font-bold anim-fade-up" role="status">
                    لم يُقبل طلبك السابق.
                    @if ($profile->review_note)
                        <span class="block font-normal text-slate-300 mt-1">ملاحظة المراجع: {{ $profile->review_note }}</span>
                    @endif
                    يمكنك تعديل نبذتك وإعادة التقديم.
                </div>
            @endif

            @if ($denial)
                <div class="rounded-2xl border border-gold/30 bg-gold/10 text-gold px-5 py-4 text-sm font-bold" role="status">{{ $denial }}</div>
            @else
                <form method="POST" action="{{ route('creator.apply.store') }}" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
                    @csrf
                    <div>
                        <label for="bio" class="block text-sm font-bold text-white mb-1">عرّف بنفسك ولماذا تريد الانضمام</label>
                        <textarea id="bio" name="bio" rows="5" required minlength="{{ config('creators.limits.bio_min') }}" maxlength="{{ config('creators.limits.bio_max') }}" aria-describedby="bio-help"
                            class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ old('bio') }}</textarea>
                        <p id="bio-help" class="text-xs text-slate-500 mt-1">من {{ config('creators.limits.bio_min') }} إلى {{ config('creators.limits.bio_max') }} حرفًا، نصًا عاديًا. نعرض اسم حسابك ونبذتك علنًا في صفحتك كمبدع إن قُبلت؛ لا نعرض بريدك ولا أي بيانات خاصة.</p>
                        @error('bio') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400 space-y-1">
                        <p>أحجيات المجتمع تمرّ بمراجعة قبل النشر، وتظهر بوسم «من المجتمع» وباسمك.</p>
                        <p>أحجيات المجتمع لا تمنح جواهر ولا نقاط خبرة، ولا يُحتسب لعبها في المهام أو الإنجازات أو الحملات أو المنافسات.</p>
                    </div>
                    <button type="submit" class="btn-gem w-full">إرسال الطلب</button>
                </form>
            @endif
        @endif
    </div>
@endsection
`````

### `resources/views/creator/dashboard.blade.php`

`sha256: ebfd47cbd1c59759b02fbf7f9d1d46b02a06e80ac3f1c3317ee91aaf201bc8a2`

`````blade
@extends('layouts.app')

@section('title', 'استوديو المبدع')

@section('content')
    <div class="space-y-8">
        <x-page-header title="استوديو المبدع" icon="palette" subtitle="أنشئ أحجيات المجتمع وتابع حالتها وأرقام لعبها." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creators.show', $profile->slug) }}" class="btn-secondary !py-2 !px-4 text-sm">صفحتي العامة</a>
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

`sha256: 4ebdb149d4457bb589e5d8a55a5773c8a4c592ee38af7fd04c59d64d35bf012d`

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
            </div>
        </section>

        <section aria-label="أرقام المبدع" class="grid grid-cols-2 gap-3 anim-fade-up d-1">
            <x-stat-card label="أحجيات منشورة" :value="number_format($published)" icon="puzzle" />
            <x-stat-card label="إجمالي اللاعبين" :value="number_format($plays)" icon="friends" />
        </section>

        <section aria-label="أحجيات المبدع" class="anim-fade-up d-2">
            <x-section-header title="أحجياته" />
            @if ($works->isEmpty())
                <x-empty-state icon="puzzle" title="لا أحجيات منشورة حاليًا" message="سيظهر هنا ما ينشره هذا المبدع بعد اعتماده من الإشراف." />
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($works as $work)
                        @include('community._card', ['work' => $work])
                    @endforeach
                </div>
                <div class="flex justify-center mt-6">{{ $works->links() }}</div>
            @endif
        </section>
    </div>
@endsection
`````

### `resources/views/creator/puzzles/_fields.blade.php`

`sha256: 6f9edf2e739b059a3e64f324d18eb6b04a1ecf7694ae123ac98bc548a6e9d8c5`

`````blade
@php
    /**
     * E28: حقول التأليف المشتركة بين الإنشاء والتعديل. $kind نوع الأحجية، $values القيم الحالية (من النسخة أو الافتراضية)، $categories التصنيفات، $locked=true للقراءة فقط.
     * القيم القديمة بعد فشل التحقق (old) تتقدّم على المخزَّنة. القوائم تُعرض سطرًا لكل عنصر. كل النصوص مهرَّبة؛ لا HTML من المبدع يُعرض.
     */
    $locked = $locked ?? false;
    $val = fn (string $key, $default = '') => old($key, $values[$key] ?? $default);
    $lines = function (string $key) use ($values) {
        $v = old($key, $values[$key] ?? []);

        return is_array($v) ? implode("\n", $v) : (string) $v;
    };
    $inputCls = 'w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-60';
    $l = config('creators.limits');
@endphp

<fieldset @disabled($locked) class="space-y-5">
    <div>
        <label for="title" class="block text-sm font-bold text-white mb-1">عنوان الأحجية</label>
        <input id="title" name="title" type="text" value="{{ $val('title') }}" required minlength="{{ $l['title_min'] }}" maxlength="{{ $l['title_max'] }}" autocomplete="off" class="{{ $inputCls }}">
        @error('title') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="puzzle_category_id" class="block text-sm font-bold text-white mb-1">التصنيف</label>
            <select id="puzzle_category_id" name="puzzle_category_id" required class="{{ $inputCls }}">
                <option value="">اختر التصنيف</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) $val('puzzle_category_id', 0) === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            @error('puzzle_category_id') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="difficulty" class="block text-sm font-bold text-white mb-1">الصعوبة</label>
            <select id="difficulty" name="difficulty" required class="{{ $inputCls }}">
                @foreach (['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'] as $key => $label)
                    <option value="{{ $key }}" @selected($val('difficulty', 'easy') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('difficulty') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="prompt" class="block text-sm font-bold text-white mb-1">نص الأحجية / السؤال</label>
        <textarea id="prompt" name="prompt" rows="4" required minlength="{{ $l['prompt_min'] }}" maxlength="{{ $l['prompt_max'] }}" class="{{ $inputCls }}">{{ $val('prompt') }}</textarea>
        <p class="text-xs text-slate-500 mt-1">نص عادي فقط؛ لا وسوم ولا أكواد.</p>
        @error('prompt') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
    </div>

    @if (in_array($kind, ['text', 'image', 'multiple_choice'], true))
        @if ($kind === 'multiple_choice')
            <div>
                <label for="choices" class="block text-sm font-bold text-white mb-1">الاختيارات (سطر لكل اختيار)</label>
                <textarea id="choices" name="choices" rows="5" class="{{ $inputCls }}" aria-describedby="choices-help">{{ $lines('choices') }}</textarea>
                <p id="choices-help" class="text-xs text-slate-500 mt-1">من {{ $l['choices_min'] }} إلى {{ $l['choices_max'] }} اختيارات مختلفة.</p>
                @error('choices') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
                @error('choices.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label for="answer" class="block text-sm font-bold text-white mb-1">الجواب الصحيح</label>
            <input id="answer" name="answer" type="text" value="{{ $val('answer') }}" required maxlength="{{ $l['answer_max'] }}" autocomplete="off" class="{{ $inputCls }}">
            <p class="text-xs text-slate-500 mt-1">{{ $kind === 'multiple_choice' ? 'اكتب الجواب مطابقًا تمامًا لأحد الاختيارات.' : 'يُقارَن بعد توحيد المسافات والتشكيل وحالة الأحرف.' }} لا يظهر الجواب لأي لاعب.</p>
            @error('answer') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($kind === 'sequence')
        <div>
            <label for="items" class="block text-sm font-bold text-white mb-1">العناصر بالترتيب الصحيح (سطر لكل عنصر)</label>
            <textarea id="items" name="items" rows="6" class="{{ $inputCls }}" aria-describedby="items-help">{{ $lines('items') }}</textarea>
            <p id="items-help" class="text-xs text-slate-500 mt-1">من {{ $l['sequence_min'] }} إلى {{ $l['sequence_max'] }} عناصر مختلفة. نخلطها للاعب ويرتّبها.</p>
            @error('items') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            @error('items.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($kind === 'memory')
        <div>
            <label for="faces" class="block text-sm font-bold text-white mb-1">وجوه البطاقات (سطر لكل وجه)</label>
            <textarea id="faces" name="faces" rows="6" class="{{ $inputCls }}" aria-describedby="faces-help">{{ $lines('faces') }}</textarea>
            <p id="faces-help" class="text-xs text-slate-500 mt-1">من {{ $l['memory_min'] }} إلى {{ $l['memory_max'] }} وجوه مختلفة (كلمة أو رمز قصير)؛ يُنشأ لكل وجه زوج بطاقات.</p>
            @error('faces') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
            @error('faces.*') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    <div>
        <label for="hint" class="block text-sm font-bold text-white mb-1">تلميح (اختياري)</label>
        <input id="hint" name="hint" type="text" value="{{ $val('hint') }}" maxlength="{{ $l['hint_max'] }}" autocomplete="off" class="{{ $inputCls }}">
        <p class="text-xs text-slate-500 mt-1">يظهر للاعب عند طلبه، ومجانًا.</p>
        @error('hint') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
    </div>

    @if ($kindConfig['media'] ?? false)
        <div>
            <label for="image" class="block text-sm font-bold text-white mb-1">صورة الأحجية</label>
            @if (! empty($imageUrl))
                <img src="{{ $imageUrl }}" alt="الصورة الحالية" class="max-h-48 rounded-xl border border-white/10 mb-2">
            @endif
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-300 file:me-3 file:rounded-lg file:border-0 file:bg-amethyst/20 file:px-4 file:py-2 file:text-sm file:font-bold file:text-amethyst" aria-describedby="image-help">
            <p id="image-help" class="text-xs text-slate-500 mt-1">JPEG أو PNG أو WEBP، حتى {{ round(config('creators.media.max_kb') / 1024) }} ميغابايت، بأبعاد من {{ config('creators.media.min_dimension') }} إلى {{ config('creators.media.max_dimension') }} بكسل. لا SVG. الصورة مطلوبة قبل الإرسال.</p>
            @error('image') <p class="text-xs text-rose mt-1" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif
</fieldset>
`````

### `resources/views/creator/puzzles/_row.blade.php`

`sha256: 0b46481114195fab1899670ee0fb2d4fd52deb63de100afd8b144562de37aa42`

`````blade
@php
    /** @var \App\Models\CreatorPuzzle $work */
    $rev = $work->latestRevision;
    $kinds = config('creators.kinds');
@endphp
{{-- E28: صف عمل المبدع. حالة النسخة الأخيرة + حالة النشر (منشور/مخفي/مؤرشف) معًا: عمل منشور بنسخة تعديل مسودة يعرض الاثنتين. نصوص مهرَّبة. --}}
<div class="glass rounded-2xl p-4 mb-3 flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <a href="{{ route('creator.puzzles.edit', $work->public_id) }}" class="font-display font-black text-white hover:text-amethyst break-words focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $rev?->title ?? $work->published_title }}</a>
        <p class="text-xs text-slate-400 mt-1">
            {{ $kinds[$rev?->kind]['label'] ?? '' }}
            @if ($rev?->category) · {{ $rev->category->name }} @endif
            @if ($rev) · نسخة {{ $rev->revision_number }} @endif
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if ($rev) <x-creator-status :status="$rev->status" :small="true" /> @endif
        @if ($work->isPublished() && ! $work->isArchived() && ! $work->isHidden()) <x-status-badge tone="success" :small="true" icon="globe">ظاهرة للعامة</x-status-badge> @endif
        @if ($work->isArchived()) <x-creator-status status="archived" :small="true" /> @endif
        @if ($work->isHidden()) <x-creator-status status="hidden" :small="true" /> @endif
        <a href="{{ route('creator.puzzles.edit', $work->public_id) }}" class="btn-secondary !py-1.5 !px-3 text-xs">فتح</a>
    </div>
</div>
`````

### `resources/views/creator/puzzles/create.blade.php`

`sha256: d90ed5c84d4bd0a5ffea287456ca99f5893dc78f96a813e857905023fd62d227`

`````blade
@extends('layouts.app')

@section('title', 'أحجية جديدة')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="أحجية جديدة" icon="plus" :subtitle="$kind ? 'الخطوة 2 من 2: المحتوى والحل. تُحفظ كمسودة ثم تعاينها وترسلها للمراجعة.' : 'الخطوة 1 من 2: اختر نوع الأحجية.'" :back="route('creator.puzzles.index')" backLabel="أحجياتي" class="anim-fade-up !mb-0" />

        @if (! $kind)
            <div class="grid gap-3 sm:grid-cols-2 anim-fade-up">
                @foreach ($kinds as $key => $cfg)
                    <a href="{{ route('creator.puzzles.create', ['kind' => $key]) }}" class="glass rounded-2xl p-5 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                        <span class="block font-display font-black text-white">{{ $cfg['label'] }}</span>
                        <span class="block text-xs text-slate-400 mt-1">
                            @switch($key)
                                @case('text') سؤال بجواب يكتبه اللاعب. @break
                                @case('multiple_choice') سؤال باختيارات يختار منها اللاعب. @break
                                @case('image') سؤال مرفق بصورة وجواب كتابي. @break
                                @case('sequence') عناصر يرتّبها اللاعب بالترتيب الصحيح. @break
                                @case('memory') لعبة مطابقة أزواج البطاقات. @break
                            @endswitch
                        </span>
                    </a>
                @endforeach
            </div>
            <p class="text-xs text-slate-500">أنواع أخرى (مثل لعبة الفروق) غير متاحة للمبدعين حاليًا.</p>
        @else
            <form method="POST" action="{{ route('creator.puzzles.store') }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <p class="text-sm font-bold text-amethyst">{{ $kinds[$kind]['label'] }}</p>
                @include('creator.puzzles._fields', ['kind' => $kind, 'values' => [], 'categories' => $categories, 'kindConfig' => $kinds[$kind]])
                @error('kind') <p class="text-xs text-rose" role="alert">{{ $message }}</p> @enderror
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400">أحجيات المجتمع بلا جوائز اقتصادية، وتحتاج موافقة المراجع قبل ظهورها.</div>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ كمسودة</button>
                    <a href="{{ route('creator.puzzles.create') }}" class="btn-secondary">تغيير النوع</a>
                </div>
            </form>
        @endif
    </div>
@endsection
`````

### `resources/views/creator/puzzles/edit.blade.php`

`sha256: c1b7ca1b061ee807d316b8f5ac4f1e6813d4b036db61ffeb280a7011168128ac`

`````blade
@extends('layouts.app')

@section('title', 'تعديل الأحجية')

@section('content')
    @php
        $editable = $revision->isEditable();
        $kinds = config('creators.kinds');
        $values = ['title' => $revision->title, 'puzzle_category_id' => $revision->puzzle_category_id, 'difficulty' => $revision->difficulty] + $content;
        $latestFinal = in_array($revision->status, ['approved', 'rejected'], true);
    @endphp
    <div class="max-w-3xl mx-auto space-y-6">
        <x-page-header :title="$revision->title" icon="pencil" :subtitle="($kinds[$revision->kind]['label'] ?? '').' · نسخة '.$revision->revision_number" :back="route('creator.puzzles.index')" backLabel="أحجياتي" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <x-creator-status :status="$revision->status" />
                @if ($work->isArchived()) <x-creator-status status="archived" /> @endif
                @if ($work->isHidden()) <x-creator-status status="hidden" /> @endif
            </x-slot:actions>
        </x-page-header>

        @if ($work->isHidden() && $work->hidden_reason === \App\Models\CreatorPuzzle::HIDDEN_MODERATION)
            <div class="rounded-2xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 text-sm font-bold" role="status">
                أخفى فريق الإشراف النسخة المنشورة من هذه الأحجية.
                @if ($work->hidden_note) <span class="block font-normal text-slate-300 mt-1">السبب: {{ $work->hidden_note }}</span> @endif
            </div>
        @elseif ($work->isHidden())
            <div class="rounded-2xl border border-gold/30 bg-gold/10 text-gold px-5 py-4 text-sm font-bold" role="status">منشورك مخفي مؤقتًا بسبب حالة حسابك كمبدع، ويعود تلقائيًا عند زوال السبب.</div>
        @endif

        @if (in_array($revision->status, ['changes_requested', 'rejected'], true) && $revision->review_note)
            <div class="rounded-2xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm" role="status">
                <p class="font-black text-gold">{{ $revision->status === 'rejected' ? 'سبب الرفض' : 'التعديلات المطلوبة' }}</p>
                <p class="text-slate-200 mt-1">{{ $revision->review_note }}</p>
            </div>
        @endif

        @if ($revision->status === 'pending_review')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">أُرسلت للمراجعة في {{ $revision->submitted_at?->translatedFormat('j F Y H:i') }}. لا يمكن تعديلها وهي قيد المراجعة؛ اسحبها لتعديلها.</div>
        @elseif ($work->isPublished() && ! $editable && $revision->status === 'approved')
            <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-300" role="status">هذه النسخة منشورة ولا تُعدَّل مباشرة. لتغييرها أنشئ نسخة جديدة؛ تبقى المنشورة حيّة حتى تُعتمد الجديدة.</div>
        @endif

        <form method="POST" action="{{ route('creator.puzzles.update', $work->public_id) }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
            @csrf @method('PUT')
            @include('creator.puzzles._fields', ['kind' => $revision->kind, 'values' => $values, 'categories' => $categories, 'kindConfig' => $kindConfig, 'locked' => ! $editable, 'imageUrl' => $revision->imageUrl()])
            @if ($editable)
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ التعديلات</button>
                </div>
            @endif
        </form>

        @if ($editable && $revision->image_path)
            <form method="POST" action="{{ route('creator.puzzles.image.destroy', $work->public_id) }}" onsubmit="return confirm('حذف الصورة؟')">
                @csrf @method('DELETE')
                <button type="submit" class="btn-secondary text-xs"><x-ui-icon name="trash" class="w-4 h-4" />حذف الصورة الحالية</button>
            </form>
        @endif

        <section aria-label="إجراءات" class="glass rounded-3xl p-6 space-y-4">
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('creator.puzzles.preview', $work->public_id) }}" class="btn-secondary">معاينة كما سيراها اللاعب</a>

                @if ($editable)
                    <form method="POST" action="{{ route('creator.puzzles.submit', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm">إرسال للمراجعة</button>
                    </form>
                @endif

                @if ($revision->status === 'pending_review')
                    <form method="POST" action="{{ route('creator.puzzles.withdraw', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">سحب من المراجعة</button>
                    </form>
                @endif

                @if ($latestFinal)
                    <form method="POST" action="{{ route('creator.puzzles.revise', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">{{ $revision->status === 'approved' ? 'إنشاء نسخة جديدة للتعديل' : 'إنشاء نسخة جديدة' }}</button>
                    </form>
                @endif

                @if ($work->isPublished() && ! $work->isArchived())
                    <form method="POST" action="{{ route('creator.puzzles.archive', $work->public_id) }}" onsubmit="return confirm('أرشفة الأحجية؟ ستُخفى من العامة ويمكنك إلغاء الأرشفة لاحقًا.')">@csrf
                        <button type="submit" class="btn-secondary">أرشفة</button>
                    </form>
                @elseif ($work->isArchived())
                    <form method="POST" action="{{ route('creator.puzzles.unarchive', $work->public_id) }}">@csrf
                        <button type="submit" class="btn-secondary">إلغاء الأرشفة</button>
                    </form>
                @endif

                @if ($revision->status !== 'pending_review' && (! $work->isPublished() || $editable))
                    <form method="POST" action="{{ route('creator.puzzles.destroy', $work->public_id) }}" onsubmit="return confirm('{{ $work->isPublished() ? 'حذف مسودة التعديل؟ تبقى النسخة المنشورة كما هي.' : 'حذف هذا العمل نهائيًا؟' }}')">@csrf @method('DELETE')
                        <button type="submit" class="btn-danger"><x-ui-icon name="trash" class="w-4 h-4" />{{ $work->isPublished() ? 'حذف مسودة التعديل' : 'حذف' }}</button>
                    </form>
                @endif
            </div>

            @if ($work->isPublished() && ! $work->isHidden() && ! $work->isArchived())
                <p class="text-xs text-slate-400">المنشورة حاليًا: <a class="text-amethyst font-bold hover:underline" href="{{ route('community.puzzles.show', $work->public_id) }}">{{ $work->published_title }}</a> ({{ number_format($work->plays_count) }} لاعب)</p>
            @endif
        </section>
    </div>
@endsection
`````

### `resources/views/creator/puzzles/index.blade.php`

`sha256: cb8f5edbcae2cb36129dc4d42cd0b8bfd830fdaa5b6464017960fd2e3fe42dc2`

`````blade
@extends('layouts.app')

@section('title', 'أحجياتي')

@section('content')
    <div class="space-y-6">
        <x-page-header title="أحجياتي" icon="puzzle" subtitle="كل أعمالك وحالتها." :back="route('creator.dashboard')" backLabel="الاستوديو" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creator.puzzles.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />أحجية جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 anim-fade-up">
            @foreach (['all' => 'الكل', 'draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                <a href="{{ route('creator.puzzles.index', $key === 'all' ? [] : ['status' => $key]) }}" @if ($bucket === $key) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $bucket === $key ? 'chip-active' : '' }}">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <section class="anim-fade-up">
            @forelse ($works as $work)
                @include('creator.puzzles._row', ['work' => $work])
            @empty
                <x-empty-state icon="palette" title="لا أحجيات هنا" message="غيّر التصفية أو أنشئ أحجية جديدة." :action="route('creator.puzzles.create')" actionLabel="أحجية جديدة" />
            @endforelse

            <div class="flex justify-center mt-6">{{ $works->links() }}</div>
        </section>
    </div>
@endsection
`````

### `resources/views/creator/puzzles/preview.blade.php`

`sha256: 2b29f61d1a7753fc4f7be31390a82a621113346109e7ac8d50b6591a6637527a`

`````blade
@extends('layouts.app')

@section('title', 'معاينة: '.$puzzle->title)
@section('content_width', 'max-w-3xl')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

{{--
    E28: معاينة أحجية مجتمع (للمبدع صاحبها أو للمراجع). المحرك والعارض والمدقّق نفسها كالأحجية الحقيقية، لكن بلا أي تسجيل: لا محاولات ولا جواهر ولا XP ولا مهام ولا تقدّم.
    الرابط غير عام وغير مفهرَس. لوحة الحل تظهر للمراجع فقط ($solution != null) ولا تصل لصفحة المبدع ولا للعامة. كل النصوص مهرَّبة.
--}}
@section('content')
    <div class="space-y-5">
        @if ($backRoute)
            <a href="{{ $backRoute }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
                <x-ui-icon name="chevron-right" class="w-4 h-4" />العودة للتحرير
            </a>
        @endif

        <div class="flex items-start gap-3 rounded-2xl border border-amethyst/30 bg-amethyst/10 px-4 py-3 text-sm anim-fade-up" role="note">
            <x-ui-icon name="info" class="w-5 h-5 shrink-0 text-amethyst mt-0.5" />
            <p class="text-slate-200">
                <span class="font-black text-white">وضع المعاينة.</span>
                لا تُسجَّل محاولاتك ولا تُمنح جواهر أو نقاط خبرة، ولا يتأثر أي تقدّم أو مهمة. الفحص يتم بالمدقّق الرسمي نفسه.
            </p>
        </div>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                @if ($revision->category)
                    <span class="chip !py-1 !px-3">{{ $revision->category->name }}</span>
                @endif
                <x-difficulty-badge :difficulty="$puzzle->difficulty" />
                <x-creator-status :status="$revision->status" :small="true" />
                <span class="ms-auto text-xs font-bold text-slate-400">نسخة {{ $revision->revision_number }}</span>
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $puzzle->title }}</h1>
            @if ($work->creator?->user)
                <p class="text-xs text-slate-400 mb-4">بقلم {{ $work->creator->user->name }}</p>
            @endif

            <p class="font-display font-black text-xl md:text-2xl text-white mb-4 leading-snug break-words whitespace-pre-line">{{ $puzzle->prompt }}</p>

            @if ($revision->imageUrl())
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ $revision->imageUrl() }}" alt="{{ $puzzle->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif

            <form method="POST" action="{{ $checkRoute }}" class="mt-6">
                @csrf
                @include($renderer)

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="text-sm font-bold text-slate-400">معاينة بلا حدّ للمحاولات</span>
                    <button type="submit" class="btn-gem !py-3 !px-6">فحص الإجابة</button>
                </div>
            </form>

            @if ($puzzle->hint)
                <details class="mt-6 pt-6 border-t border-white/5">
                    <summary class="cursor-pointer text-sm font-bold text-slate-300 hover:text-gold transition motion-reduce:transition-none rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إظهار التلميح</summary>
                    <p class="mt-3 text-sm text-slate-200 break-words">{{ $puzzle->hint }}</p>
                </details>
            @endif
        </div>

        @if (is_array($solution))
            <section class="rounded-2xl border border-gold/30 bg-gold/10 p-5 anim-fade-up" aria-labelledby="reviewer-solution-title">
                <h2 id="reviewer-solution-title" class="font-display font-black text-gold flex items-center gap-2"><x-ui-icon name="key" class="w-5 h-5" />لوحة الحل (للمراجع فقط)</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    @foreach ($solution as $label => $value)
                        <div>
                            <dt class="font-black text-white">{{ $label }}</dt>
                            <dd class="text-slate-200 mt-1">
                                @if (is_array($value))
                                    <ol class="list-decimal ps-5 space-y-0.5">
                                        @foreach ($value as $entry)
                                            <li class="break-words">{{ is_array($entry) ? implode(' / ', array_map('strval', $entry)) : $entry }}</li>
                                        @endforeach
                                    </ol>
                                @else
                                    <span class="break-words">{{ $value }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif
    </div>
@endsection
`````

### `routes/creator.php`

`sha256: 1b332658e6f2e61b0ae07f7511024e5fd65ba050ca39ce55a899415e8fcdf30f`

`````php
<?php

use App\Http\Controllers\Creators\CommunityPuzzleController;
use App\Http\Controllers\Creators\CreatorApplicationController;
use App\Http\Controllers\Creators\CreatorDashboardController;
use App\Http\Controllers\Creators\CreatorPuzzleController;
use App\Http\Controllers\Creators\CreatorReviewPreviewController;
use App\Http\Controllers\Creators\PublicCreatorController;
use App\Http\Middleware\EnsureCreatorStudioAccess;
use Illuminate\Support\Facades\Route;

/*
| E28 - منظومة المبدعين وأحجيات المجتمع (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  عام (قراءة فقط، للضيوف): صفحة المبدع، قائمة أحجيات المجتمع، صفحة الأحجية (404 لغير الظاهر).
|  لاعب موثَّق غير مجمَّد: لعب/تلميح/بلاغ أحجية مجتمع (مسار منفصل عن /puzzles الرسمي)، وطلب الانضمام كمبدع.
|  استوديو المبدع (ملف معتمَد + بريد موثَّق + حساب غير مجمَّد): كل مسارات {work} بمعرّف ULID عام يُحلّ باستعلام محصور بالمالك.
|  معاينة المراجع (creators.review): نسخة مرسلة، غير عامة.
| الصفحات الحرفية تسبق الديناميكية (create قبل {work}).
*/

Route::get('/creators/{creator:slug}', [PublicCreatorController::class, 'show'])->name('creators.show');
Route::get('/community/puzzles', [CommunityPuzzleController::class, 'index'])->name('community.puzzles.index');
Route::get('/community/puzzles/{work}', [CommunityPuzzleController::class, 'show'])->whereUlid('work')->name('community.puzzles.show');

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::post('/community/puzzles/{work}/attempt', [CommunityPuzzleController::class, 'attempt'])->whereUlid('work')->middleware('throttle:puzzle-attempt')->name('community.puzzles.attempt');
    Route::post('/community/puzzles/{work}/hint', [CommunityPuzzleController::class, 'hint'])->whereUlid('work')->middleware('throttle:puzzle-hint')->name('community.puzzles.hint');
    Route::post('/community/puzzles/{work}/report', [CommunityPuzzleController::class, 'report'])->whereUlid('work')->middleware('throttle:community-report')->name('community.puzzles.report');

    Route::get('/creator/apply', [CreatorApplicationController::class, 'show'])->name('creator.apply');
    Route::post('/creator/apply', [CreatorApplicationController::class, 'store'])->middleware('throttle:creator-apply')->name('creator.apply.store');

    Route::middleware(EnsureCreatorStudioAccess::class)->prefix('creator')->name('creator.')->group(function () {
        Route::get('/', [CreatorDashboardController::class, 'index'])->name('dashboard');

        Route::get('/puzzles', [CreatorPuzzleController::class, 'index'])->name('puzzles.index');
        Route::get('/puzzles/create', [CreatorPuzzleController::class, 'create'])->name('puzzles.create');
        Route::post('/puzzles', [CreatorPuzzleController::class, 'store'])->middleware('throttle:creator-draft-create')->name('puzzles.store');

        Route::prefix('/puzzles/{work}')->whereUlid('work')->name('puzzles.')->group(function () {
            Route::get('/edit', [CreatorPuzzleController::class, 'edit'])->name('edit');
            Route::put('/', [CreatorPuzzleController::class, 'update'])->name('update');
            Route::delete('/', [CreatorPuzzleController::class, 'destroy'])->name('destroy');
            Route::delete('/image', [CreatorPuzzleController::class, 'destroyImage'])->name('image.destroy');
            Route::get('/preview', [CreatorPuzzleController::class, 'preview'])->name('preview');
            Route::post('/preview', [CreatorPuzzleController::class, 'previewCheck'])->middleware('throttle:puzzle-attempt')->name('preview.check');
            Route::post('/submit', [CreatorPuzzleController::class, 'submit'])->middleware('throttle:creator-submit')->name('submit');
            Route::post('/withdraw', [CreatorPuzzleController::class, 'withdraw'])->middleware('throttle:creator-submit')->name('withdraw');
            Route::post('/revise', [CreatorPuzzleController::class, 'revise'])->middleware('throttle:creator-submit')->name('revise');
            Route::post('/archive', [CreatorPuzzleController::class, 'archive'])->name('archive');
            Route::post('/unarchive', [CreatorPuzzleController::class, 'unarchive'])->name('unarchive');
        });
    });
});

Route::middleware(['auth', 'account.active', 'can:creators.review'])->group(function () {
    Route::get('/community/review/{revision}', [CreatorReviewPreviewController::class, 'show'])->whereNumber('revision')->name('community.review.preview');
    Route::post('/community/review/{revision}/check', [CreatorReviewPreviewController::class, 'check'])->whereNumber('revision')->middleware('throttle:puzzle-attempt')->name('community.review.check');
});
`````

### `tests/Feature/CreatorE28/E28EligibilityTest.php`

`sha256: 9ab61d2beb7f7a2249afe2b70c7cc92a5a347474336bf4336ea7982091997e69`

`````php
<?php

use App\Models\CreatorProfile;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Policies\CreatorProfilePolicy;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

const E28_BIO = 'مهتم بصناعة الألغاز الذهنية وأرغب بمشاركتها مع مجتمع المنصة.';

test('Y1-1 (1): a normal verified user can apply to become a creator', function () {
    $user = e16User();

    $this->actingAs($user)->get(route('creator.apply'))->assertOk();
    $this->actingAs($user)->post(route('creator.apply.store'), ['bio' => E28_BIO])->assertRedirect(route('creator.apply'))->assertSessionHas('success');

    $profile = CreatorProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->status)->toBe('pending')->and($profile->bio)->toBe(E28_BIO)->and($profile->slug)->not->toBe('')
        ->and($profile->submitted_at)->not->toBeNull()->and($profile->approved_at)->toBeNull();
});

test('Y1-2 (2): a guest cannot apply', function () {
    $this->get(route('creator.apply'))->assertRedirect(route('login'));
    $this->post(route('creator.apply.store'), ['bio' => E28_BIO])->assertRedirect(route('login'));

    expect(CreatorProfile::query()->count())->toBe(0);
});

test('Y1-3 (3): an unverified user cannot apply (route and service)', function () {
    $user = e16User(['email_verified_at' => null]);

    $response = $this->actingAs($user)->post(route('creator.apply.store'), ['bio' => E28_BIO]);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toBe(route('creator.apply'));

    expect(fn () => app(CreatorApplicationService::class)->apply($user, E28_BIO))->toThrow(CreatorException::class)
        ->and(CreatorProfile::query()->count())->toBe(0);
});

test('Y1-4 (4): a frozen user cannot apply and a frozen creator cannot use the studio', function () {
    $frozen = e26_freeze(e16User());
    $this->actingAs($frozen)->post(route('creator.apply.store'), ['bio' => E28_BIO]);
    expect(CreatorProfile::query()->where('user_id', $frozen->id)->exists())->toBeFalse()
        ->and(fn () => app(CreatorApplicationService::class)->apply($frozen, E28_BIO))->toThrow(CreatorException::class);

    $creator = e28_creator();
    $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk();
    e26_freeze($creator);

    $this->actingAs($creator->fresh())->get(route('creator.dashboard'))->assertStatus(302);
    $this->actingAs($creator->fresh())->getJson(route('creator.puzzles.index'))->assertStatus(403);
    expect(fn () => app(CreatorPuzzleService::class)->createDraft($creator->fresh(), e28_data()))->toThrow(CreatorException::class);
});

test('Y1-5 (5): a duplicate pending application is prevented', function () {
    $user = e16User();
    $svc = app(CreatorApplicationService::class);
    $svc->apply($user, E28_BIO);

    expect(fn () => $svc->apply($user->fresh(), E28_BIO))->toThrow(CreatorException::class);
    $this->actingAs($user)->post(route('creator.apply.store'), ['bio' => E28_BIO])->assertSessionHas('error');

    expect(CreatorProfile::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('Y1-6 (6): an authorized reviewer approves the application - audited, notified, studio opens', function () {
    $user = e16User();
    $profile = app(CreatorApplicationService::class)->apply($user, E28_BIO);
    $reviewer = e28_reviewer('administrator');

    app(CreatorApplicationService::class)->approve($profile, $reviewer, 'مرحبًا بك');

    $profile->refresh();
    expect($profile->status)->toBe('approved')->and($profile->approved_at)->not->toBeNull()->and($profile->reviewed_by)->toBe($reviewer->id);
    expect(OperationalAuditLog::query()->where('action', 'creator_approved')->where('subject_id', $profile->id)->where('actor_user_id', $reviewer->id)->exists())->toBeTrue();
    expect($user->notifications()->count())->toBe(1);

    $this->actingAs($user->fresh())->get(route('creator.dashboard'))->assertOk();
});

test('Y1-7 (7): unauthorized actions are denied - a plain player, a moderator without manage, and a viewer-only role', function () {
    $profile = app(CreatorApplicationService::class)->apply(e16User(), E28_BIO);
    $svc = app(CreatorApplicationService::class);

    expect(fn () => $svc->approve($profile, e16User()))->toThrow(HttpException::class)
        ->and(fn () => $svc->reject($profile, e16User(), 'سبب واضح للرفض'))->toThrow(HttpException::class);

    // المشرف يراجع (creators.review) لكنه لا يعلّق (creators.manage).
    $moderator = e28_reviewer('moderator');
    $svc->approve($profile, $moderator);
    expect(fn () => $svc->suspend($profile->fresh(), $moderator, 'مخالفة الإرشادات'))->toThrow(HttpException::class);

    // سياسات لوحة الإدارة: عرض بـcreators.view فقط، ولا كتابة خام لأحد.
    $policy = new CreatorProfilePolicy;
    expect($policy->viewAny(e16User()))->toBeFalse()->and($policy->viewAny($moderator))->toBeTrue()
        ->and($policy->update($moderator, $profile))->toBeFalse()->and($policy->delete(e28_reviewer('administrator'), $profile))->toBeFalse()->and($policy->create($moderator))->toBeFalse();
    expect(Gate::forUser(e16User())->allows('viewAny', CreatorProfile::class))->toBeFalse();
});

test('Y1-8 (8): rejection stores the reason, requires one, notifies, and allows re-applying', function () {
    $user = e16User();
    $svc = app(CreatorApplicationService::class);
    $profile = $svc->apply($user, E28_BIO);
    $reviewer = e28_reviewer();

    expect(fn () => $svc->reject($profile, $reviewer, 'x'))->toThrow(CreatorException::class);

    $svc->reject($profile, $reviewer, 'النبذة غير كافية لتقييم خبرتك.');
    $profile->refresh();
    expect($profile->status)->toBe('rejected')->and($profile->review_note)->toBe('النبذة غير كافية لتقييم خبرتك.')
        ->and(OperationalAuditLog::query()->where('action', 'creator_rejected')->exists())->toBeTrue()
        ->and($user->notifications()->count())->toBe(1);

    $again = $svc->apply($user->fresh(), E28_BIO.' نبذة محسّنة بعد الرفض.');
    expect($again->id)->toBe($profile->id)->and($again->status)->toBe('pending')->and($again->review_note)->toBeNull();
});

test('Y1-9 (9): a suspended creator can neither create nor submit, and the studio redirects', function () {
    $creator = e28_creator();
    $draft = e28_draft($creator);
    $publicId = $draft->work()->value('public_id');
    $svc = app(CreatorPuzzleService::class);

    app(CreatorApplicationService::class)->suspend($creator->creatorProfile()->first(), e28_reviewer('administrator'), 'مخالفة متكررة للإرشادات');
    $creator = $creator->fresh();

    expect(fn () => $svc->createDraft($creator, e28_data()))->toThrow(CreatorException::class)
        ->and(fn () => $svc->submit($creator, $publicId))->toThrow(CreatorException::class)
        ->and(fn () => $svc->updateDraft($creator, $publicId, e28_data()))->toThrow(CreatorException::class);

    $this->actingAs($creator)->get(route('creator.dashboard'))->assertRedirect(route('creator.apply'));
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data())->assertRedirect(route('creator.apply'));
    expect(app(CreatorPuzzleService::class)->paginate($creator->creatorProfile()->first())->total())->toBe(1);
});
`````

### `tests/Feature/CreatorE28/E28IntegrityTest.php`

`sha256: 0b7df61686721864109917cadd8f542ffbfcabd0045818a61ce7dbeb3ab15837`

`````php
<?php

use App\Filament\Resources\PuzzleResource;
use App\GameEngine\GameTypeRegistry;
use App\Models\CampaignGateQualification;
use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Services\Competitive\CompetitiveEligibility;
use App\Services\Engagement\QuestService;
use App\Services\GameSessionService;
use App\Services\PuzzleAttemptService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

/** @return array{0: CreatorPuzzle, 1: Puzzle} عمل منشور وأحجيته القانونية. */
function e28_live(string $kind = 'text', array $over = []): array
{
    $work = e28_published(e28_creator(), $kind, $over);

    return [$work, $work->publishedPuzzle];
}

test('Y3-23 (23): a creator cannot set a gem reward (create or update) and the canonical puzzle always has zero', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    foreach (['gem_reward', 'reward_currency_id'] as $field) {
        $this->post(route('creator.puzzles.store'), e28_data('text', [$field => 500]))->assertSessionHasErrors($field);
    }

    $work = e28_draft($creator)->work;
    $this->put(route('creator.puzzles.update', $work->public_id), e28_data('text', ['gem_reward' => 9]))->assertSessionHasErrors('gem_reward');

    $published = e28_published($creator);
    expect((int) $published->publishedPuzzle->gem_reward)->toBe(0)->and($published->publishedPuzzle->reward_currency_id)->toBeNull();

    // حتى كتابة مباشرة بالنموذج لا تنجو: الإنشاء يصفّر المكافآت (رفض افتراضي على مستوى النموذج).
    $forced = new Puzzle;
    $forced->forceFill(['puzzle_category_id' => e28_category()->id, 'title' => 'x', 'type' => 'text', 'difficulty' => 'easy', 'prompt' => 'سؤال طويل بما يكفي', 'answer_raw' => 'a', 'max_attempts' => 3, 'gem_reward' => 999, 'xp_reward' => 999, 'is_active' => true, 'origin' => 'community'])->save();
    expect((int) $forced->fresh()->gem_reward)->toBe(0)->and((int) $forced->fresh()->xp_reward)->toBe(0)->and($forced->fresh()->is_active)->toBeFalse();
});

test('Y3-24 (24): a creator cannot set an XP reward and the canonical puzzle always has zero', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('text', ['xp_reward' => 500]))->assertSessionHasErrors('xp_reward');

    $puzzle = e28_published($creator)->publishedPuzzle;
    expect((int) $puzzle->xp_reward)->toBe(0)->and((int) config('creators.puzzle.xp_reward'))->toBe(0)->and((int) config('creators.puzzle.gem_reward'))->toBe(0);
});

test('Y3-25 (25): a creator cannot make a puzzle the daily puzzle', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['is_daily_puzzle' => true]))->assertSessionHasErrors('is_daily_puzzle');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['daily_puzzle_date' => today()->toDateString()]))->assertSessionHasErrors('daily_puzzle_date');

    $puzzle = e28_published($creator)->publishedPuzzle;
    expect($puzzle->is_daily_puzzle)->toBeFalse()->and($puzzle->daily_puzzle_date)->toBeNull()->and($puzzle->is_active)->toBeFalse()->and($puzzle->origin)->toBe('community');
});

test('Y3-26 (26): a creator cannot attach a puzzle to an official campaign / season / competition / challenge, and admin pickers never offer community puzzles', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    // طلب واحد بكل حقول الربط الرسمي (محدِّد creator-draft-create يسمح بخمس مسودات بالدقيقة): كلها ترفض صراحة لا تُتجاهل بصمت.
    $fields = ['campaign_id', 'campaign_step_id', 'campaign_gate_id', 'season_id', 'competition_id', 'competitive_event_id', 'challenge_id'];
    $this->post(route('creator.puzzles.store'), e28_data('text', array_fill_keys($fields, 1)))->assertSessionHasErrors($fields);
    expect(CreatorPuzzle::query()->count())->toBe(0);

    $community = e28_published($creator)->publishedPuzzle;
    $official = Puzzle::factory()->create();

    // منتقيات الإدارة (الأحجيات/الحملات/التحديات/المنافسات) مبنية على official().
    expect(Puzzle::query()->official()->pluck('id')->all())->toContain($official->id)->not->toContain($community->id);
    $this->actingAs(e28_reviewer('administrator'));
    expect(PuzzleResource::getEloquentQuery()->pluck('puzzles.id')->all())->toContain($official->id)->not->toContain($community->id);
    expect(app(CompetitiveEligibility::class)->eligiblePuzzlesQuery(false)->pluck('puzzles.id')->all())->not->toContain($community->id);
});

test('Y3-27 (27): a correct community solve grants zero gems - no wallet change, no transaction rows', function () {
    [$work] = e28_live();
    $player = e16User();
    $before = e28_world($player);

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHas('success');

    expect(CommunityPuzzleAttempt::query()->where('user_id', $player->id)->where('is_correct', true)->count())->toBe(1);
    $after = e28_world($player);
    expect($after['wallet'])->toBe($before['wallet'])->and($after['counts']['GemTransaction'])->toBe($before['counts']['GemTransaction'])->and($after['counts']['CurrencyTransaction'])->toBe($before['counts']['CurrencyTransaction']);
});

test('Y3-28 (28): a correct community solve grants zero XP - no XP transaction, no level progress', function () {
    [$work] = e28_live();
    $player = e16User();
    $before = e28_world($player);

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);

    $after = e28_world($player);
    expect($after['xp'])->toBe($before['xp'])->and($after['counts']['XpTransaction'])->toBe($before['counts']['XpTransaction'])->and($after['counts']['PlayerProgression'])->toBe($before['counts']['PlayerProgression'])
        ->and($after['counts']['UserAchievementProgress'])->toBe($before['counts']['UserAchievementProgress']);

    // ضابط (control): الحل الرسمي بالمستخدم نفسه يغيّر الحالة فعلًا، فاللقطة قادرة على كشف أي تسرّب.
    $official = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 12, 'xp_reward' => 10]);
    app(PuzzleAttemptService::class)->attempt($player, $official, 'صح');
    expect(e28_world($player))->not->toBe($after);
});

test('Y3-29 (29): a community solve does not progress a daily quest (while an official solve does)', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();
    [$work] = e28_live();
    $player = e16User();

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);

    expect((int) app(QuestService::class)->progressFor($player, $quest)->current_value)->toBe(0)->and(e28_world($player)['quest'])->toBe(0);

    app(PuzzleAttemptService::class)->attempt($player, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');
    expect((int) app(QuestService::class)->progressFor($player, $quest)->fresh()->current_value)->toBeGreaterThan(0);
});

test('Y3-30 (30): a community solve does not touch the official streak (while an official solve does)', function () {
    [$work] = e28_live();
    $player = e16User();

    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);
    expect(e28_world($player)['streak'])->toBe(0)->and(e28_world($player)['counts']['PlayerStreak'])->toBe(0);

    app(PuzzleAttemptService::class)->attempt($player, Puzzle::factory()->create(['answer_raw' => 'صح']), 'صح');
    expect(e28_world($player)['streak'])->toBe(1);
});

test('Y3-31 (31): a community puzzle can never qualify a campaign gate - every official engine path refuses it', function () {
    [, $community] = e28_live();
    $player = e16User();
    $before = e28_world($player);

    expect(fn () => app(PuzzleAttemptService::class)->attempt($player, $community, 'القلم'))->toThrow(\RuntimeException::class);
    expect(fn () => app(PuzzleAttemptService::class)->purchaseHint($player, $community))->toThrow(\RuntimeException::class);
    expect(fn () => app(GameSessionService::class)->start($player, $community))->toThrow(\RuntimeException::class);

    expect(CampaignGateQualification::query()->count())->toBe(0)->and(PuzzleAttempt::query()->where('puzzle_id', $community->id)->count())->toBe(0)->and(e28_world($player))->toBe($before);

    // المسارات الرسمية العامة (الويب وAPI) تعامل أحجية المجتمع كغير موجودة.
    $this->actingAs($player);
    $this->get(route('puzzles.show', $community))->assertNotFound();
    $this->post(route('puzzles.attempt', $community), ['answer' => 'القلم'])->assertNotFound();
});

test('Y3-32 (32): a community puzzle never enters an official competition, challenge or official leaderboard', function () {
    [$work, $community] = e28_live();
    $eligibility = app(CompetitiveEligibility::class);

    expect($eligibility->reasonIfIneligible($community, false))->toBe('أحجيات المجتمع لا تصلح للمنافسات.')->and($eligibility->isEligible($community))->toBeFalse();

    $player = e16User();
    $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم']);

    // لا صف يدخل لوحة الصدارة الرسمية أو تأهيل المنافسات: جدول المحاولات الرسمي فارغ، وجدول المجتمع هو الوحيد.
    expect(PuzzleAttempt::query()->count())->toBe(0)->and(CommunityPuzzleAttempt::query()->count())->toBe(1)->and(e28_world($player)['counts']['CompetitiveEventParticipant'])->toBe(0);
    $this->get(route('leaderboard.index'))->assertOk();

    // الأحجية القانونية غير مفعّلة رسميًا ولا تُعدَّل أو تُحذف.
    expect(fn () => $community->forceFill(['is_active' => true])->save())->toThrow(\LogicException::class)
        ->and(fn () => $community->delete())->toThrow(\LogicException::class);
    expect(app(GameTypeRegistry::class)->validatorFor($community))->not->toBeNull();
});
`````

### `tests/Feature/CreatorE28/E28OperationsTest.php`

`sha256: a6e40a9f85df133a66c5b889fe90e572007a6778f3cb06aa08caa00f79fd4fcf`

`````php
<?php

use App\Filament\Resources\CommunityPuzzleReportResource\Pages\ListCommunityPuzzleReports;
use App\Filament\Resources\CreatorProfileResource\Pages\ListCreatorProfiles;
use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ListCreatorPuzzleRevisions;
use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ViewCreatorPuzzleRevision;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\UserAccountService;
use Database\Seeders\DemoCreatorSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E28-X (إضافي على قائمة Y1–Y10) - الجزء التشغيلي: لوحة الإشراف (Filament) بصلاحياتها، تجميد الحساب → إخفاء، تنظيف الوسائط اليتيمة، بيانات Demo، قائمة التنقل.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('public');
});

test('X1: the moderation resources open for a moderator and stay closed to players and to roles without creators.view', function () {
    $creator = e28_creator();
    e28_pending($creator, 'text', ['title' => 'قيد الفحص']);
    $moderator = e28_reviewer('moderator');

    $this->actingAs($moderator);
    Livewire::test(ListCreatorProfiles::class)->assertSuccessful();
    Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->assertSee('قيد الفحص');
    Livewire::test(ListCommunityPuzzleReports::class)->assertSuccessful();
    auth()->logout();

    $this->actingAs(e16User());
    expect(\App\Filament\Resources\CreatorPuzzleRevisionResource::canViewAny())->toBeFalse()
        ->and(\App\Filament\Resources\CreatorProfileResource::canViewAny())->toBeFalse()
        ->and(\App\Filament\Resources\CommunityPuzzleReportResource::canViewAny())->toBeFalse();
    $this->get('/admin')->assertForbidden();   // لاعب عادي لا يدخل اللوحة أصلًا
});

test('X2: a moderator approves from the review queue; reasons are mandatory for changes/reject; a view-only role cannot act', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'للاعتماد من اللوحة']);
    $second = e28_pending($creator, 'text', ['title' => 'للرفض من اللوحة']);

    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $this->actingAs($viewOnly);
    Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->assertTableActionHidden('approve', $revision)->assertTableActionHidden('reject', $revision);
    auth()->logout();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('reject', $second, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('approve', $revision)->assertHasNoTableActionErrors();

    $revision->refresh();
    expect($revision->status)->toBe(CreatorPuzzleRevision::STATUS_APPROVED)
        ->and($revision->work->refresh()->is_visible)->toBeTrue()
        ->and($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('reject', $second, ['note' => 'سبب الرفض مكتوب وواضح.'])->assertHasNoTableActionErrors();
    expect($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_REJECTED);
});

test('X3: the queue detail page never shows the answer, and the queue never loads the encrypted content column', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'لغز الإشراف', 'answer' => 'جوابإشرافيسري']);

    $this->actingAs(e28_reviewer('moderator'));
    $html = Livewire::test(ViewCreatorPuzzleRevision::class, ['record' => $revision->getKey()])->assertSuccessful()->html();

    expect($html)->toContain('لغز الإشراف')->and(str_contains($html, 'جوابإشرافيسري'))->toBeFalse();
    e28_assert_no_secrets($html, ['جوابإشرافيسري']);
});

test('X4: freezing a creator account hides their works immediately, and unfreezing brings them back (no stale visibility)', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'عمل يتأثر بالتجميد']);
    $admin = e28_reviewer('administrator');

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    expect(e27_titles(e27_suggest($this, 'يتأثر')))->toBe(['عمل يتأثر بالتجميد']);

    app(UserAccountService::class)->freeze($creator, $admin, 'سبب التجميد المؤقت للاختبار.');

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    expect($work->refresh()->is_visible)->toBeFalse()->and($work->hidden_reason)->toBe(CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN)
        ->and(e27_titles(e27_suggest($this, 'يتأثر')))->toBe([]);

    $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertNotFound();

    app(UserAccountService::class)->unfreeze($creator->refresh(), $admin);

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    expect($work->refresh()->is_visible)->toBeTrue()->and($work->hidden_reason)->toBeNull();
});

test('X5: creators:prune-media removes only old orphaned files - never a referenced or a recent one - and --dry-run deletes nothing', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => UploadedFile::fake()->image('a.png', 200, 200)]))->assertSessionHasNoErrors();
    $referenced = CreatorPuzzleRevision::query()->firstOrFail()->image_path;
    $directory = dirname($referenced);

    $disk = Storage::disk('public');
    $disk->put($directory.'/orphan-old.png', 'x');
    $disk->put($directory.'/orphan-new.png', 'x');
    touch($disk->path($referenced), time() - 5 * 86400);                  // مرجعي قديم: يبقى لأنه مرتبط
    touch($disk->path($directory.'/orphan-old.png'), time() - 5 * 86400);  // يتيم قديم: يُحذف

    Artisan::call('creators:prune-media', ['--dry-run' => true]);
    $disk->assertExists($directory.'/orphan-old.png');

    Artisan::call('creators:prune-media');

    $disk->assertMissing($directory.'/orphan-old.png');
    $disk->assertExists($directory.'/orphan-new.png');
    $disk->assertExists($referenced);
});

test('X6: the demo seeder is idempotent, uses only the official services, and never touches wallets, XP or official attempts', function () {
    $this->seed(DemoCreatorSeeder::class);
    $first = [
        'users' => User::query()->count(),
        'profiles' => CreatorProfile::query()->count(),
        'works' => CreatorPuzzle::query()->count(),
        'revisions' => CreatorPuzzleRevision::query()->count(),
        'canonical' => Puzzle::query()->where('origin', 'community')->count(),
    ];

    $this->seed(DemoCreatorSeeder::class);

    expect([
        'users' => User::query()->count(),
        'profiles' => CreatorProfile::query()->count(),
        'works' => CreatorPuzzle::query()->count(),
        'revisions' => CreatorPuzzleRevision::query()->count(),
        'canonical' => Puzzle::query()->where('origin', 'community')->count(),
    ])->toBe($first)
        ->and($first['works'])->toBeGreaterThan(0)
        ->and(\App\Models\XpTransaction::query()->count())->toBe(0)
        ->and(\App\Models\GemTransaction::query()->count())->toBe(0)
        ->and(\App\Models\PuzzleAttempt::query()->count())->toBe(0)
        ->and(Puzzle::query()->where('origin', 'community')->where(fn ($q) => $q->where('gem_reward', '>', 0)->orWhere('xp_reward', '>', 0))->count())->toBe(0);
});

test('X7: the demo seeder refuses to run in production', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect(fn () => app(DemoCreatorSeeder::class)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CreatorProfile::query()->count())->toBe(0);
});

test('X8: navigation - community puzzles are listed for everyone; the creator studio entry only for verified players', function () {
    $guest = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($guest)->toContain(route('community.puzzles.index'))->and(str_contains($guest, route('creator.apply')))->toBeFalse();

    $verified = $this->actingAs(e16User())->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($verified)->toContain(route('creator.apply'))->toContain('استوديو المبدع');
    auth()->logout();

    $unverified = $this->actingAs(e16User(['email_verified_at' => null]))->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect(str_contains($unverified, route('creator.apply')))->toBeFalse();
});

test('X9: beyond the partial-match limit the community search filter is a bare-column prefix LIKE served by the visible-title index, and reads no encrypted content', function () {
    config(['search.partial_match_max_id' => 0]);
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'نمط مجتمع واحد']);
    e27_cold();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $titles = e27_titles(e27_suggest($this, 'نمط', e16User()));
    $log = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "creator_puzzles"'));
    expect($titles)->toBe(['نمط مجتمع واحد'])->and($query)->not->toBeNull();

    $where = substr($query['query'], (int) strpos($query['query'], ' from "'));
    expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
        ->and(str_contains($query['query'], 'content'))->toBeFalse();

    foreach ($query['bindings'] as $binding) {
        if (is_string($binding) && str_contains($binding, '%')) {
            expect($binding)->not->toStartWith('%');
        }
    }

    $plan = collect(\Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('creator_puzzles_visible_title_index');
});
`````

### `tests/Feature/CreatorE28/E28PerformanceTest.php`

`sha256: 270cb4daab88dc40e34e8cf986896e6f870182341b8104465afaa42ed691627d`

`````php
<?php

use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ListCreatorPuzzleRevisions;
use App\Models\CreatorPuzzleRevision;
use App\Models\User;
use App\Services\Creators\CreatorPuzzleService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E28-Y10 - الأداء. المبدأ كـE27: عدد الاستعلامات لا يتبع حجم البيانات (لا N+1) والسقوف مقيسة. SQLite لا يقيس زمن MySQL؛ لذا الاختبارات بنيوية (عدد وشكل) لا زمنية.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

if (! function_exists('e28_publish_many')) {
    /** ينشر $n عملًا للمبدع (كلها بعنوان يبدأ بـ«لغز»)، ويضيف لاعبين على أول ثلاثة منها لتحريك التحليلات. */
    function e28_publish_many(User $creator, int $n, string $prefix = 'لغز'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            e28_published($creator, 'text', ['title' => $prefix.' '.$i]);
        }
    }
}

test('Y10-80 (80): the creator dashboard runs a bounded, size-independent number of queries (3 vs 25 works)', function () {
    $warm = e28_creator();
    $this->actingAs($warm)->get(route('creator.dashboard'))->assertOk();   // تسخين كاش الصلاحيات والإعدادات (لا يخصّ المقيسَين)
    auth()->logout();

    $small = e28_creator();
    e28_publish_many($small, 3);
    $large = e28_creator();
    e28_publish_many($large, 25);

    $queries = [];

    foreach (['small' => $small, 'large' => $large] as $key => $creator) {
        $this->actingAs($creator);
        $queries[$key] = e27_queries(fn () => $this->get(route('creator.dashboard'))->assertOk());
        auth()->logout();
    }

    expect($queries['large'])->toBe($queries['small'])
        ->and($queries['large'])->toBeLessThanOrEqual(20);

    // قائمة الأعمال مرقَّمة (لا تحمّل كل الأعمال) وعدد استعلاماتها ثابت أيضًا
    $this->actingAs($large);
    $list = e27_queries(fn () => $this->get(route('creator.puzzles.index'))->assertOk());
    auth()->logout();
    $this->actingAs($small);
    $listSmall = e27_queries(fn () => $this->get(route('creator.puzzles.index'))->assertOk());

    expect($list)->toBe($listSmall);
});

test('Y10-81 (81): the public creator page has no N+1 (3 vs 25 works) and paginates the works', function () {
    $warm = e28_creator();
    e28_publish_many($warm, 1);
    $this->get(route('creators.show', $warm->creatorProfile()->value('slug')))->assertOk();

    $small = e28_creator();
    e28_publish_many($small, 3);
    $large = e28_creator();
    e28_publish_many($large, 25);

    $count = fn ($creator) => e27_queries(fn () => $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertOk());
    $a = $count($small);
    $b = $count($large);

    expect($b)->toBe($a)->and($b)->toBeLessThanOrEqual(12);

    $html = $this->get(route('creators.show', $large->creatorProfile()->value('slug')))->getContent();
    expect(substr_count($html, 'من المجتمع'))->toBeLessThanOrEqual((int) config('creators.page_size') + 3);   // صفحة واحدة فقط من البطاقات (+ وسوم الصفحة)

    // والقائمة العامة لأحجيات المجتمع كذلك
    $list = fn () => e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());
    $withLarge = $list();
    expect($withLarge)->toBeLessThanOrEqual(12);
});

test('Y10-82 (82): the moderation queue is paginated and its query count does not grow with the queue (5 vs 25 pending)', function () {
    $moderator = e28_reviewer('moderator');
    $this->actingAs($moderator);

    $creators = [];

    for ($c = 0; $c < 5; $c++) {
        $creators[] = e28_creator();
    }

    foreach ($creators as $creator) {
        e28_pending($creator, 'text', ['title' => 'طابور '.fake()->unique()->numerify('####')]);
    }

    $warm = Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful();   // تسخين
    $small = e27_queries(fn () => Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful());
    expect($warm->instance()->getTableRecords()->total())->toBe(5);

    foreach ($creators as $creator) {
        for ($i = 0; $i < 4; $i++) {
            e28_pending($creator, 'text', ['title' => 'طابور '.fake()->unique()->numerify('####')]);
        }
    }

    expect(CreatorPuzzleRevision::query()->where('status', 'pending_review')->count())->toBe(25);

    $pageSize = (int) config('creators.moderation_page_size');
    $page = null;
    $large = e27_queries(function () use (&$page) {
        $page = Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->instance()->getTableRecords();
    });
    expect($page->total())->toBe(25)->and(count($page->items()))->toBe($pageSize);

    // الطابور مرقَّم: صفحة واحدة بحجم الإعداد، لا كل الـ25؛ وعدد الاستعلامات ثابت لا يتبع الحجم (لا N+1 على العمل/المبدع/التصنيف)
    expect($pageSize)->toBeLessThan(25)->and($large)->toBe($small);

    // محتوى النسخة المشفَّر لا يُحمَّل لقائمة الطابور أصلًا
    $sql = e27_sql(fn () => Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful());
    $revisionSelects = array_filter($sql, fn ($q) => str_contains($q, 'from "creator_puzzle_revisions"') && str_contains($q, 'select'));

    foreach ($revisionSelects as $query) {
        expect(str_contains($query, '"content"'))->toBeFalse('the queue must not load encrypted content');
        expect(str_contains($query, '"creator_puzzle_revisions".*') || str_contains($query, 'select *'))->toBeFalse();
    }
});

test('Y10-83 (83): community search integration has no N+1 - suggest, full search and Discover (3 vs 30 community works)', function () {
    $category = e28_category();
    $viewer = e16User();
    $creators = [e28_creator(), e28_creator(), e28_creator()];

    $grow = function (int $from, int $to) use ($creators, $category) {
        for ($i = $from + 1; $i <= $to; $i++) {
            e28_published($creators[$i % 3], 'text', ['title' => 'نسيج أحجية '.$i, 'puzzle_category_id' => $category->id]);
        }
    };

    $endpoints = [
        'suggest guest' => fn () => $this->getJson(route('search.suggest', ['q' => 'نسيج']))->assertOk(),
        'suggest player' => fn () => $this->actingAs($viewer)->getJson(route('search.suggest', ['q' => 'نسيج']))->assertOk(),
        'search all' => fn () => $this->actingAs($viewer)->get(route('search.index', ['q' => 'نسيج']))->assertOk(),
        'search community tab' => fn () => $this->actingAs($viewer)->get(route('search.index', ['q' => 'نسيج', 'type' => 'community']))->assertOk(),
        'discover guest' => fn () => $this->get(route('discover.index'))->assertOk(),
        'discover player' => fn () => $this->actingAs($viewer)->get(route('discover.index'))->assertOk(),
        'community list' => fn () => $this->get(route('community.puzzles.index'))->assertOk(),
    ];

    $grow(0, 3);
    foreach ($endpoints as $run) {
        $run();   // تسخين الكاش (الرائج، مجسّ الحجم)
    }
    $small = array_map(fn ($run) => e27_queries($run), $endpoints);

    $grow(3, 30);
    $large = array_map(fn ($run) => e27_queries($run), $endpoints);

    expect($large)->toBe($small);

    // المجموعة والبطاقات مقيّدة بالحدود: لا تتبع حجم المجتمع
    $suggest = e27_suggest($this, 'نسيج');
    $community = collect($suggest['groups'])->firstWhere('type', 'community');
    expect(count($community['items']))->toBeLessThanOrEqual((int) config('search.suggest_per_type', 3));
});
`````

### `tests/Feature/CreatorE28/E28PublicTest.php`

`sha256: 80dec3c183891f09ff3edfef60363357d30812107099103f5e5a542b81c3e5b5`

`````php
<?php

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorVisibilityService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

function e28_slug(User $creator): string
{
    return $creator->creatorProfile()->value('slug');
}

test('Y6-49 (49): the creator public page is open to guests with name, bio, joined date and totals', function () {
    $creator = e28_creator(e16User(['name' => 'نور المبدعة']));
    e28_published($creator, 'text', ['title' => 'لغز نور الأول']);

    $response = $this->get(route('creators.show', e28_slug($creator)))->assertOk();
    $response->assertSee('نور المبدعة')->assertSee('مبدع')->assertSee('لغز نور الأول')->assertSee('مبدع ألغاز يحب صناعة التحديات الذهنية للمجتمع.');
    expect($response->getContent())->toContain('أحجيات منشورة');
});

test('Y6-50 (50): only published, visible content appears on the creator page', function () {
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'منشورة ظاهرة']);
    e28_draft($creator, 'text', ['title' => 'مسودة خاصة']);
    e28_pending($creator, 'text', ['title' => 'قيد المراجعة خاصة']);
    $rejected = e28_pending($creator, 'text', ['title' => 'مرفوضة خاصة']);
    app(\App\Services\Creators\CreatorReviewService::class)->reject($rejected, e28_reviewer(), 'لا يناسب الإرشادات تمامًا.');
    $archived = e28_published($creator, 'text', ['title' => 'مؤرشفة خاصة']);
    app(CreatorPuzzleService::class)->archive($creator, $archived->public_id);

    $html = $this->get(route('creators.show', e28_slug($creator)))->assertOk()->getContent();
    expect($html)->toContain('منشورة ظاهرة')->and($html)->not->toContain('مسودة خاصة')->and($html)->not->toContain('قيد المراجعة خاصة')->and($html)->not->toContain('مرفوضة خاصة')->and($html)->not->toContain('مؤرشفة خاصة');
});

test('Y6-51 (51): a draft (or pending work) is not reachable publicly - 404 for guests, players and other creators', function () {
    $creator = e28_creator();
    $draft = e28_draft($creator, 'text', ['title' => 'مسودة سرية'])->work;
    $pending = e28_pending($creator, 'text', ['title' => 'معلقة سرية'])->work;

    foreach ([$draft, $pending] as $work) {
        $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
        $this->actingAs(e16User())->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
        $this->actingAs(e28_creator())->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
        $this->actingAs($creator)->get(route('community.puzzles.show', $work->public_id))->assertNotFound();   // حتى صاحبها: يعاينها من الاستوديو لا من الصفحة العامة
        auth()->logout();
        $this->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'x'])->assertRedirect(route('login'));
    }

    $this->get(route('community.puzzles.index'))->assertOk()->assertDontSee('مسودة سرية')->assertDontSee('معلقة سرية');
});

test('Y6-52 (52): a rejected work is not reachable publicly', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'لغز مرفوض']);
    app(\App\Services\Creators\CreatorReviewService::class)->reject($revision, e28_reviewer(), 'سبب الرفض مكتوب وواضح.');
    $work = $revision->work;

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs(e16User())->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
    $this->get(route('community.puzzles.index'))->assertDontSee('لغز مرفوض');
    $this->get(route('creators.show', e28_slug($creator)))->assertDontSee('لغز مرفوض');
});

test('Y6-53 (53): a moderator-hidden publication is not reachable (page, list, creator page), and returns on restore', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز سيُخفى']);
    $reviewer = e28_reviewer();

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    app(CreatorVisibilityService::class)->hide($work, $reviewer, 'مخالفة للإرشادات بعد المراجعة.');

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs(e16User())->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
    $this->get(route('community.puzzles.index'))->assertDontSee('لغز سيُخفى');
    $this->get(route('creators.show', e28_slug($creator)))->assertDontSee('لغز سيُخفى');
    expect($work->fresh()->is_visible)->toBeFalse();

    app(CreatorVisibilityService::class)->restore($work->fresh(), $reviewer);
    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
});

test('Y6-54 (54): the creator attribution is visible and links to the creator page', function () {
    $creator = e28_creator(e16User(['name' => 'سامي صانع الألغاز']));
    $work = e28_published($creator, 'text', ['title' => 'لغز سامي']);

    $show = $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    $show->assertSee('بقلم')->assertSee('سامي صانع الألغاز');
    expect($show->getContent())->toContain(route('creators.show', e28_slug($creator)));

    $this->get(route('community.puzzles.index'))->assertOk()->assertSee('سامي صانع الألغاز');
});

test('Y6-55 (55): the community label is on every public surface and the page states there are no rewards', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز موسوم']);

    foreach ([route('community.puzzles.show', $work->public_id), route('community.puzzles.index'), route('creators.show', e28_slug($creator))] as $url) {
        $this->get($url)->assertOk()->assertSee('من المجتمع');
    }

    $this->get(route('community.puzzles.show', $work->public_id))->assertSee('بلا جوائز');
    $official = \App\Models\Puzzle::factory()->create(['title' => 'لغز رسمي']);
    $this->get(route('puzzles.index'))->assertOk()->assertDontSee('من المجتمع');
});

test('Y6-56 (56): private creator data is absent - no email, status, review notes, user id, hidden reason', function () {
    $user = e16User(['name' => 'سلمى', 'email' => 'salma.private@example.test']);
    $creator = e28_creator($user);
    $revision = e28_pending($creator, 'text', ['title' => 'لغز سلمى']);
    app(\App\Services\Creators\CreatorReviewService::class)->approve($revision, e28_reviewer(), 'ملاحظة اعتماد داخلية سرية');
    $profile = CreatorProfile::query()->firstOrFail();
    app(CreatorVisibilityService::class)->hide($revision->work->fresh(), e28_reviewer(), 'ملاحظة إخفاء داخلية سرية');
    $second = e28_published($creator, 'text', ['title' => 'لغز ثانٍ ظاهر']);

    foreach ([route('creators.show', $profile->slug), route('community.puzzles.index'), route('community.puzzles.show', $second->public_id)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('salma.private@example.test')->and($html)->not->toContain('ملاحظة اعتماد داخلية سرية')->and($html)->not->toContain('ملاحظة إخفاء داخلية سرية')
            ->and($html)->not->toContain('account_frozen')->and($html)->not->toContain('moderation')->and($html)->not->toContain('review_note');
        e28_assert_no_secrets($html);
    }

    // مبدع غير معتمَد/معلَّق/مجمَّد = صفحته 404.
    app(CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'تعليق لأغراض الاختبار هنا');
    $this->get(route('creators.show', $profile->slug))->assertNotFound();
    $this->get(route('community.puzzles.show', $second->public_id))->assertNotFound();

    $pending = app(CreatorApplicationService::class)->apply(e16User(), 'مقدّم جديد ينتظر قرار المراجعة هنا الآن.');
    $this->get(route('creators.show', $pending->slug))->assertNotFound();
    $this->get('/creators/does-not-exist')->assertNotFound();
});
`````

### `tests/Feature/CreatorE28/E28ReportsTest.php`

`sha256: f7780efdf19bec17de253f1658d6b0c67e192ac3492f9492fcff32398ac6175c`

`````php
<?php

use App\Models\CommunityPuzzleReport;
use App\Models\OperationalAuditLog;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('Y7-57 (57): a verified user reports a community puzzle with a closed reason', function () {
    $work = e28_published(e28_creator());
    $reporter = e16User();

    $this->actingAs($reporter)->post(route('community.puzzles.report', $work->public_id), ['reason' => 'misleading', 'details' => 'السؤال مضلل برأيي.'])->assertSessionHas('success');

    $report = CommunityPuzzleReport::query()->firstOrFail();
    expect($report->status)->toBe('open')->and($report->reason)->toBe('misleading')->and($report->reporter_id)->toBe($reporter->id)->and($report->creator_puzzle_id)->toBe($work->id)->and($report->puzzle_id)->toBe($work->published_puzzle_id);

    // سبب خارج القائمة المغلقة مرفوض، والضيف يُحوَّل لتسجيل الدخول.
    $this->actingAs(e16User())->post(route('community.puzzles.report', $work->public_id), ['reason' => 'because'])->assertSessionHasErrors('reason');
    auth()->logout();
    $this->post(route('community.puzzles.report', $work->public_id), ['reason' => 'spam'])->assertRedirect(route('login'));
    expect(CommunityPuzzleReport::query()->count())->toBe(1);
});

test('Y7-58 (58): a creator cannot report their own puzzle', function () {
    $creator = e28_creator();
    $work = e28_published($creator);

    $this->actingAs($creator)->post(route('community.puzzles.report', $work->public_id), ['reason' => 'spam'])->assertSessionHas('error');
    expect(CommunityPuzzleReport::query()->count())->toBe(0)
        ->and(fn () => app(CommunityReportService::class)->report($creator, $work, 'spam', null))->toThrow(CreatorException::class);
});

test('Y7-59 (59): one open report per user per publication - service and database constraint', function () {
    $work = e28_published(e28_creator());
    $reporter = e16User();
    $svc = app(CommunityReportService::class);

    $svc->report($reporter, $work, 'broken', null);
    expect(fn () => $svc->report($reporter, $work, 'spam', null))->toThrow(CreatorException::class)->and(CommunityPuzzleReport::query()->count())->toBe(1);

    // طبقة قاعدة البيانات تحمي حتى لو تجاوزت الخدمة (سباق متزامن).
    expect(fn () => DB::table('community_puzzle_reports')->insert(['creator_puzzle_id' => $work->id, 'reporter_id' => $reporter->id, 'reason' => 'spam', 'status' => 'open', 'open_marker' => 1, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);

    // بعد الإغلاق يجوز بلاغ جديد.
    $svc->dismiss(CommunityPuzzleReport::query()->firstOrFail(), e28_reviewer(), 'لا مخالفة');
    $svc->report($reporter, $work, 'spam', null);
    expect(CommunityPuzzleReport::query()->count())->toBe(2)->and(CommunityPuzzleReport::query()->where('status', 'open')->count())->toBe(1);
});

test('Y7-60 (60): a moderator handles a report - dismiss and action, with handler and note; unauthorized users cannot', function () {
    $work = e28_published(e28_creator());
    $svc = app(CommunityReportService::class);
    $first = $svc->report(e16User(), $work, 'spam', null);
    $second = $svc->report(e16User(), $work, 'broken', 'لا يعمل الحل');
    $moderator = e28_reviewer();

    expect(fn () => $svc->dismiss($first, e16User()))->toThrow(HttpException::class);

    $svc->dismiss($first, $moderator, 'لا مخالفة ظاهرة');
    $svc->action($second, $moderator, 'تم التواصل مع المبدع');

    expect($first->fresh()->status)->toBe('dismissed')->and($first->fresh()->handled_by)->toBe($moderator->id)->and($first->fresh()->handler_note)->toBe('لا مخالفة ظاهرة')->and($first->fresh()->open_marker)->toBeNull()
        ->and($second->fresh()->status)->toBe('actioned')
        ->and(fn () => $svc->dismiss($first->fresh(), $moderator))->toThrow(CreatorException::class);
    expect($work->fresh()->is_visible)->toBeTrue();   // المعالجة وحدها لا تخفي
});

test('Y7-61 (61): handling a report with hide removes the publication and closes every open report on it', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز مُبلَّغ عنه']);
    $svc = app(CommunityReportService::class);
    $a = $svc->report(e16User(), $work, 'inappropriate', null);
    $b = $svc->report(e16User(), $work, 'copyright', null);

    $svc->actionAndHide($a, e28_reviewer(), 'محتوى مخالف تم التأكد منه.');

    $work->refresh();
    expect($work->is_visible)->toBeFalse()->and($work->isHidden())->toBeTrue()->and($work->hidden_reason)->toBe('moderation')->and($work->hidden_note)->toBe('محتوى مخالف تم التأكد منه.')
        ->and($a->fresh()->status)->toBe('actioned')->and($b->fresh()->status)->toBe('actioned')->and(CommunityPuzzleReport::query()->where('status', 'open')->count())->toBe(0);
    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();

    // المبدع يرى السبب في الاستوديو ويُشعَر.
    $this->actingAs($creator)->get(route('creator.puzzles.edit', $work->public_id))->assertOk()->assertSee('أخفى فريق الإشراف')->assertSee('محتوى مخالف تم التأكد منه.');
    expect($creator->notifications()->count())->toBeGreaterThanOrEqual(3);   // اعتماد مبدع + اعتماد أحجية + إخفاء
});

test('Y7-62 (62): hiding and report handling are audited with actor and metadata', function () {
    $work = e28_published(e28_creator());
    $svc = app(CommunityReportService::class);
    $report = $svc->report(e16User(), $work, 'spam', null);
    $moderator = e28_reviewer();
    $svc->actionAndHide($report, $moderator, 'سبب إخفاء موثّق بالتدقيق.');

    $hidden = OperationalAuditLog::query()->where('action', 'community_puzzle_hidden')->firstOrFail();
    $actioned = OperationalAuditLog::query()->where('action', 'community_report_actioned')->firstOrFail();

    expect($hidden->actor_user_id)->toBe($moderator->id)->and($hidden->subject_id)->toBe($work->id)->and($hidden->metadata['note'])->toBe('سبب إخفاء موثّق بالتدقيق.')
        ->and($actioned->actor_user_id)->toBe($moderator->id)->and($actioned->subject_id)->toBe($report->id)->and($actioned->metadata['hide'])->toBeTrue();

    app(\App\Services\Creators\CreatorVisibilityService::class)->restore($work->fresh(), $moderator);
    expect(OperationalAuditLog::query()->where('action', 'community_puzzle_restored')->where('actor_user_id', $moderator->id)->exists())->toBeTrue();
});

test('Y7-63 (63): a hidden publication disappears from Search and Discover immediately', function () {
    $work = e28_published(e28_creator(), 'text', ['title' => 'نمط خفي قريبًا']);

    expect(e27_titles(e27_suggest($this, 'نمط')))->toContain('نمط خفي قريبًا');
    $this->get(route('discover.index'))->assertSee('نمط خفي قريبًا');

    app(\App\Services\Creators\CreatorVisibilityService::class)->hide($work, e28_reviewer(), 'إخفاء إشرافي لأغراض الاختبار.');

    expect(e27_titles(e27_suggest($this, 'نمط')))->not->toContain('نمط خفي قريبًا');
    $this->get(route('search.index', ['q' => 'نمط خفي']))->assertOk()->assertDontSee('نمط خفي قريبًا');
    $this->get(route('discover.index'))->assertOk()->assertDontSee('نمط خفي قريبًا');
});
`````

### `tests/Feature/CreatorE28/E28ReviewTest.php`

`sha256: 3850ed3176d123321b72e5d7e6a99eadcad08bdcfea7a3ece587d56fe362c1fb`

`````php
<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\OperationalAuditLog;
use App\Models\Puzzle;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Notifications\NotificationDispatcher;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('Y4-33 (33): a draft is submitted for review - pending status, timestamp, visible in the pending bucket', function () {
    $creator = e28_creator();
    $revision = e28_draft($creator);
    $publicId = $revision->work()->value('public_id');

    $this->actingAs($creator)->post(route('creator.puzzles.submit', $publicId))->assertRedirect(route('creator.puzzles.edit', $publicId))->assertSessionHas('success');

    $revision->refresh();
    expect($revision->status)->toBe('pending_review')->and($revision->submitted_at)->not->toBeNull();
    $this->get(route('creator.puzzles.index', ['status' => 'pending']))->assertOk()->assertSee($revision->title);
    expect($revision->work->fresh()->is_visible)->toBeFalse();
});

test('Y4-34 (34): a submitted draft is locked - no edit, no resubmit - until the creator withdraws it', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);
    $publicId = $revision->work()->value('public_id');
    $svc = app(CreatorPuzzleService::class);

    expect(fn () => $svc->updateDraft($creator, $publicId, e28_data('text', ['title' => 'تعديل محظور'])))->toThrow(CreatorException::class)
        ->and(fn () => $svc->submit($creator, $publicId))->toThrow(CreatorException::class);
    $this->actingAs($creator)->put(route('creator.puzzles.update', $publicId), e28_data('text', ['title' => 'تعديل محظور']))->assertSessionHas('error');
    $this->get(route('creator.puzzles.edit', $publicId))->assertOk()->assertSee('قيد المراجعة');
    expect($revision->fresh()->title)->not->toBe('تعديل محظور');

    $svc->withdraw($creator, $publicId);
    expect($revision->fresh()->status)->toBe('draft')->and($revision->fresh()->submitted_at)->toBeNull();
});

test('Y4-35 (35): a reviewer requests changes - reason required and stored, creator notified, audit written', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $revision = e28_pending($creator);
    $svc = app(CreatorReviewService::class);

    expect(fn () => $svc->requestChanges($revision, $reviewer, 'x'))->toThrow(CreatorException::class);

    $svc->requestChanges($revision, $reviewer, 'وضّح صياغة السؤال أكثر من فضلك.');
    $revision->refresh();
    expect($revision->status)->toBe('changes_requested')->and($revision->review_note)->toBe('وضّح صياغة السؤال أكثر من فضلك.')->and($revision->reviewed_by)->toBe($reviewer->id)
        ->and(OperationalAuditLog::query()->where('action', 'creator_submission_changes_requested')->where('subject_id', $revision->id)->exists())->toBeTrue()
        ->and($creator->notifications()->count())->toBeGreaterThanOrEqual(2);   // اعتماد المبدع + طلب التعديل

    $this->actingAs($creator)->get(route('creator.puzzles.edit', $revision->work()->value('public_id')))->assertOk()->assertSee('وضّح صياغة السؤال');
});

test('Y4-36 (36): after changes are requested the creator edits and resubmits, and the second review approves', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $revision = e28_pending($creator);
    $publicId = $revision->work()->value('public_id');
    app(CreatorReviewService::class)->requestChanges($revision, $reviewer, 'الجواب غير واضح، حدّده بدقة.');

    $this->actingAs($creator)->put(route('creator.puzzles.update', $publicId), e28_data('text', ['title' => 'عنوان معدّل بعد المراجعة', 'answer' => 'الممحاة']))->assertSessionHasNoErrors();
    $this->post(route('creator.puzzles.submit', $publicId))->assertSessionHas('success');

    $revision->refresh();
    expect($revision->status)->toBe('pending_review')->and($revision->title)->toBe('عنوان معدّل بعد المراجعة')->and($revision->review_note)->toBeNull();

    app(CreatorReviewService::class)->approve($revision, $reviewer);
    expect($revision->fresh()->status)->toBe('approved')->and($revision->work->fresh()->is_visible)->toBeTrue();
});

test('Y4-37 (37): a reviewer rejects - reason stored, notified, the revision is frozen, and the creator can start a fresh revision', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);
    $publicId = $revision->work()->value('public_id');

    app(CreatorReviewService::class)->reject($revision, e28_reviewer(), 'المحتوى لا يتوافق مع إرشادات النشر.');
    $revision->refresh();

    expect($revision->status)->toBe('rejected')->and($revision->review_note)->toBe('المحتوى لا يتوافق مع إرشادات النشر.')
        ->and(OperationalAuditLog::query()->where('action', 'creator_submission_rejected')->exists())->toBeTrue()
        ->and($revision->work->fresh()->is_visible)->toBeFalse()->and($revision->puzzle_id)->toBeNull();

    expect(fn () => app(CreatorPuzzleService::class)->updateDraft($creator, $publicId, e28_data()))->toThrow(CreatorException::class);
    expect(function () use ($revision) {
        $revision->title = 'تلاعب';
        $revision->save();
    })->toThrow(\LogicException::class);

    $next = app(CreatorPuzzleService::class)->revise($creator, $publicId);
    expect($next->revision_number)->toBe(2)->and($next->status)->toBe('draft');
});

test('Y4-38 (38): a reviewer approves - the work is published and visible, only by an authorized reviewer', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);

    expect(fn () => app(CreatorReviewService::class)->approve($revision, e16User()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($revision->fresh()->status)->toBe('pending_review');

    app(CreatorReviewService::class)->approve($revision, e28_reviewer(), 'ممتازة');

    $work = $revision->work->fresh();
    expect($revision->fresh()->status)->toBe('approved')->and($work->is_visible)->toBeTrue()->and($work->published_revision_id)->toBe($revision->id)
        ->and($work->published_title)->toBe($revision->title)->and($work->first_published_at)->not->toBeNull();

    // لا يُعتمد مرتين.
    expect(fn () => app(CreatorReviewService::class)->approve($revision->fresh(), e28_reviewer()))->toThrow(CreatorException::class);
});

test('Y4-39 (39): approval materializes exactly one canonical community puzzle through the official engine', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'sequence');
    expect(Puzzle::query()->where('origin', 'community')->count())->toBe(0);

    app(CreatorReviewService::class)->approve($revision, e28_reviewer());

    $revision->refresh();
    $puzzle = Puzzle::query()->findOrFail($revision->puzzle_id);
    expect(Puzzle::query()->where('origin', 'community')->count())->toBe(1)
        ->and($puzzle->origin)->toBe('community')->and($puzzle->is_active)->toBeFalse()->and((int) $puzzle->gem_reward)->toBe(0)->and((int) $puzzle->xp_reward)->toBe(0)
        ->and($puzzle->game_type)->toBe('sequence')->and($puzzle->solution_data)->not->toBeEmpty()
        ->and($revision->work->fresh()->published_puzzle_id)->toBe($puzzle->id)->and((int) $puzzle->max_attempts)->toBe((int) config('creators.puzzle.max_attempts'));
});

test('Y4-40 (40): the public payload is safe - no answer, hash, solution or config reaches a public page', function () {
    $creator = e28_creator();
    $text = e28_published($creator, 'text', ['answer' => 'جواب-فريد-٩٩٩', 'hint' => 'تلميح فريد عام']);
    $sequence = e28_published($creator, 'sequence', ['items' => ['ألف', 'باء', 'جيم', 'دال']]);

    foreach ([$text, $sequence] as $work) {
        $this->actingAs(e16User());
        $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
        e28_assert_no_secrets($html, ['جواب-فريد-٩٩٩']);
    }

    $guestHtml = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    e28_assert_no_secrets($guestHtml, ['جواب-فريد-٩٩٩']);

    // الحمولة العامة لا تتضمن الجواب المشفَّر للنسخة أيضًا (content مخفي من التسلسل).
    expect(array_key_exists('content', $text->latestRevision->toArray()))->toBeFalse();
});

test('Y4-41 (41): approval is audited with actor, work, revision and canonical puzzle', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $revision = e28_pending($creator);
    app(CreatorReviewService::class)->approve($revision, $reviewer);

    $log = OperationalAuditLog::query()->where('action', 'creator_submission_approved')->firstOrFail();
    expect($log->actor_user_id)->toBe($reviewer->id)->and($log->subject_id)->toBe($revision->id)->and($log->subject_type)->toBe(CreatorPuzzleRevision::class)
        ->and($log->metadata['work'])->toBe($revision->work()->value('public_id'))->and($log->metadata['revision'])->toBe(1)->and($log->metadata['puzzle_id'])->toBe($revision->fresh()->puzzle_id);
});

test('Y4-42 (42): a notification failure never rolls back an approval', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator);

    $this->mock(NotificationDispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->atLeast()->once()->andThrow(new \RuntimeException('notification backend down')));   // يثبت أن مسار الفشل نُفِّذ فعلًا

    app(CreatorReviewService::class)->approve($revision, e28_reviewer());

    expect($revision->fresh()->status)->toBe('approved')->and($revision->work->fresh()->is_visible)->toBeTrue()->and(Puzzle::query()->where('origin', 'community')->count())->toBe(1)
        ->and(OperationalAuditLog::query()->where('action', 'creator_submission_approved')->count())->toBe(1);
});
`````

### `tests/Feature/CreatorE28/E28SearchDiscoveryTest.php`

`sha256: 3725e58fd0bfcc08c7bba4a400d17c11ee20e8d8fc117435dbf0216382f44629`

`````php
<?php

use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Creators\CreatorVisibilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E28-Y8 - أحجيات المجتمع داخل البحث والاكتشاف (E27): نوع بحث مستقل بوسم «من المجتمع»، وأقسام اكتشاف مستقلة، ورائج بلاعبين مميَّزين موثَّقين،
| وتنوّع المبدعين، ولا تسرّب لمحتوى خاص. كل شيء محلي على SQLite وكاش array.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e28_plays')) {
    /** يكتب $players لاعبًا مميَّزًا (حسابات موثَّقة جديدة) بمحاولة واحدة لكل منهم على عمل منشور؛ أول $solvers يحلّون. @return list<User> */
    function e28_plays(CreatorPuzzle $work, int $players, int $solvers = 0, array $userAttrs = []): array
    {
        $users = [];

        for ($i = 0; $i < $players; $i++) {
            $user = e16User($userAttrs);
            DB::table('community_puzzle_attempts')->insert([
                'user_id' => $user->id,
                'creator_puzzle_id' => $work->id,
                'puzzle_id' => $work->published_puzzle_id,
                'attempt_number' => 1,
                'is_correct' => $i < $solvers,
                'created_at' => now()->subHour(),
            ]);
            $users[] = $user;
        }

        Cache::forget('discover.community.trending');

        return $users;
    }

    /** @return list<string> مفاتيح أقسام /discover بترتيب ظهورها. */
    function e28_discover_sections(string $html): array
    {
        return uiAttrs(uiXpath($html), '//section[@data-discover-section]', 'data-discover-section');
    }

    /** @return list<string> عناوين بطاقات قسم اكتشاف. */
    function e28_section_titles(string $html, string $key): array
    {
        $x = uiXpath($html);
        $out = [];

        foreach ($x->query('//section[@data-discover-section="'.$key.'"]//a/h3') as $h3) {
            $out[] = trim($h3->textContent);
        }

        return $out;
    }
}

test('Y8-64 (64): a published community puzzle is searchable by its title', function () {
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'لغز الغيمة المضيئة']);

    $json = e27_suggest($this, 'الغيمة');

    expect(e27_titles($json))->toContain('لغز الغيمة المضيئة')
        ->and(e27_types($json))->toContain('community');

    // وصفحة البحث الكاملة تجدها أيضًا
    $this->get(route('search.index', ['q' => 'الغيمة']))->assertOk()->assertSee('لغز الغيمة المضيئة');
});

test('Y8-65 (65): drafts, pending, changes-requested and rejected works are never searchable', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    e28_draft($creator, 'text', ['title' => 'زمردة مسودة']);
    e28_pending($creator, 'text', ['title' => 'زمردة معلقة']);

    $changes = e28_pending($creator, 'text', ['title' => 'زمردة تعديلات']);
    app(CreatorReviewService::class)->requestChanges($changes, $reviewer, 'يرجى توضيح السؤال أكثر.');

    $rejected = e28_pending($creator, 'text', ['title' => 'زمردة مرفوضة']);
    app(CreatorReviewService::class)->reject($rejected, $reviewer, 'لا يناسب الإرشادات تمامًا.');

    e28_published($creator, 'text', ['title' => 'زمردة منشورة']);

    $titles = e27_titles(e27_suggest($this, 'زمردة'));

    expect($titles)->toBe(['زمردة منشورة']);
});

test('Y8-66 (66): hidden, archived and suspended-creator works are not searchable, and return on restore', function () {
    $creator = e28_creator();
    $reviewer = e28_reviewer();
    $hidden = e28_published($creator, 'text', ['title' => 'ياقوتة مخفية']);
    $archived = e28_published($creator, 'text', ['title' => 'ياقوتة مؤرشفة']);
    e28_published($creator, 'text', ['title' => 'ياقوتة ظاهرة']);

    app(CreatorVisibilityService::class)->hide($hidden, $reviewer, 'مخالفة للإرشادات بعد المراجعة.');
    app(CreatorPuzzleService::class)->archive($creator, $archived->public_id);

    expect(e27_titles(e27_suggest($this, 'ياقوتة')))->toBe(['ياقوتة ظاهرة']);

    // تعليق المبدع يُخفي كل أعماله من البحث، واستعادته تُعيد المنشور
    $profile = $creator->creatorProfile()->first();
    $admin = e28_reviewer('administrator');
    app(CreatorApplicationService::class)->suspend($profile, $admin, 'مخالفة متكررة للإرشادات.');
    expect(e27_titles(e27_suggest($this, 'ياقوتة')))->toBe([]);

    app(CreatorApplicationService::class)->restore($profile->refresh(), $admin);
    expect(e27_titles(e27_suggest($this, 'ياقوتة')))->toBe(['ياقوتة ظاهرة']);
});

test('Y8-67 (67): community results carry their own group, label and badge - never mixed into the official puzzles group', function () {
    $category = e27_category('أحاجي');
    e27_puzzle('لؤلؤة رسمية', ['puzzle_category_id' => $category->id]);
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'لؤلؤة مجتمع', 'difficulty' => 'hard']);

    $json = e27_suggest($this, 'لؤلؤة');
    $community = collect($json['groups'])->firstWhere('type', 'community');
    $official = collect($json['groups'])->firstWhere('type', 'puzzles');

    expect($community)->not->toBeNull()
        ->and($community['label'])->toBe('أحجيات المجتمع')
        ->and(collect($community['items'])->pluck('title')->all())->toBe(['لؤلؤة مجتمع'])
        ->and($community['items'][0]['badge'])->toContain('من المجتمع')->toContain('صعب')
        ->and($community['items'][0]['url'])->toStartWith('/community/puzzles/')
        ->and(collect($official['items'])->pluck('title')->all())->toBe(['لؤلؤة رسمية'])
        ->and(collect($official['items'])->pluck('badge')->implode(' '))->not->toContain('من المجتمع');

    $this->get(route('search.index', ['q' => 'لؤلؤة']))->assertOk()->assertSee('أحجيات المجتمع')->assertSee('من المجتمع');
});

test('Y8-68 (68): the Discover hub has community sections labelled as community, for guests and members', function () {
    $author = e28_creator();
    e28_published($author, 'text', ['title' => 'عقد الحكاية']);

    foreach ([null, e16User()] as $viewer) {
        $caller = $viewer ? $this->actingAs($viewer) : $this;
        $html = $caller->get(route('discover.index'))->assertOk()->getContent();

        expect(e28_discover_sections($html))->toContain('community-new')
            ->and(e28_section_titles($html, 'community-new'))->toBe(['عقد الحكاية'])
            ->and(uiXpath($html)->query('//section[@data-discover-section="community-new"]')->item(0)->textContent)->toContain('من المجتمع')->toContain('بقلم');
        auth()->logout();
    }
});

test('Y8-69 (69): trending counts unique verified, unfrozen players - not raw attempts, not unverified or frozen accounts', function () {
    $author = e28_creator();
    $spammed = e28_published($author, 'text', ['title' => 'لغز مكرر محاولاته']);
    $popular = e28_published($author, 'text', ['title' => 'لغز لاعبوه كثر']);
    $tainted = e28_published($author, 'text', ['title' => 'لغز لاعبوه مشبوهون']);

    // لاعب واحد بعشر محاولات: لاعب مميَّز واحد = تحت الحد الأدنى
    $one = e16User();
    foreach (range(1, 10) as $n) {
        DB::table('community_puzzle_attempts')->insert(['user_id' => $one->id, 'creator_puzzle_id' => $spammed->id, 'puzzle_id' => $spammed->published_puzzle_id, 'attempt_number' => min($n, 3), 'is_correct' => false, 'created_at' => now()->subHour()]);
    }

    e28_plays($popular, 3, 1);
    e28_plays($tainted, 3, 0, ['email_verified_at' => null]);
    foreach (e28_plays($tainted, 2) as $user) {
        $user->forceFill(['is_frozen' => true])->save();
    }
    Cache::flush();

    $trending = e28_section_titles($this->get(route('discover.index'))->assertOk()->getContent(), 'community-trending');

    expect($trending)->toBe(['لغز لاعبوه كثر'])
        ->and(array_keys(app(\App\Services\Creators\CommunityDiscovery::class)->trendingScores()))->toBe([$popular->id]);
});

test('Y8-70 (70): recommendations include an eligible community puzzle, and exclude own, already-played and already-shown works', function () {
    // أقدم ← أحدث. القسم «جديد» يأخذ أحدث عملين لكل مبدع (السقف)، و«مقترحة لك» ما بقي بعد استبعاد المعروض والملعوب والمملوك.
    $author = e28_creator();
    e28_published($author, 'text', ['title' => 'مقترحة قديمة']);
    $played = e28_published($author, 'text', ['title' => 'مقترحة لعبتها']);
    e28_published($author, 'text', ['title' => 'مقترحة حديثة ١']);
    e28_published($author, 'text', ['title' => 'مقترحة حديثة ٢']);

    $mine = e28_creator();
    foreach (['١', '٢', '٣'] as $n) {
        e28_published($mine, 'text', ['title' => 'من أعمالي '.$n]);
    }

    $player = e28_plays($played, 1)[0];

    $html = $this->actingAs($player)->get(route('discover.index'))->assertOk()->getContent();
    $forYou = e28_section_titles($html, 'community-for-you');
    expect($forYou)->toContain('مقترحة قديمة')->not->toContain('مقترحة لعبتها')
        ->and(array_intersect($forYou, e28_section_titles($html, 'community-new')))->toBe([]);   // لا تكرار بين القسمين
    auth()->logout();

    $own = $this->actingAs($mine)->get(route('discover.index'))->assertOk()->getContent();
    $ownForYou = e28_section_titles($own, 'community-for-you');
    expect($ownForYou)->toContain('مقترحة قديمة');

    foreach ($ownForYou as $title) {
        expect($title)->not->toStartWith('من أعمالي');
    }
    auth()->logout();

    // الضيف: لا قسم شخصي
    expect(e28_discover_sections($this->get(route('discover.index'))->getContent()))->not->toContain('community-for-you');
});

test('Y8-71 (71): the per-creator cap keeps one prolific creator from owning the community sections', function () {
    $prolific = e28_creator();
    foreach (range(1, 5) as $i) {
        e28_published($prolific, 'text', ['title' => 'غزير-'.$i]);
    }
    $other = e28_creator();
    e28_published($other, 'text', ['title' => 'آخر-1']);

    $titles = e28_section_titles($this->get(route('discover.index'))->assertOk()->getContent(), 'community-new');
    $fromProlific = array_filter($titles, fn ($t) => str_starts_with($t, 'غزير-'));

    expect(count($fromProlific))->toBeLessThanOrEqual((int) config('creators.discovery.per_creator_cap'))
        ->and($titles)->toContain('آخر-1');
});

test('Y8-72 (72): search and Discover never leak private content - answers, hints, drafts, emails, internal ids', function () {
    $creator = e28_creator(e16User(['name' => 'صانعة السر', 'email' => 'secret-maker@example.test']));
    e28_published($creator, 'text', ['title' => 'لغز المفتاح الخفي', 'answer' => 'جوابسريجدا', 'hint' => 'تلميحسريجدا']);
    e28_draft($creator, 'text', ['title' => 'لغز مسودة خفي', 'answer' => 'مسودةجواب']);

    $suggest = $this->getJson(route('search.suggest', ['q' => 'لغز']))->assertOk()->getContent();
    $page = $this->get(route('search.index', ['q' => 'لغز']))->assertOk()->getContent();
    $discover = $this->get(route('discover.index'))->assertOk()->getContent();
    $work = CreatorPuzzle::query()->firstOrFail();

    foreach ([$suggest, $page, $discover] as $body) {
        e28_assert_no_secrets($body, ['جوابسريجدا', 'تلميحسريجدا', 'مسودةجواب', 'secret-maker@example.test']);
        expect(str_contains($body, 'لغز مسودة خفي'))->toBeFalse();
    }

    // البحث بالجواب أو التلميح لا يجد شيئًا (البحث بعنوان المنشور فقط)
    expect(e27_titles(e27_suggest($this, 'جوابسريجدا')))->toBe([])
        ->and(e27_titles(e27_suggest($this, 'تلميحسريجدا')))->toBe([])
        ->and(json_decode($suggest, true)['groups'][0]['items'][0])->not->toHaveKeys(['id', 'creator_id', 'user_id', 'email', 'answer'])
        ->and($suggest)->toContain($work->public_id);
});
`````

### `tests/Feature/CreatorE28/E28SecurityTest.php`

`sha256: a2a81d0bbcc29638f1b14c64b1c7afd01a791e88ae8dca11764d2721276997ad`

`````php
<?php

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Creators\CreatorMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

/*
| E28-Y9 - الأمان: IDOR للمسودات/التعديل/الوسائط (404 بلا أثر)، صلاحية المراجعة، CSRF، حدود المعدل، وعدم تسريب الجواب/الحل.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');
});

test('Y9-73 (73): draft IDOR - another creator, a player and a guest cannot reach a draft by any studio or public route', function () {
    $owner = e28_creator();
    $stranger = e28_creator();
    $work = e28_draft($owner, 'text', ['title' => 'مسودة المالك السرية', 'answer' => 'جوابمسودةسري'])->work;
    $id = $work->public_id;

    foreach ([$stranger, e16User()] as $intruder) {
        $this->actingAs($intruder);

        foreach ([
            ['get', route('creator.puzzles.edit', $id)],
            ['get', route('creator.puzzles.preview', $id)],
            ['post', route('creator.puzzles.preview.check', $id)],
            ['post', route('creator.puzzles.submit', $id)],
            ['post', route('creator.puzzles.withdraw', $id)],
            ['post', route('creator.puzzles.archive', $id)],
            ['get', route('community.puzzles.show', $id)],
        ] as [$method, $url]) {
            $status = $this->{$method}($url)->getStatusCode();
            expect($status)->toBeIn([403, 404, 302], "{$method} {$url} -> {$status}");   // 302 = تحويل لطلب الانضمام لمن ليس مبدعًا
            expect($status)->not->toBe(200);
        }

        auth()->logout();
    }

    $this->get(route('creator.puzzles.edit', $id))->assertRedirect(route('login'));
    expect($work->latestRevision()->first()->status)->toBe(CreatorPuzzleRevision::STATUS_DRAFT);
});

test('Y9-74 (74): edit IDOR - update/delete/revise of another creator\'s work changes nothing; ids are opaque ULIDs, not sequential', function () {
    $owner = e28_creator();
    $stranger = e28_creator();
    $work = e28_published($owner, 'text', ['title' => 'منشورة المالك']);
    $draft = e28_draft($owner, 'text', ['title' => 'مسودة المالك'])->work;

    $this->actingAs($stranger);
    $this->put(route('creator.puzzles.update', $draft->public_id), e28_data('text', ['title' => 'اختراق ناجح']))->assertNotFound();
    $this->delete(route('creator.puzzles.destroy', $draft->public_id))->assertNotFound();
    $this->post(route('creator.puzzles.revise', $work->public_id))->assertNotFound();
    $this->post(route('creator.puzzles.unarchive', $work->public_id))->assertNotFound();

    // معرّف رقمي تسلسلي لا يحلّ أي عمل (المسار whereUlid)
    $this->get('/creator/puzzles/'.$draft->id.'/edit')->assertNotFound();
    $this->get('/community/puzzles/'.$work->id)->assertNotFound();

    expect($draft->fresh()->latestRevision()->first()->title)->toBe('مسودة المالك')
        ->and(CreatorPuzzle::query()->count())->toBe(2)
        ->and(CreatorPuzzleRevision::query()->count())->toBe(2)
        ->and($work->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

test('Y9-75 (75): media IDOR - another creator cannot delete, replace or reference someone else\'s image', function () {
    $owner = e28_creator();
    $stranger = e28_creator();

    $this->actingAs($owner)->post(route('creator.puzzles.store'), e28_data('image', ['image' => UploadedFile::fake()->image('a.png', 200, 200)]))->assertSessionHasNoErrors();
    $revision = CreatorPuzzleRevision::query()->firstOrFail();
    $path = $revision->image_path;
    Storage::disk('public')->assertExists($path);
    auth()->logout();

    // المسار لا يُحلّ إلا لمالكه (ومنه لا حذف ولا نسخ لغيره)، وبنية .. و\0 مرفوضة
    $media = app(CreatorMediaService::class);
    $strangerProfile = $stranger->creatorProfile()->first();
    $ownerProfile = $owner->creatorProfile()->first();

    expect($media->ownedBy($path, $ownerProfile))->toBeTrue()
        ->and($media->ownedBy($path, $strangerProfile))->toBeFalse()
        ->and($media->ownedBy('community/puzzles/'.$ownerProfile->id.'/../'.$strangerProfile->id.'/x.png', $ownerProfile))->toBeFalse()
        ->and($media->ownedBy("community/puzzles/{$ownerProfile->id}/x\0.png", $ownerProfile))->toBeFalse()
        ->and($media->ownedBy('../.env', $ownerProfile))->toBeFalse()
        ->and($media->ownedBy(null, $ownerProfile))->toBeFalse()
        ->and($media->copy($path, $strangerProfile))->toBeNull();

    $media->delete($path, $strangerProfile);
    Storage::disk('public')->assertExists($path);                                                           // حذف الغريب تجاهَل الملف

    $work = $revision->work;
    $this->actingAs($stranger)->delete(route('creator.puzzles.image.destroy', $work->public_id))->assertNotFound();
    $this->put(route('creator.puzzles.update', $work->public_id), e28_data('image', ['image' => UploadedFile::fake()->image('b.png', 200, 200)]))->assertNotFound();
    Storage::disk('public')->assertExists($path);

    // المبدع نفسه لا يستطيع نسبة ملف غيره لمسودته: image_path حقل ممنوع (422)
    $mine = e28_draft($stranger, 'text', ['title' => 'مسودتي'])->work;
    $this->put(route('creator.puzzles.update', $mine->public_id), e28_data('text', ['image_path' => $path]))->assertSessionHasErrors('image_path');
    expect($mine->latestRevision()->first()->image_path)->toBeNull();
});

test('Y9-76 (76): the reviewer preview requires creators.review - guests redirect; players, creators and view-only users get 403; drafts stay 404', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'للمراجعة فقط']);
    $draft = e28_draft($creator, 'text', ['title' => 'مسودة لا تُراجع']);
    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $moderator = e28_reviewer('moderator');

    $show = route('community.review.preview', $revision);
    $check = route('community.review.check', $revision);

    $this->get($show)->assertRedirect(route('login'));
    $this->post($check, ['answer' => 'x'])->assertRedirect(route('login'));

    foreach ([e16User(), $creator, $viewOnly] as $user) {
        $this->actingAs($user);
        $this->get($show)->assertForbidden();
        $this->post($check, ['answer' => 'x'])->assertForbidden();
        auth()->logout();
    }

    $this->actingAs($moderator)->get($show)->assertOk()->assertSee('لوحة الحل');
    $this->get(route('community.review.preview', $draft))->assertNotFound();
    $this->post(route('community.review.check', $draft), ['answer' => 'x'])->assertNotFound();
});

test('Y9-77 (77): CSRF protects every mutating creator / community route (419 without a token; all inside the web group)', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text');
    $draft = e28_draft($creator, 'text')->work;
    $player = e16User();

    // (1) مع تفعيل فحص CSRF (البيئة غير testing) يُرفض كل تعديل بلا رمز ولا يتغير شيء
    $before = [CreatorPuzzle::query()->count(), CreatorPuzzleRevision::query()->count(), \App\Models\CommunityPuzzleReport::query()->count()];
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($creator);
        $this->post(route('creator.puzzles.store'), e28_data('text'))->assertStatus(419);
        $this->put(route('creator.puzzles.update', $draft->public_id), e28_data('text', ['title' => 'تعديل بلا رمز']))->assertStatus(419);
        $this->post(route('creator.puzzles.submit', $draft->public_id))->assertStatus(419);
        $this->delete(route('creator.puzzles.destroy', $draft->public_id))->assertStatus(419);
        $this->post(route('creator.puzzles.revise', $work->public_id))->assertStatus(419);
        $this->post(route('creator.puzzles.archive', $work->public_id))->assertStatus(419);
        $this->post(route('creator.apply.store'), ['bio' => str_repeat('ب', 40)])->assertStatus(419);

        $this->actingAs($player);
        $this->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'x'])->assertStatus(419);
        $this->post(route('community.puzzles.hint', $work->public_id))->assertStatus(419);
        $this->post(route('community.puzzles.report', $work->public_id), ['reason' => 'spam'])->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect([CreatorPuzzle::query()->count(), CreatorPuzzleRevision::query()->count(), \App\Models\CommunityPuzzleReport::query()->count()])->toBe($before)
        ->and($draft->fresh()->latestRevision()->first()->title)->not->toBe('تعديل بلا رمز');

    // (2) ثابت: لا مسار تعديل creator.* / community.* خارج مجموعة web (الجلسة + CSRF)
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_contains((string) $route->getActionName(), 'Controllers\\Creators\\')) {   // مسارات E28 وحدها (وليس community.chat.* مثلًا)
            continue;
        }

        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $checked++;
        expect(in_array('web', $route->gatherMiddleware(), true))->toBeTrue("route {$name} is outside the web group");
    }

    expect($checked)->toBeGreaterThanOrEqual(15);
});

test('Y9-78 (78): rate limits bite - apply, draft create, submit and report', function () {
    // طلب الانضمام: 2/دقيقة
    $applicant = e16User();
    $statuses = e26_hammer($this, 'post', route('creator.apply.store'), 4, fn () => ['bio' => 'سيرة مبدع طويلة بما يكفي لتجاوز الحد الأدنى للأحرف.'], false);
    expect(e26_first_429($statuses))->toBeNull();                                // غير مسجَّل: لا يصل للمحدِّد (تحويل لتسجيل الدخول)

    $this->actingAs($applicant);
    $statuses = e26_hammer($this, 'post', route('creator.apply.store'), 4, ['bio' => 'سيرة مبدع طويلة بما يكفي لتجاوز الحد الأدنى للأحرف.']);
    expect(e26_first_429($statuses))->toBe(3);
    auth()->logout();

    // إنشاء المسودات: 5/دقيقة
    $creator = e28_creator();
    $this->actingAs($creator);
    $statuses = e26_hammer($this, 'post', route('creator.puzzles.store'), 7, ['title' => 'x']);              // تحقق فاشل يستهلك المحاولة أيضًا
    expect(e26_first_429($statuses))->toBe(6);

    // الإرسال للمراجعة: 5/دقيقة (على عمل بلا نسخة صالحة = رفض لطيف، لكن المحدِّد يعدّ كل طلب)
    $draft = e28_draft(e28_creator($other = e16User()), 'text')->work;
    auth()->logout();
    $this->actingAs($other);
    $statuses = e26_hammer($this, 'post', route('creator.puzzles.withdraw', $draft->public_id), 7);
    expect(e26_first_429($statuses))->toBe(6);
    auth()->logout();

    // البلاغات: 5/دقيقة
    $work = e28_published(e28_creator(), 'text');
    $this->actingAs(e16User());
    $statuses = e26_hammer($this, 'post', route('community.puzzles.report', $work->public_id), 7, ['reason' => 'spam']);
    expect(e26_first_429($statuses))->toBe(6);
});

test('Y9-79 (79): the answer and the solution never leak - public page, wrong attempts, exhausted state, owner preview, API and web puzzle routes', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز بلا تسريب', 'answer' => 'سرجوهرةفريد', 'hint' => 'تلميحمحجوب']);
    $canonical = Puzzle::query()->findOrFail($work->published_puzzle_id);
    $player = e16User();
    $secrets = ['سرجوهرةفريد'];

    // الصفحة العامة (ضيف ولاعب): لا جواب ولا تلميح قبل طلبه
    $guest = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    e28_assert_no_secrets($guest, $secrets);
    expect(str_contains($guest, 'تلميحمحجوب'))->toBeFalse();

    // محاولات خاطئة حتى الاستنفاد: لا جواب في أي استجابة
    $this->actingAs($player);
    $max = (int) config('creators.puzzle.max_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'خطأ'.$i])->assertRedirect();
        $page = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
        e28_assert_no_secrets($page, $secrets);
    }

    $this->get(route('community.puzzles.show', $work->public_id))->assertSee('استنفدت');

    // المعاينة: صاحب العمل لا يرى لوحة الحل (للمراجع فقط)
    auth()->logout();
    $owner = $this->actingAs($creator)->get(route('creator.puzzles.preview', $work->public_id))->assertOk()->getContent();
    expect(str_contains($owner, 'لوحة الحل'))->toBeFalse();
    e28_assert_no_secrets($owner, $secrets);
    auth()->logout();

    // المراجع يراها (لقطة نسخة مرسلة)
    $revision = e28_pending($creator, 'text', ['title' => 'للمراجع', 'answer' => 'جوابالمراجع']);
    $this->actingAs(e28_reviewer())->get(route('community.review.preview', $revision))->assertOk()->assertSee('لوحة الحل')->assertSee('جوابالمراجع');
    auth()->logout();

    // الأحجية القانونية لا تُكشف عبر مسارات الأحجيات الرسمية (ويب وAPI) ولا عبر المعرّف الرقمي
    $this->get(route('puzzles.show', $canonical))->assertNotFound();
    $this->actingAs($player);
    $this->getJson('/api/puzzles/'.$canonical->id)->assertNotFound();
    $list = $this->getJson('/api/puzzles')->assertOk()->getContent();
    expect(str_contains($list, 'لغز بلا تسريب'))->toBeFalse();
    expect(str_contains($list, 'سرجوهرةفريد'))->toBeFalse();
});
`````

### `tests/Feature/CreatorE28/E28StudioTest.php`

`sha256: 9f023157b5d4d2809526fca4d0ecebf1ca54eae6c936a3af1398e5229f7f80bb`

`````php
<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');
});

function e28_png(string $name = 'p.png', int $w = 200, int $h = 200): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

test('Y2-10 (10): an approved creator opens the studio, the list and the create page', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->get(route('creator.dashboard'))->assertOk()->assertSee('استوديو المبدع');
    $this->get(route('creator.puzzles.index'))->assertOk();
    $this->get(route('creator.puzzles.create'))->assertOk();
    $this->get(route('creator.puzzles.create', ['kind' => 'sequence']))->assertOk();
});

test('Y2-11 (11): the creator creates a draft - stored encrypted, in draft status, owned by the creator', function () {
    $creator = e28_creator();
    $payload = e28_data('text', ['title' => 'مسودتي الأولى']);

    $response = $this->actingAs($creator)->post(route('creator.puzzles.store'), $payload);
    $work = CreatorPuzzle::query()->firstOrFail();
    $response->assertRedirect(route('creator.puzzles.edit', $work->public_id));

    $revision = $work->latestRevision;
    expect($revision->status)->toBe('draft')->and($revision->kind)->toBe('text')->and($revision->title)->toBe('مسودتي الأولى')
        ->and($work->creator->user_id)->toBe($creator->id)->and($work->is_visible)->toBeFalse()->and($work->published_puzzle_id)->toBeNull();

    // الجواب الخام لا يُخزَّن نصًا صريحًا بقاعدة البيانات (حقل مشفَّر).
    expect(\Illuminate\Support\Facades\DB::table('creator_puzzle_revisions')->value('content'))->not->toContain('القلم');

    $this->get(route('creator.puzzles.edit', $work->public_id))->assertOk()->assertSee('مسودتي الأولى');
});

test('Y2-12 (12): a non-creator (player, applicant, guest) is denied the studio', function () {
    $this->get(route('creator.dashboard'))->assertRedirect(route('login'));

    $player = e16User();
    $this->actingAs($player)->get(route('creator.dashboard'))->assertRedirect(route('creator.apply'));
    $this->actingAs($player)->post(route('creator.puzzles.store'), e28_data())->assertRedirect(route('creator.apply'));

    app(\App\Services\Creators\CreatorApplicationService::class)->apply($player, 'متقدّم ينتظر قرار المراجعة على طلبه الأول.');
    $this->actingAs($player->fresh())->get(route('creator.puzzles.index'))->assertRedirect(route('creator.apply'));

    expect(CreatorPuzzle::query()->count())->toBe(0);
});

test('Y2-13 (13): a creator cannot read, edit, submit or delete another creator\'s work (404, nothing changes)', function () {
    $owner = e28_creator();
    $intruder = e28_creator();
    $work = e28_draft($owner, 'text', ['title' => 'خاصة بالمالك'])->work;
    $before = $work->latestRevision()->first()->only(['title', 'status']);

    $this->actingAs($intruder);
    $this->get(route('creator.puzzles.edit', $work->public_id))->assertNotFound();
    $this->put(route('creator.puzzles.update', $work->public_id), e28_data('text', ['title' => 'اختراق']))->assertNotFound();
    $this->post(route('creator.puzzles.submit', $work->public_id))->assertNotFound();
    $this->delete(route('creator.puzzles.destroy', $work->public_id))->assertNotFound();
    $this->get(route('creator.puzzles.preview', $work->public_id))->assertNotFound();

    expect($work->latestRevision()->first()->only(['title', 'status']))->toBe($before)->and(CreatorPuzzle::query()->count())->toBe(1);
});

test('Y2-14 (14): every supported game type is accepted by the studio', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    foreach (['text', 'multiple_choice', 'sequence', 'memory'] as $kind) {
        $this->post(route('creator.puzzles.store'), e28_data($kind))->assertSessionHasNoErrors()->assertRedirect();
    }

    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => e28_png()]))->assertSessionHasNoErrors();

    expect(CreatorPuzzleRevision::query()->orderBy('id')->pluck('kind')->all())->toBe(['text', 'multiple_choice', 'sequence', 'memory', 'image']);
});

test('Y2-15 (15): an unsupported game type is rejected (spot_difference and unknown types)', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    foreach (['spot_difference', 'quiz', 'rm -rf', ''] as $kind) {
        $this->post(route('creator.puzzles.store'), e28_data('text', ['kind' => $kind]))->assertSessionHasErrors('kind');
    }

    expect(CreatorPuzzle::query()->count())->toBe(0);
});

test('Y2-16 (16): an arbitrary renderer is rejected (present in the request, even empty)', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['renderer' => 'games.spot-difference']))->assertSessionHasErrors('renderer');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['renderer' => '']))->assertSessionHasErrors('renderer');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['game_type' => 'spot_difference']))->assertSessionHasErrors('game_type');

    expect(CreatorPuzzle::query()->count())->toBe(0);
});

test('Y2-17 (17): an arbitrary validation_type / score_mode is rejected, and the engine derives them server-side', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['validation_type' => 'always_true']))->assertSessionHasErrors('validation_type');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['score_mode' => 'flat']))->assertSessionHasErrors('score_mode');

    $work = e28_published($creator, 'sequence');
    $puzzle = $work->publishedPuzzle;
    $definition = app(\App\GameEngine\GameTypeRegistry::class)->definitionFor('sequence');
    expect($puzzle->renderer)->toBe($definition->renderer())->and($puzzle->validation_type)->toBe($definition->validationType())->and($puzzle->score_mode)->toBe($definition->scoreMode());
});

test('Y2-18 (18): raw HTML / script input is refused at entry and always rendered escaped', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['title' => '<script>alert(1)</script>']))->assertSessionHasErrors('title');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['prompt' => 'سؤال <img src=x onerror=alert(1)> طويل بما يكفي']))->assertSessionHasErrors('prompt');
    $this->post(route('creator.puzzles.store'), e28_data('multiple_choice', ['choices' => ['<b>x</b>', 'y', 'z'], 'answer' => 'y']))->assertSessionHasErrors();

    expect(CreatorPuzzle::query()->count())->toBe(0);

    // نص يحوي رموزًا رياضية آمنة يُعرض مهرَّبًا (لا تفسير HTML) في صفحة التحرير.
    $this->post(route('creator.puzzles.store'), e28_data('text', ['title' => 'ما قيمة 3 > 2 و 1 < 2 ؟', 'prompt' => 'أيّهما أكبر: 3 > 2 أم 2 > 3 في المقارنة العددية؟']))->assertSessionHasNoErrors();
    $work = CreatorPuzzle::query()->firstOrFail();
    $html = $this->get(route('creator.puzzles.edit', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('3 &gt; 2');
});

test('Y2-19 (19): a valid image uploads to the owner directory under a server-generated name', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => e28_png('صورة.png')]))->assertSessionHasNoErrors();

    $revision = CreatorPuzzleRevision::query()->firstOrFail();
    $profileId = $creator->creatorProfile()->value('id');

    expect($revision->image_path)->toStartWith("community/puzzles/{$profileId}/")->and($revision->image_path)->toMatch('/\.png$/')->and($revision->image_path)->not->toContain('صورة');
    Storage::disk('public')->assertExists($revision->image_path);
});

test('Y2-20 (20): SVG (and script-bearing disguised files) are rejected', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $svg]))->assertSessionHasErrors('image');

    $disguised = UploadedFile::fake()->createWithContent('x.png', '<?php echo 1; ?>');
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $disguised]))->assertSessionHasErrors('image');

    expect(CreatorPuzzle::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('Y2-21 (21): oversized or out-of-range images are rejected', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $big = UploadedFile::fake()->image('big.jpg', 300, 300)->size((int) config('creators.media.max_kb') + 10);
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $big]))->assertSessionHasErrors('image');

    $tiny = e28_png('t.png', 20, 20);
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $tiny]))->assertSessionHasErrors('image');

    expect(CreatorPuzzle::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('Y2-22 (22): a path-traversal file name is neutralized - the stored path never contains the client name', function () {
    $creator = e28_creator();
    $evil = UploadedFile::fake()->image('../../../evil.png', 200, 200);

    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => $evil]))->assertSessionHasNoErrors();

    $path = CreatorPuzzleRevision::query()->firstOrFail()->image_path;
    $profileId = $creator->creatorProfile()->value('id');

    expect($path)->toStartWith("community/puzzles/{$profileId}/")->and($path)->not->toContain('..')->and($path)->not->toContain('evil')
        ->and($path)->toMatch('#^community/puzzles/\d+/[0-9a-f-]{36}\.png$#');
    expect(array_filter(Storage::disk('public')->allFiles(), fn ($f) => ! str_starts_with($f, 'community/puzzles/')))->toBe([]);
});
`````

### `tests/Feature/CreatorE28/E28VersioningTest.php`

`sha256: 14ef39109885ef08b10cdf5c2ebf43f52667d990727e2a167a3070edd5088960`

`````php
<?php

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

/** عمل منشور بنسخة جديدة معتمَدة فوقه. @return array{0: User, 1: CreatorPuzzle, 2: CreatorPuzzleRevision, 3: CreatorPuzzleRevision} */
function e28_revised(array $v1 = [], array $v2 = []): array
{
    $creator = e28_creator();
    $work = e28_published($creator, 'text', $v1 + ['title' => 'العنوان القديم', 'answer' => 'قديم']);
    $first = $work->publishedRevision ?? CreatorPuzzleRevision::query()->findOrFail($work->published_revision_id);

    $svc = app(CreatorPuzzleService::class);
    $second = $svc->revise($creator, $work->public_id);
    $svc->updateDraft($creator, $work->public_id, e28_data('text', $v2 + ['title' => 'العنوان الجديد', 'answer' => 'جديد']));
    $svc->submit($creator, $work->public_id);

    return [$creator, $work, $first, $second->fresh()];
}

test('Y5-43 (43): published content cannot be mutated directly - creator route, service, revision model and canonical puzzle all refuse', function () {
    $creator = e28_creator();
    $work = e28_published($creator);
    $publicId = $work->public_id;
    $revision = CreatorPuzzleRevision::query()->findOrFail($work->published_revision_id);

    expect(fn () => app(CreatorPuzzleService::class)->updateDraft($creator, $publicId, e28_data('text', ['title' => 'تعديل مباشر'])))->toThrow(CreatorException::class);
    $this->actingAs($creator)->put(route('creator.puzzles.update', $publicId), e28_data('text', ['title' => 'تعديل مباشر']))->assertSessionHas('error');

    expect(function () use ($revision) {
        $revision->forceFill(['title' => 'تعديل مباشر'])->save();
    })->toThrow(\LogicException::class);
    expect(function () use ($work) {
        $work->publishedPuzzle->forceFill(['title' => 'تعديل مباشر'])->save();
    })->toThrow(\LogicException::class);

    expect($revision->fresh()->title)->not->toBe('تعديل مباشر')->and($work->fresh()->published_title)->not->toBe('تعديل مباشر');
});

test('Y5-44 (44): editing a published work creates a new revision while the published one stays live', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'المنشورة']);
    $publishedRevisionId = $work->published_revision_id;

    $this->actingAs($creator)->post(route('creator.puzzles.revise', $work->public_id))->assertSessionHas('success');

    $work->refresh();
    $new = CreatorPuzzleRevision::query()->findOrFail($work->latest_revision_id);
    expect($new->revision_number)->toBe(2)->and($new->status)->toBe('draft')->and($new->id)->not->toBe($publishedRevisionId)
        ->and($work->published_revision_id)->toBe($publishedRevisionId)->and($work->is_visible)->toBeTrue()
        ->and(CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->count())->toBe(2);

    // لا نسختان مفتوحتان معًا.
    expect(fn () => app(CreatorPuzzleService::class)->revise($creator, $work->public_id))->toThrow(CreatorException::class);
});

test('Y5-45 (45): the old version stays intact and public while a new revision is edited or pending', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'العنوان القديم', 'prompt' => 'سؤال النسخة القديمة الأصلي هنا']);
    $oldRevision = CreatorPuzzleRevision::query()->findOrFail($work->published_revision_id);
    $oldPuzzleId = $work->published_puzzle_id;
    $snapshot = $oldRevision->only(['title', 'status', 'puzzle_id', 'kind']);
    $svc = app(CreatorPuzzleService::class);

    $svc->revise($creator, $work->public_id);
    $svc->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'العنوان الجديد', 'prompt' => 'سؤال النسخة الجديدة المختلف هنا']));
    $svc->submit($creator, $work->public_id);

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('العنوان القديم')->and($html)->toContain('سؤال النسخة القديمة الأصلي هنا')->and($html)->not->toContain('سؤال النسخة الجديدة المختلف هنا');
    expect($oldRevision->fresh()->only(['title', 'status', 'puzzle_id', 'kind']))->toBe($snapshot)->and($work->fresh()->published_puzzle_id)->toBe($oldPuzzleId);
});

test('Y5-46 (46): historical attempts stay attached to the old version after a new version is published', function () {
    [$creator, $work, $first, $second] = e28_revised();
    $player = e16User();
    $oldPuzzle = Puzzle::query()->findOrFail($first->puzzle_id);

    // اللاعب يحاول النسخة الحالية (القديمة) قبل اعتماد الجديدة.
    app(CommunityPlayService::class)->attempt($player, $work->fresh(), 'خطأ');
    $attempt = CommunityPuzzleAttempt::query()->where('user_id', $player->id)->firstOrFail();
    expect($attempt->puzzle_id)->toBe($oldPuzzle->id);

    app(CreatorReviewService::class)->approve($second, e28_reviewer());

    $attempt->refresh();
    expect($attempt->puzzle_id)->toBe($oldPuzzle->id)->and($attempt->is_correct)->toBeFalse()->and($attempt->attempt_number)->toBe(1);

    // الحالة بالنسخة الجديدة نظيفة (محاولاتها مستقلة) والتاريخ باقٍ بالقديمة.
    $newPuzzle = $work->fresh()->publishedPuzzle;
    $state = app(CommunityPlayService::class)->state($player, $newPuzzle);
    expect($state['used'])->toBe(0)->and(app(CommunityPlayService::class)->state($player, $oldPuzzle)['used'])->toBe(1)
        ->and(CommunityPuzzleAttempt::query()->where('puzzle_id', $oldPuzzle->id)->count())->toBe(1);
});

test('Y5-47 (47): approving the new revision creates a new canonical version - a second community puzzle, never an overwrite', function () {
    [, $work, $first, $second] = e28_revised();
    $oldPuzzleSnapshot = Puzzle::query()->findOrFail($first->puzzle_id)->only(['title', 'prompt', 'answer_hash']);

    app(CreatorReviewService::class)->approve($second, e28_reviewer());

    $second->refresh();
    expect($second->puzzle_id)->not->toBeNull()->and($second->puzzle_id)->not->toBe($first->puzzle_id)
        ->and(Puzzle::query()->where('origin', 'community')->count())->toBe(2)
        ->and(CreatorPuzzleRevision::query()->where('creator_puzzle_id', $work->id)->whereNotNull('puzzle_id')->distinct()->count('puzzle_id'))->toBe(2)
        ->and(Puzzle::query()->findOrFail($first->puzzle_id)->only(['title', 'prompt', 'answer_hash']))->toBe($oldPuzzleSnapshot);
});

test('Y5-48 (48): the current publication moves to the new version - snapshot, public page and canonical id', function () {
    [, $work, $first, $second] = e28_revised();
    app(CreatorReviewService::class)->approve($second, e28_reviewer());

    $work->refresh();
    expect($work->published_revision_id)->toBe($second->id)->and($work->published_puzzle_id)->toBe($second->fresh()->puzzle_id)->and($work->published_title)->toBe('العنوان الجديد')->and($work->is_visible)->toBeTrue();

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('العنوان الجديد')->and($html)->not->toContain('العنوان القديم');

    // الجواب الجديد هو المعتمد للّاعبين من الآن.
    $player = e16User();
    $svc = app(CommunityPlayService::class);
    expect($svc->attempt($player, $work, 'قديم')['correct'])->toBeFalse()->and($svc->attempt($player, $work, 'جديد')['correct'])->toBeTrue();
});
`````

### `tests/Feature/CreatorE28/_support.php`

`sha256: 57c4fb226c1ca5872c3d3b6b87f2be86bf65540e00c4ab8d002dbdcfc8a170f3`

`````php
<?php

use App\Models\CampaignGateQualification;
use App\Models\CompetitiveEventParticipant;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\CurrencyTransaction;
use App\Models\FraudFlag;
use App\Models\GameSession;
use App\Models\GemTransaction;
use App\Models\PlayerProgression;
use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\UserCampaignProgress;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;

/*
| E28 - مساعدات مشتركة لاختبارات منظومة المبدعين. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| كل شيء محلي على SQLite وكاش array؛ لا شبكة. كل شيء يمرّ بالخدمات الرسمية (لا كتابة مباشرة بالحالات) كي تختبر الاختبارات المسار الحقيقي.
*/

require_once __DIR__.'/../DiscoveryE27/_support.php';

if (! function_exists('e28_category')) {
    function e28_category(array $attrs = []): PuzzleCategory
    {
        return PuzzleCategory::factory()->create($attrs + ['is_active' => true]);
    }

    /** مستخدم بصلاحية المراجعة (مشرف) أو دور آخر. يتطلب بذر RolesAndPermissionsSeeder. */
    function e28_reviewer(string $role = 'moderator'): User
    {
        $user = e16User();
        $user->assignRole($role);

        return $user;
    }

    /** مستخدم موثَّق بملف مبدع معتمَد (طلب ← اعتماد بالخدمة الرسمية). */
    function e28_creator(?User $user = null, ?User $reviewer = null): User
    {
        $user ??= e16User();
        $applications = app(CreatorApplicationService::class);
        $profile = $applications->apply($user, 'مبدع ألغاز يحب صناعة التحديات الذهنية للمجتمع.');
        $applications->approve($profile, $reviewer ?? e28_reviewer());

        return $user->refresh()->unsetRelation('creatorProfile');
    }

    /** حمولة مدخلات صالحة لنوع معيّن (يجوز تجاوز أي حقل). */
    function e28_data(string $kind = 'text', array $over = []): array
    {
        $category = PuzzleCategory::query()->where('is_active', true)->first() ?? e28_category();
        $base = ['kind' => $kind, 'title' => 'أحجية '.fake()->unique()->numerify('####'), 'puzzle_category_id' => $category->id, 'difficulty' => 'easy', 'prompt' => 'ما الشيء الذي يكتب ولا يقرأ أبدًا؟', 'hint' => 'تجده على المكتب'];

        $byKind = match ($kind) {
            'multiple_choice' => ['choices' => ['القلم', 'الكتاب', 'المسطرة'], 'answer' => 'القلم'],
            'sequence' => ['items' => ['الأول', 'الثاني', 'الثالث', 'الرابع']],
            'memory' => ['faces' => ['تفاح', 'موز', 'عنب', 'خوخ']],
            default => ['answer' => 'القلم'],
        };

        return $over + $byKind + $base;
    }

    function e28_draft(User $creator, string $kind = 'text', array $over = []): CreatorPuzzleRevision
    {
        return app(CreatorPuzzleService::class)->createDraft($creator, e28_data($kind, $over));
    }

    function e28_pending(User $creator, string $kind = 'text', array $over = []): CreatorPuzzleRevision
    {
        $revision = e28_draft($creator, $kind, $over);

        return app(CreatorPuzzleService::class)->submit($creator, $revision->work()->value('public_id'));
    }

    /** عمل منشور فعلًا (مسودة ← إرسال ← اعتماد). */
    function e28_published(User $creator, string $kind = 'text', array $over = [], ?User $reviewer = null): CreatorPuzzle
    {
        $revision = e28_pending($creator, $kind, $over);
        app(CreatorReviewService::class)->approve($revision, $reviewer ?? e28_reviewer());

        return $revision->work()->first();
    }

    /** لقطة حالة الاقتصاد والتقدّم كلها لمستخدم: أي تغيّر بعد لعب مجتمع = اختراق للعزل. */
    function e28_world(User $user): array
    {
        $models = [XpTransaction::class, GemTransaction::class, CurrencyTransaction::class, PuzzleAttempt::class, PlayerProgression::class, PlayerStreak::class,
            UserQuestProgress::class, UserAchievementProgress::class, CampaignGateQualification::class, UserCampaignProgress::class, CompetitiveEventParticipant::class, GameSession::class, FraudFlag::class];

        $counts = [];

        foreach ($models as $model) {
            $counts[class_basename($model)] = $model::query()->count();
        }

        return [
            'wallet' => e26_wallet_state($user),
            'counts' => $counts,
            'xp' => (int) PlayerProgression::query()->where('user_id', $user->id)->sum('total_xp'),
            'streak' => (int) PlayerStreak::query()->where('user_id', $user->id)->sum('current_streak'),
            'quest' => (int) UserQuestProgress::query()->where('user_id', $user->id)->sum('current_value'),
        ];
    }

    /** صفحات المبدع/المجتمع/المراجعة كلها: لا تسرّب جوابًا ولا حقول سلطة. */
    function e28_assert_no_secrets(string $html, array $secrets = []): void
    {
        foreach (array_merge($secrets, ['answer_hash', 'answer_raw', 'solution_data', 'game_config']) as $secret) {
            expect(str_contains($html, $secret))->toBeFalse('leaked: '.$secret);
        }
    }

    /** اسم مستخدم/بريد غير موجودين بصفحات عامة. */
    function e28_assert_private_absent(string $html, User $creator): void
    {
        expect(str_contains($html, $creator->email))->toBeFalse('email leaked')
            ->and(str_contains($html, 'password'))->toBeFalse();
    }
}
`````


---

## B) MODIFIED FILES — ملفات كاملة (استبدال) (26 ملفًا)

كل ملف أدناه **كامل** (ليس رقعة): انسخه فوق الموجود. التغييرات المحورية: Puzzle (origin + حراسة)، PuzzleAttemptService/GameSessionService/CompetitiveEligibility (رفض أحجيات المجتمع)، المتحكمات والمنتقيات (استبعاد المجتمع)، NotificationType (6 أنواع)، config/permissions + seeder (creators.*)، AppServiceProvider (4 محدِّدات)، UserAccountService (مصالحة الظهور عند التجميد)، NavigationMenu، config/search + DatabaseSearchBackend + DiscoveryService (دمج E27)، routes/web + routes/console، .env.example، وE27PerformanceTest (سقف suggest +1 ومصدر مجتمع).

### `.env.example`

`sha256: 108e27b71963e9664d4228c8df981a144decc1b4486a6579b711d1cdc726bb69`

`````ini
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
`````

### `app/Filament/Resources/CampaignGateResource/RelationManagers/StepsRelationManager.php`

`sha256: dd84699104885e0df37d72f0e266405b9801d81edb62f93aa70c9a562affed84`

`````php
<?php

namespace App\Filament\Resources\CampaignGateResource\RelationManagers;

use App\Models\CampaignStep;
use App\Models\Puzzle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * الطبقة الأدنى بالتسلسل - لا Resource خاص للخطوات. الحقول الشرطية حسب
 * kind/reward_mode عبر Get/live(). Media عامة (صورة/صوت) لكل الأنواع (تُستخدم
 * فعلياً بأصيل مثلاً لخطوات puzzle كأدلة صوتية، لا حصراً narrative) - Content
 * Status إداري بحت لا يُقرأ بمنطق الإكمال إطلاقاً. Puzzle.solution_data لا
 * تظهر هون إطلاقاً - فقط Select لأحجية موجودة مسبقاً.
 */
class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'الخطوات (Steps)';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('kind')
                ->label('نوع الخطوة')
                ->options([
                    CampaignStep::KIND_NARRATIVE => 'سردية (Narrative)',
                    CampaignStep::KIND_PUZZLE => 'أحجية (Puzzle)',
                    CampaignStep::KIND_REFLECTION => 'تأمّل (Reflection)',
                ])
                ->required()->live()->columnSpanFull(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required()->default(1),

            // ===== narrative فقط =====
            Forms\Components\Textarea::make('content.body')
                ->label('نص الخطوة')->rows(5)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_NARRATIVE)
                ->columnSpanFull(),

            // ===== reflection فقط =====
            Forms\Components\Textarea::make('content.intro')
                ->label('مقدّمة اختيارية قبل السؤال')
                ->rows(2)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('content.prompt')
                ->label('سؤال التأمّل')->rows(3)
                ->required(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('content.min_chars')
                ->label('الحد الأدنى لعدد الأحرف')->numeric()->minValue(1)->default(10)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION),
            Forms\Components\TextInput::make('content.max_chars')
                ->label('الحد الأقصى لعدد الأحرف')->numeric()->minValue(1)->default(2000)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION),

            // ===== Media عامة - لكل الأنواع، اختيارية دائماً =====
            Forms\Components\Section::make('وسائط مرافقة (اختياري)')
                ->description('صورة أو مقطع صوتي يظهر مع هذه الخطوة للاعب - اختياري تماماً.')
                ->schema([
                    Forms\Components\Select::make('content.media_type')
                        ->label('نوع الوسائط')
                        ->options(['image' => 'صورة', 'audio' => 'مقطع صوتي'])
                        ->placeholder('بدون وسائط')
                        ->native(false)
                        ->live(),

                    Forms\Components\FileUpload::make('content.media_path')
                        ->label('الملف')
                        ->disk('public')
                        ->directory('campaigns/media')
                        ->acceptedFileTypes(fn (Get $get) => $get('content.media_type') === 'audio'
                            ? ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a']
                            : ['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(8192)
                        ->visible(fn (Get $get) => filled($get('content.media_type'))),

                    Forms\Components\TextInput::make('content.caption')
                        ->label('تعليق توضيحي (اختياري)')
                        ->visible(fn (Get $get) => filled($get('content.media_type')))
                        ->columnSpanFull(),

                    // تحذير إداري فقط - لا يمنع الحفظ، فقط يُنبِّه إن أشار المسار
                    // لملف غير موجود فعلياً بالتخزين (مثلاً بعد نقل/حذف يدوي خارجي).
                    Forms\Components\Placeholder::make('media_missing_warning')
                        ->label('')
                        ->content('⚠️ هذا الملف غير موجود فعلياً بالتخزين - ارفع الملف من جديد.')
                        ->visible(function (Get $get) {
                            $path = $get('content.media_path');

                            return filled($path) && ! Storage::disk('public')->exists($path);
                        })
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsed()
                ->collapsible(),

            // ===== puzzle فقط - اختيار أحجية موجودة حصراً =====
            Forms\Components\Select::make('puzzle_id')
                ->label('الأحجية')
                ->options(fn () => Puzzle::query()->official()->where('is_active', true)->pluck('title', 'id'))
                ->searchable()
                ->required(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE)
                ->helperText('تعديل محتوى/حل الأحجية نفسها يتم من صفحة الأحجيات، لا هون.')
                ->columnSpanFull(),

            // ===== المكافأة - لخطوات الأحجية فقط =====
            Forms\Components\Select::make('reward_mode')
                ->label('سياسة المكافأة')
                ->options([
                    CampaignStep::REWARD_MODE_INHERIT => 'استخدام مكافأة الأحجية الطبيعية',
                    CampaignStep::REWARD_MODE_OVERRIDE => 'جائزة مخصَّصة لهذه الخطوة',
                    CampaignStep::REWARD_MODE_NONE => 'بلا جائزة',
                ])
                ->default(CampaignStep::REWARD_MODE_INHERIT)->required()->live()
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE),
            Forms\Components\TextInput::make('reward_override_amount')
                ->label('قيمة الجائزة المخصَّصة (جواهر)')->numeric()->minValue(0)
                ->required(fn (Get $get) => $get('reward_mode') === CampaignStep::REWARD_MODE_OVERRIDE)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE
                    && $get('reward_mode') === CampaignStep::REWARD_MODE_OVERRIDE),

            // ===== Content Status - إداري بحت، لا يُقرأ بمنطق اللعب =====
            Forms\Components\Select::make('content.status')
                ->label('حالة المحتوى')
                ->options([
                    CampaignStep::CONTENT_STATUS_FINAL => 'نهائي',
                    CampaignStep::CONTENT_STATUS_PLACEHOLDER => 'محتوى مؤقت (Placeholder)',
                    CampaignStep::CONTENT_STATUS_CONTENT_PENDING => 'بانتظار محتوى العميل',
                    CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING => 'بانتظار قرار تقني (يمنع النشر العام)',
                ])
                ->default(CampaignStep::CONTENT_STATUS_FINAL)
                ->native(false)
                ->helperText('يُعرض للإدارة فقط أبداً - لا يظهر لأي لاعب.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('العنوان'),
                Tables\Columns\TextColumn::make('kind')
                    ->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        CampaignStep::KIND_PUZZLE => 'أحجية',
                        CampaignStep::KIND_REFLECTION => 'تأمّل',
                        default => 'سردية',
                    }),
                Tables\Columns\TextColumn::make('puzzle.title')->label('الأحجية')->placeholder('—'),
                Tables\Columns\TextColumn::make('content_status_display')
                    ->label('حالة المحتوى')
                    ->state(fn (CampaignStep $record) => match ($record->contentStatus()) {
                        CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING => 'قرار تقني معلَّق',
                        CampaignStep::CONTENT_STATUS_CONTENT_PENDING => 'بانتظار محتوى',
                        CampaignStep::CONTENT_STATUS_PLACEHOLDER => 'مؤقت',
                        default => 'نهائي',
                    })
                    ->badge()
                    ->color(fn (CampaignStep $record) => match ($record->contentStatus()) {
                        CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING => 'danger',
                        CampaignStep::CONTENT_STATUS_CONTENT_PENDING, CampaignStep::CONTENT_STATUS_PLACEHOLDER => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
`````

### `app/Filament/Resources/ChallengeResource.php`

`sha256: d635eda31ccca1832eca0419103ea798a368d05e88bab5b4a697ac8a264162ba`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChallengeResource\Pages;
use App\Models\Challenge;
use App\Models\Puzzle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChallengeResource extends Resource
{
    protected static ?string $model = Challenge::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'التحديات والبطولات';

    protected static ?string $modelLabel = 'تحدي';

    protected static ?string $pluralModelLabel = 'التحديات والبطولات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required(),
            Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),
            Forms\Components\Select::make('type')->label('النوع')
                ->options(['weekly' => 'تحدي أسبوعي', 'tournament' => 'بطولة'])->required(),
            Forms\Components\DateTimePicker::make('starts_at')->label('يبدأ في')->required(),
            Forms\Components\DateTimePicker::make('ends_at')->label('ينتهي في')->required(),
            Forms\Components\TextInput::make('bonus_gem_pool')->label('مجموع جواهر المكافأة')->numeric()->default(0),
            Forms\Components\Select::make('puzzles')->label('الأحجيات المرتبطة')
                ->relationship('puzzles', 'title', modifyQueryUsing: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->official())->multiple()->preload()->searchable(),
            Forms\Components\Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('type')->label('النوع'),
                Tables\Columns\TextColumn::make('starts_at')->label('البداية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('ends_at')->label('النهاية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('participants_count')->label('المشاركون')->counts('participants'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChallenges::route('/'),
            'create' => Pages\CreateChallenge::route('/create'),
            'edit' => Pages\EditChallenge::route('/{record}/edit'),
        ];
    }
}
`````

### `app/Filament/Resources/PuzzleCategoryResource.php`

`sha256: b91537adff147668820d43e47abeebd451870de7834e9d570a1248ff23d8035b`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PuzzleCategoryResource\Pages;
use App\Models\PuzzleCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PuzzleCategoryResource extends Resource
{
    protected static ?string $model = PuzzleCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'التصنيفات';

    protected static ?string $modelLabel = 'تصنيف';

    protected static ?string $pluralModelLabel = 'التصنيفات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required(),
            Forms\Components\TextInput::make('slug')->label('المعرّف (slug)')->required()->unique(ignoreRecord: true),
            Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),
            Forms\Components\TextInput::make('icon')->label('أيقونة'),
            Forms\Components\TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('puzzles_count')->label('عدد الأحجيات')->counts('puzzles'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّل')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('الترتيب')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()->before(fn (Tables\Actions\DeleteAction $action, PuzzleCategory $record) => static::guardDelete($action, $record))]);
    }

    /** E28: حذف تصنيف يحوي أحجيات مجتمع منشورة يمحو سجل النسخ؛ نمنعه برسالة بدل خطأ قيد بقاعدة البيانات. */
    public static function guardDelete(\Filament\Actions\MountableAction $action, PuzzleCategory $record): void
    {
        if ($record->puzzles()->where('origin', \App\Models\Puzzle::ORIGIN_COMMUNITY)->exists()) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title('لا يمكن حذف هذا التصنيف')
                ->body('يحوي أحجيات مجتمع منشورة ضمن سجل المبدعين. عطّل التصنيف بدل حذفه.')
                ->send();

            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPuzzleCategories::route('/'),
            'create' => Pages\CreatePuzzleCategory::route('/create'),
            'edit' => Pages\EditPuzzleCategory::route('/{record}/edit'),
        ];
    }
}
`````

### `app/Filament/Resources/PuzzleCategoryResource/Pages/EditPuzzleCategory.php`

`sha256: 850928a777eb365cfb2bf6e95e3653597168c4ee22c39457a18faabdcae51695`

`````php
<?php

namespace App\Filament\Resources\PuzzleCategoryResource\Pages;

use App\Filament\Resources\PuzzleCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPuzzleCategory extends EditRecord
{
    protected static string $resource = PuzzleCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->before(fn (Actions\DeleteAction $action) => \App\Filament\Resources\PuzzleCategoryResource::guardDelete($action, $this->record))];
    }
}
`````

### `app/Filament/Resources/PuzzleResource.php`

`sha256: 0a8eac02d1e37fcf236b0e70848e6dc6191412f908db3167b8a8156280a6ddcd`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\GameTypeAuthoring\FilamentGameTypeSchemaRegistry;
use App\Filament\Resources\PuzzleResource\Pages;
use App\GameEngine\GameTypeRegistry;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PuzzleResource extends Resource
{
    protected static ?string $model = Puzzle::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'الأحجيات';

    protected static ?string $modelLabel = 'أحجية';

    protected static ?string $pluralModelLabel = 'الأحجيات';

    /** E28: أحجيات المجتمع (لقطات النسخ المعتمدة) لا تظهر ولا تُعدَّل من هنا؛ مراجعتها وإخفاؤها من مركز مراجعة المبدعين. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->official();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('puzzle_category_id')
                ->label('التصنيف')
                ->options(PuzzleCategory::pluck('name', 'id'))
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),

            Forms\Components\Select::make('type')
                ->label('نوع الأحجية (الأنواع الكلاسيكية)')
                ->options([
                    'text' => 'نصية',
                    'image' => 'صورة',
                    'multiple_choice' => 'اختيار من متعدد',
                ])
                ->live()
                ->required(),

            Forms\Components\Select::make('difficulty')
                ->label('مستوى الصعوبة')
                ->options(['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'])
                ->live()
                ->afterStateUpdated(fn (Get $get, $state, Forms\Set $set) => $set('gem_reward', config("gems.rewards.$state", 5)))
                ->required(),

            Forms\Components\Textarea::make('prompt')->label('نص السؤال')->required()->columnSpanFull(),

            Forms\Components\FileUpload::make('image_path')
                ->label('صورة الأحجية')
                ->image()
                ->disk('public')
                ->directory('puzzles')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(4096)
                ->visible(fn (Get $get) => $get('type') === 'image'),
                
            Forms\Components\Repeater::make('choices')
                ->label('الخيارات')
                ->simple(Forms\Components\TextInput::make('choice')->required())
                ->visible(fn (Get $get) => $get('type') === 'multiple_choice')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('answer_raw')
                ->label('الإجابة الصحيحة')
                ->helperText('لا تُخزَّن بشكل صريح - تُحوَّل تلقائياً إلى بصمة (hash) عند الحفظ.')
                ->required(fn (string $context) => $context === 'create')
                ->dehydrated(fn ($state) => filled($state)),

            Forms\Components\Textarea::make('hint')->label('التلميح (اختياري)')->columnSpanFull(),

            Forms\Components\TextInput::make('max_attempts')->label('عدد المحاولات المسموح')->numeric()->default(3)->required(),
            Forms\Components\TextInput::make('time_limit_seconds')->label('مؤقت زمني (ثانية، اختياري)')->numeric(),
            Forms\Components\TextInput::make('gem_reward')->label('مكافأة الجواهر')->numeric()->required(),

            Forms\Components\Toggle::make('is_daily_puzzle')->label('أحجية اليوم')->live(),
            Forms\Components\DatePicker::make('daily_puzzle_date')
                ->label('تاريخ أحجية اليوم')
                ->visible(fn (Get $get) => $get('is_daily_puzzle')),

            Forms\Components\Toggle::make('is_active')->label('مفعّلة')->default(true),

            Forms\Components\Section::make('محرك الألعاب التفاعلية (اختياري)')
                ->description('اتركه فارغاً لأحجية كلاسيكية عادية (النوع أعلاه). اختر نوع لعبة هون فقط لتفعيل تجربة تفاعلية جديدة.')
                ->schema([
                    Forms\Components\Select::make('game_type')
                        ->label('نوع اللعبة التفاعلية')
                        ->options(fn () => app(GameTypeRegistry::class)->gameTypeOptions())
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (Get $get, $state, Forms\Set $set) {
                            $defaults = app(GameTypeRegistry::class)->defaultsFor($state);
                            $set('validation_type', $defaults['validation_type'] ?? null);
                            $set('score_mode', $defaults['score_mode'] ?? 'flat');
                            $set('renderer', $defaults['renderer'] ?? null);
                        })
                        ->columnSpanFull(),

                    // حقول كل نوع لعبة تأتي هون ديناميكيًا من الـAuthoring Registry -
                    // إضافة نوع جديد لا تلمس هذا الملف إطلاقاً (شوف config/game_types.php).
                    ...app(FilamentGameTypeSchemaRegistry::class)->allFields(),

                    Forms\Components\TextInput::make('validation_type')->label('نوع التحقق (تلقائي)')->disabled()->dehydrated(),
                    Forms\Components\TextInput::make('score_mode')->label('نمط الاحتساب (تلقائي)')->disabled()->dehydrated(),
                    Forms\Components\TextInput::make('renderer')->label('العارض (تلقائي)')->disabled()->dehydrated(),
                ])
                ->columns(2)
                ->collapsible(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('التصنيف'),
                Tables\Columns\BadgeColumn::make('difficulty')
                    ->label('الصعوبة')
                    ->colors(['success' => 'easy', 'warning' => 'medium', 'danger' => 'hard']),
                Tables\Columns\TextColumn::make('game_type')
                    ->label('نوع اللعبة')
                    ->badge()
                    ->placeholder('كلاسيكي')
                    ->color(fn ($state) => $state ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('gem_reward')->label('الجواهر'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّلة')->boolean(),
                Tables\Columns\TextColumn::make('attempts_count')->label('عدد المحاولات')->counts('attempts'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('puzzle_category_id')
                    ->label('التصنيف')
                    ->options(PuzzleCategory::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->label('الصعوبة')
                    ->options(['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب']),
                Tables\Filters\SelectFilter::make('game_type')
                    ->label('نوع اللعبة')
                    ->options(fn () => app(GameTypeRegistry::class)->gameTypeOptions()),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPuzzles::route('/'),
            'create' => Pages\CreatePuzzle::route('/create'),
            'edit' => Pages\EditPuzzle::route('/{record}/edit'),
        ];
    }
}
`````

### `app/Http/Controllers/Api/PuzzleController.php`

`sha256: c3029cf4c687f19ea7578bfb41ef19d7e90c32c2617b7e0d5908f414c6314dac`

`````php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SolvePuzzleRequest;
use App\GameEngine\GameTypeRegistry;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Services\PuzzleAttemptService;
use Illuminate\Http\Request;

class PuzzleController extends Controller
{
    public function __construct(protected PuzzleAttemptService $attempts) {}

    public function categories()
    {
        return PuzzleCategory::where('is_active', true)->orderBy('sort_order')->get();
    }

    public function index(Request $request)
    {
        return Puzzle::query()
            ->where('is_active', true)
            ->when($request->category_id, fn ($q) => $q->where('puzzle_category_id', $request->category_id))
            ->select(['id', 'puzzle_category_id', 'title', 'type', 'game_type', 'difficulty', 'gem_reward'])
            ->paginate(20);
    }

    public function show(Puzzle $puzzle, GameTypeRegistry $games)
    {
        // E26: (1) غير المفعّلة 404 كالقائمة. (2) game_config الخام كان يسرّب ترتيب الإجابة الصحيح للترتيب (عناصر بالترتيب الصحيح) وأزواج بطاقات الذاكرة؛
        // نُعيد حمولة النوع العامة فقط (publicPayload: عناصر مخلوطة برموز معتمة، بلا solution_data/hotspots) ونحتفظ بالمفتاح game_config للتوافق.
        abort_unless($puzzle->is_active && ! $puzzle->isCommunity(), 404);

        return array_merge($puzzle->only([
            'id', 'title', 'type', 'difficulty', 'prompt', 'choices',
            'image_path', 'max_attempts', 'time_limit_seconds', 'gem_reward',
            'game_type', 'renderer',
        ]), [
            'game_config' => $this->publicGamePayload($puzzle, $games),
        ]);
    }

    /** حمولة النوع العامة؛ نوع غير مسجَّل (بيانات قديمة) = لا حمولة بدل خطأ 500. */
    protected function publicGamePayload(Puzzle $puzzle, GameTypeRegistry $games): ?array
    {
        try {
            return $games->definitionFor($puzzle->game_type)->publicPayload($puzzle);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function attempt(SolvePuzzleRequest $request, Puzzle $puzzle)
    {
        abort_if($puzzle->isCommunity(), 404);

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->attempts->attempt(
                $request->user(),
                $puzzle,
                (string) $request->input('answer', ''),
                $request->boolean('used_hint'),
                $submission
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
`````

### `app/Http/Controllers/PuzzleController.php`

`sha256: 43cf92a6dbd8b5e7f9a718804c0c31ce25f5e5bd475427c04c044100dfa18949`

`````php
<?php

namespace App\Http\Controllers;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Services\PuzzleAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PuzzleController extends Controller
{
    public function __construct(protected PuzzleAttemptService $attempts) {}

    public function index(PuzzleCategory $category = null)
    {
        $query = Puzzle::query()->where('is_active', true)->with('category');

        if ($category) {
            $query->where('puzzle_category_id', $category->id);
        }

        $puzzles = $query->latest()->paginate(12);
        $categories = PuzzleCategory::where('is_active', true)->orderBy('sort_order')->get();

        return view('puzzles.index', compact('puzzles', 'categories', 'category'));
    }

    public function show(Puzzle $puzzle, GameTypeRegistry $games)
    {
        $user = Auth::user();

        // E28: أحجيات المجتمع صفحتها /community/puzzles/{id} حصرًا؛ هذا المسار رسمي (جواهر/XP) ولا يعرضها لأحد حتى المسؤول.
        abort_if($puzzle->isCommunity(), 404);

        // E26: أحجية غير مفعّلة (مسودة/مسحوبة) لا تُعرض للعامة؛ المسؤول يعاينها. 404 لا 403 كي لا يُكشف وجودها.
        abort_unless($puzzle->is_active || ($user && $user->can('admin.access')), 404);

        $attemptsUsed = $user
            ? $puzzle->attempts()->where('user_id', $user->id)->count()
            : 0;

        $alreadySolved = $user && $user->hasSolvedPuzzle($puzzle);
        $renderer = $games->rendererFor($puzzle);
        $usesGameSession = $games->definitionFor($puzzle->game_type) instanceof GameSessionHandler;

        return view('puzzles.show', compact('puzzle', 'attemptsUsed', 'alreadySolved', 'renderer', 'usesGameSession'));
    }

    public function attempt(SolvePuzzleRequest $request, Puzzle $puzzle): RedirectResponse
    {
        abort_if($puzzle->isCommunity(), 404);

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->attempts->attempt(
                Auth::user(),
                $puzzle,
                (string) $request->input('answer', ''),
                $request->boolean('used_hint'),
                $submission
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['correct']) {
            return redirect()
                ->route('puzzles.show', $puzzle)
                ->with('success', "إجابة صحيحة! حصلت على {$result['gems_awarded']} جوهرة معلقة.");
        }

        $message = $result['attempts_left'] > 0
            ? "إجابة غير صحيحة. تبقى لك {$result['attempts_left']} محاولة."
            : 'إجابة غير صحيحة. لقد استنفدت محاولاتك لهذه الأحجية.';

        return back()->with('error', $message);
    }

    public function hint(Puzzle $puzzle): RedirectResponse
    {
        abort_if($puzzle->isCommunity(), 404);

        try {
            $hint = $this->attempts->purchaseHint(Auth::user(), $puzzle);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('hint', $hint);
    }
}
`````

### `app/Models/Puzzle.php`

`sha256: cdf01ad1a0b27b10f34fbf77a44d4fc40fbce5f5968c86d20e96be1dfe2af89b`

`````php
<?php

namespace App\Models;

use App\GameEngine\GameTypeRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Puzzle extends Model
{
    use HasFactory;

    /** E28: مصدر الأحجية حقيقة سيرفرية على الصف (لا من الرابط). 'origin' ليس بالـfillable: يُضبط عند الإنشاء فقط عبر forceFill من مُنشئ أحجيات المجتمع. */
    public const ORIGIN_OFFICIAL = 'official';

    public const ORIGIN_COMMUNITY = 'community';

     protected $fillable = [
        'puzzle_category_id', 'title', 'type', 'difficulty', 'prompt',
        'image_path', 'choices', 'answer_hash', 'answer_raw', 'hint', 'max_attempts',
        'time_limit_seconds', 'gem_reward', 'xp_reward', 'reward_currency_id', 'is_daily_puzzle',
        'daily_puzzle_date', 'is_active',
        'game_type', 'game_config', 'solution_data', 'renderer', 'validation_type', 'score_mode',
    ];

    protected $appends = [];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'game_config' => 'array',
            'solution_data' => 'array',
            'is_daily_puzzle' => 'boolean',
            'is_active' => 'boolean',
            'daily_puzzle_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Puzzle $puzzle) {
            // E28: أحجية المجتمع لقطة لنسخة معتمدة. لا تُعدَّل بعد إنشائها (سلامة المحاولات التاريخية) ولا يتبدّل مصدرها أبدًا،
            // وتُنشأ دائمًا بلا أي مكافأة/ربط رسمي/تفعيل رسمي مهما كان مصدر الكتابة (رفض افتراضي على مستوى النموذج).
            if ($puzzle->exists) {
                if ($puzzle->isDirty('origin')) {
                    throw new \LogicException('لا يمكن تغيير مصدر الأحجية.');
                }

                if ($puzzle->getOriginal('origin') === self::ORIGIN_COMMUNITY) {
                    throw new \LogicException('أحجية المجتمع المنشورة لقطة ثابتة ولا تُعدَّل؛ أنشئ نسخة جديدة بدلًا منها.');
                }
            } elseif ($puzzle->isCommunity()) {
                $puzzle->forceFill([
                    'is_active' => false,
                    'gem_reward' => 0,
                    'xp_reward' => 0,
                    'reward_currency_id' => null,
                    'is_daily_puzzle' => false,
                    'daily_puzzle_date' => null,
                    'time_limit_seconds' => null,
                ]);
            }

            app(GameTypeRegistry::class)->prepareForSave($puzzle);
        });

        static::deleting(function (Puzzle $puzzle) {
            if ($puzzle->isCommunity()) {
                throw new \LogicException('أحجية المجتمع جزء من سجل النسخ ولا تُحذف.');
            }
        });
    }

    public function isCommunity(): bool
    {
        return $this->getAttribute('origin') === self::ORIGIN_COMMUNITY;
    }

    /** الأحجيات الرسمية فقط (لوحة الإدارة والإحصاءات والمنتقيات). الصفوف القديمة رسمية بالافتراضي. */
    public function scopeOfficial($query)
    {
        return $query->where($query->getModel()->getTable().'.origin', self::ORIGIN_OFFICIAL);
    }

    public function setAnswerRawAttribute(?string $value): void
    {
        if (filled($value)) {
            $this->attributes['answer_hash'] = static::normalizeAndHash($value);
        }
    }

    public static function normalizeAndHash(string $answer): string
    {
        $normalized = trim(mb_strtolower($answer));
        $normalized = preg_replace('/[\x{064B}-\x{0652}]/u', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return hash('sha256', $normalized);
    }

    public function checkAnswer(string $submitted): bool
    {
        return hash_equals($this->answer_hash, static::normalizeAndHash($submitted));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'puzzle_category_id');
    }

    public function rewardCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reward_currency_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PuzzleAttempt::class);
    }

    public function challenges(): BelongsToMany
    {
        return $this->belongsToMany(Challenge::class, 'challenge_puzzle');
    }

    public function campaignSteps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }
}
`````

### `app/Models/User.php`

`sha256: 5f0cfe515417135a74fae115a339b3e53aae6abce4c7c0002d7863a2f075f9d0`

`````php
<?php

namespace App\Models;

use App\GameEngine\Support\AttemptContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail, FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_MEMBERS = 'members';
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_MEMBERS, self::VISIBILITY_PUBLIC];

    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_visibility',
    ];

    protected $attributes = [
        'profile_visibility' => self::VISIBILITY_PRIVATE,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->public_id ??= (string) \Illuminate\Support\Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_frozen' => 'boolean',
            'friend_requests_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('admin.access');
    }

    public function wallets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        $currencyId = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency()->id;

        return $this->hasOne(Wallet::class)->where('currency_id', $currencyId);
    }

    public function gemTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GemTransaction::class);
    }

    public function currencyTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CurrencyTransaction::class);
    }

    public function puzzleAttempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PuzzleAttempt::class);
    }

    public function redemptionRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RedemptionRequest::class);
    }

    public function fraudFlags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FraudFlag::class);
    }

    public function deviceSightings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeviceSighting::class);
    }

    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function hasSolvedPuzzle(Puzzle $puzzle, ?AttemptContext $context = null): bool
    {
        $context ??= AttemptContext::none();

        return $this->puzzleAttempts()
            ->where('puzzle_id', $puzzle->id)
            ->where('is_correct', true)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->exists();
    }

    public function campaignProgress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserCampaignProgress::class);
    }

    public function campaignQualifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignGateQualification::class);
    }

    public function createdCampaigns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Campaign::class, 'created_by');
    }

    public function frozenBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'frozen_by');
    }

    public function storePurchases(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StorePurchase::class);
    }

    public function inventoryItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserInventoryItem::class);
    }

    public function inventoryTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function entitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function cosmeticLoadouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserCosmeticLoadout::class);
    }

    public function isProfilePublic(): bool
    {
        return $this->profile_visibility === self::VISIBILITY_PUBLIC;
    }

    public function isProfileMembersOnly(): bool
    {
        return $this->profile_visibility === self::VISIBILITY_MEMBERS;
    }

    public function isProfilePrivate(): bool
    {
        return $this->profile_visibility === self::VISIBILITY_PRIVATE;
    }

    public function profileViewableBy(?User $viewer): bool
    {
        if ($viewer && $viewer->id === $this->id) {
            return true;
        }

        return match ($this->profile_visibility) {
            self::VISIBILITY_PUBLIC => true,
            self::VISIBILITY_MEMBERS => $viewer !== null,
            default => false,
        };
    }

    public function playerProgression(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PlayerProgression::class);
    }

    public function xpTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(XpTransaction::class);
    }

    public function levelUnlocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserLevelUnlock::class);
    }

    public function achievementProgress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserAchievementProgress::class);
    }

    /** E28: ملف المبدع (إن وُجد). */
    public function creatorProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CreatorProfile::class);
    }
}
`````

### `app/Providers/AppServiceProvider.php`

`sha256: 49e2078c699ebd94d53c59365996837077fbb40c14021361eec4ba38f501a4c0`

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

### `app/Services/Analytics/PuzzleAnalyticsService.php`

`sha256: 494b9a819932b739b046594c06a2bc0bbbf0a65c2fd30e918adfdc42fe2c705e`

`````php
<?php

namespace App\Services\Analytics;

use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PuzzleAnalyticsService
{
    public const MIN_SAMPLE_SIZE = 5;

    public function overview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('puzzles.overview'), now()->addMinutes(10), function () use ($period) {
            $attempts = PuzzleAttempt::whereBetween('created_at', [$period->start, $period->end]);
            $total = (clone $attempts)->count();
            $correct = (clone $attempts)->where('is_correct', true)->count();

            return [
                'total_puzzles' => Puzzle::official()->count(),
                'active_puzzles' => Puzzle::where('is_active', true)->count(),
                'total_attempts' => $total,
                'correct_attempts' => $correct,
                'incorrect_attempts' => $total - $correct,
                'success_rate' => $this->rate($correct, $total),
                'unique_players' => (clone $attempts)->distinct('user_id')->count('user_id'),
            ];
        });
    }

    protected function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    /**
     * إصلاح: HAVING على عمود withCount() المحسوب (subquery، لا GROUP BY
     * حقيقي) يفشل بـSQLite ("HAVING clause on a non-aggregate query").
     * عدد الأحجيات محدود دائماً (عشرات/مئات، لا ملايين مثل المحاولات) -
     * التصفية بالذاكرة هنا آمنة تماماً أداءً، بعكس التجميع على المحاولات
     * نفسها الذي يبقى بالكامل داخل قاعدة البيانات (بند 57/60).
     */
    public function topPuzzles(AnalyticsPeriod $period, int $limit = 10): array
    {
        $puzzles = Puzzle::query()
            ->withCount([
                'attempts as attempts_count' => fn ($q) => $q->whereBetween('created_at', [$period->start, $period->end]),
                'attempts as correct_count' => fn ($q) => $q->whereBetween('created_at', [$period->start, $period->end])->where('is_correct', true),
            ])
            ->get(['id', 'title']);

        $mostPlayed = $puzzles->sortByDesc('attempts_count')->take($limit)
            ->map(fn ($p) => ['title' => $p->title, 'attempts' => $p->attempts_count])->values();

        $eligible = $puzzles->filter(fn ($p) => $p->attempts_count >= self::MIN_SAMPLE_SIZE);

        $withRate = $eligible->map(fn ($p) => [
            'title' => $p->title,
            'attempts' => $p->attempts_count,
            'success_rate' => $this->rate($p->correct_count, $p->attempts_count),
        ]);

        return [
            'most_played' => $mostPlayed->all(),
            'highest_success_rate' => $withRate->sortByDesc('success_rate')->take($limit)->values()->all(),
            'lowest_success_rate' => $withRate->sortBy('success_rate')->take($limit)->values()->all(),
            'min_sample_size' => self::MIN_SAMPLE_SIZE,
        ];
    }

    public function difficultyBreakdown(AnalyticsPeriod $period): array
    {
        $rows = PuzzleAttempt::join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
            ->whereBetween('puzzle_attempts.created_at', [$period->start, $period->end])
            ->selectRaw('puzzles.difficulty, COUNT(*) as attempts, SUM(CASE WHEN puzzle_attempts.is_correct THEN 1 ELSE 0 END) as correct')
            ->groupBy('puzzles.difficulty')
            ->get();

        return $rows->map(fn ($r) => [
            'difficulty' => $r->difficulty ?? 'غير محدَّد',
            'attempts' => (int) $r->attempts,
            'success_rate' => $this->rate((int) $r->correct, (int) $r->attempts),
        ])->all();
    }

    public function gameTypeBreakdown(AnalyticsPeriod $period): array
    {
        $rows = PuzzleAttempt::join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
            ->whereBetween('puzzle_attempts.created_at', [$period->start, $period->end])
            ->selectRaw('COALESCE(puzzles.game_type, "classic") as game_type, COUNT(*) as attempts, COUNT(DISTINCT puzzle_attempts.user_id) as players, SUM(CASE WHEN puzzle_attempts.is_correct THEN 1 ELSE 0 END) as correct')
            ->groupBy('game_type')
            ->get();

        return $rows->map(fn ($r) => [
            'game_type' => $r->game_type,
            'attempts' => (int) $r->attempts,
            'players' => (int) $r->players,
            'success_rate' => $this->rate((int) $r->correct, (int) $r->attempts),
        ])->all();
    }

    public function challengeOverview(): array
    {
        return [
            'total_challenges' => Challenge::count(),
            'active_challenges' => Challenge::where('is_active', true)
                ->where('starts_at', '<=', now())->where('ends_at', '>=', now())->count(),
            'total_participants' => ChallengeParticipant::distinct('user_id')->count('user_id'),
        ];
    }
}
`````

### `app/Services/Competitive/CompetitiveEligibility.php`

`sha256: e8ea327687ebbcd2418612404e1f5c8a514c6a79f898d5f7f0b0436cc37c3f19`

`````php
<?php

namespace App\Services\Competitive;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\Models\Puzzle;

/**
 * أي أحجية تصلح هدفًا تنافسيًا؟ قاعدتان من التدقيق: (1) بلا تلميح: التلميح يُشترى بعملة فيصير ميزة مدفوعة (Pay-to-Win)؛ (2) إجابة منفردة
 * مُتحقَّق منها بالسيرفر (exact_string/memory_match/sequence_match). أنواع الجلسات التفاعلية (spot_difference) خارج النطاق: مسارها القياسي
 * يمنح مكافآت ويحتاج تصميمًا مستقلًا.
 */
class CompetitiveEligibility
{
    public const SUPPORTED_VALIDATION_TYPES = ['exact_string', 'memory_match', 'sequence_match'];

    public function __construct(protected GameTypeRegistry $games) {}

    /** @return string|null سبب الرفض بالعربية، أو null إن كانت صالحة */
    public function reasonIfIneligible(Puzzle $puzzle, bool $requireActive = true): ?string
    {
        // E28: أحجيات المجتمع بلا اقتصاد/منافسة رسمية (حتى حين يُسمح بغير المفعَّلة للجدولة).
        if ($puzzle->isCommunity()) {
            return 'أحجيات المجتمع لا تصلح للمنافسات.';
        }

        if ($requireActive && ! $puzzle->is_active) {
            return 'هذه الأحجية غير مفعَّلة.';
        }

        if (filled($puzzle->hint)) {
            return 'الأحجيات ذات التلميح لا تصلح للمنافسة (التلميح يُشترى بعملة).';
        }

        $definition = $this->games->definitionFor($puzzle->game_type);

        if ($definition instanceof GameSessionHandler || ! in_array($definition->validationType(), self::SUPPORTED_VALIDATION_TYPES, true)) {
            return 'نوع هذه الأحجية غير مدعوم في المنافسات حاليًا.';
        }

        return null;
    }

    /**
     * استعلام الأحجيات المؤهَّلة لتحدّيات الأصدقاء (مفعَّلة، بلا تلميح، إجابة منفردة): مرآة SQL لـreasonIfIneligible للبحث بالترقيم. المرجع
     * عند الإنشاء هو reasonIfIneligible دائمًا (اختبار يثبت اتساق الاثنين).
     */
    public function eligiblePuzzlesQuery(bool $requireActive = true)
    {
        return Puzzle::query()->official()->when($requireActive, fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('hint')->orWhere('hint', ''))
            ->where(fn ($q) => $q->whereNull('game_type')->orWhereIn('game_type', ['memory', 'sequence']));
    }

    public function isEligible(Puzzle $puzzle, bool $requireActive = true): bool
    {
        return $this->reasonIfIneligible($puzzle, $requireActive) === null;
    }
}
`````

### `app/Services/Discovery/DiscoveryService.php`

`sha256: 1a91953ab86b248c10e8aae105ec343738b448ea819c3654c2044cfd7822bff7`

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
    public function __construct(protected TrendingService $trending, protected RecommendationService $recommendations, protected CommunityDiscovery $community) {}

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
        $this->add($sections, 'community-new', 'جديد من المجتمع', 'friends', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['new']);
        $this->add($sections, 'community-for-you', 'مقترحة لك من المجتمع', 'sparkles', route('community.puzzles.index'), 'كل أحجيات المجتمع', $community['for_you']);

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

### `app/Services/GameSessionService.php`

`sha256: 734b57abd4408dee6bd25242b0ad0c69ab75f80e3840d509a6be27c79868cc4e`

`````php
<?php

namespace App\Services;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * الأورشستريتور الوحيد لدورة حياة أي جلسة لعبة Stateful - عام تمامًا، لا
 * يعرف شيئًا عن spot_difference بالاسم (يفوّض التفاعل الفعلي لِـ
 * GameSessionHandler الخاص بنوع اللعبة). كل عملية حسم (Finalize) تمر
 * بمعاملة واحدة مع قفل صف، وتُنتج محاولة PuzzleAttempt واحدة بالضبط عبر
 * PuzzleAttemptService الموجود فعلياً - لا تكرار لمنطق المكافأة هون إطلاقاً.
 */
class GameSessionService
{
    public function __construct(
        protected GameTypeRegistry $games,
        protected PuzzleAttemptService $attempts,
    ) {}

    /**
     * $context اختياري وسيرفري بحت - Standalone (الافتراضي) لا يتقاطع أبداً
     * مع أي سياق آخر (حملة/تحدٍّ راعٍ لاحقاً)، ولا العكس.
     */
    public function start(User $user, Puzzle $puzzle, ?AttemptContext $context = null): GameSession
    {
        $context ??= AttemptContext::none();

        // E28: جلسات اللعب مسار رسمي يمنح مكافآت وتأهيلًا؛ أحجيات المجتمع (غير المدعومة بالجلسات أصلًا) مرفوضة هنا كحاجز أخير.
        if ($puzzle->isCommunity()) {
            throw new \RuntimeException('هذه الأحجية غير متاحة عبر هذا المسار.');
        }

        $definition = $this->games->definitionFor($puzzle->game_type);

        if (! $definition instanceof GameSessionHandler) {
            throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
        }

        if (! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير مُفعَّلة حالياً.');
        }

        return DB::transaction(function () use ($user, $puzzle, $definition, $context) {
            $existing = GameSession::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->where('status', GameSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $existing->isExpired()) {
                    return $existing; // جلسة نشطة صالحة فعلاً بنفس السياق - نعيد استخدامها بدل التكرار
                }

                $this->finalize($existing);
            }

            if ($user->hasSolvedPuzzle($puzzle, $context)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $attemptsUsed = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->count();

            if ($attemptsUsed >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            return GameSession::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                ...$context->toAttributes(),
                'status' => GameSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => $puzzle->time_limit_seconds
                    ? now()->addSeconds($puzzle->time_limit_seconds)
                    : null,
                'server_state' => $definition->initialServerState($puzzle),
            ]);
        });
    }

    /**
     * @return array{hit: bool, found: int, required: int, completed: bool, correct: ?bool, gems_awarded: ?int, session_status: string}
     */
    public function reveal(GameSession $session, float $x, float $y): array
    {
        return DB::transaction(function () use ($session, $x, $y) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                return $this->staleResponse($locked);
            }

            if ($locked->isExpired()) {
                $this->finalize($locked);

                return $this->staleResponse($locked->fresh());
            }

            $puzzle = $locked->puzzle;
            $definition = $this->games->definitionFor($puzzle->game_type);

            if (! $definition instanceof GameSessionHandler) {
                throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
            }

            $outcome = $definition->reveal($puzzle, $locked, $x, $y);

            $locked->update(['server_state' => ['found_indices' => $outcome['found_indices']]]);

            $response = [
                'hit' => $outcome['hit'],
                'found' => $outcome['found_count'],
                'required' => $outcome['required_count'],
                'completed' => false,
                'correct' => null,
                'gems_awarded' => null,
                'session_status' => $locked->status,
            ];

            if ($outcome['all_found']) {
                $finalized = $this->finalize($locked->fresh());
                $response['completed'] = true;
                $response['correct'] = $finalized['correct'];
                $response['gems_awarded'] = $finalized['gems_awarded'];
                $response['session_status'] = $locked->fresh()->status;
            }

            return $response;
        });
    }

    /**
     * الحسم النهائي - Idempotent بالكامل. يُستدعى إما تلقائياً من reveal()
     * عند اكتمال كل الفروق، أو عند اكتشاف انتهاء الوقت. لا يمنح مكافأة
     * مرتين مهما استُدعي، بفضل قفل الصف + فحص الحالة قبل أي تعديل.
     *
     * Invariant حقيقي: الجلسة لا تُختم "مكتملة" إلا إذا أكّد Game Type نفسه
     * (عبر isServerStateComplete) أن server_state تمثّل حلاً كاملاً صحيحاً -
     * بغض النظر عمّن استدعى finalize ولماذا.
     *
     * @return array{correct: bool, gems_awarded: int, already_finalized: bool}
     */
    public function finalize(GameSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                $existingAttempt = PuzzleAttempt::where('game_session_id', $locked->id)->first();

                return [
                    'correct' => (bool) ($existingAttempt?->is_correct),
                    'gems_awarded' => 0,
                    'already_finalized' => true,
                ];
            }

            $puzzle = $locked->puzzle;
            $definition = $this->games->definitionFor($puzzle->game_type);

            $genuinelySolved = ! $locked->isExpired()
                && $definition instanceof GameSessionHandler
                && $definition->isServerStateComplete($puzzle, (array) $locked->server_state);

            $locked->update([
                'status' => $genuinelySolved ? GameSession::STATUS_COMPLETED : GameSession::STATUS_EXPIRED,
                'completed_at' => now(),
            ]);

            // Context يُستخرَج من الجلسة نفسها - أبداً لا يُفرَض none() قسراً
            // إذا كانت الجلسة تحمل سياقاً حقيقياً (حملة/تحدٍّ راعٍ لاحقاً).
            $context = $locked->context_type
                ? AttemptContext::for($locked->context_type, $locked->context_id)
                : AttemptContext::none();

            try {
                $result = $this->attempts->attempt(
                    $locked->user,
                    $puzzle,
                    '',
                    false,
                    ['server_state' => $locked->server_state],
                    $context,
                );
            } catch (\RuntimeException $e) {
                return ['correct' => false, 'gems_awarded' => 0, 'already_finalized' => false];
            }

            PuzzleAttempt::whereKey($result['attempt']->id)->update(['game_session_id' => $locked->id]);

            return [
                'correct' => $result['correct'],
                'gems_awarded' => $result['gems_awarded'],
                'already_finalized' => false,
            ];
        });
    }

    /** @return array{hit: bool, found: int, required: int, completed: bool, correct: ?bool, gems_awarded: ?int, session_status: string} */
    protected function staleResponse(GameSession $session): array
    {
        return [
            'hit' => false,
            'found' => count((array) ($session->server_state['found_indices'] ?? [])),
            'required' => 0,
            'completed' => in_array($session->status, [GameSession::STATUS_COMPLETED, GameSession::STATUS_EXPIRED], true),
            'correct' => null,
            'gems_awarded' => null,
            'session_status' => $session->status,
        ];
    }
}
`````

### `app/Services/Notifications/NotificationType.php`

`sha256: 899c98a895aa90631732ad1a4366c9b16d597bd4246e4c6f8f1b5a103f0dbbae`

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
            self::CreatorApproved, self::CreatorRejected, self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden => NotificationCategory::Social,
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

`sha256: e2a339c1a6a7291b17dd0e01fe3e17429e8f73db621e335b2886080e07880a1a`

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

            if ($isCorrect) {
                $gemsAwarded = $this->awardGemsForSolve($user, $puzzle, $context);
                $this->updateChallengeScoresIfAny($user, $puzzle);
            }

            return [
                'attempt' => $puzzleAttempt,
                'correct' => $isCorrect,
                'gems_awarded' => $gemsAwarded,
                'attempts_left' => max(0, $puzzle->max_attempts - $puzzleAttempt->attempt_number),
            ];
        });

        if ($result['correct']) {
            $this->progression->afterPuzzleSolved($user, $result['attempt'], $puzzle, $context);
        }

        return $result;
    }

    protected function awardGemsForSolve(User $user, Puzzle $puzzle, AttemptContext $context): int
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
            return 0;
        }

        $this->wallets->creditPending($user, $currency, $reward, "solved_puzzle:{$puzzle->id}", $puzzle);

        $this->fraud->evaluateAfterEarn($user);

        return $reward;
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

`sha256: f42fd00fab96b51ee8b088ffdda84951970f1b6f28b1d825504ac40f834314a9`

`````php
<?php

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchBackend;
use App\Services\Search\Contracts\SearchSource;
use App\Services\Search\Sources\CampaignSource;
use App\Services\Search\Sources\ChampionshipSource;
use App\Services\Search\Sources\CommunitySource;
use App\Services\Search\Sources\CompetitionSource;
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

    public function __construct(PuzzleSource $puzzles, CampaignSource $campaigns, SeasonSource $seasons, CompetitionSource $competitions, ChampionshipSource $championships, TeamSource $teams, PlayerSource $players, CommunitySource $community)
    {
        foreach ([$puzzles, $community, $campaigns, $seasons, $competitions, $championships, $teams, $players] as $source) {
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

### `app/Services/UserAccountService.php`

`sha256: 302cbb2d4ecbebbdebae75a7427ad5b76999d39a237cd60705ddf7ef191f2b51`

`````php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * نقطة الحقيقة الوحيدة لتجميد/رفع تجميد حساب - تتعامل مع الحالة +
 * الحماية (AuthorizationSafetyService) + سجل العمليات (OperationalAuditService)
 * معاً. لا Controller/Filament Action يعدّل is_frozen مباشرة.
 */
class UserAccountService
{
    public function __construct(
        protected AuthorizationSafetyService $safety,
        protected OperationalAuditService $audit,
    ) {}

    public function freeze(User $target, User $actor, string $reason): void
    {
        // لا يمكن تجميد Super Admin إلا من طرف Super Admin آخر، ولا يمكن
        // تجميد آخر Super Admin بالنظام إطلاقاً (حساب مجمَّد = معطَّل
        // فعلياً لأي غرض عملي بلوحة الإدارة).
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        if ($this->safety->isLastSuperAdmin($target)) {
            abort(403, 'لا يمكن تجميد هذا الحساب لأنه آخر مدير أعلى بالمنصة.');
        }

        if ($target->is($actor)) {
            abort(403, 'لا يمكنك تجميد حسابك الخاص.');
        }

        DB::transaction(function () use ($target, $actor, $reason) {
            // forceFill لازم هون - is_frozen/frozen_reason/frozen_at/frozen_by
            // مو موجودين بـ$fillable على User (نفس درس AdminUserSeeder) -
            // update() العادية كانت ستُسقط هذه الحقول بصمت بلا أي خطأ.
            $target->forceFill([
                'is_frozen' => true,
                'frozen_reason' => $reason,
                'frozen_at' => now(),
                'frozen_by' => $actor->id,
            ])->save();

            $this->audit->log('user_frozen', $target, ['reason' => $reason], $actor);
        });

        $this->reconcileCreatorVisibility($target);
    }

    public function unfreeze(User $target, User $actor): void
    {
        $this->safety->assertCanModifyUserAuthorization($actor, $target);

        $previousReason = $target->frozen_reason;

        DB::transaction(function () use ($target, $actor, $previousReason) {
            $target->forceFill([
                'is_frozen' => false,
                'frozen_reason' => null,
                'frozen_at' => null,
                'frozen_by' => null,
            ])->save();

            $this->audit->log('user_unfrozen', $target, ['previous_reason' => $previousReason], $actor);
        });

        $this->reconcileCreatorVisibility($target);
    }

    /**
     * E28: تجميد/رفع تجميد حساب مبدع يُخفي/يُظهر منشوراته (إخفاء مشتقّ بسبب account_frozen لا يلغي الإخفاء الإشرافي). بعد الـcommit؛ فشلها لا يُفشل التجميد
     * (الأمان أولًا) وأي مصالحة لاحقة تعيد الاتساق. is_frozen يكتبها هذا الصنف وحده.
     */
    protected function reconcileCreatorVisibility(User $target): void
    {
        try {
            app(\App\Services\Creators\CreatorVisibilityService::class)->reconcileForUser($target->refresh());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
`````

### `app/Support/NavigationMenu.php`

`sha256: f98ac6aee98c8d626c94697c067c811335681f3ad1ac5a7ed3508f64b5f9cbf6`

`````php
<?php

namespace App\Support;

use App\Models\User;

/**
 * بنية التنقل العامة (E22) - **عرض فقط**: لا منطق مجال ولا صلاحيات جديدة. تبني قوائم التنقل الرئيسية/الثانوية/الحساب من المسارات القائمة وتحترم مفاتيح
 * إعدادات المنصة (`navigation.show_*`). كل وجهة كانت بالتنقل القديم باقية بوصول ≤ نقرتين: الرئيسية (أيقونات) ← «المزيد» ← قائمة الحساب ← ورقة «المزيد» بالجوال.
 * مصدر واحد لسطح المكتب والجوال فلا تنحرف القائمتان.
 */
final class NavigationMenu
{
    /**
     * @param  array<string, mixed>  $navigation  إعدادات المجموعة navigation
     * @return array{primary: list<array>, bottom: list<array>, guest: list<array>, more: list<array{title: string, items: list<array>}>, account: list<array>}
     */
    public static function build(?User $user, array $navigation): array
    {
        $verified = $user !== null && $user->email_verified_at !== null;
        $show = fn (string $key) => (bool) ($navigation[$key] ?? true);
        $item = fn (string $key, string $route, string $icon, string $label, array $match, array $extra = []) => [
            'key' => $key, 'url' => route($route), 'route' => $route, 'icon' => $icon, 'label' => $label, 'match' => $match,
        ] + $extra;

        $home = $item('home', 'home', 'home', 'الرئيسية', ['home']);
        $puzzles = $item('puzzles', 'puzzles.index', 'puzzle', 'الأحجيات', ['puzzles.*']);
        $competitions = $item('competitions', 'competitions.index', 'trophy', 'المنافسات', ['competitions.*', 'team-championships.*', 'players.competitive']);
        $teams = $item('teams', 'teams.index', 'team', 'الفرق', ['teams.*'], ['except' => ['teams.chat']]);
        $friends = $item('friends', 'friends.index', 'friends', 'الأصدقاء', ['friends.*']);
        $messages = $item('messages', 'messages.index', 'chat', 'الرسائل', ['messages.*', 'community.chat', 'teams.chat', 'chat.*'], ['badge' => 'chat']);

        $primary = array_values(array_filter([
            $home,
            $show('show_puzzles_link') ? $puzzles : null,
            $competitions,
            $teams,
            $verified ? $friends : null,
            $verified ? $messages : null,
        ]));

        // الجوال: 4 مدخل سفلية + «المزيد». الفريق والأصدقاء بورقة «المزيد» (نقرتان).
        $bottom = array_values(array_filter([
            $home,
            $show('show_puzzles_link') ? $puzzles : null,
            $competitions,
            $verified ? $messages : $teams,
        ]));

        $explore = array_values(array_filter([
            $item('discover', 'discover.index', 'sparkles', 'اكتشف', ['discover.*', 'search.*']),
            $item('community-puzzles', 'community.puzzles.index', 'friends', 'أحجيات المجتمع', ['community.puzzles.*', 'creators.*']),
            $show('show_seasons_link') ? $item('seasons', 'seasons.index', 'calendar', 'المواسم', ['seasons.*', 'campaigns.*']) : null,
            $show('show_challenges_link') ? $item('challenges', 'challenges.index', 'bolt', 'التحديات', ['challenges.*', 'friends.challenges.*']) : null,
            $show('show_leaderboard_link') ? $item('leaderboard', 'leaderboard.index', 'chart', 'لوحة الصدارة', ['leaderboard.*']) : null,
            $item('championships', 'team-championships.index', 'flag', 'بطولات الفرق', ['team-championships.*']),
            $item('hall-of-fame', 'competitions.hall-of-fame', 'sparkles', 'قاعة الأمجاد', ['competitions.hall-of-fame']),
        ]));

        $moreGroups = [['title' => 'استكشاف', 'items' => $explore]];

        if ($user !== null) {
            $moreGroups[] = ['title' => 'تقدّمي', 'items' => [
                $item('progress', 'progress.show', 'sparkles', 'تقدّمي وإنجازاتي', ['progress.*']),
                $item('quests', 'quests.show', 'quests', 'المهام اليومية', ['quests.*']),
            ]];
            $moreGroups[] = ['title' => 'متجري ومحفظتي', 'items' => [
                $item('store', 'store.index', 'store', 'المتجر', ['store.*']),
                $item('inventory', 'inventory.index', 'inventory', 'مقتنياتي', ['inventory.*']),
                $item('wallet', 'wallet.index', 'wallet', 'محفظتي', ['wallet.*']),
                $item('redemption', 'redemption.index', 'gift', 'الاستبدال', ['redemption.*']),
            ]];
            $verified && $moreGroups[] = ['title' => 'التواصل', 'items' => array_values(array_filter([
                $item('notifications', 'notifications.index', 'bell', 'مركز الإشعارات', ['notifications.*']),
                $teams,
                $friends,
            ]))];
            // E28: بوابة الاستوديو (عرض فقط، بلا استعلام لكل طلب): صفحة الطلب تعرض الحالة وتُحيل المبدع المعتمَد للاستوديو.
            $verified && $moreGroups[] = ['title' => 'المبدعون', 'items' => [
                $item('creator-studio', 'creator.apply', 'palette', 'استوديو المبدع', ['creator.*']),
            ]];
            $user->email_verified_at === null && $moreGroups[] = ['title' => 'المجتمع', 'items' => [$teams]];
        } else {
            $moreGroups[] = ['title' => 'المتجر', 'items' => [$item('store', 'store.index', 'store', 'المتجر', ['store.*'])]];
        }

        $guest = array_values(array_filter([
            $show('show_puzzles_link') ? $puzzles : null,
            $show('show_seasons_link') ? $item('seasons', 'seasons.index', 'calendar', 'المواسم', ['seasons.*', 'campaigns.*']) : null,
            $competitions,
            $teams,
            $show('show_leaderboard_link') ? $item('leaderboard', 'leaderboard.index', 'chart', 'لوحة الصدارة', ['leaderboard.*']) : null,
            $item('store', 'store.index', 'store', 'المتجر', ['store.*']),
        ]));

        $account = [];

        if ($user !== null) {
            $account[] = ['key' => 'profile', 'route' => 'players.show', 'url' => route('players.show', $user), 'icon' => 'user', 'label' => 'عرض ملفي الشخصي', 'match' => ['players.show']];
            $verified && $account[] = ['key' => 'customize', 'route' => 'profile.customize', 'url' => route('profile.customize'), 'icon' => 'palette', 'label' => 'تخصيص الهوية', 'match' => ['profile.customize']];
            $account[] = ['key' => 'settings', 'route' => 'profile.edit', 'url' => route('profile.edit'), 'icon' => 'settings', 'label' => 'إعدادات الحساب', 'match' => ['profile.edit', 'profile.update']];
            $account[] = ['key' => 'privacy', 'route' => 'profile.edit', 'url' => route('profile.edit').'#privacy', 'icon' => 'lock', 'label' => 'الخصوصية', 'match' => []];
        }

        return compact('primary', 'bottom', 'guest') + ['more' => $moreGroups, 'account' => $account];
    }

    /** هل العنصر هو الصفحة الحالية؟ (مطابقة أسماء المسارات، مع استثناءات). */
    public static function isActive(array $item): bool
    {
        $request = request();

        if ($item['match'] === [] || ! $request->routeIs(...$item['match'])) {
            return false;
        }

        return ! (($item['except'] ?? []) !== [] && $request->routeIs(...$item['except']));
    }
}
`````

### `config/permissions.php`

`sha256: 8d0f687c4ffcc87234d2eb9b49ae65d67a4fc1350975e2e6783377330daed42a`

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

    'notifications' => [
        'label' => 'الإشعارات',
        'permissions' => [
            'notifications.settings.manage' => 'إدارة إعدادات الإشعارات العامة (تفعيل/ساعات التذكير/ميزانية التذكيرات)',
        ],
    ],

];
`````

### `config/search.php`

`sha256: a1372f6a7eb27a5c3f825e649b617997245806200a6876fc9b1d00bd8648e70f`

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
    'types' => ['puzzles', 'community', 'campaigns', 'seasons', 'competitions', 'championships', 'teams', 'players'],

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

`sha256: a4181af4dc0dfb717dfa6e820e051eb5ade8944dcc9305aa36d71641d79ff6d6`

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

### `routes/console.php`

`sha256: e1ba476052b9d2064e9c0a20299b43f86e67ea0c409f8fafd9a34207f8e76313`

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

// E25: نبض المجدول - يكتب الطابع الزمني بالكاش الافتراضي كل دقيقة؛ عمره = صحة المجدول (مركز العمليات، production:check، وعتبة /ready الاختيارية).
// يمرّ من $guard مثل بقية المهام (قاعدة E24: لا مهمة بلا قفل تداخل محدود ولا onOneServer). قفل 5 دقائق يكفي لعملية بطولها ميلي ثوانٍ وينتهي وحده بعد أي انهيار.
// يُسجَّل أخيرًا كي لا يغيّر ترتيب المهام القائمة.
$guard(Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute()->name('scheduler-heartbeat'), 5);
`````

### `routes/web.php`

`sha256: e376ae13771a36fbebc04f6df63d56fe54133017637293deccc6ac9418ad052c`

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
`````

### `tests/Feature/DiscoveryE27/E27PerformanceTest.php`

`sha256: 4a43bd2ae69b16b5d76b30d90db3ba16484b0ebe5c817b692e0b325c710cc269`

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

    // JSON بلا إطار صفحة: مصدر لكل نوع (8 منذ E28: +1 لأحجيات المجتمع) + تحميل التصنيف + الحظر للمسجَّل. مقيس 11 (ضيف) و11 (لاعب).
    expect($count('suggest guest'))->toBeLessThanOrEqual(11)->and($count('suggest player'))->toBeLessThanOrEqual(12);
    // صفحات كاملة: فوق قاعدة الصفحة العادية بنفس المشاهد (مقيس: ضيف +7، لاعب +7 أو أقل).
    expect($count('search all (guest)') - $baseGuest)->toBeLessThanOrEqual(12)
        ->and($count('search all (player)') - $basePlayer)->toBeLessThanOrEqual(12)
        ->and($count('search players page') - $basePlayer)->toBeLessThanOrEqual(5)
        ->and($count('search puzzles page') - $basePlayer)->toBeLessThanOrEqual(5);
    // الاكتشاف: قسم بكل استعلام محدود (مقيس 22).
    expect($count('discover guest'))->toBeLessThanOrEqual(26)->and($count('discover player'))->toBeLessThanOrEqual(28);
});

test('P3: a cold cache costs a few extra queries once - then nothing until it expires', function () {
    $viewer = e27_user();
    e27_grow(0, 5);
    $endpoints = e27_endpoints($this, $viewer);

    e27_cold();
    $cold = e27_queries($endpoints['suggest guest']);
    $warm = e27_queries($endpoints['suggest guest']);
    expect($cold)->toBeLessThanOrEqual(18)->and($warm)->toBeLessThan($cold)->and($cold - $warm)->toBeLessThanOrEqual(8);   // مجسّ الحجم لكل نوع مرة واحدة

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

    $tiered = collect($log)->filter(fn ($q) => str_contains($q['query'], 'search_tier') && ! str_contains($q['query'], 'from "team_championships"') && ! str_contains($q['query'], 'from "creator_puzzles"'));   // لا بطولات ولا أحجيات مجتمع بالبيانات: جداول فارغة (أحجيات المجتمع بوضعها الكبير مفحوصة في CreatorE28)
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


---

## C) MIGRATIONS — جديدة فقط (لا تعديل لقديم)

1. `database/migrations/2026_10_20_000001_create_creator_ecosystem_tables.php` — creator_profiles, creator_puzzles, creator_puzzle_revisions, community_puzzle_attempts, community_puzzle_reports (+ الفهارس والقيود). محتواها الكامل في A أعلاه.
2. `database/migrations/2026_10_20_000002_add_origin_to_puzzles_table.php` — عمود `puzzles.origin` (افتراضي `official`) + فهرس. محتواها الكامل في A أعلاه.

كلتاهما قابلة للتراجع (`down()`)، وتعمل على MySQL وSQLite. بعد النسخ: `php artisan migrate` (بلا `--force` محليًا).


---

## D) ENV LINES

أضفها لـ`.env` **اختياريًا** (كلها بقيم افتراضية مناسبة، وموجودة معلَّقة في `.env.example`):

```ini

# E28 - منظومة المبدعين وأحجيات المجتمع (كلها اختيارية؛ الافتراضيات آمنة ولا تتطلب شيئًا). انظر docs/creator-ecosystem-e28.md.
# أحجيات المجتمع بلا مكافآت اقتصادية بأي إعداد (جواهر/XP = 0 ثابت بالكود، لا مفتاح بيئة له).
# CREATOR_PUZZLE_MAX_ATTEMPTS=3              # عدد محاولات اللاعب لكل أحجية مجتمع (سيرفري، لا يحدده المبدع)
# CREATOR_MAX_WORKS=100                      # سقف أعمال المبدع الواحد
# CREATOR_MAX_OPEN_DRAFTS=20                 # سقف المسودات المفتوحة لكل مبدع
# CREATOR_MAX_PENDING=5                      # سقف الأعمال قيد المراجعة معًا لكل مبدع
# CREATOR_IMAGE_MAX_KB=4096                  # أقصى حجم صورة أحجية (كيلوبايت). JPEG/PNG/WEBP فقط، لا SVG
# CREATOR_ANALYTICS_TTL=120                  # عمر لقطة تحليلات المبدع بالثواني (أرقام مجمَّعة فقط)
# CREATOR_TRENDING_MIN_PLAYERS=2             # أقل عدد لاعبين مميَّزين كي يظهر عمل بقسم «رائج من المجتمع»
```


---

## E) LOCAL COMMANDS (محليًا على XAMPP، بالترتيب)

NO GIT COMMANDS.

```powershell
# 1) بعد نسخ الملفات (new-files + modified-full-files) إلى جذر المشروع
php artisan migrate

# 2) صلاحيات creators.* وأدوارها (idempotent)
php artisan db:seed --class=RolesAndPermissionsSeeder

# 3) اختياري: بيانات Demo للمبدعين (محلي فقط؛ يرفض الإنتاج)
php artisan db:seed --class=DemoCreatorSeeder

# 4) تنظيف الكاش (أقسام الاكتشاف/الرائج/الصلاحيات)
php artisan optimize:clear

# 5) اختبارات E28 (مسار محدد، ليس الحزمة كلها)
php artisan test tests/Feature/CreatorE28

# 6) انحدارات مستهدفة
php artisan test tests/Feature/DiscoveryE27
php artisan test tests/Feature/UiE22 tests/Feature/UiE23
php artisan test tests/Feature/SecurityE26

# 7) اختياري: معاينة تنظيف صور المبدعين اليتيمة (لا يحذف)
php artisan creators:prune-media --dry-run
```

لا تغيير للواجهة الأمامية (JS/CSS) في E28 ← **لا حاجة لـ`npm run build`**. لا `composer install/update`.

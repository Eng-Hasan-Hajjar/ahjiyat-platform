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

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserCollectionReportResource\Pages;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Services\Collections\CollectionModerationService;
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
 * E35 - طابور بلاغات المجموعات. يعرض عنوان المجموعة ومالكها (الاسم فقط) والسبب (قائمة مغلقة) والمبلِّغ (الاسم فقط)؛ لا بريد ولا نص حر من المبلِّغ.
 * مجموعة صارت خاصة بعد البلاغ لا يُعرض عنوانها للمشرف (خصوصية المالك). الإجراءات (رفض / معالجة / معالجة مع إخفاء) عبر CollectionModerationService بصلاحية collections.moderate
 * (تدقيق + ذرّية + إشعار المالك عند الإخفاء). لا إنشاء ولا تعديل خام ولا حذف.
 */
class UserCollectionReportResource extends Resource
{
    protected static ?string $model = UserCollectionReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'بلاغات المجموعات';

    protected static ?string $modelLabel = 'بلاغ مجموعة';

    protected static ?string $pluralModelLabel = 'بلاغات المجموعات';

    protected static ?int $navigationSort = 83;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج'][$s] ?? $s;
    }

    /** عنوان المجموعة للمشرف: فقط ما دامت مشتركة (غير خاصة)، وإلا نص محايد. */
    public static function collectionTitle(UserCollectionReport $report): string
    {
        $c = $report->collection;

        if ($c === null) {
            return '—';
        }

        return $c->visibility === UserCollection::VISIBILITY_PRIVATE ? 'أصبحت المجموعة خاصة' : (string) $c->title;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['collection:id,public_id,title,visibility,is_moderation_hidden,user_id', 'collection.user:id,name', 'reporter:id,name']))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption((int) config('collections.reports.moderation_page_size', 20))
            ->paginationPageOptions([10, (int) config('collections.reports.moderation_page_size', 20), 50])
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel((string) $state))
                    ->color(fn ($state) => ['open' => 'warning', 'actioned' => 'success'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('collection.title')->label('المجموعة')->state(fn (UserCollectionReport $r) => self::collectionTitle($r))->limit(40),
                Tables\Columns\TextColumn::make('collection.user.name')->label('المالك')->placeholder('—'),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->formatStateUsing(fn ($state) => UserCollectionReport::REASONS[$state] ?? $state),
                Tables\Columns\TextColumn::make('reporter.name')->label('المُبلِّغ'),
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج'])->default(UserCollectionReport::STATUS_OPEN),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('open')->label('فتح المجموعة')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (UserCollectionReport $r) => route('collections.public.show', $r->collection->public_id), shouldOpenInNewTab: true)
                    ->visible(fn (UserCollectionReport $r) => $r->collection !== null && $r->collection->visibility !== UserCollection::VISIBILITY_PRIVATE && ! $r->collection->is_moderation_hidden),
                self::closeAction('dismiss', 'رفض البلاغ', 'gray', fn (CollectionModerationService $s, UserCollectionReport $r, ?string $n) => $s->dismiss($r, auth()->user(), $n), 'رُفض البلاغ.'),
                self::closeAction('action', 'تمت المعالجة', 'success', fn (CollectionModerationService $s, UserCollectionReport $r, ?string $n) => $s->action($r, auth()->user(), $n), 'حُسم البلاغ.'),
                Tables\Actions\Action::make('hide')->label('معالجة وإخفاء المجموعة')->color('danger')->icon('heroicon-o-eye-slash')
                    ->form([Forms\Components\Textarea::make('note')->label('السبب (إلزامي، يراه المالك)')->required()->minLength((int) config('collections.reports.note_min'))->maxLength((int) config('collections.reports.note_max'))])
                    ->visible(fn (UserCollectionReport $r) => $r->status === UserCollectionReport::STATUS_OPEN && (auth()->user()?->can('collections.moderate') ?? false))
                    ->action(fn (UserCollectionReport $record, array $data) => self::guard(fn () => app(CollectionModerationService::class)->actionAndHide($record, auth()->user(), (string) $data['note']), 'أُخفيت المجموعة وحُسمت البلاغات المفتوحة عليها.')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('البلاغ')->columns(3)->schema([
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel((string) $state)),
                Infolists\Components\TextEntry::make('reason')->label('السبب')->formatStateUsing(fn ($state) => UserCollectionReport::REASONS[$state] ?? $state),
                Infolists\Components\TextEntry::make('reporter.name')->label('المُبلِّغ'),
                Infolists\Components\TextEntry::make('collection.title')->label('المجموعة')->state(fn (UserCollectionReport $r) => self::collectionTitle($r)),
                Infolists\Components\TextEntry::make('created_at')->label('وقت البلاغ')->dateTime('Y-m-d H:i'),
                Infolists\Components\TextEntry::make('handled_at')->label('وقت المعالجة')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('handler_note')->label('ملاحظة المعالج')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    /** @param \Closure(CollectionModerationService, UserCollectionReport, ?string): mixed $call */
    protected static function closeAction(string $name, string $label, string $color, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->form([Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength((int) config('collections.reports.note_max'))])
            ->visible(fn (UserCollectionReport $r) => $r->status === UserCollectionReport::STATUS_OPEN && (auth()->user()?->can('collections.moderate') ?? false))
            ->action(fn (UserCollectionReport $record, array $data) => self::guard(fn () => $call(app(CollectionModerationService::class), $record, $data['note'] ?? null), $ok));
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
        return ['index' => Pages\ListUserCollectionReports::route('/'), 'view' => Pages\ViewUserCollectionReport::route('/{record}')];
    }
}

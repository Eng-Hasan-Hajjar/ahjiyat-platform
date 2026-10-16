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

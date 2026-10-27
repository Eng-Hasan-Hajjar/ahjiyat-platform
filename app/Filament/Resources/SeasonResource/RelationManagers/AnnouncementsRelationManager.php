<?php

namespace App\Filament\Resources\SeasonResource\RelationManagers;

use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * E42 - إعلانات الموسم (نص عادي فقط، يُنقّى عند الحفظ ويُهرَّب عند العرض). الإعلان لا يعني إشعارًا: «إشعار اللاعبين» قرار صريح بصلاحية النشر (seasons.publish)
 * ويُرسَل مرة واحدة فقط ولا يُغيَّر بعد إرساله. الإعلان المجدول/المسودة لا يظهر للجمهور.
 */
class AnnouncementsRelationManager extends RelationManager
{
    protected static string $relationship = 'announcements';

    protected static ?string $title = 'إعلانات الموسم';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('seasons.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('seasons.update') ?? false);
    }

    protected function statusLabels(): array
    {
        return ['draft' => 'مسودة', 'scheduled' => 'مجدول', 'live' => 'منشور', 'expired' => 'منتهٍ'];
    }

    /** @return array<int, Forms\Components\Component> */
    protected function schemaFor(): array
    {
        $canNotify = auth()->user()?->can('seasons.publish') ?? false;

        return [
            Forms\Components\TextInput::make('title')->label('العنوان')->required()->maxLength((int) config('seasons.limits.announcement_title_max', 160))->columnSpanFull(),
            Forms\Components\Textarea::make('body')->label('النص (نص عادي بلا HTML)')->required()->rows(4)->maxLength((int) config('seasons.limits.announcement_body_max', 2000))->columnSpanFull(),
            Forms\Components\DateTimePicker::make('published_at')->label('موعد النشر')->seconds(false)->helperText('فارغ = مسودة لا تظهر للجمهور. مستقبلي = مجدول.'),
            Forms\Components\DateTimePicker::make('expires_at')->label('موعد الانتهاء (اختياري)')->seconds(false),
            Forms\Components\Select::make('season_phase_id')->label('المرحلة (اختياري)')->native(false)->placeholder('كل الموسم')
                ->options(fn () => $this->getOwnerRecord()->phases()->pluck('name', 'id')->all()),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0)->minValue(0)->maxValue(65535),
            Forms\Components\Toggle::make('is_featured')->label('تثبيت'),
            Forms\Components\Select::make('notify_audience')->label('إشعار اللاعبين')->native(false)->placeholder('بدون إشعار')
                ->options(['participants' => 'مشاركو الحملة الأساسية', 'all' => 'كل اللاعبين الموثَّقين'])
                ->visible($canNotify)
                ->helperText('يُرسَل مرة واحدة عند حلول موعد النشر، ولا يمكن تغييره بعد الإرسال.'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated([10, 25])
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('id'))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->limit(60),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->state(fn (SeasonAnnouncement $r) => $this->statusLabels()[$r->statusAt()])->badge()
                    ->color(fn (SeasonAnnouncement $r) => match ($r->statusAt()) {
                        'live' => 'success',
                        'scheduled' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('published_at')->label('النشر')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('notify_audience')->label('الإشعار')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, SeasonAnnouncement $r) => $state === null ? '—' : ($state === 'all' ? 'الجميع' : 'المشاركون').($r->notified_at ? ' · عُولج' : ' · بانتظار الموعد')),
                Tables\Columns\IconColumn::make('is_featured')->label('مثبّت')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addAnnouncement')->label('إعلان جديد')->icon('heroicon-o-megaphone')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->form($this->schemaFor())->modalWidth('3xl')
                    ->action(fn (array $data) => $this->run(fn (SeasonBuilderService $b, Season $s) => $b->createAnnouncement(auth()->user(), $s, $data), 'حُفظ الإعلان.')),
            ])
            ->actions([
                Tables\Actions\Action::make('editAnnouncement')->label('تعديل')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->fillForm(fn (SeasonAnnouncement $record) => $record->only(['title', 'body', 'published_at', 'expires_at', 'season_phase_id', 'sort_order', 'is_featured', 'notify_audience']))
                    ->form($this->schemaFor())->modalWidth('3xl')
                    ->action(fn (SeasonAnnouncement $record, array $data) => $this->run(fn (SeasonBuilderService $b) => $b->updateAnnouncement(auth()->user(), $record, $data), 'حُدّث الإعلان.')),
                Tables\Actions\Action::make('deleteAnnouncement')->label('حذف')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn () => ! $this->isReadOnly())
                    ->action(fn (SeasonAnnouncement $record) => $this->run(fn (SeasonBuilderService $b) => $b->deleteAnnouncement(auth()->user(), $record), 'حُذف الإعلان.')),
            ]);
    }

    protected function run(\Closure $call, string $ok): void
    {
        try {
            $call(app(SeasonBuilderService::class), $this->getOwnerRecord());
            Notification::make()->success()->title($ok)->send();
        } catch (SeasonException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}

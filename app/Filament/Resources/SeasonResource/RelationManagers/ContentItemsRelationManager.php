<?php

namespace App\Filament\Resources\SeasonResource\RelationManagers;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\MissionPool;
use App\Models\Season;
use App\Models\SeasonContentItem;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * E42 - محتوى الموسم المرتبط (حملات إضافية، منافسات، مجموعات مهام). **ربط عرض وتنسيق فقط**: لا نسخ لتقدّم ولا مكافآت ولا تواريخ - الكيان يبقى سلطة بياناته.
 * كل كتابة عبر SeasonBuilderService (حدود، منع الحملة الأساسية/حملة موسم آخر، منافسة ملغاة، تدقيق).
 */
class ContentItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'contentItems';

    protected static ?string $title = 'محتوى الموسم';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('seasons.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('seasons.update') ?? false);
    }

    protected function typeLabels(): array
    {
        return [
            SeasonContentItem::TYPE_CAMPAIGN => 'حملة إضافية',
            SeasonContentItem::TYPE_COMPETITIVE_EVENT => 'منافسة',
            SeasonContentItem::TYPE_MISSION_POOL => 'مجموعة مهام',
        ];
    }

    /** @return array<int|string, string> */
    protected function targets(?string $type): array
    {
        /** @var Season $season */
        $season = $this->getOwnerRecord();

        return match ($type) {
            SeasonContentItem::TYPE_CAMPAIGN => Campaign::query()->where('id', '!=', $season->campaign_id)
                ->whereDoesntHave('season')->orderByDesc('id')->limit(100)->pluck('title', 'id')->all(),
            SeasonContentItem::TYPE_COMPETITIVE_EVENT => CompetitiveEvent::query()->where('status', '!=', CompetitiveEvent::STATUS_CANCELLED)
                ->orderByDesc('id')->limit(100)->pluck('title', 'id')->all(),
            SeasonContentItem::TYPE_MISSION_POOL => MissionPool::query()->orderByDesc('id')->limit(100)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    protected function titleOf(SeasonContentItem $item): string
    {
        return match ($item->content_type) {
            SeasonContentItem::TYPE_CAMPAIGN => (string) $item->campaign?->title,
            SeasonContentItem::TYPE_COMPETITIVE_EVENT => (string) $item->competitiveEvent?->title,
            default => (string) $item->missionPool?->name,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->modifyQueryUsing(fn ($query) => $query->with(['campaign:id,title', 'competitiveEvent:id,title', 'missionPool:id,name', 'phase:id,name']))
            ->columns([
                Tables\Columns\TextColumn::make('content_type')->label('النوع')->badge()->formatStateUsing(fn (string $state) => $this->typeLabels()[$state] ?? $state),
                Tables\Columns\TextColumn::make('title')->label('العنصر')->state(fn (SeasonContentItem $r) => $this->titleOf($r))->description(fn (SeasonContentItem $r) => $r->display_label),
                Tables\Columns\TextColumn::make('phase.name')->label('المرحلة')->placeholder('—'),
                Tables\Columns\TextColumn::make('sort_order')->label('الترتيب'),
                Tables\Columns\IconColumn::make('is_featured')->label('مميّز')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addContent')->label('ربط محتوى')->icon('heroicon-o-link')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->form(fn () => [
                        Forms\Components\Select::make('type')->label('النوع')->options($this->typeLabels())->required()->native(false)->live(),
                        Forms\Components\Select::make('target_id')->label('العنصر')->required()->searchable()->native(false)
                            ->options(fn (Get $get) => $this->targets($get('type'))),
                        ...$this->optionFields(),
                    ])
                    ->action(function (array $data) {
                        $this->run(fn (SeasonBuilderService $b, Season $s) => $b->addContent(auth()->user(), $s, (string) $data['type'], (int) $data['target_id'], $this->options($data)), 'رُبط المحتوى بالموسم.');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('editContent')->label('تعديل')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->fillForm(fn (SeasonContentItem $record) => $record->only(['display_label', 'season_phase_id', 'sort_order', 'is_featured']))
                    ->form($this->optionFields())
                    ->action(fn (SeasonContentItem $record, array $data) => $this->run(fn (SeasonBuilderService $b) => $b->updateContent(auth()->user(), $record, $this->options($data)), 'حُدّث الرابط.')),
                Tables\Actions\Action::make('unlink')->label('فكّ الربط')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->modalDescription('لا يحذف هذا الإجراء الحملة أو المنافسة أو المجموعة نفسها، يزيل ظهورها في الموسم فقط.')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->action(fn (SeasonContentItem $record) => $this->run(fn (SeasonBuilderService $b) => $b->removeContent(auth()->user(), $record), 'فُكّ الربط.')),
            ]);
    }

    /** @return array<int, Forms\Components\Component> */
    protected function optionFields(): array
    {
        return [
            Forms\Components\TextInput::make('display_label')->label('عنوان العرض (اختياري)')->maxLength((int) config('seasons.limits.label_max', 120)),
            Forms\Components\Select::make('season_phase_id')->label('المرحلة (اختياري)')->native(false)->placeholder('كل الموسم')
                ->options(fn () => $this->getOwnerRecord()->phases()->pluck('name', 'id')->all()),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0)->minValue(0)->maxValue(65535),
            Forms\Components\Toggle::make('is_featured')->label('مميّز'),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function options(array $data): array
    {
        return array_intersect_key($data, array_flip(['display_label', 'season_phase_id', 'sort_order', 'is_featured']));
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

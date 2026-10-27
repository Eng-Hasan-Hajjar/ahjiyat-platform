<?php

namespace App\Filament\Resources\SeasonResource\RelationManagers;

use App\Models\Season;
use App\Models\SeasonPhase;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use App\Services\Seasons\SeasonTheme;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * E42 - مراحل الموسم (فصوله). كل كتابة عبر SeasonBuilderService حصرًا (لا create/edit/delete الافتراضية): فحص عدم التداخل والبقاء ضمن نافذة الموسم،
 * وقفل بداية ما بدأ، وتدقيق، وإبطال الكاش. المرحلة بيانات جدولة وتحرير فقط - ليست مهمة ولا مرحلة حملة.
 */
class PhasesRelationManager extends RelationManager
{
    protected static string $relationship = 'phases';

    protected static ?string $title = 'مراحل الموسم';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('seasons.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('seasons.update') ?? false);
    }

    /** @return array<int, Forms\Components\Component> */
    protected function schemaFor(bool $creating): array
    {
        return [
            Forms\Components\TextInput::make('name')->label('اسم المرحلة')->required()->maxLength((int) config('seasons.limits.phase_name_max', 120)),
            Forms\Components\TextInput::make('internal_key')->label('معرّف داخلي (اختياري)')->visible($creating)->maxLength(64)
                ->helperText('حروف إنجليزية صغيرة وأرقام وشرطات. يُولَّد تلقائيًا إن تُرك فارغًا، ولا يتغير بعد الإنشاء.'),
            Forms\Components\Textarea::make('description')->label('وصف قصير')->rows(2)->maxLength((int) config('seasons.limits.phase_description_max', 600))->columnSpanFull(),
            Forms\Components\DateTimePicker::make('starts_at')->label('بداية المرحلة')->required()->seconds(false),
            Forms\Components\DateTimePicker::make('ends_at')->label('نهاية المرحلة')->required()->seconds(false)->helperText('الفترة نصف مفتوحة: يمكن أن تبدأ مرحلة لحظة انتهاء سابقتها.'),
            Forms\Components\Select::make('accent')->label('لون المرحلة')->options(SeasonTheme::accentLabels())->native(false)->placeholder('يرث ثيم الموسم'),
            Forms\Components\TextInput::make('sort_order')->label('ترتيب عند التساوي')->numeric()->default(0)->minValue(0)->maxValue(65535),
            Forms\Components\Toggle::make('is_featured')->label('مميّزة'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->modifyQueryUsing(fn ($query) => $query->orderBy('starts_at')->orderBy('sort_order')->orderBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('المرحلة')->description(fn (SeasonPhase $r) => $r->internal_key),
                Tables\Columns\TextColumn::make('starts_at')->label('البداية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('ends_at')->label('النهاية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('state')->label('الحالة')->state(fn (SeasonPhase $r) => SeasonPhase::stateLabel($r->stateAt()))->badge()
                    ->color(fn (SeasonPhase $r) => match ($r->stateAt()) {
                        SeasonPhase::STATE_CURRENT => 'success',
                        SeasonPhase::STATE_UPCOMING => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_featured')->label('مميّزة')->boolean(),
                Tables\Columns\IconColumn::make('went_live_at')->label('بدأت')->boolean()->state(fn (SeasonPhase $r) => $r->went_live_at !== null),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addPhase')->label('إضافة مرحلة')->icon('heroicon-o-plus')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->form($this->schemaFor(true))->modalWidth('3xl')
                    ->action(fn (array $data) => $this->run(fn (SeasonBuilderService $b, Season $s) => $b->addPhase(auth()->user(), $s, $this->payload($data)), 'أُضيفت المرحلة.')),
            ])
            ->actions([
                Tables\Actions\Action::make('editPhase')->label('تعديل')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->fillForm(fn (SeasonPhase $record) => $record->only(['name', 'description', 'starts_at', 'ends_at', 'sort_order', 'is_featured']) + ['accent' => $record->themeAccent()])
                    ->form($this->schemaFor(false))->modalWidth('3xl')
                    ->action(fn (SeasonPhase $record, array $data) => $this->run(fn (SeasonBuilderService $b) => $b->updatePhase(auth()->user(), $record, $this->payload($data)), 'حُدّثت المرحلة.')),
                Tables\Actions\Action::make('deletePhase')->label('حذف')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (SeasonPhase $record) => ! $this->isReadOnly() && $record->went_live_at === null)
                    ->action(fn (SeasonPhase $record) => $this->run(fn (SeasonBuilderService $b) => $b->deletePhase(auth()->user(), $record), 'حُذفت المرحلة.')),
            ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function payload(array $data): array
    {
        $accent = $data['accent'] ?? null;
        unset($data['accent']);

        return $data + ['theme' => $accent ? ['accent' => $accent] : []];
    }

    protected function run(\Closure $call, string $ok): void
    {
        try {
            $call(app(SeasonBuilderService::class), $this->getOwnerRecord());
            Notification::make()->success()->title($ok)->send();
        } catch (SeasonException $e) {
            Notification::make()->danger()->title($e->getMessage())->body($e->details === [] ? null : implode(' • ', $e->details))->send();
        }
    }
}

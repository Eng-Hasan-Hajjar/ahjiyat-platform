<?php

namespace App\Filament\Resources\MissionPoolResource\RelationManagers;

use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionPoolService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * E34 - مهام المجموعة. العضوية تُعدَّل **فقط** عبر MissionPoolService (نوع الفترة يجب أن يطابق + تدقيق + تفريغ الكاش)، لا attach/detach الافتراضيين.
 * المهمة المنتمية لمجموعة لا تظهر لأي لاعب لم تُسند له (QuestService::isVisibleTo) - فضمّ مهمة عامة قائمة لمجموعة يحوّلها لمهمة مُسنَدة للفترات القادمة.
 */
class QuestsRelationManager extends RelationManager
{
    protected static string $relationship = 'quests';

    protected static ?string $title = 'مهام المجموعة';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('liveops.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('liveops.manage') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('المهمة')->description(fn (QuestDefinition $r) => $r->internal_key),
                Tables\Columns\TextColumn::make('target_value')->label('الهدف'),
                Tables\Columns\TextColumn::make('reward_currency_amount')->label('جواهر/عملة')->placeholder('—'),
                Tables\Columns\TextColumn::make('xp_reward')->label('XP'),
                Tables\Columns\IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addQuest')->label('إضافة مهمة')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->form(fn () => [
                        Forms\Components\Select::make('quest_id')->label('المهمة')->required()->searchable()->native(false)
                            ->options(function () {
                                /** @var MissionPool $pool */
                                $pool = $this->getOwnerRecord();

                                return QuestDefinition::query()->where('period_type', $pool->period_type)
                                    ->whereNotIn('id', $pool->quests()->pluck('quest_definitions.id'))
                                    ->orderBy('name')->limit(100)->pluck('name', 'id');
                            }),
                    ])
                    ->action(function (array $data) {
                        $this->run(fn (MissionPoolService $s, MissionPool $pool) => $s->attach(auth()->user(), $pool, QuestDefinition::query()->findOrFail((int) $data['quest_id'])), 'أُضيفت المهمة للمجموعة.');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('removeQuest')->label('إزالة')->color('danger')->requiresConfirmation()
                    ->modalDescription('إن لم تكن المهمة عضوًا في مجموعة أخرى فستصبح مهمة عامة ظاهرة لكل اللاعبين ما لم تعطّلها من صفحة المهام. لقطات الإسناد السابقة لا تتغير.')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->action(function (QuestDefinition $record) {
                        $this->run(fn (MissionPoolService $s, MissionPool $pool) => $s->detach(auth()->user(), $pool, $record), 'أُزيلت المهمة من المجموعة.');
                    }),
            ]);
    }

    protected function run(\Closure $call, string $ok): void
    {
        try {
            $call(app(MissionPoolService::class), $this->getOwnerRecord());
            Notification::make()->success()->title($ok)->send();
        } catch (LiveOpsException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}

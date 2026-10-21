<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MissionPoolResource\Pages;
use App\Filament\Resources\MissionPoolResource\RelationManagers\QuestsRelationManager;
use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionEligibilityRule;
use App\Services\LiveOps\MissionPoolService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E34 - مجموعات المهام. الإنشاء/التعديل/التفعيل/العضوية كلها عبر MissionPoolService (liveops.manage + تدقيق + حد معدّل + تفريغ كاش).
 * المجموعة تحدّد **من يرى أي مهمة** فقط؛ المكافآت وتقدّم اللاعب يبقيان في تعريف المهمة/QuestService. لا حذف: التعطيل يحفظ تاريخ الإسناد.
 * تُنشأ مُعطَّلة؛ التفعيل يتطلب مهامًا، ويُرفض فقط إن تجاوز الحدّ الأعلى النظري للمهام المتزامنة الحدّ الصلب.
 */
class MissionPoolResource extends Resource
{
    protected static ?string $model = MissionPool::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'مجموعات المهام';

    protected static ?string $modelLabel = 'مجموعة مهام';

    protected static ?string $pluralModelLabel = 'مجموعات المهام';

    protected static ?int $navigationSort = 65;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function periodLabel(string $p): string
    {
        return $p === QuestDefinition::PERIOD_WEEKLY ? 'أسبوعية' : 'يومية';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('المجموعة')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('الاسم')->required()->maxLength(120),
                Forms\Components\TextInput::make('slug')->label('المعرّف')->required()->maxLength(64)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->helperText('أحرف لاتينية صغيرة وأرقام وشرطات.')->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Textarea::make('description')->label('الوصف (داخلي)')->maxLength(1000)->columnSpanFull(),
                Forms\Components\Select::make('period_type')->label('نوع الفترة')->native(false)->required()
                    ->options([QuestDefinition::PERIOD_DAILY => 'يومية', QuestDefinition::PERIOD_WEEKLY => 'أسبوعية'])
                    ->disabled(fn (?MissionPool $record) => $record !== null)
                    ->dehydrated()
                    ->helperText('لا يتغيّر بعد الإنشاء (يجب أن تطابق فترة كل مهام المجموعة).'),
                Forms\Components\TextInput::make('selection_count')->label('عدد المهام المُسنَدة لكل لاعب')->numeric()->required()
                    ->minValue(1)->maxValue((int) config('liveops.pools.max_selection', 10))->default(3),
            ]),
            Forms\Components\Section::make('الإسناد')->columns(2)->schema([
                Forms\Components\Select::make('eligibility_rule')->label('أهلية اللاعب')->native(false)->required()
                    ->options(MissionEligibilityRule::options())->default(MissionEligibilityRule::All->value),
                Forms\Components\TextInput::make('priority')->label('الأولوية (الأصغر أولًا)')->numeric()->minValue(0)->maxValue(65535)->default(100)
                    ->helperText('أول مجموعة مؤهِّلة للاعب في هذه الفترة هي التي تُسند له.'),
                Forms\Components\Toggle::make('personalized')->label('تفضيل شفاف (فئات حديثة / تقدّم مفتوح / نشاط حملة)')
                    ->helperText('قواعد معلنة فقط؛ لا تعلّم آلة ولا بيانات خاصة.')->columnSpanFull(),
                Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ (اختياري)'),
                Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي (اختياري)')->after('starts_at'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('quests'))
            ->defaultSort('priority')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('المجموعة')->searchable()->limit(40)->description(fn (MissionPool $r) => $r->slug),
                Tables\Columns\TextColumn::make('period_type')->label('الفترة')->badge()->formatStateUsing(fn ($state) => self::periodLabel((string) $state)),
                Tables\Columns\TextColumn::make('selection_count')->label('الاختيار')->numeric(),
                Tables\Columns\TextColumn::make('quests_count')->label('مهام')->numeric(),
                Tables\Columns\TextColumn::make('eligibility_rule')->label('الأهلية')->formatStateUsing(fn ($state) => MissionEligibilityRule::options()[$state] ?? $state),
                Tables\Columns\IconColumn::make('personalized')->label('تفضيل')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('فعّالة')->boolean(),
                Tables\Columns\TextColumn::make('priority')->label('أولوية')->numeric(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period_type')->label('الفترة')->options([QuestDefinition::PERIOD_DAILY => 'يومية', QuestDefinition::PERIOD_WEEKLY => 'أسبوعية']),
                Tables\Filters\TernaryFilter::make('is_active')->label('فعّالة'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    self::act('activate', 'تفعيل', 'success', false, fn (MissionPoolService $s, MissionPool $r) => $s->activate(auth()->user(), $r), 'فُعّلت المجموعة (تسري على الفترات القادمة للاعبين).'),
                    self::act('deactivate', 'تعطيل', 'warning', true, fn (MissionPoolService $s, MissionPool $r) => $s->deactivate(auth()->user(), $r), 'عُطّلت المجموعة (تبقى لقطات الإسناد السابقة كما هي).'),
                ]),
            ]);
    }

    protected static function act(string $name, string $label, string $color, bool $whenActive, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (MissionPool $r) => $r->is_active === $whenActive && (auth()->user()?->can('liveops.manage') ?? false))
            ->action(function (MissionPool $record) use ($call, $ok) {
                try {
                    $call(app(MissionPoolService::class), $record);
                    Notification::make()->success()->title($ok)->send();
                } catch (LiveOpsException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [QuestsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMissionPools::route('/'),
            'create' => Pages\CreateMissionPool::route('/create'),
            'edit' => Pages\EditMissionPool::route('/{record}/edit'),
        ];
    }
}

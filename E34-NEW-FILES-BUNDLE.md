# E34 — حزمة الملفات الجديدة (محتوى كامل)

> للمراجعة فقط؛ المرجع للنسخ هو مجلد `files/`.

## `app/Console/Commands/DispatchLiveOpsReminders.php`

````php
<?php

namespace App\Console\Commands;

use App\Services\LiveOps\LiveOpsReminderService;
use Illuminate\Console\Command;

/**
 * E34 - تذكيرات LiveOps (انتهاء مهمة جارية، أهداف الأسبوع، بدء منافسة للمسجَّلين، عودة بعد غياب). لا منطق هنا: القرار كله بـLiveOpsReminderService.
 * قراءة فقط + إشعارات، Idempotent، ويُجدوَل ساعيًا. غيابه لا يكسر شيئًا.
 */
class DispatchLiveOpsReminders extends Command
{
    protected $signature = 'liveops:remind';

    protected $description = 'تذكيرات LiveOps المحدودة (مهام جارية، أهداف أسبوعية، بدء منافسة، عودة). قراءة فقط، Idempotent، ساعيًا.';

    public function handle(LiveOpsReminderService $service): int
    {
        foreach ($service->run() as $name => $s) {
            $this->info($name.': '.collect($s)->map(fn ($v, $k) => "{$k}={$v}")->implode(' '));
        }

        return self::SUCCESS;
    }
}
````

## `app/Filament/Pages/LiveOpsAnalyticsPage.php`

````php
<?php

namespace App\Filament\Pages;

use App\Services\LiveOps\RetentionAnalytics;
use Filament\Pages\Page;

/**
 * E34 - تحليلات الاحتفاظ للمشرفين (liveops.view). مجاميع فقط على اللعب الرسمي الصحيح (لا الدخول)؛ لا صفوف لاعبين ولا أسماء.
 */
class LiveOpsAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'تحليلات الاحتفاظ';

    protected static ?string $title = 'تحليلات الاحتفاظ والمهام';

    protected static ?string $slug = 'liveops-analytics';

    protected static ?int $navigationSort = 67;

    protected static string $view = 'filament.pages.liveops-analytics';

    public int $days = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('liveops.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;

        return ['overview' => app(RetentionAnalytics::class)->overview($days), 'days' => $days];
    }
}
````

## `app/Filament/Pages/LiveOpsPlannerPage.php`

````php
<?php

namespace App\Filament\Pages;

use App\Services\LiveOps\LiveOpsPlannerService;
use Filament\Pages\Page;

/**
 * E34 - مخطِّط LiveOps للمشرفين (liveops.view): قراءة فقط. جدول الأحداث القادمة/الجارية (من التقويم نفسه)، المهام الفعّالة، المجموعات،
 * وتحذيرات تعارض/إفراط. لا يغيّر شيئًا ولا يخزّن؛ التعديل من مجموعات المهام/المهام/الحملات كما هي. لا بيانات لاعبين.
 */
class LiveOpsPlannerPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'مخطِّط LiveOps';

    protected static ?string $title = 'مخطِّط LiveOps';

    protected static ?string $slug = 'liveops-planner';

    protected static ?int $navigationSort = 66;

    protected static string $view = 'filament.pages.liveops-planner';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('liveops.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(LiveOpsPlannerService::class)->overview();
    }
}
````

## `app/Filament/Resources/MissionPoolResource.php`

````php
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
````

## `app/Filament/Resources/MissionPoolResource/Pages/CreateMissionPool.php`

````php
<?php

namespace App\Filament\Resources\MissionPoolResource\Pages;

use App\Filament\Resources\MissionPoolResource;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionPoolService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMissionPool extends CreateRecord
{
    protected static string $resource = MissionPoolResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(MissionPoolService::class)->create(auth()->user(), $data);
        } catch (LiveOpsException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
````

## `app/Filament/Resources/MissionPoolResource/Pages/EditMissionPool.php`

````php
<?php

namespace App\Filament\Resources\MissionPoolResource\Pages;

use App\Filament\Resources\MissionPoolResource;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionPoolService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMissionPool extends EditRecord
{
    protected static string $resource = MissionPoolResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(MissionPoolService::class)->update(auth()->user(), $record, $data);
        } catch (LiveOpsException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
````

## `app/Filament/Resources/MissionPoolResource/Pages/ListMissionPools.php`

````php
<?php

namespace App\Filament\Resources\MissionPoolResource\Pages;

use App\Filament\Resources\MissionPoolResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMissionPools extends ListRecords
{
    protected static string $resource = MissionPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
````

## `app/Filament/Resources/MissionPoolResource/RelationManagers/QuestsRelationManager.php`

````php
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
````

## `app/Http/Controllers/EventCalendarController.php`

````php
<?php

namespace App\Http\Controllers;

use App\Services\LiveOps\Calendar\EventCalendarService;
use Illuminate\Http\Request;

/**
 * E34 - تقويم الأحداث العام: الآن / اليوم / هذا الأسبوع / قريبًا. قراءة فقط. للضيف لقطة عامة مشتركة بلا أي استعلام مستخدم؛ للمسجَّل
 * قسم "أجندتي" المحدود. لا مبالغ رعاية ولا بيانات خاصة في أي عنصر.
 */
class EventCalendarController extends Controller
{
    public function __construct(protected EventCalendarService $calendar) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();

        return view('events.index', [
            'sections' => $this->calendar->sections(),
            'agenda' => $user ? $this->calendar->agendaFor($user) : collect(),
            'timezone' => $this->calendar->timezone(),
        ]);
    }
}
````

## `app/Models/MissionPool.php`

````php
<?php

namespace App\Models;

use App\Services\LiveOps\MissionEligibilityRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E34 - مجموعة مهام (إعدادات فقط). ليست محرك مهام: قائمة اختيار فوق QuestDefinition.
 * كل تغيير حالة/عضوية يمر بـMissionPoolService (تدقيق + تفريغ الكاش). الإسناد الفعلي للمستخدمين بـMissionAssignmentService.
 */
class MissionPool extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $pool) {
            $pool->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'selection_count' => 'integer',
            'priority' => 'integer',
            'personalized' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function quests(): BelongsToMany
    {
        return $this->belongsToMany(QuestDefinition::class, 'mission_pool_quests', 'mission_pool_id', 'quest_definition_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('mission_pool_quests.sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserMissionAssignment::class, 'mission_pool_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rule(): MissionEligibilityRule
    {
        return MissionEligibilityRule::tryFrom((string) $this->eligibility_rule) ?? MissionEligibilityRule::All;
    }

    /** ضمن نافذتها الزمنية الحقيقية الآن (لا تفحص is_active). */
    public function isWithinWindow(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        return $this->ends_at === null || ! $at->gt($this->ends_at);
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
````

## `app/Models/UserMissionAssignment.php`

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E34 - لقطة إسناد مهمة لمستخدم في فترة (تحدّد الظهور فقط). تُكتب حصرًا عبر MissionAssignmentService.
 * لا واجهة تكتبها ولا حقل يأتي من العميل.
 */
class UserMissionAssignment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(MissionPool::class, 'mission_pool_id');
    }

    public function questDefinition(): BelongsTo
    {
        return $this->belongsTo(QuestDefinition::class);
    }
}
````

## `app/Policies/MissionPoolPolicy.php`

````php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * E34 - مجموعات المهام: العرض liveops.view، الإنشاء/التعديل liveops.manage. بلا حذف عبر الواجهة
 * (الحذف الوحيد المسموح هو بالخدمة لمجموعة بلا سجل إسناد؛ التعطيل هو المسار الاعتيادي ويحفظ التاريخ).
 */
class MissionPoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('liveops.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('liveops.view');
    }

    public function create(User $user): bool
    {
        return $user->can('liveops.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('liveops.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
````

## `app/Services/LiveOps/Calendar/EventCalendarService.php`

````php
<?php

namespace App\Services\LiveOps\Calendar;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\SponsorshipProgram;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\CampaignProgressPairs;
use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * E34 - تقويم الأحداث: **تجميع** لمصادر موجودة (مواسم، حملات، منافسات، بطولات فرق، حملات دعوة حيّة، برامج رعاية نشطة) بلا نسخ بياناتها
 * ولا جدول أحداث جديد؛ المصدر يبقى سلطة حالته وتواريخه.
 *
 *  - لقطة عامة مشتركة (مصفوفات خفيفة بلا بيانات مستخدم) بكاش قصير؛ الحالة (قادم/جارٍ/ينتهي قريبًا/انتهى) **تُحسب وقت القراءة** من التواريخ فلا تتأخر.
 *  - توقيت موحَّد: المنطقة الزمنية للمنصة (نفس QuestPeriodService). الأسبوع يبدأ الاثنين كالمهام الأسبوعية.
 *  - كل مصدر محدود (per_source_limit) ومقيَّد زمنيًا (لا انتهى، لا أبعد من upcoming_days) → لا استعلام بلا حد.
 *  - "أجندتي": مرتبطة بالمستخدم وبحدّ أقصى صغير؛ لا تكتب شيئًا. لا مزامنة تقويم خارجي.
 *  - لا مبالغ رعاية ولا بيانات خاصة في أي عنصر: عنوان ورابط وتواريخ فقط. العناوين تُهرَّب عند العرض (Blade).
 */
class EventCalendarService
{
    public const CACHE_KEY = 'liveops:calendar:v1';

    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDING_SOON = 'ending_soon';
    public const STATUS_ENDED = 'ended';

    public const TYPE_SEASON = 'season';
    public const TYPE_CAMPAIGN = 'campaign';
    public const TYPE_COMPETITIVE = 'competitive_event';
    public const TYPE_TEAM_CHAMPIONSHIP = 'team_championship';
    public const TYPE_REFERRAL = 'referral_campaign';
    public const TYPE_SPONSORSHIP = 'sponsorship_program';

    public function __construct(protected QuestPeriodService $periods) {}

    public function timezone(): string
    {
        return $this->periods->timezone();
    }

    public function now(): Carbon
    {
        return now()->setTimezone($this->timezone());
    }

    /** حالة مشتقة من تواريخ حقيقية فقط. بلا تواريخ = جارٍ دائم (بلا عدّ تنازلي). */
    public function statusOf(?Carbon $startsAt, ?Carbon $endsAt, ?Carbon $now = null): string
    {
        $now ??= $this->now();

        if ($startsAt !== null && $now->lt($startsAt)) {
            return self::STATUS_UPCOMING;
        }

        if ($endsAt !== null) {
            if ($now->gt($endsAt)) {
                return self::STATUS_ENDED;
            }

            if ($now->diffInSeconds($endsAt, false) <= max(1, (int) config('liveops.calendar.ending_soon_hours', 24)) * 3600) {
                return self::STATUS_ENDING_SOON;
            }
        }

        return self::STATUS_LIVE;
    }

    // ───────────────────────── اللقطة المشتركة ─────────────────────────

    /** @return list<array<string, mixed>> */
    public function snapshot(): array
    {
        $seconds = max(0, (int) config('liveops.calendar.cache_seconds', 60));

        return $seconds === 0 ? $this->build() : Cache::remember(self::CACHE_KEY, $seconds, fn () => $this->build());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array<string, mixed>> */
    protected function build(): array
    {
        $now = now();
        $horizon = $now->copy()->addDays(max(1, (int) config('liveops.calendar.upcoming_days', 60)));
        $limit = max(1, (int) config('liveops.calendar.per_source_limit', 30));
        $rows = [];

        // المواسم المنشورة (خطها الزمني = خط حملتها)
        Season::query()->where('is_published', true)
            ->whereHas('campaign', fn ($q) => $q->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $horizon)))
            ->with('campaign:id,title,starts_at,ends_at')
            ->orderBy('id')->limit($limit)->get()
            ->each(function (Season $s) use (&$rows) {
                $rows[] = $this->row(self::TYPE_SEASON, $s->id, $s->campaign?->title ?? $s->code, $s->campaign?->starts_at, $s->campaign?->ends_at, 'seasons.show', ['season' => $s->slug], 'موسم', $s->banner_image, $s->campaign_id);
            });

        // الحملات المستقلة (غير المرتبطة بموسم منشور؛ المرتبطة تظهر عبر موسمها بلا تكرار)
        Campaign::query()->where('is_active', true)
            ->whereDoesntHave('season', fn ($q) => $q->where('is_published', true))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $horizon))
            ->orderBy('id')->limit($limit)
            ->get(['id', 'slug', 'title', 'cover_image', 'starts_at', 'ends_at'])
            ->each(function (Campaign $c) use (&$rows) {
                $rows[] = $this->row(self::TYPE_CAMPAIGN, $c->id, $c->title, $c->starts_at, $c->ends_at, 'campaigns.show', ['campaign' => $c->slug], 'حملة', $c->cover_image, $c->id);
            });

        // المنافسات الرسمية المنشورة (القادمة/الجارية فقط)
        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)
            ->where('ends_at', '>=', $now)->where('starts_at', '<=', $horizon)
            ->orderBy('starts_at')->limit($limit)
            ->get(['id', 'slug', 'title', 'starts_at', 'ends_at'])
            ->each(function (CompetitiveEvent $e) use (&$rows) {
                $rows[] = $this->row(self::TYPE_COMPETITIVE, $e->id, $e->title, $e->starts_at, $e->ends_at, 'competitions.show', ['event' => $e->slug], 'منافسة');
            });

        TeamChampionship::query()->where('status', TeamChampionship::STATUS_PUBLISHED)
            ->where('ends_at', '>=', $now)->where('starts_at', '<=', $horizon)
            ->orderBy('starts_at')->limit($limit)
            ->get(['id', 'slug', 'title', 'starts_at', 'ends_at'])
            ->each(function (TeamChampionship $t) use (&$rows) {
                $rows[] = $this->row(self::TYPE_TEAM_CHAMPIONSHIP, $t->id, $t->title, $t->starts_at, $t->ends_at, 'team-championships.show', ['championship' => $t->slug], 'بطولة فرق');
            });

        // حملات الدعوة الحيّة والعلنية (الصفحة خلف الدخول؛ لا أرقام ولا مكافآت هنا)
        if ((bool) config('referrals.enabled', true)) {
            ReferralCampaign::query()->live()->where('is_public', true)
                ->orderBy('id')->limit($limit)
                ->get(['id', 'name', 'starts_at', 'ends_at'])
                ->each(function (ReferralCampaign $r) use (&$rows) {
                    $rows[] = $this->row(self::TYPE_REFERRAL, $r->id, $r->name, $r->starts_at, $r->ends_at, 'referrals.index', [], 'دعوة الأصدقاء');
                });
        }

        // برامج الرعاية النشطة (عنوان وتواريخ فقط؛ لا مبالغ ولا أهداف تمويل)
        if ((bool) config('sponsorship.enabled', true)) {
            SponsorshipProgram::query()->activeNow()
                ->orderBy('id')->limit($limit)
                ->get(['id', 'slug', 'title', 'starts_at', 'ends_at'])
                ->each(function (SponsorshipProgram $p) use (&$rows) {
                    $rows[] = $this->row(self::TYPE_SPONSORSHIP, $p->id, $p->title, $p->starts_at, $p->ends_at, 'supporters.index', ['scope' => 'program', 'program' => $p->slug], 'برنامج رعاية');
                });
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function row(string $type, int $id, string $title, ?\DateTimeInterface $starts, ?\DateTimeInterface $ends, string $route, array $params, string $badge, ?string $image = null, ?int $campaignId = null): array
    {
        return [
            'type' => $type,
            'key' => $type.':'.$id,
            'ref' => $id,
            'campaign_id' => $campaignId,
            'title' => $title,
            'starts_at' => $starts ? Carbon::instance($starts)->toIso8601String() : null,
            'ends_at' => $ends ? Carbon::instance($ends)->toIso8601String() : null,
            'route' => $route,
            'params' => $params,
            'badge' => $badge,
            'image' => $image,
        ];
    }

    // ───────────────────────── القراءة (الحالة تُحسب هنا) ─────────────────────────

    /**
     * كل العناصر غير المنتهية، مرتبة: الجارية (الأقرب انتهاءً أولًا) ثم القادمة (الأقرب بدءًا).
     *
     * @return Collection<int, EventItem>
     */
    public function items(?Carbon $now = null): Collection
    {
        $now = ($now ?? $this->now())->copy()->setTimezone($this->timezone());
        $tz = $this->timezone();

        return collect($this->snapshot())
            ->filter(fn (array $r) => Route::has($r['route']))
            ->map(function (array $r) use ($now, $tz) {
                $starts = $r['starts_at'] !== null ? Carbon::parse($r['starts_at'])->setTimezone($tz) : null;
                $ends = $r['ends_at'] !== null ? Carbon::parse($r['ends_at'])->setTimezone($tz) : null;

                return new EventItem(
                    $r['type'], $r['key'], (string) $r['title'], $starts, $ends,
                    $this->statusOf($starts, $ends, $now),
                    route($r['route'], $r['params']),
                    (string) $r['badge'],
                    $r['image'] ?: null,
                    $r['ref'] ?? null,
                    $r['campaign_id'] ?? null,
                );
            })
            ->reject(fn (EventItem $i) => $i->status === self::STATUS_ENDED)
            ->sort(function (EventItem $a, EventItem $b) {
                $rank = fn (EventItem $i) => $i->status === self::STATUS_UPCOMING ? 1 : 0;

                return [$rank($a), ($rank($a) === 1 ? $a->startsAt : $a->endsAt)?->getTimestamp() ?? PHP_INT_MAX, $a->key]
                    <=> [$rank($b), ($rank($b) === 1 ? $b->startsAt : $b->endsAt)?->getTimestamp() ?? PHP_INT_MAX, $b->key];
            })
            ->values();
    }

    /**
     * الأقسام الأربعة: الآن / اليوم / هذا الأسبوع / قريبًا. كل عنصر قادم يظهر بقسم واحد فقط.
     *
     * @return array{now: Collection<int,EventItem>, today: Collection<int,EventItem>, week: Collection<int,EventItem>, upcoming: Collection<int,EventItem>}
     */
    public function sections(?Carbon $now = null): array
    {
        $now = ($now ?? $this->now())->copy()->setTimezone($this->timezone());
        $items = $this->items($now);
        $endOfToday = $now->copy()->endOfDay();
        $endOfWeek = $now->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        $live = $items->filter(fn (EventItem $i) => $i->status !== self::STATUS_UPCOMING)->values();
        $upcoming = $items->filter(fn (EventItem $i) => $i->status === self::STATUS_UPCOMING);

        return [
            'now' => $live,
            'today' => $upcoming->filter(fn (EventItem $i) => $i->startsAt->lte($endOfToday))->values(),
            'week' => $upcoming->filter(fn (EventItem $i) => $i->startsAt->gt($endOfToday) && $i->startsAt->lte($endOfWeek))->values(),
            'upcoming' => $upcoming->filter(fn (EventItem $i) => $i->startsAt->gt($endOfWeek))->values(),
        ];
    }

    /**
     * أجندتي: ما يخص المستخدم فعلًا (منافسة مسجَّل بها، حملة/موسم له تقدّم فيه) + أي عنصر ينتهي قريبًا من تلك المجموعة. بحدّ أقصى صغير، قراءة فقط.
     *
     * @return Collection<int, EventItem>
     */
    public function agendaFor(User $user, ?Carbon $now = null): Collection
    {
        $items = $this->items($now);

        if ($items->isEmpty()) {
            return collect();
        }

        $registered = $items->contains(fn (EventItem $i) => $i->type === self::TYPE_COMPETITIVE)
            ? CompetitiveEventParticipant::query()->where('user_id', $user->id)->pluck('competitive_event_id')->map(fn ($v) => (int) $v)->all()
            : [];

        $campaigns = $items->contains(fn (EventItem $i) => in_array($i->type, [self::TYPE_SEASON, self::TYPE_CAMPAIGN], true))
            ? CampaignProgressPairs::campaignsByUser([$user->id])->get($user->id, collect())->map(fn ($v) => (int) $v)->all()
            : [];

        return $items
            ->filter(fn (EventItem $i) => match ($i->type) {
                self::TYPE_COMPETITIVE => in_array($i->ref, $registered, true),
                self::TYPE_SEASON, self::TYPE_CAMPAIGN => $i->campaignId !== null && in_array($i->campaignId, $campaigns, true),
                default => false,
            })
            ->take(max(1, (int) config('liveops.calendar.agenda_max', 5)))
            ->values();
    }
}
````

## `app/Services/LiveOps/Calendar/EventItem.php`

````php
<?php

namespace App\Services\LiveOps\Calendar;

use Illuminate\Support\Carbon;

/**
 * E34 - عنصر تقويم موحَّد (DTO للقراءة فقط). لا يخزّن بيانات مصدره ولا يكرّرها: هو إسقاط لحدث موجود (موسم/حملة/منافسة/بطولة/دعوة/رعاية).
 * الحالة والعدّ التنازلي مشتقان خادميًا من تواريخ حقيقية فقط؛ لا عدّاد بلا تاريخ فعلي ولا استعجال مصطنع.
 */
final class EventItem
{
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly string $title,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $endsAt,
        public readonly string $status,
        public readonly string $url,
        public readonly string $badge,
        public readonly ?string $image = null,
        public readonly ?int $ref = null,
        public readonly ?int $campaignId = null,
    ) {}

    /** التاريخ الحقيقي الذي يُعدّ تنازليًا إليه (البدء للقادم، الانتهاء للجاري إن وُجد)، وإلا null (لا عدّاد). */
    public function countdownTo(): ?Carbon
    {
        return match ($this->status) {
            EventCalendarService::STATUS_UPCOMING => $this->startsAt,
            EventCalendarService::STATUS_LIVE, EventCalendarService::STATUS_ENDING_SOON => $this->endsAt,
            default => null,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            EventCalendarService::STATUS_UPCOMING => 'قريبًا',
            EventCalendarService::STATUS_LIVE => 'جارٍ الآن',
            EventCalendarService::STATUS_ENDING_SOON => 'ينتهي قريبًا',
            default => 'انتهى',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'status' => $this->status,
            'url' => $this->url,
            'badge' => $this->badge,
            'image' => $this->image,
        ];
    }
}
````

## `app/Services/LiveOps/ComebackService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\Campaign;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\Season;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Carbon;

/**
 * E34 - رحلة العودة (Comeback). مشتقة بالكامل من **اللعب الرسمي الموثوق** (آخر يوم نشاط مؤهِّل بـPlayerStreak.last_active_date)
 * لا من تسجيل الدخول: فتح الموقع وحده لا يُنهي الحالة ولا يُنشئها.
 *
 * لا حالة مخزَّنة ولا جدول: تزول الحالة تلقائيًا عند أول حل رسمي صحيح (StreakService يحدّث last_active_date)،
 * فلا تظهر بعد استئناف فعلي. الافتراضي: **لا مكافأة جواهر للعودة** (لا مفتاح لها في config).
 * لا عقوبة ولا لغة ضغط: رسالة ترحيب ومقترحات سهلة فقط.
 */
class ComebackService
{
    public function __construct(protected QuestPeriodService $periods) {}

    public static function minInactiveDays(): int
    {
        return max(1, (int) config('liveops.comeback.min_inactive_days', 7));
    }

    /** غياب ≥ $minDays أيام عن آخر يوم لعب رسمي (بالتاريخ المحلي الموحَّد للمنصة). بلا تاريخ لعب = لاعب جديد لا عائد. */
    public static function isInactiveFor(?string $lastActiveDate, int $minDays): bool
    {
        $days = self::daysSince($lastActiveDate);

        return $days !== null && $days >= max(1, $minDays);
    }

    public static function daysSince(?string $lastActiveDate): ?int
    {
        if ($lastActiveDate === null || $lastActiveDate === '') {
            return null;
        }

        $today = Carbon::parse(app(QuestPeriodService::class)->todayLocal())->startOfDay();
        $last = Carbon::parse($lastActiveDate)->startOfDay();

        return max(0, (int) $last->diffInDays($today));
    }

    /**
     * آخر تاريخ لعب رسمي صحيح (Y-m-d). المصدر المعتمد PlayerStreak؛ وللحسابات القديمة بلا صف سلسلة نرجع لآخر محاولة صحيحة.
     */
    public function lastQualifyingDate(User $user): ?string
    {
        $date = PlayerStreak::query()->where('user_id', $user->id)->value('last_active_date');

        if ($date !== null) {
            return Carbon::parse($date)->toDateString();
        }

        $last = PuzzleAttempt::query()->where('user_id', $user->id)->where('is_correct', true)->max('created_at');

        return $last !== null ? $this->periods->localDateFor(Carbon::parse($last)) : null;
    }

    public function inactiveDays(User $user): ?int
    {
        return self::daysSince($this->lastQualifyingDate($user));
    }

    public function isEligible(User $user): bool
    {
        return self::isInactiveFor($this->lastQualifyingDate($user), self::minInactiveDays());
    }

    /**
     * @return array{last_active_date:string, inactive_days:int, suggestions:list<array{kind:string,title:string,url:string}>}|null
     */
    public function stateFor(User $user): ?array
    {
        $last = $this->lastQualifyingDate($user);

        if (! self::isInactiveFor($last, self::minInactiveDays())) {
            return null;
        }

        return [
            'last_active_date' => $last,
            'inactive_days' => (int) self::daysSince($last),
            'suggestions' => $this->suggestions($user),
        ];
    }

    /**
     * مقترحات سهلة ومحدودة (≤ 3). المهمة المخصَّصة تضيفها طبقة مركز المهام (فهي من يعرف إسناد المستخدم).
     *
     * @return list<array{kind:string,title:string,url:string}>
     */
    public function suggestions(User $user): array
    {
        $out = [];

        $puzzle = $this->easyOfficialPuzzle($user);

        if ($puzzle !== null) {
            $out[] = ['kind' => 'puzzle', 'title' => 'أحجية سهلة للبدء: '.$puzzle->title, 'url' => route('puzzles.show', $puzzle)];
        }

        if (UserCampaignProgress::query()->where('user_id', $user->id)->exists() && Campaign::query()->where('is_active', true)->exists()) {
            $out[] = ['kind' => 'campaign', 'title' => 'أكمل حملتك من حيث توقفت', 'url' => route('campaigns.index')];
        } elseif (Season::query()->where('is_published', true)->exists()) {
            $out[] = ['kind' => 'season', 'title' => 'اطّلع على الموسم الحالي', 'url' => route('seasons.index')];
        }

        return $out;
    }

    /**
     * أحجية رسمية سهلة مفعّلة لم يحلّها المستخدم. اختيار حتمي بحسب (مستخدم، اليوم): لا random() ويثبت طوال اليوم.
     * رسمية فقط: لا أحجيات مجتمع (صفرية الاقتصاد ولا تُحسب مهامًا).
     */
    protected function easyOfficialPuzzle(User $user): ?Puzzle
    {
        $ids = Puzzle::query()->official()
            ->where('is_active', true)
            ->where('difficulty', 'easy')
            ->whereNotExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('puzzle_attempts')
                    ->whereColumn('puzzle_attempts.puzzle_id', 'puzzles.id')
                    ->where('puzzle_attempts.user_id', $user->id)
                    ->where('puzzle_attempts.is_correct', true);
            })
            ->orderBy('id')
            ->limit(25)
            ->pluck('id')->all();

        if ($ids === []) {
            return null;
        }

        usort($ids, fn (int $a, int $b) => hexdec(substr(sha1($user->id.'|'.$this->periods->todayLocal().'|'.$a), 0, 12)) <=> hexdec(substr(sha1($user->id.'|'.$this->periods->todayLocal().'|'.$b), 0, 12)));

        return Puzzle::query()->find($ids[0]);
    }
}
````

## `app/Services/LiveOps/LiveOpsException.php`

````php
<?php

namespace App\Services\LiveOps;

/** E34 - خطأ أعمال مفهوم (رسالة عربية آمنة للعرض). `reason` رمز آلي ثابت للاختبارات والواجهة؛ لا يحمل بيانات خاصة. */
class LiveOpsException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
````

## `app/Services/LiveOps/LiveOpsPlannerService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\Campaign;
use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Services\LiveOps\Calendar\EventCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * E34 - مخطِّط LiveOps (طبقة قراءة/إدارة فوق نماذج قائمة؛ لا تخزّن شيئًا ولا تغيّر شيئًا): يجمع الجدول الزمني الحالي للأحداث (من التقويم نفسه)
 * والمهام والمجموعات، ويكشف **تحذيرات** تعارض/إفراط. التحذير لا يمنع إجراءً إداريًا صالحًا؛ الحدّ الصلب الوحيد على تفعيل مجموعة
 * يرفع الحدّ الأعلى النظري لمهام المستخدم المتزامنة فوق الحدّ الصلب المضبوط (غير آمن فعلًا، رفض مفهوم السبب).
 *
 * لا نموذج مالي مخترَع: "الثقل" = مجموع مبالغ العملة المُعلَنة بتعريفات المهام (أعلى كسب نظري لمستخدم واحد في الفترة) مقارنةً بعتبات config فقط.
 * الرعاية لا تُطبَّق على المهام تلقائيًا ولا يقرأ هذا المخطِّط أي مبلغ رعاية.
 */
class LiveOpsPlannerService
{
    public function __construct(
        protected EventCalendarService $calendar,
        protected MissionAssignmentService $assignments,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $now = now();
        $quests = QuestDefinition::query()->with('rewardCurrency:id,short_name')->get();
        $pooled = array_flip($this->assignments->pooledQuestIds());
        $live = $quests->filter(fn (QuestDefinition $q) => $q->is_active && $this->withinWindow($q, $now));

        $pools = MissionPool::query()->withCount('quests')->orderBy('priority')->orderBy('id')->get();

        $potential = $this->potential($live, $pooled, $pools);

        return [
            'now' => $now,
            'timezone' => $this->calendar->timezone(),
            'events' => $this->calendar->items(),
            'quests' => [
                'daily' => $this->questSummary($live, $pooled, QuestDefinition::PERIOD_DAILY),
                'weekly' => $this->questSummary($live, $pooled, QuestDefinition::PERIOD_WEEKLY),
            ],
            'pools' => $pools,
            'potential' => $potential,
            'warnings' => $this->warnings($quests, $live, $pooled, $pools, $potential, $now),
        ];
    }

    protected function withinWindow(QuestDefinition $q, Carbon $now): bool
    {
        return ($q->starts_at === null || ! $now->lt($q->starts_at)) && ($q->ends_at === null || ! $now->gt($q->ends_at));
    }

    /** @return array{active:int, global:int, pooled:int} */
    protected function questSummary(Collection $live, array $pooled, string $type): array
    {
        $of = $live->where('period_type', $type);

        return [
            'active' => $of->count(),
            'global' => $of->reject(fn (QuestDefinition $q) => isset($pooled[$q->id]))->count(),
            'pooled' => $of->filter(fn (QuestDefinition $q) => isset($pooled[$q->id]))->count(),
        ];
    }

    /**
     * أعلى حدّ نظري لما يراه/يكسبه مستخدم واحد في الفترة: مهام عامة كلها + (لكل نوع فترة) أكبر مساهمة مجموعة (أفضل selection_count مهمة مكافأةً).
     *
     * @return array{missions:int, daily_reward:int, weekly_reward:int}
     */
    public function potential(Collection $live, array $pooled, Collection $pools): array
    {
        $reward = fn (QuestDefinition $q) => (int) ($q->reward_currency_amount ?? 0);
        $missions = 0;
        $rewards = ['daily' => 0, 'weekly' => 0];

        foreach ([QuestDefinition::PERIOD_DAILY, QuestDefinition::PERIOD_WEEKLY] as $type) {
            $global = $live->where('period_type', $type)->reject(fn (QuestDefinition $q) => isset($pooled[$q->id]));
            $missions += $global->count();
            $rewards[$type] += $global->sum($reward);

            $bestMissions = 0;
            $bestReward = 0;

            foreach ($pools->where('is_active', true)->where('period_type', $type) as $pool) {
                $members = $live->where('period_type', $type)->filter(fn (QuestDefinition $q) => $this->isMember($pool, $q));
                $top = $members->sortByDesc($reward)->take((int) $pool->selection_count);
                $bestMissions = max($bestMissions, $top->count());
                $bestReward = max($bestReward, $top->sum($reward));
            }

            $missions += $bestMissions;
            $rewards[$type] += $bestReward;
        }

        return ['missions' => $missions, 'daily_reward' => (int) $rewards['daily'], 'weekly_reward' => (int) $rewards['weekly']];
    }

    protected function isMember(MissionPool $pool, QuestDefinition $q): bool
    {
        return in_array($q->id, $this->memberIds($pool), true);
    }

    /** @var array<int, list<int>> */
    private array $memberCache = [];

    /** @return list<int> */
    protected function memberIds(MissionPool $pool): array
    {
        return $this->memberCache[$pool->id] ??= \Illuminate\Support\Facades\DB::table('mission_pool_quests')
            ->where('mission_pool_id', $pool->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * @param  array<int,int>  $pooled
     * @param  array{missions:int, daily_reward:int, weekly_reward:int}  $potential
     * @return list<array{code:string, level:string, message:string}>
     */
    protected function warnings(Collection $quests, Collection $live, array $pooled, Collection $pools, array $potential, Carbon $now): array
    {
        $out = [];
        $warn = (int) config('liveops.budget.max_concurrent_missions_warn', 12);
        $hard = (int) config('liveops.budget.max_concurrent_missions_hard', 40);

        if ($potential['missions'] > $hard) {
            $out[] = ['code' => 'missions_over_hard_cap', 'level' => 'critical', 'message' => "الحدّ الأعلى النظري لمهام المستخدم المتزامنة ({$potential['missions']}) تجاوز الحدّ الصلب ({$hard}). خفّض المهام الفعّالة أو selection_count."];
        } elseif ($potential['missions'] > $warn) {
            $out[] = ['code' => 'too_many_missions', 'level' => 'warning', 'message' => "مهام متزامنة كثيرة لمستخدم واحد ({$potential['missions']} > {$warn}). قد يرهق اللاعب؛ راجع المجموعات."];
        }

        foreach ([['daily', 'daily_gems_warn', 'اليومية', 'daily_reward'], ['weekly', 'weekly_gems_warn', 'الأسبوعية', 'weekly_reward']] as [$type, $key, $label, $field]) {
            $limit = (int) config("liveops.budget.{$key}", 0);

            if ($limit > 0 && $potential[$field] > $limit) {
                $out[] = ['code' => "reward_heavy_{$type}", 'level' => 'warning', 'message' => "مجموع مكافآت المهام {$label} (حدّ أعلى نظري لمستخدم واحد) {$potential[$field]} يتجاوز العتبة {$limit}."];
            }
        }

        foreach ($quests as $q) {
            if ($q->starts_at !== null && $q->ends_at !== null && $q->ends_at->lte($q->starts_at)) {
                $out[] = ['code' => 'invalid_window', 'level' => 'warning', 'message' => "نافذة زمنية غير صالحة للمهمة «{$q->name}» (النهاية قبل البداية)."];
            } elseif ($q->is_active && $q->ends_at !== null && $now->gt($q->ends_at)) {
                $out[] = ['code' => 'expired_but_active', 'level' => 'info', 'message' => "المهمة «{$q->name}» منتهية النافذة لكنها ما زالت مفعّلة (لا تظهر للاعبين)."];
            }
        }

        foreach ($pools->where('is_active', true) as $pool) {
            $valid = $live->where('period_type', $pool->period_type)->filter(fn (QuestDefinition $q) => $this->isMember($pool, $q))->count();

            if ($valid < (int) $pool->selection_count) {
                $out[] = ['code' => 'pool_thin', 'level' => 'info', 'message' => "المجموعة «{$pool->name}» فعّالة بـ{$valid} مهمة صالحة فقط وselection_count = {$pool->selection_count}."];
            }
        }

        // حملات مستقلة بنفس العنوان وبنوافذ متداخلة (تكرار محتمل يربك اللاعب).
        $campaigns = Campaign::query()->where('is_active', true)->get(['id', 'title', 'starts_at', 'ends_at']);

        foreach ($campaigns->groupBy(fn (Campaign $c) => mb_strtolower(trim($c->title))) as $title => $group) {
            if ($group->count() > 1 && $this->anyOverlap($group)) {
                $out[] = ['code' => 'duplicate_campaign_overlap', 'level' => 'warning', 'message' => "حملتان فعّالتان بنفس العنوان «{$group->first()->title}» بنوافذ متداخلة."];
            }
        }

        return $out;
    }

    /** @param  Collection<int, Campaign>  $group */
    protected function anyOverlap(Collection $group): bool
    {
        $items = $group->values();

        for ($i = 0; $i < $items->count(); $i++) {
            for ($j = $i + 1; $j < $items->count(); $j++) {
                $a = $items[$i];
                $b = $items[$j];
                $aStart = $a->starts_at?->getTimestamp() ?? 0;
                $bStart = $b->starts_at?->getTimestamp() ?? 0;
                $aEnd = $a->ends_at?->getTimestamp() ?? PHP_INT_MAX;
                $bEnd = $b->ends_at?->getTimestamp() ?? PHP_INT_MAX;

                if ($aStart <= $bEnd && $bStart <= $aEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * يُستدعى من MissionPoolService::activate: هل يتجاوز تفعيل هذه المجموعة الحدّ الصلب لمهام المستخدم المتزامنة؟ (الاستثناء الوحيد "غير الآمن").
     */
    public function wouldExceedHardCap(MissionPool $pool): bool
    {
        $hard = (int) config('liveops.budget.max_concurrent_missions_hard', 40);
        $quests = QuestDefinition::query()->get();
        $now = now();
        $pooled = array_flip($this->assignments->pooledQuestIds());
        $live = $quests->filter(fn (QuestDefinition $q) => $q->is_active && $this->withinWindow($q, $now));
        $pools = MissionPool::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $pool->id))->get()
            ->each(fn (MissionPool $p) => $p->is_active = true);

        return $this->potential($live, $pooled, $pools)['missions'] > $hard;
    }
}
````

## `app/Services/LiveOps/LiveOpsReminderService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\ReEngagementPolicy;
use App\Services\PlatformSettingsService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * E34 - تذكيرات LiveOps (يشغّلها المجدوِل الساعي فقط). تعيد استعمال E15 كاملًا: NotificationDispatcher (تفضيلات الفئة + المفتاح الشامل) وميزانية التذكيرات.
 *
 * ثوابت حاكمة (نفس فلسفة ReEngagementService/LifecycleReminderService):
 *  - **قراءة فقط من حالة اللعب**: لا تُنشئ UserQuestProgress ولا تستدعي syncCurrentQuests ولا تعدّل سلسلة ولا تمنح شيئًا؛ تكتب إشعارات فقط.
 *  - Idempotent بمفتاح دلالي لكل (مستخدم + موضوع/فترة): تشغيل الأمر 10 مرات = إشعار واحد.
 *  - لا User::all(): جمهور كل نوع مبنيّ من سجلات الحدث/النشاط نفسها بدفعات (chunk/chunkById) ثم تحميل مستخدمي الدفعة باستعلام واحد.
 *  - لا إزعاج: سقف يومي خاص بـLiveOps (config) **و**ميزانية E15 (max_reengagement_per_day) كلاهما يُحترَم؛ والأنواع الأربعة ليست "تذكيرات استعادية" إلحاحية.
 *  - الصحة لا تعتمد عليه: غياب المجدوِل = لا إشعارات فقط. فشل إشعار واحد لا يوقف بقية المستخدمين.
 *  - الافتراضيات متحفظة: العودة والأسبوعية متوقفتان؛ انتهاء المهمة وبدء الحدث مفعّلان (config/liveops.php).
 */
class LiveOpsReminderService
{
    public function __construct(
        protected NotificationDispatcher $dispatcher,
        protected ReEngagementPolicy $policy,
        protected QuestPeriodService $periods,
        protected PlatformSettingsService $settings,
        protected ComebackService $comeback,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function run(): array
    {
        return [
            'mission_expiring' => $this->missionExpiring(),
            'weekly_missions' => $this->weeklyMissions(),
            'event_starting' => $this->eventStarting(),
            'comeback' => $this->comeback(),
        ];
    }

    /** @return array<string, int|string> */
    protected function stats(): array
    {
        return ['candidates' => 0, 'ineligible' => 0, 'already' => 0, 'over_budget' => 0, 'created' => 0, 'suppressed' => 0, 'failed' => 0];
    }

    protected function enabled(string $flag): bool
    {
        return (bool) config("liveops.notifications.{$flag}", false) && (bool) $this->settings->get('notifications', 'notifications_enabled', true);
    }

    protected function chunk(): int
    {
        return max(1, (int) config('liveops.notifications.chunk_size', 200));
    }

    // ───────────────────────── 1) مهام جارية تنتهي مع نهاية اليوم ─────────────────────────

    /**
     * مستخدمون لديهم مهمة يومية **بدأوها فعلًا** (تقدّم > 0) ولم يكملوها، في الساعات الأخيرة من اليوم المحلي. لا إشعار لمن لم يبدأ شيئًا.
     *
     * @return array<string, int|string>
     */
    public function missionExpiring(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('mission_expiring')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $ctx = $this->periods->dailyContext();
        $hours = max(1, (int) config('liveops.notifications.mission_expiring_hours_before_end', 4));

        if (now()->diffInSeconds($ctx->end, false) > $hours * 3600) {
            return $stats + ['skipped' => 'before_window'];
        }

        $now = now();

        UserQuestProgress::query()
            ->join('quest_definitions as q', 'q.id', '=', 'user_quest_progress.quest_definition_id')
            ->where('user_quest_progress.period_key', $ctx->periodKey)
            ->whereNull('user_quest_progress.completed_at')
            ->where('user_quest_progress.current_value', '>', 0)
            ->where('q.is_active', true)->where('q.period_type', QuestDefinition::PERIOD_DAILY)
            ->where(fn ($w) => $w->whereNull('q.ends_at')->orWhere('q.ends_at', '>', $now))
            ->selectRaw('user_quest_progress.user_id as user_id, count(*) as in_progress')
            ->groupBy('user_quest_progress.user_id')
            ->orderBy('user_id')
            ->chunk($this->chunk(), function ($rows) use (&$stats, $ctx) {
                $counts = $rows->pluck('in_progress', 'user_id')->map(fn ($n) => (int) $n)->all();

                $this->deliver(array_keys($counts), $stats, NotificationType::MissionExpiring,
                    fn (User $u) => "mission-expiring:{$u->id}:{$ctx->periodKey}",
                    fn (User $u) => ['count' => $counts[$u->id] ?? 1],
                );
            });

        return $stats;
    }

    // ───────────────────────── 2) أهداف الأسبوع الجديدة (الاثنين، لنشطي اللعب مؤخرًا) ─────────────────────────

    /** @return array<string, int|string> */
    public function weeklyMissions(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('weekly_missions_available')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $local = now()->setTimezone($this->periods->timezone());

        if ($local->dayOfWeekIso !== 1) {
            return $stats + ['skipped' => 'not_week_start'];
        }

        if (! QuestDefinition::query()->where('is_active', true)->where('period_type', QuestDefinition::PERIOD_WEEKLY)->exists()) {
            return $stats + ['skipped' => 'no_weekly_missions'];
        }

        $weekKey = $this->periods->weeklyContext()->periodKey;
        $since = Carbon::parse($this->periods->todayLocal())->subDays(max(1, (int) config('player_notifications.recent_activity_days', 7)))->toDateString();

        PlayerStreak::query()->where('last_active_date', '>=', $since)->select('id', 'user_id')
            ->chunkById($this->chunk(), function ($rows) use (&$stats, $weekKey) {
                $this->deliver($rows->pluck('user_id')->all(), $stats, NotificationType::WeeklyMissionsAvailable,
                    fn (User $u) => "weekly-missions:{$u->id}:{$weekKey}",
                    fn (User $u) => [],
                );
            });

        return $stats;
    }

    // ───────────────────────── 3) بدء منافسة قريبًا (للمسجَّلين فقط) ─────────────────────────

    /** @return array<string, int|string> */
    public function eventStarting(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('event_starting_soon')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $now = now();
        $window = max(1, (int) config('liveops.notifications.event_starting_window_hours', 24));

        CompetitiveEvent::query()
            ->where('status', CompetitiveEvent::STATUS_PUBLISHED)
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addHours($window))
            ->orderBy('id')
            ->chunkById(50, function ($events) use (&$stats, $now) {
                foreach ($events as $event) {
                    $hours = max(1, (int) ceil($now->diffInMinutes($event->starts_at, false) / 60));

                    CompetitiveEventParticipant::query()
                        ->where('competitive_event_id', $event->id)->where('status', 'registered')
                        ->select('id', 'user_id')
                        ->chunkById($this->chunk(), function ($rows) use (&$stats, $event, $hours) {
                            $this->deliver($rows->pluck('user_id')->all(), $stats, NotificationType::EventStartingSoon,
                                fn (User $u) => "event-starting-soon:{$u->id}:{$event->id}",
                                fn (User $u) => ['title' => $event->title, 'hours' => $hours],
                                ['competitive_event_id' => $event->id], ['event' => $event->slug], 'competitions.show',
                            );
                        });
                }
            });

        return $stats;
    }

    // ───────────────────────── 4) العودة بعد غياب (مرة لكل فترة غياب) ─────────────────────────

    /** @return array<string, int|string> */
    public function comeback(): array
    {
        $stats = $this->stats();

        if (! $this->enabled('comeback_available')) {
            return $stats + ['skipped' => 'disabled'];
        }

        $today = Carbon::parse($this->periods->todayLocal());
        $newest = $today->copy()->subDays(ComebackService::minInactiveDays())->toDateString();
        $oldest = $today->copy()->subDays(max(ComebackService::minInactiveDays(), (int) config('liveops.comeback.notify_max_days', 45)))->toDateString();

        PlayerStreak::query()
            ->whereNotNull('last_active_date')->where('last_active_date', '<=', $newest)->where('last_active_date', '>=', $oldest)
            ->select('id', 'user_id', 'last_active_date')
            ->chunkById($this->chunk(), function ($rows) use (&$stats) {
                $dates = $rows->mapWithKeys(fn ($r) => [(int) $r->user_id => Carbon::parse($r->last_active_date)->toDateString()])->all();

                $this->deliver(array_keys($dates), $stats, NotificationType::ComebackAvailable,
                    fn (User $u) => "comeback:{$u->id}:{$dates[$u->id]}",
                    fn (User $u) => [],
                );
            });

        return $stats;
    }

    // ───────────────────────── الإرسال المشترك (ميزانية + تكرار + عزل أخطاء) ─────────────────────────

    /**
     * @param  list<int|string>  $userIds
     * @param  array<string, int|string>  $stats
     * @param  \Closure(User): string  $key
     * @param  \Closure(User): array<string, mixed>  $params
     * @param  array<string, scalar>  $refs
     * @param  array<string, scalar>  $actionParams
     */
    protected function deliver(array $userIds, array &$stats, NotificationType $type, \Closure $key, \Closure $params, array $refs = [], array $actionParams = [], ?string $route = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return;
        }

        $users = User::query()->whereIn('id', $ids)->where('is_frozen', false)->whereNotNull('email_verified_at')->get();
        $stats['candidates'] += count($ids);
        $stats['ineligible'] += count($ids) - $users->count();

        if ($users->isEmpty()) {
            return;
        }

        $keys = $users->mapWithKeys(fn (User $u) => [$u->id => $key($u)])->all();

        $sent = array_flip(DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('type_key', $type->value)
            ->whereIn('idempotency_key', array_values($keys))
            ->pluck('notifiable_id')->all());

        $e15 = $this->policy->usedToday($users->pluck('id')->all());
        $liveOps = $this->usedTodayLiveOps($users->pluck('id')->all());
        $maxE15 = $this->policy->maxPerDay();
        $cap = max(0, (int) config('liveops.notifications.daily_cap', 1));

        foreach ($users as $user) {
            if (isset($sent[$user->id])) {
                $stats['already']++;
            } elseif (($e15[$user->id] ?? 0) >= $maxE15 || ($liveOps[$user->id] ?? 0) >= $cap) {
                $stats['over_budget']++;
            } else {
                $this->send($user, $type, $params($user), $keys[$user->id], $refs, $actionParams, $route, $stats);
            }
        }
    }

    /** @param  array<string, int|string>  $stats */
    protected function send(User $user, NotificationType $type, array $params, string $key, array $refs, array $actionParams, ?string $route, array &$stats): void
    {
        try {
            match ($this->dispatcher->dispatch($user, $type, $params, $key, $refs, $actionParams, $route)) {
                DispatchResult::Created => $stats['created']++,
                DispatchResult::Suppressed => $stats['suppressed']++,
                DispatchResult::Duplicate => $stats['already']++,
                DispatchResult::Failed => $stats['failed']++,
            };
        } catch (\Throwable $e) {
            report($e); // فشل إشعار واحد لا يمسّ أي شيء آخر ولا يوقف بقية المستخدمين.
            $stats['failed']++;
        }
    }

    /** @return list<string> */
    public static function typeKeys(): array
    {
        return [
            NotificationType::MissionExpiring->value, NotificationType::WeeklyMissionsAvailable->value,
            NotificationType::EventStartingSoon->value, NotificationType::ComebackAvailable->value,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int> عدد إشعارات LiveOps المُنشأة اليوم المحلي لكل مستخدم (استعلام واحد للدفعة).
     */
    public function usedTodayLiveOps(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        [$start, $end] = $this->policy->dayBounds();

        return DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $userIds)
            ->whereIn('type_key', self::typeKeys())
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('notifiable_id, count(*) as used')
            ->groupBy('notifiable_id')
            ->pluck('used', 'notifiable_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
````

## `app/Services/LiveOps/MissionAssignmentService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\MissionPool;
use App\Models\PlayerProgression;
use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\UserMissionAssignment;
use App\Models\UserQuestProgress;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E34 - إسناد المهام لكل مستخدم من مجموعات المهام. **يحدّد الظهور فقط**: لا يمنح ولا يحسب تقدّمًا ولا يكتب مكافأة.
 * التقدّم والمكافآت يبقيان بالكامل في QuestService/UserQuestProgress/ProgressionRewardService (لا محرك ثانٍ).
 *
 * الخصائص المقصودة:
 *  - كسول: لا صفوف جماعية. صف الإسناد يُنشأ لمستخدم واحد، عند فتح فعلي أو حدث لعب، وبحدّ selection_count.
 *  - لقطة ثابتة: بعد أن يملك المستخدم إسنادًا للفترة، لا تغيّره تعديلات المجموعات (تسري على الفترة التالية).
 *  - حتمي: sha1(user|period|pool) ثم sha1(seed|quest) - لا random() ولا حالة خفية؛ نفس المدخلات = نفس المهام.
 *  - آمن تحت التزامن: معاملة + قفل صف المستخدم + إعادة فحص تحت القفل + UNIQUE + insertOrIgnore كحاجز أخير.
 *  - مهمة غير منتمية لأي مجموعة = مهمة عامة تعمل كما كانت (توافق خلفي كامل).
 *  - مهمة منتمية لمجموعة لا تظهر لغير من أُسندت له (أو من له تقدّم قائم بالفترة الحالية = استمرارية).
 *
 * الإشارات المستعملة للتخصيص (شفافة وقائمة على قواعد): المستوى، فئات الأحجيات **الرسمية** المحلولة مؤخرًا، تقدّم الحملات الأخير،
 * وتقدّم غير مكتمل قائم بالمهمة. مستبعد صراحةً: الدردشة، المدفوعات، مبالغ الرعاية، أعلام الاحتيال، البريد، الملف الخاص.
 *
 * ليست singleton عمدًا: الذاكرة المؤقتة (memo) بعمر المثيل الذي يحمله QuestService الواحد (طلب/حدث واحد)، فلا بيانات قديمة عبر الطلبات.
 */
class MissionAssignmentService
{
    public const CACHE_KEY = 'liveops:mission-pools:v1';

    /** @var array<string, list<int>> */
    private array $assignedMemo = [];

    /** @var array<string, list<int>> */
    private array $progressMemo = [];

    /** @var array<int, array{level:int,last_active_date:?string}> */
    private array $factsMemo = [];

    /** @var array{pools: array<int, array<string,mixed>>, pooled: array<int,true>}|null */
    private ?array $snapshotMemo = null;

    public function __construct(
        protected QuestPeriodService $periods,
        protected QuestEvaluatorRegistry $registry,
    ) {}

    // ───────────────────────── لقطة التعريفات المشتركة (بلا بيانات مستخدم) ─────────────────────────

    /**
     * @return array{pools: array<int, array<string,mixed>>, pooled: array<int,true>}
     */
    public function snapshot(): array
    {
        return $this->snapshotMemo ??= $this->loadSnapshot();
    }

    /**
     * @return array{pools: array<int, array<string,mixed>>, pooled: array<int,true>}
     */
    protected function loadSnapshot(): array
    {
        $seconds = max(0, (int) config('liveops.pools.cache_seconds', 300));

        $build = function (): array {
            $pools = MissionPool::query()->orderBy('priority')->orderBy('id')->get();
            $members = DB::table('mission_pool_quests')->orderBy('sort_order')->orderBy('quest_definition_id')->get(['mission_pool_id', 'quest_definition_id'])
                ->groupBy('mission_pool_id');

            $out = ['pools' => [], 'pooled' => []];

            foreach ($pools as $pool) {
                $ids = ($members[$pool->id] ?? collect())->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();

                $out['pools'][$pool->id] = [
                    'id' => (int) $pool->id,
                    'period_type' => (string) $pool->period_type,
                    'selection_count' => (int) $pool->selection_count,
                    'priority' => (int) $pool->priority,
                    'eligibility_rule' => (string) $pool->eligibility_rule,
                    'personalized' => (bool) $pool->personalized,
                    'is_active' => (bool) $pool->is_active,
                    'starts_at' => $pool->starts_at?->toIso8601String(),
                    'ends_at' => $pool->ends_at?->toIso8601String(),
                    'quest_ids' => $ids,
                ];

                foreach ($ids as $id) {
                    $out['pooled'][$id] = true;
                }
            }

            return $out;
        };

        if ($seconds === 0) {
            return $build();
        }

        return Cache::remember(self::CACHE_KEY, $seconds, $build);
    }

    public static function flushSnapshot(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function forgetMemo(): void
    {
        $this->assignedMemo = [];
        $this->progressMemo = [];
        $this->factsMemo = [];
        $this->snapshotMemo = null;
    }

    /** هل المهمة منتمية لأي مجموعة (فتُعرض بالإسناد فقط)؟ */
    public function isPooled(QuestDefinition $quest): bool
    {
        return isset($this->snapshot()['pooled'][$quest->id]);
    }

    /** @return list<int> */
    public function pooledQuestIds(): array
    {
        return array_map('intval', array_keys($this->snapshot()['pooled']));
    }

    // ───────────────────────── الواجهة التي يستعملها QuestService ─────────────────────────

    /**
     * هل يجوز عرض/تقييم هذه المهمة لهذا المستخدم في هذه الفترة؟
     * غير منتمية لمجموعة = نعم (سلوك E13 كما هو). منتمية = مُسندة له أو لديه تقدّم قائم بالفترة (استمرارية).
     * أي خلل داخلي يُغلق بأمان للمهام المجمَّعة فقط (لا تظهر) ولا يكسر اللعب.
     */
    public function isAllowed(User $user, QuestDefinition $quest, PeriodContext $period): bool
    {
        if (! $this->isPooled($quest)) {
            return true;
        }

        try {
            if (in_array((int) $quest->id, $this->assignedQuestIds($user, $period), true)) {
                return true;
            }

            return in_array((int) $quest->id, $this->progressQuestIds($user, $period), true);
        } catch (\Throwable $e) {
            Log::error('فشل فحص إسناد المهمة', ['user_id' => $user->id, 'quest_id' => $quest->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * معرّفات المهام المُسندة للمستخدم في هذه الفترة (تُنشئ الإسناد كسولًا عند أول طلب، إن وُجدت مجموعة نشطة مؤهِّلة).
     *
     * @return list<int>
     */
    public function assignedQuestIds(User $user, PeriodContext $period): array
    {
        $key = $user->id.'|'.$period->periodKey;

        if (isset($this->assignedMemo[$key])) {
            return $this->assignedMemo[$key];
        }

        $existing = $this->existingIds($user, $period->periodKey);

        if ($existing !== []) {
            return $this->assignedMemo[$key] = $existing;
        }

        $pool = $this->choosePool($user, $period);

        if ($pool === null) {
            return $this->assignedMemo[$key] = [];
        }

        return $this->assignedMemo[$key] = $this->assignWithinLock($user, $pool, $period);
    }

    // ───────────────────────── الاختيار والإسناد ─────────────────────────

    /**
     * أول مجموعة (بالأولوية ثم المعرّف) نشطة الآن لنوع الفترة ومؤهِّل لها المستخدم ولديها ما تُسنده فعليًا.
     * المجموعة القياسية = مجموعة قاعدتها "all" بأقل أولوية (آخر ما يُفحص) فتعمل كاحتياطي.
     *
     * @return array<string,mixed>|null
     */
    protected function choosePool(User $user, PeriodContext $period): ?array
    {
        $now = now();
        $facts = null;

        foreach ($this->snapshot()['pools'] as $pool) {
            if (! $pool['is_active'] || $pool['period_type'] !== $period->periodType || $pool['quest_ids'] === []) {
                continue;
            }

            if ($pool['starts_at'] !== null && $now->lt($pool['starts_at'])) {
                continue;
            }

            if ($pool['ends_at'] !== null && $now->gt($pool['ends_at'])) {
                continue;
            }

            $rule = MissionEligibilityRule::tryFrom($pool['eligibility_rule']) ?? MissionEligibilityRule::All;

            if ($rule !== MissionEligibilityRule::All) {
                $facts ??= $this->factsFor($user);

                if (! $rule->check($user, $facts)) {
                    continue;
                }
            }

            if ($this->candidates($pool, $period) === []) {
                continue;
            }

            return $pool;
        }

        return null;
    }

    /**
     * المهام الصالحة فعليًا للإسناد: نشطة، ضمن نافذتها الزمنية الآن، نفس نوع فترة المجموعة، ولها مُقيِّم مدعوم.
     *
     * @param  array<string,mixed>  $pool
     * @return list<QuestDefinition>
     */
    protected function candidates(array $pool, PeriodContext $period): array
    {
        $now = now();

        return QuestDefinition::query()
            ->whereIn('id', $pool['quest_ids'])
            ->where('is_active', true)
            ->where('period_type', $pool['period_type'])
            ->get()
            ->filter(fn (QuestDefinition $q) => ($q->starts_at === null || ! $now->lt($q->starts_at))
                && ($q->ends_at === null || ! $now->gt($q->ends_at))
                && $this->registry->evaluatorFor($q->condition_type) !== null)
            ->values()
            ->all();
    }

    /**
     * يكتب لقطة الإسناد تحت قفل صف المستخدم. عند وجود إسناد سابق (سباق خسرناه) يُعاد كما هو بلا إضافة.
     *
     * @param  array<string,mixed>  $pool
     * @return list<int>
     */
    public function assignWithinLock(User $user, array $pool, PeriodContext $period): array
    {
        $ids = DB::transaction(function () use ($user, $pool, $period) {
            User::query()->whereKey($user->id)->lockForUpdate()->first(['id']);

            $existing = $this->existingIds($user, $period->periodKey);

            if ($existing !== []) {
                return $existing;
            }

            $selected = $this->select($user, $pool, $period);
            $now = now();
            $rows = [];

            foreach ($selected as $slot => $quest) {
                $rows[] = [
                    'user_id' => $user->id,
                    'mission_pool_id' => $pool['id'],
                    'quest_definition_id' => $quest->id,
                    'period_key' => $period->periodKey,
                    'slot' => min(255, $slot),
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                UserMissionAssignment::query()->insertOrIgnore($rows);
            }

            return $this->existingIds($user, $period->periodKey);
        });

        $this->assignedMemo[$user->id.'|'.$period->periodKey] = $ids;

        return $ids;
    }

    /**
     * اختيار حتمي: أعلى تفضيل شفاف (إن كان التخصيص مفعّلًا) ثم ترتيب sha1 ثابت، ثم المعرّف كفاصل نهائي.
     *
     * @param  array<string,mixed>  $pool
     * @return list<QuestDefinition>
     */
    public function select(User $user, array $pool, PeriodContext $period): array
    {
        $candidates = $this->candidates($pool, $period);

        if ($candidates === []) {
            return [];
        }

        $count = max(1, min((int) $pool['selection_count'], (int) config('liveops.pools.max_selection', 10)));
        $seed = $user->id.'|'.$period->periodKey.'|'.$pool['id'];
        $affinity = ($pool['personalized'] && config('liveops.pools.personalization', true))
            ? $this->affinities($user, $candidates, $period)
            : [];

        $scored = [];

        foreach ($candidates as $quest) {
            $scored[] = [
                'quest' => $quest,
                'affinity' => $affinity[$quest->id] ?? 0,
                'hash' => hexdec(substr(sha1($seed.'|'.$quest->id), 0, 12)),
            ];
        }

        usort($scored, fn (array $a, array $b) => [$b['affinity'], $a['hash'], $a['quest']->id] <=> [$a['affinity'], $b['hash'], $b['quest']->id]);

        return array_map(fn (array $s) => $s['quest'], array_slice($scored, 0, $count));
    }

    /**
     * تفضيل شفاف قائم على قواعد (لا ML): +3 تقدّم غير مكتمل قائم، +2 فئة أحجيات رسمية لُعبت مؤخرًا، +2 نشاط حملة حديث.
     *
     * @param  list<QuestDefinition>  $candidates
     * @return array<int,int>
     */
    protected function affinities(User $user, array $candidates, PeriodContext $period): array
    {
        $days = max(1, (int) config('liveops.pools.recent_category_days', 14));
        $since = now()->subDays($days);
        $ids = array_map(fn (QuestDefinition $q) => $q->id, $candidates);

        $open = UserQuestProgress::query()
            ->where('user_id', $user->id)
            ->where('period_key', $period->periodKey)
            ->whereIn('quest_definition_id', $ids)
            ->whereNull('completed_at')
            ->where('current_value', '>', 0)
            ->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();

        $needsCategory = collect($candidates)->contains(fn (QuestDefinition $q) => $q->scope_type === 'puzzle_category');
        $categories = [];

        if ($needsCategory) {
            $categories = PuzzleAttempt::query()
                ->join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
                ->where('puzzle_attempts.user_id', $user->id)
                ->where('puzzle_attempts.is_correct', true)
                ->where('puzzle_attempts.created_at', '>=', $since)
                ->where('puzzles.origin', \App\Models\Puzzle::ORIGIN_OFFICIAL)
                ->distinct()
                ->pluck('puzzles.puzzle_category_id')->map(fn ($i) => (int) $i)->all();
        }

        $campaignActive = false;

        if (collect($candidates)->contains(fn (QuestDefinition $q) => $q->condition_type === 'campaign_steps_completed')) {
            $campaignActive = UserCampaignProgress::query()->where('user_id', $user->id)->where('updated_at', '>=', $since)->exists();
        }

        $out = [];

        foreach ($candidates as $quest) {
            $score = 0;

            if (in_array((int) $quest->id, $open, true)) {
                $score += 3;
            }

            if ($quest->scope_type === 'puzzle_category' && in_array((int) $quest->scope_id, $categories, true)) {
                $score += 2;
            }

            if ($quest->condition_type === 'campaign_steps_completed' && $campaignActive) {
                $score += 2;
            }

            $out[$quest->id] = $score;
        }

        return $out;
    }

    // ───────────────────────── قراءات مساعدة ─────────────────────────

    /** @return list<int> */
    protected function existingIds(User $user, string $periodKey): array
    {
        return UserMissionAssignment::query()
            ->where('user_id', $user->id)
            ->where('period_key', $periodKey)
            ->orderBy('slot')->orderBy('quest_definition_id')
            ->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
    }

    /** تقدّم قائم للمستخدم بالفترة الحالية (استمرارية لمهمة صارت منتمية لمجموعة بعد أن بدأها). @return list<int> */
    protected function progressQuestIds(User $user, PeriodContext $period): array
    {
        $key = $user->id.'|'.$period->periodKey;

        return $this->progressMemo[$key] ??= UserQuestProgress::query()
            ->where('user_id', $user->id)
            ->where('period_key', $period->periodKey)
            ->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array{level:int,last_active_date:?string} */
    protected function factsFor(User $user): array
    {
        return $this->factsMemo[$user->id] ??= [
            'level' => (int) (PlayerProgression::query()->where('user_id', $user->id)->value('current_level') ?? 1),
            'last_active_date' => ($d = PlayerStreak::query()->where('user_id', $user->id)->value('last_active_date')) !== null
                ? \Illuminate\Support\Carbon::parse($d)->toDateString()
                : null,
        ];
    }
}
````

## `app/Services/LiveOps/MissionCenterService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\UserQuestProgress;
use App\Services\Engagement\PeriodContext;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\Engagement\StreakService;
use App\Services\LiveOps\Calendar\EventCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * E34 - مركز المهام: طبقة **عرض مُجمَّعة** فوق خدمات موجودة (QuestService/StreakService/Achievements/EventCalendar). لا تحسب تقدّمًا ولا تمنح
 * ولا تعدّل سلسلة: كل ما تفعله جمع بيانات للقراءة بعدد استعلامات ثابت (لا N+1) وتنسيقها للواجهة.
 *
 *  - الحالات: in_progress / completed (مكتملة، المكافأة قيد المعالجة) / reward_granted. لا "طالب بالمكافأة" يدويًا: المنح تلقائي عبر QuestService.
 *  - الوقت من خدمة فترات المنصة نفسها (QuestPeriodService)؛ المتبقي حقيقي (نهاية الفترة أو نهاية نافذة المهمة الأقرب) بلا لغة ضغط.
 *  - widgetFor() للوحة الرئيسية: قراءة فقط بالكامل (لا مزامنة ولا إنشاء صفوف تقدّم).
 */
class MissionCenterService
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REWARD_GRANTED = 'reward_granted';

    public function __construct(
        protected QuestService $quests,
        protected QuestPeriodService $periods,
        protected StreakService $streaks,
        protected StreakMilestoneService $milestones,
        protected EventCalendarService $calendar,
        protected ComebackService $comeback,
    ) {}

    /**
     * صفحة المهام الكاملة. $sync=true يُبقي سلوك E13 (شفاء ذاتي من بيانات اللعب الحقيقية عند الفتح الفعلي) ويُنشئ صفوف تقدّم للمهام الظاهرة فقط.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, bool $sync = true): array
    {
        if ($sync) {
            $this->quests->syncCurrentQuests($user);
        }

        $daily = $this->rowsFor($user, QuestDefinition::PERIOD_DAILY, $sync);
        $weekly = $this->rowsFor($user, QuestDefinition::PERIOD_WEEKLY, $sync);
        $streak = $this->streaks->streakFor($user);
        $sections = $this->calendar->sections();
        $agenda = $this->calendar->agendaFor($user);
        $comeback = $this->comeback->stateFor($user);

        if ($comeback !== null) {
            $first = $daily->concat($weekly)->first(fn (array $r) => $r['status'] === self::STATUS_IN_PROGRESS);

            if ($first !== null) {
                $comeback['suggestions'][] = ['kind' => 'mission', 'title' => 'مهمة خفيفة: '.$first['quest']->name, 'url' => route('missions.index')];
            }
        }

        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'streak' => $streak,
            'milestones' => $this->milestones->statusFor($user),
            'nearAchievements' => $this->nearAchievements($user),
            'agenda' => $agenda,
            'events' => $sections['now']->concat($sections['today'])->concat($sections['week'])->take(4)->values(),
            'comeback' => $comeback,
            'timezone' => $this->periods->timezone(),
        ];
    }

    /**
     * عنصر اللوحة الرئيسية: 2–3 مهام أولوية + السلسلة + أقرب حدث. قراءة فقط (الإسناد الكسول لهذا المستخدم فقط مسموح؛ لا صفوف تقدّم).
     *
     * @return array{missions: Collection<int, array<string,mixed>>, streak: int, next_event: ?\App\Services\LiveOps\Calendar\EventItem, total: int, completed: int}
     */
    public function widgetFor(User $user, ?\App\Models\PlayerStreak $streak = null): array
    {
        $daily = $this->rowsFor($user, QuestDefinition::PERIOD_DAILY, false);
        $weekly = $this->rowsFor($user, QuestDefinition::PERIOD_WEEKLY, false);
        $all = $daily->concat($weekly);

        $priority = $all->filter(fn (array $r) => $r['status'] === self::STATUS_IN_PROGRESS)
            ->sortByDesc('percent')->take(3)->values();

        $next = $this->calendar->items()->first();

        return [
            'missions' => $priority,
            'streak' => StreakMilestoneService::effectiveCurrent($streak ?? $this->streaks->streakFor($user)),
            'next_event' => $next,
            'total' => $daily->count(),
            'completed' => $daily->where('status', '!=', self::STATUS_IN_PROGRESS)->count(),
        ];
    }

    /**
     * صفوف المهام لنوع فترة: استعلام تعريفات + تقدّم + إسناد ثابت العدد.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function rowsFor(User $user, string $periodType, bool $materialize): Collection
    {
        $period = $this->periods->contextFor($periodType);
        $quests = $this->quests->visibleQuestsFor($user, $periodType, $period);

        if ($quests->isEmpty()) {
            return collect();
        }

        $progress = UserQuestProgress::query()
            ->where('user_id', $user->id)->where('period_key', $period->periodKey)
            ->whereIn('quest_definition_id', $quests->pluck('id'))
            ->get()->keyBy('quest_definition_id');

        $now = now();

        return $quests->map(function (QuestDefinition $quest) use ($user, $period, $progress, $materialize, $now) {
            $row = $progress->get($quest->id);

            if ($row === null && $materialize) {
                $row = $this->quests->progressFor($user, $quest, $period);
            }

            // بلا صف (قراءة فقط): تقدّم افتراضي صفري غير محفوظ - لا يُكتب شيء.
            $row ??= new UserQuestProgress([
                'user_id' => $user->id, 'quest_definition_id' => $quest->id, 'period_type' => $period->periodType, 'period_key' => $period->periodKey,
                'current_value' => 0, 'target_value_snapshot' => $quest->target_value,
            ]);

            return $this->row($quest, $row, $period, $now);
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(QuestDefinition $quest, UserQuestProgress $progress, PeriodContext $period, Carbon $now): array
    {
        $target = max(0, (int) $progress->target_value_snapshot);
        $percent = $target > 0 ? min(100, (int) round(($progress->current_value / $target) * 100)) : 0;

        $status = match (true) {
            $progress->completed_at === null => self::STATUS_IN_PROGRESS,
            $progress->reward_granted_at === null => self::STATUS_COMPLETED,
            default => self::STATUS_REWARD_GRANTED,
        };

        $end = $period->end;

        if ($quest->ends_at !== null && $quest->ends_at->lt($end)) {
            $end = $quest->ends_at;
        }

        return [
            'quest' => $quest,
            'progress' => $progress,
            'percent' => $percent,
            'status' => $status,
            'remaining' => $status === self::STATUS_IN_PROGRESS ? self::formatRemaining($now, $end) : null,
        ];
    }

    /** وقت متبقٍ حقيقي بصيغة محايدة (أيام/ساعات/دقائق). null إن انقضى. */
    public static function formatRemaining(Carbon $now, \DateTimeInterface $end): ?string
    {
        $seconds = (int) $now->diffInSeconds(Carbon::instance($end), false);

        if ($seconds <= 0) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days} يوم و{$hours} ساعة",
            $hours > 0 => "{$hours} ساعة و{$minutes} دقيقة",
            default => max(1, $minutes).' دقيقة',
        };
    }

    /**
     * إنجازات قاربت الاكتمال (≥ 50%): استعلام واحد محدود، بلا إنجازات مخفية. تحفيز بلا ضغط؛ المنح من نظام الإنجازات.
     *
     * @return Collection<int, array{name:string, current:int, target:int, percent:int}>
     */
    public function nearAchievements(User $user, int $limit = 3): Collection
    {
        return UserAchievementProgress::query()
            ->join('achievements', 'achievements.id', '=', 'user_achievement_progress.achievement_id')
            ->where('user_achievement_progress.user_id', $user->id)
            ->whereNull('user_achievement_progress.unlocked_at')
            ->where('achievements.is_active', true)->where('achievements.is_hidden', false)
            ->where('user_achievement_progress.current_value', '>', 0)
            ->whereColumn('user_achievement_progress.current_value', '<', 'achievements.target_value')
            ->orderByDesc('user_achievement_progress.current_value')
            ->limit(25)
            ->get(['achievements.name', 'achievements.target_value', 'user_achievement_progress.current_value'])
            ->map(fn ($r) => [
                'name' => (string) $r->name,
                'current' => (int) $r->current_value,
                'target' => (int) $r->target_value,
                'percent' => (int) floor(((int) $r->current_value / max(1, (int) $r->target_value)) * 100),
            ])
            ->filter(fn (array $a) => $a['percent'] >= 50)
            ->sortByDesc('percent')
            ->take($limit)
            ->values();
    }
}
````

## `app/Services/LiveOps/MissionEligibilityRule.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\PlayerStreak;
use App\Models\User;

/**
 * E34 - سجل مغلق لقواعد أهلية مجموعات المهام. لا قاعدة تأتي من نص إداري حر ولا من العميل،
 * وكلها تقرأ حقائق خادمية فقط: عمر الحساب، المستوى، وآخر نشاط **لعب رسمي** (لا تسجيل دخول).
 * الإشارات المستبعدة عمدًا: الدردشة، المدفوعات، مبالغ الرعاية، أعلام الاحتيال، البريد، الملف الخاص.
 */
enum MissionEligibilityRule: string
{
    case All = 'all';
    case NewPlayer = 'new_player';
    case Returning = 'returning';
    case Experienced = 'experienced';

    public function label(): string
    {
        return match ($this) {
            self::All => 'الجميع (المجموعة القياسية)',
            self::NewPlayer => 'لاعب جديد',
            self::Returning => 'لاعب عائد بعد غياب',
            self::Experienced => 'لاعب متمرّس',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @param array{level?:int,last_active_date?:?string} $facts */
    public function check(User $user, array $facts): bool
    {
        return match ($this) {
            self::All => true,
            self::NewPlayer => $user->created_at !== null
                && $user->created_at->gte(now()->subDays(max(1, (int) config('liveops.pools.new_player_days', 14)))),
            self::Returning => ComebackService::isInactiveFor(
                $facts['last_active_date'] ?? null,
                max(1, (int) config('liveops.comeback.min_inactive_days', 7)),
            ),
            self::Experienced => (int) ($facts['level'] ?? 1) >= max(1, (int) config('liveops.pools.experienced_level', 5)),
        };
    }
}
````

## `app/Services/LiveOps/MissionPoolService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E34 - إدارة مجموعات المهام (طبقة إدارة فوق QuestDefinition؛ لا محرك مهام).
 *
 * كل كتابة: صلاحية liveops.manage + حد معدّل لكل مشرف + تدقيق + معاملة + تفريغ كاش اللقطة المشتركة.
 * لا أثر رجعي: تعديل مجموعة لا يغيّر لقطة إسناد سبق أن أُنشئت لأي مستخدم (يسري على الفترة التالية).
 * لا تُحذف مجموعة لها تاريخ إسناد (تُعطَّل). لا تُفعَّل مجموعة بلا مهام (لا شيء يُسند).
 */
class MissionPoolService
{
    public function __construct(protected OperationalAuditService $audit) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): MissionPool
    {
        $this->authorize($actor);
        $clean = $this->validated($data, null);

        $pool = DB::transaction(function () use ($clean, $actor) {
            $p = new MissionPool;
            $p->forceFill($clean + ['is_active' => false, 'created_by' => $actor->id])->save();

            return $p;
        });

        $this->audit->log('mission_pool_created', $pool, ['slug' => $pool->slug], $actor);
        MissionAssignmentService::flushSnapshot();

        return $pool;
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, MissionPool $pool, array $data): MissionPool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $pool, $data) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $clean = $this->validated($data + $locked->only(['name', 'slug', 'period_type']), $locked);

            if ($clean['period_type'] !== $locked->period_type
                && ($locked->assignments()->exists() || DB::table('mission_pool_quests')->where('mission_pool_id', $locked->id)->exists())) {
                throw new LiveOpsException('period_locked', 'لا يمكن تغيير نوع فترة مجموعة لها مهام أو سجل إسناد.');
            }

            $before = $locked->only(array_keys($clean));
            $locked->forceFill($clean)->save();

            $this->audit->log('mission_pool_updated', $locked, ['before' => $before, 'after' => $locked->only(array_keys($clean))], $actor);
            MissionAssignmentService::flushSnapshot();

            return $locked;
        });
    }

    public function activate(User $actor, MissionPool $pool): MissionPool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $pool) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();

            if (! DB::table('mission_pool_quests')->where('mission_pool_id', $locked->id)->exists()) {
                throw new LiveOpsException('empty_pool', 'لا يمكن تفعيل مجموعة بلا مهام.');
            }

            // الحدّ الصلب الوحيد (غير الآمن فعلًا): لا يتجاوز الحدّ الأعلى النظري لمهام المستخدم المتزامنة الحدّ المضبوط. بقية التجاوزات تحذيرات فقط.
            if (app(LiveOpsPlannerService::class)->wouldExceedHardCap($locked)) {
                throw new LiveOpsException('hard_cap', 'تفعيل هذه المجموعة يتجاوز الحدّ الأقصى لمهام اللاعب المتزامنة. خفّض المهام الفعّالة أو عدد الاختيار أولًا.');
            }

            $locked->forceFill(['is_active' => true])->save();
            $this->audit->log('mission_pool_activated', $locked, ['slug' => $locked->slug], $actor);
            MissionAssignmentService::flushSnapshot();

            return $locked;
        });
    }

    public function deactivate(User $actor, MissionPool $pool): MissionPool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $pool) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => false])->save();
            $this->audit->log('mission_pool_deactivated', $locked, ['slug' => $locked->slug], $actor);
            MissionAssignmentService::flushSnapshot();

            return $locked;
        });
    }

    public function attach(User $actor, MissionPool $pool, QuestDefinition $quest, int $sortOrder = 0): void
    {
        $this->authorize($actor);

        if ($quest->period_type !== $pool->period_type) {
            throw new LiveOpsException('period_mismatch', 'نوع فترة المهمة لا يطابق نوع فترة المجموعة.');
        }

        try {
            DB::transaction(function () use ($actor, $pool, $quest, $sortOrder) {
                $now = now();

                if (DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->where('quest_definition_id', $quest->id)->exists()) {
                    throw new LiveOpsException('already_member', 'المهمة عضو بالمجموعة أصلًا.');
                }

                DB::table('mission_pool_quests')->insert([
                    'mission_pool_id' => $pool->id,
                    'quest_definition_id' => $quest->id,
                    'sort_order' => max(0, min(65535, $sortOrder)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->audit->log('mission_pool_quest_attached', $pool, ['quest' => $quest->internal_key], $actor);
            });
        } catch (UniqueConstraintViolationException) {
            throw new LiveOpsException('already_member', 'المهمة عضو بالمجموعة أصلًا.');
        }

        MissionAssignmentService::flushSnapshot();
    }

    public function detach(User $actor, MissionPool $pool, QuestDefinition $quest): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $pool, $quest) {
            $removed = DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->where('quest_definition_id', $quest->id)->delete();

            if ($removed > 0) {
                $this->audit->log('mission_pool_quest_detached', $pool, ['quest' => $quest->internal_key], $actor);
            }
        });

        MissionAssignmentService::flushSnapshot();
    }

    public function delete(User $actor, MissionPool $pool): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $pool) {
            $locked = MissionPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();

            if ($locked->assignments()->exists()) {
                throw new LiveOpsException('has_history', 'لا تُحذف مجموعة لها سجل إسناد؛ عطّلها بدلًا من ذلك.');
            }

            $this->audit->log('mission_pool_deleted', $locked, ['slug' => $locked->slug], $actor);
            $locked->delete();
        });

        MissionAssignmentService::flushSnapshot();
    }

    protected function authorize(User $actor): void
    {
        if (! $actor->can('liveops.manage')) {
            throw new LiveOpsException('forbidden', 'لا تملك صلاحية إدارة مجموعات المهام.');
        }

        $key = 'liveops-action:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('liveops.limits.action_per_minute', 30))) {
            throw new LiveOpsException('rate_limited', 'عدد كبير من إجراءات LiveOps خلال دقيقة. انتظر قليلًا.');
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function validated(array $data, ?MissionPool $existing): array
    {
        $v = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('mission_pools', 'slug')->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'period_type' => ['required', Rule::in(QuestDefinition::PERIOD_TYPES)],
            'selection_count' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('liveops.pools.max_selection', 10)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'eligibility_rule' => ['sometimes', Rule::in(array_keys(MissionEligibilityRule::options()))],
            'personalized' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...(! empty($data['starts_at']) ? ['after:starts_at'] : [])],
        ]);

        if ($v->fails()) {
            throw ValidationException::withMessages($v->errors()->toArray());
        }

        $d = $v->validated();

        return [
            'name' => $d['name'],
            'slug' => $d['slug'],
            'description' => $d['description'] ?? null,
            'period_type' => $d['period_type'],
            'selection_count' => (int) ($d['selection_count'] ?? $existing?->selection_count ?? 3),
            'priority' => (int) ($d['priority'] ?? $existing?->priority ?? 100),
            'eligibility_rule' => $d['eligibility_rule'] ?? $existing?->eligibility_rule ?? MissionEligibilityRule::All->value,
            'personalized' => (bool) ($d['personalized'] ?? $existing?->personalized ?? false),
            'starts_at' => $d['starts_at'] ?? null,
            'ends_at' => $d['ends_at'] ?? null,
        ];
    }
}
````

## `app/Services/LiveOps/RetentionAnalytics.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\CompetitiveEventParticipant;
use App\Models\PlayerStreak;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\GameEngine\Support\AttemptContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * E34 - تحليلات الاحتفاظ **مُجمَّعة فقط** (لا صفوف مستخدمين، لا أسماء، لا SDK خارجي، لا تتبّع جهاز). مبنية على سجلات اللعب الموجودة.
 *
 * "لعب ذو معنى" = محاولة أحجية **رسمية صحيحة** (puzzle_attempts.is_correct = 1) - نفس المصدر الموثوق للسلسلة؛ تسجيل الدخول وحده لا يُحسب أبدًا،
 * وأحجيات المجتمع/سلاسل المبدعين خارج الجدول فلا تدخل. اليوم = التاريخ المحلي للمنصة (QuestPeriodService؛ UTC افتراضيًا).
 *
 * الصيغ (وثّقها docs/liveops-retention-e34.md):
 *  - mission_completion[daily|weekly]: completed / total لصفوف user_quest_progress لهذا النوع التي period_start >= بداية النافذة.
 *    تنبيه: الصف يُنشأ كسولًا عند فتح مركز المهام أو حدث لعب، فالمقام = "معرَّضون للمهمة" لا "كل المسجَّلين".
 *  - participation: عدد المستخدمين المميَّزين الذين لهم تقدّم > 0 في كل فترة (period_key)؛ متوسط الأيام + الأمس/الأسبوع الأخير.
 *  - streak_distribution: أعلى سلاسل *فعّالة* فقط (last_active_date >= أمس)؛ current_streak المخزَّن لا يتناقص بالوقت فلا يُقرأ خامًا.
 *  - event_participation: مسجَّلو المنافسات الرسمية بالنافذة (registered_at) + مستخدمو الحملات المميَّزون (إكمال خطوة سردية/تأمل أو حل صحيح بسياق حملة).
 *  - returners: مستخدمون لعبوا ضمن النافذة وكان آخر لعب لهم *قبل أول لعبة بالنافذة* قبل N يومًا فأكثر (N = liveops.comeback.min_inactive_days).
 *  - retention D{1,7,30}: لفوج التسجيل (users.created_at بتاريخ محلي d) المنضج فقط (d + N <= اليوم)، النسبة = من لعب لعبًا ذا معنى في اليوم d+N **بالضبط** / حجم الفوج
 *    (يشمل من لم يلعب قط في المقام). فوج أصغر من cohort_min_size => null (غير كافٍ) لا نسبة مضلِّلة.
 * الكاش قصير (analytics.cache_seconds) ومشترك لأنه بلا بيانات مستخدمين.
 */
class RetentionAnalytics
{
    public function __construct(protected QuestPeriodService $periods) {}

    /** @return array<string, mixed> */
    public function overview(?int $days = null): array
    {
        $days = max(1, min(180, $days ?? (int) config('liveops.analytics.window_days', 30)));
        $seconds = max(0, (int) config('liveops.analytics.cache_seconds', 300));
        $build = fn () => $this->build($days);

        return $seconds === 0 ? $build() : Cache::remember("liveops:analytics:v1:{$days}", $seconds, $build);
    }

    public static function flush(): void
    {
        foreach ([7, 14, 30, 60, 90, 180] as $d) {
            Cache::forget("liveops:analytics:v1:{$d}");
        }

        Cache::forget('liveops:analytics:v1:'.(int) config('liveops.analytics.window_days', 30));
    }

    /** @return array<string, mixed> */
    protected function build(int $days): array
    {
        $today = Carbon::parse($this->periods->todayLocal())->startOfDay();
        $since = $today->copy()->subDays($days);

        return [
            'window_days' => $days,
            'generated_for' => $today->toDateString(),
            'mission_completion' => [
                'daily' => $this->completion('daily', $since),
                'weekly' => $this->completion('weekly', $since),
            ],
            'participation' => [
                'daily' => $this->participation('daily', $since),
                'weekly' => $this->participation('weekly', $since),
            ],
            'streak_distribution' => $this->streakDistribution($today),
            'event_participation' => $this->eventParticipation($since),
            'returners' => $this->returners($since),
            'retention' => $this->retention($today),
        ];
    }

    /** @return array{total:int, completed:int, rate:?float} */
    protected function completion(string $type, Carbon $since): array
    {
        $base = UserQuestProgress::query()->where('period_type', $type)->where('period_start', '>=', $since);
        $total = (clone $base)->count();
        $completed = (clone $base)->whereNotNull('completed_at')->count();

        return ['total' => $total, 'completed' => $completed, 'rate' => $total > 0 ? round($completed / $total, 4) : null];
    }

    /** @return array{periods:int, average:?float, latest:int} */
    protected function participation(string $type, Carbon $since): array
    {
        $rows = UserQuestProgress::query()
            ->where('period_type', $type)->where('period_start', '>=', $since)->where('current_value', '>', 0)
            ->selectRaw('period_key, count(distinct user_id) as users')
            ->groupBy('period_key')->orderBy('period_key')
            ->pluck('users', 'period_key');

        // الفترة الجارية ناقصة بطبيعتها؛ "الأحدث" = آخر فترة مكتملة إن وُجدت وإلا الجارية.
        $currentKey = $this->periods->contextFor($type)->periodKey;
        $closed = $rows->except($currentKey);

        return [
            'periods' => $rows->count(),
            'average' => $rows->isEmpty() ? null : round($rows->avg(), 2),
            'latest' => (int) ($closed->isNotEmpty() ? $closed->last() : ($rows->get($currentKey) ?? 0)),
        ];
    }

    /** @return array<string, int> */
    protected function streakDistribution(Carbon $today): array
    {
        $yesterday = $today->copy()->subDay()->toDateString();
        $thresholds = app(StreakMilestoneService::class)->thresholds();
        $active = PlayerStreak::query()->where('last_active_date', '>=', $yesterday);
        $out = [];
        $activeTotal = 0;

        // حدود الأقسام: 1..(m1-1) ثم كل معلَم حتى (التالي-1) وآخرها مفتوح. المحتوى بحسب المعالم المضبوطة (لا أرقام ثابتة).
        $edges = array_values(array_unique(array_merge([1], $thresholds)));
        sort($edges);

        foreach ($edges as $i => $from) {
            $to = $edges[$i + 1] ?? null;
            $q = (clone $active)->where('current_streak', '>=', $from);

            if ($to !== null) {
                $q->where('current_streak', '<', $to);
            }

            $n = $q->count();
            $activeTotal += $n;
            $out[$to === null ? "{$from}+" : ($to - $from === 1 ? (string) $from : "{$from}-".($to - 1))] = $n;
        }

        return ['none_or_broken' => max(0, PlayerStreak::query()->count() - $activeTotal)] + $out;
    }

    /** @return array{competitive_registrations:int, competitive_users:int, campaign_users:int} */
    protected function eventParticipation(Carbon $since): array
    {
        $reg = CompetitiveEventParticipant::query()->where('registered_at', '>=', $since);

        $narrative = UserCampaignProgress::query()->where('completed_at', '>=', $since)->pluck('user_id');
        $puzzle = PuzzleAttempt::query()
            ->where('is_correct', true)->where('context_type', AttemptContext::TYPE_CAMPAIGN_STEP)->where('created_at', '>=', $since)
            ->distinct()->pluck('user_id');

        return [
            'competitive_registrations' => (clone $reg)->count(),
            'competitive_users' => (clone $reg)->distinct()->count('user_id'),
            'campaign_users' => $narrative->merge($puzzle)->unique()->count(),
        ];
    }

    /** @return array{min_inactive_days:int, returners:int, active_in_window:int, rate:?float} */
    protected function returners(Carbon $since): array
    {
        $n = ComebackService::minInactiveDays();
        $first = PuzzleAttempt::query()->where('is_correct', true)->where('created_at', '>=', $since)
            ->selectRaw('user_id, min(created_at) as first_in')->groupBy('user_id')->get();

        if ($first->isEmpty()) {
            return ['min_inactive_days' => $n, 'returners' => 0, 'active_in_window' => 0, 'rate' => null];
        }

        $returners = 0;

        foreach ($first->chunk(500) as $chunk) {
            $prev = PuzzleAttempt::query()->where('is_correct', true)->where('created_at', '<', $since)
                ->whereIn('user_id', $chunk->pluck('user_id'))
                ->selectRaw('user_id, max(created_at) as last_before')->groupBy('user_id')
                ->pluck('last_before', 'user_id');

            foreach ($chunk as $row) {
                $last = $prev->get($row->user_id);

                if ($last !== null && Carbon::parse($last)->startOfDay()->diffInDays(Carbon::parse($row->first_in)->startOfDay()) >= $n) {
                    $returners++;
                }
            }
        }

        return ['min_inactive_days' => $n, 'returners' => $returners, 'active_in_window' => $first->count(), 'rate' => round($returners / $first->count(), 4)];
    }

    /**
     * D1/D7/D30 بصيغة اليوم-المحدّد (لا "في أي وقت بعد"). فوج منضج فقط، بحدّ أعلى لحجم الفوج المقروء (حماية الذاكرة).
     *
     * @return array<string, array{cohort:int, retained:int, rate:?float}>
     */
    protected function retention(Carbon $today): array
    {
        $out = [];
        $window = max(1, (int) config('liveops.analytics.window_days', 30));
        $min = max(1, (int) config('liveops.analytics.cohort_min_size', 5));
        $cap = 20000;

        foreach ([1, 7, 30] as $n) {
            // أيام تسجيل منضجة: من (اليوم - النافذة - N) حتى (اليوم - N) شاملين.
            $from = $today->copy()->subDays($window + $n);
            $to = $today->copy()->subDays($n)->endOfDay();

            $users = User::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to)
                ->orderBy('id')->limit($cap)->get(['id', 'created_at']);

            $cohort = $users->count();
            $retained = 0;

            foreach ($users->chunk(1000) as $chunk) {
                $dates = PuzzleAttempt::query()->where('is_correct', true)
                    ->whereIn('user_id', $chunk->pluck('id'))->where('created_at', '>=', $from)
                    ->get(['user_id', 'created_at'])
                    ->groupBy('user_id')
                    ->map(fn ($rows) => $rows->map(fn ($r) => $this->periods->localDateFor(Carbon::parse($r->created_at)))->flip());

                foreach ($chunk as $u) {
                    $target = Carbon::parse($this->periods->localDateFor($u->created_at))->addDays($n)->toDateString();

                    if (isset(($dates->get($u->id) ?? collect())[$target])) {
                        $retained++;
                    }
                }
            }

            $out["d{$n}"] = ['cohort' => $cohort, 'retained' => $retained, 'rate' => $cohort >= $min ? round($retained / $cohort, 4) : null];
        }

        return $out;
    }
}
````

## `app/Services/LiveOps/StreakMilestoneService.php`

````php
<?php

namespace App\Services\LiveOps;

use App\Models\Achievement;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;
use App\Services\Progression\AchievementService;
use Illuminate\Support\Facades\Log;

/**
 * E34 - معالم السلسلة اليومية (3/7/14/30/60/100 قابلة للضبط).
 *
 *  - StreakService يبقى السلطة الوحيدة: هذه الخدمة **تقرأ** longest_streak فقط ولا تكتب سلسلة.
 *  - المعلَم = إنجاز (Achievement) من نوع streak_longest_days؛ المنح/الإشعار/الأمان عبر AchievementService كما هو.
 *    الافتراضي: تقدير بلا مكافأة (xp_reward = 0)؛ أي مكافأة يضبطها المدير من لوحة الإنجازات وتمر بسلطة الاقتصاد الموجودة (مرة واحدة، idempotent).
 *  - لا Freeze ولا حماية مدفوعة ولا استرداد: longest_streak لا يتناقص، فكسر السلسلة لا يسحب معلمًا نيل.
 *  - محدودة التكلفة: لا تفعل شيئًا ما لم يبلغ longest أصغر عتبة، ولا تقيّم إلا إنجازات معلم فعّالة لم تُفتح بعد وهدفها ≤ longest (استعلام واحد).
 */
class StreakMilestoneService
{
    public function __construct(protected AchievementService $achievements) {}

    /** @return list<int> عتبات مرتَّبة تصاعديًا بلا تكرار. */
    public function thresholds(): array
    {
        $t = array_values(array_unique(array_map('intval', (array) config('liveops.streak_milestones', [3, 7, 14, 30, 60, 100]))));
        $t = array_values(array_filter($t, fn (int $d) => $d > 0));
        sort($t);

        return $t;
    }

    /** يُستدعى بعد تحديث السلسلة من حلّ رسمي صحيح. آمن لإعادة الاستدعاء (idempotent). */
    public function onStreakUpdated(User $user): void
    {
        $thresholds = $this->thresholds();

        if ($thresholds === []) {
            return;
        }

        $longest = (int) (PlayerStreak::query()->where('user_id', $user->id)->value('longest_streak') ?? 0);

        if ($longest < $thresholds[0]) {
            return;
        }

        $pending = Achievement::query()
            ->where('is_active', true)
            ->where('condition_type', AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)
            ->where('target_value', '<=', $longest)
            ->whereNotExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('user_achievement_progress')
                    ->whereColumn('user_achievement_progress.achievement_id', 'achievements.id')
                    ->where('user_achievement_progress.user_id', $user->id)
                    ->whereNotNull('user_achievement_progress.unlocked_at');
            })
            ->get();

        foreach ($pending as $achievement) {
            try {
                $this->achievements->evaluateAchievement($user, $achievement);
            } catch (\Throwable $e) {
                Log::error('فشل تقييم معلم سلسلة', ['achievement_id' => $achievement->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * السلسلة **الفعّالة** للعرض: current_streak المخزَّن لا يتناقص بمرور الوقت (يُصفَّر عند أول نشاط جديد فقط)، فنعرض 0 إن كان آخر يوم لعب رسمي
     * أقدم من أمس بالتاريخ المحلي للمنصة. عرض فقط: لا كتابة ولا عقوبة ولا مساس بـlongest_streak (يبقى محفوظًا دائمًا).
     */
    public static function effectiveCurrent(?PlayerStreak $row): int
    {
        if ($row === null || (int) $row->current_streak <= 0) {
            return 0;
        }

        if ($row->last_active_date === null) {
            return (int) $row->current_streak;
        }

        $today = \Illuminate\Support\Carbon::parse(app(\App\Services\Engagement\QuestPeriodService::class)->todayLocal())->startOfDay();
        $last = \Illuminate\Support\Carbon::parse($row->last_active_date)->startOfDay();

        return $last->diffInDays($today) <= 1 ? (int) $row->current_streak : 0;
    }

    /**
     * حالة العرض (للقراءة فقط، مشتقة من longest/current بلا كتابة): المعالم المنجزة، والمعلم التالي والأيام المتبقية.
     *
     * @return array{current:int, longest:int, reached:list<int>, next:?int, days_to_next:?int}
     */
    public function statusFor(User $user): array
    {
        $row = PlayerStreak::query()->where('user_id', $user->id)->first(['current_streak', 'longest_streak', 'last_active_date']);
        $current = self::effectiveCurrent($row);
        $longest = (int) ($row?->longest_streak ?? 0);
        $thresholds = $this->thresholds();

        $reached = array_values(array_filter($thresholds, fn (int $d) => $d <= $longest));
        // "التالي" = أول معلَم **لم يُنَل بعد** (أكبر من أطول سلسلة)، والأيام المتبقية = ما ينقص سلسلتك الحالية لبلوغه (لا يُعرض معلَم نِيل سابقًا كأنه قادم).
        $next = collect($thresholds)->first(fn (int $d) => $d > $longest);

        return [
            'current' => $current,
            'longest' => $longest,
            'reached' => $reached,
            'next' => $next,
            'days_to_next' => $next === null ? null : max(0, $next - $current),
        ];
    }
}
````

## `app/Services/Progression/Achievements/StreakLongestDaysEvaluator.php`

````php
<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Services\Progression\AchievementEvaluatorRegistry;

/**
 * E34 - معلم السلسلة اليومية كإنجاز (لا محرك إنجازات/سلسلة ثانٍ). المصدر الوحيد: PlayerStreak.longest_streak
 * (يُحدَّث حصرًا عبر StreakService من حلّ رسمي صحيح؛ لا يتناقص ولا يعتمد على الدخول ولا على الوقت).
 * لذلك كسر السلسلة لاحقًا لا يسحب معلَمًا سبق نيله (longest محفوظ)، ولا شيء يُسترد أبدًا.
 */
class StreakLongestDaysEvaluator implements AchievementEvaluator
{
    public function supports(Achievement $achievement): bool
    {
        return $achievement->condition_type === AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS;
    }

    public function currentValue(User $user, Achievement $achievement): int
    {
        return (int) (PlayerStreak::query()->where('user_id', $user->id)->value('longest_streak') ?? 0);
    }
}
````

## `config/liveops.php`

````php
<?php

/*
| E34 - مركز العمليات الحيّة والاحتفاظ باللاعبين (LiveOps & Retention).
|
| قواعد مغلقة (لا تُضبط بأي إعداد هنا):
|  - لا محرك مهام/سلسلة/إنجازات ثانٍ: الإسناد يحدّد **الظهور** فقط، والتقدّم والمكافأة يبقيان في QuestService/ProgressionRewardService.
|  - لا ضغط نفسي ولا استعجال مصطنع ولا عقوبة على غياب يوم، ولا حماية سلسلة مدفوعة، ولا استرداد لمكافأة سبق اكتسابها.
|  - أحجيات المجتمع وسلاسل المبدعين صفرية الاقتصاد؛ نقرات الإحالة والدعم والدفع لا تُحسب تقدّمًا.
|  - مكافأة العودة (Comeback) بجواهر غير موجودة أصلًا: لا مفتاح لها هنا عمدًا.
| كل القيم أدناه تُضبط بالبيئة (LIVEOPS_*) ولا تحوي أسرارًا. التفاصيل: docs/liveops-retention-e34.md
*/
return [

    /*
    | مجموعات المهام (Mission Pools) والتدوير الحتمي لكل مستخدم.
    | الاختيار حتمي: sha1(user_id | period_key | pool_id) + sha1(seed | quest_id) - بلا random() ثم بلا أي حالة خفية.
    */
    'pools' => [
        // كاش تعريفات المجموعات/العضوية المشتركة (لا يحوي بيانات مستخدم). يُفرَّغ فور أي تعديل إداري.
        'cache_seconds' => (int) env('LIVEOPS_POOL_CACHE_SECONDS', 300),

        // قواعد الأهلية المغلقة (MissionEligibilityRule)
        'new_player_days' => (int) env('LIVEOPS_NEW_PLAYER_DAYS', 14),
        'experienced_level' => (int) env('LIVEOPS_EXPERIENCED_LEVEL', 5),

        // التخصيص قائم على قواعد شفافة فقط (فئات رسمية حديثة + حملة/موسم + تقدّم غير مكتمل)، لا ML ولا بيانات خاصة.
        'personalization' => (bool) env('LIVEOPS_PERSONALIZATION', true),
        'recent_category_days' => (int) env('LIVEOPS_RECENT_CATEGORY_DAYS', 14),

        // سقف أمان لعدد المهام التي تسندها مجموعة واحدة لمستخدم واحد في فترة واحدة.
        'max_selection' => 10,
    ],

    /*
    | تقويم الأحداث (/events): لقطة عامة مشتركة (مصفوفات بسيطة) تُحسب حالتها وقت القراءة.
    */
    'calendar' => [
        'cache_seconds' => (int) env('LIVEOPS_CALENDAR_CACHE_SECONDS', 60),
        'ending_soon_hours' => (int) env('LIVEOPS_ENDING_SOON_HOURS', 24),
        'upcoming_days' => (int) env('LIVEOPS_UPCOMING_DAYS', 60),
        'per_source_limit' => (int) env('LIVEOPS_CALENDAR_SOURCE_LIMIT', 30),
        'agenda_max' => (int) env('LIVEOPS_AGENDA_MAX', 5),
    ],

    /*
    | معالم السلسلة اليومية (أيام). تُنفَّذ كإنجازات (Achievement) من نوع streak_longest_days بلا محرك جديد؛
    | المصدر الموثوق longest_streak في PlayerStreak. القيم هنا للقراءة/البذر/العرض؛ المنح عبر نظام الإنجازات.
    */
    'streak_milestones' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('LIVEOPS_STREAK_MILESTONES', '3,7,14,30,60,100'))),
        fn (int $d) => $d > 0,
    )),

    /*
    | العودة (Comeback): غياب عن **لعب رسمي موثوق** (لا تسجيل دخول) بمقدار min_inactive_days يومًا أو أكثر.
    | تزول الحالة تلقائيًا عند أول حل رسمي صحيح (last_active_date يتحدّث). بلا مكافأة جواهر افتراضيًا.
    */
    'comeback' => [
        'min_inactive_days' => (int) env('LIVEOPS_COMEBACK_MIN_DAYS', 7),
        // بعد هذا الحد لا نرسل إشعارًا (اللاعب غائب منذ زمن طويل؛ لا إلحاح). البطاقة داخل المنصة تبقى.
        'notify_max_days' => (int) env('LIVEOPS_COMEBACK_NOTIFY_MAX_DAYS', 45),
    ],

    /*
    | إشعارات LiveOps: تعيد استخدام E15 كاملًا (Dispatcher + تفضيلات الفئة). لا فئة جديدة ولا استعادة إلحاحية.
    | الافتراضات متحفظة: العودة والمهام الأسبوعية متوقفتان؛ تنبيه انتهاء المهمة وبدء الحدث مفعّلان.
    */
    'notifications' => [
        'mission_expiring' => (bool) env('LIVEOPS_NOTIFY_MISSION_EXPIRING', true),
        'weekly_missions_available' => (bool) env('LIVEOPS_NOTIFY_WEEKLY_MISSIONS', false),
        'event_starting_soon' => (bool) env('LIVEOPS_NOTIFY_EVENT_STARTING', true),
        'comeback_available' => (bool) env('LIVEOPS_NOTIFY_COMEBACK', false),

        // سقف يومي لإشعارات LiveOps لكل مستخدم (فوق ميزانية E15 للتذكيرات؛ الأقل يسود).
        'daily_cap' => (int) env('LIVEOPS_NOTIFY_DAILY_CAP', 1),
        'chunk_size' => (int) env('LIVEOPS_NOTIFY_CHUNK', 200),

        // تنبيه "تنتهي مهمتك اليوم": يُرسل فقط للمهام الجارية غير المكتملة، وفي هذه الساعات الأخيرة من اليوم (بتوقيت المنصة).
        'mission_expiring_hours_before_end' => (int) env('LIVEOPS_MISSION_EXPIRING_HOURS', 4),
        'event_starting_window_hours' => (int) env('LIVEOPS_EVENT_STARTING_HOURS', 24),
    ],

    /*
    | ميزانية الأمان للجدولة: تحذيرات (لا تمنع إجراءً إداريًا صالحًا) وحدّ صلب واحد لعدد المهام الفعّالة المتزامنة.
    | لا نموذج مالي مخترَع: المقارنة بمجموع جواهر المكافآت المُعلَنة فقط. الرعاية لا تُطبَّق على المهام تلقائيًا.
    */
    'budget' => [
        'max_concurrent_missions_warn' => (int) env('LIVEOPS_MAX_CONCURRENT_WARN', 12),
        'max_concurrent_missions_hard' => (int) env('LIVEOPS_MAX_CONCURRENT_HARD', 40),
        'daily_gems_warn' => (int) env('LIVEOPS_DAILY_GEMS_WARN', 150),
        'weekly_gems_warn' => (int) env('LIVEOPS_WEEKLY_GEMS_WARN', 600),
    ],

    // حدّ معدّل إجراءات LiveOps الإدارية لكل مشرف (صلاحية liveops.manage + تدقيق + تفريغ كاش).
    'limits' => [
        'action_per_minute' => (int) env('LIVEOPS_ACTION_PER_MINUTE', 30),
    ],

    /*
    | تحليلات الاحتفاظ: مُجمَّعة فقط، على اللعب الرسمي الصحيح (لا الدخول)، بلا SDK خارجي.
    */
    'analytics' => [
        'cache_seconds' => (int) env('LIVEOPS_ANALYTICS_CACHE_SECONDS', 300),
        'window_days' => (int) env('LIVEOPS_ANALYTICS_WINDOW_DAYS', 30),
        'cohort_min_size' => (int) env('LIVEOPS_COHORT_MIN_SIZE', 5),
    ],
];
````

## `database/migrations/2026_10_28_000001_create_mission_pools_tables.php`

````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E34 - مجموعات المهام (إعدادات فقط؛ لا تاريخ مالي ولا تقدّم هنا).
 *
 * المجموعة لا تُنشئ محرك مهام: هي قائمة اختيار فوق QuestDefinition الموجودة. العضوية (mission_pool_quests) تُبقي
 * المهمة المنتمية لمجموعة غير ظاهرة للجميع؛ تظهر فقط لمن أُسندت له (انظر user_mission_assignments).
 *
 * سياسة الحذف:
 *  - حذف المجموعة يحذف عضويتها فقط (cascade). وحذف مهمة غير مستعملة يزيل عضويتها (cascade) بلا أثر على أي تاريخ.
 *  - المنشئ SET NULL (حذفه لا يحذف المجموعة).
 *  - لا أعمدة مالية: كل المكافآت تبقى بتعريف المهمة نفسه (سلطة الاقتصاد الوحيدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_pools', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();
            $table->string('period_type', 16);                              // daily | weekly (نفس QuestDefinition::PERIOD_TYPES)
            $table->unsignedTinyInteger('selection_count')->default(3);
            $table->unsignedSmallInteger('priority')->default(100);         // الأصغر أولًا؛ أول مجموعة مؤهِّلة تُسند للمستخدم
            $table->string('eligibility_rule', 24)->default('all');        // all | new_player | returning | experienced
            $table->boolean('personalized')->default(false);                // تفضيل شفاف قائم على قواعد فقط
            $table->boolean('is_active')->default(false);                   // تُنشأ مُعطَّلة: لا إسناد قبل تفعيل صريح
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'period_type', 'priority'], 'mission_pools_active_period_priority_idx');
        });

        Schema::create('mission_pool_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_pool_id')->constrained('mission_pools')->cascadeOnDelete();
            $table->foreignId('quest_definition_id')->constrained('quest_definitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['mission_pool_id', 'quest_definition_id'], 'mission_pool_quests_pool_quest_unique');
            $table->index('quest_definition_id', 'mission_pool_quests_quest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_pool_quests');
        Schema::dropIfExists('mission_pools');
    }
};
````

## `database/migrations/2026_10_28_000002_create_user_mission_assignments_table.php`

````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E34 - لقطة إسناد المهام لكل مستخدم لكل فترة (تحدّد **الظهور** فقط؛ التقدّم والمكافأة يبقيان في user_quest_progress).
 *
 * حدود السلامة في قاعدة البيانات:
 *  - UNIQUE(user_id, quest_definition_id, period_key) ⇒ مهمة واحدة لا تُسند لمستخدم مرتين في الفترة نفسها،
 *    مهما تزامن الطلبان؛ وهي أيضًا الحاجز الأخير خلف قفل صف المستخدم في MissionAssignmentService.
 *  - الإسناد كسول: لا صفوف جماعية مسبقة؛ يُنشأ لمستخدم واحد عند فتح فعلي أو حدث لعب رسمي، وبحدّ selection_count لكل مجموعة.
 *  - اللقطة ثابتة طوال الفترة: تعديل المجموعة لاحقًا لا يبدّل ما أُسند (يسري على الفترة التالية).
 * سياسة الحذف: المستخدم/المهمة cascade (لقطة ظهور مشتقة بلا قيمة مالية؛ المكافأة الفعلية بدفاتر الاقتصاد)، المجموعة RESTRICT
 * (لا تُحذف مجموعة لها تاريخ إسناد؛ تُعطَّل بدلًا من ذلك).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mission_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mission_pool_id')->constrained('mission_pools')->restrictOnDelete();
            $table->foreignId('quest_definition_id')->constrained('quest_definitions')->cascadeOnDelete();
            $table->string('period_key', 32);
            $table->unsignedTinyInteger('slot')->default(0);
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['user_id', 'quest_definition_id', 'period_key'], 'user_mission_assignments_user_quest_period_unique');
            $table->index(['user_id', 'period_key'], 'user_mission_assignments_user_period_idx');
            $table->index(['mission_pool_id', 'period_key'], 'user_mission_assignments_pool_period_idx');
            $table->index('quest_definition_id', 'user_mission_assignments_quest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mission_assignments');
    }
};
````

## `database/seeders/DemoE34Seeder.php`

````php
<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionPoolService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E34 - بيانات Demo لمركز المهام والتقويم والعودة.   php artisan db:seed --class=DemoE34Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق liveops.test).
 * - المجموعات والتفعيل عبر الخدمة الرسمية (MissionPoolService: صلاحية + تدقيق + حد معدّل)، لا كتابة مباشرة لحالتها.
 * - Idempotent: كل سجل بمفتاح/slug/بريد ثابت (updateOrCreate / فحص وجود) ⇒ إعادة التشغيل لا تكرّر شيئًا.
 * - تولّد: مهمة يومية عامة، مهمة أسبوعية عامة، مجموعة مهام يومية (4 مهام، تُسند مهمتان لكل لاعب)، حدثان (جارٍ + قادم)،
 *   لاعب بسلسلة 7 أيام فعّالة، ولاعب مؤهَّل لتجربة العودة (آخر لعب قبل 10 أيام). لا جواهر ولا مكافآت عملة.
 */
class DemoE34Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-liveops-admin@liveops.test';

    public const STREAK_EMAIL = 'demo-e34-streak@liveops.test';

    public const COMEBACK_EMAIL = 'demo-e34-comeback@liveops.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE34Seeder');
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(StreakMilestoneAchievementsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير LiveOps التجريبي');
        $admin->syncRoles(['administrator']);

        $this->quest('demo_e34_daily_solve_2', 'حلّ أحجيتين اليوم', QuestDefinition::PERIOD_DAILY, 2, 5, 1);
        $this->quest('demo_e34_weekly_solve_8', 'ثماني أحجيات هذا الأسبوع', QuestDefinition::PERIOD_WEEKLY, 8, 20, 2);

        $pool = $this->pool($admin);

        $this->campaign('demo-e34-live', 'حملة تجريبية جارية', now()->subDays(2), now()->addDays(2));
        $this->campaign('demo-e34-upcoming', 'حملة تجريبية قادمة', now()->addDays(3), now()->addDays(10));
        EventCalendarService::flush();
        MissionAssignmentService::flushSnapshot();

        $streak = $this->user(self::STREAK_EMAIL, 'لاعب بسلسلة (تجريبي)');
        $this->streak($streak, 7, 7, now()->toDateString());

        $comeback = $this->user(self::COMEBACK_EMAIL, 'لاعب عائد (تجريبي)');
        $this->streak($comeback, 3, 5, now()->subDays(10)->toDateString());

        $this->command?->info('DemoE34: مهام='.QuestDefinition::query()->where('internal_key', 'like', 'demo_e34_%')->count()
            .' · مجموعة='.$pool->slug.' ('.($pool->fresh()->is_active ? 'فعّالة' : 'معطّلة').')');
        $this->command?->info('حسابات: '.self::ADMIN_EMAIL.' ، '.self::STREAK_EMAIL.' ، '.self::COMEBACK_EMAIL.' / '.self::PASSWORD);
    }

    protected function quest(string $key, string $name, string $period, int $target, int $xp, int $sort): QuestDefinition
    {
        $existing = QuestDefinition::query()->where('internal_key', $key)->first();

        if ($existing !== null) {
            return $existing; // لا نمسّ مهمة قد صارت مستعملة (الحارس يمنع تغيير حقولها الحساسة)
        }

        return QuestDefinition::query()->create([
            'internal_key' => $key, 'name' => $name, 'description' => 'مهمة تجريبية.', 'period_type' => $period,
            'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED, 'target_value' => $target, 'xp_reward' => $xp,
            'is_active' => true, 'sort_order' => $sort,
        ]);
    }

    protected function pool(User $admin): MissionPool
    {
        $service = app(MissionPoolService::class);
        $pool = MissionPool::query()->where('slug', 'demo-e34-daily-pool')->first()
            ?? $service->create($admin, [
                'name' => 'مجموعة المهام اليومية التجريبية', 'slug' => 'demo-e34-daily-pool', 'description' => 'تجريبية',
                'period_type' => QuestDefinition::PERIOD_DAILY, 'selection_count' => 2, 'priority' => 100, 'eligibility_rule' => 'all', 'personalized' => true,
            ]);

        $members = DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->pluck('quest_definition_id')->all();

        foreach ([['a', 1, 3], ['b', 2, 5], ['c', 3, 8], ['d', 1, 4]] as $i => [$suffix, $target, $xp]) {
            $quest = $this->quest("demo_e34_pool_{$suffix}", "مهمة المجموعة {$suffix}", QuestDefinition::PERIOD_DAILY, $target, $xp, 10 + $i);

            if (! in_array($quest->id, $members, true)) {
                $service->attach($admin, $pool, $quest, $i);
            }
        }

        if (! $pool->is_active) {
            $pool = $service->activate($admin, $pool);
        }

        return $pool;
    }

    protected function campaign(string $slug, string $title, $starts, $ends): void
    {
        if (Campaign::query()->where('slug', $slug)->exists()) {
            return;
        }

        Campaign::query()->create(['slug' => $slug, 'title' => $title, 'description' => 'حملة تجريبية لعرض التقويم.', 'is_active' => true, 'starts_at' => $starts, 'ends_at' => $ends]);
    }

    protected function streak(User $user, int $current, int $longest, string $lastActive): void
    {
        PlayerStreak::query()->updateOrCreate(['user_id' => $user->id], [
            'current_streak' => $current, 'longest_streak' => $longest, 'last_active_date' => $lastActive, 'last_qualified_at' => $lastActive.' 12:00:00',
        ]);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }
}
````

## `database/seeders/StreakMilestoneAchievementsSeeder.php`

````php
<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Services\LiveOps\StreakMilestoneService;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Illuminate\Database\Seeder;

/**
 * معالم السلسلة اليومية (E34): اختيارية، تُشغَّل يدويًا (`db:seed --class=StreakMilestoneAchievementsSeeder`) وآمنة لإعادة التشغيل
 * (updateOrCreate بالمفتاح الداخلي). **بلا مكافآت** (xp_reward = 0): تقدير فقط؛ يضبط المدير أي مكافأة صغيرة من لوحة الإنجازات.
 * لا يُعدّل معلمًا صار مستعملًا: الحارس يمنع تغيير الحقول الحساسة، فنتجاوز الصفوف المستعملة بدل رمي استثناء.
 */
class StreakMilestoneAchievementsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (app(StreakMilestoneService::class)->thresholds() as $i => $days) {
            $key = "streak_{$days}_days";
            $existing = Achievement::query()->where('internal_key', $key)->first();

            if ($existing !== null && $existing->isUsed()) {
                continue;
            }

            Achievement::query()->updateOrCreate(['internal_key' => $key], [
                'name' => "سلسلة {$days} يومًا",
                'description' => "لعبت أحجية رسمية صحيحة {$days} أيام متتالية.",
                'category' => Achievement::CATEGORY_GENERAL,
                'condition_type' => AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS,
                'target_value' => $days,
                'xp_reward' => 0,
                'is_active' => true,
                'is_hidden' => false,
                'sort_order' => 200 + $i,
            ]);
        }
    }
}
````

## `docs/liveops-retention-e34.md`

````markdown
# مركز العمليات الحيّة والاحتفاظ (E34): المهام، الأحداث، السلسلة، العودة

> طبقة **ظهور وجدولة وتحفيز** فوق المحركات القائمة؛ ليست محرك مهام/سلسلة/إنجازات ثانيًا.
> **لا حزمة Composer/npm جديدة، لا مزوّد دفع إشعارات، لا SDK تحليلات خارجي، لا محفظة جديدة.** المكافآت تمرّ حصرًا عبر سلطة الاقتصاد القائمة (`ProgressionRewardService` / `GemWalletService` بالمفاتيح الدلالية نفسها).

## القواعد المغلقة (لا يضبطها أي إعداد)

1. **لا محرك ثانٍ.** التقدّم يبقى في `UserQuestProgress` عبر `QuestService` + `QuestEvaluatorRegistry` + `QuestPeriodService` (`PeriodContext`)، والسلسلة في `StreakService`/`PlayerStreak`، والمعالم إنجازات (`Achievement`/`UserAchievementProgress`). الإسناد يحدّد **ظهور** مهمة لمستخدم فقط.
2. **لا مكافأة لمجرد تسجيل الدخول** ولا سلسلة دخول. «لعب ذو معنى» = حل **رسمي** صحيح (`puzzle_attempts.is_correct`).
3. **لا عقوبة على غياب يوم**، ولا استرداد لمكافأة سبق اكتسابها (لا مسار حذف/خصم في الكود)، ولا حماية سلسلة مدفوعة ولا تجميد/شراء سلسلة (`streak freeze` غير موجود عمدًا).
4. **لا استعجال مصطنع:** العدّ التنازلي يُشتق خادميًا من تاريخ حقيقي فقط، وبلا تاريخ لا عدّاد. اختبار سطح E23 يمنع عبارات الضغط.
5. **أحجيات المجتمع وسلاسل المبدعين صفرية الاقتصاد:** لا تُحتسب تقدّم مهمة ولا سلسلة ولا عودة ولا تحليلات (لا تكتب `puzzle_attempts`).
6. **لا تقدّم ولا مكافأة يتحكم بهما العميل:** كل شيء من وقائع الخادم. نقرات الإحالة والدعم والدفع لا تُحتسب تقدّمًا.
7. **لا إشعار مزعج:** سقف يومي + ميزانية E15 (الأقل يسود) + مفاتيح idempotency + تفضيلات المستخدم القائمة. الافتراضي متحفّظ (العودة والمهام الأسبوعية معطّلتان).
8. MySQL هي مرجع الإنتاج؛ SQLite للاختبار السريع.

## 1) مركز المهام `/missions`

- المسار الأساسي `GET /missions` (`missions.index`). المسار القديم `GET /quests` (`quests.show`) يخدم **الصفحة نفسها بـ200 بلا تحويل** فلا تنكسر روابط/إشعارات/اختبارات E13. الاثنان خلف `throttle:missions-read`، ومصادَقان.
- الأقسام: مهام اليوم، مهام الأسبوع، السلسلة ومعالمها، إنجازات قريبة من الاكتمال، أجندة/أحداث جارية، بطاقة العودة (عند الأهلية فقط).
- تحافظ الصفحة على الشفاء الذاتي لـE13 (`syncCurrentQuests`) عند الفتح الفعلي، وتُنشئ صفوف التقدّم للمهام **الظاهرة** فقط.
- عنصر اللوحة الرئيسية (`home/_missions-widget`): 2–3 مهام أولوية + السلسلة + أقرب حدث، قراءة فقط.

## 2) مجموعات المهام (Mission Pools) والتدوير

**المعمارية:** `QuestDefinition` تبقى تعريف المهمة ومكافأتها. `MissionPool` قائمة اختيار فوقها (`mission_pool_quests` عضوية). لا مكافآت بالمجموعة.

| الجدول | الدور | سياسة الحذف |
|---|---|---|
| `mission_pools` | الإعدادات: `slug` فريد، `period_type` (daily/weekly)، `selection_count`، `priority`، `eligibility_rule`، `personalized`، `is_active` (تُنشأ **معطَّلة**)، نافذة اختيارية | المنشئ SET NULL |
| `mission_pool_quests` | العضوية (`UNIQUE(pool, quest)`) | حذف المجموعة أو المهمة ⇒ cascade للعضوية فقط |
| `user_mission_assignments` | لقطة الإسناد لكل مستخدم/فترة (`UNIQUE(user, quest, period_key)`) | المستخدم/المهمة cascade، المجموعة **RESTRICT** (مجموعة لها تاريخ تُعطَّل ولا تُحذف) |

**الإسناد الكسول:** لا صفوف جماعية. يُنشأ للمستخدم عند فتح فعلي أو حدث لعب رسمي، داخل معاملة تقفل صف المستخدم (`lockForUpdate`) مع إعادة فحص تحت القفل، ثم `insertOrIgnore` فوق `UNIQUE` كحاجز أخير ⇒ لا تكرار تحت التزامن.

**الاختيار الحتمي:** `sha1(user_id | period_key | pool_id)` يولّد بذرة، وترتيب المهام بـ`sha1(seed | quest_id)`؛ لا `random()` ولا حالة خفية. نفس المدخلات = نفس المهام.

**الثبات خلال الفترة:** بعد أن يملك المستخدم لقطة للفترة، لا تغيّرها تعديلات المجموعة؛ التعديل يسري على الفترة التالية. مهمة تقدّم المستخدم فيها (صف تقدّم قائم) تبقى ظاهرة له (استمرارية) حتى لو خرجت من المجموعة.

**التوافق مع المهام العامة:** مهمة غير منتمية لأي مجموعة = مهمة عامة تظهر للجميع كما كانت. مهمة منتمية لمجموعة **فعّالة** تظهر فقط لمن أُسندت له (أو له تقدّم قائم). فصل المهمة من مجموعتها الوحيدة يعيدها عامة (تحذير بالواجهة). مهمة في مجموعة معطَّلة غير ظاهرة. أي خطأ بحث في مسار مهمة منتمية لمجموعة **يغلق بالرفض** (fail-closed).

**قواعد الأهلية المغلقة** (`MissionEligibilityRule`): `all` · `new_player` (عمر الحساب ≤ `LIVEOPS_NEW_PLAYER_DAYS`) · `returning` (غياب ≥ `LIVEOPS_COMEBACK_MIN_DAYS`) · `experienced` (مستوى ≥ `LIVEOPS_EXPERIENCED_LEVEL`). أول مجموعة مؤهِّلة بحسب `priority` تُسند.

**التخصيص (اختياري لكل مجموعة، شفاف وقائم على قواعد):** +3 تقدّم غير مكتمل قائم بالمهمة، +2 فئة رسمية حُلّت مؤخرًا (`LIVEOPS_RECENT_CATEGORY_DAYS`)، +2 نشاط حملة. لا ML ولا بيانات خاصة. **مستبعد صراحةً:** الدردشة، المدفوعات، مبالغ الرعاية، أعلام الاحتيال، البريد، الملف الخاص.

## 3) تقويم الأحداث `/events`

- مصادر موجودة فقط (لا جدول أحداث جديد): موسم، حملة، منافسة رسمية، بطولة فرق، حملة إحالة، برنامج رعاية (نوع `EventItem::type`).
- `EventItem` DTO للقراءة فقط: `type, key, title, startsAt, endsAt, status, url, badge, image`. الحالة (`upcoming|live|ending_soon|ended`) تُحسب **وقت القراءة** من تواريخ حقيقية؛ لقطة مشتركة عامة مخزَّنة `liveops:calendar:v1` (مصفوفات بسيطة بلا بيانات مستخدم).
- الأقسام: الآن / اليوم / هذا الأسبوع / القادم، بتوقيت المنصة (`config('app.timezone')`، الأسبوع الاثنين–الأحد)، وأجندة مختصرة تحدّها `LIVEOPS_AGENDA_MAX`. المنافسات المسجَّل بها المستخدم تُبرز في أجندته.
- المسار `GET /events` خلف `throttle:events-read` وعام (لا بيانات شخصية).

## 4) السلسلة ومعالمها

- المصدر الموثوق: `PlayerStreak` عبر `StreakService` (سلسلة **لعب رسمي** لا دخول). `current_streak` المخزَّن لا يتناقص بالزمن، لذا العرض يستعمل **السلسلة الفعّالة** (`StreakMilestoneService::effectiveCurrent`: آخر نشاط اليوم أو أمس).
- المعالم (افتراضيًا 3/7/14/30/60/100، `LIVEOPS_STREAK_MILESTONES`) **إنجازات** نوعها `streak_longest_days` (`StreakLongestDaysEvaluator` مسجَّل في `AchievementEvaluatorRegistry`)، تُبذر بـ`StreakMilestoneAchievementsSeeder`. المنح عبر نظام الإنجازات وسلطة `ProgressionRewardService` (idempotent) - لا منطق منح موازٍ.
- الخطاف: `GameplayProgressionService::afterPuzzleSolved` ⇒ `StreakMilestoneService::onStreakUpdated` معزول بـ`try/catch` فلا يكسر اللعب.
- **سياسة مكافآت المعالم:** البذر ينشئ المعالم **بلا مكافأة** (`xp_reward = 0`): تقدير فقط. إن أراد المدير مكافأة صغيرة فمن لوحة الإنجازات القائمة (XP عبر `ProgressionRewardService` بمفتاح idempotent)، ولا جواهر ولا مسار منح موازٍ. **لا تجميد سلسلة ولا شراء.** أطول سلسلة تُحفظ، وكسر السلسلة لا يسحب إنجازًا مكتسبًا.
- «المعلم التالي» = أول عتبة > أطول سلسلة، و«الأيام المتبقية» = `max(0, next - الفعّالة)`.

## 5) رحلة العودة (Comeback)

- **الأهلية:** غياب عن **لعب رسمي صحيح** ≥ `LIVEOPS_COMEBACK_MIN_DAYS` (7 افتراضيًا) بحسب `last_active_date`. فتح الموقع لا يُنشئ الحالة ولا ينهيها.
- **الزوال:** تلقائيًا عند أول حل رسمي صحيح (لا حالة مخزَّنة ولا جدول). لا انتظار ولا إجراء يدوي.
- **المحتوى:** ترحيب + ≤3 مقترحات سهلة (أحجية رسمية سهلة لم تُحلّ - اختيار حتمي ثابت طوال اليوم، حملة قائمة/موسم، مهمة خفيفة). لا لغة ضغط ولا عقوبة.
- **المكافأة:** **لا جواهر للعودة** (لا مفتاح لها في `config/liveops.php` عمدًا). قرار مغلق: مكافأة العودة بجواهر تُشجّع التجاهل ثم الرجوع المصطنع.
- **الإشعار:** `ComebackAvailable` معطّل افتراضيًا، ولا يُرسل بعد `LIVEOPS_COMEBACK_NOTIFY_MAX_DAYS` (45).

## 6) إشعارات LiveOps (إعادة استخدام E15 كاملًا)

| النوع | الشرط | الافتراضي |
|---|---|---|
| `MissionExpiring` | مهمة **جارية غير مكتملة** وفي الساعات الأخيرة من اليوم (`LIVEOPS_MISSION_EXPIRING_HOURS`) | مفعّل |
| `WeeklyMissionsAvailable` | يوم الاثنين (بتوقيت المنصة، مرة واحدة للأسبوع بمفتاح idempotency) وتوجد مهام أسبوعية فعّالة، **للاعبين النشطين مؤخرًا فقط** (`player_notifications.recent_activity_days`) | معطّل |
| `EventStartingSoon` | منافسة يبدأ موعدها ضمن `LIVEOPS_EVENT_STARTING_HOURS` **للمسجَّلين فقط** | مفعّل |
| `ComebackAvailable` | الأهلية أعلاه | معطّل |

- ليست «إعادة تفاعل» ولا فئة جديدة: تُعامل بتفضيلات الفئة القائمة (E15). كل إرسال بمفتاح idempotency (`مستخدم + نوع + مرجع/فترة`) فلا تكرار عند إعادة تشغيل الجدولة.
- السقف: `LIVEOPS_NOTIFY_DAILY_CAP` (1) لكل مستخدم، مع احتساب `usedToday` لميزانية E15 (الأقل يسود).
- الجدولة: `liveops:remind` **ساعيًا** (بعد تذكيرات E15 كي تُحتسب ميزانيتها أولًا)، مغلَّفًا بالحارس المعتاد `$guard` (مهلة 120 دقيقة) كبقية المهام المجدولة. المعالجة على دفعات `chunk/chunkById` بحجم `LIVEOPS_NOTIFY_CHUNK` (200) فلا تحميل كامل للمستخدمين.

## 7) لوحة الإدارة

- **مجموعات المهام** (`MissionPoolResource`): إنشاء/تعديل، تفعيل/تعطيل عبر الخدمة، إدارة العضوية (`QuestsRelationManager`)، **بلا حذف** (تعطيل فقط). `period_type` لا يتغيّر بعد وجود مهام/تاريخ.
- **المخطِّط** (`/admin/liveops-planner`): الأحداث الحالية، ملخص المهام (عامة/مجموعات)، الحدّ الأعلى النظري للمهام والمكافآت لمستخدم واحد، وتحذيرات التعارض:
  `too_many_missions`، `reward_heavy_daily|weekly`، `invalid_window`، `expired_but_active`، `pool_thin`، `duplicate_campaign_overlap`، و`missions_over_hard_cap` (حرج).
- **الحدّ الصلب الوحيد:** تفعيل مجموعة يرفع الحدّ النظري لمهام المستخدم المتزامنة فوق `LIVEOPS_MAX_CONCURRENT_HARD` يُرفض (`hard_cap`)؛ بقية الإفراط **تحذيرات** لا تمنع إجراءً إداريًا صالحًا.
- **تحليلات** (`/admin/liveops-analytics`): انظر القسم 8.
- **مهام الإدارة القائمة** (`QuestDefinitionResource`): عمود جدولة (جارية/مجدولة/منتهية/معطّلة)، عدد المجموعات والتقدّم، `ends_at` بعد `starts_at`، و**منع تغيير `period_type`** لمهمة لها تقدّم أو عضوية مجموعة (واجهة + `QuestDefinitionInvariantGuard`).
- **الصلاحيات:** `liveops.view` (قراءة المخطِّط والتحليلات) و`liveops.manage` (كتابة). كل كتابة: صلاحية + حدّ معدّل لكل مشرف (`LIVEOPS_ACTION_PER_MINUTE`) + سجل تدقيق (`OperationalAuditService`) + معاملة + تفريغ كاش اللقطة.

## 8) تحليلات الاحتفاظ (مُجمَّعة فقط)

بلا صفوف مستخدمين ولا أسماء ولا تتبّع جهاز. كاش قصير مشترك (`LIVEOPS_ANALYTICS_CACHE_SECONDS`) لأنه بلا بيانات شخصية.

- **إكمال المهام** (يومية/أسبوعية): `completed / total` لصفوف `user_quest_progress` ضمن النافذة. المقام = «معرَّضون للمهمة» لا «كل المسجَّلين» (الصف كسول).
- **المشاركة:** مستخدمون مميَّزون بتقدّم > 0 لكل فترة.
- **توزيع السلسلة:** **الفعّالة** فقط، بأقسام من المعالم المضبوطة.
- **مشاركة الأحداث:** تسجيلات المنافسات + مستخدمو الحملات المميَّزون.
- **العائدون:** لعبوا ضمن النافذة وكان آخر لعب لهم قبل أول لعبة بالنافذة بـN يومًا فأكثر.
- **D1/D7/D30 (اليوم-المحدّد، لا «في أي وقت بعد»):** لفوج التسجيل بتاريخ محلي `d` **المنضج فقط** (`d + N ≤ اليوم`): نسبة من لعب لعبًا ذا معنى في اليوم `d+N` **بالضبط** إلى حجم الفوج (يشمل من لم يلعب قط). فوج أصغر من `LIVEOPS_COHORT_MIN_SIZE` ⇒ `null` («غير كافٍ») لا نسبة مضلِّلة.
- «لعب ذو معنى» = محاولة رسمية صحيحة. تسجيل الدخول وحده لا يُحسب. اليوم = تاريخ المنصة المحلي.

## 9) العزل عن بقية المنظومات

| المنظومة | حدّ العزل |
|---|---|
| المجتمع (E28) | أحجياته لا تكتب `puzzle_attempts`؛ لا تقدّم مهام/سلسلة/تحليلات من حلّها |
| سلاسل المبدعين (E30) | صفرية الاقتصاد؛ لا تدخل مهامًا ولا سلسلة ولا عودة |
| الإحالات (E33) | نقرات الإحالة لا تُحتسب تقدّمًا؛ حملات الإحالة تظهر في التقويم كقراءة فقط |
| الدفع والدعم (E32) | لا تأثير على المهام/السلسلة؛ لا إشارات دفع في الإسناد |
| الرعاية (E30) | مبالغها لا تُقرأ في الإسناد ولا في المخطِّط؛ لا تُطبَّق على المهام تلقائيًا |
| الاقتصاد | المكافآت بمفاتيح `quest-progress:{id}:xp` وما شابه (idempotent)؛ لا مسار جديد لكتابة الدفتر |

## 10) الأداء والكاش

- لقطة المجموعات المشتركة `liveops:mission-pools:v1` (بلا بيانات مستخدم؛ تُفرَّغ بكل تعديل إداري)، لقطة التقويم `liveops:calendar:v1`، التحليلات `liveops:analytics:v1:{days}`.
- حصة E34 من استعلامات الصفحة **ثابتة** مستقلة عن عدد المهام/المجموعات/الأحداث (اختبارات Z11): قراءة اللقطة ≤2 (0 مع الكاش)، ودون مجموعات ≤2 إضافيان؛ ودجت الرئيسية 14؛ المخطِّط 11؛ التقويم 0 مع الكاش و6 دونه؛ التحليلات 24.
- **تكلفة E13 قائمة سابقة (ليست N+1 من E34):** صفحة `/missions` تكلّف ≈3.5–4.4 استعلام لكل مهمة ظاهرة بسبب الشفاء الذاتي `syncCurrentQuests` الموروث. حارس انحدار في Z11-04 (≤5 لكل مهمة).
- لا N+1 في الإسناد (استعلام مجمَّع واحد لكل فترة + memo بعمر المثيل).

## 11) قاعدة البيانات (MySQL)

- ترحيلان جديدان فقط، ولا تعديل لأي ترحيل قديم: `2026_10_28_000001_create_mission_pools_tables` و`2026_10_28_000002_create_user_mission_assignments_table`.
- لا `float/decimal`، أسماء الفهارس ≤64 حرفًا، كل FK مغطّى بفهرس، لا أعمدة مالية (لا تنطبق U-2/U-3). فهارس: `mission_pools(is_active, period_type, priority)`، `user_mission_assignments(user_id, period_key)`، `(mission_pool_id, period_key)`، `(quest_definition_id)`، `UNIQUE(user, quest, period_key)`.
- اختبارات Z10-08/09 (MySQL فقط): الإجراءات المرجعية والمحرّك والـcharset وطول المفاتيح، و`INSERT IGNORE` على المفتاح الفريد.

## 12) بيانات العرض

```powershell
php artisan db:seed --class=DemoE34Seeder    # idempotent، يرفض الإنتاج (RefusesProduction)
```

حسابات (كلمة المرور `password`): `demo-liveops-admin@liveops.test` (مشرف) · `demo-e34-streak@liveops.test` (سلسلة 7/7 اليوم) · `demo-e34-comeback@liveops.test` (غائب 10 أيام). تُنشأ مهام `demo_e34_*` ومجموعة `demo-e34-daily-pool` (4 مهام، اختيار 2، مخصَّصة، مفعَّلة عبر الخدمة) وحملتان `demo-e34-live` و`demo-e34-upcoming`.

## 13) الإعدادات (`config/liveops.php`، كلها `LIVEOPS_*` اختيارية)

انظر كتلة `# E34` في `.env.example`.

## 14) الاختبارات

`tests/Feature/LiveOpsE34/` (112 اختبارًا، Z1–Z12): مركز المهام، التدوير/المجموعات، تقدّم المهام، معالم السلسلة، العودة، التقويم، الإشعارات، الإدارة، التحليلات، التزامن (+MySQL)، الأداء، بيانات الديمو.

```powershell
php vendor/bin/pest tests/Feature/LiveOpsE34
# MySQL (قاعدة اختبار فقط؛ تنتهي بـ_test_mysql):
$env:APP_ENV="testing"; $env:DB_HOST="127.0.0.1"; $env:DB_PORT="3306"; $env:DB_DATABASE="ahjiyat_platform_test_mysql"; $env:DB_USERNAME="root"; $env:DB_PASSWORD="<كلمة مرور قاعدة الاختبار>"
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/LiveOpsE34
```

## 15) حدود معروفة

1. مقياس **العائدون** نسبي لبداية النافذة (لا يلتقط من غاب وعاد داخلها).
2. فصل مهمة من مجموعتها **الوحيدة** يعيدها مهمة عامة (محذَّر بالواجهة والوثائق).
3. مهمة في مجموعة **معطَّلة** غير ظاهرة لأحد (لا تعود عامة بصمت).
4. تكلفة `syncCurrentQuests` لكل مهمة موروثة من E13 (تحسين مؤجَّل: تقليل/كاش المزامنة).
5. لا اختبار حمل حقيقي على MySQL بملايين الصفوف (اختبار ذخان 100 مستخدم × 3 فقط).
````

## `resources/views/events/_item.blade.php`

````blade
{{--
    E34: صف حدث واحد (تقويم/مركز المهام). الحالة والعدّ مشتقان خادميًا من تواريخ حقيقية فقط؛ لا عدّ بلا تاريخ. العناوين تُهرَّب. بلا ألوان ثابتة.
    @var \App\Services\LiveOps\Calendar\EventItem $item
--}}
@php
    $tone = match ($item->status) {
        \App\Services\LiveOps\Calendar\EventCalendarService::STATUS_LIVE => 'success',
        \App\Services\LiveOps\Calendar\EventCalendarService::STATUS_ENDING_SOON => 'warning',
        default => 'primary',
    };
    $cd = $item->countdownTo();
    $remaining = $cd ? \App\Services\LiveOps\MissionCenterService::formatRemaining(now(), $cd) : null;
    $upcoming = $item->status === \App\Services\LiveOps\Calendar\EventCalendarService::STATUS_UPCOMING;
@endphp
<li class="puzzle-card !p-4 flex items-start gap-3">
    @if ($item->image)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($item->image) }}" alt="" loading="lazy" class="w-14 h-14 rounded-xl object-cover shrink-0">
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2 mb-1">
            <x-status-badge :tone="$tone" small>{{ $item->statusLabel() }}</x-status-badge>
            <x-status-badge small>{{ $item->badge }}</x-status-badge>
        </div>
        <h3 class="font-bold text-white break-words"><a href="{{ $item->url }}" class="hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $item->title }}</a></h3>
        <p class="text-xs text-slate-400 mt-1">
            @if ($item->startsAt)
                <span>يبدأ: <time datetime="{{ $item->startsAt->toIso8601String() }}">{{ $item->startsAt->format('Y-m-d H:i') }}</time></span>
            @endif
            @if ($item->endsAt)
                <span class="ms-2">ينتهي: <time datetime="{{ $item->endsAt->toIso8601String() }}">{{ $item->endsAt->format('Y-m-d H:i') }}</time></span>
            @endif
            @if (! $item->startsAt && ! $item->endsAt)
                <span>متاح بلا موعد انتهاء محدد.</span>
            @endif
        </p>
        @if ($remaining)
            <p class="text-xs font-bold text-slate-300 mt-1">{{ $upcoming ? 'يبدأ بعد' : 'يتبقى' }} {{ $remaining }}</p>
        @endif
    </div>
</li>
````

## `resources/views/events/index.blade.php`

````blade
@extends('layouts.app')

@section('title', 'تقويم الأحداث')

@section('content')

    <div class="max-w-3xl mx-auto">

        <x-page-header title="تقويم الأحداث" icon="calendar" subtitle="المواسم والحملات والمنافسات القادمة والجارية في مكان واحد. التوقيت: {{ $timezone }}." class="anim-fade-up" />

        @php
            $blocks = [
                ['key' => 'now', 'title' => 'يحدث الآن', 'empty' => 'لا توجد أحداث جارية حاليًا.'],
                ['key' => 'today', 'title' => 'اليوم', 'empty' => 'لا أحداث تبدأ اليوم.'],
                ['key' => 'week', 'title' => 'هذا الأسبوع', 'empty' => 'لا أحداث أخرى هذا الأسبوع.'],
                ['key' => 'upcoming', 'title' => 'قريبًا', 'empty' => 'لا أحداث مجدولة لاحقًا حتى الآن.'],
            ];
        @endphp

        @auth
            @if ($agenda->isNotEmpty())
                <section class="mb-8 anim-fade-up" aria-labelledby="agenda-h">
                    <h2 id="agenda-h" class="font-display font-black text-lg text-white mb-3">أجندتي</h2>
                    <ul class="space-y-3">
                        @foreach ($agenda as $item)
                            @include('events._item', ['item' => $item])
                        @endforeach
                    </ul>
                </section>
            @endif
        @endauth

        @foreach ($blocks as $block)
            <section class="mb-8 anim-fade-up" aria-labelledby="ev-{{ $block['key'] }}">
                <h2 id="ev-{{ $block['key'] }}" class="font-display font-black text-lg text-white mb-3">{{ $block['title'] }}</h2>
                @if ($sections[$block['key']]->isEmpty())
                    <p class="text-sm text-slate-500">{{ $block['empty'] }}</p>
                @else
                    <ul class="space-y-3">
                        @foreach ($sections[$block['key']] as $item)
                            @include('events._item', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach

        @auth
            <p class="text-sm text-slate-400"><a href="{{ route('missions.index') }}" class="font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلى مهامي ←</a></p>
        @endauth

    </div>

@endsection
````

## `resources/views/filament/pages/liveops-analytics.blade.php`

````blade
<x-filament-panels::page>
    @php $d = $this->data(); $o = $d['overview']; $pct = fn ($r) => $r === null ? '—' : round($r * 100, 1).'%'; @endphp

    <div class="flex items-center gap-2 text-sm">
        <span>النافذة:</span>
        @foreach ([7, 30, 90] as $opt)
            <button type="button" wire:click="$set('days', {{ $opt }})" class="px-3 py-1 rounded-lg border {{ $d['days'] === $opt ? 'bg-primary-600 text-white' : 'border-gray-300' }}">{{ $opt }} يومًا</button>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">
        "لعب ذو معنى" = محاولة أحجية رسمية صحيحة (لا تسجيل الدخول، ولا أحجيات المجتمع). مجاميع فقط بلا أسماء. اليوم = التاريخ المحلي للمنصة.
        الصف يُنشأ كسولًا عند فتح مركز المهام أو حدث لعب، فمقام الإكمال = المعرَّضون للمهمة وليس كل المسجَّلين.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-liveops-stats>
        @foreach ([
            'إكمال المهام اليومية' => $pct($o['mission_completion']['daily']['rate']).' ('.$o['mission_completion']['daily']['completed'].'/'.$o['mission_completion']['daily']['total'].')',
            'إكمال المهام الأسبوعية' => $pct($o['mission_completion']['weekly']['rate']).' ('.$o['mission_completion']['weekly']['completed'].'/'.$o['mission_completion']['weekly']['total'].')',
            'مشاركون بالمهام (أحدث يوم)' => $o['participation']['daily']['latest'],
            'مشاركون بالمهام (أحدث أسبوع)' => $o['participation']['weekly']['latest'],
            'مسجَّلو المنافسات' => $o['event_participation']['competitive_users'],
            'مشاركو الحملات' => $o['event_participation']['campaign_users'],
            'العائدون' => $o['returners']['returners'].' من '.$o['returners']['active_in_window'],
            'نسبة العودة (غياب ≥ '.$o['returners']['min_inactive_days'].' أيام)' => $pct($o['returners']['rate']),
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">الاحتفاظ (يوم محدّد بالضبط، لأفواج منضجة)</h3>
            <table class="w-full text-sm" data-liveops-retention>
                <thead><tr class="text-gray-500"><th class="text-start">المؤشر</th><th>الفوج</th><th>عادوا</th><th>النسبة</th></tr></thead>
                <tbody>
                @foreach ($o['retention'] as $k => $r)
                    <tr class="border-t"><td>{{ strtoupper($k) }}</td><td class="text-center">{{ $r['cohort'] }}</td><td class="text-center">{{ $r['retained'] }}</td><td class="text-center">{{ $r['rate'] === null ? 'بيانات غير كافية' : $pct($r['rate']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">توزيع السلاسل الفعّالة</h3>
            <table class="w-full text-sm" data-liveops-streaks>
                <thead><tr class="text-gray-500"><th class="text-start">الشريحة (أيام)</th><th>لاعبون</th></tr></thead>
                <tbody>
                @foreach ($o['streak_distribution'] as $bucket => $n)
                    <tr class="border-t"><td>{{ $bucket === 'none_or_broken' ? 'بلا سلسلة فعّالة' : $bucket }}</td><td class="text-center">{{ $n }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
````

## `resources/views/filament/pages/liveops-planner.blade.php`

````blade
<x-filament-panels::page>
    @php $d = $this->data(); @endphp

    <p class="text-xs text-gray-500">التوقيت المعتمد للمنصة: {{ $d['timezone'] }}. هذه الصفحة للقراءة فقط؛ لا تغيّر أي شيء.</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-liveops-planner-stats>
        @foreach ([
            'مهام يومية فعّالة' => $d['quests']['daily']['active'],
            'مهام أسبوعية فعّالة' => $d['quests']['weekly']['active'],
            'أعلى مهام متزامنة نظريًا للاعب' => $d['potential']['missions'],
            'أعلى جواهر يومية نظريًا' => number_format($d['potential']['daily_reward']),
            'أعلى جواهر أسبوعية نظريًا' => number_format($d['potential']['weekly_reward']),
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div data-liveops-warnings>
        <h3 class="font-bold mb-2">تنبيهات الجدولة</h3>
        @forelse ($d['warnings'] as $w)
            <div class="rounded-lg border p-3 mb-2 text-sm {{ $w['level'] === 'critical' ? 'border-danger-500' : ($w['level'] === 'warning' ? 'border-warning-500' : 'border-gray-300') }}" data-warning="{{ $w['code'] }}">
                {{ $w['message'] }}
            </div>
        @empty
            <p class="text-sm text-gray-500">لا تنبيهات. الجدولة الحالية ضمن الحدود.</p>
        @endforelse
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">مجموعات المهام</h3>
            <table class="w-full text-sm" data-liveops-pools>
                <thead><tr class="text-gray-500"><th class="text-start">المجموعة</th><th>الفترة</th><th>الاختيار</th><th>مهام</th><th>الحالة</th></tr></thead>
                <tbody>
                @forelse ($d['pools'] as $p)
                    <tr class="border-t"><td>{{ $p->name }}</td><td class="text-center">{{ $p->period_type === 'weekly' ? 'أسبوعية' : 'يومية' }}</td><td class="text-center">{{ $p->selection_count }}</td><td class="text-center">{{ $p->quests_count }}</td><td class="text-center">{{ $p->is_active ? 'فعّالة' : 'معطّلة' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-3">لا مجموعات. المهام الفعّالة كلها عامة لكل اللاعبين.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">الأحداث (من التقويم نفسه)</h3>
            <table class="w-full text-sm" data-liveops-events>
                <thead><tr class="text-gray-500"><th class="text-start">الحدث</th><th>النوع</th><th>الحالة</th><th>البداية</th><th>النهاية</th></tr></thead>
                <tbody>
                @forelse ($d['events']->reject(fn ($e) => $e->status === 'ended') as $e)
                    <tr class="border-t"><td>{{ $e->title }}</td><td class="text-center">{{ $e->badge }}</td><td class="text-center">{{ $e->statusLabel() }}</td><td class="text-center">{{ $e->startsAt?->format('Y-m-d H:i') ?? '—' }}</td><td class="text-center">{{ $e->endsAt?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-3">لا أحداث جارية أو قادمة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
````

## `resources/views/home/_missions-widget.blade.php`

````blade
{{--
    E34: ودجت "اليوم" المدمج بلوحة اللاعب: 2-3 مهام أولوية + السلسلة + أقرب حدث + رابط "عرض الكل". قراءة فقط (لا صفوف تقدّم، لا مزامنة).
    @var array $widget  (MissionCenterService::widgetFor)
--}}
<section data-missions-widget aria-labelledby="today-widget-h" class="glass rounded-3xl p-5 sm:p-6 anim-fade-up d-1">
    <div class="flex items-center justify-between gap-3 mb-3">
        <h2 id="today-widget-h" class="font-display font-black text-lg text-white">اليوم</h2>
        <a href="{{ route('missions.index') }}" class="text-sm font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">عرض كل المهام ←</a>
    </div>

    @if ($widget['missions']->isEmpty())
        <p class="text-sm text-slate-400">لا مهام قيد التقدّم الآن. يمكنك متابعة اللعب أو تصفّح الأحداث.</p>
    @else
        <ul class="space-y-3">
            @foreach ($widget['missions'] as $row)
                <li>
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span class="font-bold text-white truncate">{{ $row['quest']->name }}</span>
                        <span class="text-xs font-bold text-slate-400 shrink-0">{{ min($row['progress']->current_value, $row['progress']->target_value_snapshot) }} / {{ $row['progress']->target_value_snapshot }}</span>
                    </div>
                    <div class="w-full h-1.5 rounded-full bg-white/10 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $row['progress']->target_value_snapshot }}" aria-valuenow="{{ min($row['progress']->current_value, $row['progress']->target_value_snapshot) }}" aria-label="تقدُّم {{ $row['quest']->name }}">
                        <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $row['percent'] }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-400">
        <span>السلسلة الحالية: <span class="font-bold text-gold">{{ $widget['streak'] }}</span> يوم</span>
        @if ($widget['next_event'])
            <span>أقرب حدث: <a href="{{ route('events.index') }}" class="font-bold text-white hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $widget['next_event']->title }}</a></span>
        @else
            <a href="{{ route('events.index') }}" class="font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">تقويم الأحداث</a>
        @endif
    </div>
</section>
````

## `tests/Feature/LiveOpsE34/E34AdminTest.php`

````php
<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Pages\LiveOpsAnalyticsPage;
use App\Filament\Pages\LiveOpsPlannerPage;
use App\Filament\Resources\MissionPoolResource;
use App\Filament\Resources\MissionPoolResource\Pages\CreateMissionPool;
use App\Filament\Resources\MissionPoolResource\Pages\ListMissionPools;
use App\Filament\Resources\QuestDefinitionResource;
use App\Models\Campaign;
use App\Models\Currency;
use App\Models\MissionPool;
use App\Models\OperationalAuditLog;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\LiveOpsPlannerService;
use App\Services\LiveOps\MissionPoolService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_reason(\Closure $fn): ?string
{
    try {
        $fn();
    } catch (LiveOpsException $e) {
        return $e->reason;
    }

    return null;
}

function e34_warning_codes(): array
{
    return collect(app(LiveOpsPlannerService::class)->overview()['warnings'])->pluck('code')->unique()->values()->all();
}

test('Z8-01: permissions are gated by role and policy - view for moderators, manage for managers/admins, never delete', function () {
    $mod = e34_moderator();
    $manager = e34_manager();
    $admin = e34_admin();
    $player = e34_user();
    $pool = e34_pool([], [], false);

    expect(Gate::forUser($player)->allows('viewAny', MissionPool::class))->toBeFalse()
        ->and(Gate::forUser($mod)->allows('viewAny', MissionPool::class))->toBeTrue()
        ->and(Gate::forUser($mod)->allows('create', MissionPool::class))->toBeFalse()
        ->and(Gate::forUser($mod)->allows('update', $pool))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', MissionPool::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $pool))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $pool))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('delete', $pool))->toBeFalse();

    expect(e34_reason(fn () => app(MissionPoolService::class)->create($mod, ['name' => 'x', 'slug' => 'x', 'period_type' => 'daily'])))->toBe('forbidden')
        ->and(e34_reason(fn () => app(MissionPoolService::class)->activate($player, $pool)))->toBe('forbidden');
});

test('Z8-02: LiveOps actions are rate limited per admin', function () {
    config(['liveops.limits.action_per_minute' => 3]);
    RateLimiter::clear('liveops-action:x');
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $pool = e34_pool([], [], false, $admin);   // create = 1
    $svc->update($admin, $pool, ['name' => 'a', 'slug' => $pool->slug, 'period_type' => 'daily']); // 2
    $svc->update($admin, $pool, ['name' => 'b', 'slug' => $pool->slug, 'period_type' => 'daily']); // 3

    expect(e34_reason(fn () => $svc->update($admin, $pool, ['name' => 'c', 'slug' => $pool->slug, 'period_type' => 'daily'])))->toBe('rate_limited')
        ->and($pool->fresh()->name)->toBe('b');
});

test('Z8-03: admin screens render for the right roles and are forbidden to players', function () {
    $admin = e34_admin();
    $mod = e34_moderator();
    $player = e34_user();
    $pool = e34_pool(e34_quests(2), []);
    e34_quest(['name' => 'مهمة للقائمة']);

    foreach (['/admin/mission-pools', '/admin/mission-pools/create', "/admin/mission-pools/{$pool->public_id}/edit", '/admin/liveops-planner', '/admin/liveops-analytics', '/admin/quest-definitions'] as $url) {
        $this->flushSession();
        $this->actingAs($admin)->get($url)->assertOk();
    }

    $this->flushSession();
    $this->actingAs($mod)->get('/admin/mission-pools')->assertOk();
    $this->flushSession();
    $this->actingAs($mod)->get('/admin/liveops-planner')->assertOk();
    $this->flushSession();
    $this->actingAs($mod)->get('/admin/mission-pools/create')->assertForbidden();

    foreach (['/admin/mission-pools', '/admin/liveops-planner', '/admin/liveops-analytics'] as $url) {
        $this->flushSession();
        $this->actingAs($player)->get($url)->assertForbidden();
    }
});

test('Z8-04: creating a pool through the Filament form goes through the service - inactive, audited, validated', function () {
    $admin = e34_admin();
    $this->actingAs($admin);

    Livewire::test(CreateMissionPool::class)
        ->fillForm(['name' => 'مجموعة الاختبار', 'slug' => 'bad slug!', 'period_type' => 'daily', 'selection_count' => 3, 'eligibility_rule' => 'all', 'priority' => 100])
        ->call('create')->assertHasFormErrors(['slug']);

    expect(MissionPool::query()->count())->toBe(0);

    Livewire::test(CreateMissionPool::class)
        ->fillForm(['name' => 'مجموعة الاختبار', 'slug' => 'test-pool', 'period_type' => 'daily', 'selection_count' => 3, 'eligibility_rule' => 'all', 'priority' => 100])
        ->call('create')->assertHasNoFormErrors();

    $pool = MissionPool::query()->where('slug', 'test-pool')->first();
    expect($pool)->not->toBeNull()->and($pool->is_active)->toBeFalse()->and($pool->created_by)->toBe($admin->id)
        ->and(OperationalAuditLog::query()->where('action', 'mission_pool_created')->where('actor_user_id', $admin->id)->count())->toBe(1);
});

test('Z8-05: the list screen activates and deactivates pools through the service and reports refusals instead of crashing', function () {
    $admin = e34_admin();
    $this->actingAs($admin);
    $filled = e34_pool(e34_quests(2), [], false, $admin);
    $empty = e34_pool([], [], false, $admin);

    Livewire::test(ListMissionPools::class)
        ->callTableAction('activate', $filled)
        ->assertHasNoTableActionErrors();
    expect($filled->fresh()->is_active)->toBeTrue();

    Livewire::test(ListMissionPools::class)->callTableAction('deactivate', $filled->fresh());
    expect($filled->fresh()->is_active)->toBeFalse();

    Livewire::test(ListMissionPools::class)->callTableAction('activate', $empty);
    expect($empty->fresh()->is_active)->toBeFalse(); // رُفض (مجموعة فارغة) بإشعار لا بانهيار

    expect(OperationalAuditLog::query()->whereIn('action', ['mission_pool_activated', 'mission_pool_deactivated'])->count())->toBe(2);
});

test('Z8-06: activation is refused only when the theoretical concurrent missions exceed the hard cap; softer overload is just a warning', function () {
    config(['liveops.budget.max_concurrent_missions_warn' => 3, 'liveops.budget.max_concurrent_missions_hard' => 5]);
    e34_quests(2);                                  // عامتان
    $admin = e34_admin();
    $ok = e34_pool(e34_quests(4), ['selection_count' => 2], true, $admin);          // 2 + 2 = 4: فوق التحذير (3) دون الصلب (5)

    expect($ok->is_active)->toBeTrue()->and(e34_warning_codes())->toContain('too_many_missions')->not->toContain('missions_over_hard_cap');

    $second = e34_pool(e34_quests(5), ['selection_count' => 4, 'priority' => 5], false, $admin);   // أفضل مجموعة تصبح 2 + 4 = 6 > 5
    expect(e34_reason(fn () => app(MissionPoolService::class)->activate($admin, $second)))->toBe('hard_cap')
        ->and($second->fresh()->is_active)->toBeFalse();
});

test('Z8-07: pool membership enforces the period type, rejects duplicates and locks the period once the pool has missions', function () {
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $pool = e34_pool([], ['period_type' => 'daily'], false, $admin);
    $daily = e34_quest();
    $weekly = e34_weekly();

    expect(e34_reason(fn () => $svc->attach($admin, $pool, $weekly)))->toBe('period_mismatch');

    $svc->attach($admin, $pool, $daily);
    expect(e34_reason(fn () => $svc->attach($admin, $pool, $daily)))->toBe('already_member')
        ->and(e34_reason(fn () => $svc->update($admin, $pool, ['name' => $pool->name, 'slug' => $pool->slug, 'period_type' => 'weekly'])))->toBe('period_locked');

    $svc->detach($admin, $pool, $daily);
    expect($pool->quests()->count())->toBe(0);
});

test('Z8-08: removing a mission from its only pool makes it a global mission again, and existing snapshots stay as assigned', function () {
    $u = e34_user();
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    [$a, $b, $c] = e34_quests(3);
    $pool = e34_pool([$a, $b, $c], ['selection_count' => 1], true, $admin);
    $mine = e34_visible_ids($u);
    $assignedRows = UserMissionAssignment::query()->where('user_id', $u->id)->count();
    $notMine = collect([$a->id, $b->id, $c->id])->diff($mine)->first();
    $detached = QuestDefinition::query()->find($notMine);

    $svc->detach($admin, $pool, $detached);

    // المهمة المُزالة صارت عامة (ظاهرة للجميع)، ولقطة اللاعب الحالية لم تتبدّل صفوفها
    expect(e34_visible_ids($u))->toEqualCanonicalizing([...$mine, $detached->id])
        ->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe($assignedRows);
});

test('Z8-09: a pool with assignment history can only be deactivated; an unused one can be deleted through the service', function () {
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $used = e34_pool(e34_quests(2), [], true, $admin);
    e34_visible_ids(e34_user());

    expect(e34_reason(fn () => $svc->delete($admin, $used)))->toBe('has_history')->and($used->fresh())->not->toBeNull();

    $unused = e34_pool([], [], false, $admin);
    $svc->delete($admin, $unused);

    expect(MissionPool::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(OperationalAuditLog::query()->where('action', 'mission_pool_deleted')->count())->toBe(1);
});

test('Z8-10: every pool change leaves an audit trail with the acting admin', function () {
    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $q = e34_quest();
    $pool = e34_pool([$q], [], false, $admin);
    $svc->update($admin, $pool, ['name' => 'جديد', 'slug' => $pool->slug, 'period_type' => 'daily']);
    $svc->activate($admin, $pool);
    $svc->deactivate($admin, $pool);
    $svc->detach($admin, $pool, $q);

    $actions = OperationalAuditLog::query()->where('actor_user_id', $admin->id)->pluck('action')->all();
    expect($actions)->toContain('mission_pool_created')->toContain('mission_pool_quest_attached')->toContain('mission_pool_updated')
        ->toContain('mission_pool_activated')->toContain('mission_pool_deactivated')->toContain('mission_pool_quest_detached');
});

test('Z8-11: the planner flags overload, heavy rewards, invalid or stale windows, thin pools and duplicate overlapping campaigns', function () {
    config(['liveops.budget.max_concurrent_missions_warn' => 2, 'liveops.budget.max_concurrent_missions_hard' => 3, 'liveops.budget.daily_gems_warn' => 10]);
    e34_pool(e34_quests(1), ['selection_count' => 3]);                                         // مجموعة رقيقة (تُفعَّل قبل ازدحام المهام العامة)
    $currency = Currency::factory()->create();
    e34_quests(4, ['reward_currency_id' => $currency->id, 'reward_currency_amount' => 5]);    // 4 مهام عامة: فوق الصلب (3) وثقيلة (20 > 10)
    e34_quest(['name' => 'نافذة معكوسة', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDay()]);
    e34_quest(['name' => 'منتهية فعّالة', 'ends_at' => now()->subDay()]);
    Campaign::withoutEvents(function () {
        Campaign::factory()->create(['slug' => 'dup-1', 'title' => 'حملة مكررة', 'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
        Campaign::factory()->create(['slug' => 'dup-2', 'title' => 'حملة مكررة', 'is_active' => true, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(9)]);
    });

    $codes = e34_warning_codes();

    expect($codes)->toContain('missions_over_hard_cap')->toContain('reward_heavy_daily')->toContain('invalid_window')
        ->toContain('expired_but_active')->toContain('pool_thin')->toContain('duplicate_campaign_overlap');
});

test('Z8-12: a calm schedule produces no warnings and the planner and analytics pages are read-only', function () {
    e34_quests(2);
    $admin = e34_admin();
    $writes = fn () => \Illuminate\Support\Facades\DB::table('user_quest_progress')->count() + MissionPool::query()->count() + UserMissionAssignment::query()->count() + OperationalAuditLog::query()->count();
    $before = $writes();

    expect(e34_warning_codes())->toBe([]);

    $this->actingAs($admin);
    $planner = Livewire::test(LiveOpsPlannerPage::class)->assertSuccessful();
    expect($planner->instance()->data()['potential']['missions'])->toBe(2);

    $analytics = Livewire::test(LiveOpsAnalyticsPage::class)->assertSuccessful()->set('days', 9999);
    expect($analytics->instance()->data()['days'])->toBe(30);
    $analytics->set('days', 7);
    expect($analytics->instance()->data()['days'])->toBe(7)->and($writes())->toBe($before);
});

test('Z8-13: quest administration - pooled missions cannot change period, the schedule label is truthful, and the list shows pool usage', function () {
    $pooled = e34_quest(['name' => 'مهمة مجمّعة']);
    $pool = e34_pool([$pooled, e34_quest()], [], false);

    $pooled->period_type = QuestDefinition::PERIOD_WEEKLY;
    expect(fn () => $pooled->save())->toThrow(StoreItemInvariantViolation::class);

    $mk = fn (array $o) => QuestDefinition::factory()->make($o);
    expect(QuestDefinitionResource::scheduleLabel($mk([])))->toBe('جارية')
        ->and(QuestDefinitionResource::scheduleLabel($mk(['is_active' => false])))->toBe('معطّلة')
        ->and(QuestDefinitionResource::scheduleLabel($mk(['starts_at' => now()->addDay()])))->toBe('مجدولة')
        ->and(QuestDefinitionResource::scheduleLabel($mk(['ends_at' => now()->subDay()])))->toBe('منتهية');

    $this->actingAs(e34_admin());
    Livewire::test(\App\Filament\Resources\QuestDefinitionResource\Pages\ListQuestDefinitions::class)->assertSuccessful()->assertSee('مهمة مجمّعة');
    expect($pool->quests()->count())->toBe(2);
});

test('Z8-14: the pool resource keeps the E26 admin conventions - no delete, service-only writes, no raw model mutation in the resource', function () {
    expect(MissionPoolResource::canDelete(new MissionPool))->toBeFalse();

    foreach (glob(base_path('app/Filament/Resources/MissionPoolResource').'/{*,*/*}.php', GLOB_BRACE) as $file) {
        $code = e34_code_only($file);
        expect(preg_match('/MissionPool::(create|forceCreate|query\(\)->(create|update|delete))|->forceFill|DB::table\(/', $code))->toBe(0, basename($file));
    }
});
````

## `tests/Feature/LiveOpsE34/E34AnalyticsTest.php`

````php
<?php

use App\Models\CompetitiveEventParticipant;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\LiveOps\RetentionAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_an(?int $days = 30): array
{
    RetentionAnalytics::flush();

    return app(RetentionAnalytics::class)->overview($days);
}

/** محاولة رسمية صحيحة بتاريخ محدد (بلا مرور بالمسار الكامل: نحن نقيس القراءة التحليلية لا اللعب). */
function e34_play(User $u, string $at): void
{
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::query()->forceCreate(['user_id' => $u->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false, 'created_at' => $at, 'updated_at' => $at]);
}

function e34_signup(int $daysAgo, int $n): array
{
    return collect(range(1, $n))->map(function () use ($daysAgo) {
        $u = e34_user();
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($daysAgo)->setTime(12, 0)]);

        return $u->fresh();
    })->all();
}

test('Z9-01: mission completion is completed over exposed rows per period type, and an empty window shows no fake percentage', function () {
    expect(e34_an()['mission_completion'])->toBe(['daily' => ['total' => 0, 'completed' => 0, 'rate' => null], 'weekly' => ['total' => 0, 'completed' => 0, 'rate' => null]]);

    $q = e34_quest();
    $w = e34_weekly();
    foreach ([[true], [false], [false]] as $i => [$done]) {
        UserQuestProgress::factory()->create(['user_id' => e34_user()->id, 'quest_definition_id' => $q->id, 'period_key' => 'daily:2026-10-20', 'period_start' => now()->startOfDay(), 'completed_at' => $done ? now() : null]);
    }
    UserQuestProgress::factory()->create(['user_id' => e34_user()->id, 'quest_definition_id' => $w->id, 'period_type' => 'weekly', 'period_key' => 'weekly:2026-43', 'period_start' => now()->startOfWeek(), 'completed_at' => now()]);

    $m = e34_an()['mission_completion'];
    expect($m['daily'])->toBe(['total' => 3, 'completed' => 1, 'rate' => 0.3333])->and($m['weekly'])->toBe(['total' => 1, 'completed' => 1, 'rate' => 1.0]);
});

test('Z9-02: D1 and D7 are exact-day retention on matured cohorts, and D30 is empty until a cohort has matured', function () {
    $cohort = e34_signup(10, 6);
    // D1: 3 لاعبين لعبوا يوم التسجيل+1 بالضبط؛ لاعب لعب +2 فقط (لا يُحسب D1)؛ D7: 2 لعبوا +7 بالضبط
    foreach ([0, 1, 2] as $i) {
        e34_play($cohort[$i], now()->subDays(9)->setTime(10, 0)->toDateTimeString());
    }
    e34_play($cohort[3], now()->subDays(8)->setTime(10, 0)->toDateTimeString());
    foreach ([0, 4] as $i) {
        e34_play($cohort[$i], now()->subDays(3)->setTime(10, 0)->toDateTimeString());
    }
    e34_play($cohort[5], now()->subDays(10)->setTime(20, 0)->toDateTimeString()); // يوم التسجيل نفسه: ليس احتفاظًا

    $r = e34_an()['retention'];

    expect($r['d1'])->toBe(['cohort' => 6, 'retained' => 3, 'rate' => 0.5])
        ->and($r['d7'])->toBe(['cohort' => 6, 'retained' => 2, 'rate' => 0.3333])
        ->and($r['d30'])->toMatchArray(['cohort' => 0, 'retained' => 0, 'rate' => null]);
});

test('Z9-03: a cohort below the minimum size never produces a misleading percentage, and unmatured signups are excluded', function () {
    $tiny = e34_signup(10, 2);
    foreach ($tiny as $u) {
        e34_play($u, now()->subDays(9)->setTime(10, 0)->toDateTimeString());
    }
    e34_signup(0, 8); // سجّلوا اليوم: D1 لم ينضج فلا يدخلون الفوج

    $r = e34_an()['retention'];

    expect($r['d1']['cohort'])->toBe(2)->and($r['d1']['retained'])->toBe(2)->and($r['d1']['rate'])->toBeNull();
});

test('Z9-04: the streak distribution counts only effective streaks, so a broken streak is not counted as active', function () {
    $mk = function (int $current, int $daysAgo) {
        $u = e34_user();
        e34_streak($u, $current, max($current, 9), now()->subDays($daysAgo)->toDateString());
    };
    $mk(2, 0); $mk(5, 1); $mk(8, 0); $mk(6, 5); $mk(40, 9);

    $d = e34_an()['streak_distribution'];

    expect($d['1-2'])->toBe(1)->and($d['3-6'])->toBe(1)->and($d['7-13'])->toBe(1)->and($d['none_or_broken'])->toBe(2)
        ->and($d['30-59'])->toBe(0);
});

test('Z9-05: returners are players whose last play before the window was at least the comeback threshold before their first in-window play', function () {
    config(['liveops.comeback.min_inactive_days' => 7]);
    $gap = e34_user();
    e34_play($gap, now()->subDays(50)->toDateTimeString());
    e34_play($gap, now()->subDays(5)->toDateTimeString());
    $steady = e34_user();
    e34_play($steady, now()->subDays(31)->toDateTimeString());
    e34_play($steady, now()->subDays(29)->toDateTimeString());
    $newcomer = e34_user();
    e34_play($newcomer, now()->subDays(3)->toDateTimeString());

    $r = e34_an()['returners'];

    expect($r)->toMatchArray(['min_inactive_days' => 7, 'returners' => 1, 'active_in_window' => 3, 'rate' => 0.3333]);
    expect(e34_an(7)['returners']['active_in_window'])->toBe(2);
});

test('Z9-06: event participation counts distinct people, not registrations, and ignores community or non-official play', function () {
    $a = e34_user();
    $b = e34_user();
    $e1 = e17Event(['title' => 'منافسة 1', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $e2 = e17Event(['title' => 'منافسة 2', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

    foreach ([[$e1, $a], [$e2, $a], [$e1, $b]] as [$e, $u]) {
        CompetitiveEventParticipant::query()->create(['competitive_event_id' => $e->id, 'user_id' => $u->id, 'status' => 'registered', 'registered_at' => now()->subDay()]);
    }

    expect(e34_an()['event_participation'])->toMatchArray(['competitive_registrations' => 3, 'competitive_users' => 2, 'campaign_users' => 0]);
});

test('Z9-07: analytics are aggregates only - no identities, clamped windows, cached and flushed explicitly', function () {
    $u = e34_user(['email' => 'secret.person@example.test', 'name' => 'اسم سري']);
    e34_play($u, now()->subDays(2)->toDateTimeString());
    e34_streak($u, 3, 3, now()->toDateString());

    $json = json_encode(e34_an());
    expect($json)->not->toContain('secret.person')->not->toContain('اسم سري')->not->toContain('"user_id"')->not->toContain('"email"');

    expect(app(RetentionAnalytics::class)->overview(0)['window_days'])->toBe(1)
        ->and(app(RetentionAnalytics::class)->overview(99999)['window_days'])->toBe(180)
        ->and(app(RetentionAnalytics::class)->overview(-4)['window_days'])->toBe(1);

    config(['liveops.analytics.cache_seconds' => 120]);
    RetentionAnalytics::flush();
    $first = app(RetentionAnalytics::class)->overview(30);
    e34_play(e34_user(), now()->subDay()->toDateTimeString());
    expect(app(RetentionAnalytics::class)->overview(30)['returners'])->toBe($first['returners']);   // من الكاش
    RetentionAnalytics::flush();
    expect(app(RetentionAnalytics::class)->overview(30)['returners']['active_in_window'])->toBe($first['returners']['active_in_window'] + 1);
});

test('Z9-08: sign-in alone is never meaningful play - only correct official attempts move retention and returners', function () {
    $u = e34_user();
    DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays(10)]);
    $this->actingAs($u)->get('/missions')->assertOk(); // "دخول" وتصفح

    $wrong = Puzzle::factory()->create();
    PuzzleAttempt::query()->forceCreate(['user_id' => $u->id, 'puzzle_id' => $wrong->id, 'attempt_number' => 1, 'is_correct' => false, 'used_hint' => false, 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)]);

    $a = e34_an();
    expect($a['retention']['d1']['retained'])->toBe(0)->and($a['returners']['active_in_window'])->toBe(0);
});
````

## `tests/Feature/LiveOpsE34/E34CalendarTest.php`

````php
<?php

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\Season;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\Calendar\EventItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC')); // أربعاء
});
afterEach(fn () => Carbon::setTestNow());

function e34_campaign(array $over = []): Campaign
{
    return Campaign::withoutEvents(fn () => Campaign::factory()->create($over + ['slug' => 'c-'.fake()->unique()->numerify('######'), 'title' => 'حملة '.fake()->unique()->numerify('####'), 'is_active' => true]));
}

function e34_cal(): EventCalendarService
{
    return app(EventCalendarService::class);
}

test('Z6-01: /events is public, returns 200 for guests, has one h1 and states the platform time zone', function () {
    e34_campaign(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'title' => 'حملة التقويم']);

    $html = $this->get('/events')->assertOk()->assertSee('حملة التقويم')->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)->and($html)->toContain(config('app.timezone'));
    foreach (['اشترِ', 'ستفقد', 'باقي دقائق', 'آخر فرصة'] as $bad) {
        expect($html)->not->toContain($bad);
    }
});

test('Z6-02: status is derived from real dates - upcoming, live, ending soon, ended, and undated items are simply live', function () {
    $c = e34_cal();
    $now = now();

    expect($c->statusOf($now->copy()->addHour(), $now->copy()->addDays(3), $now))->toBe(EventCalendarService::STATUS_UPCOMING)
        ->and($c->statusOf($now->copy()->subHour(), $now->copy()->addDays(3), $now))->toBe(EventCalendarService::STATUS_LIVE)
        ->and($c->statusOf($now->copy()->subHour(), $now->copy()->addHours(23), $now))->toBe(EventCalendarService::STATUS_ENDING_SOON)
        ->and($c->statusOf($now->copy()->subDays(2), $now->copy()->subSecond(), $now))->toBe(EventCalendarService::STATUS_ENDED)
        ->and($c->statusOf(null, null, $now))->toBe(EventCalendarService::STATUS_LIVE)
        ->and($c->statusOf(null, $now->copy()->addHours(2), $now))->toBe(EventCalendarService::STATUS_ENDING_SOON);
});

test('Z6-03: standalone campaigns show once, season campaigns show through their published season only, inactive and ended ones never show', function () {
    $standalone = e34_campaign(['title' => 'مستقلة', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    $seasonCampaign = e34_campaign(['title' => 'حملة الموسم', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $seasonCampaign->id, 'slug' => 'pub-s', 'is_published' => true]));
    $draftCampaign = e34_campaign(['title' => 'موسم مسودة', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $draftCampaign->id, 'slug' => 'draft-s', 'is_published' => false]));
    e34_campaign(['title' => 'معطّلة', 'is_active' => false, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    e34_campaign(['title' => 'منتهية', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDay()]);

    $items = e34_cal()->items();
    $titles = $items->pluck('title')->all();

    expect($titles)->toContain('مستقلة')->toContain('حملة الموسم')->toContain('موسم مسودة')->not->toContain('معطّلة')->not->toContain('منتهية')
        ->and(count(array_keys($titles, 'حملة الموسم')))->toBe(1)
        ->and($items->firstWhere('title', 'حملة الموسم')->type)->toBe(EventCalendarService::TYPE_SEASON)
        ->and($items->firstWhere('title', 'موسم مسودة')->type)->toBe(EventCalendarService::TYPE_CAMPAIGN)
        ->and($items->firstWhere('title', 'مستقلة')->type)->toBe(EventCalendarService::TYPE_CAMPAIGN);
});

test('Z6-04: competitive events appear only when published and not finished, within the upcoming horizon', function () {
    e17Event(['title' => 'منافسة منشورة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    e17Event(['title' => 'مسودة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], CompetitiveEvent::STATUS_DRAFT);
    e17Event(['title' => 'ملغاة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], CompetitiveEvent::STATUS_CANCELLED);
    e17Event(['title' => 'مكتملة', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()], CompetitiveEvent::STATUS_COMPLETED);
    e17Event(['title' => 'بعيدة جدًا', 'starts_at' => now()->addDays(200), 'ends_at' => now()->addDays(201)]);

    $titles = e34_cal()->items()->pluck('title')->all();

    expect($titles)->toBe(['منافسة منشورة']);
});

test('Z6-05: sections (now, today, this week, upcoming) use the platform time zone and Monday-to-Sunday weeks, one section per item', function () {
    config(['app.timezone' => 'UTC']);
    $cal = e34_cal();

    e34_campaign(['title' => 'جارية', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20)]);
    e34_campaign(['title' => 'الليلة', 'starts_at' => Carbon::parse('2026-10-14 21:30:00', 'UTC'), 'ends_at' => now()->addDays(20)]);
    e34_campaign(['title' => 'الجمعة', 'starts_at' => Carbon::parse('2026-10-16 10:00:00', 'UTC'), 'ends_at' => now()->addDays(20)]);
    e34_campaign(['title' => 'الأسبوع القادم', 'starts_at' => Carbon::parse('2026-10-19 00:00:00', 'UTC'), 'ends_at' => now()->addDays(30)]);

    $s = $cal->sections();
    $names = fn ($k) => $s[$k]->pluck('title')->all();

    expect($names('now'))->toBe(['جارية'])->and($names('today'))->toBe(['الليلة'])->and($names('week'))->toBe(['الجمعة'])->and($names('upcoming'))->toBe(['الأسبوع القادم']);

    // منطقة المنصة تغيّر "اليوم": 21:30 UTC = 00:30 غدًا بتوقيت الرياض ⇒ يسقط من "اليوم" إلى "هذا الأسبوع"
    config(['app.timezone' => 'Asia/Riyadh']);
    $s2 = $cal->sections();
    expect($s2['today']->pluck('title')->all())->toBe([])->and($s2['week']->pluck('title')->sort()->values()->all())->toBe(['الجمعة', 'الليلة']);
});

test('Z6-06: the shared snapshot is cached without user data, and status is recomputed at read time as the clock moves', function () {
    config(['liveops.calendar.cache_seconds' => 60]);
    e34_campaign(['title' => 'قريبة', 'starts_at' => now()->addHours(2), 'ends_at' => now()->addDays(5)]);

    $cal = e34_cal();
    expect($cal->items()->first()->status)->toBe(EventCalendarService::STATUS_UPCOMING);

    e34_campaign(['title' => 'جديدة بعد الكاش', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    expect($cal->items()->pluck('title')->all())->toBe(['قريبة']); // اللقطة مخزَّنة

    Carbon::setTestNow(now()->addHours(3)); // الحدث بدأ: الحالة تتغير بلا تفريغ كاش
    expect($cal->items()->first()->status)->toBe(EventCalendarService::STATUS_LIVE);

    EventCalendarService::flush();
    expect($cal->items()->pluck('title')->all())->toContain('جديدة بعد الكاش');

    $raw = json_encode(Cache::get(EventCalendarService::CACHE_KEY));
    expect($raw)->not->toContain('@')->not->toContain('email');
});

test('Z6-07: my agenda holds only what concerns the player, is capped, and is empty for others', function () {
    $mine = e34_user();
    $other = e34_user();
    $events = [];

    foreach (range(1, 7) as $i) {
        $events[$i] = e17Event(['title' => "منافسة {$i}", 'starts_at' => now()->addHours($i + 1), 'ends_at' => now()->addDays(2)]);
    }

    foreach ($events as $e) {
        CompetitiveEventParticipant::query()->create(['competitive_event_id' => $e->id, 'user_id' => $mine->id, 'status' => 'registered', 'registered_at' => now()]);
    }

    config(['liveops.calendar.agenda_max' => 5]);

    expect(e34_cal()->agendaFor($mine))->toHaveCount(5)->and(e34_cal()->agendaFor($other))->toHaveCount(0);
});

test('Z6-08: items expose a fixed whitelist of fields - no amounts, goals, emails or internals', function () {
    e34_campaign(['title' => 'حملة', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(2)]);
    $item = e34_cal()->items()->first();

    expect($item)->toBeInstanceOf(EventItem::class)
        ->and(array_keys($item->toArray()))->toBe(['type', 'title', 'starts_at', 'ends_at', 'status', 'url', 'badge', 'image']);

    foreach (e34_cal()->snapshot() as $row) {
        expect(array_diff(array_keys($row), ['type', 'key', 'ref', 'campaign_id', 'title', 'starts_at', 'ends_at', 'route', 'params', 'badge', 'image']))->toBe([]);
    }
});

test('Z6-09: countdowns exist only for real dates - never for ended or undated items', function () {
    e34_campaign(['title' => 'بلا تواريخ', 'starts_at' => null, 'ends_at' => null]);
    e34_campaign(['title' => 'تنتهي', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(4)]);
    e34_campaign(['title' => 'تبدأ', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)]);

    $by = e34_cal()->items()->keyBy('title');

    expect($by['بلا تواريخ']->countdownTo())->toBeNull()
        ->and($by['تنتهي']->countdownTo()->equalTo($by['تنتهي']->endsAt))->toBeTrue()
        ->and($by['تبدأ']->countdownTo()->equalTo($by['تبدأ']->startsAt))->toBeTrue();
});
````

## `tests/Feature/LiveOpsE34/E34ComebackTest.php`

````php
<?php

use App\Models\GemTransaction;
use App\Models\CurrencyTransaction;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\XpTransaction;
use App\Services\LiveOps\ComebackService;
use App\Services\LiveOps\MissionCenterService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

test('Z5-01: eligibility starts exactly at the configured number of inactive official-play days and follows the config', function () {
    $svc = app(ComebackService::class);
    $mk = function (int $daysAgo) {
        $u = e34_user();
        e34_streak($u, 2, 4, now()->subDays($daysAgo)->toDateString());

        return $u;
    };

    expect($svc->isEligible($mk(0)))->toBeFalse()
        ->and($svc->isEligible($mk(1)))->toBeFalse()
        ->and($svc->isEligible($mk(6)))->toBeFalse()
        ->and($svc->isEligible($mk(7)))->toBeTrue()
        ->and($svc->isEligible($mk(30)))->toBeTrue();

    config(['liveops.comeback.min_inactive_days' => 3]);
    expect($svc->isEligible($mk(3)))->toBeTrue()->and($svc->isEligible($mk(2)))->toBeFalse();
});

test('Z5-02: a player with no official play history is a new player, not a returner; old accounts without a streak row fall back to their last correct attempt', function () {
    $svc = app(ComebackService::class);

    expect($svc->isEligible(e34_user([], 90)))->toBeFalse()->and($svc->stateFor(e34_user()))->toBeNull();

    $legacy = e34_user([], 90);
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::query()->forceCreate(['user_id' => $legacy->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12)]);

    expect($svc->isEligible($legacy))->toBeTrue()->and($svc->inactiveDays($legacy))->toBe(12);

    $wrongOnly = e34_user([], 90);
    PuzzleAttempt::query()->forceCreate(['user_id' => $wrongOnly->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => false, 'used_hint' => false, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12)]);
    expect($svc->isEligible($wrongOnly))->toBeFalse(); // محاولة خاطئة ليست لعبًا موثوقًا
});

test('Z5-03: the comeback state clears by itself on the first official correct solve', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());
    $svc = app(ComebackService::class);
    expect($svc->stateFor($u))->not->toBeNull();

    e34_solve($u);

    expect(app(ComebackService::class)->stateFor($u))->toBeNull()
        ->and(PlayerStreak::query()->where('user_id', $u->id)->value('last_active_date')->toDateString())->toBe(now()->toDateString());
});

test('Z5-04: visiting pages or logging in never clears it - only real official play does', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());

    $this->actingAs($u)->get('/missions')->assertOk();
    $this->actingAs($u)->get('/')->assertOk();
    $this->actingAs($u)->get('/events')->assertOk();
    $this->post('/logout');

    expect(app(ComebackService::class)->isEligible($u))->toBeTrue();
});

test('Z5-05: there is no comeback gem reward - no config key, no wallet or XP movement, and no reward promise on the page', function () {
    expect(array_keys((array) config('liveops.comeback')))->toBe(['min_inactive_days', 'notify_max_days']);

    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());
    $before = [XpTransaction::query()->count(), GemTransaction::query()->count(), CurrencyTransaction::query()->count()];

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    expect([XpTransaction::query()->count(), GemTransaction::query()->count(), CurrencyTransaction::query()->count()])->toBe($before);
    foreach (['مكافأة العودة', 'جواهر مجانية', 'هدية العودة', 'ستفقد', 'فاتك', 'اشترِ'] as $phrase) {
        expect($html)->not->toContain($phrase);
    }
});

test('Z5-06: suggestions are easy, active, official, unsolved puzzles chosen deterministically per player and day, at most three items', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());

    $ok = collect(range(1, 4))->map(fn ($i) => Puzzle::factory()->create(['title' => "سهلة {$i}", 'difficulty' => 'easy']));
    Puzzle::factory()->create(['title' => 'صعبة', 'difficulty' => 'hard']);
    Puzzle::factory()->create(['title' => 'معطّلة', 'difficulty' => 'easy', 'is_active' => false]);
    $solved = Puzzle::factory()->create(['title' => 'محلولة', 'difficulty' => 'easy']);
    PuzzleAttempt::query()->forceCreate(['user_id' => $u->id, 'puzzle_id' => $solved->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12)]);

    $svc = app(ComebackService::class);
    $first = $svc->suggestions($u);
    $second = app(ComebackService::class)->suggestions($u);

    expect($first)->toBe($second)->and(count($first))->toBeLessThanOrEqual(3);

    $puzzleLine = collect($first)->firstWhere('kind', 'puzzle');
    expect($puzzleLine)->not->toBeNull();
    expect(collect($ok)->contains(fn ($p) => str_contains($puzzleLine['title'], $p->title)))->toBeTrue()
        ->and($puzzleLine['title'])->not->toContain('صعبة')->not->toContain('معطّلة')->not->toContain('محلولة');
});

test('Z5-07: the mission center welcomes returners with a calm card and shows nothing for active players', function () {
    $returner = e34_user();
    e34_streak($returner, 3, 5, now()->subDays(10)->toDateString());
    $active = e34_user();
    e34_streak($active, 3, 5, now()->toDateString());
    e34_quest();

    $html = $this->actingAs($returner)->get('/missions')->assertOk()->getContent();
    expect($html)->toContain('مرحبًا بعودتك')->toContain(now()->subDays(10)->toDateString());

    $this->actingAs($active)->get('/missions')->assertOk()->assertDontSee('مرحبًا بعودتك');
});

test('Z5-08: the comeback suggestions include one light in-progress mission when the player has one', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());
    e34_quest(['name' => 'مهمة خفيفة جدًا', 'target_value' => 2]);

    $data = app(MissionCenterService::class)->forUser($u, true);

    expect($data['comeback'])->not->toBeNull()
        ->and(collect($data['comeback']['suggestions'])->pluck('kind')->all())->toContain('mission')
        ->and(collect($data['comeback']['suggestions'])->firstWhere('kind', 'mission')['title'])->toContain('مهمة خفيفة جدًا');
});
````

## `tests/Feature/LiveOpsE34/E34ConcurrencyTest.php`

````php
<?php

use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserMissionAssignment;
use App\Services\Engagement\QuestPeriodService;
use App\Services\LiveOps\MissionAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_row(User $u, int $poolId, int $questId, string $key, int $slot = 0): array
{
    return ['user_id' => $u->id, 'mission_pool_id' => $poolId, 'quest_definition_id' => $questId, 'period_key' => $key, 'slot' => $slot, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()];
}

test('Z10-01: the database itself forbids the same mission twice for a player in a period', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(2), []);
    $quest = $pool->quests()->first();

    DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $quest->id, 'daily:2026-10-14'));

    expect(fn () => DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $quest->id, 'daily:2026-10-14')))->toThrow(UniqueConstraintViolationException::class);

    // نفس المهمة بفترة أخرى أو مستخدم آخر مسموح
    DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $quest->id, 'daily:2026-10-15'));
    DB::table('user_mission_assignments')->insert(e34_row(e34_user(), $pool->id, $quest->id, 'daily:2026-10-14'));
    expect(UserMissionAssignment::query()->count())->toBe(3);
});

test('Z10-02: repeated assignment is idempotent and never adds rows once a snapshot exists', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(5), ['selection_count' => 3]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $snapshot = $svc->snapshot()['pools'][$pool->id];

    $a = $svc->assignWithinLock($u, $snapshot, $ctx);
    $b = app(MissionAssignmentService::class)->assignWithinLock($u, $snapshot, $ctx);
    $c = app(MissionAssignmentService::class)->assignedQuestIds($u, $ctx);

    expect($a)->toHaveCount(3)->and($b)->toBe($a)->and($c)->toBe($a)->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(3);
});

test('Z10-03: a competing writer that stores the same deterministic selection between the check and the insert causes no duplicates', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(6), ['selection_count' => 3]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $snapshot = $svc->snapshot()['pools'][$pool->id];

    $winnerRows = collect($svc->select($u, $snapshot, $ctx))->values()->map(fn ($q, $i) => e34_row($u, $pool->id, $q->id, $ctx->periodKey, $i))->all();

    $fired = false;
    DB::listen(function ($q) use (&$fired, $winnerRows) {
        // أول قراءة لجدول الإسناد داخل القفل هي إعادة الفحص؛ بعدها "يفوز" كاتب آخر بالإدراج قبل إدراجنا.
        if (! $fired && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'user_mission_assignments')) {
            $fired = true;
            DB::table('user_mission_assignments')->insert($winnerRows);
        }
    });

    $ids = app(MissionAssignmentService::class)->assignWithinLock($u, $snapshot, $ctx);

    expect($fired)->toBeTrue()->and($ids)->toHaveCount(3)->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(3);
});

test('Z10-04: when another writer already assigned the period, the in-lock recheck returns their rows and adds nothing', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(6), ['selection_count' => 2]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $other = $pool->quests()->orderByDesc('quest_definitions.id')->limit(2)->get();

    foreach ($other as $i => $q) {
        DB::table('user_mission_assignments')->insert(e34_row($u, $pool->id, $q->id, $ctx->periodKey, $i));
    }

    $ids = $svc->assignWithinLock($u, $svc->snapshot()['pools'][$pool->id], $ctx);

    expect($ids)->toEqualCanonicalizing($other->pluck('id')->all())->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(2);
});

test('Z10-05: the assignment locks the player row inside a transaction before it reads or writes assignments', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(3), ['selection_count' => 2]);
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $log = [];
    $base = DB::transactionLevel(); // الاختبارات تعمل داخل معاملة RefreshDatabase؛ المقارنة نسبية لها

    DB::listen(function ($q) use (&$log) {
        $sql = strtolower($q->sql);

        if (str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`')) {
            $log[] = ['users', DB::transactionLevel()];
        } elseif (str_contains($sql, 'user_mission_assignments')) {
            $log[] = [str_starts_with($sql, 'select') ? 'read' : 'write', DB::transactionLevel()];
        }
    });

    $svc->assignWithinLock($u, $svc->snapshot()['pools'][$pool->id], $ctx);

    expect($log[0])->toBe(['users', $base + 1])->and(collect($log)->every(fn ($e) => $e[1] >= $base + 1))->toBeTrue()
        ->and(collect($log)->pluck(0)->all())->toContain('write');
});

test('Z10-06: delete rules - restrict on pools with history, cascade on users, quests and pool membership', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(3), ['selection_count' => 2]);
    e34_visible_ids($u);
    expect(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(2);

    expect(fn () => DB::table('mission_pools')->where('id', $pool->id)->delete())->toThrow(QueryException::class);

    $assignedQuest = UserMissionAssignment::query()->where('user_id', $u->id)->value('quest_definition_id');
    QuestDefinition::query()->whereKey($assignedQuest)->first()->delete();   // لا تقدّم بعد ⇒ يُسمح بحذفها
    expect(UserMissionAssignment::query()->where('quest_definition_id', $assignedQuest)->count())->toBe(0)
        ->and(DB::table('mission_pool_quests')->where('quest_definition_id', $assignedQuest)->count())->toBe(0);

    DB::table('users')->where('id', $u->id)->delete();
    expect(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(0)->and(MissionPool::query()->whereKey($pool->id)->exists())->toBeTrue();
});

test('Z10-07: the E34 tables carry exactly the planned unique constraints and covering indexes', function () {
    $idx = fn (string $t) => collect(Schema::getIndexes($t))->keyBy('name');
    $a = $idx('user_mission_assignments');
    $p = $idx('mission_pool_quests');
    $m = $idx('mission_pools');

    expect($a['user_mission_assignments_user_quest_period_unique']['unique'])->toBeTrue()
        ->and($a['user_mission_assignments_user_quest_period_unique']['columns'])->toBe(['user_id', 'quest_definition_id', 'period_key'])
        ->and($a['user_mission_assignments_user_period_idx']['columns'])->toBe(['user_id', 'period_key'])
        ->and($a['user_mission_assignments_pool_period_idx']['columns'])->toBe(['mission_pool_id', 'period_key'])
        ->and($p['mission_pool_quests_pool_quest_unique']['unique'])->toBeTrue()
        ->and($m['mission_pools_active_period_priority_idx']['columns'])->toBe(['is_active', 'period_type', 'priority']);

    foreach ([$a, $p, $m] as $set) {
        foreach ($set->keys() as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64);
        }
    }
});

test('Z10-08: [MySQL] referential actions, engine, charset and key lengths are the intended production ones', function () {
    $db = DB::connection();
    $schema = $db->getDatabaseName();

    $rules = collect($db->select('select TABLE_NAME t, REFERENCED_TABLE_NAME r, DELETE_RULE d from information_schema.REFERENTIAL_CONSTRAINTS where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?)', [$schema, 'user_mission_assignments', 'mission_pool_quests', 'mission_pools']))
        ->mapWithKeys(fn ($r) => [$r->t.'>'.$r->r => $r->d]);

    expect($rules['user_mission_assignments>users'])->toBe('CASCADE')
        ->and($rules['user_mission_assignments>mission_pools'])->toBe('RESTRICT')
        ->and($rules['user_mission_assignments>quest_definitions'])->toBe('CASCADE')
        ->and($rules['mission_pool_quests>mission_pools'])->toBe('CASCADE')
        ->and($rules['mission_pool_quests>quest_definitions'])->toBe('CASCADE')
        ->and($rules['mission_pools>users'])->toBe('SET NULL');

    $tables = collect($db->select('select TABLE_NAME t, ENGINE e, TABLE_COLLATION c from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_NAME in (?, ?, ?)', [$schema, 'user_mission_assignments', 'mission_pool_quests', 'mission_pools']));
    foreach ($tables as $t) {
        expect($t->e)->toBe('InnoDB')->and($t->c)->toStartWith('utf8mb4');
    }

    $cols = collect($db->select('select COLUMN_NAME n, COLUMN_TYPE t from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ?', [$schema, 'user_mission_assignments']))->pluck('t', 'n');
    expect($cols['period_key'])->toBe('varchar(32)')->and($cols['slot'])->toStartWith('tinyint');
})->skip(fn () => DB::getDriverName() !== 'mysql', 'MySQL only');

test('Z10-09: [MySQL] insert-ignore on the unique key keeps exactly one row and the row lock statement is really issued', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(2), []);
    $quest = $pool->quests()->first();
    $row = e34_row($u, $pool->id, $quest->id, 'daily:2026-10-14');

    UserMissionAssignment::query()->insertOrIgnore([$row]);
    UserMissionAssignment::query()->insertOrIgnore([$row]);
    expect(UserMissionAssignment::query()->count())->toBe(1);

    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = strtolower($q->sql);
    });
    $svc = app(MissionAssignmentService::class);
    $svc->assignWithinLock(e34_user(), $svc->snapshot()['pools'][$pool->id], app(QuestPeriodService::class)->dailyContext());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update') && str_contains($s, '`users`')))->toBeTrue()
        ->and(collect($sql)->contains(fn ($s) => str_contains($s, 'insert ignore into `user_mission_assignments`')))->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'mysql', 'MySQL only');
````

## `tests/Feature/LiveOpsE34/E34DemoSeederTest.php`

````php
<?php

use App\Models\Campaign;
use App\Models\CurrencyTransaction;
use App\Models\GemTransaction;
use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use Database\Seeders\DemoE34Seeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());

/** @return array<string,int> */
function e34_demo_counts(): array
{
    return [
        'users' => User::query()->count(),
        'quests' => QuestDefinition::query()->count(),
        'pools' => MissionPool::query()->count(),
        'members' => DB::table('mission_pool_quests')->count(),
        'campaigns' => Campaign::query()->count(),
        'streaks' => PlayerStreak::query()->count(),
        'assignments' => DB::table('user_mission_assignments')->count(),
        'audit' => DB::table('operational_audit_logs')->count(),
    ];
}

test('Z12-01: the demo seeder is idempotent - a second and third run change no table and create no duplicates', function () {
    $this->seed(DemoE34Seeder::class);
    $first = e34_demo_counts();
    $this->seed(DemoE34Seeder::class);
    $this->seed(DemoE34Seeder::class);

    expect(e34_demo_counts())->toBe($first)
        ->and(MissionPool::query()->where('slug', 'demo-e34-daily-pool')->count())->toBe(1)
        ->and(QuestDefinition::query()->where('internal_key', 'like', 'demo_e34_%')->count())->toBe(6)
        ->and(Campaign::query()->whereIn('slug', ['demo-e34-live', 'demo-e34-upcoming'])->count())->toBe(2)
        ->and(User::query()->whereIn('email', [DemoE34Seeder::ADMIN_EMAIL, DemoE34Seeder::STREAK_EMAIL, DemoE34Seeder::COMEBACK_EMAIL])->count())->toBe(3);
});

test('Z12-02: the seeded pool is active with four members and was activated through the official service (audit entries exist), with no currency or XP written', function () {
    $this->seed(DemoE34Seeder::class);
    $pool = MissionPool::query()->where('slug', 'demo-e34-daily-pool')->firstOrFail();

    expect($pool->is_active)->toBeTrue()
        ->and((int) $pool->selection_count)->toBe(2)
        ->and(DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->count())->toBe(4)
        ->and(DB::table('operational_audit_logs')->where('action', 'mission_pool_activated')->count())->toBe(1)
        ->and(GemTransaction::query()->count())->toBe(0)
        ->and(CurrencyTransaction::query()->count())->toBe(0);
});

test('Z12-03: demo accounts are .test addresses and the seeder refuses to run in production', function () {
    $this->seed(DemoE34Seeder::class);

    foreach ([DemoE34Seeder::ADMIN_EMAIL, DemoE34Seeder::STREAK_EMAIL, DemoE34Seeder::COMEBACK_EMAIL] as $email) {
        expect($email)->toEndWith('@liveops.test');
    }

    app()['env'] = 'production';

    try {
        expect(fn () => (new DemoE34Seeder)->run())->toThrow(RuntimeException::class);
    } finally {
        app()['env'] = 'testing';
    }
});

test('Z12-04: the demo scenarios actually render - admin planner, streak player sees an active streak, comeback player sees the comeback card', function () {
    $this->seed(DemoE34Seeder::class);

    $admin = User::query()->where('email', DemoE34Seeder::ADMIN_EMAIL)->firstOrFail();
    $this->actingAs($admin)->get('/admin/liveops-planner')->assertOk();
    $this->actingAs($admin)->get('/admin/mission-pools')->assertOk();

    $streak = User::query()->where('email', DemoE34Seeder::STREAK_EMAIL)->firstOrFail();
    $this->actingAs($streak)->get('/missions')->assertOk()->assertSee('7');

    $comeback = User::query()->where('email', DemoE34Seeder::COMEBACK_EMAIL)->firstOrFail();
    $this->actingAs($comeback)->get('/missions')->assertOk()->assertSee('مرحبًا بعودتك');

    $this->get('/events')->assertOk()->assertSee('حملة تجريبية جارية')->assertSee('حملة تجريبية قادمة');
});
````

## `tests/Feature/LiveOpsE34/E34MissionCenterTest.php`

````php
<?php

use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\MissionCenterService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());
afterEach(fn () => Carbon::setTestNow());

const E34_PRESSURE = ['اشترِ', 'Claim Reward', 'طالب بالمكافأة', 'ستفقد', 'باقي دقائق', 'ادفع للحفاظ', 'آخر فرصة', 'سارع'];

test('Z1-01: /missions requires login, /quests stays reachable (200, no redirect) and both serve the mission center', function () {
    $this->get('/missions')->assertRedirect(route('login'));
    $this->get('/quests')->assertRedirect(route('login'));

    $u = e34_user();
    e34_quest(['name' => 'مهمة المركز']);

    $this->actingAs($u)->get('/missions')->assertOk()->assertSee('مهمة المركز');
    $this->actingAs($u)->get('/quests')->assertOk()->assertSee('مهمة المركز');

    expect(route('missions.index', [], false))->toBe('/missions')->and(route('quests.show', [], false))->toBe('/quests');
});

test('Z1-02: the page has sections for daily and weekly missions and exactly one h1', function () {
    $u = e34_user();
    e34_quest(['name' => 'يومية أ']);
    e34_weekly(['name' => 'أسبوعية أ']);

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    expect($html)->toContain('يومية أ')->toContain('أسبوعية أ')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

test('Z1-03: official solves advance a global mission and the card shows percent and in-progress state with a real remaining time', function () {
    $u = e34_user();
    e34_quest(['name' => 'ثلاث أحجيات', 'target_value' => 4]);

    e34_solve($u);
    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    expect($html)->toContain('ثلاث أحجيات')->toContain('قيد التقدّم')->toContain('25%')->toContain('الوقت المتبقي');
});

test('Z1-04: a granted reward is labelled as granted and a completed-but-unpaid mission keeps the existing processing label', function () {
    $u = e34_user();
    $done = e34_quest(['name' => 'منجزة ومُمنوحة', 'xp_reward' => 5]);
    $pending = e34_quest(['name' => 'منجزة قيد المعالجة', 'xp_reward' => 5]);
    $quests = app(QuestService::class);
    $period = app(QuestPeriodService::class)->dailyContext();

    $a = $quests->progressFor($u, $done, $period);
    $b = $quests->progressFor($u, $pending, $period);
    UserQuestProgress::query()->whereKey($a->id)->update(['current_value' => 1, 'completed_at' => now(), 'reward_granted_at' => now()]);
    UserQuestProgress::query()->whereKey($b->id)->update(['current_value' => 1, 'completed_at' => now(), 'reward_granted_at' => null]);

    // قراءة بلا مزامنة: الحالة الوسيطة تُعرض بنص E13 الحالي (فتح الصفحة الفعلي يشفي المكافأة المعلّقة ذاتيًا كما كان).
    $rows = app(MissionCenterService::class)->rowsFor($u, 'daily', false)->keyBy(fn ($r) => $r['quest']->id);
    expect($rows[$done->id]['status'])->toBe(MissionCenterService::STATUS_REWARD_GRANTED)
        ->and($rows[$pending->id]['status'])->toBe(MissionCenterService::STATUS_COMPLETED);

    $card = fn ($row) => view('quests._quest-card', ['quest' => $row['quest'], 'progress' => $row['progress'], 'percent' => $row['percent'], 'remaining' => $row['remaining']])->render();
    expect($card($rows[$done->id]))->toContain('تم منح المكافأة')
        ->and($card($rows[$pending->id]))->toContain('جارٍ معالجة المكافأة');

    // الصفحة الفعلية: تُظهر "تم منح المكافأة" وتشفي المعلّقة (لا تبقى معلّقة بعد الفتح).
    $this->actingAs($u)->get('/missions')->assertOk()->assertSee('تم منح المكافأة');
    expect(UserQuestProgress::query()->whereKey($b->id)->value('reward_granted_at'))->not->toBeNull();
});

test('Z1-05: opening the page lazily creates progress and assignments for this user only, and only for visible missions', function () {
    $a = e34_user();
    $b = e34_user();
    e34_quest(['name' => 'عامة']);
    e34_pool(e34_quests(4), ['selection_count' => 2]);

    $this->actingAs($a)->get('/missions')->assertOk();

    expect(UserQuestProgress::query()->where('user_id', $a->id)->count())->toBe(3)   // عامة + مهمتان مُسندتان
        ->and(UserMissionAssignment::query()->where('user_id', $a->id)->count())->toBe(2)
        ->and(UserQuestProgress::query()->where('user_id', $b->id)->count())->toBe(0)
        ->and(UserMissionAssignment::query()->where('user_id', $b->id)->count())->toBe(0);
});

test('Z1-06: no pressure wording, no emoji in the heading and no inline hex colours on the page', function () {
    $u = e34_user();
    e34_quest();
    e34_streak($u, 3, 8, now()->subDays(12)->toDateString()); // يعرض أيضًا بطاقة العودة

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    foreach (E34_PRESSURE as $phrase) {
        expect($html)->not->toContain($phrase);
    }

    preg_match('/<h1[^>]*>(.*?)<\/h1>/su', $html, $m);
    expect(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $m[1] ?? ''))->toBe(0)
        ->and(preg_match('/#[0-9a-fA-F]{6}\b/', strip_tags($m[0] ?? '')))->toBe(0);
});

test('Z1-07: remaining time is formatted from real dates (days/hours/minutes) and is null once ended', function () {
    $now = Carbon::parse('2026-10-10 12:00:00', 'UTC');

    expect(MissionCenterService::formatRemaining($now, $now->copy()->addDays(2)->addHours(3)))->toBe('2 يوم و3 ساعة')
        ->and(MissionCenterService::formatRemaining($now, $now->copy()->addHours(5)->addMinutes(20)))->toBe('5 ساعة و20 دقيقة')
        ->and(MissionCenterService::formatRemaining($now, $now->copy()->addSeconds(30)))->toBe('1 دقيقة')
        ->and(MissionCenterService::formatRemaining($now, $now->copy()->subSecond()))->toBeNull()
        ->and(MissionCenterService::formatRemaining($now, $now))->toBeNull();
});

test('Z1-08: the streak panel shows the effective streak (a broken streak shows 0) while the longest streak is kept, with no punishment wording', function () {
    $u = e34_user();
    e34_quest();
    e34_streak($u, 5, 9, now()->subDays(4)->toDateString());

    $data = app(MissionCenterService::class)->forUser($u, false);

    expect($data['milestones']['current'])->toBe(0)->and($data['milestones']['longest'])->toBe(9)
        ->and(PlayerStreak::query()->where('user_id', $u->id)->value('current_streak'))->toBe(5); // لا كتابة ولا تصفير مخزَّن

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();
    foreach (['ستفقد', 'خسرت سلسلتك', 'انقطعت'] as $bad) {
        expect($html)->not->toContain($bad);
    }
});

test('Z1-09: the home widget shows at most three missions, reads only, and writes no progress rows', function () {
    $u = e34_user();
    e34_quests(5);
    expect(UserQuestProgress::query()->count())->toBe(0);

    $html = $this->actingAs($u)->get('/')->assertOk()->getContent();

    expect(UserQuestProgress::query()->count())->toBe(0);

    $widget = app(MissionCenterService::class)->widgetFor($u);
    expect($widget['missions']->count())->toBeLessThanOrEqual(3)->and($widget['total'])->toBe(5)->and($widget['completed'])->toBe(0);
    expect($html)->toContain('data-missions-widget');
});
````

## `tests/Feature/LiveOpsE34/E34MissionProgressTest.php`

````php
<?php

use App\Models\PlayerProgression;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\MissionAssignmentService;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());

test('Z3-01: a solve advances global missions and only the missions assigned to that player, never the other pool members', function () {
    $u = e34_user();
    $global = e34_quest();
    $members = e34_quests(5, ['target_value' => 3]);
    e34_pool($members, ['selection_count' => 2]);

    e34_solve($u);

    $progress = UserQuestProgress::query()->where('user_id', $u->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    $assigned = UserMissionAssignment::query()->where('user_id', $u->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();

    expect($assigned)->toHaveCount(2)
        ->and($progress)->toEqualCanonicalizing([$global->id, ...$assigned])
        ->and(UserQuestProgress::query()->where('user_id', $u->id)->whereIn('quest_definition_id', array_diff(array_map(fn ($q) => $q->id, $members), $assigned))->count())->toBe(0);
});

test('Z3-02: the reward is granted once through the existing authority (XP) and never doubled by repeated solves or re-evaluation', function () {
    $u = e34_user();
    $quest = e34_quest(['xp_reward' => 10]);
    e34_pool([$quest, e34_quest()], ['selection_count' => 2]);

    $questXp = fn () => (int) XpTransaction::query()->where('user_id', $u->id)->where('type', XpTransaction::TYPE_QUEST_REWARD)->sum('amount');

    e34_solve($u);
    expect($questXp())->toBe(10);

    e34_solve($u);
    app(QuestService::class)->syncCurrentQuests($u);
    app(QuestService::class)->syncCurrentQuests($u);

    expect($questXp())->toBe(10)
        ->and(XpTransaction::query()->where('user_id', $u->id)->where('type', XpTransaction::TYPE_QUEST_REWARD)->count())->toBe(1);
});

test('Z3-03: playing a community puzzle never touches missions, assignments, streaks or any economy table (zero-economy isolation)', function () {
    $player = e34_user();
    $quest = e34_quest(['target_value' => 1, 'xp_reward' => 10]);
    e34_pool(e34_quests(3), ['selection_count' => 2]);
    $work = e28_published(e28_creator());

    $before = e28_world($player);
    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), 'القلم');

    expect(e28_world($player))->toBe($before)
        ->and(UserQuestProgress::query()->where('user_id', $player->id)->count())->toBe(0)
        ->and(UserMissionAssignment::query()->where('user_id', $player->id)->count())->toBe(0);

    $this->actingAs($player)->get('/missions')->assertOk();
    expect(UserQuestProgress::query()->where('user_id', $player->id)->where('quest_definition_id', $quest->id)->value('current_value'))->toBe(0);
});

test('Z3-04: the client cannot drive progress or rewards - missions/quests/events routes are read-only and writes are rejected', function () {
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (preg_match('#^(missions|quests|events)(/|$)#', $uri)) {
            expect(array_values(array_diff($route->methods(), ['GET', 'HEAD'])))->toBe([], "route {$uri} must be GET-only");
        }
    }

    $u = e34_user();
    $quest = e34_quest();
    $before = UserQuestProgress::query()->count();

    $this->actingAs($u)->post('/missions', ['current_value' => 99, 'quest' => $quest->id])->assertStatus(405);
    $this->actingAs($u)->post('/quests', ['amount' => 500])->assertStatus(405);
    $this->actingAs($u)->put('/events', ['x' => 1])->assertStatus(405);

    expect(UserQuestProgress::query()->count())->toBe($before);
});

test('Z3-05: continuity - a mission the player already progressed stays visible to them after it joins a pool, and stays hidden from others', function () {
    $u = e34_user();
    $other = e34_user();
    $quest = e34_quest(['target_value' => 5]);
    app(QuestService::class)->progressFor($u, $quest, app(QuestPeriodService::class)->dailyContext());

    $filler = e34_quests(3);
    e34_pool([$quest, ...$filler], ['selection_count' => 1]);

    expect(e34_visible_ids($u))->toContain($quest->id)->and(e34_visible_via_isVisible($u))->toContain($quest->id);
    expect(e34_visible_ids($other))->toHaveCount(1);
});

test('Z3-06: an assignment failure fails closed for pooled missions only and never breaks gameplay or global missions', function () {
    $u = e34_user();
    $global = e34_quest(['target_value' => 2]);
    e34_pool(e34_quests(3), ['selection_count' => 2]);

    $this->partialMock(MissionAssignmentService::class, fn ($m) => $m->shouldReceive('assignedQuestIds')->andThrow(new RuntimeException('boom')));

    e34_solve($u); // لا يرمي

    expect(UserQuestProgress::query()->where('user_id', $u->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all())->toBe([$global->id])
        ->and(e34_visible_ids($u))->toBe([$global->id]);
});

test('Z3-07: batched visibility (visibleQuestsFor) matches per-quest visibility (isVisibleTo) across every mixed case', function () {
    $u = e34_user();
    e34_quest();                                              // عامة نشطة
    $inactiveWith = e34_quest(['is_active' => false]);        // معطّلة لكن لها تقدّم
    e34_quest(['is_active' => false]);                        // معطّلة بلا تقدّم
    e34_quest(['starts_at' => now()->addDay()]);              // خارج النافذة
    e34_quest(['ends_at' => now()->subHour()]);               // منتهية
    $members = e34_quests(4);
    e34_pool($members, ['selection_count' => 2]);
    $other = e34_pool(e34_quests(2), ['selection_count' => 1, 'priority' => 500]);
    $weekly = e34_weekly();
    e34_pool([$weekly, e34_weekly()], ['period_type' => 'weekly', 'selection_count' => 1]);

    app(QuestService::class)->progressFor($u, $inactiveWith, app(QuestPeriodService::class)->dailyContext());

    foreach (['daily', 'weekly'] as $type) {
        expect(e34_visible_ids($u, $type))->toBe(collect(e34_visible_via_isVisible($u, $type))->sort()->values()->all());
    }

    expect($other->fresh()->is_active)->toBeTrue();
});

test('Z3-08: missions of other period types or other pools are never given progress rows for a player who is not assigned them', function () {
    $u = e34_user();
    e34_pool([e34_weekly(), e34_weekly(), e34_weekly()], ['period_type' => 'weekly', 'selection_count' => 1]);

    e34_solve($u);

    expect(UserQuestProgress::query()->where('user_id', $u->id)->where('period_type', 'weekly')->count())->toBe(1)
        ->and(UserMissionAssignment::query()->where('user_id', $u->id)->count())->toBe(1);
});

test('Z3-09: no second engine - the evaluator set is unchanged and LiveOps services never write progress, rewards or wallets', function () {
    expect(QuestEvaluatorRegistry::ALL_TYPES)->toBe([
        QuestEvaluatorRegistry::PUZZLES_SOLVED, QuestEvaluatorRegistry::PUZZLES_SOLVED_IN_CATEGORY,
        QuestEvaluatorRegistry::CAMPAIGN_STEPS_COMPLETED, QuestEvaluatorRegistry::QUALIFICATIONS_EARNED,
    ]);

    $forbidden = ['GemWalletService', 'ProgressionRewardService', 'grantXp', 'grantQuestRewards', 'UserQuestProgress::query()->create', 'UserQuestProgress::create', 'UserQuestProgress::query()->update', 'forceCreate'];

    foreach (glob(base_path('app/Services/LiveOps').'/{*,*/*}.php', GLOB_BRACE) as $file) {
        $src = e34_code_only($file);
        foreach ($forbidden as $needle) {
            // الاستثناء الوحيد المقصود: لقطة الإسناد نفسها (UserMissionAssignment) عبر insertOrIgnore.
            expect(str_contains($src, $needle))->toBeFalse("{$file} must not contain {$needle}");
        }
    }
});
````

## `tests/Feature/LiveOpsE34/E34NotificationsTest.php`

````php
<?php

use App\Models\CompetitiveEventParticipant;
use App\Models\User;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\LiveOpsReminderService;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\PlatformSettingsService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 21:30:00', 'UTC')); // الأربعاء، داخل آخر 4 ساعات من اليوم
});
afterEach(fn () => Carbon::setTestNow());

function e34_notes(User $u, NotificationType $t): int
{
    return DatabaseNotification::query()->where('notifiable_id', $u->id)->where('type_key', $t->value)->count();
}

function e34_reminders(): LiveOpsReminderService
{
    return app(LiveOpsReminderService::class);
}

/** تقدّم مفتوح (بدأه ولم يكمله) على مهمة يومية. */
function e34_open_progress(User $u, int $current = 1, int $target = 3): void
{
    $quest = e34_quest(['target_value' => $target]);
    $p = app(QuestService::class)->progressFor($u, $quest, app(QuestPeriodService::class)->dailyContext());
    \App\Models\UserQuestProgress::query()->whereKey($p->id)->update(['current_value' => $current]);
}

test('Z7-01: registry integrity - four new types, no new category, none of them a re-engagement type, calm copy without pressure', function () {
    $new = [NotificationType::MissionExpiring, NotificationType::WeeklyMissionsAvailable, NotificationType::EventStartingSoon, NotificationType::ComebackAvailable];

    expect(count(NotificationCategory::cases()))->toBe(8)->and(count(NotificationCategory::optional()))->toBe(7)
        ->and(NotificationType::reEngagementKeys())->toEqualCanonicalizing(['streak_at_risk', 'daily_quests_available', 'season_ending_soon', 'campaign_ending_soon', 'competitive_event_ending_soon']);

    foreach ($new as $t) {
        expect($t->isReEngagement())->toBeFalse()->and($t->category())->toBeInstanceOf(NotificationCategory::class)->and($t->icon())->not->toBe('');
        $text = $t->title(['count' => 2, 'title' => 'حدث', 'hours' => 5]).' '.$t->body(['count' => 2, 'title' => 'حدث', 'hours' => 5]);
        foreach (['اشترِ', 'ستفقد', 'باقي دقائق', 'آخر فرصة', 'سارع', 'لا تفوّت'] as $bad) {
            expect($text)->not->toContain($bad);
        }
    }
    expect(LiveOpsReminderService::typeKeys())->toBe(array_map(fn ($t) => $t->value, $new));
});

test('Z7-02: mission-expiring reaches only players with an in-progress mission, only in the last hours of the local day', function () {
    $started = e34_user();
    e34_open_progress($started);
    $notStarted = e34_user();
    $done = e34_user();
    $q = e34_quest();
    $p = app(QuestService::class)->progressFor($done, $q, app(QuestPeriodService::class)->dailyContext());
    \App\Models\UserQuestProgress::query()->whereKey($p->id)->update(['current_value' => 1, 'completed_at' => now(), 'reward_granted_at' => now()]);

    $stats = e34_reminders()->missionExpiring();

    expect($stats['created'])->toBe(1)->and(e34_notes($started, NotificationType::MissionExpiring))->toBe(1)
        ->and(e34_notes($notStarted, NotificationType::MissionExpiring))->toBe(0)->and(e34_notes($done, NotificationType::MissionExpiring))->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
    expect(e34_reminders()->missionExpiring())->toMatchArray(['skipped' => 'before_window', 'created' => 0]);
});

test('Z7-03: reruns are idempotent - the same player is never notified twice for the same period', function () {
    $u = e34_user();
    e34_open_progress($u);

    $first = e34_reminders()->missionExpiring();
    $second = e34_reminders()->missionExpiring();
    $third = e34_reminders()->run()['mission_expiring'];

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)->and($second['already'])->toBe(1)->and($third['created'])->toBe(0)
        ->and(e34_notes($u, NotificationType::MissionExpiring))->toBe(1);
});

test('Z7-04: category preferences and the global switch are respected; frozen and unverified accounts are skipped', function () {
    $optedOut = e34_user();
    e34_open_progress($optedOut);
    app(NotificationPreferenceService::class)->update($optedOut, ['quest_enabled' => false]);

    $frozen = e34_user(['is_frozen' => true]);
    e34_open_progress($frozen);
    $unverified = e34_user(['email_verified_at' => null]);
    e34_open_progress($unverified);

    $stats = e34_reminders()->missionExpiring();

    expect($stats['created'])->toBe(0)->and($stats['suppressed'])->toBe(1)->and($stats['ineligible'])->toBe(2)
        ->and(DatabaseNotification::query()->count())->toBe(0);

    $ok = e34_user();
    e34_open_progress($ok);
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    expect(e34_reminders()->missionExpiring())->toMatchArray(['skipped' => 'disabled', 'created' => 0]);
});

test('Z7-05: the LiveOps daily cap holds across types for one player', function () {
    $u = e34_user();
    e34_open_progress($u);
    $event = e17Event(['title' => 'منافسة الليلة', 'starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(9)]);
    CompetitiveEventParticipant::query()->create(['competitive_event_id' => $event->id, 'user_id' => $u->id, 'status' => 'registered', 'registered_at' => now()]);

    $a = e34_reminders()->missionExpiring();
    $b = e34_reminders()->eventStarting();

    expect($a['created'])->toBe(1)->and($b['created'])->toBe(0)->and($b['over_budget'])->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $u->id)->count())->toBe(1);

    config(['liveops.notifications.daily_cap' => 2]);
    expect(e34_reminders()->eventStarting()['created'])->toBe(1);
});

test('Z7-06: event-starting-soon goes only to registered players of a published event starting inside the window, once per event', function () {
    $registered = e34_user();
    $stranger = e34_user();
    $soon = e17Event(['title' => 'تبدأ قريبًا', 'starts_at' => now()->addHours(6), 'ends_at' => now()->addHours(10)]);
    $far = e17Event(['title' => 'بعد يومين', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]);
    $draft = e17Event(['title' => 'مسودة', 'starts_at' => now()->addHours(6), 'ends_at' => now()->addHours(9)], \App\Models\CompetitiveEvent::STATUS_DRAFT);

    foreach ([$soon, $far, $draft] as $e) {
        CompetitiveEventParticipant::query()->create(['competitive_event_id' => $e->id, 'user_id' => $registered->id, 'status' => 'registered', 'registered_at' => now()]);
    }

    $first = e34_reminders()->eventStarting();
    $second = e34_reminders()->eventStarting();

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)
        ->and(e34_notes($registered, NotificationType::EventStartingSoon))->toBe(1)->and(e34_notes($stranger, NotificationType::EventStartingSoon))->toBe(0);
});

test('Z7-07: comeback notices are off by default, then go once per absence to players inside the allowed range', function () {
    $in = e34_user();
    e34_streak($in, 2, 4, now()->subDays(10)->toDateString());
    $tooFresh = e34_user();
    e34_streak($tooFresh, 2, 4, now()->subDays(3)->toDateString());
    $tooOld = e34_user();
    e34_streak($tooOld, 2, 4, now()->subDays(80)->toDateString());

    expect(e34_reminders()->comeback())->toMatchArray(['skipped' => 'disabled', 'created' => 0]);

    config(['liveops.notifications.comeback_available' => true]);
    $first = e34_reminders()->comeback();
    $second = e34_reminders()->comeback();

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)
        ->and(e34_notes($in, NotificationType::ComebackAvailable))->toBe(1)
        ->and(e34_notes($tooFresh, NotificationType::ComebackAvailable))->toBe(0)->and(e34_notes($tooOld, NotificationType::ComebackAvailable))->toBe(0);
});

test('Z7-08: weekly missions are announced only on the platform week start, only to recently active players, and are off by default', function () {
    e34_weekly();
    $active = e34_user();
    e34_streak($active, 2, 2, now()->toDateString());
    $lapsed = e34_user();
    e34_streak($lapsed, 2, 2, now()->subDays(30)->toDateString());

    expect(e34_reminders()->weeklyMissions())->toMatchArray(['skipped' => 'disabled']);

    config(['liveops.notifications.weekly_missions_available' => true]);
    expect(e34_reminders()->weeklyMissions())->toMatchArray(['skipped' => 'not_week_start', 'created' => 0]); // الأربعاء

    Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00', 'UTC')); // الإثنين
    e34_streak($active, 2, 2, now()->toDateString());
    $stats = e34_reminders()->weeklyMissions();

    expect($stats['created'])->toBe(1)->and(e34_notes($active, NotificationType::WeeklyMissionsAvailable))->toBe(1)->and(e34_notes($lapsed, NotificationType::WeeklyMissionsAvailable))->toBe(0);
});

test('Z7-09: delivery is chunked - every eligible player is reached exactly once however small the chunk', function () {
    config(['liveops.notifications.chunk_size' => 2]);
    $users = collect(range(1, 5))->map(function () {
        $u = e34_user();
        e34_open_progress($u);

        return $u;
    });

    $stats = e34_reminders()->missionExpiring();

    expect($stats['created'])->toBe(5)->and($stats['candidates'])->toBe(5);
    foreach ($users as $u) {
        expect(e34_notes($u, NotificationType::MissionExpiring))->toBe(1);
    }
});

test('Z7-10: the command is registered on an hourly schedule and runs cleanly', function () {
    expect(Artisan::call('liveops:remind'))->toBe(0)->and(Artisan::output())->toContain('mission_expiring')->toContain('comeback');

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('liveops:remind');
});

test('Z7-11: one failing notification never stops the rest of the batch', function () {
    $a = e34_user();
    $b = e34_user();
    e34_open_progress($a);
    e34_open_progress($b);

    $calls = 0;
    $this->mock(\App\Services\Notifications\NotificationDispatcher::class, function ($m) use (&$calls) {
        $m->shouldReceive('dispatch')->andReturnUsing(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('boom');
            }

            return \App\Services\Notifications\DispatchResult::Created;
        });
    });

    $stats = e34_reminders()->missionExpiring();

    expect($calls)->toBe(2)->and($stats['failed'])->toBe(1)->and($stats['created'])->toBe(1);
});
````

## `tests/Feature/LiveOpsE34/E34PerformanceTest.php`

````php
<?php

use App\Models\Campaign;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\QuestPeriodService;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\LiveOpsPlannerService;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionCenterService;
use App\Services\LiveOps\RetentionAnalytics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());

/** @return array{0:int,1:int} [كل الاستعلامات، المنسوبة لجداول E34 فقط] */
function e34_split_queries(\Closure $fn): array
{
    $all = 0;
    $e34 = 0;
    DB::listen(function ($q) use (&$all, &$e34) {
        $all++;
        if (preg_match('/mission_pool|user_mission_assignments/', $q->sql)) {
            $e34++;
        }
    });
    $fn();

    return [$all, $e34];
}

test('Z11-01: the E34 share of a mission page load is a small constant independent of pool size', function () {
    $small = e34_user();
    $big = e34_user();

    e34_pool(e34_quests(4), ['selection_count' => 2]);
    [, $smallPool] = e34_split_queries(fn () => $this->actingAs($small)->get('/missions')->assertOk());

    e34_pool(e34_quests(30), ['selection_count' => 2, 'priority' => 1]);
    [, $bigPool] = e34_split_queries(fn () => $this->actingAs($big)->get('/missions')->assertOk());

    expect($smallPool)->toBeLessThanOrEqual(12)->and($bigPool)->toBeLessThanOrEqual(12)->and(abs($bigPool - $smallPool))->toBeLessThanOrEqual(2);
});

test('Z11-02: no pools means almost no extra work - the pool snapshot costs at most two reads and none once cached', function () {
    e34_quests(3);
    $u = e34_user();

    [, $none] = e34_split_queries(fn () => $this->actingAs($u)->get('/missions')->assertOk());
    expect($none)->toBeLessThanOrEqual(2);

    config(['liveops.pools.cache_seconds' => 300]);
    MissionAssignmentService::flushSnapshot();
    app(MissionAssignmentService::class)->snapshot();

    [, $cached] = e34_split_queries(fn () => app(MissionAssignmentService::class)->snapshot()); // مثيل جديد، كاش دافئ
    expect($cached)->toBe(0);

    $raw = json_encode(Cache::get(MissionAssignmentService::CACHE_KEY));
    expect($raw)->not->toContain('user_id')->not->toContain('email');
});

test('Z11-03: repeated visibility checks for one player and period reuse the assignment lookup (memoised), not one query per mission', function () {
    $pool = e34_pool(e34_quests(15), ['selection_count' => 3]);
    $quests = QuestDefinition::query()->get();
    $svc = app(MissionAssignmentService::class);
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $u = e34_user();

    $n = e34_count_queries(function () use ($quests, $svc, $u, $ctx) {
        foreach ($quests as $q) {
            $svc->isAllowed($u, $q, $ctx);
        }
    });

    expect($n)->toBeLessThanOrEqual(12)->and($pool->fresh()->is_active)->toBeTrue();
});

test('Z11-04: the page cost per visible mission stays within the existing E13 self-healing budget (regression guard, no N+1 added by E34)', function () {
    $costs = [];

    foreach ([3, 12] as $n) {
        QuestDefinition::query()->update(['is_active' => false]); // لا حذف: صفوف التقدّم تشير إليها (FK)
        e34_quests($n);
        $u = e34_user();
        $this->actingAs($u)->get('/missions')->assertOk();                                   // يُنشئ صفوف التقدّم
        $costs[$n] = e34_count_queries(fn () => $this->actingAs($u)->get('/missions')->assertOk());  // الفتح الدافئ
    }

    $perQuest = ($costs[12] - $costs[3]) / 9;
    expect($perQuest)->toBeLessThanOrEqual(5.0)->and($costs[3])->toBeLessThanOrEqual(70);
});

test('Z11-05: the home widget and dashboard cost the same whether there are 3 or 20 missions', function () {
    $counts = [];

    foreach ([3, 20] as $n) {
        QuestDefinition::query()->update(['is_active' => false]); // لا حذف: صفوف التقدّم تشير إليها (FK)
        e34_quests($n);
        $u = e34_user();
        $this->actingAs($u)->get('/')->assertOk(); // إحماء (كاشات الإعدادات/الجلسة) كي لا يُحسب على أول قياس
        $counts[$n] = [
            e34_count_queries(fn () => app(MissionCenterService::class)->widgetFor($u)),
            e34_count_queries(fn () => $this->actingAs($u)->get('/')->assertOk()),
        ];
    }

    expect($counts[3][0])->toBeLessThanOrEqual(20)->and(abs($counts[20][0] - $counts[3][0]))->toBeLessThanOrEqual(1)
        ->and(abs($counts[20][1] - $counts[3][1]))->toBeLessThanOrEqual(3);
});

test('Z11-06: the calendar is one cached snapshot - constant uncached cost, zero cached cost, no per-event queries', function () {
    $uncached = [];
    $page = [];

    foreach ([5, 30] as $n) {
        Campaign::query()->delete();
        foreach (range(1, $n) as $_) {
            Campaign::withoutEvents(fn () => Campaign::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3)]));
        }

        config(['liveops.calendar.cache_seconds' => 0]);
        $uncached[$n] = e34_count_queries(fn () => app(EventCalendarService::class)->items());

        config(['liveops.calendar.cache_seconds' => 60]);
        EventCalendarService::flush();
        app(EventCalendarService::class)->items();
        expect(e34_count_queries(fn () => app(EventCalendarService::class)->items()))->toBe(0);
        $this->get('/events')->assertOk(); // إحماء
        $page[$n] = e34_count_queries(fn () => $this->get('/events')->assertOk());
    }

    expect($uncached[5])->toBeLessThanOrEqual(8)->and($uncached[30])->toBe($uncached[5])
        ->and($page[5])->toBeLessThanOrEqual(12)->and($page[30])->toBe($page[5]);
});

test('Z11-07: the planner and the analytics page issue a constant number of queries regardless of data volume', function () {
    $planner = [];
    $analytics = [];

    foreach ([3, 30] as $n) {
        QuestDefinition::query()->update(['is_active' => false]); // لا حذف: صفوف التقدّم تشير إليها (FK)
        e34_quests($n);
        $planner[$n] = e34_count_queries(fn () => app(LiveOpsPlannerService::class)->overview());
    }

    foreach ([10, 60] as $n) {
        foreach (range(1, $n) as $_) {
            e34_streak(e34_user(), 2, 2, now()->toDateString());
        }
        RetentionAnalytics::flush();
        $analytics[$n] = e34_count_queries(fn () => app(RetentionAnalytics::class)->overview(30));
    }

    expect($planner[3])->toBeLessThanOrEqual(15)->and($planner[30])->toBe($planner[3])
        ->and($analytics[10])->toBeLessThanOrEqual(30)->and($analytics[60])->toBe($analytics[10]);
});

test('Z11-08: scale smoke - a hundred players are assigned from a large pool with bounded writes and no duplicate rows', function () {
    $pool = e34_pool(e34_quests(40), ['selection_count' => 3]);
    $users = collect(range(1, 100))->map(fn () => e34_user());

    foreach ($users as $u) {
        e34_visible_ids($u);
    }

    $rows = DB::table('user_mission_assignments');
    expect((clone $rows)->count())->toBe(300)
        ->and((clone $rows)->select('user_id', 'quest_definition_id', 'period_key')->distinct()->get()->count())->toBe(300)
        ->and(DB::table('user_mission_assignments')->where('mission_pool_id', $pool->id)->count())->toBe(300);
});
````

## `tests/Feature/LiveOpsE34/E34PoolRotationTest.php`

````php
<?php

use App\Models\PlayerProgression;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionPoolService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC')); // أربعاء: الأسبوع 42
});
afterEach(fn () => Carbon::setTestNow());

function e34_assigned(\App\Models\User $u, ?string $key = null): array
{
    return UserMissionAssignment::query()->where('user_id', $u->id)
        ->when($key, fn ($q) => $q->where('period_key', $key))
        ->orderBy('quest_definition_id')->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
}

test('Z2-10: a new pool is created inactive, and activating an empty pool is refused with a clear reason', function () {
    $admin = e34_admin();
    $pool = e34_pool([], [], false, $admin);

    expect($pool->is_active)->toBeFalse();

    try {
        app(MissionPoolService::class)->activate($admin, $pool);
        $this->fail('activation of an empty pool must be refused');
    } catch (LiveOpsException $e) {
        expect($e->reason)->toBe('empty_pool');
    }

    expect($pool->fresh()->is_active)->toBeFalse();
});

test('Z2-11: pooled missions are invisible to everyone until assigned, including while their pool is inactive; global ones are unaffected', function () {
    $u = e34_user();
    $global = e34_quest();
    [$a, $b] = e34_quests(2);
    e34_pool([$a, $b], [], false); // معطّلة

    expect(e34_visible_ids($u))->toBe([$global->id])
        ->and(e34_visible_via_isVisible($u))->toBe([$global->id])
        ->and(UserMissionAssignment::query()->count())->toBe(0);
});

test('Z2-12: an active pool assigns exactly selection_count missions per user, deterministically across fresh service instances', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(6), ['selection_count' => 3]);
    $period = app(QuestPeriodService::class)->dailyContext();
    $snapshot = app(MissionAssignmentService::class)->snapshot()['pools'][$pool->id];

    $picked1 = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($u, $snapshot, $period));
    $picked2 = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($u, $snapshot, $period));
    $assigned = app(MissionAssignmentService::class)->assignedQuestIds($u, $period);

    expect($picked1)->toBe($picked2)->and($assigned)->toHaveCount(3)->and($assigned)->toEqualCanonicalizing($picked1);
    expect(app(MissionAssignmentService::class)->assignedQuestIds($u, $period))->toBe($assigned); // فتح ثانٍ: لا صفوف جديدة
    expect(e34_assigned($u))->toHaveCount(3);
});

test('Z2-13: different users receive different selections from the same pool, each with the configured size', function () {
    $pool = e34_pool(e34_quests(8), ['selection_count' => 3]);
    $sets = [];

    foreach (range(1, 12) as $_) {
        $u = e34_user();
        $ids = e34_visible_ids($u);
        expect($ids)->toHaveCount(3);
        $sets[implode(',', $ids)] = true;
    }

    expect(count($sets))->toBeGreaterThan(1)->and($pool->fresh()->is_active)->toBeTrue();
});

test('Z2-14: a snapshot is stable mid-period against pool edits and deactivation, and the next period picks up the new pool state', function () {
    $u = e34_user();
    $pool = e34_pool(e34_quests(4), ['selection_count' => 2]);
    $before = e34_visible_ids($u);
    $key = app(QuestPeriodService::class)->dailyContext()->periodKey;

    $admin = e34_admin();
    $svc = app(MissionPoolService::class);
    $newcomer = e34_quest();
    $svc->attach($admin, $pool, $newcomer);
    $svc->update($admin, $pool, ['name' => 'اسم جديد', 'slug' => $pool->slug, 'period_type' => 'daily', 'selection_count' => 4]);
    $svc->deactivate($admin, $pool);

    expect(e34_visible_ids($u))->toBe($before)->and(e34_assigned($u, $key))->toEqualCanonicalizing($before);

    // اليوم التالي: المجموعة معطّلة فلا إسناد جديد من مجموعة (المهام المجمّعة لا تظهر)
    Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', 'UTC'));
    expect(e34_visible_ids($u))->toBe([]);

    $svc->activate($admin, $pool->fresh());
    $next = e34_visible_ids($u);
    expect($next)->toHaveCount(4)->and(e34_assigned($u, 'daily:2026-10-15'))->toHaveCount(4);
});

test('Z2-15: global missions stay visible next to the pool picks (backward compatible)', function () {
    $u = e34_user();
    $global = e34_quests(2);
    e34_pool(e34_quests(5), ['selection_count' => 2]);

    $ids = e34_visible_ids($u);

    expect($ids)->toHaveCount(4)->and(array_values(array_intersect($ids, array_map(fn ($q) => $q->id, $global))))->toHaveCount(2);
});

test('Z2-16: with no pools nothing is assigned and no assignment query path is needed for plain missions', function () {
    $u = e34_user();
    e34_quests(3);

    expect(e34_visible_ids($u))->toHaveCount(3)->and(UserMissionAssignment::query()->count())->toBe(0);

    $svc = app(MissionAssignmentService::class);
    $plain = QuestDefinition::query()->first();
    expect($svc->isPooled($plain))->toBeFalse()->and($svc->isAllowed($u, $plain, app(QuestPeriodService::class)->dailyContext()))->toBeTrue();
});

test('Z2-17: eligibility rules route players to the first pool they qualify for, with the "all" pool as a fallback', function () {
    $newbie = e34_user([], 3);
    $veteran = e34_user([], 40);
    $returner = e34_user([], 60);
    e34_streak($returner, 2, 9, now()->subDays(10)->toDateString());
    $expert = e34_user([], 60);
    PlayerProgression::factory()->create(['user_id' => $expert->id, 'current_level' => 6]);

    $np = e34_pool(e34_quests(2), ['eligibility_rule' => 'new_player', 'priority' => 10, 'selection_count' => 2]);
    $rt = e34_pool(e34_quests(2), ['eligibility_rule' => 'returning', 'priority' => 20, 'selection_count' => 2]);
    $ex = e34_pool(e34_quests(2), ['eligibility_rule' => 'experienced', 'priority' => 30, 'selection_count' => 2]);
    $all = e34_pool(e34_quests(2), ['eligibility_rule' => 'all', 'priority' => 999, 'selection_count' => 2]);

    $poolOf = function ($u) {
        e34_visible_ids($u);

        return UserMissionAssignment::query()->where('user_id', $u->id)->distinct()->pluck('mission_pool_id')->map(fn ($i) => (int) $i)->all();
    };

    expect($poolOf($newbie))->toBe([$np->id])
        ->and($poolOf($returner))->toBe([$rt->id])
        ->and($poolOf($expert))->toBe([$ex->id])
        ->and($poolOf($veteran))->toBe([$all->id]);
});

test('Z2-18: a pool outside its own window (not yet started, or already ended) is never used', function () {
    $u = e34_user();
    e34_pool(e34_quests(2), ['starts_at' => now()->addDay(), 'priority' => 1]);
    e34_pool(e34_quests(2), ['ends_at' => now()->subHour(), 'starts_at' => now()->subDays(3), 'priority' => 2]);

    expect(e34_visible_ids($u))->toBe([])->and(UserMissionAssignment::query()->count())->toBe(0);

    $live = e34_pool(e34_quests(2), ['starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'priority' => 3]);
    expect(e34_visible_ids($u))->toHaveCount(2)->and(UserMissionAssignment::query()->first()->mission_pool_id)->toBe($live->id);
});

test('Z2-19: only active, in-window missions of the right period are selectable', function () {
    $u = e34_user();
    $ok = e34_quest();
    $inactive = e34_quest(['is_active' => false]);
    $future = e34_quest(['starts_at' => now()->addDay()]);
    $expired = e34_quest(['ends_at' => now()->subHour()]);
    e34_pool([$ok, $inactive, $future, $expired], ['selection_count' => 4]);

    expect(e34_visible_ids($u))->toBe([$ok->id]);
});

test('Z2-20: transparent personalization prefers open progress and recently played official categories, deterministically', function () {
    $category = PuzzleCategory::factory()->create();
    $plain = e34_quests(3, ['target_value' => 3]);
    $scoped = QuestDefinition::factory()->daily(3)->puzzlesSolvedInCategory($category->id, 3)->create();
    $pool = e34_pool([...$plain, $scoped], ['selection_count' => 1, 'personalized' => true]);

    // فئة رسمية لُعبت مؤخرًا ⇒ +2 للمهمة المرتبطة بها (الحلّ نفسه هو ما يُطلق الإسناد الكسول)
    $solver = e34_user();
    e34_solve($solver, $category);
    expect(e34_visible_ids($solver))->toBe([$scoped->id]);

    // تقدّم مفتوح قائم (+3) على مهمة عادية بدأها المستخدم قبل الإسناد يُفضَّل بلا أي إشارة أخرى
    $opener = e34_user();
    $open = $plain[1];
    $progress = app(QuestService::class)->progressFor($opener, $open, app(QuestPeriodService::class)->dailyContext());
    \App\Models\UserQuestProgress::query()->whereKey($progress->id)->update(['current_value' => 1]);

    expect(app(MissionAssignmentService::class)->assignedQuestIds($opener, app(QuestPeriodService::class)->dailyContext()))->toBe([$open->id]);

    // التخصيص مُطفأ على المجموعة: اختيار حتمي بالـhash فقط
    app(MissionPoolService::class)->update(e34_admin(), $pool, ['name' => $pool->name, 'slug' => $pool->slug, 'period_type' => 'daily', 'personalized' => false]);
    $other = e34_user();
    $snapshot = app(MissionAssignmentService::class)->snapshot()['pools'][$pool->id];
    $ctx = app(QuestPeriodService::class)->dailyContext();
    $a = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($other, $snapshot, $ctx));
    $b = array_map(fn ($q) => $q->id, app(MissionAssignmentService::class)->select($other, $snapshot, $ctx));

    expect($a)->toBe($b)->toHaveCount(1)->and($snapshot['personalized'])->toBeFalse();
});

test('Z2-21: the first eligible pool wins for the period and a pool with no valid candidates is skipped', function () {
    $u = e34_user();
    $dead = e34_pool([e34_quest(['is_active' => false])], ['priority' => 1, 'selection_count' => 1]);
    $good = e34_pool(e34_quests(3), ['priority' => 2, 'selection_count' => 2]);
    e34_pool(e34_quests(3), ['priority' => 3, 'selection_count' => 2]);

    e34_visible_ids($u);

    expect(UserMissionAssignment::query()->where('user_id', $u->id)->distinct()->pluck('mission_pool_id')->all())->toBe([$good->id])
        ->and($dead->fresh()->is_active)->toBeTrue();
});

test('Z2-22: weekly pools rotate independently of daily ones and keep one snapshot for the whole week', function () {
    $u = e34_user();
    $weeklyPool = e34_pool([e34_weekly(), e34_weekly(), e34_weekly()], ['period_type' => 'weekly', 'selection_count' => 2]);
    $dailyPool = e34_pool(e34_quests(2), ['selection_count' => 1]);

    $week1 = e34_visible_ids($u, 'weekly');
    Carbon::setTestNow(Carbon::parse('2026-10-18 20:00:00', 'UTC')); // الأحد نفس الأسبوع (يبدأ الإثنين)
    expect(e34_visible_ids($u, 'weekly'))->toBe($week1)->and($week1)->toHaveCount(2);

    Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00', 'UTC')); // الإثنين: أسبوع جديد
    expect(e34_visible_ids($u, 'weekly'))->toHaveCount(2);
    $rows = UserMissionAssignment::query()->where('user_id', $u->id)->where('mission_pool_id', $weeklyPool->id)->get();
    expect($rows)->toHaveCount(4)->and($rows->pluck('period_key')->unique())->toHaveCount(2)
        ->and(UserMissionAssignment::query()->where('mission_pool_id', $dailyPool->id)->count())->toBe(0);

    expect($weeklyPool->fresh()->period_type)->toBe('weekly');
});
````

## `tests/Feature/LiveOpsE34/E34StreakMilestoneTest.php`

````php
<?php

use App\Models\Achievement;
use App\Models\CurrencyTransaction;
use App\Models\GemTransaction;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\XpTransaction;
use App\Services\LiveOps\StreakMilestoneService;
use App\Services\Progression\Achievements\StreakLongestDaysEvaluator;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Database\Seeders\StreakMilestoneAchievementsSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_unlocked(User $u, string $key): bool
{
    $a = Achievement::query()->where('internal_key', $key)->first();

    return $a !== null && UserAchievementProgress::query()->where('user_id', $u->id)->where('achievement_id', $a->id)->whereNotNull('unlocked_at')->exists();
}

test('Z4-01: thresholds come from config, are sorted, unique and positive only', function () {
    config(['liveops.streak_milestones' => [30, 3, 3, 7, 0, -4]]);

    expect(app(StreakMilestoneService::class)->thresholds())->toBe([3, 7, 30]);
});

test('Z4-02: the seeder creates one reward-free achievement per milestone and is idempotent', function () {
    $thresholds = app(StreakMilestoneService::class)->thresholds();

    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $this->seed(StreakMilestoneAchievementsSeeder::class);

    $rows = Achievement::query()->where('condition_type', AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)->orderBy('target_value')->get();

    expect($rows->pluck('target_value')->map(fn ($v) => (int) $v)->all())->toBe($thresholds)
        ->and($rows->pluck('xp_reward')->unique()->all())->toBe([0])
        ->and($rows->pluck('internal_key')->all())->toBe(array_map(fn ($d) => "streak_{$d}_days", $thresholds));
});

test('Z4-03: reaching a milestone through real official solves unlocks it exactly once and never again on later solves', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 2, 2, now()->subDay()->toDateString());

    e34_solve($u); // اليوم الثالث ⇒ 3 أيام
    expect(e34_unlocked($u, 'streak_3_days'))->toBeTrue()->and(e34_unlocked($u, 'streak_7_days'))->toBeFalse();

    $first = UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->value('unlocked_at');
    e34_solve($u);
    e34_solve($u);

    expect(UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->count())->toBe(1)
        ->and(UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->value('unlocked_at')->equalTo($first))->toBeTrue();
});

test('Z4-04: missing days is never punished - the milestone, longest streak and wallets stay untouched while the display shows the effective streak', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 2, 2, now()->subDay()->toDateString());
    e34_solve($u);
    $xp = XpTransaction::query()->count();
    $gems = GemTransaction::query()->count() + CurrencyTransaction::query()->count();

    Carbon::setTestNow(now()->addDays(9)); // غياب 9 أيام
    $status = app(StreakMilestoneService::class)->statusFor($u);

    expect($status['current'])->toBe(0)->and($status['longest'])->toBe(3)->and($status['reached'])->toBe([3])
        ->and(e34_unlocked($u, 'streak_3_days'))->toBeTrue()
        ->and(PlayerStreak::query()->where('user_id', $u->id)->value('longest_streak'))->toBe(3)
        ->and(XpTransaction::query()->count())->toBe($xp)
        ->and(GemTransaction::query()->count() + CurrencyTransaction::query()->count())->toBe($gems);
    expect(XpTransaction::query()->where('amount', '<', 0)->count())->toBe(0);
});

test('Z4-05: status reports reached milestones and the next milestone not yet earned, with the days still needed on the current streak', function () {
    $u = e34_user();
    e34_streak($u, 5, 8, now()->subDay()->toDateString());

    $s = app(StreakMilestoneService::class)->statusFor($u);
    expect($s)->toMatchArray(['current' => 5, 'longest' => 8, 'reached' => [3, 7], 'next' => 14, 'days_to_next' => 9]);

    $new = e34_user();
    expect(app(StreakMilestoneService::class)->statusFor($new))->toMatchArray(['current' => 0, 'longest' => 0, 'reached' => [], 'next' => 3, 'days_to_next' => 3]);

    $top = e34_user();
    e34_streak($top, 120, 120, now()->toDateString());
    expect(app(StreakMilestoneService::class)->statusFor($top))->toMatchArray(['reached' => [3, 7, 14, 30, 60, 100], 'next' => null, 'days_to_next' => null]);
});

test('Z4-06: the effective streak is the stored value only while the last active day is today or yesterday', function () {
    $mk = fn (int $current, ?string $last) => new PlayerStreak(['current_streak' => $current, 'longest_streak' => 9, 'last_active_date' => $last]);

    expect(StreakMilestoneService::effectiveCurrent($mk(4, now()->toDateString())))->toBe(4)
        ->and(StreakMilestoneService::effectiveCurrent($mk(4, now()->subDay()->toDateString())))->toBe(4)
        ->and(StreakMilestoneService::effectiveCurrent($mk(4, now()->subDays(2)->toDateString())))->toBe(0)
        ->and(StreakMilestoneService::effectiveCurrent($mk(4, null)))->toBe(4)
        ->and(StreakMilestoneService::effectiveCurrent($mk(0, now()->toDateString())))->toBe(0)
        ->and(StreakMilestoneService::effectiveCurrent(null))->toBe(0);
});

test('Z4-07: unlocking a milestone grants no gems, currency or XP (recognition only; any reward is an explicit admin choice on the achievement)', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 6, 6, now()->subDay()->toDateString());
    $currency = GemTransaction::query()->count() + CurrencyTransaction::query()->count();

    e34_solve($u); // 7 أيام ⇒ يفتح 3 و7

    expect(e34_unlocked($u, 'streak_3_days'))->toBeTrue()->and(e34_unlocked($u, 'streak_7_days'))->toBeTrue()
        ->and(XpTransaction::query()->where('type', XpTransaction::TYPE_ACHIEVEMENT_REWARD)->count())->toBe(0)
        ->and(GemTransaction::query()->count() + CurrencyTransaction::query()->count())->toBe($currency);
});

test('Z4-08: the hook is idempotent and does no achievement work for players below the first milestone', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $svc = app(StreakMilestoneService::class);

    $low = e34_user();
    e34_streak($low, 1, 2, now()->toDateString());
    expect(e34_count_queries(fn () => $svc->onStreakUpdated($low)))->toBe(1); // قراءة longest فقط

    $u = e34_user();
    e34_streak($u, 3, 3, now()->toDateString());
    $svc->onStreakUpdated($u);
    $svc->onStreakUpdated($u);
    $svc->onStreakUpdated($u);

    expect(UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->count())->toBe(1);
});

test('Z4-09: the seeder never rewrites a milestone that is already in use', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 3, 3, now()->toDateString());
    app(StreakMilestoneService::class)->onStreakUpdated($u);

    $a = Achievement::query()->where('internal_key', 'streak_3_days')->first();
    $a->forceFill(['name' => 'اسم مخصّص'])->save();

    $this->seed(StreakMilestoneAchievementsSeeder::class);

    expect(Achievement::query()->where('internal_key', 'streak_3_days')->value('name'))->toBe('اسم مخصّص')
        ->and(Achievement::query()->where('condition_type', AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)->count())->toBe(6);
});

test('Z4-10: the evaluator reads the longest streak only (current streak and logins are irrelevant) and is registered with its event group', function () {
    $u = e34_user();
    e34_streak($u, 1, 12, now()->subDays(30)->toDateString());
    $a = new Achievement(['condition_type' => AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS, 'target_value' => 7]);

    $ev = new StreakLongestDaysEvaluator;
    expect($ev->supports($a))->toBeTrue()->and($ev->currentValue($u, $a))->toBe(12)
        ->and($ev->currentValue(e34_user(), $a))->toBe(0)
        ->and(AchievementEvaluatorRegistry::ALL_TYPES)->toContain(AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)
        ->and(AchievementEvaluatorRegistry::EVENT_GROUPS)->toHaveKey('streak_updated');
});
````

## `tests/Feature/LiveOpsE34/_support.php`

````php
<?php

use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionPoolService;
use App\Services\LiveOps\RetentionAnalytics;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E34 (LiveOps) - مساعدات مشتركة. SQLite افتراضيًا وتعمل كما هي على MySQL (phpunit.mysql.xml.dist). لا شبكة ولا حزم.
| كل تهيئة للمجموعات تمرّ بالخدمة الرسمية (MissionPoolService)؛ لا كتابة مباشرة للحالة إلا عند اختبار الحراسة نفسها.
| الكاشات المشتركة تُعطَّل افتراضيًا بالاختبارات (cache_seconds = 0) لحتمية النتائج؛ اختبارات الكاش تفعّلها صراحةً.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';
require_once __DIR__.'/../Notifications/NotificationTestHelpers.php';
require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e34_seed')) {
    function e34_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        config([
            'liveops.pools.cache_seconds' => 0,
            'liveops.calendar.cache_seconds' => 0,
            'liveops.analytics.cache_seconds' => 0,
            'liveops.notifications.daily_cap' => 1,
            'liveops.notifications.mission_expiring' => true,
            'liveops.notifications.event_starting_soon' => true,
            'liveops.notifications.weekly_missions_available' => false,
            'liveops.notifications.comeback_available' => false,
            'liveops.comeback.min_inactive_days' => 7,
            'liveops.limits.action_per_minute' => 1000, // اختبارات حدّ المعدّل تضبطه صراحةً
        ]);
        MissionAssignmentService::flushSnapshot();
        EventCalendarService::flush();
        RetentionAnalytics::flush();
    }

    function e34_admin(): User
    {
        $u = e16User();
        $u->assignRole('administrator');

        return $u;
    }

    function e34_moderator(): User
    {
        $u = e16User();
        $u->assignRole('moderator');

        return $u;
    }

    function e34_manager(): User
    {
        $u = e16User();
        $u->assignRole('content-manager');

        return $u;
    }

    /** لاعب موثَّق بعمر حساب محدد (افتراضيًا 20 يومًا، أي ليس "جديدًا" بقاعدة 14 يومًا). */
    function e34_user(array $attrs = [], int $daysOld = 20): User
    {
        $u = e16User($attrs);
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($daysOld)]);

        return $u->fresh();
    }

    /** مهمة يومية/أسبوعية (حلّ أحجيات) نشطة، عامة ما لم تُضَمّ لمجموعة. */
    function e34_quest(array $over = []): QuestDefinition
    {
        return QuestDefinition::factory()->create($over);
    }

    function e34_weekly(array $over = []): QuestDefinition
    {
        return QuestDefinition::factory()->weekly(3)->create($over);
    }

    /**
     * مجموعة بالخدمة الرسمية. $quests تُضمّ بالترتيب. $activate=false تتركها معطّلة.
     *
     * @param  list<QuestDefinition>  $quests
     */
    function e34_pool(array $quests = [], array $over = [], bool $activate = true, ?User $actor = null): MissionPool
    {
        $actor ??= e34_admin();
        $svc = app(MissionPoolService::class);
        $pool = $svc->create($actor, $over + [
            'name' => 'مجموعة '.fake()->unique()->numerify('####'), 'slug' => 'p'.fake()->unique()->numerify('######'),
            'period_type' => QuestDefinition::PERIOD_DAILY, 'selection_count' => 2,
        ]);

        foreach ($quests as $i => $q) {
            $svc->attach($actor, $pool, $q, $i);
        }

        if ($activate && $quests !== []) {
            $pool = $svc->activate($actor, $pool);
        }

        return $pool->fresh();
    }

    /** n مهام يومية جديدة (target 1). @return list<QuestDefinition> */
    function e34_quests(int $n, array $over = []): array
    {
        return array_map(fn () => e34_quest($over), range(1, $n));
    }

    /** حلّ أحجية رسمية صحيحة عبر المسار الرسمي الكامل (محاولة ⇒ تقدّم ⇒ مهام ⇒ سلسلة ⇒ معالم). */
    function e34_solve(User $user, ?PuzzleCategory $category = null): void
    {
        $puzzle = Puzzle::factory()->create(array_filter([
            'answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0, 'puzzle_category_id' => $category?->id,
        ], fn ($v) => $v !== null)); // الأصفار تُبقى (لا مكافأة افتراضية من المصنع)
        app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    }

    function e34_streak(User $user, int $current, int $longest, ?string $lastActive): PlayerStreak
    {
        return PlayerStreak::query()->updateOrCreate(['user_id' => $user->id], [
            'current_streak' => $current, 'longest_streak' => $longest, 'last_active_date' => $lastActive,
        ]);
    }

    /** المهام الظاهرة للمستخدم بمعرّفاتها (يومية ثم أسبوعية)، عبر مثيل خدمة جديد (لا ذاكرة مؤقتة قديمة). @return list<int> */
    function e34_visible_ids(User $user, string $type = QuestDefinition::PERIOD_DAILY): array
    {
        $quests = app(\App\Services\Engagement\QuestService::class);

        return $quests->visibleQuestsFor($user, $type)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    /** نفس السؤال عبر isVisibleTo (التكافؤ). */
    function e34_visible_via_isVisible(User $user, string $type = QuestDefinition::PERIOD_DAILY): array
    {
        $quests = app(\App\Services\Engagement\QuestService::class);

        return QuestDefinition::query()->where('period_type', $type)->orderBy('id')->get()
            ->filter(fn (QuestDefinition $q) => $quests->isVisibleTo($user, $q))
            ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    /** كود PHP بلا تعليقات (للتدقيق الساكن لا يخدعه نص التوثيق). */
    function e34_code_only(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /** عدد الاستعلامات المنفَّذة داخل $fn. */
    function e34_count_queries(\Closure $fn): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $fn();

        return $n;
    }
}
````


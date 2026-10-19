# E33 — الملفات الجديدة (54 ملفًا) بمحتواها الكامل

كل كتلة أدناه = ملف كامل يُنسخ كما هو إلى المسار المذكور في عنوانها (جذر المشروع). نفس الملفات موجودة في `files/` داخل e33-delivery.zip.

## `app/Console/Commands/ProcessReferrals.php`

`````php
<?php

namespace App\Console\Commands;

use App\Models\UserReferral;
use App\Services\Referrals\ReferralQualificationService;
use App\Services\Referrals\ReferralRewardService;
use Illuminate\Console\Command;

class ProcessReferrals extends Command
{
    protected $signature = 'referrals:process {--chunk=200 : حجم دفعة القراءة}';

    protected $description = 'E33: يستكمل الإحالات المعلّقة المنتظرة لعمر الحساب (له إجراء رسمي مسجَّل) ويعيد تسليم مكافآت جانب فشل تسليمه. آمن للتكرار (Idempotent) ولا يؤهّل إلا ما استوفى كل الشروط.';

    public function handle(ReferralQualificationService $qualification, ReferralRewardService $rewards): int
    {
        if (! (bool) config('referrals.enabled')) {
            $this->info('الإحالات معطّلة (REFERRALS_ENABLED=false).');

            return self::SUCCESS;
        }

        $chunk = max(50, min(1000, (int) $this->option('chunk')));
        $qualified = 0;
        $held = 0;
        $delivered = 0;

        UserReferral::query()->where('status', UserReferral::STATUS_PENDING)->whereNotNull('action_at')->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($qualification, &$qualified, &$held) {
                foreach ($rows as $row) {
                    $after = $qualification->evaluate($row->id);

                    match ($after?->status) {
                        UserReferral::STATUS_QUALIFIED, UserReferral::STATUS_REWARDED => $qualified++,
                        UserReferral::STATUS_PENDING_REVIEW => $held++,
                        default => null,
                    };
                }
            });

        // إعادة تسليم جوانب لم تُسلَّم بعد (فشل سابق/محفظة مجمَّدة زال تجميدها). المفتاح الدلالي في الدفتر يمنع الازدواج.
        UserReferral::query()->where('status', UserReferral::STATUS_QUALIFIED)->where('reward_status', UserReferral::REWARD_PENDING)->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($rewards, &$delivered) {
                foreach ($rows as $row) {
                    $delivered += $rewards->deliver($row->id);
                }
            });

        $this->info("تأهّلت {$qualified} · علّقت للمراجعة {$held} · جوانب مكافآت سُلّمت {$delivered}.");

        return self::SUCCESS;
    }
}
`````

## `app/Console/Commands/ReconcileReferrals.php`

`````php
<?php

namespace App\Console\Commands;

use App\Models\CurrencyTransaction;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralRewardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E33 - مطابقة الإحالات مع دفتر المحفظة. **للقراءة فقط دائمًا** (لا تعديل ولا تسليم ولا خصم): تكشف الانحرافات وتُرجع رمز خروج 1 إن وُجدت.
 * الفحوص: إحالة ذاتية؛ تكرار منطقي لنفس المُحال؛ مؤهَّلة بلا وقت تأهيل؛ حالة/مكافأة غير متسقة؛ جانب مسلَّم بلا قيد دفتر؛ قيد دفتر بلا حالة مناسبة أو بمبلغ مغاير للقطة؛
 * عدم تطابق الحملة؛ رمز يخص غير المحيل. العينات معرّفات داخلية فقط (بلا بريد أو اسم).
 */
class ReconcileReferrals extends Command
{
    protected $signature = 'referrals:reconcile {--json : مخرجات JSON} {--chunk=500 : حجم دفعة القراءة}';

    protected $description = 'E33: يقارن الإحالات بدفتر المحفظة ويكشف الانحراف. قراءة فقط (لا يصلح شيئًا)؛ رمز الخروج 1 عند وجود انحراف.';

    public function handle(): int
    {
        $chunk = max(50, min(2000, (int) $this->option('chunk')));
        $found = [];
        $add = function (string $check, int $id) use (&$found) {
            $found[$check] ??= ['count' => 0, 'sample' => []];
            $found[$check]['count']++;

            if (count($found[$check]['sample']) < 10) {
                $found[$check]['sample'][] = $id;
            }
        };

        UserReferral::query()->whereColumn('referrer_user_id', 'referred_user_id')->orderBy('id')->pluck('id')->each(fn ($id) => $add('self_referral', (int) $id));

        DB::table('user_referrals')->select('referred_user_id', DB::raw('min(id) as id'), DB::raw('count(*) as c'))->groupBy('referred_user_id')->having('c', '>', 1)->get()
            ->each(fn ($r) => $add('duplicate_logical_referral', (int) $r->id));

        UserReferral::query()->whereIn('status', UserReferral::QUALIFIED_STATUSES)->whereNull('qualified_at')->pluck('id')->each(fn ($id) => $add('qualified_without_timestamp', (int) $id));

        UserReferral::query()->where(fn ($q) => $q->where('status', UserReferral::STATUS_REWARDED)->where('reward_status', '!=', UserReferral::REWARD_GRANTED))
            ->orWhere(fn ($q) => $q->where('status', UserReferral::STATUS_QUALIFIED)->where('reward_status', UserReferral::REWARD_NONE))
            ->pluck('id')->each(fn ($id) => $add('status_reward_inconsistent', (int) $id));

        UserReferral::query()->whereNotNull('snap_campaign_id')->whereNotNull('referral_campaign_id')->whereColumn('snap_campaign_id', '!=', 'referral_campaign_id')
            ->pluck('id')->each(fn ($id) => $add('campaign_mismatch', (int) $id));

        UserReferral::query()->where('reward_status', UserReferral::REWARD_PENDING)->whereNull('snap_campaign_id')->pluck('id')->each(fn ($id) => $add('campaign_mismatch', (int) $id));

        DB::table('user_referrals')->join('referral_codes', 'referral_codes.id', '=', 'user_referrals.referral_code_id')
            ->whereColumn('referral_codes.user_id', '!=', 'user_referrals.referrer_user_id')->pluck('user_referrals.id')->each(fn ($id) => $add('code_owner_mismatch', (int) $id));

        // جانب مسلَّم يلزمه قيد دفتر بالمفتاح الدلالي وبالمبلغ نفسه.
        UserReferral::query()->where(fn ($q) => $q->whereNotNull('referrer_granted_at')->orWhereNotNull('referred_granted_at'))->orderBy('id')->chunkById($chunk, function ($rows) use ($add) {
            $keys = [];

            foreach ($rows as $r) {
                foreach (ReferralRewardService::SIDES as $side => $cols) {
                    if ($r->{$cols['at']} !== null) {
                        $keys[ReferralRewardService::ledgerKey($r->id, $side)] = [$r->id, (int) $r->{$cols['gems']}];
                    }
                }
            }

            $ledger = CurrencyTransaction::query()->whereIn('idempotency_key', array_keys($keys))->pluck('amount', 'idempotency_key');

            foreach ($keys as $key => [$id, $gems]) {
                if (! $ledger->has($key)) {
                    $add('rewarded_without_ledger', $id);
                } elseif ((int) $ledger[$key] !== $gems) {
                    $add('ledger_amount_mismatch', $id);
                }
            }
        });

        // قيد دفتر لا تسنده حالة إحالة سليمة.
        CurrencyTransaction::query()->where('idempotency_key', 'like', 'referral-qualified:%')->orderBy('id')->chunkById($chunk, function ($txs) use ($add) {
            foreach ($txs as $tx) {
                [, $id, $side] = array_pad(explode(':', (string) $tx->idempotency_key), 3, null);
                $r = ctype_digit((string) $id) ? UserReferral::query()->find((int) $id) : null;
                $cols = ReferralRewardService::SIDES[$side] ?? null;

                if ($r === null || $cols === null || $r->{$cols['at']} === null) {
                    $add('ledger_without_referral_state', (int) $tx->id);
                }
            }
        });

        ksort($found);
        $ok = $found === [];

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'findings' => $found], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($ok) {
            $this->info('الإحالات مطابقة لدفتر المحفظة (قراءة فقط: لم يُعدَّل شيء).');
        } else {
            foreach ($found as $check => $f) {
                $this->warn(sprintf('%s: %d (عينة: %s)', $check, $f['count'], implode(', ', $f['sample'])));
            }
            $this->line('قراءة فقط: لم يُعدَّل شيء. صحّح المصدر يدويًا ثم أعد التشغيل.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
`````

## `app/Filament/Pages/ReferralAnalyticsPage.php`

`````php
<?php

namespace App\Filament\Pages;

use App\Services\Referrals\ReferralAnalytics;
use Filament\Pages\Page;

/**
 * E33 - تحليلات الاكتساب للمشرفين (referrals.view). مجاميع دفعات (cohort) بلا بيانات خاصة؛ النقرات تقريبية؛ أوائل المحيلين بعدد المؤهَّلة فقط.
 */
class ReferralAnalyticsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'تحليلات الإحالات';

    protected static ?string $title = 'تحليلات الإحالات والاكتساب';

    protected static ?string $slug = 'referral-analytics';

    protected static ?int $navigationSort = 63;

    protected static string $view = 'filament.pages.referral-analytics';

    public int $days = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('referrals.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;
        $a = app(ReferralAnalytics::class);

        return ['overview' => $a->overview($days), 'campaigns' => $a->campaigns($days), 'top' => $a->topReferrers($days), 'days' => $days];
    }
}
`````

## `app/Filament/Resources/ReferralCampaignResource.php`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralCampaignResource\Pages;
use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E33 - حملات الدعوة. الإنشاء/التعديل referrals.manage عبر ReferralCampaignService (تحقق + تدقيق + حد معدّل + تفريغ الكاش). لا حذف: الإنهاء يحفظ التاريخ.
 * المكافآت **معطّلة افتراضيًا** (مفتاح عام + تفعيل على الحملة + مبلغ موجب)؛ تعديلها لا يغيّر إحالات سبق تأهيلها (لقطة). لا أسرار ولا بيانات لاعبين هنا.
 */
class ReferralCampaignResource extends Resource
{
    protected static ?string $model = ReferralCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'حملات الدعوة';

    protected static ?string $modelLabel = 'حملة دعوة';

    protected static ?string $pluralModelLabel = 'حملات الدعوة';

    protected static ?int $navigationSort = 61;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['draft' => 'مسودة', 'active' => 'نشطة', 'paused' => 'موقوفة', 'ended' => 'منتهية'][$s] ?? $s;
    }

    public static function form(Form $form): Form
    {
        $rules = collect((array) config('referrals.qualification.rules'))->mapWithKeys(fn ($n, $k) => [$k => $k.' ('.$n.' حل رسمي)'])->all();
        $max = (int) config('referrals.rewards.max_gems_per_side');
        $abs = (array) config('referrals.rewards.absolute_caps');

        return $form->schema([
            Forms\Components\Section::make('الحملة')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('الاسم')->required()->maxLength(120),
                Forms\Components\TextInput::make('slug')->label('المعرّف (يُستعمل في ?c=)')->required()->maxLength(64)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->helperText('أحرف لاتينية صغيرة وأرقام وشرطات.'),
                Forms\Components\Toggle::make('is_public')->label('تظهر لللاعبين في صفحة الدعوات')->default(true),
                Forms\Components\Select::make('season_id')->label('موسم مرتبط (اختياري)')->searchable()->options(fn () => Season::query()->with('campaign:id,title')->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn ($s) => [$s->id => $s->campaign?->title ?? ('موسم #'.$s->id)])->all()),
                Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ (اختياري)'),
                Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي (اختياري)')->after('starts_at'),
            ]),
            Forms\Components\Section::make('التأهيل')->columns(2)->schema([
                Forms\Components\Select::make('qualification_rule')->label('قاعدة التأهيل')->options($rules)->default('first_official_solve')->required(),
                Forms\Components\TextInput::make('min_account_age_hours')->label('عمر الحساب الأدنى بالساعات (فارغ = الافتراضي)')->numeric()->minValue(0)->maxValue(720),
            ]),
            Forms\Components\Section::make('المكافآت (اختيارية)')->columns(2)->description('تتطلب أيضًا REFERRALS_REWARDS_ENABLED=true. تُسلَّم كجواهر معلّقة عبر المحفظة بسقوف لكل محيل. تعديلها لا يغيّر إحالات مؤهَّلة سابقًا.')->schema([
                Forms\Components\Toggle::make('rewards_enabled')->label('تفعيل مكافآت هذه الحملة')->columnSpanFull(),
                Forms\Components\TextInput::make('referrer_reward_gems')->label('جواهر المحيل')->numeric()->minValue(0)->maxValue($max)->default(0),
                Forms\Components\TextInput::make('referred_reward_gems')->label('جواهر المُحال')->numeric()->minValue(0)->maxValue($max)->default(0),
                Forms\Components\TextInput::make('daily_cap')->label('سقف يومي (إحالات مكافَأة لكل محيل)')->numeric()->minValue(1)->maxValue((int) $abs['daily']),
                Forms\Components\TextInput::make('monthly_cap')->label('سقف شهري')->numeric()->minValue(1)->maxValue((int) $abs['monthly']),
                Forms\Components\TextInput::make('lifetime_cap')->label('سقف مدى الحياة')->numeric()->minValue(1)->maxValue((int) $abs['lifetime']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['referrals', 'referrals as qualified_count' => fn (Builder $w) => $w->whereIn('status', UserReferral::QUALIFIED_STATUSES)]))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الحملة')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('slug')->label('المعرّف')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel((string) $state))
                    ->color(fn ($state) => ['active' => 'success', 'draft' => 'gray', 'paused' => 'warning', 'ended' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\IconColumn::make('default_slot')->label('افتراضية')->boolean()->state(fn (ReferralCampaign $r) => $r->isDefault()),
                Tables\Columns\TextColumn::make('referrals_count')->label('إحالات')->numeric(),
                Tables\Columns\TextColumn::make('qualified_count')->label('مؤهَّلة')->numeric(),
                Tables\Columns\IconColumn::make('rewards_enabled')->label('مكافآت')->boolean(),
                Tables\Columns\TextColumn::make('ends_at')->label('تنتهي')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['draft' => 'مسودة', 'active' => 'نشطة', 'paused' => 'موقوفة', 'ended' => 'منتهية']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (ReferralCampaign $r) => $r->status !== ReferralCampaign::STATUS_ENDED),
                Tables\Actions\ActionGroup::make([
                    self::act('activate', 'تفعيل', 'success', [ReferralCampaign::STATUS_DRAFT], fn (ReferralCampaignService $s, ReferralCampaign $r) => $s->activate(auth()->user(), $r), 'فُعّلت الحملة.'),
                    self::act('pause', 'إيقاف مؤقت', 'warning', [ReferralCampaign::STATUS_ACTIVE], fn (ReferralCampaignService $s, ReferralCampaign $r) => $s->pause(auth()->user(), $r), 'أُوقفت الحملة (لا إسناد ولا مكافآت جديدة).'),
                    self::act('resume', 'استئناف', 'success', [ReferralCampaign::STATUS_PAUSED], fn (ReferralCampaignService $s, ReferralCampaign $r) => $s->resume(auth()->user(), $r), 'استُؤنفت الحملة.'),
                    self::act('make_default', 'جعلها الافتراضية', 'primary', [ReferralCampaign::STATUS_ACTIVE], fn (ReferralCampaignService $s, ReferralCampaign $r) => $s->makeDefault(auth()->user(), $r), 'صارت الحملة الافتراضية.'),
                    self::act('end', 'إنهاء', 'danger', [ReferralCampaign::STATUS_DRAFT, ReferralCampaign::STATUS_ACTIVE, ReferralCampaign::STATUS_PAUSED], fn (ReferralCampaignService $s, ReferralCampaign $r) => $s->end(auth()->user(), $r), 'أُنهيت الحملة (يبقى سجلّها).'),
                ]),
            ]);
    }

    protected static function act(string $name, string $label, string $color, array $from, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (ReferralCampaign $r) => in_array($r->status, $from, true) && (auth()->user()?->can('referrals.manage') ?? false))
            ->action(function (ReferralCampaign $record) use ($call, $ok) {
                try {
                    $call(app(ReferralCampaignService::class), $record);
                    Notification::make()->success()->title($ok)->send();
                } catch (ReferralException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralCampaigns::route('/'),
            'create' => Pages\CreateReferralCampaign::route('/create'),
            'edit' => Pages\EditReferralCampaign::route('/{record}/edit'),
        ];
    }
}
`````

## `app/Filament/Resources/ReferralCampaignResource/Pages/CreateReferralCampaign.php`

`````php
<?php

namespace App\Filament\Resources\ReferralCampaignResource\Pages;

use App\Filament\Resources\ReferralCampaignResource;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateReferralCampaign extends CreateRecord
{
    protected static string $resource = ReferralCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ReferralCampaignService::class)->create(auth()->user(), $data);
        } catch (ReferralException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
`````

## `app/Filament/Resources/ReferralCampaignResource/Pages/EditReferralCampaign.php`

`````php
<?php

namespace App\Filament\Resources\ReferralCampaignResource\Pages;

use App\Filament\Resources\ReferralCampaignResource;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReferralCampaign extends EditRecord
{
    protected static string $resource = ReferralCampaignResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(ReferralCampaignService::class)->update(auth()->user(), $record, $data);
        } catch (ReferralException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
`````

## `app/Filament/Resources/ReferralCampaignResource/Pages/ListReferralCampaigns.php`

`````php
<?php

namespace App\Filament\Resources\ReferralCampaignResource\Pages;

use App\Filament\Resources\ReferralCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReferralCampaigns extends ListRecords
{
    protected static string $resource = ReferralCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
`````

## `app/Filament/Resources/UserReferralResource.php`

`````php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserReferralResource\Pages;
use App\Models\ReferralCampaign;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralReviewService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E33 - مراجعة الإحالات (عرض + إجراءات مراجعة). لا إنشاء ولا تعديل ولا حذف. لا بريد ولا IP ولا بصمة: أسماء وحالات ومفاتيح إشارات مغلقة فقط.
 * الإشارات اقتراحات لا عقوبات: الاعتماد/الاستبعاد قرار بشري بصلاحية referrals.review وسبب منظَّم، وإحالة سُلّمت مكافأتها لا تُخصَم (إقرار صريح مطلوب).
 */
class UserReferralResource extends Resource
{
    protected static ?string $model = UserReferral::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'مراجعة الإحالات';

    protected static ?string $modelLabel = 'إحالة';

    protected static ?string $pluralModelLabel = 'الإحالات';

    protected static ?int $navigationSort = 62;

    public const SIGNAL_LABELS = [
        'referrer_frozen' => 'محيل مجمَّد', 'referrer_high_fraud_flag' => 'علامة احتيال عالية على المحيل', 'referred_high_fraud_flag' => 'علامة احتيال عالية على المُحال',
        'shared_ip' => 'تشارك شبكة', 'shared_device' => 'تشارك جهاز', 'similar_email' => 'بريد متشابه', 'referrer_burst' => 'دفعة دعوات متتابعة',
        'referrer_daily_velocity' => 'تأهيلات كثيرة خلال يوم', 'fast_action' => 'إجراء سريع جدًا', 'referrer_open_flag' => 'علامة مفتوحة على المحيل', 'referred_open_flag' => 'علامة مفتوحة على المُحال',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['pending' => 'معلّقة', 'pending_review' => 'بانتظار المراجعة', 'qualified' => 'مؤهَّلة', 'rewarded' => 'مكافَأة', 'disqualified' => 'مستبعدة'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['referrer:id,name', 'referred:id,name', 'campaign:id,name']))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('referrer.name')->label('المحيل')->placeholder('محذوف'),
                Tables\Columns\TextColumn::make('referred.name')->label('المُحال'),
                Tables\Columns\TextColumn::make('campaign.name')->label('الحملة')->placeholder('بلا حملة'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel((string) $state))
                    ->color(fn ($state) => ['qualified' => 'success', 'rewarded' => 'success', 'pending' => 'gray', 'pending_review' => 'warning', 'disqualified' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('risk_signals')->label('إشارات')->state(fn (UserReferral $r) => collect((array) $r->risk_signals)->map(fn ($k) => self::SIGNAL_LABELS[$k] ?? $k)->implode('، ') ?: '—')->wrap(),
                Tables\Columns\TextColumn::make('reward_status')->label('المكافأة')->formatStateUsing(fn ($state, UserReferral $r) => ['none' => '—', 'pending' => 'قيد التسليم', 'granted' => 'سُلّمت', 'skipped' => 'لا مكافأة'][$state] ?? $state)
                    ->description(fn (UserReferral $r) => $r->reward_skip_reason),
                Tables\Columns\TextColumn::make('snap_referrer_gems')->label('محيل/مُحال')->state(fn (UserReferral $r) => $r->snap_referrer_gems.' / '.$r->snap_referred_gems),
                Tables\Columns\TextColumn::make('created_at')->label('أُسنِدت')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('qualified_at')->label('تأهّلت')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(array_combine(UserReferral::STATUSES, array_map([self::class, 'statusLabel'], UserReferral::STATUSES))),
                Tables\Filters\SelectFilter::make('referral_campaign_id')->label('الحملة')->options(fn () => ReferralCampaign::query()->orderBy('name')->limit(100)->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('reward_status')->label('المكافأة')->options(['none' => '—', 'pending' => 'قيد التسليم', 'granted' => 'سُلّمت', 'skipped' => 'لا مكافأة']),
                Tables\Filters\Filter::make('has_signals')->label('بها إشارات مخاطر')->query(fn (Builder $query) => $query->whereNotNull('risk_signals')),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')->label('اعتماد')->color('success')->icon('heroicon-o-check')
                    ->visible(fn (UserReferral $r) => $r->status === UserReferral::STATUS_PENDING_REVIEW && (auth()->user()?->can('referrals.review') ?? false))
                    ->form([Forms\Components\Textarea::make('note')->label('ملاحظة المراجعة (اختياري)')->maxLength(500)->rows(2)])
                    ->requiresConfirmation()
                    ->action(fn (UserReferral $record, array $data) => self::guard(fn () => app(ReferralReviewService::class)->approve(auth()->user(), $record, $data['note'] ?? null), 'اعتُمدت الإحالة.')),
                Tables\Actions\Action::make('disqualify')->label('استبعاد')->color('danger')->icon('heroicon-o-x-mark')
                    ->visible(fn (UserReferral $r) => $r->status !== UserReferral::STATUS_DISQUALIFIED && (auth()->user()?->can('referrals.review') ?? false))
                    ->form(fn (UserReferral $r) => array_values(array_filter([
                        Forms\Components\Select::make('reason')->label('السبب')->required()->options(UserReferral::DISQUALIFY_REASONS),
                        Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength(500)->rows(2),
                        ($r->referrer_granted_at !== null || $r->referred_granted_at !== null)
                            ? Forms\Components\Checkbox::make('ack')->label('أقرّ بأن الجواهر المسلَّمة لن تُخصم تلقائيًا وتبقى في المحافظ')->accepted()
                            : null,
                    ])))
                    ->action(fn (UserReferral $record, array $data) => self::guard(fn () => app(ReferralReviewService::class)->disqualify(auth()->user(), $record, (string) $data['reason'], $data['note'] ?? null, (bool) ($data['ack'] ?? false)), 'استُبعدت الإحالة.')),
            ]);
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (ReferralException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUserReferrals::route('/')];
    }
}
`````

## `app/Filament/Resources/UserReferralResource/Pages/ListUserReferrals.php`

`````php
<?php

namespace App\Filament\Resources\UserReferralResource\Pages;

use App\Filament\Resources\UserReferralResource;
use Filament\Resources\Pages\ListRecords;

class ListUserReferrals extends ListRecords
{
    protected static string $resource = UserReferralResource::class;
}
`````

## `app/Http/Controllers/Referrals/ReferralController.php`

`````php
<?php

namespace App\Http\Controllers\Referrals;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralAnalytics;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralClickRecorder;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralShareStats;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * E33 - صفحة «دعوة الأصدقاء» الخاصة باللاعب. مجاميع فقط: لا أسماء ولا بريد ولا بيانات عن المُحالين ولا IP.
 * كل ما يُعرض مُستخرَج بمعرّف المستخدم الحالي من الجلسة (لا معرّف من الرابط). لا قائمة صدارة علنية للإحالات.
 */
class ReferralController extends Controller
{
    public function index(Request $request, ReferralCodeService $codes, ReferralAnalytics $analytics, ReferralCampaignService $campaigns, ShareLinkBuilder $shares)
    {
        abort_unless((bool) config('referrals.enabled'), 404);

        $user = $request->user();
        $code = $codes->ensureFor($user);

        return view('referrals.index', [
            'code' => $code->code,
            'link' => route('referrals.landing', $code->code),
            'stats' => $analytics->forReferrer($user),
            'campaigns' => $campaigns->publicLive(),
            'share' => $shares->build('platform', null, 'انضم إلي في أحجيات', 'أحجيات: ألغاز عربية وتحديات ممتعة. انضم عبر دعوتي.', $user),
            'rewardsOn' => (bool) config('referrals.rewards.enabled'),
        ]);
    }

    public function rotate(Request $request, ReferralCodeService $codes): RedirectResponse
    {
        abort_unless((bool) config('referrals.enabled'), 404);

        try {
            $codes->rotate($request->user());
        } catch (ReferralException $e) {
            return redirect()->route('referrals.index')->with('error', $e->getMessage());
        }

        return redirect()->route('referrals.index')->with('status', 'تم تغيير رمز دعوتك. الرمز القديم لم يعد يُسنِد دعوات جديدة.');
    }

    /** عدّاد مشاركة تقريبي (قوائم مغلقة). لا يمنح شيئًا ولا يؤثر على أي حالة؛ 204 دائمًا لمدخل صالح. */
    public function share(Request $request, ReferralShareStats $stats, ReferralClickRecorder $clicks, ReferralCodeService $codes): JsonResponse
    {
        abort_unless((bool) config('referrals.enabled'), 404);

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in((array) config('referrals.share_kinds'))],
            'target' => ['required', 'string', Rule::in((array) config('referrals.share_targets'))],
            'with_ref' => ['sometimes', 'boolean'],
        ]);

        $stats->record($data['kind'], $data['target']);

        if (! empty($data['with_ref']) && ($mine = $codes->activeFor($request->user())) !== null) {
            $clicks->recordShare($mine->id);
        }

        return response()->json([], 204);
    }
}
`````

## `app/Http/Controllers/Referrals/ReferralLandingController.php`

`````php
<?php

namespace App\Http\Controllers\Referrals;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E33 (A) - /r/{code}: يلتقط النية (للضيف فقط) ثم يعيد التوجيه لوجهة **داخلية من قائمة بيضاء** (config attribution.landings).
 * لا open redirect: ?to= مفتاح من قائمة مغلقة (لا URL)، وغير المعروف يسقط للتسجيل. الرمز غير الصالح يتصرف مثل الصالح تمامًا من ناحية الاستجابة (لا تسريب).
 * المصادَق لا يُسنَد إليه شيء ويُوجَّه للرئيسية.
 */
class ReferralLandingController extends Controller
{
    public function __invoke(Request $request, string $code, ReferralIntent $intent): RedirectResponse
    {
        if (! (bool) config('referrals.enabled')) {
            return redirect()->route('home');
        }

        if ($request->user() !== null) {
            return redirect()->route('home');
        }

        $intent->capture($request, $code, $request->query('c'), 'link');

        $landings = (array) config('referrals.attribution.landings');
        $to = $request->query('to');
        $key = is_string($to) && isset($landings[$to]) ? $to : (string) config('referrals.attribution.default_landing');

        return redirect()->route($landings[$key] ?? 'register');
    }
}
`````

## `app/Http/Middleware/CaptureReferralIntent.php`

`````php
<?php

namespace App\Http\Middleware;

use App\Services\Referrals\ReferralIntent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * E33 (C) - يلتقط ?ref=CODE (و?c=slug) من روابط المشاركة العامة لزائر **ضيف** فقط، على طلبات GET لصفحات HTML.
 * بلا أي أثر عند غياب ?ref (فحص مفتاح واحد في الطلب). محدود المعدّل بنفس مفتاح /r/{code} (referral-redirect) ويتجاهل الزيادة بصمت (لا خطأ للزائر).
 * لا يغيّر الاستجابة ولا يعيد التوجيه؛ الرمز غير الصالح لا يُكشف (لا رسالة). الخادم هو الحَكَم: لا يؤثر الرمز على المحتوى المعروض.
 */
class CaptureReferralIntent
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->query->has('ref') && (bool) config('referrals.enabled') && $request->user() === null && ! $request->expectsJson()) {
            $key = 'referral-redirect:'.$request->ip();
            $max = max(1, (int) config('referrals.limits.redirect_per_minute'));

            if (! RateLimiter::tooManyAttempts($key, $max)) {
                RateLimiter::hit($key, 60);
                app(ReferralIntent::class)->capture($request, $request->query('ref'), $request->query('c'), 'share_param');
            }
        }

        return $next($request);
    }
}
`````

## `app/Models/ReferralCampaign.php`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E33 - حملة دعوة (إعدادات فقط). كل تغيير حالة/مكافآت يمر بـReferralCampaignService (تدقيق + حد معدّل + تفريغ الكاش).
 * الحملة لا تغيّر إحالة سبق تأهيلها: لقطة المكافأة تُنسخ إلى user_referrals عند التأهيل.
 */
class ReferralCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ENDED];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'rewards_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'default_slot' => 'integer',
            'min_account_age_hours' => 'integer',
            'referrer_reward_gems' => 'integer',
            'referred_reward_gems' => 'integer',
            'daily_cap' => 'integer',
            'monthly_cap' => 'integer',
            'lifetime_cap' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(UserReferral::class);
    }

    public function isDefault(): bool
    {
        return (int) $this->default_slot === 1;
    }

    /** نشطة فعليًا الآن: الحالة active وضمن نافذتها الزمنية. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** هل تمنح مكافآت؟ يتطلب المفتاح العام + تفعيل الحملة + مبلغًا موجبًا لجانب واحد على الأقل. */
    public function rewardsActive(): bool
    {
        return (bool) config('referrals.rewards.enabled')
            && $this->rewards_enabled
            && ((int) $this->referrer_reward_gems > 0 || (int) $this->referred_reward_gems > 0);
    }

    /** @return array{daily: int, monthly: int, lifetime: int} */
    public function effectiveCaps(): array
    {
        $base = (array) config('referrals.rewards.caps');
        $abs = (array) config('referrals.rewards.absolute_caps');

        return [
            'daily' => min((int) ($this->daily_cap ?? $base['daily']), (int) $abs['daily']),
            'monthly' => min((int) ($this->monthly_cap ?? $base['monthly']), (int) $abs['monthly']),
            'lifetime' => min((int) ($this->lifetime_cap ?? $base['lifetime']), (int) $abs['lifetime']),
        ];
    }

    public function effectiveMinAgeHours(): int
    {
        return max(0, min(720, (int) ($this->min_account_age_hours ?? config('referrals.qualification.min_account_age_hours'))));
    }
}
`````

## `app/Models/ReferralCode.php`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E33 - رمز إحالة شخصي. يولّده الخادم حصرًا (ReferralCodeService)، فريد ومستقر. التدوير يعطّل القديم (active_for_user_id = NULL) ولا يحذفه.
 * لا تعيين جماعي؛ العدّادات تقريبية (clicks_count نقرات فريدة بالساعة) ولا تُستعمل لأي مكافأة.
 */
class ReferralCode extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['clicks_count' => 'integer', 'shares_count' => 'integer', 'rotated_at' => 'datetime', 'active_for_user_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(UserReferral::class);
    }

    public function isActive(): bool
    {
        return $this->active_for_user_id !== null && (int) $this->active_for_user_id === (int) $this->user_id;
    }
}
`````

## `app/Models/UserReferral.php`

`````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E33 - إحالة واحدة لكل حساب مُحال (UNIQUE referred_user_id). المحيل لا يتغيّر بعد الإنشاء (لا مسار تحديث له بأي خدمة).
 * التسجيل وحده ≠ إحالة مؤهَّلة: الحالة تبدأ pending وتنتقل فقط عبر ReferralQualificationService / ReferralReviewService.
 * لا تعيين جماعي (guarded) ولا بيانات خام (IP/UA) في أي عمود.
 */
class UserReferral extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_REWARDED = 'rewarded';

    public const STATUS_DISQUALIFIED = 'disqualified';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PENDING_REVIEW, self::STATUS_QUALIFIED, self::STATUS_REWARDED, self::STATUS_DISQUALIFIED];

    /** الحالات التي تُعدّ «مؤهَّلة» في التحليلات (تعليق المراجعة ليس مؤهَّلًا حتى يُعتمد). */
    public const QUALIFIED_STATUSES = [self::STATUS_QUALIFIED, self::STATUS_REWARDED];

    public const SOURCE_LINK = 'link';

    public const SOURCE_SHARE_PARAM = 'share_param';

    public const REWARD_NONE = 'none';

    public const REWARD_PENDING = 'pending';

    public const REWARD_GRANTED = 'granted';

    public const REWARD_SKIPPED = 'skipped';

    /** أسباب الاستبعاد (قائمة مغلقة؛ لا نص حر من العميل). */
    public const DISQUALIFY_REASONS = [
        'self_referral' => 'إحالة ذاتية',
        'fake_accounts' => 'حسابات وهمية متعددة',
        'automation' => 'نشاط آلي',
        'policy_violation' => 'مخالفة السياسة',
        'duplicate_identity' => 'هوية مكررة',
        'other' => 'سبب آخر',
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'risk_signals' => 'array',
            'action_at' => 'datetime',
            'qualified_at' => 'datetime',
            'held_at' => 'datetime',
            'reward_reserved_at' => 'datetime',
            'referrer_granted_at' => 'datetime',
            'referred_granted_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'disqualified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'snap_referrer_gems' => 'integer',
            'snap_referred_gems' => 'integer',
            'snap_campaign_id' => 'integer',
            'min_account_age_hours' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ReferralCampaign::class, 'referral_campaign_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeQualified(Builder $q): Builder
    {
        return $q->whereIn('status', self::QUALIFIED_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PENDING_REVIEW], true);
    }
}
`````

## `app/Policies/ReferralCampaignPolicy.php`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/** E33 - حملات الدعوة: العرض referrals.view، الإنشاء/التعديل referrals.manage، بلا حذف (الإنهاء بالخدمة يحفظ التاريخ). */
class ReferralCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('referrals.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('referrals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('referrals.manage');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('referrals.manage');
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
`````

## `app/Policies/UserReferralPolicy.php`

`````php
<?php

namespace App\Policies;

use App\Models\User;

/** E33 - الإحالات: عرض فقط referrals.view. لا إنشاء ولا تعديل ولا حذف من الواجهة؛ الاعتماد/الاستبعاد إجراءات خدمة بصلاحية referrals.review. */
class UserReferralPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('referrals.view');
    }

    public function view(User $user, $model = null): bool
    {
        return $user->can('referrals.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model = null): bool
    {
        return false;
    }

    public function delete(User $user, $model = null): bool
    {
        return false;
    }
}
`````

## `app/Services/Referrals/ReferralAnalytics.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\ReferralCode;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E33 (E) - تحليلات الاكتساب. **صدق لا دقة وهمية**:
 *  - كل المقاييس «دفعة» (cohort): إحالات أُسنِدت خلال الفترة، ثم ما آلت إليه. فنسبة التحويل = مؤهَّلة ÷ مُسنَدة لنفس الدفعة (لا تقسم أعدادًا من فترات مختلفة).
 *  - النقرات **تقريبية** (فريدة بالساعة، عدّاد تراكمي على الرموز لا يتجزأ بالفترة) ولا تدخل في أي نسبة تحويل؛ المشاركات من عدّادات كاش قصيرة العمر.
 *  - لا بيانات خاصة: لا بريد ولا IP؛ المحيلون الأوائل يُرتَّبون بعدد **المؤهَّلة** فقط (لا بالنقرات ولا بالتسجيلات ولا بالمبالغ) وللمشرفين فقط.
 *  - تجميعات بسيطة بفهارس (جدول مقروء بلا حلقات N+1)، مخزَّنة بالكاش قصير الأمد؛ تحليلات اللاعب الشخصية محصورة بمستخدمها.
 */
class ReferralAnalytics
{
    /**
     * مجاميع الحالات كنص SQL **ثابت** (لا استيفاء متغيرات في SQL خام: يطابق تدقيق E26 W5). يطابقه اختبار AF10-64 مع UserReferral::STATUSES.
     */
    public const STATUS_SUMS = "sum(case when status = 'pending' then 1 else 0 end) as pending, sum(case when status = 'pending_review' then 1 else 0 end) as pending_review, sum(case when status = 'qualified' then 1 else 0 end) as qualified, sum(case when status = 'rewarded' then 1 else 0 end) as rewarded, sum(case when status = 'disqualified' then 1 else 0 end) as disqualified";

    public function __construct(protected ReferralShareStats $shares) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("referrals:analytics:overview:{$days}", max(10, (int) config('referrals.analytics.cache_seconds')), function () use ($days) {
            $from = now()->subDays($days)->startOfDay();
            $q = fn () => UserReferral::query()->where('created_at', '>=', $from);

            $totals = $q()->selectRaw(self::STATUS_SUMS.', count(*) as attributed, '
                .'sum(case when referrer_granted_at is not null then snap_referrer_gems else 0 end) as referrer_gems, '
                .'sum(case when referred_granted_at is not null then snap_referred_gems else 0 end) as referred_gems')->first();

            $verified = (int) DB::table('user_referrals')->join('users', 'users.id', '=', 'user_referrals.referred_user_id')
                ->where('user_referrals.created_at', '>=', $from)->whereNotNull('users.email_verified_at')->count();

            $attributed = (int) ($totals->attributed ?? 0);
            $qualified = (int) ($totals->qualified ?? 0) + (int) ($totals->rewarded ?? 0);

            return [
                'days' => $days,
                'attributed' => $attributed,
                'verified' => $verified,
                'qualified' => $qualified,
                'pending' => (int) ($totals->pending ?? 0),
                'under_review' => (int) ($totals->pending_review ?? 0),
                'disqualified' => (int) ($totals->disqualified ?? 0),
                'rewarded' => (int) ($totals->rewarded ?? 0),
                'reward_cost_gems' => (int) ($totals->referrer_gems ?? 0) + (int) ($totals->referred_gems ?? 0),
                'conversion_pct' => $attributed > 0 ? round($qualified / $attributed * 100, 1) : null,
                'clicks_approx_lifetime' => (int) ReferralCode::query()->sum('clicks_count'),
                'shares_7d' => $this->shares->lastDays(7),
            ];
        });
    }

    /**
     * أداء الحملات (للدفعة نفسها). صف «بلا حملة» للإحالات التي لا حملة لها.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("referrals:analytics:campaigns:{$days}", max(10, (int) config('referrals.analytics.cache_seconds')), function () use ($days) {
            $rows = UserReferral::query()->where('created_at', '>=', now()->subDays($days)->startOfDay())
                ->selectRaw('referral_campaign_id, count(*) as attributed, '.self::STATUS_SUMS.', '
                    .'sum(case when referrer_granted_at is not null then snap_referrer_gems else 0 end) + sum(case when referred_granted_at is not null then snap_referred_gems else 0 end) as cost')
                ->groupBy('referral_campaign_id')->get();

            $names = ReferralCampaign::query()->whereIn('id', $rows->pluck('referral_campaign_id')->filter()->all())->pluck('name', 'id');

            return $rows->map(fn ($r) => [
                'campaign_id' => $r->referral_campaign_id !== null ? (int) $r->referral_campaign_id : null,
                'name' => $r->referral_campaign_id === null ? 'بلا حملة' : (string) ($names[$r->referral_campaign_id] ?? 'حملة محذوفة'),
                'attributed' => (int) $r->attributed,
                'qualified' => (int) $r->qualified + (int) $r->rewarded,
                'rewarded' => (int) $r->rewarded,
                'cost_gems' => (int) $r->cost,
            ])->sortByDesc('qualified')->values()->all();
        });
    }

    /**
     * أوائل المحيلين: بعدد المؤهَّلة (qualified/rewarded) فقط. للمشرفين؛ لا قائمة صدارة علنية.
     *
     * @return list<array{user_id: int, name: string, qualified: int}>
     */
    public function topReferrers(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("referrals:analytics:top:{$days}", max(10, (int) config('referrals.analytics.cache_seconds')), function () use ($days) {
            $rows = UserReferral::query()->whereIn('status', UserReferral::QUALIFIED_STATUSES)->whereNotNull('referrer_user_id')
                ->where('qualified_at', '>=', now()->subDays($days)->startOfDay())
                ->selectRaw('referrer_user_id, count(*) as qualified')->groupBy('referrer_user_id')
                ->orderByDesc('qualified')->orderBy('referrer_user_id')->limit(max(1, (int) config('referrals.analytics.top_referrers')))->get();

            $names = User::query()->whereIn('id', $rows->pluck('referrer_user_id')->all())->pluck('name', 'id');

            return $rows->map(fn ($r) => ['user_id' => (int) $r->referrer_user_id, 'name' => (string) ($names[$r->referrer_user_id] ?? '—'), 'qualified' => (int) $r->qualified])->all();
        });
    }

    /**
     * أرقام اللاعب الشخصية (مجاميع فقط، بلا أي بيانات عن المُحالين).
     *
     * @return array<string, int>
     */
    public function forReferrer(User $user): array
    {
        $t = UserReferral::query()->where('referrer_user_id', $user->id)
            ->selectRaw('count(*) as total, '.self::STATUS_SUMS.', sum(case when referrer_granted_at is not null then snap_referrer_gems else 0 end) as gems')
            ->first();

        $code = ReferralCode::query()->where('active_for_user_id', $user->id)->first(['clicks_count', 'shares_count']);

        return [
            'total' => (int) ($t->total ?? 0),
            'pending' => (int) ($t->pending ?? 0) + (int) ($t->pending_review ?? 0),
            'qualified' => (int) ($t->qualified ?? 0) + (int) ($t->rewarded ?? 0),
            'rewarded' => (int) ($t->rewarded ?? 0),
            'gems_earned' => (int) ($t->gems ?? 0),
            'clicks_approx' => (int) ($code->clicks_count ?? 0),
            'shares' => (int) ($code->shares_count ?? 0),
        ];
    }

    public function forget(): void
    {
        foreach ([7, 30, 90, 365] as $d) {
            foreach (['overview', 'campaigns', 'top'] as $k) {
                Cache::forget("referrals:analytics:{$k}:{$d}");
            }
        }
    }
}
`````

## `app/Services/Referrals/ReferralAttributionService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * E33 (A) - إسناد الحساب الجديد إلى محيل. يحدث **عند إنشاء الحساب فقط** وبقرار الخادم:
 *  - المصدر الوحيد هو «النية» المخزّنة بالخادم (جلسة/كوكي مشفّر)؛ لا حقل من نموذج التسجيل ولا ترويسة تحدد المحيل.
 *  - إحالة واحدة لكل حساب (UNIQUE referred_user_id) والمحيل لا يتغيّر لاحقًا (لا مسار تحديث): استدعاء ثانٍ يعيد الإحالة القائمة كما هي.
 *  - الإحالة الذاتية مرفوضة، ومحيل مجمَّد/غير موثّق/محذوف لا يُسنَد إليه.
 *  - الفشل لا يكسر التسجيل أبدًا (يُسجَّل ويُتجاهل) ولا يكشف شيئًا للزائر (لا رسالة «رمز غير صالح»: لا تسريب لوجود الرموز/الحسابات، وسلوك E26 للتسجيل لم يتغير).
 *  - الحالة تبدأ pending دائمًا: التسجيل وحده ليس إحالة مؤهَّلة.
 */
class ReferralAttributionService
{
    public function __construct(
        protected ReferralCodeService $codes,
        protected ReferralCampaignService $campaigns,
        protected ReferralIntent $intent,
    ) {}

    /** يُستدعى من تسجيل الحساب. لا يرمي أبدًا. */
    public function attributeFromRequest(User $newUser, Request $request): ?UserReferral
    {
        try {
            // الإسناد عبر الطلب لحساب أُنشئ في هذا الطلب نفسه فقط (حساب قديم لا يُسنَد أبدًا).
            if (! $newUser->wasRecentlyCreated) {
                return null;
            }

            $intent = $this->intent->current($request);

            if ($intent === null) {
                return null;
            }

            $referral = $this->attribute($newUser, $intent['code'], $intent['c'], $intent['s']);
            $this->intent->forget($request); // استُهلكت النية: لا إعادة استعمال ولا نية معلّقة

            return $referral;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function attribute(User $referred, string $code, ?string $campaignSlug = null, string $source = UserReferral::SOURCE_LINK): ?UserReferral
    {
        if (! (bool) config('referrals.enabled')) {
            return null;
        }

        $existing = UserReferral::query()->where('referred_user_id', $referred->id)->first();

        if ($existing !== null) {
            return $existing; // محيل واحد؛ لا يُستبدل أبدًا
        }

        $hit = $this->codes->findActive($code);

        if ($hit === null || (int) $hit['user_id'] === (int) $referred->id) {
            return null; // رمز غير صالح، أو إحالة ذاتية
        }

        $referrer = User::query()->find($hit['user_id']);

        if ($referrer === null || $referrer->is_frozen || $referrer->email_verified_at === null) {
            return null;
        }

        $campaign = $this->campaigns->resolve($campaignSlug);
        $source = in_array($source, [UserReferral::SOURCE_LINK, UserReferral::SOURCE_SHARE_PARAM], true) ? $source : UserReferral::SOURCE_LINK;

        try {
            return UserReferral::query()->forceCreate([
                'referred_user_id' => $referred->id,
                'referrer_user_id' => $referrer->id,
                'referral_code_id' => $hit['id'],
                'referral_campaign_id' => $campaign?->id,
                'source' => $source,
                'status' => UserReferral::STATUS_PENDING,
                'qualification_rule' => $this->ruleFor($campaign),
                'min_account_age_hours' => $campaign?->effectiveMinAgeHours(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return UserReferral::query()->where('referred_user_id', $referred->id)->first(); // سباق: الأول يربح
        }
    }

    protected function ruleFor(?ReferralCampaign $campaign): string
    {
        $rules = (array) config('referrals.qualification.rules');
        $rule = $campaign?->qualification_rule;

        return is_string($rule) && isset($rules[$rule]) ? $rule : (string) config('referrals.qualification.default_rule');
    }
}
`````

## `app/Services/Referrals/ReferralCampaignService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E33 (B) - حملات الدعوة.
 *
 *  - القراءة العامة (حل ?c=slug أو الحملة الافتراضية) من كاش صفوف خفيفة بلا استعلام لكل طلب؛ كل إجراء إداري يفرّغ الكاش. الحيّة تُقيَّم بالذاكرة وقت الاستعمال فلا تتأخر حدود النافذة الزمنية.
 *  - slug غير صالح/منتهٍ/غير موجود **لا يكسر الإحالة**: يسقط إلى الحملة الافتراضية الحيّة أو إلى لا حملة (إعدادات config).
 *  - كل كتابة إدارية: صلاحية referrals.manage + حد معدّل referral-campaign-action لكل مشرف + تدقيق + تفريغ الكاش + معاملة. حملة افتراضية واحدة بـUNIQUE(default_slot).
 *  - تعديل المكافآت لا يغيّر إحالة سبق تأهيلها (لقطة user_referrals).
 */
class ReferralCampaignService
{
    public const CACHE_KEY = 'referrals:campaigns:rows';

    public function __construct(protected OperationalAuditService $audit) {}

    /** @return list<array{id: int, slug: string, status: string, default: bool, starts_at: ?string, ends_at: ?string, public: bool, season_id: ?int}> */
    protected function rows(): array
    {
        return Cache::remember(self::CACHE_KEY, max(30, (int) config('referrals.analytics.campaign_cache_seconds')), function () {
            return ReferralCampaign::query()
                ->where('status', ReferralCampaign::STATUS_ACTIVE)
                ->get(['id', 'slug', 'status', 'default_slot', 'starts_at', 'ends_at', 'is_public', 'season_id'])
                ->map(fn (ReferralCampaign $c) => [
                    'id' => (int) $c->id, 'slug' => (string) $c->slug, 'status' => (string) $c->status, 'default' => $c->isDefault(),
                    'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(), 'public' => (bool) $c->is_public,
                    'season_id' => $c->season_id !== null ? (int) $c->season_id : null,
                ])->all();
        });
    }

    protected function rowIsLive(array $row): bool
    {
        $now = now();

        return $row['status'] === ReferralCampaign::STATUS_ACTIVE
            && ($row['starts_at'] === null || \Illuminate\Support\Carbon::parse($row['starts_at'])->lte($now))
            && ($row['ends_at'] === null || \Illuminate\Support\Carbon::parse($row['ends_at'])->gt($now));
    }

    /** يحلّ slug الحملة الاختياري إلى حملة حيّة، وإلا الافتراضية الحيّة، وإلا null. لا يرمي أبدًا. */
    public function resolve(?string $slug): ?ReferralCampaign
    {
        $live = array_values(array_filter($this->rows(), fn (array $r) => $this->rowIsLive($r)));
        $slug = is_string($slug) ? strtolower(trim($slug)) : null;

        $pick = null;

        if ($slug !== null && $slug !== '') {
            $pick = collect($live)->firstWhere('slug', $slug);
        }

        $pick ??= collect($live)->firstWhere('default', true);

        return $pick === null ? null : ReferralCampaign::query()->find($pick['id']);
    }

    /** slug حملة حيّة فقط (للتخزين في النية)؛ غير الصالح يصير null بصمت. */
    public function validSlug(mixed $slug): ?string
    {
        if (! is_string($slug) || $slug === '' || strlen($slug) > 64) {
            return null;
        }

        $slug = strtolower(trim($slug));
        $hit = collect($this->rows())->first(fn (array $r) => $r['slug'] === $slug && $this->rowIsLive($r));

        return $hit === null ? null : $slug;
    }

    /** slug حملة حيّة علنية مرتبطة بموسم (لروابط مشاركة الموسم)، وإلا null. */
    public function slugForSeason(int $seasonId): ?string
    {
        $hit = collect($this->rows())->first(fn (array $r) => $r['season_id'] === $seasonId && $r['public'] && $this->rowIsLive($r));

        return $hit['slug'] ?? null;
    }

    /** حملات حيّة وعلنية للعرض في صفحة /referrals (بلا استعلام إضافي لكل طلب). */
    public function publicLive(): \Illuminate\Support\Collection
    {
        $ids = collect($this->rows())->filter(fn (array $r) => $r['public'] && $this->rowIsLive($r))->pluck('id')->all();

        return $ids === [] ? collect() : ReferralCampaign::query()->whereIn('id', $ids)->orderByDesc('default_slot')->orderBy('name')->get();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ---- إجراءات إدارية ----

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ReferralCampaign
    {
        $this->authorize($actor);
        $clean = $this->validated($data, null);

        $campaign = DB::transaction(function () use ($clean, $actor) {
            $c = new ReferralCampaign;
            $c->forceFill($clean + ['status' => ReferralCampaign::STATUS_DRAFT, 'created_by' => $actor->id])->save();

            return $c;
        });

        $this->audit->log('referral_campaign_created', $campaign, ['slug' => $campaign->slug], $actor);
        $this->forget();

        return $campaign;
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, ReferralCampaign $campaign, array $data): ReferralCampaign
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $campaign, $data) {
            $locked = ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ReferralCampaign::STATUS_ENDED) {
                throw new ReferralException('campaign_ended', 'لا يمكن تعديل حملة منتهية.');
            }

            $clean = $this->validated($data + $locked->only(['name', 'slug']), $locked);
            $before = $locked->only(array_keys($clean));
            $locked->forceFill($clean)->save();

            $this->audit->log('referral_campaign_updated', $locked, ['before' => $before, 'after' => $locked->only(array_keys($clean))], $actor);
            $this->forget();

            return $locked;
        });
    }

    public function activate(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_DRAFT], ReferralCampaign::STATUS_ACTIVE, 'referral_campaign_activated');
    }

    public function pause(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_ACTIVE], ReferralCampaign::STATUS_PAUSED, 'referral_campaign_paused', clearDefault: true);
    }

    public function resume(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_PAUSED], ReferralCampaign::STATUS_ACTIVE, 'referral_campaign_resumed');
    }

    public function end(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        return $this->transition($actor, $campaign, [ReferralCampaign::STATUS_DRAFT, ReferralCampaign::STATUS_ACTIVE, ReferralCampaign::STATUS_PAUSED], ReferralCampaign::STATUS_ENDED, 'referral_campaign_ended', clearDefault: true);
    }

    /** يجعل حملة نشطة هي الافتراضية الوحيدة (يزيح السابقة بنفس المعاملة). */
    public function makeDefault(User $actor, ReferralCampaign $campaign): ReferralCampaign
    {
        $this->authorize($actor);

        try {
            return DB::transaction(function () use ($actor, $campaign) {
                $locked = ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== ReferralCampaign::STATUS_ACTIVE) {
                    throw new ReferralException('campaign_not_active', 'الحملة الافتراضية يجب أن تكون نشطة.');
                }

                ReferralCampaign::query()->where('default_slot', 1)->where('id', '!=', $locked->id)->update(['default_slot' => null]);
                $locked->forceFill(['default_slot' => 1])->save();
                $this->audit->log('referral_campaign_default_set', $locked, ['slug' => $locked->slug], $actor);
                $this->forget();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReferralException('default_conflict', 'تعارض مع تغيير آخر للحملة الافتراضية. أعد المحاولة.');
        }
    }

    /** @param list<string> $from */
    protected function transition(User $actor, ReferralCampaign $campaign, array $from, string $to, string $auditAction, bool $clearDefault = false): ReferralCampaign
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $campaign, $from, $to, $auditAction, $clearDefault) {
            $locked = ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new ReferralException('invalid_transition', 'لا يمكن تنفيذ هذا الإجراء على حملة بحالتها الحالية.');
            }

            $attrs = ['status' => $to];

            if ($to === ReferralCampaign::STATUS_ENDED) {
                $attrs['ended_at'] = now();
            }

            if ($clearDefault) {
                $attrs['default_slot'] = null;
            }

            $locked->forceFill($attrs)->save();
            $this->audit->log($auditAction, $locked, ['slug' => $locked->slug], $actor);
            $this->forget();

            return $locked;
        });
    }

    protected function authorize(User $actor): void
    {
        if (! $actor->can('referrals.manage')) {
            throw new ReferralException('forbidden', 'لا تملك صلاحية إدارة حملات الدعوة.');
        }

        $key = 'referral-campaign-action:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('referrals.limits.campaign_action_per_minute'))) {
            throw new ReferralException('rate_limited', 'عدد كبير من إجراءات الحملات خلال دقيقة. انتظر قليلًا.');
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, ?ReferralCampaign $existing): array
    {
        $rules = array_keys((array) config('referrals.qualification.rules'));
        $maxGems = (int) config('referrals.rewards.max_gems_per_side');
        $abs = (array) config('referrals.rewards.absolute_caps');

        $v = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('referral_campaigns', 'slug')->ignore($existing?->id)],
            'is_public' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...(! empty($data['starts_at']) ? ['after:starts_at'] : [])],
            'season_id' => ['nullable', 'integer', Rule::exists(Season::class, 'id')],
            'qualification_rule' => ['sometimes', Rule::in($rules)],
            'min_account_age_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'rewards_enabled' => ['sometimes', 'boolean'],
            'referrer_reward_gems' => ['sometimes', 'integer', 'min:0', 'max:'.$maxGems],
            'referred_reward_gems' => ['sometimes', 'integer', 'min:0', 'max:'.$maxGems],
            'daily_cap' => ['nullable', 'integer', 'min:1', 'max:'.$abs['daily']],
            'monthly_cap' => ['nullable', 'integer', 'min:1', 'max:'.$abs['monthly']],
            'lifetime_cap' => ['nullable', 'integer', 'min:1', 'max:'.$abs['lifetime']],
        ], [], ['name' => 'الاسم', 'slug' => 'المعرّف']);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $clean = $v->validated();
        $clean['slug'] = strtolower((string) $clean['slug']);

        return $clean;
    }
}
`````

## `app/Services/Referrals/ReferralClickRecorder.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * E33 (E) - عدّاد نقرات **تقريبي** على الرمز. لا يخزَّن IP ولا User-Agent ولا بصمة: مفتاح إلغاء التكرار هو بصمة SHA-256 (مملَّحة بـAPP_KEY)
 * تعيش في الكاش ساعة واحدة فقط ثم تزول، فالنقرة الفريدة بالساعة تُحتسب مرة. لا مكافأة ولا تأهيل يعتمدان على هذا العدّاد أبدًا.
 */
class ReferralClickRecorder
{
    public function record(int $codeId, Request $request): bool
    {
        $fingerprint = hash_hmac('sha256', $request->ip().'|'.substr((string) $request->userAgent(), 0, 200), (string) config('app.key'));
        $key = 'referrals:click:'.$codeId.':'.substr($fingerprint, 0, 32);

        if (! Cache::add($key, 1, max(60, (int) config('referrals.analytics.click_dedupe_seconds')))) {
            return false;
        }

        ReferralCode::query()->whereKey($codeId)->increment('clicks_count');

        return true;
    }

    public function recordShare(int $codeId): void
    {
        ReferralCode::query()->whereKey($codeId)->increment('shares_count');
    }
}
`````

## `app/Services/Referrals/ReferralCodeService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E33 (A) - رموز الإحالة الشخصية.
 *
 *  - يولّدها الخادم فقط من random_int (آمن تشفيريًا) بأبجدية بلا حروف علة ولا محارف ملتبسة، بحروف كبيرة؛ التصادم يعالجه فهرس UNIQUE ثم إعادة محاولة محدودة.
 *  - رمز نشط واحد لكل مستخدم يضمنه UNIQUE(active_for_user_id) (حارس القاعدة الأخير ضد سباق إنشاء رمزين).
 *  - التدوير يعطّل القديم (active_for_user_id = NULL) ويُنشئ جديدًا؛ القديم لا يُسنِد إحالات جديدة بعد الآن، والإحالات القديمة تبقى كما هي.
 *  - البحث بالرمز مخزَّن بالكاش (نتيجة إيجابية 5 دقائق، سلبية دقيقة) ويُفرَّغ عند التدوير؛ يتحقق دائمًا من الصيغة قبل لمس القاعدة.
 */
class ReferralCodeService
{
    public function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $code = strtoupper(trim($raw));
        $length = (int) config('referrals.code.length');

        if (strlen($code) !== $length) {
            return null;
        }

        $alphabet = (string) config('referrals.code.alphabet');

        return strspn($code, $alphabet) === $length ? $code : null;
    }

    public function activeFor(User $user): ?ReferralCode
    {
        return ReferralCode::query()->where('active_for_user_id', $user->id)->first();
    }

    public function ensureFor(User $user): ReferralCode
    {
        return $this->activeFor($user) ?? $this->createFor($user);
    }

    /** نص رمز المستخدم النشط (مخزَّن 5 دقائق) أو null؛ للقراءة فقط (لا إنشاء) فتُستعمل بأمان في صفحات عامة. */
    public function myCode(User $user): ?string
    {
        $hit = Cache::remember('referrals:mycode:'.$user->id, 300, fn () => $this->activeFor($user)?->code ?? '');

        return $hit === '' ? null : (string) $hit;
    }

    /**
     * يدوّر الرمز: القديم يتعطل فورًا. حدّ يومي صغير يمنع إغراق الجدول. يُسلسَل لكل مستخدم بقفل صف المستخدم.
     */
    public function rotate(User $user): ReferralCode
    {
        $old = null;

        $new = DB::transaction(function () use ($user, &$old) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $recent = ReferralCode::query()->where('user_id', $user->id)->where('rotated_at', '>=', now()->subDay())->count();

            if ($recent >= (int) config('referrals.code.max_rotations_per_day')) {
                throw new ReferralException('rotation_limit', 'بلغت الحد اليومي لتغيير رمز الدعوة. حاول لاحقًا.');
            }

            $old = $this->activeFor($user);

            if ($old !== null) {
                $old->forceFill(['active_for_user_id' => null, 'rotated_at' => now()])->save();
            }

            return $this->createFor($user);
        });

        if ($old !== null) {
            Cache::forget($this->cacheKey($old->code));
        }

        Cache::forget('referrals:mycode:'.$user->id);

        return $new;
    }

    /**
     * يبحث عن رمز **نشط** بصيغة صالحة. يعيد صفًا خفيفًا (id/user_id) لا نموذجًا كاملًا.
     *
     * @return array{id: int, user_id: int, code: string}|null
     */
    public function findActive(mixed $raw): ?array
    {
        $code = $this->normalize($raw);

        if ($code === null) {
            return null;
        }

        $hit = Cache::get($this->cacheKey($code));

        if ($hit === 'none') {
            return null;
        }

        if (is_array($hit)) {
            return $hit;
        }

        $row = ReferralCode::query()->where('code', $code)->whereNotNull('active_for_user_id')->first(['id', 'user_id', 'code']);

        if ($row === null) {
            Cache::put($this->cacheKey($code), 'none', 60);

            return null;
        }

        $value = ['id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'code' => (string) $row->code];
        Cache::put($this->cacheKey($code), $value, 300);

        return $value;
    }

    public function generate(): string
    {
        $alphabet = (string) config('referrals.code.alphabet');
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0, $n = (int) config('referrals.code.length'); $i < $n; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    protected function createFor(User $user): ReferralCode
    {
        $attempts = max(1, (int) config('referrals.code.max_collision_retries'));
        Cache::forget('referrals:mycode:'.$user->id);

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return DB::transaction(fn () => ReferralCode::query()->forceCreate([
                    'user_id' => $user->id,
                    'code' => $this->generate(),
                    'active_for_user_id' => $user->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                // إما تصادم رمز (نعيد بتوليد جديد) وإما سباق أنشأ رمزًا نشطًا لنفس المستخدم (نعيده).
                if (($existing = $this->activeFor($user)) !== null) {
                    return $existing;
                }
            }
        }

        throw new ReferralException('code_generation_failed', 'تعذّر إنشاء رمز الدعوة الآن. حاول لاحقًا.');
    }

    protected function cacheKey(string $code): string
    {
        return 'referrals:code:'.$code;
    }
}
`````

## `app/Services/Referrals/ReferralException.php`

`````php
<?php

namespace App\Services\Referrals;

/** E33 - خطأ أعمال مفهوم (رسالة عربية آمنة للعرض). `reason` رمز آلي ثابت للاختبارات والواجهة؛ لا يحمل بيانات خاصة. */
class ReferralException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
`````

## `app/Services/Referrals/ReferralIntent.php`

`````php
<?php

namespace App\Services\Referrals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * E33 (A) - «نية الإحالة» لزائر ضيف: رمز + (اختياريًا) slug حملة + مصدر + وقت. تُحفظ بالجلسة وبكوكي أول-طرف **مشفّر** (EncryptCookies = موقَّع + مشفّر)
 * بمدة قابلة للضبط محصورة في [7, 30] يومًا. القواعد:
 *  - الخادم هو الحَكَم: النية تحمل رمزًا فقط (لا معرّف مستخدم) ويُحلّ عند التسجيل؛ لا حقل من العميل يحدد المحيل.
 *  - أول محيل صالح يربح: نية صالحة قائمة لا تُستبدل (الصالحة = الرمز ما زال نشطًا والمدة لم تنقضِ).
 *  - الزائر المصادَق لا يُسنَد إليه شيء.
 *  - لا تتبّع طرف ثالث: كوكي واحد أول-طرف، HttpOnly + SameSite=Lax (+ Secure على HTTPS)، يُحذف بعد التسجيل.
 */
class ReferralIntent
{
    public const SESSION_KEY = 'referral_intent';

    public function __construct(
        protected ReferralCodeService $codes,
        protected ReferralCampaignService $campaigns,
        protected ReferralClickRecorder $clicks,
    ) {}

    public function ttlDays(): int
    {
        return max(7, min(30, (int) config('referrals.attribution.ttl_days')));
    }

    /** يلتقط النية إن كان الطلب لضيف ورمز صالح. يعيد true فقط إن خُزّنت نية جديدة. */
    public function capture(Request $request, mixed $rawCode, mixed $rawCampaign, string $source): bool
    {
        if (! (bool) config('referrals.enabled') || $request->user() !== null) {
            return false;
        }

        $hit = $this->codes->findActive($rawCode);

        if ($hit === null) {
            return false;
        }

        $this->clicks->record($hit['id'], $request);

        if ($this->current($request) !== null) {
            return false; // أول محيل صالح يربح
        }

        $intent = [
            'code' => $hit['code'],
            'c' => $this->campaigns->validSlug($rawCampaign),
            's' => $source,
            'at' => now()->getTimestamp(),
        ];

        $request->session()->put(self::SESSION_KEY, $intent);
        Cookie::queue(Cookie::make(
            (string) config('referrals.attribution.cookie'),
            json_encode($intent, JSON_UNESCAPED_UNICODE),
            $this->ttlDays() * 1440,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return true;
    }

    /**
     * النية الصالحة الحالية (من الجلسة ثم الكوكي) أو null. الصالحة: صيغة سليمة + ضمن المدة + الرمز ما زال نشطًا.
     *
     * @return array{code: string, c: ?string, s: string, at: int}|null
     */
    public function current(Request $request): ?array
    {
        $raw = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($raw)) {
            $cookie = $request->cookie((string) config('referrals.attribution.cookie'));
            $raw = is_string($cookie) && strlen($cookie) < 512 ? json_decode($cookie, true) : null;
        }

        if (! is_array($raw) || ! isset($raw['code'], $raw['at']) || ! is_int($raw['at'])) {
            return null;
        }

        if ($raw['at'] + $this->ttlDays() * 86400 < now()->getTimestamp() || $raw['at'] > now()->getTimestamp() + 60) {
            return null;
        }

        $hit = $this->codes->findActive($raw['code']);

        if ($hit === null) {
            return null;
        }

        return [
            'code' => $hit['code'],
            'c' => $this->campaigns->validSlug($raw['c'] ?? null),
            's' => in_array($raw['s'] ?? null, ['link', 'share_param'], true) ? $raw['s'] : 'link',
            'at' => $raw['at'],
        ];
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        Cookie::queue(Cookie::forget((string) config('referrals.attribution.cookie')));
    }
}
`````

## `app/Services/Referrals/ReferralNotifier.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\User;
use App\Models\UserReferral;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E33 - إشعارات الإحالة (E15). تُستدعى **بعد** نجاح التغيير (بعد الـcommit) وفشلها لا يُرجع حالة (try/catch + report). مفاتيح idempotency دلالية.
 * لا اسم للمُحال ولا أي بيانات خاصة بالنصوص/المعاملات. **لا إشعار على نقرة ولا على تسجيل وحده** (فقط التأهيل والجائزة).
 */
class ReferralNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function qualified(UserReferral $referral): void
    {
        if ($referral->referrer_user_id === null) {
            return;
        }

        $this->send((int) $referral->referrer_user_id, NotificationType::ReferralQualified, [], 'referral_qualified:'.$referral->id, $referral);
    }

    public function rewardReceived(UserReferral $referral, string $side, int $gems): void
    {
        $userId = $side === 'referrer' ? $referral->referrer_user_id : $referral->referred_user_id;

        if ($userId === null || $gems <= 0) {
            return;
        }

        $this->send((int) $userId, NotificationType::ReferralRewardReceived, ['gems' => $gems], 'referral_reward:'.$referral->id.':'.$side, $referral);
    }

    protected function send(int $userId, NotificationType $type, array $params, string $key, UserReferral $referral): void
    {
        if (! (bool) config('referrals.notifications.enabled', true)) {
            return;
        }

        try {
            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return;
            }

            $this->dispatcher->dispatch($user, $type, $params, $key, ['user_referral_id' => $referral->id], [], 'referrals.index');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
`````

## `app/Services/Referrals/ReferralQualificationService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E33 (D) - تأهيل الإحالة. **التسجيل وحده لا يؤهّل أبدًا.** شروط التأهيل مجتمعة:
 *  1) بريد المُحال موثَّق وحسابه غير مجمَّد؛
 *  2) مرّ عمر الحساب الأدنى (قابل للضبط؛ لقطة وقت الإسناد)؛
 *  3) إجراء رسمي ذو معنى: حلّ أحجيات **رسمية** صحيحة بعدد القاعدة (first_official_solve = 1 افتراضيًا) — أحجيات المجتمع لا تُحتسب لأن مسار الحل يرفضها أصلًا؛
 *  4) لا إشارات تستدعي التعليق (انظر ReferralRiskService) وإلا pending_review.
 *
 * Idempotent بالتصميم: تحت قفل صف الإحالة لا تُعالَج إلا pending، فتكرار الحدث/تزامنه لا يؤهّل مرتين ولا يضاعف شيئًا. التسليم والإشعارات بعد الـcommit.
 * الحدث: GameplayProgressionService::afterPuzzleSolved ⇒ onMeaningfulAction (معزول بـtry/catch فلا يكسر اللعب أبدًا). أمر referrals:process يستكمل المنتظرة لعمر الحساب.
 * لا تأثير على نقاط اللعب أو الترتيب: هذه الخدمة لا تكتب XP ولا درجات ولا ترتيبًا.
 */
class ReferralQualificationService
{
    public function __construct(
        protected ReferralRiskService $risk,
        protected ReferralRewardService $rewards,
        protected ReferralNotifier $notifier,
        protected ReferralCampaignService $campaigns,
    ) {}

    /** يُستدعى بعد حلّ أحجية رسمية صحيحة. لا يرمي أبدًا. */
    public function onMeaningfulAction(User $user): ?UserReferral
    {
        if (! (bool) config('referrals.enabled')) {
            return null;
        }

        try {
            $id = UserReferral::query()->where('referred_user_id', $user->id)->where('status', UserReferral::STATUS_PENDING)->value('id');

            if ($id === null) {
                return null;
            }

            UserReferral::query()->whereKey($id)->whereNull('action_at')->update(['action_at' => now()]);

            return $this->evaluate((int) $id);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * يقيّم إحالة معلّقة. يعيد الإحالة بعد التقييم (أو null إن لم تكن pending/غير موجودة).
     */
    public function evaluate(int $referralId, ?CarbonInterface $now = null): ?UserReferral
    {
        $now ??= now();
        $outcome = null;

        DB::transaction(function () use ($referralId, $now, &$outcome) {
            $r = UserReferral::query()->whereKey($referralId)->lockForUpdate()->first();

            if ($r === null || $r->status !== UserReferral::STATUS_PENDING || $r->action_at === null) {
                return;
            }

            $referred = User::query()->find($r->referred_user_id);
            $referrer = $r->referrer_user_id !== null ? User::query()->find($r->referrer_user_id) : null;

            if ($referred === null || $referrer === null || (int) $referrer->id === (int) $referred->id) {
                return; // محيل محذوف/إحالة ذاتية دفاعية: لا تأهيل (الإحالة الذاتية لا تُنشأ أصلًا)
            }

            if ($referred->email_verified_at === null || $referred->is_frozen) {
                return;
            }

            $minAge = (int) ($r->min_account_age_hours ?? config('referrals.qualification.min_account_age_hours'));

            if ($referred->created_at === null || $referred->created_at->gt($now->copy()->subHours(max(0, $minAge)))) {
                return; // ينتظر عمر الحساب
            }

            if (! $this->ruleSatisfied($r, $referred)) {
                return;
            }

            $signals = $this->risk->signalsFor($r, $referrer, $referred);

            if ($this->risk->shouldHold($signals)) {
                $r->forceFill(['status' => UserReferral::STATUS_PENDING_REVIEW, 'held_at' => $now, 'risk_signals' => $signals])->save();
                $outcome = 'held';

                return;
            }

            $this->finalize($r, $now, $signals);
            $outcome = 'qualified';
        });

        $fresh = UserReferral::query()->find($referralId);

        if ($outcome === 'qualified' && $fresh !== null) {
            $this->afterQualified($fresh);
        }

        return $fresh;
    }

    /**
     * يختم التأهيل: حالة qualified + لقطة المكافأة. يُستدعى داخل معاملة وتحت قفل صف الإحالة؛ يقفل صف المحيل لتسلسل السقوف.
     * ترتيب الأقفال ثابت (إحالة ثم محيل) فلا deadlock بين إحالتين لنفس المحيل.
     *
     * @param  list<string>  $signals
     */
    public function finalize(UserReferral $r, CarbonInterface $now, array $signals): void
    {
        User::query()->whereKey($r->referrer_user_id)->lockForUpdate()->first();

        $campaign = $r->referral_campaign_id !== null ? ReferralCampaign::query()->find($r->referral_campaign_id) : null;

        $r->forceFill([
            'status' => UserReferral::STATUS_QUALIFIED,
            'qualified_at' => $now,
            'risk_signals' => $signals === [] ? null : $signals,
        ] + $this->rewards->plan($r, $campaign, $now))->save();
    }

    /** بعد الـcommit: تسليم المكافآت ثم إشعار المحيل (بترتيب لا يؤثر على صحة التأهيل). */
    public function afterQualified(UserReferral $r): void
    {
        try {
            $this->rewards->deliver($r->id);
        } catch (Throwable $e) {
            report($e);
        }

        $this->notifier->qualified($r);
    }

    public function requiredSolves(UserReferral $r): int
    {
        $rules = (array) config('referrals.qualification.rules');

        return (int) ($rules[$r->qualification_rule] ?? $rules[config('referrals.qualification.default_rule')] ?? 1);
    }

    protected function ruleSatisfied(UserReferral $r, User $referred): bool
    {
        $need = $this->requiredSolves($r);

        if ($need <= 1) {
            return true; // الاستدعاء نفسه ناتج عن حلّ رسمي صحيح مُسجَّل (afterPuzzleSolved)
        }

        return $this->officialSolveCount($referred) >= $need;
    }

    /** أحجيات رسمية مختلفة حُلّت بشكل صحيح (من الجدول الحقيقي، لا من عدّاد قابل للتزييف). */
    public function officialSolveCount(User $user): int
    {
        return (int) DB::table('puzzle_attempts')
            ->join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
            ->where('puzzle_attempts.user_id', $user->id)
            ->where('puzzle_attempts.is_correct', true)
            ->where('puzzles.origin', \App\Models\Puzzle::ORIGIN_OFFICIAL)
            ->distinct()
            ->count('puzzle_attempts.puzzle_id');
    }
}
`````

## `app/Services/Referrals/ReferralReviewService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\User;
use App\Models\UserReferral;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E33 (E) - مراجعة المشرف للإحالات (referrals.review). الإشارات تقترح والإنسان يقرر:
 *  - approve: إحالة pending_review ⇒ qualified (مع تخطيط المكافأة وقت الاعتماد) ثم التسليم الاعتيادي بعد الـcommit.
 *  - disqualify: بسبب منظَّم من قائمة مغلقة. **لا يُخصم شيء من دفتر المحفظة** (لا خصم صامت): إن كانت قد كوفئت يجب إقرار صريح بأن الجواهر المسلَّمة تبقى،
 *    ويُسجَّل ذلك بالتدقيق. إحالة مستبعدة لا يُسلَّم لها جانب لم يُسلَّم بعد (reward_status ⇒ skipped).
 *  - كل إجراء: صلاحية + حد معدّل لكل مشرف + تدقيق. لا يغيّر حساب أي لاعب (لا تجميد) ولا ترتيبًا ولا نقاطًا.
 *  - المحيل لا يتغيّر أبدًا؛ المراجعة لا تلمس referrer_user_id.
 */
class ReferralReviewService
{
    public function __construct(
        protected ReferralQualificationService $qualification,
        protected OperationalAuditService $audit,
    ) {}

    public function approve(User $admin, UserReferral $referral, ?string $note = null): UserReferral
    {
        $this->authorize($admin);

        $result = DB::transaction(function () use ($admin, $referral, $note) {
            $r = UserReferral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($r->status !== UserReferral::STATUS_PENDING_REVIEW) {
                throw new ReferralException('not_in_review', 'الإحالة ليست بانتظار المراجعة.');
            }

            if ($r->referrer_user_id === null) {
                throw new ReferralException('referrer_missing', 'لا يمكن اعتماد إحالة محيلها محذوف.');
            }

            $this->qualification->finalize($r, now(), (array) $r->risk_signals);
            $r->forceFill(['reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $this->note($note)])->save();
            $this->audit->log('referral_review_approved', $r, ['signals' => $r->risk_signals], $admin);

            return $r;
        });

        $this->qualification->afterQualified($result);
        app(ReferralAnalytics::class)->forget(); // قرار بشري: تنعكس أرقام اللوحة فورًا لا بعد انتهاء الكاش

        return $result->fresh();
    }

    public function disqualify(User $admin, UserReferral $referral, string $reason, ?string $note = null, bool $acknowledgeKeptRewards = false): UserReferral
    {
        $this->authorize($admin);

        if (! array_key_exists($reason, UserReferral::DISQUALIFY_REASONS)) {
            throw new ReferralException('invalid_reason', 'سبب الاستبعاد غير صالح.');
        }

        $result = DB::transaction(function () use ($admin, $referral, $reason, $note, $acknowledgeKeptRewards) {
            $r = UserReferral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($r->status === UserReferral::STATUS_DISQUALIFIED) {
                throw new ReferralException('already_disqualified', 'الإحالة مستبعدة مسبقًا.');
            }

            $granted = ['referrer' => $r->referrer_granted_at !== null ? (int) $r->snap_referrer_gems : 0, 'referred' => $r->referred_granted_at !== null ? (int) $r->snap_referred_gems : 0];
            $anyGranted = $granted['referrer'] > 0 || $granted['referred'] > 0;

            if ($anyGranted && ! $acknowledgeKeptRewards) {
                throw new ReferralException('kept_rewards_ack_required', 'سُلّمت جواهر لهذه الإحالة ولن تُخصم تلقائيًا. أقرّ بذلك صراحةً لإتمام الاستبعاد.');
            }

            $attrs = [
                'status' => UserReferral::STATUS_DISQUALIFIED,
                'disqualified_reason' => $reason,
                'disqualified_at' => now(),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $this->note($note),
            ];

            if (! $anyGranted) {
                $attrs += ['reward_status' => UserReferral::REWARD_SKIPPED, 'reward_skip_reason' => 'disqualified'];
            }

            $r->forceFill($attrs)->save();
            $this->audit->log('referral_review_disqualified', $r, ['reason' => $reason, 'rewards_kept' => $granted, 'ledger_untouched' => true], $admin);

            return $r;
        });

        app(ReferralAnalytics::class)->forget();

        return $result;
    }

    protected function authorize(User $admin): void
    {
        if (! $admin->can('referrals.review')) {
            throw new ReferralException('forbidden', 'لا تملك صلاحية مراجعة الإحالات.');
        }

        $key = 'referral-campaign-action:'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('referrals.limits.campaign_action_per_minute'))) {
            throw new ReferralException('rate_limited', 'عدد كبير من الإجراءات خلال دقيقة. انتظر قليلًا.');
        }

        RateLimiter::hit($key, 60);
    }

    protected function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }
}
`````

## `app/Services/Referrals/ReferralRewardService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E33 (D) - مكافآت الإحالة (اختيارية، **معطّلة افتراضيًا**).
 *
 * التخطيط (plan) يعمل داخل معاملة التأهيل وتحت قفل صف المحيل (فيسلسل سباق السقوف):
 *  - مفتاح عام config(referrals.rewards.enabled) + تفعيل الحملة + حملة حيّة + مبلغ موجب، وإلا skipped بسبب مسجَّل.
 *  - سقوف المحيل (يوم/شهر/عمر) تُحسب من عدد الإحالات التي **حُجزت** مكافآتها (reward_reserved_at)؛ بلوغ سقف ⇒ لا مكافأة لأي جانب (يمنع مزرعة حسابات تُكافأ من جهة المُحال).
 *  - لقطة المبلغ/الحملة تُنسخ إلى الصف (snap_*): تعديل الحملة لاحقًا لا يغيّر تاريخ الإحالة.
 *
 * التسليم (deliver) بعد الـcommit، لكل جانب باستقلال، حصرًا عبر CurrencyWalletService::creditPending (الحجز المعلّق + PendingGemReleaseService) بمفتاح
 * دلالي فريد في دفتر المحفظة: referral-qualified:{referral_id}:{referrer|referred}. تكرار التسليم/سباقه لا يضاعف شيئًا (قفل صف الإحالة + UNIQUE المفتاح).
 * فشل جانب لا يلغي الآخر ولا التأهيل؛ يُعاد بأمر referrals:process. لا طلب عميل يمنح جواهر أبدًا ولا مسار يتجاوز المحفظة.
 */
class ReferralRewardService
{
    public const SIDES = ['referrer' => ['user' => 'referrer_user_id', 'gems' => 'snap_referrer_gems', 'at' => 'referrer_granted_at'], 'referred' => ['user' => 'referred_user_id', 'gems' => 'snap_referred_gems', 'at' => 'referred_granted_at']];

    public function __construct(protected CurrencyWalletService $wallets, protected CurrencyRegistry $currencies, protected ReferralNotifier $notifier) {}

    public static function ledgerKey(int|string $referralId, string $side): string
    {
        return 'referral-qualified:'.$referralId.':'.$side;
    }

    /**
     * @return array<string, mixed> أعمدة لقطة المكافأة لتُكتب مع التأهيل
     */
    public function plan(UserReferral $referral, ?ReferralCampaign $campaign, CarbonInterface $now): array
    {
        $skip = fn (string $reason) => ['reward_status' => UserReferral::REWARD_SKIPPED, 'reward_skip_reason' => $reason, 'snap_referrer_gems' => 0, 'snap_referred_gems' => 0, 'snap_campaign_id' => $campaign?->id, 'reward_reserved_at' => null];

        if ($campaign === null) {
            return $skip('no_campaign');
        }

        if (! (bool) config('referrals.rewards.enabled') || ! $campaign->rewards_enabled) {
            return $skip('rewards_disabled');
        }

        if (! $campaign->isLive($now)) {
            return $skip('campaign_inactive');
        }

        $max = (int) config('referrals.rewards.max_gems_per_side');
        $referrerGems = min($max, max(0, (int) $campaign->referrer_reward_gems));
        $referredGems = min($max, max(0, (int) $campaign->referred_reward_gems));

        if ($referrerGems === 0 && $referredGems === 0) {
            return $skip('zero_amount');
        }

        $caps = $campaign->effectiveCaps();
        $base = UserReferral::query()->where('referrer_user_id', $referral->referrer_user_id)->whereNotNull('reward_reserved_at')->where('id', '!=', $referral->id);

        foreach (['daily' => $now->copy()->startOfDay(), 'monthly' => $now->copy()->startOfMonth(), 'lifetime' => null] as $window => $from) {
            $count = (clone $base)->when($from !== null, fn ($q) => $q->where('reward_reserved_at', '>=', $from))->count();

            if ($count >= $caps[$window]) {
                return $skip('cap_'.$window);
            }
        }

        return [
            'reward_status' => UserReferral::REWARD_PENDING, 'reward_skip_reason' => null,
            'snap_referrer_gems' => $referrerGems, 'snap_referred_gems' => $referredGems,
            'snap_campaign_id' => $campaign->id, 'reward_reserved_at' => $now,
        ];
    }

    /**
     * يسلّم الجوانب غير المسلَّمة (idempotent). يعيد عدد الجوانب التي سُلّمت الآن.
     */
    public function deliver(int $referralId): int
    {
        $delivered = 0;

        foreach (array_keys(self::SIDES) as $side) {
            try {
                $delivered += $this->deliverSide($referralId, $side) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->settle($referralId);

        return $delivered;
    }

    protected function deliverSide(int $referralId, string $side): bool
    {
        $cols = self::SIDES[$side];
        $gems = 0;
        $referral = null;

        $granted = DB::transaction(function () use ($referralId, $side, $cols, &$gems, &$referral) {
            $referral = UserReferral::query()->whereKey($referralId)->lockForUpdate()->first();

            if ($referral === null
                || ! in_array($referral->status, [UserReferral::STATUS_QUALIFIED, UserReferral::STATUS_REWARDED], true)
                || $referral->reward_status === UserReferral::REWARD_SKIPPED
                || $referral->{$cols['at']} !== null) {
                return false;
            }

            $gems = (int) $referral->{$cols['gems']};
            $userId = $referral->{$cols['user']};

            if ($gems <= 0 || $userId === null) {
                return false;
            }

            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return false; // يبقى معلّقًا؛ يعيد المحاولة أمر referrals:process إن زال المانع
            }

            $this->wallets->creditPending($user, $this->currencies->defaultEarnedCurrency(), $gems, 'referral_reward', $referral, self::ledgerKey($referral->id, $side));
            $referral->forceFill([$cols['at'] => now()])->save();

            return true;
        });

        if ($granted && $referral !== null) {
            $this->notifier->rewardReceived($referral, $side, $gems);
        }

        return (bool) $granted;
    }

    /** عند تسليم كل الجوانب ذات المبلغ الموجب ⇒ rewarded/granted. شرطي على الحالة فلا يعيد إحياء إحالة مستبعدة. */
    protected function settle(int $referralId): void
    {
        $r = UserReferral::query()->find($referralId);

        if ($r === null || $r->status !== UserReferral::STATUS_QUALIFIED || $r->reward_status !== UserReferral::REWARD_PENDING) {
            return;
        }

        $done = true;

        foreach (self::SIDES as $cols) {
            if ((int) $r->{$cols['gems']} > 0 && $r->{$cols['at']} === null) {
                $done = false;
            }
        }

        if ($done) {
            UserReferral::query()->whereKey($r->id)->where('status', UserReferral::STATUS_QUALIFIED)
                ->update(['status' => UserReferral::STATUS_REWARDED, 'reward_status' => UserReferral::REWARD_GRANTED, 'rewarded_at' => now()]);
        }
    }
}
`````

## `app/Services/Referrals/ReferralRiskService.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\User;
use App\Models\UserReferral;

/**
 * E33 (E) - إشارات الاحتيال للإحالة. **إشارات لا عقوبات**: لا حظر ولا تجميد حساب ولا رفض تلقائي أبدًا.
 *  - إشارة قوية واحدة، أو عدد (hold_weak_threshold) من الإشارات الضعيفة معًا ⇒ تعليق الإحالة للمراجعة (pending_review) وتأخير المكافأة فقط.
 *  - تطابق IP وحده (إشارة ضعيفة) أو تطابق الجهاز وحده **لا يعلّق ولا يحظر**: شبكات الجامعات/الأسرة/المقاهي مشتركة مشروعًا.
 *  - نقرأ جداول الرصد القائمة فقط (DeviceSighting: بصمة User-Agent ضعيفة أصلًا) ولا نجمع بصمة جديدة؛ ولا نخزّن IP/UA في الإحالة: مفاتيح الإشارات المغلقة فقط.
 */
class ReferralRiskService
{
    public const STRONG = ['referrer_frozen', 'referrer_high_fraud_flag', 'referred_high_fraud_flag'];

    public const WEAK = ['shared_ip', 'shared_device', 'similar_email', 'referrer_burst', 'referrer_daily_velocity', 'fast_action', 'referrer_open_flag', 'referred_open_flag'];

    /** @return list<string> مفاتيح مغلقة فقط */
    public function signalsFor(UserReferral $referral, User $referrer, User $referred): array
    {
        $signals = [];

        if ($referrer->is_frozen) {
            $signals[] = 'referrer_frozen';
        }

        foreach (['referrer' => $referrer, 'referred' => $referred] as $side => $user) {
            $severities = FraudFlag::query()->where('user_id', $user->id)->where('resolved', false)->pluck('severity')->all();

            if (in_array('high', $severities, true) || in_array('critical', $severities, true)) {
                $signals[] = $side.'_high_fraud_flag';
            } elseif ($severities !== []) {
                $signals[] = $side.'_open_flag';
            }
        }

        $since = now()->subDays(max(1, (int) config('referrals.fraud.sighting_lookback_days')));

        foreach (['shared_ip' => 'ip_address', 'shared_device' => 'device_hash'] as $signal => $column) {
            $mine = DeviceSighting::query()->where('user_id', $referred->id)->where('last_seen_at', '>=', $since)->whereNotNull($column)->distinct()->limit(20)->pluck($column)->all();

            if ($mine !== [] && DeviceSighting::query()->where('user_id', $referrer->id)->where('last_seen_at', '>=', $since)->whereIn($column, $mine)->exists()) {
                $signals[] = $signal;
            }
        }

        if ($this->emailRoot((string) $referrer->email) === $this->emailRoot((string) $referred->email)) {
            $signals[] = 'similar_email';
        }

        $burst = UserReferral::query()->where('referrer_user_id', $referrer->id)->where('created_at', '>=', now()->subHour())->count();

        if ($burst >= (int) config('referrals.fraud.referrer_burst_per_hour')) {
            $signals[] = 'referrer_burst';
        }

        $velocity = UserReferral::query()->where('referrer_user_id', $referrer->id)->whereIn('status', UserReferral::QUALIFIED_STATUSES)->where('qualified_at', '>=', now()->subDay())->count();

        if ($velocity >= (int) config('referrals.fraud.referrer_qualified_per_day')) {
            $signals[] = 'referrer_daily_velocity';
        }

        if ($referral->action_at !== null && $referred->created_at !== null
            && $referral->action_at->diffInSeconds($referred->created_at, true) < (int) config('referrals.fraud.fast_action_seconds')) {
            $signals[] = 'fast_action';
        }

        return array_values(array_unique($signals));
    }

    /** @param list<string> $signals */
    public function shouldHold(array $signals): bool
    {
        $strong = array_intersect($signals, self::STRONG);
        $weak = array_intersect($signals, self::WEAK);

        return $strong !== [] || count($weak) >= max(2, (int) config('referrals.fraud.hold_weak_threshold'));
    }

    /** جذر البريد: يوحّد +tag ونقاط gmail لاكتشاف «نفس الشخص» دون تخزين شيء. */
    public function emailRoot(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $local = explode('+', $local, 2)[0];

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}
`````

## `app/Services/Referrals/ReferralShareStats.php`

`````php
<?php

namespace App\Services\Referrals;

use Illuminate\Support\Facades\Cache;

/**
 * E33 (C/E) - عدّادات مشاركة **تقريبية ومحدودة**: أنواع × أهداف من قوائم مغلقة × يوم، في الكاش فقط بصلاحية قصيرة (config share_counter_days).
 * لا هوية ولا IP ولا UA ولا رابط كامل. ليست مصدرًا لأي مكافأة، وتنخفض/تضيع بتفريغ الكاش (نصرّح بذلك في الواجهة: «تقريبي»).
 */
class ReferralShareStats
{
    public function record(string $kind, string $target): bool
    {
        if (! in_array($kind, (array) config('referrals.share_kinds'), true) || ! in_array($target, (array) config('referrals.share_targets'), true)) {
            return false;
        }

        $key = $this->key($kind, $target, now()->format('Ymd'));
        Cache::add($key, 0, now()->addDays((int) config('referrals.analytics.share_counter_days') + 1));
        Cache::increment($key);

        return true;
    }

    /** @return array{total: int, by_kind: array<string, int>, by_target: array<string, int>} */
    public function lastDays(int $days = 7): array
    {
        $byKind = array_fill_keys((array) config('referrals.share_kinds'), 0);
        $byTarget = array_fill_keys((array) config('referrals.share_targets'), 0);
        $total = 0;

        for ($d = 0; $d < max(1, min($days, (int) config('referrals.analytics.share_counter_days'))); $d++) {
            $day = now()->subDays($d)->format('Ymd');

            foreach ($byKind as $kind => $_) {
                foreach ($byTarget as $target => $__) {
                    $n = (int) Cache::get($this->key($kind, $target, $day), 0);
                    $byKind[$kind] += $n;
                    $byTarget[$target] += $n;
                    $total += $n;
                }
            }
        }

        return ['total' => $total, 'by_kind' => array_filter($byKind), 'by_target' => array_filter($byTarget)];
    }

    protected function key(string $kind, string $target, string $day): string
    {
        return "referrals:share:{$day}:{$kind}:{$target}";
    }
}
`````

## `app/Services/Referrals/ShareLinkBuilder.php`

`````php
<?php

namespace App\Services\Referrals;

use App\Models\User;

/**
 * E33 (C) - منشئ روابط المشاركة الموحَّد. كل الروابط من أسماء مسارات مسماة (`route()`) على المضيف المضبوط للتطبيق: لا URL يأتي من العميل ولا من الطلب
 * (فلا open redirect ولا حقن). القائمة المغلقة للأنواع في config(referrals.share_kinds).
 *
 * الرابط الأساسي (url) نظيف بلا معاملات = الرابط القانوني (canonical). النسخة «مع دعوتي» (url_ref) تُضاف فقط لمستخدم لديه رمز نشط، وتحمل ?ref=CODE
 * (+ &c=slug لحملة حيّة مرتبطة بالموسم). لا إنشاء رمز هنا (قراءة فقط) فلا كتابة على GET.
 * أحجيات/محتوى المجتمع تُشارَك كروابط عادية؛ المشاركة لا تمنح اقتصادًا ولا تغيّر ترتيبًا.
 */
class ShareLinkBuilder
{
    public const ROUTES = [
        'puzzle' => 'puzzles.show',
        'community_puzzle' => 'community.puzzles.show',
        'series' => 'series.show',
        'creator' => 'creators.show',
        'season' => 'seasons.show',
        'campaign' => 'campaigns.show',
        'competition' => 'competitions.show',
        'supporters' => 'supporters.show',
        'platform' => 'home',
    ];

    public function __construct(protected ReferralCodeService $codes, protected ReferralCampaignService $campaigns) {}

    /**
     * @param  mixed  $routeParam  المعامل نفسه الذي تستعمله الواجهات في route() لهذه الصفحة (نموذج/slug/public_id)؛ لا قيمة من العميل.
     * @return array{kind: string, title: string, text: string, url: string, url_ref: ?string, links: array{plain: array<string, string>, ref: ?array<string, string>}}
     */
    public function build(string $kind, mixed $routeParam, string $title, ?string $text = null, ?User $viewer = null, ?int $seasonId = null): array
    {
        abort_unless(isset(self::ROUTES[$kind]) && in_array($kind, (array) config('referrals.share_kinds'), true), 404);

        $url = match (true) {
            $kind === 'platform' => route('home'),
            $kind === 'supporters' && $routeParam === null => route('supporters.index'),
            default => route(self::ROUTES[$kind], $routeParam),
        };
        $title = mb_substr(trim($title), 0, 120);
        $text = mb_substr(trim($text ?? $title), 0, 200);

        $urlRef = null;
        $code = $viewer !== null && $viewer->email_verified_at !== null && ! $viewer->is_frozen ? $this->codes->myCode($viewer) : null;

        if ($code !== null) {
            $query = ['ref' => $code];

            if ($kind === 'season' && $seasonId !== null && ($slug = $this->campaigns->slugForSeason($seasonId)) !== null) {
                $query['c'] = $slug;
            }

            $urlRef = $url.'?'.http_build_query($query);
        }

        return [
            'kind' => $kind, 'title' => $title, 'text' => $text, 'url' => $url, 'url_ref' => $urlRef,
            'links' => ['plain' => $this->targets($title, $url), 'ref' => $urlRef === null ? null : $this->targets($title, $urlRef)],
        ];
    }

    /** @return array<string, string> */
    protected function targets(string $title, string $url): array
    {
        return [
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($title."\n".$url),
            'telegram' => 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode($title),
        ];
    }
}
`````

## `config/referrals.php`

`````php
<?php

/*
|--------------------------------------------------------------------------
| E33 - الإحالات ونمو المنصة
|--------------------------------------------------------------------------
| كل قيمة هنا افتراضية آمنة. المكافآت **معطّلة افتراضيًا** (REFERRALS_REWARDS_ENABLED=false): الإحالة تُحتسب وتظهر تحليلاتها بلا أي جواهر.
| تفعيل المكافآت يتطلب (1) مفتاحًا عامًا هنا و(2) حملة نشطة بمبالغ أكبر من صفر. لا مال نقدي ولا مستويات (MLM) ولا مكافآت على التسجيل وحده.
*/

return [
    // مفتاح الميزة كلها: false يوقف /r/{code} و?ref= والصفحة والتأهيل (الحسابات والإحالات المسجَّلة تبقى كما هي).
    'enabled' => (bool) env('REFERRALS_ENABLED', true),

    'code' => [
        // 6 محارف من أبجدية بلا حروف علة (تمنع كلمات مسيئة) وبلا محارف ملتبسة (0/1/I/O). 27^6 ≈ 387 مليون احتمال.
        'length' => 6,
        'alphabet' => 'BCDFGHJKLMNPQRSTVWXZ2346789',
        'max_collision_retries' => 8,
        // أقصى عدد مرات تدوير الرمز لكل مستخدم خلال 24 ساعة (يمنع إغراق جدول الرموز).
        'max_rotations_per_day' => 3,
    ],

    'attribution' => [
        // مدة صلاحية النية (الجلسة + الكوكي الأول-الطرف المشفّر). تُقصّ دائمًا إلى [7, 30] يومًا.
        'ttl_days' => (int) env('REFERRALS_TTL_DAYS', 14),
        'cookie' => 'ahj_ref',
        // الوجهات المسموحة بعد /r/{code} (مفتاح => اسم المسار). لا URL يأتي من العميل أبدًا (لا open redirect).
        'landings' => ['register' => 'register', 'home' => 'home', 'puzzles' => 'puzzles.index', 'discover' => 'discover.index'],
        'default_landing' => 'register',
    ],

    'qualification' => [
        // عمر الحساب الأدنى (بالساعات) قبل التأهيل؛ الحملة قد تتجاوزه ضمن [0, 720].
        'min_account_age_hours' => (int) env('REFERRALS_MIN_ACCOUNT_AGE_HOURS', 1),
        // مفتاح القاعدة => عدد الحلول الرسمية الصحيحة (أحجيات مختلفة) المطلوبة. التسجيل وحده لا يؤهّل أبدًا.
        'rules' => [
            'first_official_solve' => 1,
            'three_official_solves' => 3,
            'five_official_solves' => 5,
        ],
        'default_rule' => 'first_official_solve',
    ],

    'rewards' => [
        // false افتراضيًا: لا جواهر إحالة حتى يقرر المشرف.
        'enabled' => (bool) env('REFERRALS_REWARDS_ENABLED', false),
        // أقصى مبلغ مسموح لكل جانب (حماية من خطأ إدخال في لوحة الإدارة).
        'max_gems_per_side' => 500,
        // السقوف الافتراضية لكل محيل (عدد الإحالات المكافَأة). الحملة قد تضع أقل منها؛ لا يمكنها تجاوز الحد الأعلى المطلق.
        'caps' => ['daily' => 5, 'monthly' => 30, 'lifetime' => 100],
        'absolute_caps' => ['daily' => 50, 'monthly' => 300, 'lifetime' => 1000],
    ],

    'fraud' => [
        // إشارات فقط: لا حظر ولا تجميد. التعليق (pending_review) عند إشارة قوية أو عند هذا العدد من الإشارات الضعيفة معًا.
        'hold_weak_threshold' => 2,
        'referrer_burst_per_hour' => 6,
        'referrer_qualified_per_day' => 10,
        'fast_action_seconds' => 20,
        'sighting_lookback_days' => 30,
    ],

    'analytics' => [
        // النقرات تقريبية (فريدة بالساعة لكل زائر بلا تخزين IP أو UA). لا دقة وهمية.
        'click_dedupe_seconds' => 3600,
        'share_counter_days' => 8,
        'cache_seconds' => 120,
        'campaign_cache_seconds' => 300,
        'top_referrers' => 10,
    ],

    'limits' => [
        // الحدود بالدقيقة لكل IP/مستخدم (تُسجَّل في AppServiceProvider).
        'redirect_per_minute' => 60,
        'dashboard_per_minute' => 30,
        'share_per_minute' => 30,
        'campaign_action_per_minute' => 30,
    ],

    'notifications' => ['enabled' => (bool) env('REFERRALS_NOTIFICATIONS', true)],

    // ما يُشارَك: نوع => ['route' => اسم المسار]. بناء الروابط من المسارات المسماة فقط (لا host من الطلب).
    'share_kinds' => ['puzzle', 'community_puzzle', 'series', 'creator', 'season', 'campaign', 'competition', 'supporters', 'platform'],
    'share_targets' => ['native', 'copy', 'whatsapp', 'telegram'],
];
`````

## `database/migrations/2026_10_26_000001_create_referral_campaigns_table.php`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - حملات الدعوة (إعدادات فقط؛ لا تاريخ مالي هنا).
 *
 * حدود السلامة في قاعدة البيانات:
 *  - slug UNIQUE            ⇒ ?c=slug يشير إلى حملة واحدة.
 *  - default_slot UNIQUE    ⇒ حملة افتراضية واحدة كحدّ أقصى. القيمة 1 للحملة الافتراضية و NULL لغيرها (NULL متعدد مسموح على SQLite وMySQL)، فلا حاجة لفهرس جزئي.
 *  - لا قيم مبالغ عشرية: الجواهر أعداد صحيحة غير سالبة (أعمدة unsigned).
 * سياسة الحذف: الموسم/المنشئ SET NULL (حذفهما لا يحذف الحملة ولا يكسر الإحالات التاريخية).
 * اللقطة المالية للإحالة تُنسخ إلى user_referrals عند التأهيل، فتعديل الحملة لاحقًا لا يغيّر تاريخًا (انظر الهجرة الثالثة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_campaigns', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('draft'); // draft | active | paused | ended
            $table->boolean('is_public')->default(true);
            $table->unsignedTinyInteger('default_slot')->nullable()->unique('referral_campaigns_default_slot_unique');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('season_id')->nullable()->constrained('seasons')->nullOnDelete();

            $table->string('qualification_rule', 40)->default('first_official_solve');
            $table->unsignedSmallInteger('min_account_age_hours')->nullable(); // NULL = قيمة config

            $table->boolean('rewards_enabled')->default(false);
            $table->unsignedInteger('referrer_reward_gems')->default(0);
            $table->unsignedInteger('referred_reward_gems')->default(0);
            $table->unsignedSmallInteger('daily_cap')->nullable();    // عدد الإحالات المكافَأة لكل محيل؛ NULL = قيمة config
            $table->unsignedSmallInteger('monthly_cap')->nullable();
            $table->unsignedInteger('lifetime_cap')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at'], 'referral_campaigns_status_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_campaigns');
    }
};
`````

## `database/migrations/2026_10_26_000002_create_referral_codes_table.php`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - رموز الإحالة الشخصية.
 *
 *  - code UNIQUE              ⇒ رمز واحد لا يخص مستخدمَين (حتى بعد التدوير؛ الرموز القديمة تبقى صفوفًا معطّلة لتفسير روابط قديمة).
 *  - active_for_user_id UNIQUE ⇒ رمز نشط واحد كحدّ أقصى لكل مستخدم: يساوي user_id للرمز النشط وNULL للمدوَّر/المعطّل (NULL متعدد مسموح). الحارس الأخير ضد سباق إنشاء رمزين.
 * سياسة الحذف: حذف المستخدم يحذف رموزه (CASCADE)؛ الإحالات التاريخية تحتفظ بالمحيل SET NULL ولا تنكسر (انظر user_referrals).
 * العدّادات (clicks/shares) تقريبية بالتصميم (نقرات فريدة بالساعة، بلا IP/UA مخزَّن)؛ ليست مصدرًا للمكافآت أبدًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->unsignedBigInteger('active_for_user_id')->nullable()->unique('referral_codes_active_for_user_unique');
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'referral_codes_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
`````

## `database/migrations/2026_10_26_000003_create_user_referrals_table.php`

`````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - إحالة واحدة لكل حساب مُحال (المصدر الوحيد للحقيقة).
 *
 *  - referred_user_id UNIQUE ⇒ حساب جديد له محيل واحد كحدّ أقصى، ومحيل لا يتغيّر (لا مسار تحديث له في الخدمات). حارس القاعدة لا التطبيق وحده.
 *  - حالات: pending | pending_review | qualified | rewarded | disqualified (مفتاح نصي مغلق في النموذج).
 *  - لقطة المكافأة (snap_*, reward_reserved_at, *_granted_at) تُنسخ عند التأهيل: تعديل الحملة بعدها لا يغيّر تاريخ هذه الإحالة.
 *  - مفتاح فرادة المكافأة في دفتر المحفظة (currency_transactions.idempotency_key): referral-qualified:{id}:referrer|referred ⇒ لا ائتمان مزدوج حتى لو تكرر التسليم.
 *  - risk_signals: مفاتيح إشارات مغلقة فقط (لا IP ولا UA ولا بصمة خام).
 *
 * سياسة الحذف المقصودة:
 *  - المُحال محذوف  ⇒ CASCADE (الإحالة بياناته الشخصية؛ قيود المحفظة تبقى بإجراءاتها الخاصة).
 *  - المحيل محذوف   ⇒ SET NULL (يبقى تاريخ المُحال وأرقام التحليلات؛ لا مكافآت جديدة تُسلَّم لمحيل مجهول).
 *  - الرمز/الحملة/المراجِع محذوفة ⇒ SET NULL (الحملة تُنهى عادةً ولا تُحذف؛ اللقطة تحفظ الشروط).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_referrals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referral_code_id')->nullable()->constrained('referral_codes')->nullOnDelete();
            $table->foreignId('referral_campaign_id')->nullable()->constrained('referral_campaigns')->nullOnDelete();

            $table->string('source', 24)->default('link'); // link | share_param
            $table->string('status', 16)->default('pending');
            $table->string('qualification_rule', 40)->default('first_official_solve');
            $table->unsignedSmallInteger('min_account_age_hours')->nullable(); // لقطة وقت الإسناد (NULL = قيمة config)

            $table->timestamp('action_at')->nullable();       // أول إجراء رسمي ذي معنى (قد يسبق التأهيل بانتظار عمر الحساب)
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->json('risk_signals')->nullable();

            // لقطة المكافأة عند التأهيل + تسليم كل جانب باستقلال.
            $table->string('reward_status', 16)->default('none'); // none | pending | granted | skipped
            $table->string('reward_skip_reason', 32)->nullable();
            $table->unsignedInteger('snap_referrer_gems')->default(0);
            $table->unsignedInteger('snap_referred_gems')->default(0);
            $table->unsignedBigInteger('snap_campaign_id')->nullable(); // معرّف الحملة وقت التأهيل (بلا FK: لقطة تاريخية)
            $table->timestamp('reward_reserved_at')->nullable();        // يُحتسب ضمن سقف المحيل
            $table->timestamp('referrer_granted_at')->nullable();
            $table->timestamp('referred_granted_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();

            $table->string('disqualified_reason', 32)->nullable(); // قائمة مغلقة
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->unique('referred_user_id', 'user_referrals_referred_user_unique');
            $table->index(['referrer_user_id', 'status'], 'user_referrals_referrer_status_idx');
            $table->index(['referrer_user_id', 'reward_reserved_at'], 'user_referrals_referrer_reserved_idx');
            $table->index(['referral_campaign_id', 'status'], 'user_referrals_campaign_status_idx');
            $table->index(['status', 'action_at'], 'user_referrals_status_action_idx');
            $table->index(['status', 'created_at'], 'user_referrals_status_created_idx');
            $table->index('referral_code_id', 'user_referrals_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
    }
};
`````

## `database/seeders/DemoE33Seeder.php`

`````php
<?php

namespace Database\Seeders;

use App\Models\DeviceSighting;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAttributionService;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralQualificationService;
use App\Services\Referrals\ReferralReviewService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E33 - بيانات Demo للإحالات.   php artisan db:seed --class=DemoE33Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق referrals.test، بلا بريد حقيقي ولا IP حقيقي).
 * - بالخدمات الرسمية فقط (حملات/رموز/إسناد/تأهيل/مراجعة/محفظة): لا كتابة مباشرة بحالة أو جواهر. المكافآت مفعّلة داخل هذه العملية فقط (config مؤقت) لتوليد إحالة مكافَأة.
 * - Idempotent: كل حساب بريد ثابت، وكل إحالة تُنشأ مرة واحدة لكل مُحال، والحملات بـslug ثابت ⇒ إعادة التشغيل لا تكرّر شيئًا.
 * - تولّد: حملتان نشطتان (إحداهما افتراضية بمكافأة)، إحالات معلّقة، مؤهَّلة، مكافَأة واحدة، مستبعدة واحدة، ومشبوهة واحدة (pending_review).
 */
class DemoE33Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-referrals-admin@referrals.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE33Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        config(['referrals.enabled' => true, 'referrals.rewards.enabled' => true]);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الإحالات التجريبي', 0);
        $admin->syncRoles(['administrator']);

        $campaigns = app(ReferralCampaignService::class);
        $main = $this->campaign($campaigns, $admin, 'demo-friends', 'ادعُ صديقًا', ['rewards_enabled' => true, 'referrer_reward_gems' => 20, 'referred_reward_gems' => 10, 'min_account_age_hours' => 1], true);
        $winter = $this->campaign($campaigns, $admin, 'demo-winter', 'تحدي الشتاء', ['rewards_enabled' => false, 'min_account_age_hours' => 1], false);

        $codes = app(ReferralCodeService::class);
        $attribution = app(ReferralAttributionService::class);
        $qualification = app(ReferralQualificationService::class);

        $a = $this->user('demo-e33-referrer-a@referrals.test', 'سلمى (محيلة)', 30);
        $b = $this->user('demo-e33-referrer-b@referrals.test', 'عمر (محيل)', 30);
        $c = $this->user('demo-e33-referrer-c@referrals.test', 'ريم (محيلة)', 30);
        $code = ['a' => $codes->ensureFor($a)->code, 'b' => $codes->ensureFor($b)->code, 'c' => $codes->ensureFor($c)->code];

        // (مفتاح، محيل، حملة، هل يحلّ أحجية، مصير)
        $plan = [
            ['p1', 'a', $main, false, null], ['p2', 'a', $winter, false, null], ['p3', 'b', $main, false, null], ['p4', 'b', null, false, null],
            ['q1', 'b', $winter, true, null], ['q2', 'b', $winter, true, null], ['q3', 'c', $winter, true, null],
            ['rw', 'a', $main, true, null],
            ['dq', 'a', $main, true, 'disqualify'],
            ['sus', 'c', $winter, true, 'suspicious'],
        ];

        $review = app(ReferralReviewService::class);

        foreach ($plan as [$key, $who, $campaign, $acts, $fate]) {
            $referred = $this->user("demo-e33-{$key}@referrals.test", 'مُحال '.strtoupper($key), 3);

            if (UserReferral::query()->where('referred_user_id', $referred->id)->exists()) {
                continue; // idempotent
            }

            if ($fate === 'suspicious') {
                // إشارتان ضعيفتان معًا (شبكة + جهاز مشتركان) ⇒ تعليق للمراجعة (لا حظر). قيم وهمية في نطاق الوثائق (TEST-NET).
                foreach ([$c, $referred] as $u) {
                    DeviceSighting::query()->updateOrCreate(['user_id' => $u->id, 'ip_address' => '203.0.113.77', 'device_hash' => str_repeat('d', 64)], ['last_seen_at' => now()]);
                }
            }

            $referral = $attribution->attribute($referred, $code[$who], $campaign?->slug);

            if ($referral !== null && $acts) {
                $qualification->onMeaningfulAction($referred);
            }

            if ($fate === 'disqualify' && $referral !== null) {
                $review->disqualify($admin, $referral->fresh(), 'fake_accounts', 'حالة تجريبية', true);
            }
        }

        $this->command?->info('DemoE33: إحالات='.UserReferral::query()->count().' · '.json_encode(UserReferral::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all()));
        $this->command?->info('حساب المشرف: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    protected function campaign(ReferralCampaignService $svc, User $admin, string $slug, string $name, array $extra, bool $default): ReferralCampaign
    {
        $c = ReferralCampaign::query()->where('slug', $slug)->first() ?? $svc->create($admin, ['name' => $name, 'slug' => $slug, 'is_public' => true] + $extra);

        if ($c->status === ReferralCampaign::STATUS_DRAFT) {
            $c = $svc->activate($admin, $c);
        }

        if ($default && ! $c->isDefault()) {
            $c = $svc->makeDefault($admin, $c);
        }

        return $c;
    }

    protected function user(string $email, string $name, int $daysOld): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('users')->where('id', $user->id)->update(['created_at' => now()->subDays($daysOld)->subHour()]);

        return $user->fresh();
    }
}
`````

## `docs/referrals-growth-e33.md`

`````markdown
# الإحالات ونمو المنصة (E33)

> منظومة نمو **خادمية الحكم** فوق ما هو قائم: روابط دعوة شخصية، حملات دعوة، مشاركة موحّدة، تأهيل لا يعتمد على التسجيل وحده، مكافآت اختيارية **معطّلة افتراضيًا**، وتحليلات اكتساب صادقة.
> **لا حزمة Composer/npm جديدة، لا بكسلات ولا سكربتات طرف ثالث، لا مال نقدي، لا مستويات (MLM)، لا قائمة صدارة علنية للإحالات.** E26 (التسجيل والأمان) لم يُعدَّل سلوكه؛ الإحالة تُسنَد بعد نجاح التسجيل وبمعزل عنه.

## المبادئ الصلبة

1. **الإسناد قرار الخادم.** النية (رمز + slug حملة اختياري + مصدر + وقت) لا تحمل معرّف مستخدم ولا مبلغًا؛ المحيل يُحلّ من قاعدة البيانات عند التسجيل.
2. **التسجيل وحده لا يؤهّل.** التأهيل يتطلب بريدًا موثَّقًا + عمر حساب أدنى + حلّ أحجية **رسمية** صحيحة (بعدد القاعدة) + غياب إشارات توقف المراجعة.
3. **لا إحالة ذاتية**، و**محيل واحد لكل حساب جديد** (`UNIQUE(referred_user_id)` في قاعدة البيانات)، و**لا يتغيّر المحيل** بعد الإسناد (لا مسار تحديث له أصلًا).
4. **لا طلب خام من العميل يمنح جواهر.** المكافأة تمرّ حصرًا عبر `CurrencyWalletService::creditPending` بمفتاح دلالي فريد في الدفتر.
5. **تشارك الشبكة/الجهاز وحده لا يحظر ولا يجمّد.** الإشارات اقتراحات؛ القرار البشري يتخذه مراجع بصلاحية `referrals.review` وسبب منظَّم.
6. **سقوف صارمة** للمكافآت (يومي/شهري/مدى الحياة) مع حدود مطلقة لا تتجاوزها حملة.
7. **الإحالات لا تغيّر نقاط اللعب ولا الترتيب** (لا XP ولا درجات ولا مستويات). **مال الرعاة لا يشتري ترتيبًا في الإحالات.** محتوى المجتمع يبقى صفر اقتصاد (حلّه لا يؤهّل إحالة).
8. **الخصوصية:** اللاعب يرى مجاميع فقط؛ لوحة الإدارة بلا بريد/IP/بصمة جهاز؛ الإشعارات بلا اسم المُحال.

## الرمز والرابط

| البند | القيمة |
|---|---|
| الصيغة | 6 محارف من `BCDFGHJKLMNPQRSTVWXZ2346789` (بلا حروف علة تمنع الكلمات المسيئة، وبلا `0/1/I/O` الملتبسة) ⇒ نحو 387 مليون احتمال |
| التوليد | خادمي، `random_int` (مصدر عشوائي تشفيري)، مخزَّن بالأحرف الكبيرة، يُطبَّع الإدخال (حروف صغيرة/مسافات) قبل البحث |
| التصادم | `UNIQUE(code)` + إعادة المحاولة (حتى 8) ثم خطأ نظيف `code_generation_failed` بلا صف مكرر |
| التدوير | الرمز القديم يُعطَّل فورًا (يبقى صفًّا بلا `active_for_user_id`)، حدّ 3 مرات/24 ساعة، يُقفل صف المستخدم لتسلسل الطلبات |
| الرابط | `/r/{code}` (GET، عام) — وجهة ثابتة من قائمة مغلقة `?to=register|home|puzzles|discover`؛ **لا URL من العميل أبدًا (لا open redirect)** |
| الرمز غير الصالح | استجابة مطابقة تمامًا للصالح (لا كشف وجود) + كاش سلبي 60 ث يمنع تخمين الرموز من إغراق القاعدة |
| المصادَق | يُحوَّل إلى الصفحة الرئيسية؛ **لا يُسنَد إليه شيء** |

## الإسناد (Attribution)

- `/r/{code}` أو `?ref=CODE` (+ `&c=slug`) على أي صفحة GET عامة لزائر **ضيف** ⇒ تُخزَّن النية في الجلسة وفي كوكي أول-طرف `ahj_ref` (مشفّر عبر `EncryptCookies`، `HttpOnly`، `SameSite=Lax`، `Secure` على HTTPS).
- **الصلاحية** `REFERRALS_TTL_DAYS` (افتراضيًا 14) وتُقصّ دائمًا إلى [7, 30] يومًا.
- **أول رمز صالح يربح:** نية قائمة صالحة لا تُستبدل. الرمز المعطَّل أو المنتهية مدته لا يُحتسب.
- `RegisteredUserController` يستدعي `ReferralAttributionService::attributeFromRequest` بعد نجاح التسجيل (لحسابات أُنشئت للتو فقط)، ثم تُحذف النية والكوكي. فشل الإسناد لا يكسر التسجيل أبدًا.
- ما يُرفض: إحالة ذاتية، محيل مجمَّد، محيل غير موثَّق البريد، رمز غير فعّال. حملة غير صالحة/موقوفة/منتهية/مجهولة **لا تكسر الإحالة**: تسقط إلى الحملة الافتراضية الحيّة أو لا حملة.
- سباق إسناد متوازٍ: `UNIQUE(referred_user_id)` + التقاط `UniqueConstraintViolationException` ⇒ الأول يربح ويعيد الخاسر صف الفائز بلا خطأ.

## النموذج والحالات

`user_referrals`: `pending → qualified → rewarded`، و`pending → pending_review → qualified | disqualified`، و`qualified/rewarded/pending → disqualified` (قرار بشري).

| الحالة | المعنى |
|---|---|
| `pending` | أُسنِدت؛ بانتظار التأهيل |
| `pending_review` | استوفت الشروط لكن تراكمت إشارات تستدعي مراجعة بشرية |
| `qualified` | مؤهَّلة (قد لا تحمل مكافأة) |
| `rewarded` | سُلّمت مكافأة جانب واحد على الأقل وكلها مسوّاة |
| `disqualified` | استبعدها مراجع بسبب منظَّم (`self_referral`, `fake_accounts`, `automation`, `policy_violation`, `duplicate_identity`, `other`) |

## التأهيل

القاعدة بمفتاح مغلق في `config/referrals.php`: `first_official_solve` (1، افتراضيًا) · `three_official_solves` (3) · `five_official_solves` (5). تُحسب **أحجيات رسمية مختلفة صحيحة** من جدول `puzzle_attempts` الحقيقي (لا عدّاد قابل للتزييف). أحجيات المجتمع لا تُحتسب.

- **الحدث:** `GameplayProgressionService::afterPuzzleSolved` ⇒ `ReferralQualificationService::onMeaningfulAction` (معزول بـ`try/catch` فلا يكسر اللعب). اللاعب غير المُحال يكلّف **استعلام قراءة واحدًا مفهرسًا** ولا كتابة.
- **عمر الحساب الأدنى:** `REFERRALS_MIN_ACCOUNT_AGE_HOURS` (افتراضيًا 1)، تُنسخ لقطته وقت الإسناد، وقد تتجاوزه الحملة ضمن [0, 720]. يُسجَّل `action_at` فورًا ويستكمل التأهيل `referrals:process` عند بلوغ العمر.
- **Idempotent:** تحت قفل صف الإحالة لا تُعالَج إلا `pending`؛ تكرار الحدث أو تزامنه لا يؤهّل مرتين.
- **ترتيب الأقفال ثابت** (إحالة ثم صف المحيل) لتسلسل سقوف المحيل دون دورة أقفال (deadlock). لا سياسة إعادة محاولة لـdeadlock هنا عمدًا (ليست مسارًا ساخنًا ومحصورة بمدقّق E31 P-2 على المسارات الأربعة المدقّقة)؛ يعيد `referrals:process` أي تأهيل لم يكتمل.

## المكافآت (اختيارية، معطّلة افتراضيًا)

تتطلب **شرطين معًا:** `REFERRALS_REWARDS_ENABLED=true` **و**حملة نشطة بمبالغ أكبر من صفر. وإلا تُحتسب الإحالة بلا جواهر (`reward_skip_reason`: `rewards_disabled` / `no_campaign` / `campaign_inactive` / `zero_amount`).

| البند | القيمة |
|---|---|
| الجانبان | محيل (`referrer_reward_gems`) ومُحال (`referred_reward_gems`) — كلٌّ مستقل |
| الحد الأقصى للجانب | 500 جوهرة (حماية من خطأ الإدخال) |
| السقوف (عدد إحالات مكافَأة لكل محيل) | افتراضي 5/يوم · 30/شهر · 100/مدى الحياة؛ الحملة قد تخفضها، **والحدود المطلقة 50/300/1000 لا تُتجاوز** |
| اللقطة | عند التأهيل تُنسخ المبالغ ومعرّف الحملة إلى `snap_*` ⇒ تعديل الحملة لاحقًا لا يغيّر تاريخ إحالة |
| الدفتر | `creditPending` بسبب `referral_reward` ومفتاح `referral-qualified:{id}:referrer|referred` (فريد في `currency_transactions`) |
| الحجز | الجواهر **معلّقة** وتصير متاحة بعد `gems.pending_hold_days` (5) عبر `PendingGemReleaseService` |
| التعثّر | فشل تسليم جانب لا يُرجع التأهيل؛ `referrals:process` يعيد المحاولة، والمفتاح الدلالي يمنع الازدواج حتى لو انهار التسليم بعد الائتمان وقبل تسجيله |
| الاستبعاد | **لا خصم صامتًا أبدًا** (لا يوجد API لإبطال معلّق). استبعاد إحالة سُلّمت مكافأتها يتطلب إقرارًا صريحًا من المراجع ويُدقَّق بـ`ledger_untouched` |

## الحملات

`referral_campaigns`: `draft → active ⇄ paused → ended`. حملة افتراضية واحدة كحدّ أقصى (`UNIQUE(default_slot)`: 1 للافتراضية وNULL لغيرها). اختيارية الربط بموسم (`season_id`، `SET NULL` عند حذفه) ⇒ تُضاف `c=slug` تلقائيًا لروابط مشاركة ذلك الموسم. كل إجراء إداري: صلاحية `referrals.manage` + حد معدّل لكل مشرف + تدقيق + تفريغ الكاش. النافذة الزمنية (`starts_at/ends_at`) تُقيَّم بالذاكرة وقت الاستعمال فلا تتأخر حدودها بسبب الكاش.

## المشاركة

- مكوّن واحد `<x-share-button>` على: الأحجية، أحجية المجتمع، السلسلة، المبدع، الموسم، الحملة، المنافسة، الداعم، وصفحة الدعوات.
- `navigator.share` عند توفره، وإلا «نسخ الرابط» (Clipboard API ثم احتياطي `execCommand`). واتساب وتيليجرام روابط عادية `rel="noopener noreferrer"`. **لا سكربت خارجي ولا صور ولا iframe.**
- الرابط الأساسي نظيف (canonical). «أضف رابط دعوتي» اختياري لمن لديه رمز نشط وبريد موثَّق وغير مجمَّد، ويُبنى في الخادم من أسماء مسارات مسماة فقط (`ShareLinkBuilder`) — لا host ولا URL من العميل.
- تتبّع المشاركة: `POST /referrals/share` لمصادَق موثَّق فقط، قوائم مغلقة، يعيد 204، لا يمنح شيئًا، وعدّادات كاش تقريبية قصيرة العمر.
- `<link rel="canonical">` و`og:url`/`og:site_name`/`og:locale` للصفحات العامة القابلة للمشاركة، **بلا `ref/c/utm`** مهما كان رابط الزيارة.

## صفحة اللاعب `/referrals`

للمصادَق الموثَّق فقط. تعرض الرابط والرمز وتدوير الرمز، وأعدادًا مجمّعة: مُسنَدة · قيد الانتظار · مؤهَّلة · جواهر من الدعوات (أو نقرات تقريبية)، وشرحًا صريحًا لقواعد الإحالة، وحملات الدعوة الجارية العلنية. **لا أسماء ولا بريد ولا بيانات عن المُحالين.**

## مكافحة الإساءة

إشارات (`ReferralRiskService`) تُخزَّن مفاتيح مغلقة في `risk_signals` فقط (لا IP ولا بصمة خام ولا بريد):

- **قوية (تعلّق فورًا):** `referrer_frozen` · `referrer_high_fraud_flag` · `referred_high_fraud_flag`.
- **ضعيفة (تعلّق عند اجتماع إشارتين فأكثر `hold_weak_threshold`):** `shared_ip` · `shared_device` · `similar_email` · `referrer_burst` · `referrer_daily_velocity` · `fast_action` · `referrer_open_flag` · `referred_open_flag`.
- التعليق = `pending_review` فقط: لا حظر ولا تجميد ولا تعديل للحسابات. المراجع يعتمد (`approve`) أو يستبعد (`disqualify`) بصلاحية `referrals.review`.

## الإدارة والتحليلات

- **الموارد (Filament، مجموعة «المشاركة»):** `ReferralCampaignResource` (إنشاء/تعديل/دورة الحياة) · `UserReferralResource` (جدول مراجعة قراءة فقط + اعتماد/استبعاد) · صفحة `ReferralAnalyticsPage` (`/admin/referral-analytics`، فترات 7/30/90).
- **الصلاحيات:** `referrals.view` / `referrals.manage` / `referrals.review` — المدير كلها، مدير المحتوى (عرض+إدارة)، المشرف (عرض+مراجعة). مسارات الإدارة بمعرّف عام `public_id` لا تسلسلي.
- **التحليلات «دفعات» (cohort):** نسبة التحويل = مؤهَّلة ÷ مُسنَدة **لنفس الفترة**؛ لا نسبة وهمية عند غياب بيانات (`null`). النقرات **تقريبية** (فريدة بالساعة، لا IP/UA مخزَّن) ولا تدخل في أي نسبة. أوائل المحيلين بعدد **المؤهَّلة فقط** وللمشرفين فقط؛ **لا لوحة صدارة علنية**. قرارات المراجعة تفرّغ كاش التحليلات فورًا، وغير ذلك TTL قصير (`analytics.cache_seconds`).

## الجداول والقيود (3 هجرات جديدة، لا تعديل لأي هجرة قديمة)

| الجدول | الحدود في قاعدة البيانات |
|---|---|
| `referral_campaigns` | `UNIQUE(slug)` · `UNIQUE(default_slot)` · `season_id`/`created_by` ⇒ `SET NULL` |
| `referral_codes` | `UNIQUE(code)` · `UNIQUE(active_for_user_id)` (NULL متعدد مسموح) · `user_id` ⇒ `CASCADE` |
| `user_referrals` | `UNIQUE(referred_user_id)` · المُحال ⇒ `CASCADE` · المحيل/الرمز/الحملة/المراجع ⇒ `SET NULL` |

أسماء كل الفهارس ≤ 64 حرفًا وأعمدة الجواهر أعداد صحيحة `unsigned` (لا float/decimal)، وتمرّ الهجرات الثلاث بمحلّل E31 (`MysqlDdlLinter`) بلا فشل أو تحذير، وفحص `db:parity --connection=mysql --runtime` PASS.

## الأوامر والجدولة

| الأمر | الغرض |
|---|---|
| `php artisan referrals:process` | يستكمل المنتظرة لعمر الحساب ويعيد تسليم جانب فشل (كل 30 دقيقة عبر المجدول المحروس). آمن للتكرار |
| `php artisan referrals:reconcile [--json]` | **قراءة فقط**: إحالة ذاتية، تكرار منطقي، مؤهَّلة بلا وقت، حالة/مكافأة غير متسقة، جانب مسلَّم بلا قيد دفتر، مبلغ مغاير للقطة، عدم تطابق حملة، رمز لغير المحيل. رمز الخروج 1 عند انحراف |
| `php artisan db:seed --class=DemoE33Seeder` | بيانات تجريبية (محلي فقط، يرفض الإنتاج): حملتان وعشر إحالات بحالات مختلفة. Idempotent |

## الإعدادات

`config/referrals.php` + متغيرات `.env` الاختيارية: `REFERRALS_ENABLED` · `REFERRALS_TTL_DAYS` · `REFERRALS_MIN_ACCOUNT_AGE_HOURS` · `REFERRALS_REWARDS_ENABLED` (افتراضيًا `false`) · `REFERRALS_NOTIFICATIONS`. أربعة محدِّدات معدّل: `referral-redirect` (60/د لكل IP) · `referral-dashboard` (30/د) · `referral-share` (30/د) · `referral-campaign-action` (30/د لكل مشرف).

## الأداء

| المسار | التكلفة |
|---|---|
| `/r/{code}` بارد | استعلامان للإحالة (بحث الرمز + عدّاد نقرات)؛ **دافئ: صفر** |
| رمز مجهول | استعلام واحد ثم كاش سلبي |
| `/referrals` | عدد ثابت لاستعلامات الإحالة، لا ينمو مع عدد المدعوّين |
| خطّاف اللعب (غير مُحال) | استعلام قراءة واحد، بلا كتابة |
| التحليلات | عدد ثابت (≤ 10 باردة)، **صفر دافئة**، بلا N+1 في جدول المراجعة |
| روابط المشاركة / slug الحملة / رمز المستخدم | كاش: صفر للضيف، وقراءة واحدة باردة للعضو |

## الاختبارات

`tests/Feature/ReferralE33/` — 80 اختبارًا مرقّمًا `AF1-01…AF12-80`: الرموز (1–6) · التوجيه (7–12) · التسجيل (13–18) · التأهيل (19–25) · المكافآت (26–37) · الاحتيال (38–44) · الحملات (45–51) · المشاركة (52–57) · الخصوصية (58–63) · التحليلات (64–69) · مخطط MySQL (70–74) · الأداء (75–80). تعمل كما هي على SQLite و`phpunit.mysql.xml.dist`، وخطوة `E33 referrals on MySQL` ضمن مهمة `mysql-parity` في CI.

## حدود معروفة ومؤجَّل

- **لا خصم تلقائي** لجواهر إحالة استُبعدت بعد تسليمها (قرار مقصود: لا API لإبطال المعلّق؛ تُعالَج يدويًا عبر تسوية المحفظة إن لزم).
- عدّادات المشاركة والنقرات **تقريبية** وتضيع بتفريغ الكاش.
- لا صورة مشاركة ديناميكية، ولا SDK/Pixel/تتبّع طرف ثالث، ولا مستويات، ولا لوحة صدارة علنية، ولا كشف تعلّم آلي — خارج النطاق عمدًا.
- كشف الحسابات المتعددة قائم على بصمات الجهاز/الشبكة الموجودة (E26) كإشارات فقط؛ لا KYC.
`````

## `resources/views/components/share-button.blade.php`

`````blade
@props(['kind', 'param' => null, 'title', 'text' => null, 'seasonId' => null, 'payload' => null])

{{--
    E33 (C): مكوّن «مشاركة» موحَّد (RTL، بلا مكتبات طرف ثالث ولا بكسلات ولا سكربت خارجي).
    - الرابط الأساسي قانوني نظيف؛ «مع رابط دعوتي» اختياري لمن لديه رمز نشط (يُبنى في الخادم ولا يُركَّب في المتصفح).
    - navigator.share عند توفره، وإلا «نسخ الرابط» (Clipboard API ثم احتياطي execCommand). واتساب/تيليجرام روابط عادية rel آمن.
    - تتبّع المشاركة اختياري ومحدود: POST بقوائم مغلقة للمصادَق فقط، يفشل بصمت، ولا يمنح شيئًا.
    - المصدر: ShareLinkBuilder من مسار مسمّى؛ لا URL من العميل.
--}}
@php
    $share = $payload ?? app(\App\Services\Referrals\ShareLinkBuilder::class)->build($kind, $param, (string) $title, $text, auth()->user(), $seasonId);
    $hasRef = $share['url_ref'] !== null;
@endphp

@if ((bool) config('referrals.enabled', true))
    <div {{ $attributes->class('glass rounded-2xl p-4') }} data-share-component data-share-kind="{{ $share['kind'] }}"
         x-data="{
            withRef: {{ $hasRef ? 'true' : 'false' }},
            copied: false,
            failed: false,
            canNative: (typeof navigator !== 'undefined' && typeof navigator.share === 'function'),
            payload: @js($share),
            get url() { return (this.withRef && this.payload.url_ref) ? this.payload.url_ref : this.payload.url; },
            get links() { return (this.withRef && this.payload.links.ref) ? this.payload.links.ref : this.payload.links.plain; },
            track(target) {
                @auth
                try {
                    fetch(@js(route('referrals.share')), {
                        method: 'POST', keepalive: true, credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || '' },
                        body: JSON.stringify({ kind: this.payload.kind, target: target, with_ref: this.withRef && !!this.payload.url_ref }),
                    }).catch(() => {});
                } catch (e) {}
                @endauth
            },
            async nativeShare() {
                try { await navigator.share({ title: this.payload.title, text: this.payload.text, url: this.url }); this.track('native'); } catch (e) { /* ألغى المستخدم */ }
            },
            async copy() {
                this.failed = false;
                try {
                    if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(this.url); }
                    else { const t = document.createElement('textarea'); t.value = this.url; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); const ok = document.execCommand('copy'); document.body.removeChild(t); if (!ok) { throw new Error('copy'); } }
                    this.copied = true; this.track('copy'); setTimeout(() => this.copied = false, 2500);
                } catch (e) { this.failed = true; }
            },
         }">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-bold text-slate-300 flex items-center gap-1.5"><x-ui-icon name="send" class="w-4 h-4" />مشاركة</span>

            <button type="button" x-show="canNative" x-cloak @click="nativeShare()" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-native>
                <x-ui-icon name="send" class="w-4 h-4" />شارك
            </button>

            <button type="button" @click="copy()" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-copy>
                <x-ui-icon name="check" class="w-4 h-4" x-show="copied" x-cloak /><span x-text="copied ? 'تم النسخ' : 'نسخ الرابط'">نسخ الرابط</span>
            </button>

            <a :href="links.whatsapp" target="_blank" rel="noopener noreferrer" @click="track('whatsapp')" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-whatsapp href="{{ $share['links']['plain']['whatsapp'] }}">واتساب</a>
            <a :href="links.telegram" target="_blank" rel="noopener noreferrer" @click="track('telegram')" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-telegram href="{{ $share['links']['plain']['telegram'] }}">تيليجرام</a>
        </div>

        @if ($hasRef)
            <label class="mt-3 flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
                <input type="checkbox" x-model="withRef" class="rounded border-white/20 bg-transparent focus:ring-amethyst" data-share-ref-toggle>
                أضف رابط دعوتي (يُنسب إليّ كل من ينضم عبره)
            </label>
        @elseif (auth()->check())
            <p class="mt-3 text-xs text-slate-500">لديك رابط دعوة شخصي؟ <a href="{{ route('referrals.index') }}" class="underline hover:text-white">افتح صفحة الدعوات</a> لتفعيله.</p>
        @endif

        <p class="sr-only" aria-live="polite" x-text="copied ? 'تم نسخ الرابط' : (failed ? 'تعذّر النسخ' : '')"></p>
        <p x-show="failed" x-cloak class="mt-2 text-xs text-rose">تعذّر النسخ تلقائيًا. انسخ الرابط يدويًا: <span dir="ltr" class="select-all break-all" x-text="url"></span></p>
    </div>
@endif
`````

## `resources/views/filament/pages/referral-analytics.blade.php`

`````blade
<x-filament-panels::page>
    @php $d = $this->data(); $o = $d['overview']; @endphp

    <div class="flex items-center gap-2 text-sm">
        <span>الفترة (دفعة الإحالات المُسنَدة خلالها):</span>
        @foreach ([7, 30, 90] as $opt)
            <button type="button" wire:click="$set('days', {{ $opt }})" class="px-3 py-1 rounded-lg border {{ $d['days'] === $opt ? 'bg-primary-600 text-white' : 'border-gray-300' }}">{{ $opt }} يومًا</button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" data-referral-admin-stats>
        @foreach ([
            'مُسنَدة' => $o['attributed'], 'بريد موثَّق' => $o['verified'], 'مؤهَّلة' => $o['qualified'], 'بانتظار المراجعة' => $o['under_review'],
            'مستبعدة' => $o['disqualified'], 'مكافَأة' => $o['rewarded'], 'تكلفة الجواهر المسلَّمة' => number_format($o['reward_cost_gems']),
            'نسبة التحويل' => $o['conversion_pct'] === null ? '—' : $o['conversion_pct'].'%',
        ] as $label => $value)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-bold mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">
        النقرات التقريبية (زائر فريد في الساعة، تراكمي مدى الحياة ولا تدخل في نسبة التحويل): {{ number_format($o['clicks_approx_lifetime']) }}
        · مشاركات آخر 7 أيام (عدّادات تقريبية): {{ number_format($o['shares_7d']['total']) }}.
        كل الأرقام أعلاه لدفعة الإحالات المُسنَدة في الفترة، فنسبة التحويل = مؤهَّلة ÷ مُسنَدة لنفس الدفعة.
    </p>

    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h3 class="font-bold mb-2">أداء الحملات</h3>
            <table class="w-full text-sm" data-referral-admin-campaigns>
                <thead><tr class="text-start text-gray-500"><th class="text-start">الحملة</th><th>مُسنَدة</th><th>مؤهَّلة</th><th>مكافَأة</th><th>جواهر</th></tr></thead>
                <tbody>
                @forelse ($d['campaigns'] as $row)
                    <tr class="border-t"><td>{{ $row['name'] }}</td><td class="text-center">{{ $row['attributed'] }}</td><td class="text-center">{{ $row['qualified'] }}</td><td class="text-center">{{ $row['rewarded'] }}</td><td class="text-center">{{ number_format($row['cost_gems']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-3">لا بيانات في هذه الفترة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <h3 class="font-bold mb-2">أوائل المحيلين (بعدد المؤهَّلة فقط · للمشرفين)</h3>
            <table class="w-full text-sm" data-referral-admin-top>
                <thead><tr><th class="text-start">المحيل</th><th>مؤهَّلة</th></tr></thead>
                <tbody>
                @forelse ($d['top'] as $row)
                    <tr class="border-t"><td>{{ $row['name'] }}</td><td class="text-center">{{ $row['qualified'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="text-gray-500 py-3">لا إحالات مؤهَّلة في هذه الفترة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
`````

## `resources/views/referrals/index.blade.php`

`````blade
@extends('layouts.app')

@section('title', 'دعوة الأصدقاء')
@section('meta_description', 'ادعُ أصدقاءك إلى أحجيات عبر رابطك الشخصي. الدعوات لا تغيّر الترتيب ولا نتائج اللعب.')

@section('content')
    <x-page-header title="دعوة الأصدقاء" subtitle="شارك رابطك الشخصي. تُحتسب الدعوة بعد أن يبدأ صديقك اللعب فعليًا." icon="friends" class="anim-fade-up">
        <x-slot:actions>
            <a href="{{ route('puzzles.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="puzzle" class="w-4 h-4" />الأحجيات</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold mb-4" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-bold mb-4" role="alert">{{ session('error') }}</div>
    @endif

    <section class="glass rounded-2xl p-5 md:p-6 mb-6 anim-fade-up d-1" data-referral-link>
        <h2 class="font-display font-black text-lg text-white mb-1">رابط دعوتك</h2>
        <p class="text-sm text-slate-400 mb-3">رمزك: <span class="font-mono font-black text-white tracking-widest" dir="ltr" data-referral-code>{{ $code }}</span></p>
        <input type="text" readonly value="{{ $link }}" dir="ltr" class="w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-slate-200 select-all" aria-label="رابط الدعوة" data-referral-url>
        <div class="mt-3"><x-share-button :payload="$share" kind="platform" title="انضم إلي في أحجيات" /></div>

        <form method="POST" action="{{ route('referrals.rotate') }}" class="mt-4" onsubmit="return confirm('سيتوقف الرمز الحالي عن إسناد دعوات جديدة. متابعة؟')">
            @csrf
            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">تغيير رمز الدعوة</button>
        </form>
    </section>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 anim-fade-up d-2" data-referral-stats>
        <x-stat-card label="دعوات مُسنَدة" :value="number_format($stats['total'])" icon="friends" tone="amethyst" />
        <x-stat-card label="قيد الانتظار" :value="number_format($stats['pending'])" icon="calendar" tone="gold" hint="بانتظار توثيق البريد وأول لعب" />
        <x-stat-card label="مؤهَّلة" :value="number_format($stats['qualified'])" icon="check-circle" tone="emerald" />
        @if ($rewardsOn || $stats['gems_earned'] > 0)
            <x-stat-card label="جواهر من الدعوات" :value="number_format($stats['gems_earned'])" icon="gift" tone="gold" hint="معلّقة ثم متاحة بعد فترة الحجز" />
        @else
            <x-stat-card label="نقرات تقريبية" :value="number_format($stats['clicks_approx'])" icon="chart" tone="amethyst" hint="زائر فريد في الساعة" />
        @endif
    </div>

    <section class="glass rounded-2xl p-5 md:p-6 mb-6 anim-fade-up d-3">
        <h2 class="font-display font-black text-lg text-white mb-2">كيف تعمل الدعوات؟</h2>
        <ul class="text-sm text-slate-300 space-y-1.5 list-disc ps-5">
            <li>لا تُحتسب الدعوة بالتسجيل وحده: يجب أن يوثّق صديقك بريده ويحلّ أحجية رسمية.</li>
            <li>حساب جديد له داعٍ واحد فقط، ولا يتغيّر بعد التأهيل. لا تُحتسب دعوة نفسك.</li>
            <li>الدعوات لا تغيّر ترتيبك ولا نقاط اللعب، ولا يشتري الرعاة ترتيبًا في الدعوات.</li>
            @if ($rewardsOn)
                <li>قد تمنح بعض الحملات جواهر عند التأهيل، بسقوف يومية وشهرية، وتبقى معلّقة فترة الحجز المعتادة. للمراجعة اليدوية الحق في تعليق مكافأة مشبوهة.</li>
            @else
                <li>لا مكافآت جواهر للدعوات حاليًا.</li>
            @endif
            <li>لا نعرض لك بيانات أصدقائك: أعدادًا مجمّعة فقط.</li>
        </ul>
    </section>

    @if ($campaigns->isNotEmpty())
        <x-section-header title="حملات الدعوة الجارية" class="anim-fade-up d-3" />
        <div class="grid gap-3 md:grid-cols-2 anim-fade-up d-4" data-referral-campaigns>
            @foreach ($campaigns as $campaign)
                <div class="glass rounded-2xl p-4">
                    <h3 class="font-bold text-white break-words">{{ $campaign->name }}</h3>
                    <p class="text-xs text-slate-400 mt-1">
                        @if ($campaign->ends_at) تنتهي {{ $campaign->ends_at->translatedFormat('j F Y') }} @else مفتوحة @endif
                        @if ($rewardsOn && $campaign->rewardsActive()) · مكافأة عند التأهيل @endif
                    </p>
                    <a href="{{ $link }}?c={{ $campaign->slug }}" dir="ltr" class="mt-2 block text-xs text-slate-300 underline break-all">{{ $link }}?c={{ $campaign->slug }}</a>
                </div>
            @endforeach
        </div>
    @endif
@endsection
`````

## `routes/referrals.php`

`````php
<?php

use App\Http\Controllers\Referrals\ReferralController;
use App\Http\Controllers\Referrals\ReferralLandingController;
use Illuminate\Support\Facades\Route;

/*
| E33 - الإحالات والنمو (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF).
|
| /r/{code}   عام (ضيف يلتقط النية؛ المصادَق يُوجَّه للرئيسية). GET فقط، لا يغيّر حالة المستخدم، محدود المعدّل (referral-redirect).
| /referrals  خاص بصاحبه (auth + verified + account.active). كل تعديل POST فقط بحدود معدّل مسماة.
*/

Route::get('/r/{code}', ReferralLandingController::class)->where('code', '[A-Za-z0-9]{1,32}')->middleware('throttle:referral-redirect')->name('referrals.landing');

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/referrals', [ReferralController::class, 'index'])->middleware('throttle:referral-dashboard')->name('referrals.index');
    Route::post('/referrals/rotate', [ReferralController::class, 'rotate'])->middleware('throttle:referral-dashboard')->name('referrals.rotate');
    Route::post('/referrals/share', [ReferralController::class, 'share'])->middleware('throttle:referral-share')->name('referrals.share');
});
`````

## `tests/Feature/ReferralE33/E33AnalyticsTest.php`

`````php
<?php

use App\Filament\Pages\ReferralAnalyticsPage;
use App\Models\PlayerProgression;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAnalytics;
use App\Services\Referrals\ReferralReviewService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

function e33_analytics(): ReferralAnalytics
{
    return app(ReferralAnalytics::class);
}

/** مزيج معروف: 2 معلّقة، 1 بانتظار المراجعة، 2 مؤهَّلة ومكافَأة، 1 مستبعدة. يعيد المحيل. */
function e33_mixed_funnel(): \App\Models\User
{
    e33_reward_campaign(default: true);
    $referrer = e33_user();

    e33_attr($referrer);
    e33_attr($referrer);

    [$suspect] = e33_attr($referrer);
    e33_share_network($referrer, $suspect);
    e33_act($suspect);

    e33_qualified($referrer);
    e33_qualified($referrer);

    [, $bad] = e33_attr($referrer);
    app(ReferralReviewService::class)->disqualify(e33_admin(), $bad, 'fake_accounts');

    return $referrer;
}

test('AF10-64: the funnel counts every status exactly, conversion is qualified over attributed for the same cohort, and an empty window shows no fake percentage', function () {
    // المجاميع نص SQL ثابت (لا استيفاء في SQL خام - تدقيق E26 W5) ويجب أن يغطي كل حالة معرّفة في النموذج بلا زيادة
    foreach (UserReferral::STATUSES as $status) {
        expect(ReferralAnalytics::STATUS_SUMS)->toContain("when status = '{$status}' then 1 else 0 end) as {$status}");
    }
    expect(substr_count(ReferralAnalytics::STATUS_SUMS, 'sum(case'))->toBe(count(UserReferral::STATUSES));

    expect(e33_analytics()->overview(30))->toMatchArray(['attributed' => 0, 'qualified' => 0, 'conversion_pct' => null, 'reward_cost_gems' => 0]);

    e33_mixed_funnel();
    e33_analytics()->forget();
    $o = e33_analytics()->overview(30);

    expect($o)->toMatchArray([
        'attributed' => 6, 'verified' => 6, 'pending' => 2, 'under_review' => 1, 'qualified' => 2, 'rewarded' => 2, 'disqualified' => 1,
        'conversion_pct' => 33.3, 'reward_cost_gems' => 60, // 2 إحالتين × (20 محيل + 10 مُحال) من لقطات الحملة المسلَّمة فقط
    ]);
    expect($o['pending'] + $o['under_review'] + $o['qualified'] + $o['disqualified'])->toBe($o['attributed']);
});

test('AF10-65: windows are cohorts of attribution date, so late qualification never inflates an older period and the day range is clamped', function () {
    $referrer = e33_user();
    [$old] = e33_attr($referrer);
    DB::table('user_referrals')->where('referred_user_id', $old->id)->update(['created_at' => now()->subDays(40)]);
    [$fresh] = e33_attr($referrer);

    e33_act($old); // تأهّلت اليوم لكن دفعتها عمرها 40 يومًا

    e33_analytics()->forget();
    $o30 = e33_analytics()->overview(30);
    $o90 = e33_analytics()->overview(90);

    expect($o30['attributed'])->toBe(1)->and($o30['qualified'])->toBe(0)->and($o30['conversion_pct'])->toBe(0.0)
        ->and($o90['attributed'])->toBe(2)->and($o90['qualified'])->toBe(1)->and($o90['conversion_pct'])->toBe(50.0)
        ->and(e33_analytics()->overview(0)['days'])->toBe(1)->and(e33_analytics()->overview(99999)['days'])->toBe(365)
        ->and(e33_analytics()->overview(-5)['days'])->toBe(1);

    expect(e33_analytics()->topReferrers(30))->toHaveCount(1)->and(e33_analytics()->topReferrers(30)[0]['qualified'])->toBe(1); // تأهّلت ضمن 30 يومًا
});

test('AF10-66: clicks are approximate, deduplicated per visitor and hour, and never feed conversion; shares appear only as short-lived counters', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);
    [$referred] = e33_attr($referrer);
    e33_act($referred);
    e33_analytics()->forget();
    $conversionBefore = e33_analytics()->overview(30)['conversion_pct'];

    for ($i = 0; $i < 5; $i++) {
        $this->flushSession();
        $this->get(route('referrals.landing', $code))->assertRedirect(route('register'));
    }
    expect(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(1); // نفس الزائر نفس الساعة

    foreach (['198.51.100.7', '198.51.100.8'] as $ip) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(route('referrals.landing', $code));
    }
    expect(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(3);

    $this->actingAs($referrer)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy', 'with_ref' => true])->assertNoContent();

    e33_analytics()->forget();
    $o = e33_analytics()->overview(30);

    expect($o['clicks_approx_lifetime'])->toBe(3)->and($o['conversion_pct'])->toBe($conversionBefore)->and($o['shares_7d']['total'])->toBe(1)
        ->and($o['shares_7d']['by_kind'])->toBe(['puzzle' => 1]);

    // الاكتساب لا يُخزّن IP ولا UA في أي جدول إحالة
    foreach (['referral_codes', 'user_referrals', 'referral_campaigns'] as $table) {
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        expect(array_intersect($cols, ['ip', 'ip_address', 'user_agent', 'ua', 'device_hash', 'fingerprint']))->toBe([]);
    }
});

test('AF10-67: top referrers rank by qualified referrals only and gems, clicks, signups, sponsors or XP never buy a place', function () {
    e33_reward_campaign(default: true);
    $steady = e33_user(['name' => 'ثابت']);
    $noisy = e33_user(['name' => 'ضجيج']);
    $disq = e33_user(['name' => 'مستبعد']);

    e33_qualified($steady);
    e33_qualified($steady);
    e33_qualified($steady);

    // «الضجيج»: أكثر تسجيلات ونقرات ومشاركات ورصيد، لكن مؤهَّلة واحدة فقط
    for ($i = 0; $i < 8; $i++) {
        e33_attr($noisy);
    }
    e33_qualified($noisy);
    ReferralCode::query()->where('active_for_user_id', $noisy->id)->update(['clicks_count' => 50000, 'shares_count' => 9000]);
    app(\App\Services\Economy\CurrencyWalletService::class)->creditPending($noisy, app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency(), 100000, 'admin_adjustment', null, 'e33-noisy-wallet');

    // «المستبعد»: إحالتان مؤهّلتان ثم استُبعدت إحداهما
    [, $d1] = e33_qualified($disq);
    e33_qualified($disq);
    app(ReferralReviewService::class)->disqualify(e33_admin(), $d1->fresh(), 'fake_accounts', null, true);

    e33_analytics()->forget();
    $top = e33_analytics()->topReferrers(30);

    // التعادل يُفضّ بمعرّف المحيل (حتمي)، لا بالنقرات ولا بالرصيد ولا بالتسجيلات
    expect(array_column($top, 'name'))->toBe(['ثابت', 'ضجيج', 'مستبعد'])->and(array_column($top, 'qualified'))->toBe([3, 1, 1]);
    expect(array_keys($top[0]))->toBe(['user_id', 'name', 'qualified']); // لا بريد ولا رصيد ولا نقرات

    config(['referrals.analytics.top_referrers' => 2]);
    e33_analytics()->forget();
    expect(e33_analytics()->topReferrers(30))->toHaveCount(2);

    // الإحالة لا تمسّ تقدّم اللاعب: لا XP ولا مستوى للمحيل من تأهيل مُحاليه
    $before = PlayerProgression::query()->where('user_id', $steady->id)->first()?->only(['total_xp', 'level_id']);
    [$extra] = e33_attr($steady);
    e33_act($extra);
    expect(PlayerProgression::query()->where('user_id', $steady->id)->first()?->only(['total_xp', 'level_id']))->toBe($before)
        ->and(DB::table('xp_transactions')->where('user_id', $steady->id)->count())->toBe(0);
});

test('AF10-68: campaign performance is grouped per campaign with cost from granted snapshots, plus a no-campaign bucket', function () {
    $main = e33_reward_campaign(['name' => 'حملة الشتاء'], default: true);
    $noReward = e33_campaign(['name' => 'حملة بلا جواهر']);
    $referrer = e33_user();

    e33_qualified($referrer);                   // الحملة الافتراضية: مكافأة 20+10
    e33_qualified($referrer);
    e33_qualified($referrer, $noReward);        // حملة بلا مكافأة
    e33_attr($referrer, $noReward);             // معلّقة
    app(\App\Services\Referrals\ReferralCampaignService::class)->pause(e33_admin(), $main);
    DB::table('user_referrals')->whereNull('referral_campaign_id')->count(); // لا إحالات بلا حملة بعد
    [$loose] = e33_attr($referrer, null);       // لا حملة افتراضية فاعلة الآن ⇒ بلا حملة
    expect(UserReferral::query()->where('referred_user_id', $loose->id)->value('referral_campaign_id'))->toBeNull();

    e33_analytics()->forget();
    $rows = collect(e33_analytics()->campaigns(30))->keyBy('name');

    expect($rows['حملة الشتاء'])->toMatchArray(['attributed' => 2, 'qualified' => 2, 'rewarded' => 2, 'cost_gems' => 60])
        ->and($rows['حملة بلا جواهر'])->toMatchArray(['attributed' => 2, 'qualified' => 1, 'rewarded' => 0, 'cost_gems' => 0])
        ->and($rows['بلا حملة'])->toMatchArray(['attributed' => 1, 'qualified' => 0, 'campaign_id' => null, 'cost_gems' => 0])
        ->and($rows->count())->toBe(3)
        ->and(collect(e33_analytics()->campaigns(30))->first()['name'])->toBe('حملة الشتاء'); // الأعلى تأهيلًا أولًا
});

test('AF10-69: analytics are cached for a short time, review decisions refresh them at once, and the admin page clamps the period and is read only', function () {
    $referrer = e33_mixed_funnel();
    e33_analytics()->forget();
    expect(e33_analytics()->overview(30)['attributed'])->toBe(6);

    e33_attr($referrer);
    expect(e33_analytics()->overview(30)['attributed'])->toBe(6); // ما زال من الكاش القصير

    $suspect = UserReferral::query()->where('status', UserReferral::STATUS_PENDING_REVIEW)->firstOrFail();
    app(ReferralReviewService::class)->approve(e33_admin(), $suspect);
    $after = e33_analytics()->overview(30);
    expect($after['attributed'])->toBe(7)->and($after['under_review'])->toBe(0)->and($after['qualified'])->toBe(3); // قرار المراجعة يفرّغ الكاش

    Cache::forget('x');
    $writes = DB::table('currency_transactions')->count() + UserReferral::query()->count();
    $this->flushSession();
    $this->actingAs(e33_admin());

    $page = Livewire::test(ReferralAnalyticsPage::class)->assertSuccessful()->set('days', 9999);
    expect($page->instance()->data()['days'])->toBe(30);
    $page->set('days', 7);
    expect($page->instance()->data()['days'])->toBe(7);

    foreach ([7, 30, 90] as $d) {
        expect($this->get('/admin/referral-analytics?days='.$d))->not->toBeNull();
    }

    expect(DB::table('currency_transactions')->count() + UserReferral::query()->count())->toBe($writes); // عرض فقط
});
`````

## `tests/Feature/ReferralE33/E33CampaignTest.php`

`````php
<?php

use App\Models\OperationalAuditLog;
use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF7-45: creating a campaign is validated and authorised, starts as a draft and is audited', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $ok = ['name' => 'حملة الاختبار', 'slug' => 'test-campaign'];

    foreach ([
        ['name' => ''], ['slug' => 'Bad Slug'], ['slug' => 'UPPER'], ['slug' => str_repeat('a', 65)], ['qualification_rule' => 'free_money'],
        ['starts_at' => '2026-12-10', 'ends_at' => '2026-12-01'], ['season_id' => 999999], ['min_account_age_hours' => 9999], ['referrer_reward_gems' => -1],
    ] as $bad) {
        expect(fn () => $svc->create($admin, $bad + $ok))->toThrow(ValidationException::class);
    }

    $c = $svc->create($admin, $ok);
    expect($c->status)->toBe(ReferralCampaign::STATUS_DRAFT)->and($c->public_id)->toHaveLength(26)->and($c->created_by)->toBe($admin->id)
        ->and(OperationalAuditLog::query()->where('action', 'referral_campaign_created')->count())->toBe(1)
        ->and(fn () => $svc->create($admin, $ok))->toThrow(ValidationException::class); // slug مكرر

    foreach ([e33_user(), e33_moderator()] as $nobody) {
        try {
            $svc->create($nobody, ['name' => 'x', 'slug' => 'x-'.$nobody->id]);
            $this->fail('forbidden expected');
        } catch (ReferralException $e) {
            expect($e->reason)->toBe('forbidden');
        }
    }
});

test('AF7-46: the lifecycle draft, active, paused, active, ended is enforced and the cache follows each change', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $c = e33_campaign(activate: false);

    expect(fn () => $svc->pause($admin, $c))->toThrow(ReferralException::class)
        ->and(fn () => $svc->resume($admin, $c))->toThrow(ReferralException::class)
        ->and($svc->resolve($c->slug))->toBeNull();

    $c = $svc->activate($admin, $c);
    expect($svc->resolve($c->slug)?->id)->toBe($c->id);
    expect(fn () => $svc->activate($admin, $c))->toThrow(ReferralException::class);

    $c = $svc->pause($admin, $c);
    expect($svc->resolve($c->slug))->toBeNull();

    $c = $svc->resume($admin, $c);
    expect($svc->resolve($c->slug)?->id)->toBe($c->id);

    $c = $svc->end($admin, $c);
    expect($c->status)->toBe(ReferralCampaign::STATUS_ENDED)->and($c->ended_at)->not->toBeNull()->and($svc->resolve($c->slug))->toBeNull()
        ->and(fn () => $svc->update($admin, $c, ['name' => 'جديد']))->toThrow(ReferralException::class)
        ->and(fn () => $svc->resume($admin, $c))->toThrow(ReferralException::class);

    expect(OperationalAuditLog::query()->whereIn('action', ['referral_campaign_activated', 'referral_campaign_paused', 'referral_campaign_resumed', 'referral_campaign_ended'])->count())->toBe(4);
});

test('AF7-47: only one campaign is the default, the database guarantees it, and pausing it clears the flag', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $a = e33_campaign(default: true);
    $b = e33_campaign();

    expect($svc->resolve(null)?->id)->toBe($a->id);

    $b = $svc->makeDefault($admin, $b);
    expect($a->fresh()->default_slot)->toBeNull()->and($b->fresh()->default_slot)->toBe(1)->and($svc->resolve(null)?->id)->toBe($b->id)
        ->and(ReferralCampaign::query()->where('default_slot', 1)->count())->toBe(1);

    expect(fn () => DB::table('referral_campaigns')->where('id', $a->id)->update(['default_slot' => 1]))->toThrow(QueryException::class);

    $draft = e33_campaign(activate: false);
    expect(fn () => $svc->makeDefault($admin, $draft))->toThrow(ReferralException::class);

    $svc->pause($admin, $b->fresh());
    expect($b->fresh()->default_slot)->toBeNull()->and($svc->resolve(null))->toBeNull();
});

test('AF7-48: ?c=slug selects the campaign and an invalid, paused or unknown slug never breaks the referral', function () {
    $svc = app(ReferralCampaignService::class);
    $admin = e33_admin();
    $default = e33_campaign(default: true);
    $special = e33_campaign();
    $paused = $svc->pause($admin, e33_campaign());
    $code = e33_code(e33_user());

    $cases = [[$special->slug, $special->id], ['nope', $default->id], [$paused->slug, $default->id], ['BAD SLUG!!', $default->id], [str_repeat('z', 200), $default->id]];

    foreach ($cases as $i => [$slug, $expected]) {
        $this->flushSession();
        $this->app['auth']->forgetGuards(); // التسجيل السابق فتح جلسة؛ كل دورة زائر ضيف جديد
        $this->get(route('referrals.landing', ['code' => $code, 'c' => $slug]))->assertRedirect(route('register'))->assertSessionHas('referral_intent.code', $code);
        e33_register("campaign{$i}@example.test");
        $ref = UserReferral::query()->whereHas('referred', fn ($q) => $q->where('email', "campaign{$i}@example.test"))->first();

        expect($ref)->not->toBeNull()->and($ref->referral_campaign_id)->toBe($expected);
    }

    // نفس المنطق عبر ?ref&c على صفحة عامة
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->get(route('puzzles.index', ['ref' => $code, 'c' => $special->slug]))->assertSessionHas('referral_intent.c', $special->slug)->assertSessionHas('referral_intent.s', 'share_param');
});

test('AF7-49: the campaign window decides whether it is live and the lookup is served from cache', function () {
    $svc = app(ReferralCampaignService::class);
    $future = e33_campaign(['starts_at' => now()->addDay()->toDateTimeString()]);
    $past = e33_campaign(['starts_at' => now()->subDays(3)->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString()]);
    $open = e33_campaign(['starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()]);

    expect($svc->resolve($future->slug))->toBeNull()->and($svc->resolve($past->slug))->toBeNull()->and($svc->resolve($open->slug)?->id)->toBe($open->id)
        ->and($future->isLive())->toBeFalse()->and($past->isLive())->toBeFalse()->and($open->isLive())->toBeTrue();

    \Carbon\Carbon::setTestNow(now()->addDays(2)->addMinute());
    expect($svc->resolve($future->slug)?->id)->toBe($future->id)->and($svc->resolve($open->slug))->toBeNull(); // الحدود تُقيَّم بالذاكرة لا بالكاش القديم
    \Carbon\Carbon::setTestNow();

    $svc->resolve($open->slug); // تسخين
    DB::enableQueryLog();
    for ($i = 0; $i < 20; $i++) {
        $svc->validSlug($open->slug);
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries)->toBe(0);
});

test('AF7-50: a campaign can be attached to a season, share links of that season carry its slug, and deleting the season keeps the campaign', function () {
    $season = Season::factory()->create();
    $campaign = e33_campaign(['season_id' => $season->id]);
    $viewer = e33_user();
    e33_code($viewer);

    $share = app(ShareLinkBuilder::class)->build('season', $season, 'موسم', null, $viewer, $season->id);
    expect($share['url_ref'])->toContain('ref='.e33_codes()->myCode($viewer))->toContain('c='.$campaign->slug)
        ->and($share['url'])->not->toContain('?');

    $other = app(ShareLinkBuilder::class)->build('season', $season, 'موسم', null, $viewer, 999999);
    expect($other['url_ref'])->not->toContain('c=');

    DB::table('seasons')->where('id', $season->id)->delete();
    expect($campaign->fresh())->not->toBeNull()->and($campaign->fresh()->season_id)->toBeNull();
});

test('AF7-51: admin actions are permission gated, policy gated and rate limited per admin', function () {
    $mod = e33_moderator();
    $manager = e33_manager();
    $admin = e33_admin();
    $player = e33_user();
    $c = e33_campaign(activate: false);

    expect(Gate::forUser($player)->allows('viewAny', ReferralCampaign::class))->toBeFalse()
        ->and(Gate::forUser($mod)->allows('viewAny', ReferralCampaign::class))->toBeTrue()
        ->and(Gate::forUser($mod)->allows('create', ReferralCampaign::class))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', ReferralCampaign::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $c))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $c))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', UserReferral::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', UserReferral::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', UserReferral::class))->toBeFalse();

    config(['referrals.limits.campaign_action_per_minute' => 3]);
    $svc = app(ReferralCampaignService::class);
    $svc->update($manager, $c, ['name' => 'a']);
    $svc->update($manager, $c, ['name' => 'b']);
    $svc->update($manager, $c, ['name' => 'c']);

    try {
        $svc->update($manager, $c, ['name' => 'd']);
        $this->fail('rate limit expected');
    } catch (ReferralException $e) {
        expect($e->reason)->toBe('rate_limited');
    }

    expect($c->fresh()->name)->toBe('c')
        ->and(\Illuminate\Support\Facades\RateLimiter::limiter('referral-campaign-action'))->not->toBeNull();

    // شاشات الإدارة تُرسم فعلًا (قائمة/إنشاء/تعديل) وتعرض الحملة ولا تنهار على استعلاماتها
    foreach (['/admin/referral-campaigns', '/admin/referral-campaigns/create', "/admin/referral-campaigns/{$c->public_id}/edit", '/admin/user-referrals'] as $url) {
        $this->flushSession();
        $this->actingAs($admin)->get($url)->assertOk();
    }

    $this->flushSession();
    $this->actingAs($player)->get('/admin/referral-campaigns')->assertForbidden();
});
`````

## `tests/Feature/ReferralE33/E33CodeTest.php`

`````php
<?php

use App\Models\ReferralCode;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralException;
use Illuminate\Database\QueryException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF1-01: the code is generated by the server - uppercase, fixed length, from the safe alphabet and without vowels', function () {
    $user = e33_user();
    $code = e33_code($user);

    expect($code)->toMatch('/^[BCDFGHJKLMNPQRSTVWXZ2346789]{6}$/')
        ->and(strtoupper($code))->toBe($code)
        ->and(preg_match('/[AEIOUY015]/', $code))->toBe(0);

    // الأبجدية المضبوطة نفسها بلا حروف علة ولا محارف ملتبسة (0/1/5/I/O) - حماية من كلمات مسيئة
    expect(preg_match('/[AEIOUY015]/', (string) config('referrals.code.alphabet')))->toBe(0);

    // آلاف التوليدات كلها بالصيغة (random_int)
    for ($i = 0; $i < 300; $i++) {
        expect(e33_codes()->generate())->toMatch('/^[BCDFGHJKLMNPQRSTVWXZ2346789]{6}$/');
    }
});

test('AF1-02: the code is stable - asking again returns the same single active code', function () {
    $user = e33_user();
    $first = e33_code($user);

    expect(e33_code($user))->toBe($first)
        ->and(e33_codes()->myCode($user))->toBe($first)
        ->and(ReferralCode::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(ReferralCode::query()->where('active_for_user_id', $user->id)->count())->toBe(1);
});

test('AF1-03: a collision is retried with a fresh code and never reuses someone elses code', function () {
    $owner = e33_user();
    $taken = e33_code($owner);
    $other = e33_user();

    $mock = Mockery::mock(ReferralCodeService::class)->makePartial();
    $mock->shouldReceive('generate')->andReturn($taken, $taken, 'XKCDQ7');
    $mock->shouldAllowMockingProtectedMethods();

    $created = $mock->ensureFor($other);

    expect($created->code)->toBe('XKCDQ7')
        ->and(ReferralCode::query()->where('code', $taken)->count())->toBe(1)
        ->and(ReferralCode::query()->where('code', $taken)->value('user_id'))->toBe($owner->id);

    // استنفاد المحاولات = خطأ مفهوم لا استثناء قاعدة بيانات
    config(['referrals.code.max_collision_retries' => 2]);
    $third = e33_user();
    $always = Mockery::mock(ReferralCodeService::class)->makePartial();
    $always->shouldReceive('generate')->andReturn($taken);

    expect(fn () => $always->ensureFor($third))->toThrow(ReferralException::class);
});

test('AF1-04: rotation disables the old code immediately and keeps it as an inactive row', function () {
    $user = e33_user();
    $old = e33_code($user);
    expect(e33_codes()->findActive($old))->not->toBeNull();

    $new = e33_codes()->rotate($user);

    expect($new->code)->not->toBe($old)
        ->and(e33_codes()->findActive($old))->toBeNull()
        ->and(e33_codes()->findActive($new->code))->not->toBeNull()
        ->and(e33_codes()->myCode($user))->toBe($new->code)
        ->and(ReferralCode::query()->where('code', $old)->first()->active_for_user_id)->toBeNull()
        ->and(ReferralCode::query()->where('code', $old)->first()->rotated_at)->not->toBeNull()
        ->and(ReferralCode::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('AF1-05: rotation is limited per day so the codes table cannot be flooded', function () {
    $user = e33_user();
    e33_code($user);

    for ($i = 0; $i < (int) config('referrals.code.max_rotations_per_day'); $i++) {
        e33_codes()->rotate($user);
    }

    try {
        e33_codes()->rotate($user);
        $this->fail('expected the rotation limit');
    } catch (ReferralException $e) {
        expect($e->reason)->toBe('rotation_limit');
    }

    expect(ReferralCode::query()->where('active_for_user_id', $user->id)->count())->toBe(1);
});

test('AF1-06: only well-formed codes are looked up and the database allows a single active code per user', function () {
    $user = e33_user();
    $code = e33_code($user);

    expect(e33_codes()->normalize(strtolower($code)))->toBe($code)
        ->and(e33_codes()->normalize(' '.$code.' '))->toBe($code)
        ->and(e33_codes()->normalize('ABC'))->toBeNull()
        ->and(e33_codes()->normalize('AEIOU1'))->toBeNull()
        ->and(e33_codes()->normalize(['BCDFGH']))->toBeNull()
        ->and(e33_codes()->normalize(null))->toBeNull()
        ->and(e33_codes()->normalize('BCDFG\'--'))->toBeNull()
        ->and(e33_codes()->findActive('BCDFGH'))->toBeNull();

    // الحارس الأخير بالقاعدة: رمز نشط ثانٍ لنفس المستخدم يُرفض
    expect(fn () => DB::table('referral_codes')->insert(['user_id' => $user->id, 'code' => 'ZZZZZZ', 'active_for_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});
`````

## `tests/Feature/ReferralE33/E33FraudTest.php`

`````php
<?php

use App\Models\FraudFlag;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralReviewService;
use App\Services\Referrals\ReferralRiskService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF6-38: a shared IP alone is only a signal - the referral still qualifies and nobody is flagged or frozen', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer);
    e33_share_network($referrer, $referred, ip: true, device: false);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($after->risk_signals)->toBe(['shared_ip'])
        ->and($referrer->fresh()->is_frozen)->toBeFalse()->and($referred->fresh()->is_frozen)->toBeFalse()
        ->and(FraudFlag::query()->count())->toBe(0);
});

test('AF6-39: a shared device fingerprint alone is only a signal too', function () {
    $referrer = e33_user();
    [$referred] = e33_attr($referrer);
    e33_share_network($referrer, $referred, ip: false, device: true);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)->and($after->risk_signals)->toBe(['shared_device'])->and(FraudFlag::query()->count())->toBe(0);
});

test('AF6-40: several weak signals together hold the referral and the reward for review - without freezing, flagging or punishing anyone', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_share_network($referrer, $referred);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)
        ->and($after->held_at)->not->toBeNull()
        ->and($after->qualified_at)->toBeNull()
        ->and($after->reward_status)->toBe(UserReferral::REWARD_NONE)
        ->and($after->risk_signals)->toEqualCanonicalizing(['shared_ip', 'shared_device'])
        ->and(e33_ledger())->toHaveCount(0)
        ->and($referrer->fresh()->is_frozen)->toBeFalse()->and($referred->fresh()->is_frozen)->toBeFalse()
        ->and(FraudFlag::query()->count())->toBe(0)
        ->and(\Illuminate\Notifications\DatabaseNotification::query()->count())->toBe(0);

    // لا تأهيل تلقائي لاحقًا للمعلّقة (لا بالحدث ولا بالأمر الدوري)
    e33_act($referred);
    $this->artisan('referrals:process')->assertSuccessful();
    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and(e33_ledger())->toHaveCount(0);
});

test('AF6-41: a strong signal holds the referral on its own - an open high fraud flag or a frozen referrer - while resolved or medium flags do not', function () {
    $svc = app(\App\Services\FraudDetectionService::class);

    $a = e33_user();
    $svc->flag($a, 'abnormal_earn_rate', 'high', 'x');
    [$u1] = e33_attr($a);
    $held = e33_act($u1);
    expect($held->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($held->risk_signals)->toContain('referrer_high_fraud_flag');

    $b = e33_user();
    $flag = $svc->flag($b, 'abnormal_earn_rate', 'high', 'x');
    $svc->resolve($flag, e33_admin(), 'ok');
    [$u2] = e33_attr($b);
    expect(e33_act($u2)->status)->toBe(UserReferral::STATUS_QUALIFIED);

    $c = e33_user();
    $svc->flag($c, 'ip_shared', 'medium', 'x');
    [$u3] = e33_attr($c);
    expect(e33_act($u3)->status)->toBe(UserReferral::STATUS_QUALIFIED);

    $d = e33_user();
    [$u4] = e33_attr($d);
    $d->forceFill(['is_frozen' => true])->save();
    $frozenHold = e33_act($u4);
    expect($frozenHold->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($frozenHold->risk_signals)->toContain('referrer_frozen')->and($d->fresh()->is_frozen)->toBeTrue();
});

test('AF6-42: a reviewer can approve a held referral once - the reward is then planned and delivered normally', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_share_network($referrer, $referred);
    e33_act($referred);
    $review = app(ReferralReviewService::class);

    expect(fn () => $review->approve(e33_user(), $ref->fresh()))->toThrow(ReferralException::class);
    expect(fn () => $review->approve(e33_manager(), $ref->fresh()))->toThrow(ReferralException::class); // مدير المحتوى بلا review

    $moderator = e33_moderator();
    $approved = $review->approve($moderator, $ref->fresh(), 'شبكة جامعة');

    expect($approved->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($approved->reviewed_by)->toBe($moderator->id)
        ->and($approved->review_note)->toBe('شبكة جامعة')
        ->and($approved->qualified_at)->not->toBeNull()
        ->and(e33_ledger())->toHaveCount(2)
        ->and(e33_wallet($referrer)['pending'])->toBe(20)
        ->and(OperationalAuditLog::query()->where('action', 'referral_review_approved')->count())->toBe(1);

    expect(fn () => $review->approve($moderator, $ref->fresh()))->toThrow(ReferralException::class);

    // غير الموقوفة لا تُعتمد
    [$plain] = e33_attr($referrer);
    expect(fn () => $review->approve($moderator, UserReferral::query()->where('referred_user_id', $plain->id)->first()))->toThrow(ReferralException::class);
});

test('AF6-43: disqualification needs a structured reason and never silently debits a rewarded referral', function () {
    $referrer = e33_user();
    $admin = e33_admin();
    $review = app(ReferralReviewService::class);

    [$rewardedUser, $rewarded] = e33_attr($referrer, e33_reward_campaign());
    e33_act($rewardedUser);
    $walletBefore = [e33_wallet($referrer), e33_wallet($rewardedUser)];
    $ledgerBefore = CurrencyTransactionCount();

    expect(fn () => $review->disqualify($admin, $rewarded->fresh(), 'not_a_reason'))->toThrow(ReferralException::class)
        ->and(fn () => $review->disqualify($admin, $rewarded->fresh(), 'fake_accounts'))->toThrow(ReferralException::class); // يلزم إقرار الإبقاء

    $done = $review->disqualify($admin, $rewarded->fresh(), 'fake_accounts', 'حسابات متكررة', true);

    expect($done->status)->toBe(UserReferral::STATUS_DISQUALIFIED)
        ->and($done->disqualified_reason)->toBe('fake_accounts')
        ->and($done->reviewed_by)->toBe($admin->id)
        ->and([e33_wallet($referrer), e33_wallet($rewardedUser)])->toBe($walletBefore)
        ->and(CurrencyTransactionCount())->toBe($ledgerBefore)
        ->and(e33_ledger())->toHaveCount(2); // الدفتر كما هو: لا خصم صامت
    $audit = OperationalAuditLog::query()->where('action', 'referral_review_disqualified')->latest('id')->first();
    expect($audit->metadata['ledger_untouched'])->toBeTrue()->and($audit->metadata['rewards_kept'])->toEqual(['referrer' => 20, 'referred' => 10]); // MySQL JSON يعيد ترتيب المفاتيح

    // لم تُكافأ بعد: الاستبعاد يوقف أي تسليم لاحق
    [$pendingUser, $pending] = e33_attr($referrer, e33_reward_campaign());
    $review->disqualify($admin, $pending->fresh(), 'policy_violation');
    e33_act($pendingUser);
    app(\App\Services\Referrals\ReferralRewardService::class)->deliver($pending->id);

    expect($pending->fresh()->reward_skip_reason)->toBe('disqualified')->and(e33_ledger())->toHaveCount(2)
        ->and(fn () => $review->disqualify($admin, $pending->fresh(), 'other'))->toThrow(ReferralException::class);
});

function CurrencyTransactionCount(): int
{
    return \App\Models\CurrencyTransaction::query()->count();
}

test('AF6-44: a burst of invitations plus a look-alike email hold the referral, and one signal alone does not', function () {
    $risk = app(ReferralRiskService::class);
    expect($risk->emailRoot('A.B+promo@Gmail.com'))->toBe('ab@gmail.com')
        ->and($risk->emailRoot('x.y@example.test'))->toBe('x.y@example.test')
        ->and($risk->emailRoot('x+1@example.test'))->toBe('x@example.test');

    $referrer = e33_user(['email' => 'ab@gmail.com']);
    foreach (range(1, 5) as $i) {
        e33_attr($referrer);
    }

    // السادسة بنفس الساعة (burst) وبريد مشابه (similar_email) ⇒ إشارتان ⇒ تعليق
    [$sixth, $ref] = e33_attr($referrer, null, ['email' => 'a.b+x@gmail.com']);
    $after = e33_act($sixth);
    expect($after->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($after->risk_signals)->toEqualCanonicalizing(['referrer_burst', 'similar_email']);

    // إجراء سريع جدًا وحده (fast_action) إشارة واحدة ⇒ يتأهل
    $solo = e33_user();
    [$fast] = e33_attr($solo, null, [], 0);
    DB::table('users')->where('id', $fast->id)->update(['created_at' => now()]);
    $quick = e33_act($fast->fresh());
    expect($quick->status)->toBe(UserReferral::STATUS_QUALIFIED)->and($quick->risk_signals)->toBe(['fast_action']);
});
`````

## `tests/Feature/ReferralE33/E33MysqlSchemaTest.php`

`````php
<?php

use App\Models\ReferralCampaign;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Database\MysqlDdlCompiler;
use App\Services\Database\MysqlDdlLinter;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralRewardService;
use App\Services\Referrals\ReferralReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| AF11 - حدود السلامة في قاعدة البيانات نفسها. كل الاختبارات سائق-محايدة (SQLite افتراضيًا و MySQL 8 عبر phpunit.mysql.xml.dist)،
| فتثبت أن القيود لا تعتمد على كود التطبيق وحده، وأن ما يُختبر هنا هو نفسه ما سيعمل في الإنتاج على MySQL.
*/

beforeEach(fn () => e33_seed());

const E33_TABLES = ['referral_campaigns', 'referral_codes', 'user_referrals'];

function e33_row_referral(int $referredId, int $referrerId): array
{
    return ['public_id' => (string) Str::ulid(), 'referred_user_id' => $referredId, 'referrer_user_id' => $referrerId, 'created_at' => now(), 'updated_at' => now()];
}

function e33_row_code(int $userId, string $code, ?int $active): array
{
    return ['user_id' => $userId, 'code' => $code, 'active_for_user_id' => $active, 'created_at' => now(), 'updated_at' => now()];
}

test('AF11-70: one referrer per account is enforced by the database, and the loser of a race returns the winner without error or duplicate', function () {
    $referrer = e33_user();
    $other = e33_user();
    [$referred] = e33_attr($referrer);

    expect(fn () => DB::table('user_referrals')->insert(e33_row_referral($referred->id, $other->id)))->toThrow(QueryException::class)
        ->and(UserReferral::query()->where('referred_user_id', $referred->id)->count())->toBe(1);

    // سباق حقيقي: بعد أن يقرأ الخادم «لا إحالة» يتسلّل فائز آخر قبل الكتابة
    $racer = e33_user();
    $winnerReferrer = e33_user();
    $loserCode = e33_code($other);
    $injected = false;

    DB::listen(function ($query) use (&$injected, $racer, $winnerReferrer) {
        if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'user_referrals')) {
            $injected = true;
            DB::table('user_referrals')->insert(e33_row_referral($racer->id, $winnerReferrer->id));
        }
    });

    $result = app(\App\Services\Referrals\ReferralAttributionService::class)->attribute($racer, $loserCode);

    expect($injected)->toBeTrue()->and($result)->not->toBeNull()->and($result->referrer_user_id)->toBe($winnerReferrer->id)
        ->and(UserReferral::query()->where('referred_user_id', $racer->id)->count())->toBe(1)
        ->and(UserReferral::query()->where('referred_user_id', $racer->id)->value('referrer_user_id'))->toBe($winnerReferrer->id); // الأول يربح ولا يتبدّل
});

test('AF11-71: a code belongs to one person, a member has at most one active code, rotated codes stay as inactive history', function () {
    $user = e33_user();
    $other = e33_user();
    $first = e33_code($user);

    expect(fn () => DB::table('referral_codes')->insert(e33_row_code($other->id, $first, null)))->toThrow(QueryException::class)       // رمز مكرر
        ->and(fn () => DB::table('referral_codes')->insert(e33_row_code($user->id, 'ZZZZZZ', $user->id)))->toThrow(QueryException::class); // رمز نشط ثانٍ

    // عدة صفوف NULL مسموحة (سلوك UNIQUE على MySQL وSQLite)
    foreach (['QQQQQ2', 'QQQQQ3', 'QQQQQ4'] as $c) {
        DB::table('referral_codes')->insert(e33_row_code($user->id, $c, null));
    }
    expect(ReferralCode::query()->where('user_id', $user->id)->whereNull('active_for_user_id')->count())->toBe(3);

    $svc = e33_codes();
    $second = $svc->rotate($user);
    $third = $svc->rotate($user);

    expect(ReferralCode::query()->where('user_id', $user->id)->whereNotNull('active_for_user_id')->count())->toBe(1)
        ->and(ReferralCode::query()->where('user_id', $user->id)->count())->toBe(6)
        ->and($svc->activeFor($user)->code)->toBe($third->code)
        ->and($svc->findActive($first))->toBeNull()->and($svc->findActive($second->code))->toBeNull()->and($svc->findActive($third->code))->not->toBeNull();

    expect(fn () => $svc->rotate($user))->not->toThrow(ReferralException::class); // الثالثة ضمن الحد
    expect(fn () => $svc->rotate($user))->toThrow(ReferralException::class);       // الرابعة خلال 24 ساعة ممنوعة
});

test('AF11-72: the default slot and slug are unique, and every foreign key has the deletion policy the design promises', function () {
    $a = e33_campaign();
    $b = e33_campaign();
    $c = e33_campaign();
    expect(ReferralCampaign::query()->whereNull('default_slot')->count())->toBe(3); // عدة NULL متعايشة

    expect(fn () => DB::table('referral_campaigns')->where('id', $b->id)->update(['slug' => $a->slug]))->toThrow(QueryException::class);

    DB::table('referral_campaigns')->where('id', $a->id)->update(['default_slot' => 1]);
    expect(fn () => DB::table('referral_campaigns')->where('id', $b->id)->update(['default_slot' => 1]))->toThrow(QueryException::class);

    $expected = [
        'user_referrals' => ['referred_user_id' => 'cascade', 'referrer_user_id' => 'set null', 'referral_code_id' => 'set null', 'referral_campaign_id' => 'set null', 'reviewed_by' => 'set null'],
        'referral_codes' => ['user_id' => 'cascade'],
        'referral_campaigns' => ['season_id' => 'set null', 'created_by' => 'set null'],
    ];

    foreach ($expected as $table => $columns) {
        $actual = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            $actual[$fk['columns'][0]] = strtolower((string) $fk['on_delete']);
        }

        ksort($actual);
        ksort($columns);
        expect($actual)->toBe($columns);
    }

    // السلوك الفعلي لا الإعلان فقط
    $admin = e33_admin();
    $referrer = e33_user();
    $campaign = e33_campaign();
    [, $ref] = e33_attr($referrer, $campaign);
    app(ReferralReviewService::class)->disqualify($admin, $ref, 'other');
    $codeId = $ref->fresh()->referral_code_id;

    DB::table('referral_codes')->where('id', $codeId)->delete();
    DB::table('referral_campaigns')->where('id', $campaign->id)->delete();
    DB::table('users')->where('id', $admin->id)->delete();

    $after = $ref->fresh();
    expect($after->referral_code_id)->toBeNull()->and($after->referral_campaign_id)->toBeNull()->and($after->reviewed_by)->toBeNull()
        ->and($after->status)->toBe(UserReferral::STATUS_DISQUALIFIED)->and($after->referrer_user_id)->toBe($referrer->id);
});

test('AF11-73: the three new tables pass the MySQL DDL linter: short identifiers, integer gems, JSON only where intended, named unique guards', function () {
    $lint = (new MysqlDdlLinter)->analyze((new MysqlDdlCompiler)->compile()['statements']);
    $mine = array_intersect_key($lint['tables'], array_flip(E33_TABLES));

    expect(array_keys($mine))->toEqualCanonicalizing(E33_TABLES);

    $fails = array_values(array_filter($lint['findings'], fn (array $f) => $f['level'] === 'fail' && in_array($f['table'], E33_TABLES, true)));
    expect($fails)->toBe([])->and($lint['summary']['fail'])->toBe(0);

    foreach ($mine as $table => $model) {
        foreach ($model['columns'] as $name => $column) {
            expect(in_array($column['base'], ['float', 'double', 'real', 'decimal', 'numeric'], true))->toBeFalse("{$table}.{$name} must not be fractional");
            expect(strlen($name))->toBeLessThanOrEqual(64);
        }

        foreach (array_keys($model['indexes']) as $index) {
            expect(strlen($index))->toBeLessThanOrEqual(64);
        }

        foreach ($model['fks'] as $fk) {
            expect(strlen($fk['name']))->toBeLessThanOrEqual(64);
        }
    }

    $idx = fn (string $t, string $n) => $mine[$t]['indexes'][$n] ?? null;
    expect($idx('user_referrals', 'user_referrals_referred_user_unique'))->toMatchArray(['unique' => true, 'columns' => ['referred_user_id']])
        ->and($idx('referral_codes', 'referral_codes_active_for_user_unique'))->toMatchArray(['unique' => true, 'columns' => ['active_for_user_id']])
        ->and($idx('referral_campaigns', 'referral_campaigns_default_slot_unique'))->toMatchArray(['unique' => true, 'columns' => ['default_slot']]);

    foreach ([['user_referrals', 'snap_referrer_gems'], ['user_referrals', 'snap_referred_gems'], ['referral_campaigns', 'referrer_reward_gems'], ['referral_campaigns', 'referred_reward_gems'], ['referral_codes', 'clicks_count']] as [$t, $c]) {
        expect($mine[$t]['columns'][$c]['type'])->toContain('int')->toContain('unsigned');
    }

    expect($mine['user_referrals']['columns']['risk_signals']['base'])->toBe('json');

    // الاختبارات نفسها تعمل في CI على MySQL حقيقي (خطوة مستقلة قبل فحص المخطط الحيّ)
    $ci = preg_replace('/^\s*#.*$/m', '', File::get(base_path('.github/workflows/ci.yml')));
    expect($ci)->toContain('--configuration=phpunit.mysql.xml.dist tests/Feature/ReferralE33')
        ->and(strpos($ci, 'tests/Feature/ReferralE33'))->toBeLessThan(strpos($ci, 'db:parity --connection=mysql --runtime'));

    // نطاق الهجرات: ثلاث جديدة بالضبط بعد آخر ترحيل سابق، ولا لمسة لهجرة قديمة
    $new = collect(File::files(database_path('migrations')))->map->getFilename()->filter(fn ($f) => str_starts_with($f, '2026_10_26_'))->values();
    expect($new->all())->toBe([
        '2026_10_26_000001_create_referral_campaigns_table.php',
        '2026_10_26_000002_create_referral_codes_table.php',
        '2026_10_26_000003_create_user_referrals_table.php',
    ]);
});

test('AF11-74: collision exhaustion fails cleanly, a crashed reward delivery is replayed without double credit, and MySQL tables are InnoDB utf8mb4', function () {
    // تصادم الرموز: أبجدية من حرف واحد ⇒ المستخدم الثاني يستنفد المحاولات برسالة نظيفة ولا يُنشأ صف مكرر
    config(['referrals.code.alphabet' => 'B', 'referrals.code.length' => 1, 'referrals.code.max_collision_retries' => 3]);
    $one = e33_user();
    $two = e33_user();
    expect(e33_code($one))->toBe('B');

    try {
        e33_codes()->ensureFor($two);
        $this->fail('code_generation_failed expected');
    } catch (ReferralException $e) {
        expect($e->reason)->toBe('code_generation_failed');
    }

    expect(ReferralCode::query()->count())->toBe(1)->and(ReferralCode::query()->where('user_id', $two->id)->exists())->toBeFalse();
    config(['referrals.code.alphabet' => 'BCDFGHJKLMNPQRSTVWXZ2346789', 'referrals.code.length' => 6]);

    // انهيار بعد الائتمان وقبل تسجيل التسليم: إعادة التسليم لا تضاعف الرصيد ولا الدفتر
    e33_reward_campaign(default: true);
    $referrer = e33_user();
    [$referred, $ref] = e33_qualified($referrer);
    $ledger = e33_ledger();
    $walletReferrer = e33_wallet($referrer);
    $walletReferred = e33_wallet($referred);
    expect($ledger)->toHaveCount(2)->and($walletReferrer['pending'])->toBe(20)->and($walletReferred['pending'])->toBe(10);

    DB::table('user_referrals')->where('id', $ref->id)->update(['referrer_granted_at' => null, 'referred_granted_at' => null, 'status' => UserReferral::STATUS_QUALIFIED, 'reward_status' => UserReferral::REWARD_PENDING]);
    app(ReferralRewardService::class)->deliver($ref->id);
    app(ReferralRewardService::class)->deliver($ref->id);

    expect(e33_ledger())->toHaveCount(2)->and(e33_wallet($referrer))->toBe($walletReferrer)->and(e33_wallet($referred))->toBe($walletReferred)
        ->and(UserReferral::query()->find($ref->id)->status)->toBe(UserReferral::STATUS_REWARDED);

    // مفتاح الدفتر هو الحارس الأخير على مستوى القاعدة
    $dup = e33_ledger()->first();
    expect(fn () => DB::table('currency_transactions')->insert(collect($dup->getAttributes())->except('id')->all()))->toThrow(QueryException::class);

    if (DB::getDriverName() === 'mysql') {
        $rows = DB::select('select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = database() and table_name in (?,?,?)', E33_TABLES);
        expect($rows)->toHaveCount(3);

        foreach ($rows as $row) {
            expect($row->e)->toBe('InnoDB')->and($row->c)->toStartWith('utf8mb4');
        }

        expect(DB::selectOne('select @@transaction_isolation as i')->i)->toBeString();
    }
});
`````

## `tests/Feature/ReferralE33/E33PerformanceTest.php`

`````php
<?php

use App\Filament\Resources\UserReferralResource\Pages\ListUserReferrals;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAnalytics;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralQualificationService;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| AF12 - الأداء بالأعداد لا بالزمن: عدد الاستعلامات (والكتابات) ثابت لا يكبر مع حجم البيانات، والمسارات الساخنة تُخدَم من الكاش.
| الأعداد هنا حدود مرتفعة قليلًا فوق القياس الفعلي لتبقى مستقرة، وما يهم هو **عدم النمو** (مقارنة حجمين) لا الرقم المطلق.
*/

beforeEach(fn () => e33_seed());

/** @return array{total: int, writes: int, referral: int} */
function e33_q(Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return [
        'total' => count($log),
        'writes' => count(array_filter($log, fn ($q) => ! str_starts_with(strtolower(ltrim($q['query'])), 'select'))),
        'referral' => count(array_filter($log, fn ($q) => str_contains($q['query'], 'referral'))),
    ];
}

function e33_guest_visit(string $url): array
{
    test()->flushSession();
    app('auth')->forgetGuards();

    return e33_q(fn () => test()->get($url));
}

test('AF12-75: the redirect route costs a bounded handful of queries when cold and none when warm, whatever the amount of data', function () {
    $small = e33_user();
    $codeSmall = e33_code($small);
    Cache::flush();
    $cold = e33_guest_visit(route('referrals.landing', $codeSmall));
    $warm = e33_guest_visit(route('referrals.landing', $codeSmall));

    expect($cold['referral'])->toBeLessThanOrEqual(2)->and($cold['writes'])->toBeLessThanOrEqual(3)->and($warm['referral'])->toBe(0)->and($warm['total'])->toBe(0);

    // بيانات أكبر بكثير ⇒ نفس العدد
    for ($i = 0; $i < 40; $i++) {
        e33_attr($small);
        e33_code(e33_user());
    }
    $big = e33_user();
    $codeBig = e33_code($big);
    Cache::flush();
    $coldBig = e33_guest_visit(route('referrals.landing', $codeBig));

    // (المجموع الكلي يتضمن تتبّع بصمة الجهاز الموجود سابقًا ويتفاوت بين الزيارة الأولى والتالية؛ المقياس هنا استعلامات الإحالة)
    expect($coldBig['referral'])->toBe($cold['referral'])->and($coldBig['total'])->toBeLessThanOrEqual($cold['total']);

    // رمز غير موجود: استعلام واحد ثم كاش سلبي (لا إغراق قاعدة البيانات بتخمين الرموز)
    $first = e33_guest_visit(route('referrals.landing', 'ZZZZZZ'));
    $again = e33_guest_visit(route('referrals.landing', 'ZZZZZZ'));
    expect($first['referral'])->toBe(1)->and($first['writes'])->toBeLessThanOrEqual(2)->and($again['referral'])->toBe(0);
});

test('AF12-76: the dashboard issues a constant number of referral queries and the total does not grow with the number of invited people', function () {
    $measure = function (int $invited) {
        $referrer = e33_user();

        for ($i = 0; $i < $invited; $i++) {
            [$u] = e33_attr($referrer);
            $i % 3 === 0 && e33_act($u);
        }

        e33_code($referrer);
        Cache::flush();
        $this->flushSession();

        return e33_q(fn () => $this->actingAs($referrer)->get(route('referrals.index'))->assertOk());
    };

    $few = $measure(2);
    $many = $measure(30);

    expect($many['referral'])->toBe($few['referral'])->and($few['referral'])->toBeLessThanOrEqual(6)
        ->and($many['total'])->toBeLessThanOrEqual($few['total'])->and($many['total'])->toBeLessThanOrEqual(40)->and($many['writes'])->toBeLessThanOrEqual($few['writes']);
});

test('AF12-77: the gameplay hook costs one indexed read for a non-referred player and nothing for an already settled referral', function () {
    $svc = app(ReferralQualificationService::class);
    $plain = e33_user();

    $q = e33_q(fn () => $svc->onMeaningfulAction($plain));
    expect($q['total'])->toBe(1)->and($q['writes'])->toBe(0);

    $referrer = e33_user();
    [$referred] = e33_attr($referrer);
    e33_act($referred);
    expect(UserReferral::query()->where('referred_user_id', $referred->id)->value('status'))->toBeIn(UserReferral::QUALIFIED_STATUSES);

    $again = e33_q(fn () => $svc->onMeaningfulAction($referred));
    expect($again['total'])->toBeLessThanOrEqual(1)->and($again['writes'])->toBe(0);

    // الحلّ الفعلي لأحجية لاعب غير مُحال لا يزيد سوى هذا الاستعلام الواحد
    $puzzle = \App\Models\Puzzle::factory()->create(['answer_raw' => 'صح']);
    $player = e33_user();
    $withHook = e33_q(fn () => e33_solve($player, $puzzle));
    expect(collect([$withHook])->sum('referral'))->toBe(1);
});

test('AF12-78: analytics and the admin review table use a fixed number of queries, are served from cache when warm, and avoid N+1', function () {
    $analytics = app(ReferralAnalytics::class);
    $all = fn () => e33_q(function () use ($analytics) {
        $analytics->overview(30);
        $analytics->campaigns(30);
        $analytics->topReferrers(30);
    });

    $referrer = e33_user();
    e33_reward_campaign(default: true);

    for ($i = 0; $i < 3; $i++) {
        e33_qualified($referrer);
    }
    $analytics->forget();
    $small = $all();

    for ($i = 0; $i < 40; $i++) {
        $r = e33_user();
        e33_qualified($r);
        e33_attr($r);
    }
    $analytics->forget();
    $large = $all();
    $warm = $all();

    expect($small['total'])->toBeLessThanOrEqual(10)->and($large['total'])->toBe($small['total'])->and($warm['total'])->toBe(0);

    // جدول المراجعة: تحميل مسبق للعلاقات (لا استعلام لكل صف)
    $admin = e33_admin();
    $this->actingAs($admin);
    $rowsQueries = function (int $keep) {
        $ids = UserReferral::query()->orderBy('id')->limit($keep)->pluck('id')->all();
        DB::table('user_referrals')->whereNotIn('id', $ids)->update(['status' => 'disqualified']); // لا حذف: نُبقي الجدول بحجمه ونغيّر حالته فقط

        return e33_q(fn () => Livewire::test(ListUserReferrals::class)->assertSuccessful());
    };

    $fewRows = $rowsQueries(5);
    $manyRows = $rowsQueries(100);

    expect($manyRows['total'])->toBeLessThanOrEqual($fewRows['total'] + 2);
});

test('AF12-79: share links, codes and campaigns are cached so public pages add no queries for guests and at most one cold read for members', function () {
    $svc = app(ReferralCampaignService::class);
    $builder = app(ShareLinkBuilder::class);
    $campaign = e33_campaign(default: true);
    $member = e33_user();
    $code = e33_code($member);

    $guest = e33_q(fn () => $builder->build('platform', null, 'عنوان'));
    expect($guest['total'])->toBe(0);

    Cache::flush();
    $cold = e33_q(fn () => $builder->build('platform', null, 'عنوان', null, $member));
    $warm = e33_q(fn () => $builder->build('platform', null, 'عنوان', null, $member));
    expect($cold['total'])->toBeLessThanOrEqual(1)->and($warm['total'])->toBe(0);

    // المسارات الساخنة (كل زيارة /r و?ref وكل مشاركة موسم) تقرأ من صفوف الكاش الخفيفة: صفر استعلام
    $svc->validSlug($campaign->slug);
    $loop = e33_q(function () use ($svc, $campaign) {
        for ($i = 0; $i < 25; $i++) {
            $svc->validSlug($campaign->slug);
            $svc->validSlug('unknown-slug');
            $svc->slugForSeason(999999);
        }
    });
    expect($loop['total'])->toBe(0);

    // حلّ الحملة الكاملة يحدث مرة واحدة عند التسجيل فقط: قراءة واحدة بالمفتاح الأساسي لا غير
    $resolve = e33_q(fn () => $svc->resolve($campaign->slug));
    expect($resolve['total'])->toBeLessThanOrEqual(1);

    $codes = e33_codes();
    $codes->findActive($code);
    $found = e33_q(function () use ($codes, $code) {
        for ($i = 0; $i < 25; $i++) {
            $codes->findActive($code);
            $codes->findActive('NOPE22');
        }
    });
    expect($found['total'])->toBeLessThanOrEqual(1); // الرمز المجهول يقرأ مرة ثم يُخزَّن سلبيًا

    // صفحة عامة تحمل مكوّن المشاركة: الضيف لا يدفع أي استعلام إضافي مقابل المكوّن
    $puzzle = \App\Models\Puzzle::factory()->create(['is_active' => true]);
    $this->flushSession();
    app('auth')->forgetGuards();
    $page = e33_q(fn () => $this->get(route('puzzles.show', $puzzle))->assertOk());
    expect($page['referral'])->toBe(0);
});

test('AF12-80: the scheduled processor scales linearly per item and the reconcile command is read only with a query count independent of table size', function () {
    $seed = function (int $n) {
        $referrer = e33_user();

        for ($i = 0; $i < $n; $i++) {
            [$u, $ref] = e33_attr($referrer);
            DB::table('user_referrals')->where('id', $ref->id)->update(['action_at' => now()->subHours(3)]); // أول إجراء مسجَّل ينتظر التأهيل
        }
    };

    $seed(10);
    $q10 = e33_q(fn () => $this->artisan('referrals:process')->assertExitCode(0));
    expect(UserReferral::query()->where('status', UserReferral::STATUS_PENDING)->count())->toBe(0);

    $seed(40);
    $q40 = e33_q(fn () => $this->artisan('referrals:process')->assertExitCode(0));
    expect(UserReferral::query()->where('status', UserReferral::STATUS_PENDING)->count())->toBe(0);

    $perItem = ($q40['total'] - $q10['total']) / 30;
    expect($perItem)->toBeLessThanOrEqual(40)->and($q40['total'])->toBeLessThan($q10['total'] * 6);

    // إعادة التشغيل لا تعيد شيئًا (idempotent) وبلا كتابة
    $rerun = e33_q(fn () => $this->artisan('referrals:process')->assertExitCode(0));
    expect($rerun['writes'])->toBe(0);

    // المطابقة: قراءة فقط، وعدد الاستعلامات لا يتبع حجم الجدول على بيانات سليمة
    $small = e33_q(fn () => $this->artisan('referrals:reconcile')->assertExitCode(0));
    for ($i = 0; $i < 50; $i++) {
        e33_qualified(e33_user());
    }
    $large = e33_q(fn () => $this->artisan('referrals:reconcile')->assertExitCode(0));

    expect($small['writes'])->toBe(0)->and($large['writes'])->toBe(0)->and($large['total'])->toBeLessThanOrEqual($small['total'] + 2);
});
`````

## `tests/Feature/ReferralE33/E33PrivacyTest.php`

`````php
<?php

use App\Filament\Resources\UserReferralResource\Pages\ListUserReferrals;
use App\Models\OperationalAuditLog;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralReviewService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF9-58: the personal dashboard shows aggregate numbers only, never names, emails or addresses of invited people', function () {
    $referrer = e33_user(['name' => 'المحيل الظاهر']);
    $invited = [];

    foreach (['سري-واحد', 'سري-اثنان', 'سري-ثلاثة'] as $i => $secret) {
        [$referred] = e33_attr($referrer, null, ['name' => "مدعو {$secret}", 'email' => "invited-{$i}@private.example"]);
        $invited[] = $referred;
    }

    e33_act($invited[0]);

    $html = $this->actingAs($referrer)->get(route('referrals.index'))->assertOk()->getContent();

    foreach ($invited as $i => $u) {
        expect($html)->not->toContain($u->name)->not->toContain("invited-{$i}@private.example")->not->toContain((string) $u->public_id);
    }

    expect($html)->not->toContain('203.0.113.')->not->toContain('device_hash');
    expect(app(\App\Services\Referrals\ReferralAnalytics::class)->forReferrer($referrer))
        ->toMatchArray(['total' => 3, 'qualified' => 1, 'pending' => 2]);

    // لاعب آخر لا يرى شيئًا من هذا (المعرّف مأخوذ من الجلسة لا من الرابط)
    $stranger = e33_user();
    $this->actingAs($stranger)->get(route('referrals.index', ['user' => $referrer->id, 'user_id' => $referrer->id, 'referrer' => $referrer->id]))->assertOk();
    expect(app(\App\Services\Referrals\ReferralAnalytics::class)->forReferrer($stranger)['total'])->toBe(0);
});

test('AF9-59: an invited person never learns who invited them and the stored intent carries no personal data', function () {
    $referrer = e33_user(['name' => 'اسم المحيل السري', 'email' => 'referrer-secret@private.example']);
    $code = e33_code($referrer);

    $land = $this->get(route('referrals.landing', $code))->assertRedirect(route('register'));
    expect($land->getContent())->not->toContain('اسم المحيل السري')->not->toContain('referrer-secret@private.example');

    $intent = session('referral_intent');
    expect(array_keys($intent))->toEqualCanonicalizing(['code', 'c', 's', 'at'])->and(json_encode($intent))->not->toContain((string) $referrer->id.',');

    $page = $this->get(route('register'))->assertOk()->getContent();
    expect($page)->not->toContain('اسم المحيل السري')->not->toContain('referrer-secret@private.example');

    e33_register('guest-new@example.test');
    $referred = \App\Models\User::query()->where('email', 'guest-new@example.test')->firstOrFail();
    DB::table('users')->where('id', $referred->id)->update(['email_verified_at' => now()]);
    $html = $this->actingAs($referred->fresh())->get(route('referrals.index'))->assertOk()->getContent();

    expect($html)->not->toContain('اسم المحيل السري')->not->toContain('referrer-secret@private.example')
        ->and(app(\App\Services\Referrals\ReferralAnalytics::class)->forReferrer($referred)['total'])->toBe(0);
});

test('AF9-60: the admin review screens and analytics expose names and closed signal labels but never email, IP or device fingerprints', function () {
    $referrer = e33_user(['name' => 'محيل للمراجعة', 'email' => 'ref-admin-view@private.example']);
    [$referred] = e33_attr($referrer, null, ['name' => 'مُحال للمراجعة', 'email' => 'referred-admin-view@private.example']);
    e33_share_network($referrer, $referred);
    $referral = e33_act($referred);
    expect($referral->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($referral->risk_signals)->toContain('shared_ip', 'shared_device');

    $forbidden = ['ref-admin-view@private.example', 'referred-admin-view@private.example', '203.0.113.10', str_repeat('a', 64)];
    $admin = e33_admin();
    $this->actingAs($admin);

    $list = $this->get('/admin/user-referrals')->assertOk()->getContent();
    expect($list)->toContain('محيل للمراجعة')->toContain('تشارك شبكة')->toContain('تشارك جهاز');

    $component = Livewire::test(ListUserReferrals::class)->assertSuccessful()->assertSee('مُحال للمراجعة');

    foreach ($forbidden as $secret) {
        expect($list)->not->toContain($secret);
        $component->assertDontSee($secret);
    }

    $analytics = $this->get('/admin/referral-analytics')->assertOk()->getContent();

    foreach ($forbidden as $secret) {
        expect($analytics)->not->toContain($secret);
    }
});

test('AF9-61: stored signals, audit entries and notifications contain closed keys and counts, not identities', function () {
    e33_reward_campaign(default: true);
    $referrer = e33_user(['name' => 'محيل الإشعار', 'email' => 'notify-ref@private.example']);
    [$referred, $referral] = e33_attr($referrer, null, ['name' => 'مُحال الإشعار', 'email' => 'notify-referred@private.example']);
    $referral = e33_act($referred);
    expect($referral->status)->toBeIn(UserReferral::QUALIFIED_STATUSES);

    $notes = DatabaseNotification::query()->whereIn('type_key', ['referral_qualified', 'referral_reward_received'])->get();
    expect($notes)->not->toBeEmpty();

    foreach ($notes as $n) {
        $blob = json_encode($n->data, JSON_UNESCAPED_UNICODE);
        expect($blob)->not->toContain('مُحال الإشعار')->not->toContain('محيل الإشعار')->not->toContain('notify-referred@private.example')->not->toContain('notify-ref@private.example');
    }

    // إشارات مخاطر: مفاتيح مغلقة فقط (لا IP ولا بصمة ولا بريد)
    [$suspect] = e33_attr($referrer, null, ['email' => 'suspect-sig@private.example']);
    e33_share_network($referrer, $suspect);
    $flagged = e33_act($suspect);
    $known = array_keys(\App\Filament\Resources\UserReferralResource::SIGNAL_LABELS);
    expect($flagged->risk_signals)->not->toBeEmpty()->and(array_diff($flagged->risk_signals, $known))->toBe([])
        ->and(json_encode($flagged->risk_signals))->not->toContain('203.0.113')->not->toContain('@');

    // قرار المراجعة يُدقَّق بمعرّفات وأسباب مغلقة لا بيانات شخصية
    app(ReferralReviewService::class)->disqualify(e33_admin(), $flagged->fresh(), 'fake_accounts', 'ملاحظة داخلية');
    $audit = OperationalAuditLog::query()->where('action', 'referral_review_disqualified')->latest('id')->firstOrFail();
    $meta = json_encode($audit->metadata ?? $audit->meta ?? [], JSON_UNESCAPED_UNICODE);
    expect($meta)->not->toContain('suspect-sig@private.example')->not->toContain('203.0.113')->not->toContain(str_repeat('a', 64));
});

test('AF9-62: there is no public referral ranking, every referral route except the landing needs a signed-in member, and analytics are staff only', function () {
    $referral = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'referrals.'));

    expect($referral->map->getName()->sort()->values()->all())->toBe(['referrals.index', 'referrals.landing', 'referrals.rotate', 'referrals.share']);

    foreach ($referral as $r) {
        $mw = $r->gatherMiddleware();

        if ($r->getName() !== 'referrals.landing') {
            expect($mw)->toContain('auth')->toContain('verified');
        } else {
            expect($mw)->not->toContain('auth');
        }
    }

    foreach (Route::getRoutes()->getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'admin')) {
            expect(strtolower($r->uri()))->not->toMatch('#referral.*(leader|ranking|top)|(leader|ranking|top).*referral#');
        }
    }

    foreach (['/referrals/leaderboard', '/leaderboard/referrals', '/referrals/top', '/referrers'] as $url) {
        $this->get($url)->assertNotFound(); // لا مسار علني ولا خاص لقائمة صدارة إحالات
    }

    $this->get(route('referrals.index'))->assertRedirect(route('login'));
    $unverified = e33_user(['email_verified_at' => null]);
    $this->actingAs($unverified)->get(route('referrals.index'))->assertRedirect(route('verification.notice'));

    // لوحة Filament تستعمل AuthenticateSession: تبديل المستخدم داخل جلسة واحدة يُسقطها، فنبدأ جلسة نظيفة لكل دور
    foreach ([[e33_user(), 403], [e33_moderator(), 200], [e33_admin(), 200]] as [$who, $status]) {
        $this->flushSession();
        $this->actingAs($who)->get('/admin/referral-analytics')->assertStatus($status);
    }
});

test('AF9-63: deleting accounts keeps the books consistent and never leaves a dangling or orphaned referral', function () {
    $referrer = e33_user();
    [$referredA, $refA] = e33_attr($referrer);
    [$referredB, $refB] = e33_attr($referrer);
    $codeRow = ReferralCode::query()->where('active_for_user_id', $referrer->id)->firstOrFail();

    // حذف المُحال: يسقط صفّه فقط
    DB::table('users')->where('id', $referredA->id)->delete();
    expect(UserReferral::query()->whereKey($refA->id)->exists())->toBeFalse()->and(UserReferral::query()->whereKey($refB->id)->exists())->toBeTrue();

    // حذف المحيل: تبقى إحالة المُحال بلا محيل (للسجل والتحليلات) ويسقط رمزه
    DB::table('users')->where('id', $referrer->id)->delete();
    $refB = $refB->fresh();
    expect($refB)->not->toBeNull()->and($refB->referrer_user_id)->toBeNull()->and(ReferralCode::query()->whereKey($codeRow->id)->exists())->toBeFalse();

    // إحالة بلا محيل لا تؤهَّل ولا تكافئ ولا تُرسل إشعارًا ولا تنفجر
    $before = DB::table('currency_transactions')->count();
    expect(e33_act($referredB)?->status)->not->toBe(UserReferral::STATUS_REWARDED)
        ->and(DB::table('currency_transactions')->count())->toBe($before);

    $this->artisan('referrals:reconcile')->assertExitCode(0);
});
`````

## `tests/Feature/ReferralE33/E33QualificationTest.php`

`````php
<?php

use App\Models\Puzzle;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralQualificationService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF4-19: registration alone is not a qualified referral - nothing qualifies, rewards or notifies without an official action', function () {
    e33_rewards_on();
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());

    app(ReferralQualificationService::class)->evaluate($ref->id);
    $ref->refresh();

    expect($ref->status)->toBe(UserReferral::STATUS_PENDING)
        ->and($ref->qualified_at)->toBeNull()
        ->and($ref->action_at)->toBeNull()
        ->and(e33_ledger())->toHaveCount(0)
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

test('AF4-20: a verified referred account with an official action qualifies and the referrer is told once', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($after->qualified_at)->not->toBeNull()
        ->and($after->action_at)->not->toBeNull()
        ->and($after->reward_status)->toBe(UserReferral::REWARD_SKIPPED)
        ->and($after->reward_skip_reason)->toBe('no_campaign')
        ->and(DatabaseNotification::query()->where('notifiable_id', $referrer->id)->where('data->type', 'referral_qualified')->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $referred->id)->count())->toBe(0);
});

test('AF4-21: an unverified or frozen referred account never qualifies', function () {
    $referrer = e33_user();

    [$unverified, $r1] = e33_attr($referrer, null, ['email_verified_at' => null]);
    expect(e33_act($unverified)->status)->toBe(UserReferral::STATUS_PENDING);

    [$frozen, $r2] = e33_attr($referrer);
    $frozen->forceFill(['is_frozen' => true])->save();
    expect(e33_act($frozen)->status)->toBe(UserReferral::STATUS_PENDING);

    // يوثّق بريده لاحقًا ثم يتجمّد فيُرفع التجميد ⇒ يتأهل عند الحدث التالي
    $unverified->forceFill(['email_verified_at' => now()])->save();
    expect(e33_act($unverified)->status)->toBe(UserReferral::STATUS_QUALIFIED);
});

test('AF4-22: the minimum account age is respected and the periodic command finishes the job later', function () {
    config(['referrals.qualification.min_account_age_hours' => 24]);
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, null, [], 0);
    DB::table('users')->where('id', $referred->id)->update(['created_at' => now()->subHours(2)]);

    $after = e33_act($referred);
    expect($after->status)->toBe(UserReferral::STATUS_PENDING)->and($after->action_at)->not->toBeNull();

    $this->artisan('referrals:process')->assertSuccessful();
    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_PENDING);

    Carbon::setTestNow(now()->addHours(30));
    $this->artisan('referrals:process')->assertSuccessful();
    Carbon::setTestNow();

    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_QUALIFIED);
});

test('AF4-23: the real gameplay path qualifies - an official solve triggers it, a wrong answer does not, and multi-solve rules count distinct official puzzles', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer);

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    app(\App\Services\PuzzleAttemptService::class)->attempt($referred, $puzzle, 'خطأ');
    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_PENDING);

    $result = e33_solve($referred, Puzzle::factory()->create(['answer_raw' => 'صح']));
    expect($result['correct'])->toBeTrue()->and($ref->fresh()->status)->toBe(UserReferral::STATUS_QUALIFIED);

    // قاعدة ثلاث حلول: التكرار على نفس الأحجية لا يحتسب
    $rule = e33_campaign(['qualification_rule' => 'three_official_solves']);
    [$second, $ref2] = e33_attr($referrer, $rule);
    $p1 = Puzzle::factory()->create(['answer_raw' => 'صح']);
    e33_solve($second, $p1);
    e33_solve($second, Puzzle::factory()->create(['answer_raw' => 'صح']));
    expect($ref2->fresh()->status)->toBe(UserReferral::STATUS_PENDING);
    e33_solve($second, Puzzle::factory()->create(['answer_raw' => 'صح']));
    expect($ref2->fresh()->status)->toBe(UserReferral::STATUS_QUALIFIED);
});

test('AF4-24: qualification is idempotent - repeating the event or evaluating twice changes nothing and notifies once', function () {
    e33_rewards_on();
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());

    $first = e33_act($referred);
    $stamp = $first->qualified_at->getTimestamp();

    for ($i = 0; $i < 4; $i++) {
        e33_act($referred);
        app(ReferralQualificationService::class)->evaluate($ref->id);
    }
    $again = $ref->fresh();

    expect($again->qualified_at->getTimestamp())->toBe($stamp)
        ->and(e33_ledger())->toHaveCount(2)
        ->and(DatabaseNotification::query()->where('data->type', 'referral_qualified')->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('data->type', 'referral_reward_received')->count())->toBe(2);

    // نسخة قديمة من الصف (سباق) لا تؤهّل ثانية
    $stale = UserReferral::query()->find($ref->id);
    app(ReferralQualificationService::class)->evaluate($stale->id);
    expect(e33_ledger())->toHaveCount(2);
});

test('AF4-25: the referrer cannot change after qualification and a deleted referrer keeps the history without a referrer', function () {
    $a = e33_user();
    $b = e33_user();
    [$referred, $ref] = e33_attr($a);
    e33_act($referred);

    $other = app(\App\Services\Referrals\ReferralAttributionService::class)->attribute($referred, e33_code($b));
    $ref->refresh();

    expect($other->id)->toBe($ref->id)
        ->and($ref->referrer_user_id)->toBe($a->id)
        ->and($ref->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and(array_values(array_intersect(array_keys($ref->getAttributes()), ['referrer_user_id'])))->toBe(['referrer_user_id']);

    // لا مسار في الخدمات يكتب referrer_user_id بعد الإنشاء (فحص ساكن)
    $code = '';
    foreach (glob(app_path('Services/Referrals/*.php')) as $file) {
        $code .= file_get_contents($file);
    }
    expect(preg_match_all("/'referrer_user_id'\s*=>/", $code))->toBe(1); // الإنشاء فقط (ReferralAttributionService)

    // حذف المحيل ⇒ SET NULL والإحالة باقية (لا مكافآت جديدة لمحيل مجهول)
    $a->delete();
    expect($ref->fresh())->not->toBeNull()->and($ref->fresh()->referrer_user_id)->toBeNull();
});
`````

## `tests/Feature/ReferralE33/E33RedirectTest.php`

`````php
<?php

use App\Models\ReferralCode;
use App\Models\UserReferral;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF2-07: a guest opening /r/{code} gets the intent stored and is redirected internally, and no referral exists yet', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);

    $res = $this->get(route('referrals.landing', $code));

    $res->assertRedirect(route('register'))->assertSessionHas('referral_intent.code', $code)->assertSessionHas('referral_intent.s', 'link');
    expect(UserReferral::query()->count())->toBe(0)
        ->and(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(1);
});

test('AF2-08: an invalid, unknown or rotated code behaves like a valid one for the visitor and stores nothing', function () {
    $referrer = e33_user();
    $old = e33_code($referrer);
    e33_codes()->rotate($referrer);

    foreach ([$old, 'BCDFGH', 'ABC', 'AAAAAA', 'bcdfgh'] as $bad) {
        $this->get(route('referrals.landing', $bad))->assertRedirect(route('register'))->assertSessionMissing('referral_intent');
    }

    $this->get('/r/'.str_repeat('B', 40))->assertNotFound(); // صيغة مستحيلة تُرفض بالمسار نفسه
    expect(ReferralCode::query()->sum('clicks_count'))->toBe(0);
});

test('AF2-09: there is no open redirect - ?to is a closed key and never a URL', function () {
    $code = e33_code(e33_user());
    $host = parse_url(url('/'), PHP_URL_HOST);

    foreach (['https://evil.example/x', '//evil.example', 'javascript:alert(1)', '/\\evil.example', 'register@evil.example', ['x'], 'puzzles/../../x'] as $to) {
        $res = $this->get(route('referrals.landing', ['code' => $code, 'to' => $to]));
        $res->assertRedirect(route('register'));
        expect(parse_url((string) $res->headers->get('Location'), PHP_URL_HOST))->toBe($host);
    }

    $this->get(route('referrals.landing', ['code' => $code, 'to' => 'puzzles']))->assertRedirect(route('puzzles.index'));
    $this->get(route('referrals.landing', ['code' => $code, 'to' => 'home']))->assertRedirect(route('home'));
});

test('AF2-10: an authenticated visitor is never attributed', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);
    $visitor = e33_user();

    $this->actingAs($visitor)->get(route('referrals.landing', $code))->assertRedirect(route('home'))->assertSessionMissing('referral_intent');
    $this->actingAs($visitor)->get(route('puzzles.index', ['ref' => $code]))->assertSessionMissing('referral_intent');

    expect(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(0)
        ->and(UserReferral::query()->count())->toBe(0);
});

test('AF2-11: the first valid referrer wins and a later link cannot replace it - unless the first code is no longer valid', function () {
    $a = e33_user();
    $b = e33_user();
    $codeA = e33_code($a);
    $codeB = e33_code($b);

    $this->get(route('referrals.landing', $codeA));
    $this->get(route('referrals.landing', $codeB))->assertSessionHas('referral_intent.code', $codeA);
    $this->get(route('puzzles.index', ['ref' => $codeB]))->assertSessionHas('referral_intent.code', $codeA);

    // الأول لم يعد صالحًا (دُوِّر) ⇒ الثاني يربح
    e33_codes()->rotate($a);
    $this->get(route('referrals.landing', $codeB))->assertSessionHas('referral_intent.code', $codeB);
});

test('AF2-12: the intent is kept in a first-party encrypted cookie with a clamped 7-30 day lifetime, restored without a session, and ignored once expired', function () {
    $code = e33_code(e33_user());

    foreach ([[1, 7], [14, 14], [400, 30]] as [$configured, $expected]) {
        config(['referrals.attribution.ttl_days' => $configured]);
        Cache::flush();
        $res = $this->flushSession()->get(route('referrals.landing', $code));
        $cookie = $res->getCookie('ahj_ref', false);

        expect($cookie)->not->toBeNull()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
            ->and(round(($cookie->getExpiresTime() - time()) / 86400))->toBe((float) $expected)
            ->and($cookie->getValue())->not->toContain($code); // مشفّر لا نص صريح
    }

    config(['referrals.attribution.ttl_days' => 14]);
    $intent = json_encode(['code' => $code, 'c' => null, 's' => 'link', 'at' => now()->getTimestamp()]);

    // جلسة جديدة + كوكي فقط ⇒ النية تُستعاد وتُسنِد عند التسجيل
    $this->flushSession()->withCookie('ahj_ref', $intent);
    e33_register();
    expect(UserReferral::query()->count())->toBe(1);

    // منتهية المدة ⇒ تُتجاهل
    $this->flushSession()->withCookie('ahj_ref', json_encode(['code' => $code, 'c' => null, 's' => 'link', 'at' => now()->subDays(15)->getTimestamp()]));
    e33_register();
    expect(UserReferral::query()->count())->toBe(1);
});
`````

## `tests/Feature/ReferralE33/E33RegistrationTest.php`

`````php
<?php

use App\Models\ReferralCode;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAttributionService;
use App\Services\Referrals\ReferralIntent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF3-13: registering after a referral link creates exactly one pending referral for the code owner and clears the intent', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);

    $this->get(route('referrals.landing', $code));
    $res = e33_register('friend@example.test');

    $res->assertRedirect(route('verification.notice'))->assertSessionMissing('referral_intent');
    $new = User::query()->where('email', 'friend@example.test')->firstOrFail();
    $ref = UserReferral::query()->where('referred_user_id', $new->id)->firstOrFail();

    expect($ref->referrer_user_id)->toBe($referrer->id)
        ->and($ref->status)->toBe(UserReferral::STATUS_PENDING)
        ->and($ref->qualified_at)->toBeNull()
        ->and($ref->reward_status)->toBe(UserReferral::REWARD_NONE)
        ->and($ref->source)->toBe('link')
        ->and($ref->referral_code_id)->toBe(ReferralCode::query()->where('code', $code)->value('id'))
        ->and(UserReferral::query()->count())->toBe(1);
    $res->assertCookieExpired('ahj_ref');
});

test('AF3-14: no intent means no referral, and a forged referrer field in the request is ignored (the server decides)', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);

    e33_register('plain@example.test');
    e33_register('forged@example.test', ['ref' => $code, 'referral_code' => $code, 'referrer_id' => $referrer->id, 'referrer_user_id' => $referrer->id, 'c' => 'x']);

    expect(UserReferral::query()->count())->toBe(0);
});

test('AF3-15: self-referral and existing accounts are never attributed', function () {
    $user = e33_user();
    $code = e33_code($user);
    $svc = app(ReferralAttributionService::class);

    expect($svc->attribute($user, $code))->toBeNull()
        ->and(UserReferral::query()->count())->toBe(0);

    // حساب قديم لا يُسنَد عبر مسار التسجيل (لم يُنشأ الآن)
    $this->get(route('referrals.landing', $code));
    $old = e33_user();
    $req = Request::create('/register', 'POST');
    $req->setLaravelSession(app('session.store'));
    expect($svc->attributeFromRequest($old, $req))->toBeNull()->and(UserReferral::query()->count())->toBe(0);

    // محيل مجمَّد أو غير موثّق أو محذوف لا يُسنَد إليه
    $frozen = e33_user();
    $frozenCode = e33_code($frozen);
    $frozen->forceFill(['is_frozen' => true])->save();
    $unverified = e33_user();
    $unverifiedCode = e33_code($unverified);
    $unverified->forceFill(['email_verified_at' => null])->save();

    expect($svc->attribute(e33_user(), $frozenCode))->toBeNull()->and($svc->attribute(e33_user(), $unverifiedCode))->toBeNull();
});

test('AF3-16: an account has at most one referrer and it never changes - the database enforces it', function () {
    $a = e33_user();
    $b = e33_user();
    $svc = app(ReferralAttributionService::class);
    $referred = e33_user();

    $first = $svc->attribute($referred, e33_code($a));
    $second = $svc->attribute($referred, e33_code($b));

    expect($second->id)->toBe($first->id)
        ->and($second->referrer_user_id)->toBe($a->id)
        ->and(UserReferral::query()->where('referred_user_id', $referred->id)->count())->toBe(1);

    expect(fn () => DB::table('user_referrals')->insert([
        'public_id' => (string) Str::ulid(), 'referred_user_id' => $referred->id, 'referrer_user_id' => $b->id, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('AF3-17: the registration response does not change with a referral intent - no enumeration signal is added (E26)', function () {
    $existing = e33_user(['email' => 'taken@example.test']);
    $code = e33_code(e33_user());

    $plain = e33_register('taken@example.test');
    $plainErrors = session('errors')?->getBag('default')->toArray();

    $this->flushSession();
    $this->get(route('referrals.landing', $code));
    $withIntent = e33_register('taken@example.test');
    $intentErrors = session('errors')?->getBag('default')->toArray();

    expect($withIntent->getStatusCode())->toBe($plain->getStatusCode())
        ->and($intentErrors)->toBe($plainErrors)
        ->and(UserReferral::query()->count())->toBe(0);

    // رمز غير صالح في النية لا يُظهر أي رسالة ولا يمنع التسجيل
    $this->flushSession()->withCookie('ahj_ref', json_encode(['code' => 'BCDFGH', 'c' => null, 's' => 'link', 'at' => now()->getTimestamp()]));
    e33_register('fresh@example.test')->assertRedirect(route('verification.notice'))->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'fresh@example.test')->exists())->toBeTrue();
});

test('AF3-18: a failure while attributing never blocks the registration', function () {
    $code = e33_code(e33_user());
    $this->get(route('referrals.landing', $code));

    $this->app->bind(ReferralIntent::class, fn () => new class(app(\App\Services\Referrals\ReferralCodeService::class), app(\App\Services\Referrals\ReferralCampaignService::class), app(\App\Services\Referrals\ReferralClickRecorder::class)) extends ReferralIntent {
        public function current(Request $request): ?array
        {
            throw new RuntimeException('boom');
        }
    });

    e33_register('survivor@example.test')->assertRedirect(route('verification.notice'));

    expect(User::query()->where('email', 'survivor@example.test')->exists())->toBeTrue()
        ->and(UserReferral::query()->count())->toBe(0);
});
`````

## `tests/Feature/ReferralE33/E33RewardsTest.php`

`````php
<?php

use App\Models\CurrencyTransaction;
use App\Models\UserReferral;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PendingGemReleaseService;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralRewardService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF5-26: rewards are disabled by default - the shipped config is false and a qualification pays nothing', function () {
    $shipped = require config_path('referrals.php');
    expect($shipped['rewards']['enabled'])->toBeFalse();

    config(['referrals.rewards.enabled' => false]);
    $campaign = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 20, 'referred_reward_gems' => 10]);
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, $campaign);
    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($after->reward_status)->toBe(UserReferral::REWARD_SKIPPED)
        ->and($after->reward_skip_reason)->toBe('rewards_disabled')
        ->and($after->snap_referrer_gems)->toBe(0)
        ->and(e33_ledger())->toHaveCount(0)
        ->and(e33_wallet($referrer)['pending'])->toBe(0);
});

test('AF5-27: a reward needs the global switch, the campaign switch and a positive amount', function () {
    $referrer = e33_user();

    config(['referrals.rewards.enabled' => false]);
    $a = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 5]);
    [$u1] = e33_attr($referrer, $a);
    expect(e33_act($u1)->reward_skip_reason)->toBe('rewards_disabled');

    e33_rewards_on();
    $b = e33_campaign(['rewards_enabled' => false, 'referrer_reward_gems' => 5]);
    [$u2] = e33_attr($referrer, $b);
    expect(e33_act($u2)->reward_skip_reason)->toBe('rewards_disabled');

    $c = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 0, 'referred_reward_gems' => 0]);
    [$u3] = e33_attr($referrer, $c);
    expect(e33_act($u3)->reward_skip_reason)->toBe('zero_amount');

    $d = e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 5]);
    [$u4] = e33_attr($referrer, $d);
    expect(e33_act($u4)->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and(e33_ledger())->toHaveCount(1); // الجانب الموجب فقط

    // مبلغ فوق الحد الأقصى يُرفض عند الإدخال
    expect(fn () => e33_campaign(['rewards_enabled' => true, 'referrer_reward_gems' => 100000]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('AF5-28: the referrer and the referred amounts are independent', function () {
    foreach ([[20, 10], [0, 10], [15, 0]] as [$gr, $gd]) {
        $referrer = e33_user();
        $campaign = e33_reward_campaign(['referrer_reward_gems' => $gr, 'referred_reward_gems' => $gd]);
        [$referred] = e33_attr($referrer, $campaign);
        e33_act($referred);

        expect(e33_wallet($referrer)['pending'])->toBe($gr)->and(e33_wallet($referred)['pending'])->toBe($gd);
    }
});

test('AF5-29: gems move only through the wallet ledger with the semantic key per side', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_act($referred);

    $rows = e33_ledger()->keyBy('idempotency_key');

    expect($rows->keys()->all())->toEqualCanonicalizing(["referral-qualified:{$ref->id}:referrer", "referral-qualified:{$ref->id}:referred"])
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->amount)->toBe(20)
        ->and($rows["referral-qualified:{$ref->id}:referred"]->amount)->toBe(10)
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->type)->toBe(CurrencyTransaction::TYPE_EARN_PENDING)
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->reason)->toBe('referral_reward')
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->reference_type)->toBe((new UserReferral)->getMorphClass())
        ->and((int) $rows["referral-qualified:{$ref->id}:referrer"]->reference_id)->toBe($ref->id)
        ->and($rows["referral-qualified:{$ref->id}:referrer"]->user_id)->toBe($referrer->id)
        ->and($rows["referral-qualified:{$ref->id}:referred"]->user_id)->toBe($referred->id)
        ->and(e33_wallet($referrer)['earned'])->toBe(20);

    // لا مسار آخر في خدمات الإحالات يلمس رصيدًا مباشرة
    $code = '';
    foreach (glob(app_path('Services/Referrals/*.php')) as $file) {
        $code .= file_get_contents($file);
    }
    expect(preg_match('/->(increment|decrement)\(\s*[\'"](pending_balance|available_balance|lifetime_earned)/', $code))->toBe(0);
});

test('AF5-30: delivery is idempotent - repeated or stale deliveries never double-credit', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());
    e33_act($referred);

    $service = app(ReferralRewardService::class);
    for ($i = 0; $i < 5; $i++) {
        $service->deliver($ref->id);
    }
    $this->artisan('referrals:process')->assertSuccessful();

    $fresh = $ref->fresh();
    expect(e33_ledger())->toHaveCount(2)
        ->and(e33_wallet($referrer)['pending'])->toBe(20)
        ->and(e33_wallet($referred)['pending'])->toBe(10)
        ->and($fresh->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($fresh->reward_status)->toBe(UserReferral::REWARD_GRANTED)
        ->and($fresh->rewarded_at)->not->toBeNull()
        ->and($fresh->referrer_granted_at)->not->toBeNull()
        ->and($fresh->referred_granted_at)->not->toBeNull();

    // الدفتر نفسه يرفض المفتاح المكرر (حارس القاعدة الأخير)
    $dupe = fn () => DB::table('currency_transactions')->insert(['user_id' => $referrer->id, 'currency_id' => e33_ledger()->first()->currency_id, 'amount' => 20, 'type' => 'earn_pending', 'reason' => 'referral_reward', 'idempotency_key' => "referral-qualified:{$ref->id}:referrer", 'created_at' => now(), 'updated_at' => now()]);
    expect($dupe)->toThrow(\Illuminate\Database\QueryException::class);
});

test('AF5-31: the daily cap is per referrer and a capped referral pays neither side', function () {
    $referrer = e33_user();
    $other = e33_user();
    $campaign = e33_reward_campaign(['daily_cap' => 2]);
    $paid = [];

    for ($i = 0; $i < 3; $i++) {
        [$referred, $ref] = e33_attr($referrer, $campaign);
        $paid[] = [e33_act($referred), $referred];
    }

    expect($paid[0][0]->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($paid[1][0]->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and($paid[2][0]->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($paid[2][0]->reward_skip_reason)->toBe('cap_daily')
        ->and(e33_wallet($paid[2][1])['pending'])->toBe(0)
        ->and(e33_wallet($referrer)['pending'])->toBe(40)
        ->and(e33_ledger())->toHaveCount(4);

    // محيل آخر غير متأثر
    [$x] = e33_attr($other, $campaign);
    expect(e33_act($x)->status)->toBe(UserReferral::STATUS_REWARDED);
});

test('AF5-32: the monthly and lifetime caps also stop rewards', function () {
    $campaign = e33_reward_campaign(['daily_cap' => 10, 'monthly_cap' => 2]);
    $r1 = e33_user();
    $reasons = [];
    for ($i = 0; $i < 3; $i++) {
        [$u] = e33_attr($r1, $campaign);
        $reasons[] = e33_act($u)->reward_skip_reason;
    }
    expect($reasons)->toBe([null, null, 'cap_monthly']);

    $life = e33_reward_campaign(['daily_cap' => 10, 'monthly_cap' => 10, 'lifetime_cap' => 1]);
    $r2 = e33_user();
    [$a] = e33_attr($r2, $life);
    [$b] = e33_attr($r2, $life);
    expect(e33_act($a)->reward_skip_reason)->toBeNull()->and(e33_act($b)->reward_skip_reason)->toBe('cap_lifetime');

    // السقف المطلق في الإعداد لا يُتجاوز مهما أدخل المشرف
    expect(fn () => e33_campaign(['daily_cap' => 999999]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('AF5-33: the reward terms are a snapshot - editing or ending the campaign later never rewrites history', function () {
    $referrer = e33_user();
    $admin = e33_admin();
    $campaign = e33_reward_campaign();
    [$u1, $r1] = e33_attr($referrer, $campaign);
    e33_act($u1);

    app(ReferralCampaignService::class)->update($admin, $campaign->fresh(), ['referrer_reward_gems' => 99, 'referred_reward_gems' => 77]);
    [$u2, $r2] = e33_attr($referrer, $campaign->fresh());
    e33_act($u2);
    app(ReferralCampaignService::class)->end($admin, $campaign->fresh());

    $a = $r1->fresh();
    $b = $r2->fresh();

    expect([$a->snap_referrer_gems, $a->snap_referred_gems])->toBe([20, 10])
        ->and([$b->snap_referrer_gems, $b->snap_referred_gems])->toBe([99, 77])
        ->and($a->snap_campaign_id)->toBe($campaign->id)
        ->and(e33_ledger()->where('idempotency_key', "referral-qualified:{$r1->id}:referrer")->first()->amount)->toBe(20)
        ->and(e33_ledger()->where('idempotency_key', "referral-qualified:{$r2->id}:referrer")->first()->amount)->toBe(99);
});

test('AF5-34: referral gems reuse the pending hold and become available only after the usual hold period', function () {
    $referrer = e33_user();
    [$referred] = e33_attr($referrer, e33_reward_campaign());
    e33_act($referred);

    expect(e33_wallet($referrer))->toBe(['pending' => 20, 'available' => 0, 'earned' => 20]);

    $days = (int) config('gems.pending_hold_days');
    app(PendingGemReleaseService::class)->run(now()->addDays($days - 1));
    expect(e33_wallet($referrer)['available'])->toBe(0);

    app(PendingGemReleaseService::class)->run(now()->addDays($days + 1));
    expect(e33_wallet($referrer))->toBe(['pending' => 0, 'available' => 20, 'earned' => 20]);
});

test('AF5-35: no client request can grant gems', function () {
    $referrer = e33_user();
    $code = e33_code($referrer);
    $player = e33_user();
    $before = [e33_wallet($referrer), e33_wallet($player)];

    $this->post(route('referrals.share'), ['kind' => 'platform', 'target' => 'copy', 'gems' => 999, 'amount' => 999, 'reward' => 999])->assertSessionHasNoErrors();
    $this->actingAs($player)->post(route('referrals.share'), ['kind' => 'platform', 'target' => 'copy', 'gems' => 999, 'amount' => 999, 'with_ref' => true])->assertNoContent();
    $this->actingAs($player)->post(route('referrals.rotate'), ['gems' => 999]);
    $this->actingAs($player)->get(route('referrals.index', ['gems' => 999, 'amount' => 999]))->assertOk();
    auth()->logout();
    $this->get(route('referrals.landing', ['code' => $code, 'gems' => 500, 'amount' => 500]));
    $this->get(route('puzzles.index', ['ref' => $code, 'gems' => 100]));

    expect(e33_wallet($referrer))->toBe($before[0])->and(e33_wallet($player))->toBe($before[1])->and(e33_ledger())->toHaveCount(0)
        ->and(CurrencyTransaction::query()->count())->toBe(0);

    // لا متحكم إحالات يلمس المحفظة أصلًا
    foreach (glob(app_path('Http/Controllers/Referrals/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('Wallet')->not->toContain('creditPending');
    }
});

test('AF5-36: a paused or ended campaign pays nothing, and one failing side does not block the other - the retry delivers it once', function () {
    $referrer = e33_user();
    $admin = e33_admin();
    $campaign = e33_reward_campaign();
    [$u1] = e33_attr($referrer, $campaign);
    app(ReferralCampaignService::class)->pause($admin, $campaign->fresh());
    $paused = e33_act($u1);
    expect($paused->status)->toBe(UserReferral::STATUS_QUALIFIED)->and($paused->reward_skip_reason)->toBe('campaign_inactive')->and(e33_ledger())->toHaveCount(0);

    $campaign2 = e33_reward_campaign();
    [$u2, $r2] = e33_attr($referrer, $campaign2);

    $real = app(CurrencyWalletService::class);
    $mock = Mockery::mock(CurrencyWalletService::class)->makePartial();
    $mock->shouldReceive('creditPending')->with(Mockery::on(fn ($u) => $u->id === $u2->id), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->andThrow(new RuntimeException('wallet down'));
    $mock->shouldReceive('creditPending')->passthru();
    $this->app->instance(CurrencyWalletService::class, $mock);

    $partial = e33_act($u2);
    expect($partial->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($partial->reward_status)->toBe(UserReferral::REWARD_PENDING)
        ->and($partial->referrer_granted_at)->not->toBeNull()
        ->and($partial->referred_granted_at)->toBeNull()
        ->and(e33_wallet($referrer)['pending'])->toBe(20);

    $this->app->instance(CurrencyWalletService::class, $real);
    $this->artisan('referrals:process')->assertSuccessful();
    $this->artisan('referrals:process')->assertSuccessful();

    expect($r2->fresh()->status)->toBe(UserReferral::STATUS_REWARDED)
        ->and(e33_wallet($u2)['pending'])->toBe(10)
        ->and(e33_wallet($referrer)['pending'])->toBe(20)
        ->and(e33_ledger())->toHaveCount(2);
});

test('AF5-37: referrals change neither gameplay rewards nor ranking - ranking uses qualified referrals only', function () {
    $referrer = e33_user();
    $campaign = e33_reward_campaign();
    [$referred, $ref] = e33_attr($referrer, $campaign);
    $control = e33_user();

    $p1 = \App\Models\Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 12]);
    $withReferral = e33_solve($referred, $p1);
    $withoutReferral = e33_solve($control, $p1);

    expect($withReferral['gems_awarded'])->toBe($withoutReferral['gems_awarded'])->and($withReferral['correct'])->toBeTrue()
        ->and(DB::table('xp_transactions')->where('user_id', $referred->id)->sum('amount'))->toBe(DB::table('xp_transactions')->where('user_id', $control->id)->sum('amount'));

    // لا شيء في خدمات الإحالات يكتب XP أو ترتيبًا أو درجات، ولا يرتبط بالرعاية
    $code = '';
    foreach (glob(app_path('Services/Referrals/*.php')) as $file) {
        $code .= file_get_contents($file);
    }
    expect(preg_match('/XpService|grantXp|LeaderboardService|Sponsorship|SupporterTier/i', $code))->toBe(0);
});
`````

## `tests/Feature/ReferralE33/E33SharingTest.php`

`````php
<?php

use App\Models\Campaign;
use App\Models\Puzzle;
use App\Models\ReferralCode;
use App\Models\Season;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralShareStats;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF8-52: share URLs come only from named routes on the app host, kinds are a closed list and hostile text stays inert', function () {
    $b = app(ShareLinkBuilder::class);
    $host = parse_url(route('home'), PHP_URL_HOST);

    $s = $b->build('creator', 'ahmad', 'عنوان', null, null);
    expect($s['url'])->toBe(route('creators.show', 'ahmad'))->and($s['url_ref'])->toBeNull()->and($s['text'])->toBe('عنوان');

    foreach (['external', 'redirect', 'url', '../admin', 'PUZZLE', ''] as $kind) {
        expect(fn () => $b->build($kind, 'http://evil.test', 't'))->toThrow(NotFoundHttpException::class);
    }

    $hostile = $b->build('creator', 'https://evil.test/x', 't');
    expect(parse_url($hostile['url'], PHP_URL_HOST))->toBe($host)->and($hostile['url'])->toStartWith(route('home'));

    foreach ($b->build('platform', null, 'انضم إلينا')['links']['plain'] as $link) {
        expect($link)->toStartWith('https://')->and(parse_url($link, PHP_URL_HOST))->toBeIn(['wa.me', 't.me']);
    }

    $long = $b->build('platform', null, str_repeat('ع', 500), str_repeat('م', 900));
    expect(mb_strlen($long['title']))->toBe(120)->and(mb_strlen($long['text']))->toBe(200);

    // العنوان العدائي لا يصل إلى الصفحة كوسم نشط ولا يكسر سمات JS
    $html = Blade::render('<x-share-button kind="platform" :title="$t" />', ['t' => '</script><script>alert(1)</script>"\'><img src=x onerror=alert(2)>']);
    expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<img src=x')->not->toContain('onerror=alert(2)>');
});

test('AF8-53: the personal invite variant exists only for a verified, active member with a code, and building a link never creates a code', function () {
    $b = app(ShareLinkBuilder::class);

    $guest = $b->build('platform', null, 't');
    expect($guest['url_ref'])->toBeNull()->and($guest['links']['ref'])->toBeNull();

    $noCode = e33_user();
    $before = ReferralCode::query()->count();
    expect($b->build('platform', null, 't', null, $noCode)['url_ref'])->toBeNull()
        ->and(ReferralCode::query()->count())->toBe($before);

    $member = e33_user();
    $code = e33_code($member);
    $s = $b->build('platform', null, 't', null, $member);
    expect($s['url'])->not->toContain('?')->and($s['url_ref'])->toBe(route('home').'?ref='.$code)
        ->and($s['links']['ref']['whatsapp'])->toContain(rawurlencode('?ref='.$code))
        ->and($s['links']['plain']['whatsapp'])->not->toContain('ref');

    $unverified = e33_user(['email_verified_at' => null]);
    e33_code($member); // الرمز يبقى لمالكه فقط
    expect($b->build('platform', null, 't', null, $unverified)['url_ref'])->toBeNull();

    DB::table('users')->where('id', $member->id)->update(['is_frozen' => true]);
    expect($b->build('platform', null, 't', null, $member->fresh())['url_ref'])->toBeNull();
});

test('AF8-54: the component is first-party only, works without Web Share and exposes the toggle and tracking only to members', function () {
    $guestHtml = Blade::render('<x-share-button kind="platform" title="أحجيات" />');
    expect($guestHtml)
        ->toContain('data-share-component')->toContain('data-share-copy')->toContain('navigator.share')->toContain('execCommand')
        ->toContain('rel="noopener noreferrer"')->toContain('https://wa.me/')->toContain('https://t.me/share/url')
        ->not->toContain('data-share-ref-toggle')->not->toContain('referrals\\/share')->not->toContain('referrals/share')
        ->not->toContain('<script')->not->toContain('<iframe')->not->toContain('<img');

    // لا يظهر أي مضيف طرف ثالث غير هدفَي المشاركة الصريحين (www.w3.org = اسم فضاء SVG داخل الأيقونة، لا يُجلَب)
    preg_match_all('#https?://([a-z0-9.-]+)#i', $guestHtml, $m);
    expect(collect($m[1])->unique()->diff(['wa.me', 't.me', 'www.w3.org', parse_url(route('home'), PHP_URL_HOST)])->values()->all())->toBe([]);

    $member = e33_user();
    $code = e33_code($member);
    $this->actingAs($member);
    $memberHtml = Blade::render('<x-share-button kind="platform" title="أحجيات" />', []);
    // Blade::render لا يعرف المستخدم الحالي إلا عبر الحارس، وهو مضبوط أعلاه
    expect($memberHtml)->toContain('data-share-ref-toggle')->toContain('referrals\\/share')->toContain('?ref='.$code);

    // مستخدم مسجَّل بلا رمز: لا مفتاح دعوة، فقط إشارة لصفحة الدعوات
    $other = e33_user();
    $this->actingAs($other);
    $otherHtml = Blade::render('<x-share-button kind="platform" title="أحجيات" />');
    expect($otherHtml)->not->toContain('data-share-ref-toggle')->toContain(route('referrals.index'));
});

test('AF8-55: share tracking is member only, validated against closed lists, rate limited, and grants nothing', function () {
    $member = e33_user();
    $code = e33_code($member);
    $row = ReferralCode::query()->where('code', $code)->firstOrFail();

    $this->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy'])->assertUnauthorized();

    $snapshot = fn () => [
        DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
        UserReferral::query()->count(),
    ];
    $before = $snapshot();

    foreach ([
        ['kind' => 'puzzle', 'target' => 'copy'], ['kind' => 'community_puzzle', 'target' => 'whatsapp', 'with_ref' => true], ['kind' => 'season', 'target' => 'native'],
    ] as $payload) {
        $this->actingAs($member)->postJson(route('referrals.share'), $payload)->assertNoContent();
    }

    foreach ([
        ['kind' => 'https://evil.test', 'target' => 'copy'], ['kind' => 'puzzle', 'target' => 'facebook'], ['kind' => 'puzzle'], ['target' => 'copy'],
        ['kind' => ['a'], 'target' => 'copy'], ['kind' => 'puzzle', 'target' => 'copy', 'with_ref' => 'maybe'],
    ] as $bad) {
        $this->actingAs($member)->postJson(route('referrals.share'), $bad)->assertStatus(422);
    }

    $stats = app(ReferralShareStats::class)->lastDays(7);
    expect($stats['total'])->toBe(3)->and($stats['by_kind'])->toBe(['puzzle' => 1, 'community_puzzle' => 1, 'season' => 1])
        ->and($stats['by_target'])->toBe(['native' => 1, 'copy' => 1, 'whatsapp' => 1])
        ->and($row->fresh()->shares_count)->toBe(1)
        ->and($snapshot())->toBe($before);

    // عضو بلا رمز: with_ref لا يُنشئ رمزًا ولا يفشل
    $nocode = e33_user();
    $this->actingAs($nocode)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy', 'with_ref' => true])->assertNoContent();
    expect(ReferralCode::query()->where('user_id', $nocode->id)->exists())->toBeFalse();

    config(['referrals.limits.share_per_minute' => 3]);
    $limited = e33_user();
    e33_code($limited);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($limited)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy'])->assertNoContent();
    }

    $this->actingAs($limited)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy'])->assertStatus(429);
});

test('AF8-56: shareable pages declare a clean canonical and OG tags that ignore ref, campaign and tracking parameters', function () {
    $puzzle = Puzzle::factory()->create(['is_active' => true]);
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $owner = e33_user();
    $code = e33_code($owner);

    $pages = [
        [route('puzzles.show', $puzzle), route('puzzles.show', $puzzle)],
        [route('seasons.show', $season), route('seasons.show', $season)],
        [route('campaigns.show', $campaign), route('campaigns.show', $campaign)],
        [route('supporters.index'), route('supporters.index')],
    ];

    foreach ($pages as [$url, $canonical]) {
        $html = $this->get($url.'?ref='.$code.'&c=whatever&utm_source=x&fbclid=y')->assertOk()->getContent();

        expect($html)->toContain('<link rel="canonical" href="'.$canonical.'">')->toContain('<meta property="og:url" content="'.$canonical.'">')
            ->toContain('property="og:site_name"')->toContain('property="og:locale"')->toContain('name="description"');

        preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $m);
        expect($m[1])->not->toContain('?')->not->toContain('ref=')->not->toContain($code);
    }

    // الصفحة العامة تحمل مكوّن المشاركة ولا تنعكس القيمة المرسلة في ?ref داخلها
    $evil = '"><script>alert(1)</script>';
    $body = $this->get(route('puzzles.show', $puzzle).'?ref='.urlencode($evil))->assertOk()->getContent();
    expect($body)->toContain('data-share-component')->not->toContain($evil)->not->toContain('<script>alert(1)</script>');
});

test('AF8-57: opening or sharing a link never changes content, economy or attribution of a signed-in visitor', function () {
    $puzzle = Puzzle::factory()->create(['is_active' => true]);
    $owner = e33_user();
    $code = e33_code($owner);
    $visitor = e33_user();

    $before = [
        DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(), UserReferral::query()->count(),
        (int) DB::table('wallets')->sum('available_balance'),
    ];

    $plain = $this->actingAs($visitor)->get(route('puzzles.show', $puzzle))->assertOk();
    $withRef = $this->actingAs($visitor)->get(route('puzzles.show', $puzzle).'?ref='.$code)->assertOk();
    $withRef->assertSessionMissing('referral_intent');

    // نُسقِط ما يختلف بين أي طلبين متتاليين بطبيعته: رموز CSRF/nonce، وحقن أصول Livewire (أول استجابة فقط في العملية)، والتعليقات والمسافات
    $strip = function (string $html) {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#(name="csrf-token" content=")[^"]+|(nonce=")[^"]+|(_token" value=")[^"]+#', '$1$2$3', $html);

        return trim(preg_replace('#\s+#', ' ', $html));
    };
    expect($strip($withRef->getContent()))->toBe($strip($plain->getContent()))
        ->and(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(0); // الزائر المصادَق لا يُحتسب نقرة إحالة

    $this->actingAs($visitor)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'whatsapp', 'with_ref' => true])->assertNoContent();

    expect([
        DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(), UserReferral::query()->count(),
        (int) DB::table('wallets')->sum('available_balance'),
    ])->toBe($before);

    // المحيل نفسه: مشاركته لا تغيّر ترتيبه ولا رصيده ولا تُنشئ إحالة
    $this->actingAs($owner)->postJson(route('referrals.share'), ['kind' => 'platform', 'target' => 'copy', 'with_ref' => true])->assertNoContent();
    expect(UserReferral::query()->where('referrer_user_id', $owner->id)->count())->toBe(0);
});
`````

## `tests/Feature/ReferralE33/_support.php`

`````php
<?php

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\Puzzle;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAttributionService;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralQualificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E33 (الإحالات) - مساعدات مشتركة. كل شيء على SQLite وكاش array (ويعمل كما هو على MySQL بـphpunit.mysql.xml.dist)؛ لا شبكة ولا حزم.
| كل تهيئة تمرّ بالخدمات الرسمية (حملات/رموز/إسناد/تأهيل)؛ لا كتابة مباشرة بحالة أو مكافأة إلا عند اختبار الحراسة نفسها.
| الافتراضي بالاختبارات: عمر حساب أدنى 0 والمكافآت معطّلة (كالإنتاج)؛ اختبارات المكافآت تفعّلها صراحةً.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';

if (! function_exists('e33_seed')) {
    function e33_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        config([
            'referrals.enabled' => true,
            'referrals.rewards.enabled' => false,
            'referrals.qualification.min_account_age_hours' => 0,
            'referrals.notifications.enabled' => true,
            'referrals.attribution.ttl_days' => 14,
        ]);
    }

    function e33_rewards_on(): void
    {
        config(['referrals.rewards.enabled' => true]);
    }

    function e33_admin(): User
    {
        $u = e16User();
        $u->assignRole('administrator');

        return $u;
    }

    function e33_moderator(): User
    {
        $u = e16User();
        $u->assignRole('moderator');

        return $u;
    }

    function e33_manager(): User
    {
        $u = e16User();
        $u->assignRole('content-manager');

        return $u;
    }

    /** لاعب موثَّق (e16User) بعمر حساب كافٍ (افتراضيًا قبل يومين). */
    function e33_user(array $attrs = [], int $daysOld = 2): User
    {
        $u = e16User($attrs);
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($daysOld)]);

        return $u->fresh();
    }

    function e33_codes(): ReferralCodeService
    {
        return app(ReferralCodeService::class);
    }

    function e33_code(User $u): string
    {
        return e33_codes()->ensureFor($u)->code;
    }

    /** حملة بالخدمة الرسمية. $over تتجاوز الافتراضيات. */
    function e33_campaign(array $over = [], bool $activate = true, bool $default = false, ?User $actor = null): ReferralCampaign
    {
        $actor ??= e33_admin();
        $svc = app(ReferralCampaignService::class);
        $c = $svc->create($actor, $over + ['name' => 'حملة '.fake()->unique()->numerify('####'), 'slug' => 'c'.fake()->unique()->numerify('######'), 'is_public' => true]);

        if ($activate) {
            $c = $svc->activate($actor, $c);
        }

        if ($default) {
            $c = $svc->makeDefault($actor, $c);
        }

        return $c;
    }

    /** حملة بمكافآت مفعّلة (20 محيل / 10 مُحال افتراضيًا) ونشطة. */
    function e33_reward_campaign(array $over = [], bool $default = false): ReferralCampaign
    {
        e33_rewards_on();

        return e33_campaign($over + ['rewards_enabled' => true, 'referrer_reward_gems' => 20, 'referred_reward_gems' => 10], true, $default);
    }

    /**
     * يُسنِد مُحالًا جديدًا إلى محيل بالخدمة الرسمية. يعيد [مُحال, إحالة].
     *
     * @return array{0: User, 1: UserReferral}
     */
    function e33_attr(User $referrer, ?ReferralCampaign $campaign = null, array $referredAttrs = [], int $daysOld = 2): array
    {
        $referred = e33_user($referredAttrs, $daysOld);
        $referral = app(ReferralAttributionService::class)->attribute($referred, e33_code($referrer), $campaign?->slug);

        return [$referred, $referral];
    }

    /** يحاكي أول إجراء رسمي ذي معنى للمُحال (الحدث الذي تطلقه afterPuzzleSolved) ويعيد الإحالة بعد التقييم. */
    function e33_act(User $referred): ?UserReferral
    {
        app(ReferralQualificationService::class)->onMeaningfulAction($referred);

        return UserReferral::query()->where('referred_user_id', $referred->id)->first();
    }

    /** إحالة مؤهَّلة جاهزة: يعيد [مُحال, إحالة بعد التأهيل]. */
    function e33_qualified(User $referrer, ?ReferralCampaign $campaign = null): array
    {
        [$referred, $referral] = e33_attr($referrer, $campaign);

        return [$referred, e33_act($referred) ?? $referral];
    }

    /** حلّ حقيقي لأحجية رسمية بمسار اللعب الفعلي (يطلق afterPuzzleSolved). */
    function e33_solve(User $user, ?Puzzle $puzzle = null): array
    {
        $puzzle ??= Puzzle::factory()->create(['answer_raw' => 'صح']);

        return app(\App\Services\PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    }

    /** يربط مستخدمَين بنفس IP/بصمة جهاز (إشارات ضعيفة). */
    function e33_share_network(User $a, User $b, bool $ip = true, bool $device = true): void
    {
        foreach ([$a, $b] as $u) {
            DeviceSighting::query()->create([
                'user_id' => $u->id,
                'ip_address' => $ip ? '203.0.113.10' : '203.0.113.'.(100 + $u->id % 100),
                'device_hash' => $device ? str_repeat('a', 64) : hash('sha256', 'dev'.$u->id),
                'last_seen_at' => now(),
            ]);
        }
    }

    function e33_wallet(User $u): array
    {
        $w = \App\Models\Wallet::query()->where('user_id', $u->id)->first();

        return ['pending' => (int) ($w->pending_balance ?? 0), 'available' => (int) ($w->available_balance ?? 0), 'earned' => (int) ($w->lifetime_earned ?? 0)];
    }

    function e33_ledger(): \Illuminate\Support\Collection
    {
        return \App\Models\CurrencyTransaction::query()->where('idempotency_key', 'like', 'referral-qualified:%')->orderBy('id')->get();
    }

    function e33_password(): string
    {
        return 'Str0ng-Passw0rd!x';
    }

    /** يسجّل حسابًا جديدًا عبر مسار التسجيل الفعلي (يستعمل جلسة الاختبار الحالية). */
    function e33_register(?string $email = null, array $extra = [])
    {
        $email ??= 'new'.fake()->unique()->numerify('######').'@example.test';

        return test()->post(route('register'), $extra + ['name' => 'مسجِّل جديد', 'email' => $email, 'password' => e33_password(), 'password_confirmation' => e33_password()]);
    }
}
`````

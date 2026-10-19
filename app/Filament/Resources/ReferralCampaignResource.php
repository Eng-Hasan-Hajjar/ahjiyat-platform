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

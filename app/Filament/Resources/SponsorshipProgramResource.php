<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipProgramResource\Pages;
use App\Models\CompetitiveEvent;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\EventRewardPlanService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SponsorshipTargets;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - برامج الرعاية (أهداف رسمية فقط: المنصة/موسم/حملة/منافسة). إنشاء وتعديل sponsorships.manage؛ **خطة تعزيز المكافآت وقفلها sponsorships.reward_plan حصرًا** (فصل مالي)؛
 * كل التغييرات عبر SponsorshipProgramService (تحقق + تدقيق). لا حذف. الأدوار (راعٍ/راعٍ رئيسي) تشترط مساهمة مؤكَّدة. محتوى المجتمع ليس هدفًا ممكنًا بالتصميم.
 */
class SponsorshipProgramResource extends Resource
{
    protected static ?string $model = SponsorshipProgram::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'برامج الرعاية';

    protected static ?string $modelLabel = 'برنامج رعاية';

    protected static ?string $pluralModelLabel = 'برامج الرعاية';

    protected static ?int $navigationSort = 91;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function statusLabel(string $s): string
    {
        return ['draft' => 'مسودة', 'active' => 'نشط', 'closed' => 'مغلق', 'cancelled' => 'ملغى'][$s] ?? $s;
    }

    public static function form(Form $form): Form
    {
        $targets = app(SponsorshipTargets::class);

        return $form->schema([
            Forms\Components\Section::make('البرنامج')->columns(2)->schema([
                Forms\Components\TextInput::make('title')->label('العنوان')->required()->minLength(3)->maxLength(160)->columnSpanFull(),
                Forms\Components\Textarea::make('description')->label('الوصف (نص عادي)')->rows(3)->maxLength(1000)->columnSpanFull(),
                Forms\Components\Select::make('target_type')->label('الهدف الرسمي')->required()->live()->options([
                    'platform' => 'المنصة', 'season' => 'موسم', 'campaign' => 'حملة', 'competitive_event' => 'منافسة',
                ])->helperText('الرعاية للمحتوى الرسمي فقط؛ لا تُرعى أحجيات المجتمع ولا السلاسل.'),
                Forms\Components\Select::make('target_id')->label('العنصر المستهدف')->searchable()
                    ->options(fn (Forms\Get $get) => $targets->options((string) $get('target_type')))
                    ->visible(fn (Forms\Get $get) => $get('target_type') && $get('target_type') !== 'platform')
                    ->required(fn (Forms\Get $get) => $get('target_type') && $get('target_type') !== 'platform'),
                Forms\Components\DateTimePicker::make('starts_at')->label('يبدأ (اختياري)'),
                Forms\Components\DateTimePicker::make('ends_at')->label('ينتهي (اختياري)')->after('starts_at'),
                Forms\Components\TextInput::make('goal_amount')->label('هدف التمويل بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().'، اختياري)')->maxLength(20),
            ]),
            Forms\Components\Section::make('العرض العام')->columns(2)->schema([
                Forms\Components\Toggle::make('show_public_amounts')->label('إظهار المبالغ الدقيقة علنًا')->helperText('مغلق افتراضيًا: تظهر المستويات والأسماء فقط.'),
                Forms\Components\Toggle::make('show_goal_progress')->label('إظهار تقدّم الهدف علنًا'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('sponsorship_programs.*')->selectSub(
                'select COALESCE(SUM(c.accounting_amount_minor), 0) from sponsorship_contributions c where c.sponsorship_program_id = sponsorship_programs.id and c.status = \'confirmed\'',
                'funding_minor',
            ))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('target_type')->label('الهدف')->formatStateUsing(fn ($state, SponsorshipProgram $r) => app(SponsorshipTargets::class)->typeLabel((string) $state).($r->target_id ? " #{$r->target_id}" : '')),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['active' => 'success', 'draft' => 'gray', 'closed' => 'warning', 'cancelled' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('funding_minor')->label('التمويل المؤكَّد')->state(fn (SponsorshipProgram $r) => SponsorshipMoney::format((int) $r->funding_minor, $r->accounting_currency)),
                Tables\Columns\IconColumn::make('reward_boost_enabled')->label('تعزيز المكافآت')->boolean(),
                Tables\Columns\IconColumn::make('reward_plan_locked_at')->label('الخطة مقفلة')->boolean()->state(fn (SponsorshipProgram $r) => $r->reward_plan_locked_at !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['draft' => 'مسودة', 'active' => 'نشط', 'closed' => 'مغلق', 'cancelled' => 'ملغى']),
                Tables\Filters\SelectFilter::make('target_type')->label('الهدف')->options(['platform' => 'المنصة', 'season' => 'موسم', 'campaign' => 'حملة', 'competitive_event' => 'منافسة']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (SponsorshipProgram $r) => $r->status !== SponsorshipProgram::STATUS_CANCELLED),
                Tables\Actions\ActionGroup::make([
                    self::statusAction('activate', 'تفعيل', 'success', fn (SponsorshipProgramService $s, SponsorshipProgram $r) => $s->activate(auth()->user(), $r), [SponsorshipProgram::STATUS_DRAFT], 'فُعّل البرنامج.'),
                    self::statusAction('close', 'إغلاق', 'warning', fn (SponsorshipProgramService $s, SponsorshipProgram $r) => $s->close(auth()->user(), $r), [SponsorshipProgram::STATUS_ACTIVE], 'أُغلق البرنامج (يبقى سجلّه).'),
                    self::statusAction('cancel_program', 'إلغاء', 'danger', fn (SponsorshipProgramService $s, SponsorshipProgram $r) => $s->cancel(auth()->user(), $r), [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], 'أُلغي البرنامج.'),
                    self::assignRoleAction(),
                    self::removeRoleAction(),
                    self::rewardPlanAction(),
                    self::lockPlanAction(),
                ]),
            ]);
    }

    protected static function statusAction(string $name, string $label, string $color, \Closure $call, array $from, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (SponsorshipProgram $r) => in_array($r->status, $from, true) && (auth()->user()?->can('sponsorships.manage') ?? false))
            ->action(fn (SponsorshipProgram $record) => self::guard(fn () => $call(app(SponsorshipProgramService::class), $record), $ok));
    }

    protected static function assignRoleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('assign_role')->label('تعيين دور (راعٍ / راعٍ رئيسي)')->icon('heroicon-o-star')
            ->visible(fn (SponsorshipProgram $r) => $r->status !== SponsorshipProgram::STATUS_CANCELLED && (auth()->user()?->can('sponsorships.manage') ?? false))
            ->form(fn (SponsorshipProgram $r) => [
                Forms\Components\Select::make('supporter_id')->label('الداعم (له مساهمة مؤكَّدة بهذا البرنامج)')->required()
                    ->options(fn () => SupporterProfile::query()->whereIn('id', SponsorshipContribution::query()->where('sponsorship_program_id', $r->id)
                        ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->select('supporter_profile_id'))->orderBy('display_name')->limit(100)->pluck('display_name', 'id')->all()),
                Forms\Components\Select::make('role')->label('الدور')->required()->options(['supporter' => 'داعم', 'sponsor' => 'راعٍ', 'main_sponsor' => 'الراعي الرئيسي'])->default('sponsor'),
            ])
            ->action(fn (SponsorshipProgram $record, array $data) => self::guard(function () use ($record, $data) {
                app(SponsorshipProgramService::class)->assignRole(auth()->user(), $record, SupporterProfile::query()->findOrFail((int) $data['supporter_id']), (string) $data['role']);
            }, 'عُيّن الدور.'));
    }

    protected static function removeRoleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('remove_role')->label('إزالة دور')->icon('heroicon-o-x-mark')->color('gray')
            ->visible(fn (SponsorshipProgram $r) => auth()->user()?->can('sponsorships.manage') ?? false)
            ->form(fn (SponsorshipProgram $r) => [
                Forms\Components\Select::make('supporter_id')->label('الداعم')->required()
                    ->options(fn () => SupporterProfile::query()->whereIn('id', \App\Models\SponsorshipProgramRole::query()->where('sponsorship_program_id', $r->id)->select('supporter_profile_id'))->limit(100)->pluck('display_name', 'id')->all()),
            ])
            ->action(fn (SponsorshipProgram $record, array $data) => self::guard(function () use ($record, $data) {
                app(SponsorshipProgramService::class)->removeRole(auth()->user(), $record, SupporterProfile::query()->findOrFail((int) $data['supporter_id']));
            }, 'أُزيل الدور.'));
    }

    protected static function rewardPlanAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reward_plan')->label('خطة تعزيز المكافآت')->icon('heroicon-o-sparkles')
            ->visible(fn (SponsorshipProgram $r) => $r->status !== SponsorshipProgram::STATUS_CANCELLED && $r->reward_plan_locked_at === null && (auth()->user()?->can('sponsorships.reward_plan') ?? false))
            ->fillForm(fn (SponsorshipProgram $r) => [
                'enabled' => (bool) $r->reward_boost_enabled,
                'step' => $r->funding_step_minor ? self::decimal((int) $r->funding_step_minor, (string) $r->accounting_currency) : null,
                'per_step_bps' => (int) $r->boost_per_step_bps ?: 100,
                'max_bps' => (int) $r->max_boost_bps ?: 500,
            ])
            ->modalDescription('الصيغة: عدد الخطوات = floor(التمويل المؤكَّد ÷ الخطوة)، النسبة = min(الخطوات × نسبة الخطوة، السقف). 100 نقطة أساسية = 1%. تُطبَّق على المحتوى الرسمي فقط، وبلا أثر رجعي.')
            ->form([
                Forms\Components\Toggle::make('enabled')->label('تفعيل تعزيز المكافآت'),
                Forms\Components\TextInput::make('step')->label('الخطوة (بعملة المحاسبة '.SponsorshipMoney::accountingCurrency().')')->maxLength(20),
                Forms\Components\TextInput::make('per_step_bps')->label('نقاط أساسية لكل خطوة')->numeric()->minValue(0)->maxValue((int) config('sponsorship.reward.hard_max_bps')),
                Forms\Components\TextInput::make('max_bps')->label('سقف التعزيز (نقاط أساسية)')->numeric()->minValue(0)->maxValue((int) config('sponsorship.reward.hard_max_bps')),
            ])
            ->action(fn (SponsorshipProgram $record, array $data) => self::guard(fn () => app(SponsorshipProgramService::class)->updateRewardPlan(auth()->user(), $record, [
                'enabled' => (bool) ($data['enabled'] ?? false), 'step' => $data['step'] ?? null, 'per_step_bps' => (int) ($data['per_step_bps'] ?? 0), 'max_bps' => (int) ($data['max_bps'] ?? 0),
            ]), 'حُفظت خطة التعزيز.'));
    }

    protected static function lockPlanAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('lock_plan')->label('قفل خطة المنافسة الآن')->icon('heroicon-o-lock-closed')->color('warning')->requiresConfirmation()
            ->modalDescription('يثبّت لقطة التمويل والنسبة لهذه المنافسة قبل بدئها. لا تعديل بعد القفل، والمساهمات اللاحقة لا تغيّر جوائزها.')
            ->visible(fn (SponsorshipProgram $r) => $r->target_type === SponsorshipProgram::TARGET_COMPETITIVE_EVENT && $r->status === SponsorshipProgram::STATUS_ACTIVE
                && $r->reward_boost_enabled && $r->reward_plan_locked_at === null && (auth()->user()?->can('sponsorships.reward_plan') ?? false))
            ->action(fn (SponsorshipProgram $record) => self::guard(function () use ($record) {
                app(EventRewardPlanService::class)->lock(CompetitiveEvent::query()->findOrFail((int) $record->target_id), auth()->user());
            }, 'قُفلت خطة المنافسة.'));
    }

    /** وحدة صغرى ← نص عشري بلا floats (لملء النماذج). */
    public static function decimal(int $minor, string $currency): string
    {
        $exp = SponsorshipMoney::exponent($currency);

        return $exp === 0 ? (string) $minor : intdiv($minor, 10 ** $exp).'.'.str_pad((string) ($minor % (10 ** $exp)), $exp, '0', STR_PAD_LEFT);
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSponsorshipPrograms::route('/'),
            'create' => Pages\CreateSponsorshipProgram::route('/create'),
            'edit' => Pages\EditSponsorshipProgram::route('/{record}/edit'),
        ];
    }
}

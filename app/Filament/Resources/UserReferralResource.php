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

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipPaymentResource\Pages;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Services\Payments\PaymentAnalytics;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E32 - طابور مراجعة طلبات الدعم/الدفع (مالي). العرض sponsorships.view؛ كل إجراء (تحقق/رفض/توضيح/إلغاء/استرداد) sponsorships.confirm عبر
 * SponsorshipPaymentService حصرًا (قفل صف + معاملة ذرّية + تدقيق). لا إنشاء/تعديل خام ولا حذف من الجدول: التاريخ المالي يبقى.
 * الإثبات يُفتح عبر مسار مخوَّل (مرفق خامل + تدقيق التنزيل) لا برابط تخزين. لا بريد ولا بيانات بنكية للمحوِّل هنا. ملاحظات المراجع الداخلية تظهر لمن يملك confirm فقط.
 */
class SponsorshipPaymentResource extends Resource
{
    protected static ?string $model = SponsorshipPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'طلبات الدعم والدفع';

    protected static ?string $modelLabel = 'طلب دعم';

    protected static ?string $pluralModelLabel = 'طلبات الدعم والدفع';

    protected static ?int $navigationSort = 91;

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

    public static function getNavigationBadge(): ?string
    {
        $n = (int) (app(PaymentAnalytics::class)->summary()['awaiting_verification'] ?? 0);

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $canConfirm = fn (): bool => auth()->user()?->can('sponsorships.confirm') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('sponsorship_payments.*')
                ->addSelect(['dup_proof' => SponsorshipPayment::query()->from('sponsorship_payments as d')->selectRaw('COUNT(*)')
                    ->whereColumn('d.proof_sha256', 'sponsorship_payments.proof_sha256')->whereColumn('d.id', '!=', 'sponsorship_payments.id')])
                ->with(['program:id,title', 'user:id,name']))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 20, 50])
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('المرجع')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('user.name')->label('الداعم')->limit(24),
                Tables\Columns\TextColumn::make('program.title')->label('البرنامج')->limit(26),
                Tables\Columns\TextColumn::make('amount_minor')->label('المبلغ')->state(fn (SponsorshipPayment $r) => $r->amountLabel()),
                Tables\Columns\TextColumn::make('provider')->label('الطريقة')->badge()->color('gray'),
                Tables\Columns\IconColumn::make('is_anonymous')->label('مجهول')->boolean(),
                Tables\Columns\IconColumn::make('proof_path')->label('إثبات')->boolean()->state(fn (SponsorshipPayment $r) => $r->hasProof()),
                Tables\Columns\TextColumn::make('dup_proof')->label('إثبات مكرر')->badge()->color('danger')->formatStateUsing(fn ($state) => (int) $state > 0 ? 'مكرر ×'.(int) $state : null)->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => SponsorshipPayment::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => ['awaiting_payment' => 'warning', 'submitted' => 'primary', 'verified' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', 'expired' => 'gray', 'refunded' => 'gray'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('submitted_at')->label('أُرسل')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('payer_note')->label('ملاحظة المحوِّل')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('review_note')->label('ملاحظة داخلية')->limit(30)->visible($canConfirm)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('أُنشئ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(SponsorshipPayment::statusLabels()),
                Tables\Filters\SelectFilter::make('provider')->label('الطريقة')->options(fn () => collect((array) config('payments.providers'))->keys()->mapWithKeys(fn ($k) => [(string) $k => (string) $k])->all()),
                Tables\Filters\SelectFilter::make('sponsorship_program_id')->label('البرنامج')
                    ->options(fn () => SponsorshipProgram::query()->orderByDesc('id')->limit(100)->pluck('title', 'id')->all()),
                Tables\Filters\TernaryFilter::make('is_anonymous')->label('مجهول'),
                Tables\Filters\TernaryFilter::make('has_proof')->label('إثبات مرفوع')->queries(
                    true: fn (Builder $q) => $q->whereNotNull('proof_path'),
                    false: fn (Builder $q) => $q->whereNull('proof_path'),
                ),
            ])
            ->actions([
                Tables\Actions\Action::make('proof')->label('الإثبات')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (SponsorshipPayment $r) => $r->hasProof() && $canConfirm())
                    ->url(fn (SponsorshipPayment $r) => route('payments.staff.proof', ['payment' => $r->public_id]))->openUrlInNewTab(),

                Tables\Actions\Action::make('verify')->label('تحقق')->color('success')->icon('heroicon-o-check-circle')
                    ->visible(fn (SponsorshipPayment $r) => $r->isOpen() && $canConfirm())
                    ->modalDescription('يُنشأ دعم مؤكَّد ويُحتسب فورًا في التمويل والمستوى والصدارة والتعزيز. تأكد من وصول المبلغ فعلًا.')
                    ->form(fn (SponsorshipPayment $r) => $r->currency === SponsorshipMoney::accountingCurrency() ? [] : [
                        Forms\Components\TextInput::make('accounting_amount')->label('المعادل بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().') — إلزامي لأن العملة مختلفة')->required()->maxLength(20),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (SponsorshipPayment $record, array $data) => self::guard(fn () => app(SponsorshipPaymentService::class)->verify(auth()->user(), $record, $data['accounting_amount'] ?? null), 'تم التحقق وتأكيد الدعم.')),

                Tables\Actions\Action::make('reject')->label('رفض')->color('danger')->icon('heroicon-o-x-circle')
                    ->visible(fn (SponsorshipPayment $r) => $r->isOpen() && $canConfirm())
                    ->form([
                        Forms\Components\Select::make('code')->label('السبب')->required()->options(SponsorshipPayment::failureLabels()),
                        Forms\Components\Textarea::make('message')->label('رسالة لصاحب الطلب (تظهر له)')->rows(2)->maxLength(255),
                        Forms\Components\Textarea::make('note')->label('ملاحظة داخلية (لا تظهر له)')->rows(2)->maxLength(500),
                    ])
                    ->action(fn (SponsorshipPayment $record, array $data) => self::guard(fn () => app(SponsorshipPaymentService::class)->reject(auth()->user(), $record, (string) $data['code'], $data['message'] ?? null, $data['note'] ?? null), 'رُفض الطلب.')),

                self::reasonAction('clarify', 'طلب توضيح', 'warning', fn (SponsorshipPayment $r) => $r->status === SponsorshipPayment::STATUS_SUBMITTED,
                    fn (SponsorshipPaymentService $s, SponsorshipPayment $r, string $reason) => $s->requestClarification(auth()->user(), $r, $reason), 'أُرسل طلب التوضيح.', 'الرسالة لصاحب الطلب (إلزامي)'),
                self::reasonAction('cancel', 'إلغاء', 'gray', fn (SponsorshipPayment $r) => $r->isOpen(),
                    fn (SponsorshipPaymentService $s, SponsorshipPayment $r, string $reason) => $s->cancel(auth()->user(), $r, $reason), 'أُلغي الطلب.', 'سبب الإلغاء (يظهر لصاحب الطلب)'),
                self::reasonAction('refund', 'استرداد', 'warning', fn (SponsorshipPayment $r) => $r->status === SponsorshipPayment::STATUS_VERIFIED,
                    fn (SponsorshipPaymentService $s, SponsorshipPayment $r, string $reason) => $s->refund(auth()->user(), $r, $reason), 'سُجّل الاسترداد (لا تُسحب مكافآت سبق منحها).', 'سبب الاسترداد (إلزامي)'),
            ]);
    }

    protected static function reasonAction(string $name, string $label, string $color, \Closure $when, \Closure $call, string $ok, string $field): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->visible(fn (SponsorshipPayment $r) => $when($r) && (auth()->user()?->can('sponsorships.confirm') ?? false))
            ->form([Forms\Components\Textarea::make('reason')->label($field)->required()->minLength(3)->maxLength(255)])
            ->action(fn (SponsorshipPayment $record, array $data) => self::guard(fn () => $call(app(SponsorshipPaymentService::class), $record, (string) $data['reason']), $ok));
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            app(PaymentAnalytics::class)->forget();
            Notification::make()->success()->title($ok)->send();
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSponsorshipPayments::route('/')];
    }
}

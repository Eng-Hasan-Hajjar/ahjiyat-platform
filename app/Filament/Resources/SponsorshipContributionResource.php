<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipContributionResource\Pages;
use App\Models\SponsorshipContribution;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - المساهمات (مالية): لا إنشاء/تعديل خام ولا حذف من الجدول. التسجيل من زر الصفحة (sponsorships.manage)، والتأكيد/الإلغاء/الاسترداد (sponsorships.confirm) عبر
 * ContributionService حصرًا (تدقيق + قفل صف + حماية الحقول المالية). التصحيح بعد التأكيد = استرداد ثم تسجيل جديد. لا أرقام دفع/بطاقات في أي حقل؛ المرجع نص حر قصير.
 * الملاحظات الإدارية الخاصة تظهر لمن يملك manage فقط وفي أعمدة مخفية افتراضيًا.
 */
class SponsorshipContributionResource extends Resource
{
    protected static ?string $model = SponsorshipContribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'المساهمات';

    protected static ?string $modelLabel = 'مساهمة';

    protected static ?string $pluralModelLabel = 'المساهمات';

    protected static ?int $navigationSort = 92;

    public static function canCreate(): bool
    {
        return false; // التسجيل من إجراء الصفحة (ContributionService::record) لا من نموذج خام
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
        return ['pending' => 'معلّقة', 'confirmed' => 'مؤكَّدة', 'cancelled' => 'ملغاة', 'refunded' => 'مستردة'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('id', 'public_id', 'supporter_profile_id', 'sponsorship_program_id', 'original_amount_minor', 'currency', 'accounting_amount_minor', 'accounting_currency', 'status', 'is_anonymous', 'reference', 'created_at', 'confirmed_at')
                ->with(['supporter:id,display_name', 'program:id,title,accounting_currency']))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('public_id')->label('المعرّف')->limit(10)->copyable(),
                Tables\Columns\TextColumn::make('supporter.display_name')->label('الداعم')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('program.title')->label('البرنامج')->limit(30),
                Tables\Columns\TextColumn::make('original_amount_minor')->label('المبلغ الأصلي')->state(fn (SponsorshipContribution $r) => SponsorshipMoney::format((int) $r->original_amount_minor, $r->currency)),
                Tables\Columns\TextColumn::make('accounting_amount_minor')->label('المعادل المحاسبي')->state(fn (SponsorshipContribution $r) => $r->accounting_amount_minor === null ? '—' : SponsorshipMoney::format((int) $r->accounting_amount_minor, (string) $r->accounting_currency)),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => ['pending' => 'warning', 'confirmed' => 'success', 'refunded' => 'gray', 'cancelled' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\IconColumn::make('is_anonymous')->label('مجهولة')->boolean(),
                Tables\Columns\TextColumn::make('reference')->label('المرجع')->limit(20)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('سُجّلت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['pending' => 'معلّقة', 'confirmed' => 'مؤكَّدة', 'cancelled' => 'ملغاة', 'refunded' => 'مستردة']),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')->label('تأكيد')->color('success')->icon('heroicon-o-check-circle')
                    ->visible(fn (SponsorshipContribution $r) => $r->status === SponsorshipContribution::STATUS_PENDING && (auth()->user()?->can('sponsorships.confirm') ?? false))
                    ->form(fn (SponsorshipContribution $r) => $r->currency === SponsorshipMoney::accountingCurrency() ? [] : [
                        Forms\Components\TextInput::make('accounting_amount')->label('المعادل بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().') — إلزامي لأن العملة مختلفة')->required()->maxLength(20),
                    ])
                    ->requiresConfirmation()
                    ->action(fn (SponsorshipContribution $record, array $data) => self::guard(fn () => app(ContributionService::class)->confirm(auth()->user(), $record, $data['accounting_amount'] ?? null), 'تم تأكيد المساهمة.')),
                self::reasonAction('cancel', 'إلغاء', 'danger', SponsorshipContribution::STATUS_PENDING, fn (ContributionService $s, SponsorshipContribution $r, string $reason) => $s->cancel(auth()->user(), $r, $reason), 'أُلغيت المساهمة.'),
                self::reasonAction('refund', 'استرداد', 'warning', SponsorshipContribution::STATUS_CONFIRMED, fn (ContributionService $s, SponsorshipContribution $r, string $reason) => $s->refund(auth()->user(), $r, $reason), 'سُجّل الاسترداد (لا تُسحب مكافآت سبق منحها).'),
                Tables\Actions\Action::make('toggle_anonymous')->label('تبديل الإخفاء')->color('gray')->icon('heroicon-o-eye-slash')
                    ->visible(fn () => auth()->user()?->can('sponsorships.manage') ?? false)
                    ->action(fn (SponsorshipContribution $record) => self::guard(fn () => app(ContributionService::class)->setAnonymity(auth()->user(), $record, ! $record->is_anonymous), 'تغيّر إخفاء الهوية.')),
            ]);
    }

    protected static function reasonAction(string $name, string $label, string $color, string $from, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)
            ->visible(fn (SponsorshipContribution $r) => $r->status === $from && (auth()->user()?->can('sponsorships.confirm') ?? false))
            ->form([Forms\Components\Textarea::make('reason')->label('السبب (إلزامي)')->required()->minLength(3)->maxLength(255)])
            ->action(fn (SponsorshipContribution $record, array $data) => self::guard(fn () => $call(app(ContributionService::class), $record, (string) $data['reason']), $ok));
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
        return ['index' => Pages\ListSponsorshipContributions::route('/')];
    }
}

<?php

namespace App\Filament\Widgets;

use App\Services\Payments\PaymentAnalytics;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E32 - ملخص العمليات المالية لرأس طابور طلبات الدعم. تجميعات قليلة مخزَّنة مدة قصيرة؛ لا هويات ولا تفاصيل دفع. يراه sponsorships.view فقط. غير مكتشَف تلقائيًا بالرئيسية.
 */
class PaymentStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('sponsorships.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(PaymentAnalytics::class)->summary();

        return [
            Stat::make('بانتظار التحقق', number_format($s['awaiting_verification']))->description($s['pending'].' بانتظار الدفع')->icon('heroicon-o-clock')->color($s['awaiting_verification'] > 0 ? 'warning' : 'success'),
            Stat::make('حُقّق اليوم', number_format($s['verified_today']))->icon('heroicon-o-check-circle')->color('success'),
            Stat::make('مرفوض/ملغى/منتهٍ', number_format($s['failed']))->description('آخر '.(int) config('payments.analytics.window_days', 30).' يومًا')->icon('heroicon-o-x-circle')->color('gray'),
            Stat::make('مُسترد', number_format($s['refunded']))->icon('heroicon-o-arrow-uturn-left')->color('gray'),
            Stat::make('إجمالي المؤكَّد', SponsorshipMoney::format($s['confirmed_total_minor'], $s['currency']))->description($s['confirmed_count'].' مساهمة · المتوسط '.SponsorshipMoney::format($s['average_minor'], $s['currency']))->icon('heroicon-o-banknotes')->color('success'),
            Stat::make('حصة التحويل اليدوي', $s['manual_share'].'٪')->description('من الطلبات المحقَّقة')->icon('heroicon-o-building-library')->color('primary'),
        ];
    }
}

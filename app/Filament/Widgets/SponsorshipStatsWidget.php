<?php

namespace App\Filament\Widgets;

use App\Services\Sponsorship\SponsorshipAnalytics;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E30 - ملخص الرعاية لرأس صفحات الرعاية (غير مكتشَف تلقائيًا بالصفحة الرئيسية). أرقام مجمَّعة مخزَّنة مدة قصيرة؛ لا هويات ولا تفاصيل دفع. يراه sponsorships.view فقط.
 */
class SponsorshipStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('sponsorships.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(SponsorshipAnalytics::class)->summary();

        return [
            Stat::make('صافي الدعم المؤكَّد', SponsorshipMoney::format($s['confirmed_minor'], $s['currency']))->description($s['confirmed_count'].' مساهمة مؤكَّدة')->icon('heroicon-o-banknotes')->color('success'),
            Stat::make('مساهمات معلّقة', number_format($s['pending_count']))->icon('heroicon-o-clock')->color($s['pending_count'] > 0 ? 'warning' : 'success'),
            Stat::make('المسترد', SponsorshipMoney::format($s['refunded_minor'], $s['currency']))->description($s['refunded_count'].' مساهمة مستردة · '.$s['cancelled_count'].' ملغاة')->icon('heroicon-o-arrow-uturn-left')->color('gray'),
            Stat::make('برامج نشطة', number_format($s['active_programs']))->description($s['boost_programs'].' بتعزيز مكافآت · '.$s['locked_plans'].' خطة منافسة مقفلة')->icon('heroicon-o-gift')->color('primary'),
            Stat::make('جواهر رعاية ممنوحة', number_format($s['bonus_total']))->description($s['bonus_count'].' منحة')->icon('heroicon-o-sparkles')->color('primary'),
            Stat::make('أعلى برنامج تمويلًا', $s['top_program']['title'] ?? '—')->description(isset($s['top_program']) ? SponsorshipMoney::format($s['top_program']['funding_minor'], $s['currency']) : 'لا تمويل مؤكَّد بعد')->icon('heroicon-o-trophy')->color('success'),
        ];
    }
}

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

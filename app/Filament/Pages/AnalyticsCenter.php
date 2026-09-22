<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RegistrationGrowthChartWidget;
use App\Models\Season;
use App\Services\Analytics\CampaignAnalyticsService;
use App\Services\Analytics\EconomyAnalyticsService;
use App\Services\Analytics\PuzzleAnalyticsService;
use App\Services\Analytics\SecurityAnalyticsService;
use App\Services\Analytics\UserAnalyticsService;
use App\Services\ReportExportService;
use App\Support\AnalyticsCache;
use App\Support\AnalyticsPeriod;
use Filament\Pages\Page;

class AnalyticsCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'إدارة الوصول';

    protected static ?string $navigationLabel = 'التحليلات والتقارير';

    protected static ?string $slug = 'analytics';

    protected static string $view = 'filament.pages.analytics-center';

    public string $preset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public string $activeTab = 'overview';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getHeaderWidgets(): array
    {
        return [RegistrationGrowthChartWidget::class];
    }

    public function getPeriod(): AnalyticsPeriod
    {
        if ($this->preset === 'custom' && $this->customStart && $this->customEnd) {
            return AnalyticsPeriod::custom($this->customStart, $this->customEnd);
        }

        return AnalyticsPeriod::fromPreset($this->preset);
    }

    public function presetOptions(): array
    {
        return AnalyticsPeriod::presetOptions() + ['custom' => 'فترة مخصَّصة'];
    }

    public function refreshData(): void
    {
        // إصلاح: كانت Cache::flush() تمسح Cache التطبيق بالكامل (إعدادات
        // المنصة، صلاحيات، أي شيء آخر حسب الـDriver) - خطر غير مقبول. هذا
        // يزيد إصدار Cache التحليلات فقط؛ كل مفتاح قديم يصبح غير قابل
        // للوصول تلقائياً وينتهي وحده بـTTL الطبيعي - لا حذف فعلي، لا
        // تأثير على أي Cache آخر بالتطبيق إطلاقاً.
        AnalyticsCache::bumpVersion();
    }

    public function executiveOverview(): ?array
    {
        if (! auth()->user()?->can('analytics.view')) {
            return null;
        }

        $period = $this->getPeriod();
        $userService = app(UserAnalyticsService::class);
        $puzzleService = app(PuzzleAnalyticsService::class);

        $users = $userService->overview($period);
        $puzzles = $puzzleService->overview($period);

        $prevPeriod = $period->previous();
        $prevUsers = $userService->overview($prevPeriod);

        return [
            'total_users' => $users['total_users'],
            'new_users' => $users['new_users'],
            'new_users_change' => AnalyticsPeriod::percentChange($users['new_users'], $prevUsers['new_users']),
            'active_users' => $users['active_users'],
            'total_attempts' => $puzzles['total_attempts'],
            'success_rate' => $puzzles['success_rate'],
            'gems_issued' => auth()->user()?->can('analytics.financial') ? app(EconomyAnalyticsService::class)->gemsOverview($period)['issued_in_period'] : null,
            'pending_redemptions' => auth()->user()?->can('analytics.financial') ? app(EconomyAnalyticsService::class)->redemptionsOverview($period)['pending'] : null,
            'active_seasons' => Season::where('is_published', true)->count(),
            'open_fraud_flags' => auth()->user()?->can('analytics.security') ? app(SecurityAnalyticsService::class)->overview($period)['open_fraud_flags'] : null,
        ];
    }

    public function userAnalytics(): ?array
    {
        if (! auth()->user()?->can('analytics.users')) {
            return null;
        }

        $service = app(UserAnalyticsService::class);
        $period = $this->getPeriod();

        return [
            'overview' => $service->overview($period),
            'growth' => $service->registrationGrowth($period),
            'role_distribution' => $service->roleDistribution(),
            'engagement' => $service->engagement($period),
        ];
    }

    public function puzzleAnalytics(): ?array
    {
        if (! auth()->user()?->can('analytics.puzzles')) {
            return null;
        }

        $service = app(PuzzleAnalyticsService::class);
        $period = $this->getPeriod();

        return [
            'overview' => $service->overview($period),
            'top' => $service->topPuzzles($period),
            'difficulty' => $service->difficultyBreakdown($period),
            'game_types' => $service->gameTypeBreakdown($period),
            'challenges' => $service->challengeOverview(),
        ];
    }

    public function campaignAnalytics(): ?array
    {
        if (! auth()->user()?->can('analytics.campaigns')) {
            return null;
        }

        $service = app(CampaignAnalyticsService::class);

        return [
            'campaigns' => $service->allCampaignsSummary(),
            'seasons' => Season::with('campaign')->get()->map(fn (Season $s) => [
                'code' => $s->code,
                'is_published' => $s->is_published,
            ] + $service->seasonOverview($s))->all(),
        ];
    }

    public function economyAnalytics(): ?array
    {
        if (! auth()->user()?->can('analytics.financial')) {
            return null;
        }

        $service = app(EconomyAnalyticsService::class);
        $period = $this->getPeriod();

        return [
            'gems' => $service->gemsOverview($period),
            'wallet_distribution' => $service->walletBalanceDistribution(),
            'redemptions' => $service->redemptionsOverview($period),
        ];
    }

    public function securityAnalytics(): ?array
    {
        if (! auth()->user()?->can('analytics.security')) {
            return null;
        }

        return app(SecurityAnalyticsService::class)->overview($this->getPeriod());
    }

    /** للعرض بالـBlade فقط (إظهار/إخفاء الزر) - التفويض الفعلي عبر authorizeExport() داخل كل exportXxx(). */
    public function canExport(string $domainPermission): bool
    {
        return (bool) (auth()->user()?->can('reports.export') && auth()->user()?->can($domainPermission));
    }

    /**
     * نقطة تفويض موحَّدة لكل التصدير - لا abort_unless() مكرَّرة بكل دالة.
     * reports.export وحدها غير كافية (كانت الثغرة بـE7) - كل تقرير يحتاج
     * أيضاً صلاحية النطاق الخاصة به (Users→analytics.users،
     * Gems/Redemptions→analytics.financial، ...). Super Admin يتجاوز هذا
     * تلقائياً عبر Gate::before (E5) قبل وصوله هون أصلاً.
     */
    protected function authorizeExport(string $domainPermission): void
    {
        abort_unless(auth()->user()?->can('reports.export'), 403);
        abort_unless(auth()->user()?->can($domainPermission), 403);
    }

    public function exportUsers()
    {
        $this->authorizeExport('analytics.users');

        return app(ReportExportService::class)->usersReport($this->getPeriod());
    }

    public function exportPuzzleActivity()
    {
        $this->authorizeExport('analytics.puzzles');

        return app(ReportExportService::class)->puzzleActivityReport($this->getPeriod());
    }

    public function exportCampaignProgress()
    {
        $this->authorizeExport('analytics.campaigns');

        return app(ReportExportService::class)->campaignProgressReport($this->getPeriod());
    }

    public function exportGems()
    {
        $this->authorizeExport('analytics.financial');

        return app(ReportExportService::class)->gemsReport($this->getPeriod());
    }

    public function exportRedemptions()
    {
        $this->authorizeExport('analytics.financial');

        return app(ReportExportService::class)->redemptionsReport($this->getPeriod());
    }

    public function exportSecurity()
    {
        $this->authorizeExport('analytics.security');

        return app(ReportExportService::class)->securityReport($this->getPeriod());
    }
}
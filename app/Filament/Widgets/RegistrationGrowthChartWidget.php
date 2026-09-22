<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\UserAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\ChartWidget;

class RegistrationGrowthChartWidget extends ChartWidget
{
    protected static ?string $heading = 'نمو التسجيلات - آخر 30 يوماً';

    public static function canView(): bool
    {
        return auth()->user()?->can('analytics.users') ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $period = AnalyticsPeriod::fromPreset('last_30_days');
        $growth = app(UserAnalyticsService::class)->registrationGrowth($period);

        return [
            'datasets' => [
                [
                    'label' => 'تسجيلات جديدة',
                    'data' => $growth['values'],
                    'borderColor' => '#8b5cf6',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                ],
            ],
            'labels' => $growth['labels'],
        ];
    }
}
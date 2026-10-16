<?php

namespace App\Filament\Widgets;

use App\Services\Creators\CreatorAnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * E28 - ملخص منظومة المبدعين لرأس صفحات الإشراف (ليس بالصفحة الرئيسية: غير مكتشَف تلقائيًا). أرقام مجمَّعة مخزَّنة دقيقة؛ لا هوية لاعبين.
 */
class CreatorEcosystemStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('creators.view') ?? false;
    }

    protected function getStats(): array
    {
        $s = app(CreatorAnalyticsService::class)->adminSummary();

        return [
            Stat::make('مبدعون معتمدون', number_format($s['approved_creators']))->icon('heroicon-o-paint-brush')->color('primary'),
            Stat::make('طلبات انضمام معلَّقة', number_format($s['pending_applications']))->icon('heroicon-o-user-plus')->color($s['pending_applications'] > 0 ? 'warning' : 'success'),
            Stat::make('أحجيات بانتظار المراجعة', number_format($s['pending_submissions']))->icon('heroicon-o-clipboard-document-check')->color($s['pending_submissions'] > 0 ? 'warning' : 'success'),
            Stat::make('أحجيات مجتمع منشورة', number_format($s['published_puzzles']))->icon('heroicon-o-puzzle-piece')->color('primary'),
            Stat::make('إجمالي محاولات المجتمع', number_format($s['community_plays']))->icon('heroicon-o-play')->color('primary'),
            Stat::make('بلاغات مفتوحة', number_format($s['open_reports']))->icon('heroicon-o-flag')->color($s['open_reports'] > 0 ? 'danger' : 'success'),
        ];
    }
}

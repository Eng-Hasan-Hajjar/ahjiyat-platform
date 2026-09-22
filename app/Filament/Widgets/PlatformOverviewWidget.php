<?php

namespace App\Filament\Widgets;

use App\Models\Challenge;
use App\Models\FraudFlag;
use App\Models\Puzzle;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Models\Wallet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverviewWidget extends BaseWidget
{
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        return auth()->user()?->can('operations.dashboard_view') ?? false;
    }

    protected function getStats(): array
    {
        $stats = [];
        $user = auth()->user();

        if ($user?->can('users.view')) {
            $stats[] = Stat::make('إجمالي المستخدمين', User::role('player')->count())
                ->description('كل الحسابات المسجّلة (بدون الإدارة)')
                ->icon('heroicon-o-users')
                ->color('primary');
        }

        if ($user?->can('puzzles.view')) {
            $stats[] = Stat::make('الأحجيات المفعّلة', Puzzle::where('is_active', true)->count())
                ->description('ظاهرة حالياً للمستخدمين')
                ->icon('heroicon-o-puzzle-piece')
                ->color('primary');
        }

        if ($user?->can('challenges.view')) {
            $openChallenges = Challenge::where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->count();

            $stats[] = Stat::make('تحديات مفتوحة الآن', $openChallenges)
                ->description('يقدر المستخدمون ينضموا لها حالياً')
                ->icon('heroicon-o-trophy')
                ->color('primary');
        }

        if ($user?->can('redemptions.view')) {
            $pendingRedemptions = RedemptionRequest::where('status', RedemptionRequest::STATUS_PENDING)->count();

            $stats[] = Stat::make('طلبات استبدال قيد المراجعة', $pendingRedemptions)
                ->description($pendingRedemptions > 0 ? 'بانتظار قرارك' : 'لا يوجد طلبات معلّقة')
                ->icon('heroicon-o-gift')
                ->color($pendingRedemptions > 0 ? 'warning' : 'success');
        }

        if ($user?->can('fraud.view')) {
            $unresolvedFlags = FraudFlag::where('resolved', false)->count();

            $stats[] = Stat::make('علامات احتيال غير محلولة', $unresolvedFlags)
                ->description($unresolvedFlags > 0 ? 'تحتاج مراجعة' : 'لا يوجد بلاغات مفتوحة')
                ->icon('heroicon-o-shield-exclamation')
                ->color($unresolvedFlags > 0 ? 'danger' : 'success');
        }

        if ($user?->can('users.view_wallet')) {
            $gemsInCirculation = Wallet::sum('available_balance') + Wallet::sum('pending_balance');

            $stats[] = Stat::make('جواهر متداولة حالياً', number_format($gemsInCirculation))
                ->description('معلّقة + متاحة، بكل محافظ المنصة')
                ->icon('heroicon-o-sparkles')
                ->color('primary');
        }

        return $stats;
    }
}
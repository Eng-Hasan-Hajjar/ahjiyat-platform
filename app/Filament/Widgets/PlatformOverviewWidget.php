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
    // يظهر أول شي بأعلى الصفحة الرئيسية للوحة الإدارة - نظرة سريعة قبل الدخول لأي قسم
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        $pendingRedemptions = RedemptionRequest::where('status', RedemptionRequest::STATUS_PENDING)->count();
        $unresolvedFlags = FraudFlag::where('resolved', false)->count();

        $openChallenges = Challenge::where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->count();

        $gemsInCirculation = Wallet::sum('available_balance') + Wallet::sum('pending_balance');

        return [
            Stat::make('إجمالي المستخدمين', User::where('role', 'user')->count())
                ->description('كل الحسابات المسجّلة (بدون الإدارة)')
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('الأحجيات المفعّلة', Puzzle::where('is_active', true)->count())
                ->description('ظاهرة حالياً للمستخدمين')
                ->icon('heroicon-o-puzzle-piece')
                ->color('primary'),

            Stat::make('تحديات مفتوحة الآن', $openChallenges)
                ->description('يقدر المستخدمون ينضموا لها حالياً')
                ->icon('heroicon-o-trophy')
                ->color('primary'),

            Stat::make('طلبات استبدال قيد المراجعة', $pendingRedemptions)
                ->description($pendingRedemptions > 0 ? 'بانتظار قرارك' : 'لا يوجد طلبات معلّقة')
                ->icon('heroicon-o-gift')
                ->color($pendingRedemptions > 0 ? 'warning' : 'success'),

            Stat::make('علامات احتيال غير محلولة', $unresolvedFlags)
                ->description($unresolvedFlags > 0 ? 'تحتاج مراجعة' : 'لا يوجد بلاغات مفتوحة')
                ->icon('heroicon-o-shield-exclamation')
                ->color($unresolvedFlags > 0 ? 'danger' : 'success'),

            Stat::make('جواهر متداولة حالياً', number_format($gemsInCirculation))
                ->description('معلّقة + متاحة، بكل محافظ المنصة')
                ->icon('heroicon-o-sparkles')
                ->color('primary'),
        ];
    }
}
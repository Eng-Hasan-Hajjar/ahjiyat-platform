<?php

namespace App\Services\Analytics;

use App\Models\GemTransaction;
use App\Models\RedemptionRequest;
use App\Models\Wallet;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EconomyAnalyticsService
{
    public function gemsOverview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('economy.gems'), now()->addMinutes(10), function () use ($period) {
            $inPeriod = GemTransaction::whereBetween('created_at', [$period->start, $period->end]);

            return [
                'total_in_wallets' => (int) (Wallet::sum('available_balance') + Wallet::sum('pending_balance')),
                'issued_in_period' => (int) (clone $inPeriod)->where('amount', '>', 0)
                    ->whereIn('type', [GemTransaction::TYPE_EARN_PENDING])->sum('amount'),
                'spent_in_period' => (int) abs((clone $inPeriod)->where('type', GemTransaction::TYPE_REDEEM)->sum('amount')),
                'manual_adjustments_in_period' => (int) (clone $inPeriod)->where('type', GemTransaction::TYPE_ADMIN_ADJUSTMENT)->sum('amount'),
                'unique_earners' => (clone $inPeriod)->where('amount', '>', 0)->distinct('user_id')->count('user_id'),
                'breakdown_by_type' => $this->transactionTypeBreakdown($period),
            ];
        });
    }

    protected function transactionTypeBreakdown(AnalyticsPeriod $period): array
    {
        $labels = [
            GemTransaction::TYPE_EARN_PENDING => 'كسب (معلَّق)',
            GemTransaction::TYPE_RELEASE_AVAILABLE => 'إتاحة رصيد',
            GemTransaction::TYPE_REDEEM => 'استبدال',
            GemTransaction::TYPE_EXPIRE => 'انتهاء صلاحية',
            GemTransaction::TYPE_ADMIN_ADJUSTMENT => 'تعديل إداري',
        ];

        $rows = GemTransaction::whereBetween('created_at', [$period->start, $period->end])
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->get();

        return $rows->map(fn ($r) => [
            'type' => $labels[$r->type] ?? $r->type,
            'count' => (int) $r->count,
            'total_amount' => (int) $r->total,
        ])->all();
    }

    public function walletBalanceDistribution(): array
    {
        $buckets = ['0' => 0, '1-100' => 0, '101-500' => 0, '500+' => 0];

        Wallet::select('available_balance')->chunk(500, function ($wallets) use (&$buckets) {
            foreach ($wallets as $wallet) {
                $balance = $wallet->available_balance;
                $buckets[match (true) {
                    $balance <= 0 => '0',
                    $balance <= 100 => '1-100',
                    $balance <= 500 => '101-500',
                    default => '500+',
                }]++;
            }
        });

        return $buckets;
    }

    public function redemptionsOverview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('economy.redemptions'), now()->addMinutes(10), function () use ($period) {
            $inPeriod = RedemptionRequest::whereBetween('created_at', [$period->start, $period->end]);
            $total = (clone $inPeriod)->count();
            $approved = (clone $inPeriod)->whereIn('status', [RedemptionRequest::STATUS_APPROVED, RedemptionRequest::STATUS_FULFILLED])->count();
            $rejected = (clone $inPeriod)->where('status', RedemptionRequest::STATUS_REJECTED)->count();
            $decided = $approved + $rejected;

            $avgHoursExpr = DB::connection()->getDriverName() === 'sqlite'
                ? '(julianday(reviewed_at) - julianday(created_at)) * 24'
                : 'TIMESTAMPDIFF(SECOND, created_at, reviewed_at) / 3600';

            $avgHours = (clone $inPeriod)->whereNotNull('reviewed_at')
                ->select(DB::raw("AVG({$avgHoursExpr}) as avg_hours"))
                ->value('avg_hours');

            return [
                'total_requests' => $total,
                'pending' => (clone $inPeriod)->where('status', RedemptionRequest::STATUS_PENDING)->count(),
                'approved' => $approved,
                'rejected' => $rejected,
                'approval_rate' => $decided > 0 ? round(($approved / $decided) * 100, 1) : 0.0,
                'gems_redeemed' => (int) (clone $inPeriod)->whereIn('status', [RedemptionRequest::STATUS_APPROVED, RedemptionRequest::STATUS_FULFILLED])->sum('gems_amount'),
                'avg_processing_hours' => $avgHours !== null ? round((float) $avgHours, 1) : null,
            ];
        });
    }
}
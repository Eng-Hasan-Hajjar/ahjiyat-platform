<?php

namespace App\Services\Analytics;

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\RedemptionRequest;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EconomyAnalyticsService
{
    public function __construct(protected CurrencyRegistry $currencies) {}

    public function gemsOverview(AnalyticsPeriod $period, ?Currency $currency = null): array
    {
        $currency ??= $this->currencies->defaultEarnedCurrency();

        return Cache::remember($period->cacheKey('economy.gems', [$currency->id]), now()->addMinutes(10), function () use ($period, $currency) {
            $inPeriod = CurrencyTransaction::where('currency_id', $currency->id)
                ->whereBetween('created_at', [$period->start, $period->end]);

            return [
                'currency' => ['id' => $currency->id, 'name' => $currency->name, 'code' => $currency->code],
                'total_in_wallets' => (int) (Wallet::where('currency_id', $currency->id)->sum('available_balance')
                    + Wallet::where('currency_id', $currency->id)->sum('pending_balance')),
                'issued_in_period' => (int) (clone $inPeriod)->where('amount', '>', 0)
                    ->whereIn('type', [CurrencyTransaction::TYPE_EARN_PENDING])->sum('amount'),
                'spent_in_period' => (int) abs((clone $inPeriod)->where('type', CurrencyTransaction::TYPE_REDEEM)->sum('amount')),
                'manual_adjustments_in_period' => (int) (clone $inPeriod)->where('type', CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT)->sum('amount'),
                'unique_earners' => (clone $inPeriod)->where('amount', '>', 0)->distinct('user_id')->count('user_id'),
                'breakdown_by_type' => $this->transactionTypeBreakdown($period, $currency),
            ];
        });
    }

    public function allCurrenciesOverview(AnalyticsPeriod $period): array
    {
        return Currency::orderBy('sort_order')->get()->map(fn (Currency $c) => $this->gemsOverview($period, $c))->all();
    }

    protected function transactionTypeBreakdown(AnalyticsPeriod $period, Currency $currency): array
    {
        $labels = [
            CurrencyTransaction::TYPE_EARN_PENDING => 'كسب (معلَّق)',
            CurrencyTransaction::TYPE_RELEASE_AVAILABLE => 'إتاحة رصيد',
            CurrencyTransaction::TYPE_REDEEM => 'استبدال/صرف',
            CurrencyTransaction::TYPE_EXPIRE => 'انتهاء صلاحية',
            CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT => 'تعديل إداري',
            CurrencyTransaction::TYPE_PURCHASE => 'شراء',
        ];

        $rows = CurrencyTransaction::where('currency_id', $currency->id)
            ->whereBetween('created_at', [$period->start, $period->end])
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->get();

        return $rows->map(fn ($r) => [
            'type' => $labels[$r->type] ?? $r->type,
            'count' => (int) $r->count,
            'total_amount' => (int) $r->total,
        ])->all();
    }

    public function walletBalanceDistribution(?Currency $currency = null): array
    {
        $currency ??= $this->currencies->defaultEarnedCurrency();
        $buckets = ['0' => 0, '1-100' => 0, '101-500' => 0, '500+' => 0];

        Wallet::where('currency_id', $currency->id)->select('available_balance')->chunk(500, function ($wallets) use (&$buckets) {
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

    public function redemptionsOverview(AnalyticsPeriod $period, ?Currency $currency = null): array
    {
        $currency ??= $this->currencies->defaultEarnedCurrency();

        return Cache::remember($period->cacheKey('economy.redemptions', [$currency->id]), now()->addMinutes(10), function () use ($period, $currency) {
            $inPeriod = RedemptionRequest::where('currency_id', $currency->id)
                ->whereBetween('created_at', [$period->start, $period->end]);

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
                'currency' => ['id' => $currency->id, 'name' => $currency->name, 'code' => $currency->code],
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
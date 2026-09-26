<?php

namespace App\Services\Analytics;

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\StorePurchase;
use App\Services\Economy\CurrencyRegistry;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StoreAnalyticsService
{
    public function __construct(protected CurrencyRegistry $currencies) {}

    public function overview(AnalyticsPeriod $period, ?Currency $currency = null): array
    {
        $currency ??= $this->currencies->defaultEarnedCurrency();
        $cacheKey = $period->cacheKey('store.overview').':currency:'.$currency->id;

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period, $currency) {
            $inPeriod = StorePurchase::where('currency_id', $currency->id)
                ->whereBetween('created_at', [$period->start, $period->end]);

            $successfulStatuses = [StorePurchase::STATUS_PENDING_FULFILLMENT, StorePurchase::STATUS_FULFILLED];

            return [
                'currency' => ['id' => $currency->id, 'name' => $currency->name, 'code' => $currency->code],
                'purchases_count' => (clone $inPeriod)->whereIn('status', $successfulStatuses)->count(),
                'unique_buyers' => (clone $inPeriod)->whereIn('status', $successfulStatuses)->distinct('user_id')->count('user_id'),
                'pending_fulfillment' => (clone $inPeriod)->where('status', StorePurchase::STATUS_PENDING_FULFILLMENT)->count(),
                'refunds_count' => (clone $inPeriod)->where('status', StorePurchase::STATUS_REFUNDED)->count(),
                'total_spent' => (int) (clone $inPeriod)->whereIn('status', $successfulStatuses)->sum('price_amount'),
                'refunded_amount' => (int) (clone $inPeriod)->where('status', StorePurchase::STATUS_REFUNDED)->sum('price_amount'),
            ];
        });
    }

    public function allCurrenciesOverview(AnalyticsPeriod $period): array
    {
        return Currency::orderBy('sort_order')->get()->map(fn (Currency $c) => $this->overview($period, $c))->all();
    }

    public function topItems(AnalyticsPeriod $period, int $limit = 10): array
    {
        $cacheKey = $period->cacheKey('store.top_items').':limit:'.$limit;

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period, $limit) {
            return StorePurchase::whereBetween('created_at', [$period->start, $period->end])
                ->whereIn('status', [StorePurchase::STATUS_PENDING_FULFILLMENT, StorePurchase::STATUS_FULFILLED])
                ->select('store_item_id', DB::raw('COUNT(*) as purchase_count'))
                ->groupBy('store_item_id')
                ->orderByDesc('purchase_count')
                ->limit($limit)
                ->with('item:id,name')
                ->get()
                ->map(fn ($row) => ['item' => $row->item?->name ?? '—', 'purchases' => (int) $row->purchase_count])
                ->all();
        });
    }

    public function lowStockItems(int $threshold = 10): array
    {
        return StoreItem::query()
            ->whereNotNull('stock_limit')
            ->where('is_active', true)
            ->get()
            ->filter(fn (StoreItem $item) => $item->remainingStock() !== null && $item->remainingStock() <= $threshold)
            ->map(fn (StoreItem $item) => ['item' => $item->name, 'remaining' => $item->remainingStock()])
            ->values()
            ->all();
    }
}
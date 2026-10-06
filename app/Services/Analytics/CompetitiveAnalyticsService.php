<?php

namespace App\Services\Analytics;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveRewardGrant;
use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;

/**
 * تحليلات المنافسات (E18-E14/15): أرقام مجمَّعة فقط (لا هويات). اقتصاديًا: يقرأ **دفتر العملة الحالي** (reason = competitive_event_reward) ولا يحسب أي رصيد محفظة
 * ولا يخلق دفترًا ثانيًا. مخزَّنة مؤقتًا بمفتاح الفترة المُرقَّم (AnalyticsCache) كبقية خدمات التحليلات؛ لا Cache::flush.
 */
class CompetitiveAnalyticsService
{
    /** @return array<string, mixed> */
    public function overview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('competitive.overview'), now()->addMinutes(10), function () use ($period) {
            $between = [$period->start, $period->end];
            $public = [CompetitiveEvent::STATUS_PUBLISHED, CompetitiveEvent::STATUS_COMPLETED];

            $ledger = CurrencyTransaction::query()->where('reason', 'competitive_event_reward')->whereBetween('created_at', $between)
                ->selectRaw('currency_id, sum(amount) as total')->groupBy('currency_id')->pluck('total', 'currency_id');
            $names = Currency::query()->whereIn('id', $ledger->keys())->pluck('name', 'id');

            return [
                'events' => CompetitiveEvent::query()->whereIn('status', $public)->whereBetween('starts_at', $between)->count(),
                'finalized_events' => CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_COMPLETED)->whereBetween('finalized_at', $between)->count(),
                'participants' => CompetitiveEventParticipant::query()->whereBetween('registered_at', $between)->count(),
                'completed_results' => CompetitiveEventResult::query()->whereBetween('completed_at', $between)->count(),
                'unique_players' => CompetitiveEventResult::query()->whereBetween('completed_at', $between)->distinct()->count('user_id'),
                'rewards_granted' => CompetitiveRewardGrant::query()->where('status', CompetitiveRewardGrant::STATUS_GRANTED)->whereBetween('granted_at', $between)->count(),
                'rewards_failed_now' => CompetitiveRewardGrant::query()->where('status', CompetitiveRewardGrant::STATUS_FAILED)->count(),
                'currency_granted' => $ledger->map(fn ($total, $id) => ['currency' => $names[$id] ?? 'عملة', 'total' => (int) $total])->values()->all(),
                'top_events' => CompetitiveEvent::query()->whereIn('status', $public)->whereBetween('starts_at', $between)
                    ->orderByDesc('participants_count')->orderBy('id')->limit(5)->get(['title', 'participants_count', 'status'])
                    ->map(fn ($e) => ['title' => $e->title, 'participants' => $e->participants_count, 'status' => $e->status])->all(),
            ];
        });
    }
}

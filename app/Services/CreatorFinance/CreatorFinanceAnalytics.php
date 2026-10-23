<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorPayout;
use App\Models\CreatorRevenuePeriod;
use Illuminate\Support\Facades\DB;

/**
 * E39 - تحليلات مالية المبدعين للإدارة: أرقام مجمَّعة فقط (مطلوبات، دفعات، فترات). لا ترتيب مبدعين ولا لوحة متصدرين ولا هوية فردية؛
 * الاطلاع على حساب فردي يتم في صفحة الحسابات بصلاحيتها. كل رقم من استعلام تجميعي (لا حلقات PHP على الصفوف).
 */
class CreatorFinanceAnalytics
{
    /** @return array<string, mixed> */
    public function overview(): array
    {
        $currency = CreatorMoney::currency();

        $liability = DB::table('creator_earning_accounts')
            ->selectRaw('COUNT(*) as accounts, COALESCE(SUM(available_minor),0) as available, COALESCE(SUM(reserved_minor),0) as reserved, COALESCE(SUM(paid_lifetime_minor),0) as paid')
            ->first();

        $accountStates = [
            'enabled' => (int) CreatorEarningAccount::query()->where('monetization_status', CreatorEarningAccount::STATUS_ENABLED)->count(),
            'paused' => (int) CreatorEarningAccount::query()->where('monetization_status', CreatorEarningAccount::STATUS_PAUSED)->count(),
            'on_hold' => (int) CreatorEarningAccount::query()->whereNotNull('hold_reason_code')->count(),
        ];

        $payouts = DB::table('creator_payouts')->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount_minor),0) as total')->groupBy('status')->get()->keyBy('status');
        $oldest = CreatorPayout::query()->whereIn('status', CreatorPayout::OPEN_STATUSES)->min('requested_at');

        $periods = DB::table('creator_revenue_periods')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $recent = CreatorRevenuePeriod::query()->where('status', CreatorRevenuePeriod::STATUS_FINALIZED)->orderByDesc('finalized_at')->orderByDesc('id')
            ->limit(max(1, (int) config('creator_monetization.analytics.recent_periods', 6)))
            ->get(['public_id', 'label', 'starts_at', 'ends_at', 'pool_minor', 'allocated_minor', 'unallocated_minor', 'eligible_creators', 'total_units'])
            ->map(fn (CreatorRevenuePeriod $p) => [
                'public_id' => $p->public_id, 'label' => $p->label, 'starts_at' => $p->starts_at, 'ends_at' => $p->ends_at,
                'pool_minor' => (int) $p->pool_minor, 'allocated_minor' => (int) $p->allocated_minor, 'unallocated_minor' => (int) $p->unallocated_minor,
                'eligible_creators' => (int) $p->eligible_creators, 'total_units' => (int) $p->total_units,
            ])->all();

        $entries30 = DB::table('creator_earning_entries')->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('type, COUNT(*) as n')->groupBy('type')->pluck('n', 'type');

        return [
            'currency' => $currency,
            'accounts' => (int) $liability->accounts,
            'liability' => [
                'available_minor' => (int) $liability->available,
                'reserved_minor' => (int) $liability->reserved,
                'owed_minor' => (int) $liability->available + (int) $liability->reserved,
                'paid_lifetime_minor' => (int) $liability->paid,
            ],
            'account_states' => $accountStates,
            'payouts' => collect(CreatorPayout::STATUSES)->map(fn ($label, $status) => [
                'label' => $label,
                'count' => (int) ($payouts[$status]->n ?? 0),
                'total_minor' => (int) ($payouts[$status]->total ?? 0),
            ])->all(),
            'oldest_open_payout_hours' => $oldest !== null ? (int) floor(now()->diffInHours(\Illuminate\Support\Carbon::parse($oldest), true)) : null,
            'periods' => collect(CreatorRevenuePeriod::STATUSES)->map(fn ($label, $status) => ['label' => $label, 'count' => (int) ($periods[$status] ?? 0)])->all(),
            'recent_periods' => $recent,
            'entries_30d' => collect(CreatorEarningEntry::TYPES)->map(fn ($label, $type) => ['label' => $label, 'count' => (int) ($entries30[$type] ?? 0)])->all(),
        ];
    }
}

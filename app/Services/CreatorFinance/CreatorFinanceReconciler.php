<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorPayout;
use App\Models\CreatorRevenuePeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E39 - مصالحة مالية المبدعين (قراءة فقط افتراضيًا). تقارن لقطات الحسابات بالدفتر والحجوزات بالدفعات المفتوحة والمصروف بالدفعات المصروفة
 * وتخصيصات الفترات المُقفلة بقيودها. الإصلاح الوحيد المسموح (--fix-balances) إعادة بناء **لقطة الحساب المشتقة** من الدفتر؛ كل ما عداه يُبلَّغ ويُعالَج يدويًا
 * (لا تعديل لدفتر ولا لدفعة ولا لفترة). تنتهي بالقائمة الكاملة للنتائج؛ النتيجة «نظيفة» إن لم يبقَ شيء بعد الإصلاح.
 */
class CreatorFinanceReconciler
{
    public function __construct(protected CreatorLedgerService $ledger) {}

    /**
     * @return array{checked_accounts:int, checked_periods:int, findings:list<array{code:string, subject:string, detail:string, fixable:bool, fixed:bool}>, fixed:int, remaining:int}
     */
    public function run(int $limit = 500, bool $fixBalances = false, ?User $actor = null): array
    {
        $limit = max(1, $limit);
        $findings = [];
        $fixed = 0;

        $accounts = CreatorEarningAccount::query()->orderBy('id')->limit($limit)->get();

        foreach ($accounts->chunk(200) as $chunk) {
            $ids = $chunk->pluck('id')->all();
            $sums = DB::table('creator_earning_entries')->whereIn('creator_earning_account_id', $ids)
                ->selectRaw('creator_earning_account_id as id, SUM(available_delta_minor) as a, SUM(reserved_delta_minor) as r, SUM(paid_delta_minor) as p')->groupBy('creator_earning_account_id')->get()->keyBy('id');
            $payouts = DB::table('creator_payouts')->whereIn('creator_earning_account_id', $ids)
                ->selectRaw('creator_earning_account_id as id, status, COUNT(*) as n, SUM(amount_minor) as total')->groupBy('creator_earning_account_id', 'status')->get()->groupBy('id');
            $currencyBad = DB::table('creator_earning_entries as e')->join('creator_earning_accounts as a', 'a.id', '=', 'e.creator_earning_account_id')
                ->whereIn('e.creator_earning_account_id', $ids)->whereColumn('e.currency', '<>', 'a.currency')->distinct()->pluck('e.creator_earning_account_id')->all();

            foreach ($chunk as $account) {
                $key = 'account:'.$account->public_id;
                $a = (int) ($sums[$account->id]->a ?? 0);
                $r = (int) ($sums[$account->id]->r ?? 0);
                $p = (int) ($sums[$account->id]->p ?? 0);
                $byStatus = collect($payouts[$account->id] ?? [])->keyBy('status');
                $openTotal = 0;
                $openCount = 0;

                foreach (CreatorPayout::OPEN_STATUSES as $s) {
                    $openTotal += (int) ($byStatus[$s]->total ?? 0);
                    $openCount += (int) ($byStatus[$s]->n ?? 0);
                }

                if ($a < 0 || $r < 0 || $p < 0) {
                    $findings[] = $this->finding('ledger_negative_balance', $key, "الدفتر يعطي رصيدًا سالبًا (متاح {$a}، محجوز {$r}، مدفوع {$p}).", false);
                }

                if ((int) $account->available_minor !== $a || (int) $account->reserved_minor !== $r || (int) $account->paid_lifetime_minor !== $p) {
                    $drift = $this->finding('snapshot_drift', $key, "اللقطة (متاح {$account->available_minor}، محجوز {$account->reserved_minor}، مدفوع {$account->paid_lifetime_minor}) تخالف الدفتر ({$a}/{$r}/{$p}).", true);

                    if ($fixBalances && $a >= 0 && $r >= 0 && $p >= 0 && $this->ledger->rebuildSnapshot($account, $actor)) {
                        $drift['fixed'] = true;
                        $fixed++;
                    }

                    $findings[] = $drift;
                }

                if ($r !== $openTotal) {
                    $findings[] = $this->finding('reserved_vs_open_payouts', $key, "المحجوز في الدفتر ({$r}) لا يساوي مجموع الدفعات المفتوحة ({$openTotal}).", false);
                }

                $paidTotal = (int) ($byStatus[CreatorPayout::STATUS_PAID]->total ?? 0);

                if ($p !== $paidTotal) {
                    $findings[] = $this->finding('paid_vs_paid_payouts', $key, "المدفوع في الدفتر ({$p}) لا يساوي مجموع الدفعات المصروفة ({$paidTotal}).", false);
                }

                if ($openCount > 1) {
                    $findings[] = $this->finding('multiple_open_payouts', $key, "{$openCount} طلبات دفعة مفتوحة لحساب واحد.", false);
                }

                if (in_array($account->id, $currencyBad, true)) {
                    $findings[] = $this->finding('currency_mismatch', $key, 'قيود بعملة تخالف عملة الحساب.', false);
                }
            }
        }

        $findings = array_merge($findings, $this->payoutChecks($limit), $this->periodChecks($limit));

        $remaining = count(array_filter($findings, fn (array $f) => ! $f['fixed']));

        return [
            'checked_accounts' => $accounts->count(),
            'checked_periods' => (int) min($limit, CreatorRevenuePeriod::query()->where('status', CreatorRevenuePeriod::STATUS_FINALIZED)->count()),
            'findings' => $findings,
            'fixed' => $fixed,
            'remaining' => $remaining,
        ];
    }

    /** @return list<array{code:string, subject:string, detail:string, fixable:bool, fixed:bool}> */
    protected function payoutChecks(int $limit): array
    {
        $out = [];

        $payouts = DB::table('creator_payouts')->orderByDesc('id')->limit($limit)->get(['id', 'public_id', 'status', 'open_account_id', 'creator_earning_account_id', 'external_reference', 'currency']);
        $ids = $payouts->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $keys = [];

        foreach (array_chunk($ids, 400) as $part) {
            foreach (DB::table('creator_earning_entries')->whereIn('creator_payout_id', $part)->get(['creator_payout_id', 'type']) as $e) {
                $keys[(int) $e->creator_payout_id][$e->type] = true;
            }
        }

        foreach ($payouts as $p) {
            $subject = 'payout:'.$p->public_id;
            $types = $keys[(int) $p->id] ?? [];
            $open = in_array($p->status, CreatorPayout::OPEN_STATUSES, true);

            if (empty($types['payout_reserve'])) {
                $out[] = $this->finding('payout_missing_reserve', $subject, 'طلب دفعة بلا قيد حجز.', false);
            }

            if (in_array($p->status, [CreatorPayout::STATUS_REJECTED, CreatorPayout::STATUS_CANCELLED], true) && empty($types['payout_release'])) {
                $out[] = $this->finding('payout_missing_release', $subject, 'دفعة مرفوضة/ملغاة بلا قيد فكّ حجز.', false);
            }

            if ($p->status === CreatorPayout::STATUS_PAID && (empty($types['payout_settle']) || $p->external_reference === null)) {
                $out[] = $this->finding('payout_missing_settlement', $subject, 'دفعة مصروفة بلا قيد صرف أو مرجع خارجي.', false);
            }

            if ($open && (int) $p->open_account_id !== (int) $p->creator_earning_account_id) {
                $out[] = $this->finding('payout_open_marker', $subject, 'دفعة مفتوحة بعلامة فتح خاطئة.', false);
            }

            if (! $open && $p->open_account_id !== null) {
                $out[] = $this->finding('payout_open_marker', $subject, 'دفعة منتهية ما زالت بعلامة فتح.', false);
            }
        }

        return $out;
    }

    /** @return list<array{code:string, subject:string, detail:string, fixable:bool, fixed:bool}> */
    protected function periodChecks(int $limit): array
    {
        $out = [];

        $periods = CreatorRevenuePeriod::query()->where('status', CreatorRevenuePeriod::STATUS_FINALIZED)->orderByDesc('id')->limit($limit)->get();

        foreach ($periods as $period) {
            $subject = 'period:'.$period->public_id;
            $row = DB::table('creator_revenue_allocations')->where('creator_revenue_period_id', $period->id)
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(allocated_minor),0) as total, COALESCE(SUM(qualified_units),0) as units')->first();

            if ((int) $period->allocated_minor + (int) $period->unallocated_minor !== (int) $period->pool_minor) {
                $out[] = $this->finding('period_pool_mismatch', $subject, 'الموزَّع + غير الموزَّع لا يساوي المجمّع.', false);
            }

            if ((int) $row->total !== (int) $period->allocated_minor || (int) $row->n !== (int) $period->eligible_creators || (int) $row->units !== (int) $period->total_units) {
                $out[] = $this->finding('period_allocation_totals', $subject, 'مجاميع التخصيصات لا تطابق أرقام الفترة.', false);
            }

            $entries = DB::table('creator_earning_entries')->where('creator_revenue_period_id', $period->id)->where('type', 'revenue_share')
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(available_delta_minor),0) as total')->first();
            $positive = (int) DB::table('creator_revenue_allocations')->where('creator_revenue_period_id', $period->id)->where('allocated_minor', '>', 0)->count();

            if ((int) $entries->total !== (int) $period->allocated_minor || (int) $entries->n !== $positive) {
                $out[] = $this->finding('period_ledger_mismatch', $subject, 'قيود الدفتر لا تطابق تخصيصات الفترة.', false);
            }
        }

        return $out;
    }

    /** @return array{code:string, subject:string, detail:string, fixable:bool, fixed:bool} */
    protected function finding(string $code, string $subject, string $detail, bool $fixable): array
    {
        return ['code' => $code, 'subject' => $subject, 'detail' => $detail, 'fixable' => $fixable, 'fixed' => false];
    }
}

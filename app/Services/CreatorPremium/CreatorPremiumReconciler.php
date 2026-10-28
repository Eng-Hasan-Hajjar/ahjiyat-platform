<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorDirectSale;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use Illuminate\Support\Facades\DB;

/**
 * E43 - مصالحة السوق المميز: **قراءة فقط** (لا تعديل لوصول ولا بيع ولا دفتر ولا طلب). تقارن بين السلطات الأربع: الطلب المؤكَّد (E41) ← الوصول ← لقطة البيع ← قيد الدفتر (E39)،
 * وتُبلغ عن كل انحراف بنتيجة مقروءة. الإصلاح دائمًا بقرار بشري عبر عمليات الإدارة الموثَّقة (استرداد/إعادة محاولة العكس/تحميل المنصة/تعديل E39)، فلا إصلاح آليًا هنا عمدًا:
 * تعديل مالي خلفي صامت أخطر من الانحراف الذي يعالجه.
 *
 * الفحوص (code): purchase_without_access، purchase_without_sale، access_without_verified_purchase، refunded_purchase_with_active_access،
 * sale_credit_mismatch (نوع/مبلغ/حساب/مفتاح القيد)، sale_amount_mismatch (الإجمالي/العملة/عملة الحساب/إعادة حساب القسمة بالقاعدة)، sale_wrong_creator،
 * refunded_purchase_without_reversal، reversal_mismatch، sale_needs_finance_review، orphan_credit_entry، offer_currency_mismatch، sale_after_pause، purchase_in_review.
 */
class CreatorPremiumReconciler
{
    /**
     * @return array{checked_purchases:int, checked_sales:int, findings:list<array{code:string, subject:string, detail:string}>, remaining:int, truncated:bool}
     */
    public function run(?int $limit = null): array
    {
        $max = max(1, (int) config('creator_premium.reconcile.max_limit', 5000));
        $limit = min($max, max(1, $limit ?? (int) config('creator_premium.reconcile.default_limit', 500)));
        $cap = max(1, (int) config('creator_premium.reconcile.max_findings', 200));

        $findings = [];
        $purchases = DB::table('digital_purchases')
            ->where('product_type', DigitalPurchase::PRODUCT_CREATOR_SERIES)
            ->whereIn('status', [DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED])
            ->orderByDesc('id')->limit($limit)
            ->get(['id', 'public_id', 'user_id', 'product_id', 'amount_minor', 'currency', 'status']);

        $sales = DB::table('creator_direct_sales')->orderByDesc('id')->limit($limit)->get();

        $this->purchaseChecks($purchases, $findings);
        $this->saleChecks($sales, $findings);
        $this->accessChecks($limit, $findings);
        $this->orphanCredits($limit, $findings);
        $this->offerChecks($limit, $findings);
        $this->reviewChecks($limit, $findings);

        $truncated = count($findings) > $cap;

        return [
            'checked_purchases' => $purchases->count(),
            'checked_sales' => $sales->count(),
            'findings' => array_slice($findings, 0, $cap),
            'remaining' => count($findings),
            'truncated' => $truncated,
        ];
    }

    /** @param list<array{code:string, subject:string, detail:string}> $findings */
    protected function purchaseChecks($purchases, array &$findings): void
    {
        if ($purchases->isEmpty()) {
            return;
        }

        $ids = $purchases->pluck('id')->all();
        $accessBySource = DB::table('creator_series_accesses')->whereIn('source_digital_purchase_id', $ids)->get(['source_digital_purchase_id', 'user_id', 'creator_series_id', 'revoked_at'])->keyBy('source_digital_purchase_id');
        $saleByPurchase = DB::table('creator_direct_sales')->whereIn('digital_purchase_id', $ids)->get(['digital_purchase_id', 'status'])->keyBy('digital_purchase_id');

        foreach ($purchases as $p) {
            $subject = 'purchase:'.$p->public_id;
            $access = $accessBySource[$p->id] ?? null;
            $sale = $saleByPurchase[$p->id] ?? null;

            if ($p->status === DigitalPurchase::STATUS_VERIFIED) {
                if ($access === null || $access->revoked_at !== null || (int) $access->user_id !== (int) $p->user_id || (int) $access->creator_series_id !== (int) $p->product_id) {
                    $findings[] = $this->finding('purchase_without_access', $subject, 'طلب مؤكَّد بلا وصول فعّال مطابق لمشتريه وسلسلته.');
                }

                if ($sale === null) {
                    $findings[] = $this->finding('purchase_without_sale', $subject, 'طلب مؤكَّد بلا سجل بيع مباشر (لا قيد حصة للمبدع).');
                }
            } else {
                if ($access !== null && $access->revoked_at === null) {
                    $findings[] = $this->finding('refunded_purchase_with_active_access', $subject, 'طلب مسترد ما زال وصوله فعّالًا.');
                }

                if ($sale !== null && $sale->status === CreatorDirectSale::STATUS_CREDITED) {
                    $findings[] = $this->finding('refunded_purchase_without_reversal', $subject, 'طلب مسترد وحصة المبدع لم تُعكس ولم تُعلَّم للمراجعة المالية.');
                }
            }
        }
    }

    /** @param list<array{code:string, subject:string, detail:string}> $findings */
    protected function saleChecks($sales, array &$findings): void
    {
        if ($sales->isEmpty()) {
            return;
        }

        $purchases = DB::table('digital_purchases')->whereIn('id', $sales->pluck('digital_purchase_id'))->get(['id', 'amount_minor', 'currency', 'status', 'product_id'])->keyBy('id');
        $entryIds = $sales->pluck('credit_entry_id')->merge($sales->pluck('reversal_entry_id'))->filter()->unique()->values()->all();
        $entries = DB::table('creator_earning_entries')->whereIn('id', $entryIds)->get()->keyBy('id');
        $accounts = DB::table('creator_earning_accounts')->whereIn('id', $sales->pluck('creator_earning_account_id'))->get(['id', 'creator_profile_id', 'currency'])->keyBy('id');
        $series = DB::table('creator_series')->whereIn('id', $sales->pluck('creator_series_id'))->get(['id', 'creator_profile_id'])->keyBy('id');

        foreach ($sales as $s) {
            $subject = 'sale:'.$s->public_id;
            $purchase = $purchases[$s->digital_purchase_id] ?? null;
            $account = $accounts[$s->creator_earning_account_id] ?? null;
            $credit = $entries[$s->credit_entry_id] ?? null;
            $gross = (int) $s->gross_minor;
            $expectedCreator = intdiv($gross, 10000) * (int) $s->creator_bps + intdiv(($gross % 10000) * (int) $s->creator_bps, 10000);

            if ($credit === null || $credit->type !== CreatorEarningEntry::TYPE_DIRECT_SALE_CREDIT || (int) $credit->available_delta_minor !== (int) $s->creator_share_minor
                || (int) $credit->creator_earning_account_id !== (int) $s->creator_earning_account_id || $credit->source_key !== CreatorDirectSaleService::creditKey((int) $s->digital_purchase_id)
                || (int) $credit->reserved_delta_minor !== 0 || (int) $credit->paid_delta_minor !== 0) {
                $findings[] = $this->finding('sale_credit_mismatch', $subject, 'قيد الدفتر لا يطابق البيع (النوع/المبلغ/الحساب/المفتاح).');
            }

            if ($purchase === null || $gross !== (int) $purchase->amount_minor || $s->currency !== $purchase->currency || $account === null || $s->currency !== $account->currency
                || (int) $s->creator_share_minor !== $expectedCreator || (int) $s->creator_share_minor + (int) $s->platform_share_minor !== $gross || (int) $s->creator_bps + (int) $s->platform_bps !== 10000) {
                $findings[] = $this->finding('sale_amount_mismatch', $subject, 'الإجمالي/العملة/القسمة لا تطابق الطلب أو حساب المبدع أو قاعدة الحساب المعتمدة.');
            }

            $seriesRow = $series[$s->creator_series_id] ?? null;

            if ($account === null || $seriesRow === null || (int) $account->creator_profile_id !== (int) $s->creator_profile_id || (int) $seriesRow->creator_profile_id !== (int) $s->creator_profile_id
                || ($purchase !== null && (int) $purchase->product_id !== (int) $s->creator_series_id)) {
                $findings[] = $this->finding('sale_wrong_creator', $subject, 'المبدع/الحساب/السلسلة في البيع لا تتسق مع الطلب.');
            }

            if ($s->status === CreatorDirectSale::STATUS_REFUNDED) {
                $reversal = $s->reversal_entry_id === null ? null : ($entries[$s->reversal_entry_id] ?? null);

                if ($reversal === null || $reversal->type !== CreatorEarningEntry::TYPE_DIRECT_SALE_REVERSAL || (int) $reversal->available_delta_minor !== -1 * (int) $s->creator_share_minor
                    || $reversal->source_key !== CreatorDirectSaleService::reversalKey((int) $s->digital_purchase_id) || (int) $reversal->creator_earning_account_id !== (int) $s->creator_earning_account_id) {
                    $findings[] = $this->finding('reversal_mismatch', $subject, 'بيع معلَّم مسترَدًّا بلا قيد عكس مطابق.');
                }
            } elseif ($s->reversal_entry_id !== null) {
                $findings[] = $this->finding('reversal_mismatch', $subject, 'قيد عكس مربوط ببيع غير مسترد.');
            }

            if ($s->status === CreatorDirectSale::STATUS_NEEDS_REVIEW) {
                $findings[] = $this->finding('sale_needs_finance_review', $subject, 'استرداد مؤكَّد لم تُعكس حصة المبدع منه (دُفعت أو لا يكفي المتاح): بانتظار قرار مالي (إعادة محاولة أو تحميل المنصة).');
            }
        }
    }

    /** وصول فعّال لا يسنده طلب مؤكَّد (صف مسحوب لا يُفحص). @param list<array{code:string, subject:string, detail:string}> $findings */
    protected function accessChecks(int $limit, array &$findings): void
    {
        $rows = DB::table('creator_series_accesses as a')
            ->leftJoin('digital_purchases as d', 'd.id', '=', 'a.source_digital_purchase_id')
            ->whereNull('a.revoked_at')
            ->where(fn ($q) => $q->whereNull('d.id')->orWhere('d.status', '<>', DigitalPurchase::STATUS_VERIFIED)->orWhereColumn('d.user_id', '<>', 'a.user_id')->orWhereColumn('d.product_id', '<>', 'a.creator_series_id'))
            ->orderByDesc('a.id')->limit($limit)->get(['a.public_id', 'd.status as purchase_status']);

        foreach ($rows as $r) {
            $findings[] = $this->finding('access_without_verified_purchase', 'access:'.$r->public_id, 'وصول فعّال لا يسنده طلب مؤكَّد مطابق'.($r->purchase_status !== null ? " (حالة الطلب: {$r->purchase_status})" : ' (الطلب مفقود)').'.');
        }
    }

    /** قيد حصة بيع لا يملكه أي سجل بيع. @param list<array{code:string, subject:string, detail:string}> $findings */
    protected function orphanCredits(int $limit, array &$findings): void
    {
        $rows = DB::table('creator_earning_entries as e')
            ->leftJoin('creator_direct_sales as s', 's.credit_entry_id', '=', 'e.id')
            ->where('e.type', CreatorEarningEntry::TYPE_DIRECT_SALE_CREDIT)->whereNull('s.id')
            ->orderByDesc('e.id')->limit($limit)->get(['e.id', 'e.source_key']);

        foreach ($rows as $r) {
            $findings[] = $this->finding('orphan_credit_entry', 'entry:'.$r->id, 'قيد direct_sale_credit بلا سجل بيع ('.$r->source_key.').');
        }
    }

    /** @param list<array{code:string, subject:string, detail:string}> $findings */
    protected function offerChecks(int $limit, array &$findings): void
    {
        $rows = DB::table('creator_series_sale_offers as o')
            ->join('creator_earning_accounts as a', 'a.creator_profile_id', '=', 'o.creator_profile_id')
            ->whereIn('o.status', CreatorSeriesSaleOffer::LIVE_STATUSES)->whereColumn('o.currency', '<>', 'a.currency')
            ->orderByDesc('o.id')->limit($limit)->get(['o.public_id']);

        foreach ($rows as $r) {
            $findings[] = $this->finding('offer_currency_mismatch', 'offer:'.$r->public_id, 'عملة العرض تخالف عملة حساب أرباح المبدع (الخيار A).');
        }

        $late = DB::table('creator_series_sale_offers as o')
            ->join('creator_direct_sales as s', 's.creator_series_sale_offer_id', '=', 'o.id')
            ->where('o.status', CreatorSeriesSaleOffer::STATUS_PAUSED)->whereNotNull('o.paused_at')->whereColumn('s.sold_at', '>', 'o.paused_at')
            ->orderByDesc('s.id')->limit($limit)->get(['o.public_id as offer', 's.public_id as sale']);

        foreach ($late as $r) {
            $findings[] = $this->finding('sale_after_pause', 'sale:'.$r->sale, 'بيع سُجّل بعد إيقاف العرض ('.$r->offer.').');
        }
    }

    /** طلبات السلاسل التي عليها علم مراجعة مالية لم يُحلّ (دفع وصل ولم يُفتح الوصول بعد). @param list<array{code:string, subject:string, detail:string}> $findings */
    protected function reviewChecks(int $limit, array &$findings): void
    {
        $rows = DB::table('digital_purchases')
            ->where('product_type', DigitalPurchase::PRODUCT_CREATOR_SERIES)->whereNotNull('review_flag')->whereNull('review_resolved_at')
            ->orderByDesc('id')->limit($limit)->get(['public_id', 'review_flag']);

        foreach ($rows as $r) {
            $findings[] = $this->finding('purchase_in_review', 'purchase:'.$r->public_id, 'علم مراجعة مالية قائم: '.(DigitalPurchase::REVIEW_FLAGS[$r->review_flag] ?? $r->review_flag).'.');
        }
    }

    /** @return array{code:string, subject:string, detail:string} */
    protected function finding(string $code, string $subject, string $detail): array
    {
        return ['code' => $code, 'subject' => $subject, 'detail' => $detail];
    }
}

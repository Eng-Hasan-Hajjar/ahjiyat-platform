<?php

namespace App\Services\Payments;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use Illuminate\Support\Facades\DB;

/**
 * E32 - مطابقة مالية **للقراءة فقط**: تكتشف عدم اتساق بين طلبات الدفع والمساهمات ولا تُصلح شيئًا أبدًا (إصلاح التاريخ المالي إجراء إداري صريح ومدقَّق).
 * كل فحص استعلام واحد محدود بسقف؛ المخرجات معرّفات علنية (ULID) ورموز مرجع وأكواد فقط — لا أسماء ولا بريد ولا مسارات إثبات ولا ملاحظات ولا أسرار.
 *
 * الأكواد: verified_without_contribution · contribution_not_confirmed · amount_mismatch · currency_mismatch · refund_state_mismatch · orphan_materialized_contribution · duplicate_provider_reference · duplicate_reference
 */
class PaymentReconciliationService
{
    public const CODES = [
        'verified_without_contribution' => 'طلب محقَّق بلا مساهمة مرتبطة',
        'contribution_not_confirmed' => 'طلب محقَّق ومساهمته غير مؤكَّدة',
        'amount_mismatch' => 'مبلغ الطلب لا يطابق المساهمة',
        'currency_mismatch' => 'عملة الطلب لا تطابق المساهمة',
        'refund_state_mismatch' => 'حالة الاسترداد غير متطابقة بين الطلب والمساهمة',
        'orphan_materialized_contribution' => 'مساهمة مجسَّدة من مرجع طلب لا يرتبط بها',
        'duplicate_provider_reference' => 'مرجع مزوّد مكرر',
        'duplicate_reference' => 'رمز مرجع مكرر',
    ];

    /**
     * @return list<array{code: string, payment: ?string, reference: ?string, contribution: ?string}>
     */
    public function run(): array
    {
        $limit = max(1, (int) config('payments.reconcile.max_findings', 200));
        $findings = [];

        $add = function (string $code, iterable $rows) use (&$findings, $limit): void {
            foreach ($rows as $row) {
                if (count($findings) >= $limit) {
                    return;
                }

                $findings[] = ['code' => $code, 'payment' => $row->payment ?? null, 'reference' => $row->reference ?? null, 'contribution' => $row->contribution ?? null];
            }
        };

        $joined = fn () => DB::table('sponsorship_payments as p')->join('sponsorship_contributions as c', 'c.id', '=', 'p.sponsorship_contribution_id')
            ->select(['p.public_id as payment', 'p.reference as reference', 'c.public_id as contribution'])->orderBy('p.id')->limit($limit);

        $add('verified_without_contribution', DB::table('sponsorship_payments as p')->where('p.status', SponsorshipPayment::STATUS_VERIFIED)->whereNull('p.sponsorship_contribution_id')
            ->select(['p.public_id as payment', 'p.reference as reference'])->orderBy('p.id')->limit($limit)->get());

        $add('contribution_not_confirmed', $joined()->where('p.status', SponsorshipPayment::STATUS_VERIFIED)->where('c.status', '!=', SponsorshipContribution::STATUS_CONFIRMED)->get());

        $add('amount_mismatch', $joined()->whereIn('p.status', [SponsorshipPayment::STATUS_VERIFIED, SponsorshipPayment::STATUS_REFUNDED])
            ->where(fn ($q) => $q->whereColumn('c.original_amount_minor', '!=', 'p.amount_minor')->orWhereColumn('c.accounting_amount_minor', '!=', 'p.accounting_amount_minor'))->get());

        $add('currency_mismatch', $joined()->whereIn('p.status', [SponsorshipPayment::STATUS_VERIFIED, SponsorshipPayment::STATUS_REFUNDED])
            ->where(fn ($q) => $q->whereColumn('c.currency', '!=', 'p.currency')->orWhereColumn('c.accounting_currency', '!=', 'p.accounting_currency'))->get());

        $add('refund_state_mismatch', $joined()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('p.status', SponsorshipPayment::STATUS_REFUNDED)->where('c.status', '!=', SponsorshipContribution::STATUS_REFUNDED))
            ->orWhere(fn ($w) => $w->where('p.status', SponsorshipPayment::STATUS_VERIFIED)->where('c.status', SponsorshipContribution::STATUS_REFUNDED)))->get());

        // مساهمة تحمل مرجع طلب (provider + external_reference = reference) لكن الطلب لا يشير إليها.
        $add('orphan_materialized_contribution', DB::table('sponsorship_contributions as c')
            ->join('sponsorship_payments as p', function ($join) {
                $join->on('p.provider', '=', 'c.provider')->on('p.reference', '=', 'c.external_reference');
            })
            ->where(fn ($q) => $q->whereNull('p.sponsorship_contribution_id')->orWhereColumn('p.sponsorship_contribution_id', '!=', 'c.id'))
            ->select(['p.public_id as payment', 'p.reference as reference', 'c.public_id as contribution'])->orderBy('c.id')->limit($limit)->get());

        $add('duplicate_provider_reference', DB::table('sponsorship_payments')->whereNotNull('provider_reference')
            ->groupBy('provider', 'provider_reference')->havingRaw('COUNT(*) > 1')
            ->select(['provider_reference as reference'])->limit($limit)->get());

        $add('duplicate_reference', DB::table('sponsorship_payments')->groupBy('reference')->havingRaw('COUNT(*) > 1')->select(['reference'])->limit($limit)->get());

        return $findings;
    }

    /**
     * @param  list<array{code: string}>  $findings
     * @return array<string, int>
     */
    public function summarize(array $findings): array
    {
        $counts = array_fill_keys(array_keys(self::CODES), 0);

        foreach ($findings as $finding) {
            $counts[$finding['code']] = ($counts[$finding['code']] ?? 0) + 1;
        }

        return $counts;
    }
}

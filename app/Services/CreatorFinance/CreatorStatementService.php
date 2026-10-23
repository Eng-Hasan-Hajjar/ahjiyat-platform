<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorPayout;
use App\Models\CreatorProfile;
use App\Models\CreatorRevenueAllocation;
use App\Models\CreatorRevenuePeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * E39 - قراءات المبدع لأرباحه (لوحة + كشف فترة). كل استعلام محصور بحساب المبدع نفسه (creator_profile_id) فلا يرى أحدٌ بيانات غيره.
 * لا يعرض مجمّع المنصة ولا بيانات مبدعين آخرين ولا ملاحظات داخلية ولا هويات مشرفين؛ يعرض رسالة الرفض العامة فقط.
 */
class CreatorStatementService
{
    public function accountFor(CreatorProfile $profile): ?CreatorEarningAccount
    {
        return CreatorEarningAccount::query()->where('creator_profile_id', $profile->id)->first();
    }

    /** @return array<string, mixed> */
    public function dashboard(CreatorProfile $profile, int $page = 1): array
    {
        $account = $this->accountFor($profile);
        $currency = $account?->currency ?? CreatorMoney::currency();
        $perPage = max(5, (int) config('creator_monetization.list.per_page', 20));

        if ($account === null) {
            return ['account' => null, 'currency' => $currency];
        }

        /** @var LengthAwarePaginator $statements */
        $statements = CreatorRevenueAllocation::query()->where('creator_profile_id', $profile->id)
            ->with('period:id,public_id,label,starts_at,ends_at,finalized_at')
            ->orderByDesc('creator_revenue_period_id')->paginate($perPage, ['*'], 'page', $page);

        $payouts = CreatorPayout::query()->where('creator_earning_account_id', $account->id)->orderByDesc('id')->limit(15)->get();
        $open = $payouts->first(fn (CreatorPayout $p) => $p->isOpen());
        $min = (int) config('creator_monetization.payout.min_amount_minor');

        $blocked = match (true) {
            ! (bool) config('creator_monetization.enabled', true) => 'ميزة الأرباح معطَّلة حاليًا.',
            ! $account->isEnabled() => 'التسييل موقوف مؤقتًا لحسابك.',
            $account->isOnHold() => 'طلبات الدفع متوقفة مؤقتًا على حسابك؛ تواصل مع الدعم.',
            $open !== null => 'لديك طلب دفعة مفتوح بالفعل.',
            (int) $account->available_minor < $min => 'رصيدك المتاح أقل من الحدّ الأدنى للدفعة.',
            default => null,
        };

        return [
            'account' => $account,
            'currency' => $currency,
            'statements' => $statements,
            'payouts' => $payouts,
            'open_payout' => $open,
            'min_payout_minor' => $min,
            'methods' => (array) config('creator_monetization.payout.methods'),
            'blocked_reason' => $blocked,
        ];
    }

    /** @return array<string, mixed>|null */
    public function statement(CreatorProfile $profile, string $periodPublicId): ?array
    {
        $period = CreatorRevenuePeriod::query()->where('public_id', $periodPublicId)->where('status', CreatorRevenuePeriod::STATUS_FINALIZED)->first();

        if ($period === null) {
            return null;
        }

        $allocation = CreatorRevenueAllocation::query()->where('creator_revenue_period_id', $period->id)->where('creator_profile_id', $profile->id)->first();

        if ($allocation === null) {
            return null;
        }

        $total = max(1, (int) $allocation->period_total_units);
        $bps = intdiv((int) $allocation->qualified_units * 10000, $total);

        return [
            'period' => $period,
            'allocation' => $allocation,
            'share_bps' => $bps,
            'policy' => (array) ($period->policy_snapshot ?? []),
        ];
    }
}

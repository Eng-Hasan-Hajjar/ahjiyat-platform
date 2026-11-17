<?php

namespace App\Services\Economy\Integrity;

use App\Models\CurrencyTransaction;
use App\Models\RedemptionRequest;
use Illuminate\Support\Facades\DB;

/**
 * E46: ما يمكن **إثباته** عن التزامات الاستبدال من مصدرها الموثوق (جدول redemption_requests مقابل دفتر currency_transactions)، قراءة فقط.
 *
 * فحص مصدر «ميزانية المكافآت»: لا يوجد في الكود ولا في config/gems.php ولا في أي جدول تعريف لميزانية/صندوق مكافآت جواهر
 * (الموجود ميزانيات لا علاقة لها: إشعارات، مهام LiveOps، نجوم تصويت، أرباح مبدعين). لذلك `budget` دائمًا null: لا رقم تخميني.
 * `per_redemption_unit` يحوّل الجواهر إلى «وحدة استبدال داخلية» لكن المكافأة المطلوبة (reward_description) نص حرّ بلا قيمة نقدية مخزَّنة،
 * فلا يُشتقّ مبلغ نقدي للالتزامات أيضًا؛ تُعرض بالجواهر فقط.
 *
 * عدم العدّ المزدوج: الجواهر المطلوب استبدالها **خُصمت من المحفظة لحظة الطلب** (debitAvailable ضمن requestRedemption)، فهي التزام قائم خارج أرصدة
 * المحافظ؛ لا تُضاف إلى أرصدة المحافظ ولا تُجمَع مع صفوف `redeem` في الدفتر (كل طلب له صف خصم واحد فقط، وهو الدليل على أنه «مدعوم» لا التزام إضافي).
 *
 * بصمات انتقالات الحالة في البيانات (تكشف أثر reject/reject و reject/fulfill إن حدثت فعلًا):
 *  - duplicate_refunds: طلبات لها أكثر من قيد استرداد.
 *  - rejected_without_refund: طلبات مرفوضة بلا أي قيد استرداد.
 *  - refunded_but_not_rejected: طلبات لها قيد استرداد وحالتها ليست «مرفوض».
 */
class RedemptionCommitments
{
    public const OPEN_STATUSES = [RedemptionRequest::STATUS_PENDING, RedemptionRequest::STATUS_APPROVED];

    /**
     * @return array{
     *     budget: null,
     *     currencies: array<int, array{currency_id: int, open_requests: int, open_gems: int, backed_requests: int, unbacked_requests: int}>,
     *     footprints: array{duplicate_refunds: int, rejected_without_refund: int, refunded_but_not_rejected: int}
     * }
     */
    public function summary(): array
    {
        $morph = (new RedemptionRequest)->getMorphClass();
        $open = self::OPEN_STATUSES;

        $rows = DB::table('redemption_requests')
            ->whereIn('status', $open)
            ->groupBy('currency_id')
            ->orderBy('currency_id')
            ->selectRaw('currency_id, COUNT(*) AS open_requests, COALESCE(SUM(gems_amount), 0) AS open_gems')
            ->get();

        // مدعوم = يوجد صف خصم redeem واحد بالضبط يخصّ الطلب نفسه (المستخدم والعملة والمبلغ) فيُحسب مرة واحدة لا مرتين.
        $backed = DB::table('redemption_requests as r')
            ->whereIn('r.status', $open)
            ->whereRaw('(SELECT COUNT(*) FROM currency_transactions t WHERE t.reference_type = ? AND t.reference_id = r.id AND t.type = ? AND t.user_id = r.user_id AND t.currency_id = r.currency_id AND t.amount = -r.gems_amount) = 1', [$morph, CurrencyTransaction::TYPE_REDEEM])
            ->groupBy('r.currency_id')
            ->selectRaw('r.currency_id AS currency_id, COUNT(*) AS backed')
            ->pluck('backed', 'currency_id');

        $currencies = [];

        foreach ($rows as $row) {
            $id = (int) $row->currency_id;
            $count = (int) $row->open_requests;
            $currencies[$id] = [
                'currency_id' => $id,
                'open_requests' => $count,
                'open_gems' => (int) $row->open_gems,
                'backed_requests' => (int) ($backed[$id] ?? 0),
                'unbacked_requests' => $count - (int) ($backed[$id] ?? 0),
            ];
        }

        return ['budget' => null, 'currencies' => $currencies, 'footprints' => $this->footprints($morph)];
    }

    /** @return array{duplicate_refunds: int, rejected_without_refund: int, refunded_but_not_rejected: int} */
    protected function footprints(string $morph): array
    {
        $refundRows = fn () => DB::table('currency_transactions')
            ->where('reference_type', $morph)
            ->where('amount', '>', 0)
            ->where('reason', 'like', 'redemption_rejected:%');

        $duplicate = DB::query()->fromSub(
            $refundRows()->groupBy('reference_id')->havingRaw('COUNT(*) > 1')->select('reference_id'),
            'dup'
        )->count();

        $rejectedWithoutRefund = DB::table('redemption_requests as r')
            ->where('r.status', RedemptionRequest::STATUS_REJECTED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('currency_transactions as t')
                ->whereColumn('t.reference_id', 'r.id')->where('t.reference_type', $morph)
                ->where('t.amount', '>', 0)->where('t.reason', 'like', 'redemption_rejected:%'))
            ->count();

        $refundedNotRejected = DB::table('redemption_requests as r')
            ->where('r.status', '!=', RedemptionRequest::STATUS_REJECTED)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('currency_transactions as t')
                ->whereColumn('t.reference_id', 'r.id')->where('t.reference_type', $morph)
                ->where('t.amount', '>', 0)->where('t.reason', 'like', 'redemption_rejected:%'))
            ->count();

        return [
            'duplicate_refunds' => (int) $duplicate,
            'rejected_without_refund' => (int) $rejectedWithoutRefund,
            'refunded_but_not_rejected' => (int) $refundedNotRejected,
        ];
    }
}

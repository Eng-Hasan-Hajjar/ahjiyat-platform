<?php

namespace App\Support;

use App\Models\CurrencyTransaction;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * E46 (عرض فقط): يشرح للاعب حركة واحدة من دفتر معاملاته بلغة بسيطة: ماذا حدث، وهل مسّ المتاح أم المعلّق.
 *
 * لا يغيّر أي رصيد ولا أي صف. التصنيف هنا هو نفسه المطبَّق في فلتر «نوع الحركة» (scopeKind) ويُثبِّت اختبارٌ أنهما لا يتباعدان.
 *
 * الأنواع (بحسب type أولًا، ثم السبب للاسترداد):
 *   earn        earn_pending / progression_reward / purchase       ← المعلّق (earn_pending) أو المتاح (الباقي)
 *   release     release_available                                   ← ينتقل من المعلّق إلى المتاح؛ لا يزيد ما تملكه، فلا يُعرض كأنه «+ كسب»
 *   debit       redeem / spend / expire                             ← يُخصم من المتاح
 *   refund      type=refund أو سبب redemption_rejected:/store_refund: ← يعود إلى المتاح
 *   other       admin_adjustment (تعديل إداري) وأي نوع غير معروف    ← يتبع إشارة المبلغ على المتاح
 *
 * لا تعتمد الدلالة على اللون وحده: لكل نوع أيقونة + كلمة + جملة أثر، والمبلغ بإشارته (+/−) إلا الإتاحة فبلا إشارة.
 * لا يُعرض أي تاريخ إتاحة ولا عدد أيام: لا يُحسب هنا شيء من حالة التعليق.
 */
final class WalletMovement
{
    public const EARN = 'earn';

    public const RELEASE = 'release';

    public const DEBIT = 'debit';

    public const REFUND = 'refund';

    public const OTHER = 'other';

    /** @var list<string> */
    private const EARN_TYPES = [
        CurrencyTransaction::TYPE_EARN_PENDING,
        CurrencyTransaction::TYPE_PROGRESSION_REWARD,
        CurrencyTransaction::TYPE_PURCHASE,
    ];

    /** @var list<string> */
    private const DEBIT_TYPES = [
        CurrencyTransaction::TYPE_REDEEM,
        CurrencyTransaction::TYPE_SPEND,
        CurrencyTransaction::TYPE_EXPIRE,
    ];

    /** أسباب الاسترداد (بادئات قبل «:») حين يكون النوع المخزَّن admin_adjustment (هكذا يكتب RedemptionService). */
    private const REFUND_REASON_HEADS = ['redemption_rejected', 'store_refund'];

    /** @return array<string, string> مفتاح الفلتر ← تسميته للاعب */
    public static function kinds(): array
    {
        return [
            self::EARN => 'كسب وإضافة',
            self::RELEASE => 'إتاحة من المعلّق',
            self::DEBIT => 'خصم',
            self::REFUND => 'استرداد',
            self::OTHER => 'تعديلات وأخرى',
        ];
    }

    public static function isKind(?string $kind): bool
    {
        return is_string($kind) && array_key_exists($kind, self::kinds());
    }

    public static function kindOf(?string $type, ?string $reason): string
    {
        $type = (string) $type;

        return match (true) {
            in_array($type, self::EARN_TYPES, true) => self::EARN,
            $type === CurrencyTransaction::TYPE_RELEASE_AVAILABLE => self::RELEASE,
            in_array($type, self::DEBIT_TYPES, true) => self::DEBIT,
            $type === CurrencyTransaction::TYPE_REFUND || self::hasRefundReason($reason) => self::REFUND,
            default => self::OTHER,
        };
    }

    /**
     * نفس التصنيف أعلاه كشرط SQL (للفلتر). بلا قراءة غير الجدول نفسه ولا كتابة.
     *
     * @param  BuilderContract|Relation  $query
     */
    public static function scopeKind($query, string $kind)
    {
        $reasonLikes = array_map(fn (string $head) => $head.':%', self::REFUND_REASON_HEADS);
        $notRefundReason = function ($q) use ($reasonLikes) {
            $q->whereNull('reason')->orWhere(function ($w) use ($reasonLikes) {
                foreach ($reasonLikes as $like) {
                    $w->where('reason', 'not like', $like);
                }
            });
        };
        $classified = array_merge(self::EARN_TYPES, [CurrencyTransaction::TYPE_RELEASE_AVAILABLE], self::DEBIT_TYPES);

        return match ($kind) {
            self::EARN => $query->whereIn('type', self::EARN_TYPES),
            self::RELEASE => $query->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE),
            self::DEBIT => $query->whereIn('type', self::DEBIT_TYPES),
            self::REFUND => $query->whereNotIn('type', $classified)->where(function ($q) use ($reasonLikes) {
                $q->where('type', CurrencyTransaction::TYPE_REFUND);
                foreach ($reasonLikes as $like) {
                    $q->orWhere('reason', 'like', $like);
                }
            }),
            self::OTHER => $query->whereNotIn('type', $classified)->where('type', '!=', CurrencyTransaction::TYPE_REFUND)->where($notRefundReason),
            default => $query,
        };
    }

    /**
     * @return array{
     *     kind: string, kind_label: string, icon: string, tone: string,
     *     label: string, effect: string, sign: string, amount: int, amount_text: string
     * }
     */
    public static function describe(CurrencyTransaction $transaction): array
    {
        $type = (string) $transaction->type;
        $amount = (int) $transaction->amount;
        $kind = self::kindOf($type, $transaction->reason);
        $positive = $amount >= 0;

        [$chip, $icon, $tone] = match ($kind) {
            self::EARN => ['كسب', 'plus', 'text-emerald'],
            self::RELEASE => ['إتاحة', 'check-circle', 'text-amethyst'],
            self::DEBIT => ['خصم', 'arrow-down', 'text-rose'],
            self::REFUND => ['استرداد', 'undo', 'text-gold'],
            default => ['تعديل', 'pencil', 'text-slate-300'],
        };

        $effect = match (true) {
            $type === CurrencyTransaction::TYPE_EARN_PENDING => 'أُضيف إلى رصيدك المعلّق، ويصبح متاحًا بعد انتهاء فترة التعليق',
            $kind === self::RELEASE => 'انتقل من رصيدك المعلّق إلى المتاح (مجموع ما تملكه لم يتغيّر)',
            $type === CurrencyTransaction::TYPE_EXPIRE => 'انتهت صلاحيته وخُصم من رصيدك المتاح',
            $kind === self::REFUND => 'أُعيد إلى رصيدك المتاح',
            $kind === self::DEBIT => 'خُصم من رصيدك المتاح',
            $positive => 'أُضيف إلى رصيدك المتاح',
            default => 'خُصم من رصيدك المتاح',
        };

        $sign = $kind === self::RELEASE ? '' : ($positive ? '+' : '−');
        $abs = abs($amount);

        return [
            'kind' => $kind,
            'kind_label' => $chip,
            'icon' => $icon,
            'tone' => $tone,
            'label' => self::labelFor($kind, $transaction->reason, $positive),
            'effect' => $effect,
            'sign' => $sign,
            'amount' => $abs,
            'amount_text' => $sign.number_format($abs),
        ];
    }

    /** تسمية الحركة: السبب المعروف بالعربية، أو النص العربي الحر، وإلا تسمية عامة بحسب النوع (لا يظهر رمز تقني للاعب أبدًا). */
    private static function labelFor(string $kind, ?string $reason, bool $positive): string
    {
        $described = WalletReasonLabel::describe($reason);

        if ($described['known']) {
            return $described['label'];
        }

        if ($described['raw'] !== '' && ! WalletReasonLabel::looksInternal($described['raw'])) {
            return $described['label'];
        }

        return match ($kind) {
            self::EARN => 'كسب',
            self::RELEASE => 'إتاحة رصيد معلّق',
            self::DEBIT => 'خصم من الرصيد',
            self::REFUND => 'استرداد',
            default => $positive ? 'إضافة إلى الرصيد' : 'خصم من الرصيد',
        };
    }

    private static function hasRefundReason(?string $reason): bool
    {
        $head = strstr((string) $reason, ':', true);

        return $head !== false && in_array($head, self::REFUND_REASON_HEADS, true);
    }
}

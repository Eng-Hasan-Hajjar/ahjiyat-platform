<?php

namespace App\Services\Economy\Integrity;

use App\Models\CurrencyTransaction;

/**
 * E46: القاعدة الموثَّقة لأثر كل نوع معاملة في دفتر `currency_transactions` على الرصيدين (المتاح والمعلَّق).
 *
 * المصدر الوحيد لهذه القاعدة هو مسارات الكتابة الفعلية في CurrencyWalletService (لا أسماء الأصناف):
 *
 *  | النوع               | المبلغ في الدفتر | المتاح        | المعلَّق       | من يكتبه                                         |
 *  |---------------------|------------------|---------------|----------------|--------------------------------------------------|
 *  | earn_pending        | موجب دائمًا      | لا أثر        | +المبلغ        | creditPending                                    |
 *  | release_available   | موجب دائمًا      | +المبلغ       | −المبلغ        | releasePending (صف واحد ينقل بين الرصيدين)       |
 *  | أي نوع آخر معروف    | بإشارته المخزَّنة | +المبلغ بإشارته | لا أثر        | creditAvailable / debitAvailable / refund / adjust |
 *
 * ملاحظات مثبتة من الكود:
 *  - التحويل بين الرصيدين صف واحد موجب (release_available) أثره المزدوج مشتقّ من نوعه؛ ليس صفّين.
 *  - debitAvailable يخزّن المبلغ سالبًا (redeem أو spend عند الشراء من المتجر؛ ويستعمل النوع redeem أيضًا لشراء التلميح).
 *  - refund() و adjust() و creditAvailable() تؤثّر في المتاح فقط. refund() يكتب النوع admin_adjustment افتراضيًا
 *    (رفض طلب الاستبدال يرجّع الجواهر بهذا النوع وبسبب redemption_rejected:ID) أو refund عند الشراء من المتجر.
 *  - النوع expire مُعرَّف لكن لا يكتبه أي مسار حاليًا (لا تصفير تلقائي عند expires_at)؛ يُعامل كنوع متاح غير شاذ.
 *  - الأنواع غير المعروفة تُعامل أثرًا على المتاح (كالأنواع الأخرى) لكنها تُبلَّغ كشذوذ فلا يُحسم فرق بسببها.
 *
 * هذه الفئة لا تلمس قاعدة البيانات ولا تكتب شيئًا.
 */
final class LedgerEffects
{
    /** أنواع تؤثّر في المعلَّق وليس في المتاح عند الكسب. */
    public const EARN = CurrencyTransaction::TYPE_EARN_PENDING;

    /** النوع الوحيد الذي ينقل بين الرصيدين. */
    public const RELEASE = CurrencyTransaction::TYPE_RELEASE_AVAILABLE;

    /**
     * أنواع تُكتب بمبلغ موجب حصرًا (assertPositiveAmount في الخدمة).
     *
     * @return list<string>
     */
    public static function alwaysPositiveTypes(): array
    {
        return [self::EARN, self::RELEASE];
    }

    /**
     * أنواع تُكتب بمبلغ سالب حصرًا عبر debitAvailable (النوع الافتراضي redeem، و spend للمتجر).
     *
     * @return list<string>
     */
    public static function alwaysNegativeTypes(): array
    {
        return [CurrencyTransaction::TYPE_REDEEM, CurrencyTransaction::TYPE_SPEND];
    }

    /**
     * كل الأنواع المعرَّفة في CurrencyTransaction. ما عداها «غير معروف» ويُبلَّغ كشذوذ.
     *
     * @return list<string>
     */
    public static function knownTypes(): array
    {
        return [
            CurrencyTransaction::TYPE_EARN_PENDING,
            CurrencyTransaction::TYPE_RELEASE_AVAILABLE,
            CurrencyTransaction::TYPE_REDEEM,
            CurrencyTransaction::TYPE_EXPIRE,
            CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT,
            CurrencyTransaction::TYPE_REFUND,
            CurrencyTransaction::TYPE_PURCHASE,
            CurrencyTransaction::TYPE_SPEND,
            CurrencyTransaction::TYPE_PROGRESSION_REWARD,
        ];
    }

    /**
     * أثر صف واحد على الرصيدين، بمعاملات تُضرب في المبلغ المخزَّن: ['available' => م1, 'pending' => م2].
     *
     * @return array{available: int, pending: int}
     */
    public static function effect(string $type): array
    {
        return match ($type) {
            self::EARN => ['available' => 0, 'pending' => 1],
            self::RELEASE => ['available' => 1, 'pending' => -1],
            default => ['available' => 1, 'pending' => 0],
        };
    }

    /**
     * أثر قائمة صفوف (type, amount) على الرصيدين: مرجع حسابي صغير تُقارَن به نتائج التجميع في SQL داخل الاختبارات.
     *
     * @param  iterable<array{type: string, amount: int}>  $rows
     * @return array{available: int, pending: int}
     */
    public static function fold(iterable $rows): array
    {
        $available = 0;
        $pending = 0;

        foreach ($rows as $row) {
            $effect = self::effect((string) $row['type']);
            $available += $effect['available'] * (int) $row['amount'];
            $pending += $effect['pending'] * (int) $row['amount'];
        }

        return ['available' => $available, 'pending' => $pending];
    }

    /** هل الصف يخالف قاعدة الإشارة الموثَّقة لنوعه (أو مبلغه صفر)؟ مرجع لمنطق التجميع في SQL. */
    public static function violatesSignRule(string $type, int $amount): bool
    {
        if ($amount === 0) {
            return true;
        }

        if (in_array($type, self::alwaysPositiveTypes(), true)) {
            return $amount < 0;
        }

        if (in_array($type, self::alwaysNegativeTypes(), true)) {
            return $amount > 0;
        }

        return false;
    }
}

<?php

namespace App\Services\Economy\Integrity;

/**
 * E46: نتيجة تدقيق (مستخدم + عملة) واحدة. المتاح والمعلَّق يُقارَن كلٌّ على حدة.
 *
 * الأحكام الثلاثة:
 *  - مطابق (matched): المخزَّن في المحفظة = المشتق من الدفتر للمتاح وللمعلَّق معًا.
 *  - فرق مثبت (proven_difference): فرق حسابي قائم في أحدهما أو كليهما، مع دفتر غير فارغ، وعدّاد lifetime_earned متسق مع الدفتر
 *    (لا دليل على جواهر مكتسبة خارج الدفتر)، ولا شذوذ في الدفتر، ولا حركة جارية. الحكم يثبت **وجود الفرق** لا **سببه**.
 *  - لا يمكن الجزم (undetermined): فرق قائم لكن توجد قرينة تمنع نسبته إلى خطأ (رصيد افتتاحي/مرحَّل محتمل، شذوذ في الدفتر، حركة جارية...). الأسباب في reasons.
 */
final class WalletAuditCase
{
    public const VERDICT_MATCHED = 'matched';

    public const VERDICT_PROVEN = 'proven_difference';

    public const VERDICT_UNDETERMINED = 'undetermined';

    /** محفظة فيها رصيد بلا أي صف دفتر: قد يكون رصيدًا افتتاحيًا/مرحَّلًا. */
    public const REASON_NO_LEDGER = 'no_ledger';

    /** lifetime_earned في المحفظة أكبر مما يفسّره الدفتر: جواهر مكتسبة خارج الدفتر (رصيد مرحَّل محتمل). */
    public const REASON_CARRIED_EARNINGS = 'carried_earnings';

    /** lifetime_earned أقل من مجموع الكسب المسجَّل في الدفتر: عدّاد المحفظة نفسه غير متسق فلا يُعتمد شاهدًا على اكتمال التاريخ. */
    public const REASON_LIFETIME_BELOW_LEDGER = 'lifetime_below_ledger';

    /** نوع معاملة غير معروف أو مبلغ يخالف قاعدة الإشارة الموثَّقة لنوعه: أثره على الرصيد غير مضمون. */
    public const REASON_LEDGER_ANOMALY = 'ledger_anomaly';

    /** المحفظة/الدفتر تحرّكا خلال نافذة السماح أو تغيّرت القراءة بين مرورَي الفحص: قد يكون الفرق لقطة وسطية. */
    public const REASON_IN_FLIGHT = 'in_flight';

    /** صفوف دفتر بلا صف محفظة. */
    public const REASON_WALLET_MISSING = 'wallet_missing';

    /** ملاحظة (ليست سببًا): فرق ظهر في المرور الأول واختفى عند إعادة القراءة. */
    public const NOTE_SETTLED_ON_RECHECK = 'settled_on_recheck';

    /** ملاحظة: الأرقام متطابقة لكن الدفتر يحوي شذوذًا يستحق نظرة. */
    public const NOTE_LEDGER_ANOMALY = 'ledger_anomaly';

    /**
     * @param  list<string>  $reasons  أسباب عدم الجزم (فارغة لغير undetermined)
     * @param  list<string>  $notes  ملاحظات لا تغيّر الحكم
     */
    public function __construct(
        public readonly ?int $walletId,
        public readonly int $userId,
        public readonly int $currencyId,
        public readonly string $verdict,
        public readonly int $storedAvailable,
        public readonly int $derivedAvailable,
        public readonly int $storedPending,
        public readonly int $derivedPending,
        public readonly int $ledgerRows,
        public readonly array $reasons = [],
        public readonly array $notes = [],
    ) {}

    public function diffAvailable(): int
    {
        return $this->storedAvailable - $this->derivedAvailable;
    }

    public function diffPending(): int
    {
        return $this->storedPending - $this->derivedPending;
    }

    public function isMatched(): bool
    {
        return $this->verdict === self::VERDICT_MATCHED;
    }

    public static function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_MATCHED => 'مطابق',
            self::VERDICT_PROVEN => 'فرق مثبت',
            self::VERDICT_UNDETERMINED => 'لا يمكن الجزم',
            default => $verdict,
        };
    }

    public static function reasonLabel(string $code): string
    {
        return match ($code) {
            self::REASON_NO_LEDGER => 'رصيد بلا أي حركة في الدفتر (قد يكون رصيدًا افتتاحيًا أو مرحَّلًا)',
            self::REASON_CARRIED_EARNINGS => 'إجمالي ما كُسب في المحفظة أكبر مما يفسّره الدفتر (رصيد مرحَّل محتمل)',
            self::REASON_LIFETIME_BELOW_LEDGER => 'إجمالي ما كُسب في المحفظة أقل من الدفتر (عدّاد المحفظة نفسه غير متسق)',
            self::REASON_LEDGER_ANOMALY => 'نوع معاملة غير معروف أو مبلغ بإشارة غير متوقعة في الدفتر',
            self::REASON_IN_FLIGHT => 'حركة جارية على المحفظة أثناء الفحص (أعد الفحص لاحقًا)',
            self::REASON_WALLET_MISSING => 'حركات في الدفتر بلا محفظة مقابلة',
            self::NOTE_SETTLED_ON_RECHECK => 'اختفى الفرق عند إعادة القراءة',
            default => $code,
        };
    }

    /** @return array<string, mixed> تمثيل آمن للتخزين/JSON: أرقام ورموز فقط. */
    public function toArray(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'user_id' => $this->userId,
            'currency_id' => $this->currencyId,
            'verdict' => $this->verdict,
            'stored_available' => $this->storedAvailable,
            'derived_available' => $this->derivedAvailable,
            'diff_available' => $this->diffAvailable(),
            'stored_pending' => $this->storedPending,
            'derived_pending' => $this->derivedPending,
            'diff_pending' => $this->diffPending(),
            'ledger_rows' => $this->ledgerRows,
            'reasons' => $this->reasons,
            'notes' => $this->notes,
        ];
    }
}

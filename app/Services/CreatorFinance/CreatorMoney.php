<?php

namespace App\Services\CreatorFinance;

use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E39 - المبالغ كأعداد صحيحة بالوحدة الصغرى وبعملة محاسبة واحدة (نفس سجل عملات الرعاية). لا floats في أي حساب.
 * المحلّل صارم عمدًا: لا فواصل آلاف (لأن «1,000» غامضة بين اللغات)، لا إشارات، لا أسّ علمي، لا مسافات داخلية؛ أرقام عربية-هندية وفاصل «٫» مقبولان.
 */
final class CreatorMoney
{
    public static function currency(): string
    {
        $configured = config('creator_monetization.accounting_currency');

        if (is_string($configured) && $configured !== '' && SponsorshipMoney::isAllowed(strtoupper($configured))) {
            return strtoupper($configured);
        }

        return SponsorshipMoney::accountingCurrency();
    }

    /**
     * يحوّل نصًا إلى وحدة صغرى موجبة صحيحة.
     *
     * @throws CreatorFinanceException
     */
    public static function parse(string|int $input, ?string $currency = null, ?int $max = null): int
    {
        $currency ??= self::currency();

        if (! SponsorshipMoney::isAllowed($currency)) {
            throw new CreatorFinanceException('عملة غير مدعومة.');
        }

        $exp = SponsorshipMoney::exponent($currency);
        $text = strtr(trim((string) $input), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);

        if (preg_match('/^(\d{1,12})(?:\.(\d{1,4}))?$/', $text, $m) !== 1) {
            throw new CreatorFinanceException('المبلغ غير صالح؛ اكتب رقمًا موجبًا بلا فواصل آلاف'.($exp > 0 ? " وبحد أقصى {$exp} خانة عشرية" : '').'.');
        }

        $fraction = $m[2] ?? '';

        if (strlen($fraction) > $exp) {
            throw new CreatorFinanceException($exp === 0 ? 'المبلغ يجب أن يكون عددًا صحيحًا.' : "المبلغ يقبل حتى {$exp} خانة عشرية فقط.");
        }

        $minor = ((int) $m[1]) * (10 ** $exp) + ($exp > 0 && $fraction !== '' ? (int) str_pad($fraction, $exp, '0') : 0);

        if ($minor <= 0) {
            throw new CreatorFinanceException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        if ($max !== null && $minor > $max) {
            throw new CreatorFinanceException('المبلغ يتجاوز الحدّ الأقصى المسموح.');
        }

        return $minor;
    }

    public static function format(int $minor, ?string $currency = null): string
    {
        return SponsorshipMoney::format($minor, $currency ?? self::currency());
    }

    /** نص بالوحدة الرئيسية بلا رمز عملة ولا فواصل آلاف، يعود عبر parse() (لحقول التعديل). */
    public static function toMajorText(int $minor, ?string $currency = null): string
    {
        $currency ??= self::currency();
        $exp = SponsorshipMoney::exponent($currency);

        if ($exp === 0) {
            return (string) $minor;
        }

        return intdiv($minor, 10 ** $exp).'.'.str_pad((string) ($minor % (10 ** $exp)), $exp, '0', STR_PAD_LEFT);
    }

    /**
     * ⌊a × b ÷ c⌋ و(a × b) mod c بأعداد صحيحة دون تجاوز 64 بت (a = المجمّع، b = وحدات المبدع ≤ c = مجموع الوحدات).
     * a×b = (a÷c)×c×b + (a mod c)×b ⇒ الجزء الأول ÷ c = (a÷c)×b بلا فقد، والثاني < c² (محمي بسقف مجموع الوحدات).
     *
     * @return array{0: int, 1: int} [الحصة الصحيحة، بسط الباقي]
     */
    public static function mulDiv(int $a, int $b, int $c): array
    {
        if ($c <= 0 || $a < 0 || $b < 0 || $b > $c) {
            throw new CreatorFinanceException('معاملات توزيع غير صالحة.');
        }

        $whole = intdiv($a, $c);
        $rest = ($a % $c) * $b;

        return [$whole * $b + intdiv($rest, $c), $rest % $c];
    }
}

<?php

namespace App\Services\Sponsorship;

/**
 * E30 - المبالغ كأعداد صحيحة بالوحدة الصغرى (cents/kuruş). لا floats في أي حساب: التحليل نصّي (أرقام + فاصلة عشرية اختيارية) والتنسيق بقسمة صحيحة.
 * العملات من قائمة بيضاء بالإعداد (config/sponsorship.php). لا سعر صرف حيّ: المعادل المحاسبي يدخله المدير عند التأكيد.
 */
final class SponsorshipMoney
{
    public static function accountingCurrency(): string
    {
        $code = strtoupper((string) config('sponsorship.accounting_currency', 'USD'));

        return self::isAllowed($code) ? $code : 'USD';
    }

    /** @return list<string> */
    public static function currencies(): array
    {
        return array_map('strval', array_keys((array) config('sponsorship.currencies', [])));
    }

    /** @return array<string, string> رمز العملة ← تسمية عربية للقوائم. */
    public static function options(): array
    {
        $out = [];

        foreach ((array) config('sponsorship.currencies', []) as $code => $meta) {
            $out[(string) $code] = $code.' — '.($meta['label'] ?? $code);
        }

        return $out;
    }

    public static function isAllowed(?string $currency): bool
    {
        return is_string($currency) && $currency !== '' && array_key_exists($currency, (array) config('sponsorship.currencies', []));
    }

    public static function exponent(string $currency): int
    {
        return max(0, min(4, (int) config("sponsorship.currencies.{$currency}.exponent", 2)));
    }

    public static function symbol(string $currency): string
    {
        return (string) config("sponsorship.currencies.{$currency}.symbol", $currency);
    }

    /**
     * يحوّل مُدخَلًا نصيًا (مثل "1250" أو "12.50" أو بأرقام عربية) إلى وحدة صغرى صحيحة موجبة. يرمي SponsorshipException لأي مُدخَل غير سليم.
     *
     * @throws SponsorshipException
     */
    public static function parse(string|int $amount, string $currency): int
    {
        if (! self::isAllowed($currency)) {
            throw new SponsorshipException('عملة غير مدعومة.');
        }

        $exp = self::exponent($currency);
        $text = strtr(trim((string) $amount), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '', ',' => '', ' ' => '']);

        if (preg_match('/^(\d{1,13})(?:\.(\d{1,'.max(1, $exp).'}))?$/', $text, $m) !== 1 || ($exp === 0 && isset($m[2]))) {
            throw new SponsorshipException('المبلغ غير صالح؛ اكتب رقمًا موجبًا'.($exp > 0 ? " بحد أقصى {$exp} خانة عشرية" : ' صحيحًا').'.');
        }

        $minor = ((int) $m[1]) * (10 ** $exp) + ($exp > 0 && isset($m[2]) ? (int) str_pad($m[2], $exp, '0') : 0);

        return self::assertMinor($minor);
    }

    /** @throws SponsorshipException */
    public static function assertMinor(int $minor): int
    {
        if ($minor <= 0) {
            throw new SponsorshipException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        if ($minor > (int) config('sponsorship.max_amount_minor', 1_000_000_000)) {
            throw new SponsorshipException('المبلغ يتجاوز الحدّ الأقصى المسموح.');
        }

        return $minor;
    }

    /** تنسيق للعرض بقسمة صحيحة (لا floats): "$1,250.00". */
    public static function format(int $minor, ?string $currency = null): string
    {
        $currency ??= self::accountingCurrency();
        $exp = self::exponent($currency);
        $div = 10 ** $exp;
        $whole = intdiv(abs($minor), $div);
        $fraction = $exp > 0 ? '.'.str_pad((string) (abs($minor) % $div), $exp, '0', STR_PAD_LEFT) : '';

        return ($minor < 0 ? '-' : '').self::symbol($currency).number_format($whole).$fraction;
    }
}

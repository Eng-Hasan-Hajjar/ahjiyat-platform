<?php

namespace App\Services\Payments;

use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E32 - قواعد الدفع الخادمية الموحَّدة (لا تُؤخذ من المتصفح أبدًا): قائمة العملات المسموحة، الحدّان الأدنى/الأعلى، تحليل المبلغ.
 * المبلغ يُحلَّل بـSponsorshipMoney (نص ⇒ وحدة صغرى صحيحة بلا floats) ثم يُقيَّد بحدود الدفع. العملة من قائمة بيضاء (تقاطع الرعاية ∩ الدفع ∩ المزوّد).
 */
final class PaymentRules
{
    /** @return list<string> */
    public static function currencies(?PaymentProvider $provider = null): array
    {
        $codes = SponsorshipMoney::currencies();
        $configured = array_map('strval', (array) config('payments.allowed_currencies', []));

        if ($configured !== []) {
            $codes = array_values(array_intersect($codes, $configured));
        }

        $providerCodes = $provider?->currencies();

        if ($providerCodes !== null) {
            $codes = array_values(array_intersect($codes, $providerCodes));
        }

        return array_values($codes);
    }

    public static function isCurrencyAllowed(string $currency, ?PaymentProvider $provider = null): bool
    {
        return in_array($currency, self::currencies($provider), true);
    }

    public static function minMinor(): int
    {
        return max(1, (int) config('payments.checkout.min_amount_minor', 100));
    }

    public static function maxMinor(): int
    {
        $global = (int) config('sponsorship.max_amount_minor', 1_000_000_000);
        $configured = config('payments.checkout.max_amount_minor');

        return $configured === null ? $global : max(1, min($global, (int) $configured));
    }

    /**
     * يحلّل مبلغ الدفع ويتحقق من العملة والحدود. @throws PaymentException
     */
    public static function parseAmount(string|int $amount, string $currency, ?PaymentProvider $provider = null): int
    {
        $currency = strtoupper(trim($currency));

        if (! self::isCurrencyAllowed($currency, $provider)) {
            throw new PaymentException('عملة غير مسموحة للدعم.');
        }

        try {
            $minor = SponsorshipMoney::parse($amount, $currency);
        } catch (SponsorshipException $e) {
            throw new PaymentException($e->getMessage());
        }

        if ($minor < self::minMinor()) {
            throw new PaymentException('المبلغ أقل من الحدّ الأدنى للدعم ('.SponsorshipMoney::format(self::minMinor(), $currency).').');
        }

        if ($minor > self::maxMinor()) {
            throw new PaymentException('المبلغ يتجاوز الحدّ الأقصى للدعم ('.SponsorshipMoney::format(self::maxMinor(), $currency).').');
        }

        if (! (bool) config('payments.checkout.allow_custom_amount', true) && ! in_array($minor, self::suggestedMinor($currency), true)) {
            throw new PaymentException('اختر أحد المبالغ المقترحة.');
        }

        return $minor;
    }

    /** @return list<int> المبالغ المقترحة بالوحدة الصغرى لعملة معيّنة (للراحة فقط؛ الخادم يتحقق دائمًا). */
    public static function suggestedMinor(string $currency): array
    {
        $factor = 10 ** SponsorshipMoney::exponent($currency);
        $out = [];

        foreach ((array) config('payments.checkout.suggested_amounts', []) as $major) {
            $minor = (int) $major * $factor;

            if ($minor >= self::minMinor() && $minor <= self::maxMinor()) {
                $out[] = $minor;
            }
        }

        return $out;
    }

    /** @return list<array{minor: int, label: string, input: string}> للعرض: input هو المبلغ كما يُكتب في الحقل (بلا رمز). */
    public static function suggestions(string $currency): array
    {
        $factor = 10 ** SponsorshipMoney::exponent($currency);

        return array_map(
            static fn (int $minor): array => ['minor' => $minor, 'label' => SponsorshipMoney::format($minor, $currency), 'input' => (string) intdiv($minor, $factor)],
            self::suggestedMinor($currency),
        );
    }
}

<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorEarningAccount;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E43 - قواعد التسعير والقسمة (بلا قاعدة بيانات): مفتاح الإيقاف، حدّا السعر، العملة (الخيار A: عملة العرض = عملة حساب أرباح المبدع، بلا تحويل ولا سعر صرف أبدًا)، وقسمة الإيراد بنقاط الأساس.
 *
 * قاعدة القسمة (موثَّقة ومثبَّتة باختبار): creator_minor = ⌊gross × creator_bps ÷ 10000⌋ ، platform_minor = gross − creator_minor ⇒ مجموعهما = gross دائمًا.
 * الحساب بأعداد صحيحة فقط وبلا تجاوز: gross = q×10000 + r ⇒ ⌊gross×bps÷10000⌋ = q×bps + ⌊r×bps÷10000⌋ (لا ضرب gross×bps مباشرة).
 */
class CreatorPremiumPolicy
{
    public const RULE_CREATOR_FLOOR = 'creator_floor';

    public function enabled(): bool
    {
        return (bool) config('creator_premium.enabled', false);
    }

    /** @throws CreatorPremiumException */
    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new CreatorPremiumException('disabled', 'سوق السلاسل المميزة غير متاح حاليًا.');
        }
    }

    /**
     * @return array{creator_minor: int, platform_minor: int, creator_bps: int, platform_bps: int, rule: string}
     *
     * @throws CreatorPremiumException share_policy_invalid
     */
    public function split(int $grossMinor): array
    {
        $creatorBps = (int) config('creator_premium.sales.creator_share_bps');
        $platformBps = (int) config('creator_premium.sales.platform_share_bps');

        if ($grossMinor <= 0 || $creatorBps < 1 || $platformBps < 0 || $creatorBps + $platformBps !== 10000) {
            throw new CreatorPremiumException('share_policy_invalid', 'سياسة قسمة الإيراد غير صالحة؛ تواصل مع الدعم.');
        }

        $quotient = intdiv($grossMinor, 10000);
        $rest = $grossMinor % 10000;
        $creator = $quotient * $creatorBps + intdiv($rest * $creatorBps, 10000);

        return [
            'creator_minor' => $creator,
            'platform_minor' => $grossMinor - $creator,
            'creator_bps' => $creatorBps,
            'platform_bps' => $platformBps,
            'rule' => self::RULE_CREATOR_FLOOR,
        ];
    }

    /** null = السعر صالح. السعر عدد صحيح موجب ضمن الحدّين. */
    public function priceProblem(int $minor): ?string
    {
        $min = max(1, (int) config('creator_premium.sales.min_price_minor'));
        $max = max($min, (int) config('creator_premium.sales.max_price_minor'));

        if ($minor < $min || $minor > $max) {
            return 'السعر خارج النطاق المسموح.';
        }

        return null;
    }

    /** عملة قابلة للبيع: ضمن عملات التجارة E41 وسجل العملات المسموحة. لا تحويل. */
    public function currencyAllowed(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', $currency) === 1
            && in_array($currency, (array) config('commerce.allowed_currencies', []), true)
            && SponsorshipMoney::isAllowed($currency);
    }

    /** الخيار A: عملة العرض يجب أن تطابق عملة حساب أرباح المبدع حرفيًا. null = مطابقة. */
    public function currencyProblem(?CreatorEarningAccount $account, string $currency): ?string
    {
        if ($account === null) {
            return 'لا يوجد حساب أرباح مفعَّل لهذا المبدع.';
        }

        if ($account->currency !== $currency) {
            return 'عملة العرض يجب أن تطابق عملة حساب أرباحك ('.$account->currency.')؛ لا يوجد تحويل عملات.';
        }

        if (! $this->currencyAllowed($currency)) {
            return 'هذه العملة غير متاحة للبيع حاليًا.';
        }

        return null;
    }
}

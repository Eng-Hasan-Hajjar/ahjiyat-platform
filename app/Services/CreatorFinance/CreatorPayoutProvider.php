<?php

namespace App\Services\CreatorFinance;

/**
 * E39 - حدّ المزوّد **الصادر** (صرف أموال للمبدع). منفصل عمدًا عن App\Services\Payments\PaymentProvider (الوارد: التحقق من دعم مدفوع).
 * اليوم ينفَّذ يدويًا فقط (ManualCreatorPayoutProvider)؛ المزوّد الآلي المستقبلي يُنفِّذ الواجهة نفسها دون تغيير الخدمات. لا يمرّ عبره أي سر أو وجهة تحويل مخزَّنة.
 */
interface CreatorPayoutProvider
{
    /** رمز ثابت يُخزَّن في creator_payouts.provider. */
    public function code(): string;

    /** هل يُرسل الصرف آليًا (false = يسجّله موظف بمرجع خارجي). */
    public function dispatchesAutomatically(): bool;

    /**
     * يتحقق من مرجع الصرف الخارجي ويعيده منظَّفًا.
     *
     * @throws CreatorFinanceException
     */
    public function normalizeReference(string $reference): string;
}

<?php

namespace App\Services\CreatorFinance;

/** E39 - الصرف اليدوي: يتم خارج المنصة ويُسجَّل هنا بمرجع (رقم حوالة/إيصال) فقط. لا يخزَّن أي رقم حساب ولا وجهة. */
class ManualCreatorPayoutProvider implements CreatorPayoutProvider
{
    public function code(): string
    {
        return 'manual';
    }

    public function dispatchesAutomatically(): bool
    {
        return false;
    }

    public function normalizeReference(string $reference): string
    {
        $reference = trim(preg_replace('/\s+/u', ' ', $reference) ?? '');
        $min = (int) config('creator_monetization.payout.reference_min', 4);
        $max = (int) config('creator_monetization.payout.reference_max', 120);
        $length = mb_strlen($reference);

        if ($length < $min || $length > $max) {
            throw new CreatorFinanceException("مرجع الصرف يجب أن يكون بين {$min} و{$max} حرفًا.");
        }

        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/#:\-]*$/u', $reference) !== 1) {
            throw new CreatorFinanceException('مرجع الصرف يقبل الحروف والأرقام والرموز . _ / # : - فقط.');
        }

        return $reference;
    }
}

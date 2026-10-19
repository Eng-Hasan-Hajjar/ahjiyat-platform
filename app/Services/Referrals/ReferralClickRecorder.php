<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * E33 (E) - عدّاد نقرات **تقريبي** على الرمز. لا يخزَّن IP ولا User-Agent ولا بصمة: مفتاح إلغاء التكرار هو بصمة SHA-256 (مملَّحة بـAPP_KEY)
 * تعيش في الكاش ساعة واحدة فقط ثم تزول، فالنقرة الفريدة بالساعة تُحتسب مرة. لا مكافأة ولا تأهيل يعتمدان على هذا العدّاد أبدًا.
 */
class ReferralClickRecorder
{
    public function record(int $codeId, Request $request): bool
    {
        $fingerprint = hash_hmac('sha256', $request->ip().'|'.substr((string) $request->userAgent(), 0, 200), (string) config('app.key'));
        $key = 'referrals:click:'.$codeId.':'.substr($fingerprint, 0, 32);

        if (! Cache::add($key, 1, max(60, (int) config('referrals.analytics.click_dedupe_seconds')))) {
            return false;
        }

        ReferralCode::query()->whereKey($codeId)->increment('clicks_count');

        return true;
    }

    public function recordShare(int $codeId): void
    {
        ReferralCode::query()->whereKey($codeId)->increment('shares_count');
    }
}

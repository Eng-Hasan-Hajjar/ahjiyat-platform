<?php

namespace App\Services\Referrals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * E33 (A) - «نية الإحالة» لزائر ضيف: رمز + (اختياريًا) slug حملة + مصدر + وقت. تُحفظ بالجلسة وبكوكي أول-طرف **مشفّر** (EncryptCookies = موقَّع + مشفّر)
 * بمدة قابلة للضبط محصورة في [7, 30] يومًا. القواعد:
 *  - الخادم هو الحَكَم: النية تحمل رمزًا فقط (لا معرّف مستخدم) ويُحلّ عند التسجيل؛ لا حقل من العميل يحدد المحيل.
 *  - أول محيل صالح يربح: نية صالحة قائمة لا تُستبدل (الصالحة = الرمز ما زال نشطًا والمدة لم تنقضِ).
 *  - الزائر المصادَق لا يُسنَد إليه شيء.
 *  - لا تتبّع طرف ثالث: كوكي واحد أول-طرف، HttpOnly + SameSite=Lax (+ Secure على HTTPS)، يُحذف بعد التسجيل.
 */
class ReferralIntent
{
    public const SESSION_KEY = 'referral_intent';

    public function __construct(
        protected ReferralCodeService $codes,
        protected ReferralCampaignService $campaigns,
        protected ReferralClickRecorder $clicks,
    ) {}

    public function ttlDays(): int
    {
        return max(7, min(30, (int) config('referrals.attribution.ttl_days')));
    }

    /** يلتقط النية إن كان الطلب لضيف ورمز صالح. يعيد true فقط إن خُزّنت نية جديدة. */
    public function capture(Request $request, mixed $rawCode, mixed $rawCampaign, string $source): bool
    {
        if (! (bool) config('referrals.enabled') || $request->user() !== null) {
            return false;
        }

        $hit = $this->codes->findActive($rawCode);

        if ($hit === null) {
            return false;
        }

        $this->clicks->record($hit['id'], $request);

        if ($this->current($request) !== null) {
            return false; // أول محيل صالح يربح
        }

        $intent = [
            'code' => $hit['code'],
            'c' => $this->campaigns->validSlug($rawCampaign),
            's' => $source,
            'at' => now()->getTimestamp(),
        ];

        $request->session()->put(self::SESSION_KEY, $intent);
        Cookie::queue(Cookie::make(
            (string) config('referrals.attribution.cookie'),
            json_encode($intent, JSON_UNESCAPED_UNICODE),
            $this->ttlDays() * 1440,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return true;
    }

    /**
     * النية الصالحة الحالية (من الجلسة ثم الكوكي) أو null. الصالحة: صيغة سليمة + ضمن المدة + الرمز ما زال نشطًا.
     *
     * @return array{code: string, c: ?string, s: string, at: int}|null
     */
    public function current(Request $request): ?array
    {
        $raw = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($raw)) {
            $cookie = $request->cookie((string) config('referrals.attribution.cookie'));
            $raw = is_string($cookie) && strlen($cookie) < 512 ? json_decode($cookie, true) : null;
        }

        if (! is_array($raw) || ! isset($raw['code'], $raw['at']) || ! is_int($raw['at'])) {
            return null;
        }

        if ($raw['at'] + $this->ttlDays() * 86400 < now()->getTimestamp() || $raw['at'] > now()->getTimestamp() + 60) {
            return null;
        }

        $hit = $this->codes->findActive($raw['code']);

        if ($hit === null) {
            return null;
        }

        return [
            'code' => $hit['code'],
            'c' => $this->campaigns->validSlug($raw['c'] ?? null),
            's' => in_array($raw['s'] ?? null, ['link', 'share_param'], true) ? $raw['s'] : 'link',
            'at' => $raw['at'],
        ];
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        Cookie::queue(Cookie::forget((string) config('referrals.attribution.cookie')));
    }
}

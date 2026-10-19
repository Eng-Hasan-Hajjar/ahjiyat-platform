<?php

namespace App\Services\Referrals;

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\User;
use App\Models\UserReferral;

/**
 * E33 (E) - إشارات الاحتيال للإحالة. **إشارات لا عقوبات**: لا حظر ولا تجميد حساب ولا رفض تلقائي أبدًا.
 *  - إشارة قوية واحدة، أو عدد (hold_weak_threshold) من الإشارات الضعيفة معًا ⇒ تعليق الإحالة للمراجعة (pending_review) وتأخير المكافأة فقط.
 *  - تطابق IP وحده (إشارة ضعيفة) أو تطابق الجهاز وحده **لا يعلّق ولا يحظر**: شبكات الجامعات/الأسرة/المقاهي مشتركة مشروعًا.
 *  - نقرأ جداول الرصد القائمة فقط (DeviceSighting: بصمة User-Agent ضعيفة أصلًا) ولا نجمع بصمة جديدة؛ ولا نخزّن IP/UA في الإحالة: مفاتيح الإشارات المغلقة فقط.
 */
class ReferralRiskService
{
    public const STRONG = ['referrer_frozen', 'referrer_high_fraud_flag', 'referred_high_fraud_flag'];

    public const WEAK = ['shared_ip', 'shared_device', 'similar_email', 'referrer_burst', 'referrer_daily_velocity', 'fast_action', 'referrer_open_flag', 'referred_open_flag'];

    /** @return list<string> مفاتيح مغلقة فقط */
    public function signalsFor(UserReferral $referral, User $referrer, User $referred): array
    {
        $signals = [];

        if ($referrer->is_frozen) {
            $signals[] = 'referrer_frozen';
        }

        foreach (['referrer' => $referrer, 'referred' => $referred] as $side => $user) {
            $severities = FraudFlag::query()->where('user_id', $user->id)->where('resolved', false)->pluck('severity')->all();

            if (in_array('high', $severities, true) || in_array('critical', $severities, true)) {
                $signals[] = $side.'_high_fraud_flag';
            } elseif ($severities !== []) {
                $signals[] = $side.'_open_flag';
            }
        }

        $since = now()->subDays(max(1, (int) config('referrals.fraud.sighting_lookback_days')));

        foreach (['shared_ip' => 'ip_address', 'shared_device' => 'device_hash'] as $signal => $column) {
            $mine = DeviceSighting::query()->where('user_id', $referred->id)->where('last_seen_at', '>=', $since)->whereNotNull($column)->distinct()->limit(20)->pluck($column)->all();

            if ($mine !== [] && DeviceSighting::query()->where('user_id', $referrer->id)->where('last_seen_at', '>=', $since)->whereIn($column, $mine)->exists()) {
                $signals[] = $signal;
            }
        }

        if ($this->emailRoot((string) $referrer->email) === $this->emailRoot((string) $referred->email)) {
            $signals[] = 'similar_email';
        }

        $burst = UserReferral::query()->where('referrer_user_id', $referrer->id)->where('created_at', '>=', now()->subHour())->count();

        if ($burst >= (int) config('referrals.fraud.referrer_burst_per_hour')) {
            $signals[] = 'referrer_burst';
        }

        $velocity = UserReferral::query()->where('referrer_user_id', $referrer->id)->whereIn('status', UserReferral::QUALIFIED_STATUSES)->where('qualified_at', '>=', now()->subDay())->count();

        if ($velocity >= (int) config('referrals.fraud.referrer_qualified_per_day')) {
            $signals[] = 'referrer_daily_velocity';
        }

        if ($referral->action_at !== null && $referred->created_at !== null
            && $referral->action_at->diffInSeconds($referred->created_at, true) < (int) config('referrals.fraud.fast_action_seconds')) {
            $signals[] = 'fast_action';
        }

        return array_values(array_unique($signals));
    }

    /** @param list<string> $signals */
    public function shouldHold(array $signals): bool
    {
        $strong = array_intersect($signals, self::STRONG);
        $weak = array_intersect($signals, self::WEAK);

        return $strong !== [] || count($weak) >= max(2, (int) config('referrals.fraud.hold_weak_threshold'));
    }

    /** جذر البريد: يوحّد +tag ونقاط gmail لاكتشاف «نفس الشخص» دون تخزين شيء. */
    public function emailRoot(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $local = explode('+', $local, 2)[0];

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}

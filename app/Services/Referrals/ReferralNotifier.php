<?php

namespace App\Services\Referrals;

use App\Models\User;
use App\Models\UserReferral;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Throwable;

/**
 * E33 - إشعارات الإحالة (E15). تُستدعى **بعد** نجاح التغيير (بعد الـcommit) وفشلها لا يُرجع حالة (try/catch + report). مفاتيح idempotency دلالية.
 * لا اسم للمُحال ولا أي بيانات خاصة بالنصوص/المعاملات. **لا إشعار على نقرة ولا على تسجيل وحده** (فقط التأهيل والجائزة).
 */
class ReferralNotifier
{
    public function __construct(protected NotificationDispatcher $dispatcher) {}

    public function qualified(UserReferral $referral): void
    {
        if ($referral->referrer_user_id === null) {
            return;
        }

        $this->send((int) $referral->referrer_user_id, NotificationType::ReferralQualified, [], 'referral_qualified:'.$referral->id, $referral);
    }

    public function rewardReceived(UserReferral $referral, string $side, int $gems): void
    {
        $userId = $side === 'referrer' ? $referral->referrer_user_id : $referral->referred_user_id;

        if ($userId === null || $gems <= 0) {
            return;
        }

        $this->send((int) $userId, NotificationType::ReferralRewardReceived, ['gems' => $gems], 'referral_reward:'.$referral->id.':'.$side, $referral);
    }

    protected function send(int $userId, NotificationType $type, array $params, string $key, UserReferral $referral): void
    {
        if (! (bool) config('referrals.notifications.enabled', true)) {
            return;
        }

        try {
            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return;
            }

            $this->dispatcher->dispatch($user, $type, $params, $key, ['user_referral_id' => $referral->id], [], 'referrals.index');
        } catch (Throwable $e) {
            report($e);
        }
    }
}

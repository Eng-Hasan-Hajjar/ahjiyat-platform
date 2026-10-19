<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E33 (D) - مكافآت الإحالة (اختيارية، **معطّلة افتراضيًا**).
 *
 * التخطيط (plan) يعمل داخل معاملة التأهيل وتحت قفل صف المحيل (فيسلسل سباق السقوف):
 *  - مفتاح عام config(referrals.rewards.enabled) + تفعيل الحملة + حملة حيّة + مبلغ موجب، وإلا skipped بسبب مسجَّل.
 *  - سقوف المحيل (يوم/شهر/عمر) تُحسب من عدد الإحالات التي **حُجزت** مكافآتها (reward_reserved_at)؛ بلوغ سقف ⇒ لا مكافأة لأي جانب (يمنع مزرعة حسابات تُكافأ من جهة المُحال).
 *  - لقطة المبلغ/الحملة تُنسخ إلى الصف (snap_*): تعديل الحملة لاحقًا لا يغيّر تاريخ الإحالة.
 *
 * التسليم (deliver) بعد الـcommit، لكل جانب باستقلال، حصرًا عبر CurrencyWalletService::creditPending (الحجز المعلّق + PendingGemReleaseService) بمفتاح
 * دلالي فريد في دفتر المحفظة: referral-qualified:{referral_id}:{referrer|referred}. تكرار التسليم/سباقه لا يضاعف شيئًا (قفل صف الإحالة + UNIQUE المفتاح).
 * فشل جانب لا يلغي الآخر ولا التأهيل؛ يُعاد بأمر referrals:process. لا طلب عميل يمنح جواهر أبدًا ولا مسار يتجاوز المحفظة.
 */
class ReferralRewardService
{
    public const SIDES = ['referrer' => ['user' => 'referrer_user_id', 'gems' => 'snap_referrer_gems', 'at' => 'referrer_granted_at'], 'referred' => ['user' => 'referred_user_id', 'gems' => 'snap_referred_gems', 'at' => 'referred_granted_at']];

    public function __construct(protected CurrencyWalletService $wallets, protected CurrencyRegistry $currencies, protected ReferralNotifier $notifier) {}

    public static function ledgerKey(int|string $referralId, string $side): string
    {
        return 'referral-qualified:'.$referralId.':'.$side;
    }

    /**
     * @return array<string, mixed> أعمدة لقطة المكافأة لتُكتب مع التأهيل
     */
    public function plan(UserReferral $referral, ?ReferralCampaign $campaign, CarbonInterface $now): array
    {
        $skip = fn (string $reason) => ['reward_status' => UserReferral::REWARD_SKIPPED, 'reward_skip_reason' => $reason, 'snap_referrer_gems' => 0, 'snap_referred_gems' => 0, 'snap_campaign_id' => $campaign?->id, 'reward_reserved_at' => null];

        if ($campaign === null) {
            return $skip('no_campaign');
        }

        if (! (bool) config('referrals.rewards.enabled') || ! $campaign->rewards_enabled) {
            return $skip('rewards_disabled');
        }

        if (! $campaign->isLive($now)) {
            return $skip('campaign_inactive');
        }

        $max = (int) config('referrals.rewards.max_gems_per_side');
        $referrerGems = min($max, max(0, (int) $campaign->referrer_reward_gems));
        $referredGems = min($max, max(0, (int) $campaign->referred_reward_gems));

        if ($referrerGems === 0 && $referredGems === 0) {
            return $skip('zero_amount');
        }

        $caps = $campaign->effectiveCaps();
        $base = UserReferral::query()->where('referrer_user_id', $referral->referrer_user_id)->whereNotNull('reward_reserved_at')->where('id', '!=', $referral->id);

        foreach (['daily' => $now->copy()->startOfDay(), 'monthly' => $now->copy()->startOfMonth(), 'lifetime' => null] as $window => $from) {
            $count = (clone $base)->when($from !== null, fn ($q) => $q->where('reward_reserved_at', '>=', $from))->count();

            if ($count >= $caps[$window]) {
                return $skip('cap_'.$window);
            }
        }

        return [
            'reward_status' => UserReferral::REWARD_PENDING, 'reward_skip_reason' => null,
            'snap_referrer_gems' => $referrerGems, 'snap_referred_gems' => $referredGems,
            'snap_campaign_id' => $campaign->id, 'reward_reserved_at' => $now,
        ];
    }

    /**
     * يسلّم الجوانب غير المسلَّمة (idempotent). يعيد عدد الجوانب التي سُلّمت الآن.
     */
    public function deliver(int $referralId): int
    {
        $delivered = 0;

        foreach (array_keys(self::SIDES) as $side) {
            try {
                $delivered += $this->deliverSide($referralId, $side) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->settle($referralId);

        return $delivered;
    }

    protected function deliverSide(int $referralId, string $side): bool
    {
        $cols = self::SIDES[$side];
        $gems = 0;
        $referral = null;

        $granted = DB::transaction(function () use ($referralId, $side, $cols, &$gems, &$referral) {
            $referral = UserReferral::query()->whereKey($referralId)->lockForUpdate()->first();

            if ($referral === null
                || ! in_array($referral->status, [UserReferral::STATUS_QUALIFIED, UserReferral::STATUS_REWARDED], true)
                || $referral->reward_status === UserReferral::REWARD_SKIPPED
                || $referral->{$cols['at']} !== null) {
                return false;
            }

            $gems = (int) $referral->{$cols['gems']};
            $userId = $referral->{$cols['user']};

            if ($gems <= 0 || $userId === null) {
                return false;
            }

            $user = User::query()->find($userId);

            if ($user === null || $user->is_frozen) {
                return false; // يبقى معلّقًا؛ يعيد المحاولة أمر referrals:process إن زال المانع
            }

            $this->wallets->creditPending($user, $this->currencies->defaultEarnedCurrency(), $gems, 'referral_reward', $referral, self::ledgerKey($referral->id, $side));
            $referral->forceFill([$cols['at'] => now()])->save();

            return true;
        });

        if ($granted && $referral !== null) {
            $this->notifier->rewardReceived($referral, $side, $gems);
        }

        return (bool) $granted;
    }

    /** عند تسليم كل الجوانب ذات المبلغ الموجب ⇒ rewarded/granted. شرطي على الحالة فلا يعيد إحياء إحالة مستبعدة. */
    protected function settle(int $referralId): void
    {
        $r = UserReferral::query()->find($referralId);

        if ($r === null || $r->status !== UserReferral::STATUS_QUALIFIED || $r->reward_status !== UserReferral::REWARD_PENDING) {
            return;
        }

        $done = true;

        foreach (self::SIDES as $cols) {
            if ((int) $r->{$cols['gems']} > 0 && $r->{$cols['at']} === null) {
                $done = false;
            }
        }

        if ($done) {
            UserReferral::query()->whereKey($r->id)->where('status', UserReferral::STATUS_QUALIFIED)
                ->update(['status' => UserReferral::STATUS_REWARDED, 'reward_status' => UserReferral::REWARD_GRANTED, 'rewarded_at' => now()]);
        }
    }
}

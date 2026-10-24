<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTierReward;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserPassRewardClaim;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E40 - استلام مكافآت المسار. السلطة الوحيدة على «من يستلم ماذا»، كلها من الخادم:
 *  - الشريحة تُقرأ من صف التقدّم المقفول (لا من العميل) والمميز من premium_unlocked_at المقفول (لا من علم أو طلب).
 *  - مطالبة واحدة لكل (مستخدم، مكافأة): قفل صف التقدّم + unique في القاعدة ⇒ النقر المزدوج/التزامن لا يمنح مرتين.
 *  - المنح عبر InventoryService/EntitlementService الرسميتين داخل **نفس المعاملة** مع صف المطالبة: لا مطالبة بلا منح ولا منح بلا مطالبة.
 *  - إعادة فحص العنصر لحظة الاستلام (لا أثر لتغيّر لاحق في التعريف على ما بُلغ، لكن عنصرًا لم يعد صالحًا للمنح يُرفض برسالة واضحة دون أن يفسد الباقي).
 *  - نافذة الاستلام: من البدء حتى claim_ends_at. بعدها لا استلام (ولا فقدان نقاط: التقدّم محفوظ للتاريخ).
 *  - لا عملة/جواهر/نقد/مرتبة/جائزة حقيقية: العنصر تجميلي/وصول/رقمي فقط (PassRules) ولا تأثير على أي مسار تنافسي أو سمعة.
 */
class PassClaimService
{
    public const REASON = 'ahjiyat_pass_reward';

    public function __construct(
        protected PassDefinitionCache $cache,
        protected PassProgressService $progress,
        protected InventoryService $inventory,
        protected EntitlementService $entitlements,
    ) {}

    /** @throws PassException */
    public function claim(User $user, AhjiyatPass $pass, int $rewardId, string $source = UserPassRewardClaim::SOURCE_MANUAL): UserPassRewardClaim
    {
        $this->assertCanClaim($user, $pass);

        $track = $this->cache->track($pass);
        $reward = $track->rewardById($rewardId);

        if ($reward === null) {
            throw new PassException('reward_not_found', 'هذه المكافأة غير موجودة في هذا المسار.');
        }

        try {
            return DB::transaction(function () use ($user, $pass, $reward, $source) {
                $existing = $this->progress->find($user, $pass);

                if ($existing === null) {
                    throw new PassException('not_reached', 'لم تبلغ هذه الشريحة بعد.');
                }

                // قفل صف التقدّم يسلسل كل مطالبات هذا اللاعب في هذا المسار (النقر المزدوج/تبويبان/تزامن).
                $locked = UserPassProgress::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();

                if ((int) $reward['tier'] > (int) $locked->highest_tier) {
                    throw new PassException('not_reached', 'لم تبلغ هذه الشريحة بعد.');
                }

                if ($reward['track'] === AhjiyatPassTierReward::TRACK_PREMIUM && ! $locked->hasPremium()) {
                    throw new PassException('premium_required', 'هذه مكافأة المسار المميز.');
                }

                if (UserPassRewardClaim::query()->where('user_id', $user->getKey())->where('ahjiyat_pass_tier_reward_id', $reward['id'])->exists()) {
                    throw new PassException('already_claimed', 'استلمت هذه المكافأة من قبل.');
                }

                $item = StoreItem::query()->find($reward['item_id']);
                $problem = $item === null ? ['item_missing', 'المكافأة غير متاحة حاليًا.'] : $this->itemProblem($item);

                if ($problem !== null) {
                    throw new PassException('item_unavailable', 'هذه المكافأة غير متاحة للاستلام الآن. تواصل مع الدعم إن استمرت المشكلة.', [$problem[0]]);
                }

                $quantity = max(1, (int) $reward['quantity']);
                $tierReward = AhjiyatPassTierReward::query()->find($reward['id']);
                $transactionId = null;
                $entitlementId = null;

                if ($item->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT) {
                    $quantity = 1; // الامتياز لا يُضاعَف بالكمية.
                    $entitlementId = $this->entitlements->grant($user, $item)->getKey();
                } else {
                    $transactionId = $this->inventory->grant($user, $item, $quantity, self::REASON, $tierReward)->getKey();
                }

                $claim = new UserPassRewardClaim;
                $claim->forceFill([
                    'user_id' => $user->getKey(),
                    'user_pass_progress_id' => $locked->getKey(),
                    'ahjiyat_pass_id' => $pass->getKey(),
                    'ahjiyat_pass_tier_reward_id' => $reward['id'],
                    'store_item_id' => $item->getKey(),
                    'track' => $reward['track'],
                    'tier_number' => $reward['tier'],
                    'quantity' => $quantity,
                    'item_name' => mb_substr((string) $item->name, 0, 160),
                    'source' => $source,
                    'inventory_transaction_id' => $transactionId,
                    'user_entitlement_id' => $entitlementId,
                ])->save();

                return $claim;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PassException('already_claimed', 'استلمت هذه المكافأة من قبل.');
        }
    }

    /**
     * «استلام الكل»: كل مكافأة تُستلم **باستقلال** (معاملة لكل واحدة): فشل مكافأة لا يمنع الباقي ولا يُرجع ما نجح. مقيَّد بسقف إعداد.
     *
     * @return array{claimed: list<int>, failed: list<array{reward_id: int, reason: string}>, remaining: int}
     */
    public function collectAll(User $user, AhjiyatPass $pass): array
    {
        $this->assertCanClaim($user, $pass);

        $progress = $this->progress->find($user, $pass);

        if ($progress === null) {
            return ['claimed' => [], 'failed' => [], 'remaining' => 0];
        }

        $claimedIds = UserPassRewardClaim::query()->where('user_pass_progress_id', $progress->getKey())->pluck('ahjiyat_pass_tier_reward_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $claimable = $this->cache->track($pass)->claimable((int) $progress->highest_tier, $progress->hasPremium(), $claimedIds);
        $cap = max(1, (int) config('ahjiyat_pass.rewards.collect_all_max', 60));
        $batch = array_slice($claimable, 0, $cap);

        $claimed = [];
        $failed = [];

        foreach ($batch as $reward) {
            try {
                $this->claim($user, $pass, (int) $reward['id'], UserPassRewardClaim::SOURCE_COLLECT_ALL);
                $claimed[] = (int) $reward['id'];
            } catch (PassException $e) {
                if ($e->reason !== 'already_claimed') {
                    $failed[] = ['reward_id' => (int) $reward['id'], 'reason' => $e->reason];
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['reward_id' => (int) $reward['id'], 'reason' => 'error'];
            }
        }

        return ['claimed' => $claimed, 'failed' => $failed, 'remaining' => max(0, count($claimable) - count($batch)) + count($failed)];
    }

    /** @throws PassException */
    protected function assertCanClaim(User $user, AhjiyatPass $pass): void
    {
        if (! (bool) config('ahjiyat_pass.enabled', true)) {
            throw new PassException('disabled', 'مسار الأحجيات غير متاح حاليًا.');
        }

        if ($user->is_frozen) {
            throw new PassException('frozen', 'حسابك مقيَّد؛ لا يمكن استلام المكافآت.');
        }

        if (! $pass->isClaimWindowOpen()) {
            throw new PassException('claim_closed', 'انتهت مهلة استلام مكافآت هذا المسار.');
        }
    }

    /**
     * فحص أخف من PassRules::itemProblem عمدًا: لا يعيد فرض «غير معروض للبيع» (قاعدة تعريف لا استلام) ولا يمنع بسبب تغيّر غير جوهري.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function itemProblem(StoreItem $item): ?array
    {
        if (! $item->is_active) {
            return ['item_inactive', 'inactive'];
        }

        if ($item->ends_at !== null && $item->ends_at->isPast()) {
            return ['item_expired', 'expired'];
        }

        if (! in_array($item->item_type, (array) config('ahjiyat_pass.rewards.allowed_item_types', ['cosmetic', 'access', 'digital']), true)) {
            return ['item_type', 'type'];
        }

        if (! in_array($item->fulfillment_type, [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT], true)) {
            return ['item_fulfillment', 'fulfillment'];
        }

        if ($item->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT && blank($item->entitlement_key)) {
            return ['item_entitlement', 'entitlement'];
        }

        return null;
    }
}

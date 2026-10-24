<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTierReward;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserPassRewardClaim;
use App\Services\Commerce\CommerceFulfillmentException;
use App\Services\Commerce\CommerceProductHandler;

/**
 * E41 - معالج منتج «المسار المميز» (E40 يبقى السلطة): شراء مؤكَّد ⇒ premium_unlocked_at على تقدّم اللاعب، وسحبه عند الاسترداد.
 * هذا هو **المنطق الوحيد** لفتح/سحب المميز، تستدعيه نواة التجارة (DigitalPurchaseService) للمسارين معًا (مراجع يدوي + مزوّد موثوق)، فلا تكرار لمنطق الفتح.
 * يُستدعى داخل معاملة والطلب مقفول؛ القفل التالي هو صف التقدّم (ترتيب القفل نفسه كما في E40: الطلب ثم التقدّم).
 * لا نقاط ولا شرائح ولا مكافآت تُمنح هنا: المميز يجعل مكافآت الشرائح المبلوغة قابلة للاستلام (مشتقّة) فقط.
 */
class PassPremiumProductHandler implements CommerceProductHandler
{
    public function __construct(protected PassProgressService $progress, protected PassNotifier $notifier) {}

    public function productType(): string
    {
        return DigitalPurchase::PRODUCT_PASS_PREMIUM;
    }

    public function fulfill(DigitalPurchase $locked): void
    {
        $pass = AhjiyatPass::query()->find($locked->product_id);
        $buyer = User::query()->find($locked->user_id);

        if ($pass === null || $buyer === null || $pass->locked_at === null) {
            throw new CommerceFulfillmentException('pass_missing', 'المسار المرتبط بالطلب غير موجود.');
        }

        if ($pass->status === AhjiyatPass::STATUS_ARCHIVED) {
            throw new CommerceFulfillmentException('pass_archived', 'المسار مؤرشف؛ ارفض الطلب مع رسالة للمشتري وتصرّف مالياً خارج المنصة.');
        }

        if ($buyer->is_frozen) {
            throw new CommerceFulfillmentException('frozen', 'حساب المشتري مقيَّد؛ لا يُفتح المميز له. راجع الحالة قبل أي قرار.');
        }

        try {
            $progress = $this->progress->ensure($buyer, $pass);
        } catch (PassException $e) {
            throw new CommerceFulfillmentException($e->reason, $e->getMessage());
        }

        $lockedProgress = UserPassProgress::query()->whereKey($progress->getKey())->lockForUpdate()->firstOrFail();

        if ($lockedProgress->hasPremium()) {
            throw new CommerceFulfillmentException('already_owned', 'يملك المشتري المميز بالفعل؛ راجع الطلب كمكرر.');
        }

        $lockedProgress->forceFill(['premium_unlocked_at' => now(), 'premium_purchase_id' => $locked->getKey()])->save();
    }

    public function claimedItemCount(DigitalPurchase $locked): int
    {
        $progress = $this->lockedProgress($locked);

        return $progress === null ? 0 : UserPassRewardClaim::query()
            ->where('user_pass_progress_id', $progress->getKey())
            ->where('track', AhjiyatPassTierReward::TRACK_PREMIUM)
            ->count();
    }

    public function reverse(DigitalPurchase $locked): void
    {
        $progress = $this->lockedProgress($locked);

        if ($progress !== null && $progress->premium_purchase_id === $locked->getKey()) {
            $progress->forceFill(['premium_unlocked_at' => null, 'premium_purchase_id' => null])->save();
        }
    }

    public function notifyVerified(DigitalPurchase $purchase): void
    {
        $this->notifier->premiumUnlocked($purchase);
    }

    protected function lockedProgress(DigitalPurchase $locked): ?UserPassProgress
    {
        return UserPassProgress::query()
            ->where('user_id', $locked->user_id)
            ->where('ahjiyat_pass_id', $locked->product_id)
            ->lockForUpdate()
            ->first();
    }
}

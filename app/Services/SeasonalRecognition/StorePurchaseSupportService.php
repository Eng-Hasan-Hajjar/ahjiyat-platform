<?php

namespace App\Services\SeasonalRecognition;

use App\Models\SeasonSupportPointEntry;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E44 - جسر متجر العملات الداخلية ⇒ نقاط الدعم. **لا يمسّ مسار الشراء المالي إطلاقًا**: يُستدعى من مراقب نموذج بعد الـcommit ويعزل كل فشل (الشراء ينجح مهما حدث هنا).
 *
 *  - اللقطة: عند إنشاء الشراء تُنسخ نقاط خيار السعر (store_item_prices.support_points، الافتراضي 0 للقديم) إلى store_purchases.support_points_snapshot - فقط إن كان مفتاح الداعمين مفعّلًا حينها.
 *    تغيير السعر لاحقًا لا يغيّر ما وُعد به الشراء ولا ما يُعكس عند الاسترداد.
 *  - المنح: شراء **منفَّذ فعليًا** (status = fulfilled) وحده يمنح. الموسم = الموسم الحيّ الوحيد المؤهَّل (SeasonSupportSeasonResolver):
 *      صفر ⇒ لا نقاط؛ أكثر من واحد ⇒ لا تخمين (لا منح + أثر تدقيق + تنبيه سجل + تظهر في المطابقة كـstore_purchase_without_grant).
 *  - الاسترداد: قيد سالب واحد يعكس لقطة المنح الأصلي (متكرر الأمان).
 * نقاط المتجر علنية (is_public = true)؛ إخفاء الأسماء المجهولة وملفات E30 غير العلنية يتم عند القراءة.
 */
class StorePurchaseSupportService
{
    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonSupportSeasonResolver $resolver,
        protected SeasonSupportPointService $points,
        protected SeasonalRecognitionBroadcaster $broadcaster,
        protected OperationalAuditService $audit,
    ) {}

    /** لقطة النقاط عند إنشاء الشراء (يستدعيها مراقب creating). */
    public function snapshotFor(StorePurchase $purchase): int
    {
        if (! $this->settings->supportersGloballyEnabled() || $purchase->store_item_price_id === null) {
            return 0;
        }

        $points = (int) StoreItemPrice::query()->whereKey($purchase->store_item_price_id)->value('support_points');
        $max = (int) config('seasonal_recognition.supporters.max_support_points_per_purchase', 1000000);

        return max(0, min($points, $max));
    }

    /** @return string granted|not_applicable|no_points|no_season|ambiguous|duplicate|failed */
    public function onFulfilled(int $purchaseId): string
    {
        try {
            $purchase = StorePurchase::query()->find($purchaseId);

            if ($purchase === null || $purchase->status !== StorePurchase::STATUS_FULFILLED) {
                return 'not_applicable';
            }

            $snapshot = (int) $purchase->getAttribute('support_points_snapshot');

            if ($snapshot <= 0) {
                return 'no_points';
            }

            $resolved = $this->resolver->resolve();

            if ($resolved['status'] === SeasonSupportSeasonResolver::NONE) {
                return 'no_season';
            }

            if ($resolved['status'] === SeasonSupportSeasonResolver::AMBIGUOUS) {
                $this->audit->log('seasonal_support_ambiguous_season', $purchase, ['store_purchase_id' => $purchase->id, 'candidate_season_ids' => $resolved['ids']], null);
                Log::warning('E44: store purchase support points skipped - more than one eligible live season.', ['store_purchase_id' => $purchase->id, 'seasons' => $resolved['ids']]);

                return 'ambiguous';
            }

            $entry = $this->points->grant((int) $resolved['season']->id, (int) $purchase->user_id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, (int) $purchase->id, $snapshot, true);

            if ($entry === null) {
                return 'duplicate';
            }

            $this->afterChange((int) $resolved['season']->id);

            return 'granted';
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        }
    }

    /** يعكس منح شراء مُسترد (قيد سالب واحد). true إن أُنشئ عكس جديد. */
    public function onRefunded(int $purchaseId): bool
    {
        try {
            $entry = $this->points->reverse(SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $purchaseId);

            if ($entry === null) {
                return false;
            }

            $this->afterChange((int) $entry->season_id);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function afterChange(int $seasonId): void
    {
        SeasonalRecognitionCache::bump($seasonId, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
        $this->broadcaster->supportersUpdated($seasonId);
    }
}

<?php

namespace App\Observers\SeasonalRecognition;

use App\Models\StorePurchase;
use App\Services\SeasonalRecognition\StorePurchaseSupportService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - يربط متجر العملات الداخلية بنقاط الدعم **دون تعديل مسار الشراء المالي**:
 *  - creating: لقطة support_points_snapshot (0 للقديم أو عند تعطيل المفتاح) تُكتب مع صف الشراء نفسه.
 *  - المنح عند الانتقال إلى fulfilled والعكس عند الانتقال إلى refunded: بعد الـcommit فقط وبعزل كامل لأي فشل (الشراء/الاسترداد ينجح مهما حدث هنا).
 * الخدمة نفسها متكررة الأمان (مفتاح مصدر فريد) فلا يضاعف تكرار الاستدعاء شيئًا.
 */
class StorePurchaseSupportObserver
{
    public function __construct(protected StorePurchaseSupportService $support) {}

    public function creating(StorePurchase $purchase): void
    {
        try {
            $purchase->setAttribute('support_points_snapshot', $this->support->snapshotFor($purchase));
        } catch (Throwable $e) {
            report($e);
            $purchase->setAttribute('support_points_snapshot', 0);
        }
    }

    public function created(StorePurchase $purchase): void
    {
        if ($purchase->status === StorePurchase::STATUS_FULFILLED) {
            $this->afterCommit(fn () => $this->support->onFulfilled((int) $purchase->id));
        }
    }

    public function updated(StorePurchase $purchase): void
    {
        if (! $purchase->wasChanged('status')) {
            return;
        }

        $id = (int) $purchase->id;

        match ($purchase->status) {
            StorePurchase::STATUS_FULFILLED => $this->afterCommit(fn () => $this->support->onFulfilled($id)),
            StorePurchase::STATUS_REFUNDED => $this->afterCommit(fn () => $this->support->onRefunded($id)),
            default => null,
        };
    }

    protected function afterCommit(\Closure $work): void
    {
        DB::afterCommit(function () use ($work): void {
            try {
                $work();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}

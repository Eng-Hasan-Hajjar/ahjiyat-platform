<?php

namespace App\Observers\SeasonalRecognition;

use App\Models\SponsorshipPayment;
use App\Services\SeasonalRecognition\SeasonSupporterCardService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - يردّ على تغيّر حالة طلب دفع E32 لبطاقات الداعم **دون تعديل خدمة الدفع**: verified ⇒ تفعيل ومنح، refunded ⇒ عكس، rejected|cancelled|expired ⇒ إلغاء البطاقة المعلّقة.
 * بعد الـcommit وبعزل كامل لأي فشل: لا يتراجع تحقق مالي لأن بطاقة تعذّر تفعيلها؛ المطابقة تكشف البطاقات العالقة والخدمات متكررة الأمان فتعيد التفعيل بأمان.
 * طلبات الدفع التي لا بطاقة لها (الرعاية العادية) تمرّ بلا أثر: بحث واحد بالمفتاح الفريد داخل الخدمة.
 */
class SponsorshipPaymentCardObserver
{
    public function __construct(protected SeasonSupporterCardService $cards) {}

    public function updated(SponsorshipPayment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        $id = (int) $payment->id;

        $work = match ($payment->status) {
            SponsorshipPayment::STATUS_VERIFIED => fn () => $this->cards->activateForPayment($id),
            SponsorshipPayment::STATUS_REFUNDED => fn () => $this->cards->refundForPayment($id),
            SponsorshipPayment::STATUS_REJECTED, SponsorshipPayment::STATUS_CANCELLED, SponsorshipPayment::STATUS_EXPIRED => fn () => $this->cards->voidForPayment($id),
            default => null,
        };

        if ($work === null) {
            return;
        }

        DB::afterCommit(function () use ($work): void {
            try {
                $work();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}

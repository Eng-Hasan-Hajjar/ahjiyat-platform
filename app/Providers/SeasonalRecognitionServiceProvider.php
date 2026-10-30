<?php

namespace App\Providers;

use App\Models\SponsorshipPayment;
use App\Models\StorePurchase;
use App\Observers\SeasonalRecognition\SponsorshipPaymentCardObserver;
use App\Observers\SeasonalRecognition\StorePurchaseSupportObserver;
use App\Services\SeasonalRecognition\SeasonSupporterIdentityResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * E44 - تسجيل مراقبي التقدير الموسمي ومحدِّدات المعدّل. مزوِّد مستقل عمدًا (لا تعديل على AppServiceProvider المشترك).
 *  - مراقب المتجر: لقطة نقاط الدعم + المنح/العكس بعد الـcommit. مراقب الدفع: تفعيل/عكس/إلغاء بطاقات الداعم. كلاهما معزول الفشل.
 *  - seasonal-vote: تعديلات النجوم لكل مستخدم بالدقيقة (config مع سقف إنتاجي). seasonal-card-request: طلبات البطاقات (مال حقيقي) أضيق.
 */
class SeasonalRecognitionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // عارض الهوية الموسمية يُستدعى لكل صفحة ملف؛ نسخة واحدة لكل طلب (بلا حالة تتسرب بين الطلبات: Octane-safe لأن الاعتماديات بلا حالة).
        $this->app->scoped(SeasonSupporterIdentityResolver::class);
    }

    public function boot(): void
    {
        StorePurchase::observe(StorePurchaseSupportObserver::class);
        SponsorshipPayment::observe(SponsorshipPaymentCardObserver::class);

        RateLimiter::for('seasonal-vote', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('seasonal_recognition.writers.mutations_per_minute', 30)))->by('sv:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('seasonal-card-request', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('seasonal_recognition.supporters.card_requests_per_minute', 6)))->by('scr:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('seasonal-board', function (Request $request) {
            return Limit::perMinute(60)->by('sb:'.($request->user()?->id ?: $request->ip()));
        });
    }
}

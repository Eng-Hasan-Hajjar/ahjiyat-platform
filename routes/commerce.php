<?php

use App\Http\Controllers\Commerce\CommerceCheckoutController;
use App\Http\Controllers\Commerce\CommercePurchaseController;
use App\Http\Controllers\Commerce\CommerceSandboxController;
use App\Services\Commerce\CommerceEnvironment;
use Illuminate\Support\Facades\Route;

/*
| E41 - التجارة الاستهلاكية (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل ما يغيّر حالة).
|
|  البوابة: حساب موثَّق (auth + verified) + غير مجمَّد (account.active). كل مخرج محصور بمالك الشراء (طلب غيره = 404).
|  التسمية: يتجنب حارس مسارات E18 (RewardSecurityEconomyTest) أي كلمة reward|grant|claim|payout|prize في URI أو اسم المسار أو المحدِّد: checkout / purchases / receipts / orders / commerce.
|  عودة المزوّد (GET) عرض فقط ولا تؤكد دفعًا أبدًا. بدء الدفع POST بحد معدّل مسمّى. الاستطلاع قراءة فقط بحد معدّل مسمّى.
|  المسارات التجريبية (sandbox) لا تُسجَّل في الإنتاج إطلاقًا. webhook المزوّد في routes/commerce_webhooks.php (توقيع، بلا CSRF بطبيعة مجموعة api).
*/
Route::middleware(['auth', 'verified', 'account.active'])->prefix('commerce')->group(function () {
    Route::middleware('throttle:commerce-read')->group(function () {
        Route::get('/purchases', [CommercePurchaseController::class, 'index'])->name('commerce.purchases.index');
        Route::get('/purchases/{order}/receipt', [CommercePurchaseController::class, 'receipt'])->whereUlid('order')->name('commerce.receipts.show');
        Route::get('/checkout/{order}/return', [CommerceCheckoutController::class, 'return'])->whereUlid('order')->name('commerce.checkout.return');
    });

    Route::get('/orders/{order}/status', [CommercePurchaseController::class, 'status'])->whereUlid('order')->middleware('throttle:commerce-status')->name('commerce.orders.status');
    Route::post('/checkout/{order}', [CommerceCheckoutController::class, 'start'])->whereUlid('order')->middleware('throttle:commerce-checkout')->name('commerce.checkout.start');

    if (CommerceEnvironment::sandboxAllowed()) {
        Route::get('/sandbox/{session}', [CommerceSandboxController::class, 'show'])->where('session', '[A-Za-z0-9_]{1,64}')->middleware('throttle:commerce-read')->name('commerce.sandbox.pay');
        Route::post('/sandbox/{session}/{outcome}', [CommerceSandboxController::class, 'complete'])->where('session', '[A-Za-z0-9_]{1,64}')->whereIn('outcome', ['paid', 'failed', 'cancelled'])->middleware('throttle:commerce-checkout')->name('commerce.sandbox.complete');
    }
});

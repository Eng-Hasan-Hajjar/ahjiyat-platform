<?php

use App\Http\Controllers\Support\CheckoutController;
use App\Http\Controllers\Support\PaymentController;
use App\Http\Controllers\Support\StaffProofController;
use App\Http\Controllers\Support\SupportController;
use Illuminate\Support\Facades\Route;

/*
| E32 - مسارات الدعم والدفع (تُضمَّن من routes/web.php بمجموعة web: الجلسة وCSRF مفعّلان لكل مسار كتابة).
| القراءة العامة (/support, /support/{program}) بلا حساب؛ كل ما يخصّ الطلبات والمال خلف auth + verified + account.active.
| الطلب يُحَلّ بالملكية داخل الاستعلام (404 لغير المالك). لا مسار هنا يؤكّد أو يمنح أو يستردّ: ذلك من لوحة الإدارة عبر SponsorshipPaymentService حصرًا.
| الأسبقية: المسارات الثابتة (history, payments/…) قبل {program} كي لا يلتقطها وسيط البرنامج.
*/

Route::middleware('throttle:support-read')->group(function () {
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
});

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/support/history', [PaymentController::class, 'history'])->middleware('throttle:payment-status')->name('support.history');

    Route::prefix('/support/payments/{payment}')->name('support.payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'show'])->middleware('throttle:payment-status')->name('show');
        Route::get('/proof', [PaymentController::class, 'proof'])->middleware('throttle:payment-status')->name('proof');
        Route::post('/transferred', [PaymentController::class, 'transferred'])->middleware(['throttle:support-submit', 'throttle:support-proof-upload'])->name('transferred');
        Route::post('/proof', [PaymentController::class, 'uploadProof'])->middleware('throttle:support-proof-upload')->name('proof.upload');
        Route::post('/cancel', [PaymentController::class, 'cancel'])->middleware('throttle:support-submit')->name('cancel');
    });

    // مراجع مالي: تنزيل الإثبات (سياسة downloadProof ⇒ sponsorships.confirm) + تدقيق كل تنزيل.
    Route::get('/payments/staff/{payment:public_id}/proof', StaffProofController::class)->middleware('throttle:payment-status')->name('payments.staff.proof');

    Route::get('/support/{program:slug}/checkout', [CheckoutController::class, 'create'])->middleware('throttle:support-read')->name('support.checkout');
    Route::post('/support/{program:slug}/checkout', [CheckoutController::class, 'store'])->middleware('throttle:support-checkout')->name('support.checkout.store');
});

Route::middleware('throttle:support-read')->group(function () {
    Route::get('/support/{program:slug}', [SupportController::class, 'show'])->name('support.program');
});

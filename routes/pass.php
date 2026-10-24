<?php

use App\Http\Controllers\Pass\PassController;
use App\Http\Controllers\Pass\PassOrderController;
use App\Http\Middleware\EnsurePassEnabled;
use Illuminate\Support\Facades\Route;

/*
| E40 - مسار الأحجيات (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل ما يغيّر حالة).
|
|  البوابة: حساب موثَّق (auth + verified) + غير مجمَّد (account.active). المجمَّد/المقيَّد بسياق الأمان (E38) لا يبلغ هذه المسارات بالبناء.
|  GET قراءة فقط (الانضمام الكسول للمسار الفعّال واستعادة النقاط الفائتة على GET idempotent ولا يمنح مكافأة). كل ما يغيّر حالة POST بحدود معدّل مسمّاة.
|  لا مسار يقبل سعرًا أو عملة أو شريحة أو مميزًا من العميل: السعر من الخادم، الشريحة من تقدّمه، والمميز من التحقق المالي وحده.
|  التسمية: يتجنب حارس مسارات E18 (RewardSecurityEconomyTest) أي كلمة reward|grant|claim|payout|prize في URI أو اسم المسار أو اسم المحدِّد، لذا «collect» و«slot» و«orders».
*/
Route::middleware(['auth', 'verified', 'account.active', EnsurePassEnabled::class])->prefix('pass')->group(function () {
    Route::middleware('throttle:pass-read')->group(function () {
        Route::get('/', [PassController::class, 'index'])->name('pass.index');
        Route::get('/archive', [PassController::class, 'archive'])->name('pass.archive');
        Route::get('/archive/{pass}', [PassController::class, 'archiveShow'])->whereUlid('pass')->name('pass.archive.show');
        Route::get('/premium', [PassOrderController::class, 'premium'])->name('pass.premium');
        Route::get('/orders/{order}', [PassOrderController::class, 'show'])->whereUlid('order')->name('pass.orders.show');
    });

    Route::middleware('throttle:pass-collect')->group(function () {
        Route::post('/collect/{slot}', [PassController::class, 'collect'])->whereNumber('slot')->name('pass.collect');
        Route::post('/collect-all', [PassController::class, 'collectAll'])->name('pass.collect_all');
    });

    Route::post('/orders', [PassOrderController::class, 'store'])->middleware('throttle:pass-order')->name('pass.orders.store');
    Route::post('/orders/{order}/submit', [PassOrderController::class, 'submit'])->whereUlid('order')->middleware(['throttle:pass-submit', 'throttle:pass-proof'])->name('pass.orders.submit');
    Route::post('/orders/{order}/cancel', [PassOrderController::class, 'cancel'])->whereUlid('order')->middleware('throttle:pass-order')->name('pass.orders.cancel');
});

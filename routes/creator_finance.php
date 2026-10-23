<?php

use App\Http\Controllers\Creators\CreatorEarningsController;
use App\Http\Middleware\EnsureCreatorStudioAccess;
use Illuminate\Support\Facades\Route;

/*
| E39 - أرباح المبدع (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  نفس بوابة استوديو المبدعين: ملف معتمَد + بريد موثَّق + حساب غير مجمَّد (account.active). المجمَّد/المقيَّد بسياق الأمان (E38) لا يبلغ هذه المسارات بالبناء.
|  GET قراءة فقط (لا تعديل حالة عبر GET). الإجراءان المصرَّفان (طلب دفعة/إلغاؤها) POST بمحدّدات خاصة، والملكية تُحلّ باستعلام محصور بالمستخدم (ULID عام لا معرّف تسلسلي).
|  لا مسار لتعديل رصيد أو تخصيص أو مرجع صرف من جهة المبدع أبدًا: كلها حصرًا لخدمات الإدارة.
*/
Route::middleware(['auth', 'verified', 'account.active', EnsureCreatorStudioAccess::class])->prefix('creator/earnings')->group(function () {
    Route::get('/', [CreatorEarningsController::class, 'index'])->middleware('throttle:creator-earnings-read')->name('creator.earnings');
    Route::get('/statements/{period}', [CreatorEarningsController::class, 'statement'])->whereUlid('period')->middleware('throttle:creator-earnings-read')->name('creator.earnings.statement');
    Route::post('/payouts', [CreatorEarningsController::class, 'requestPayout'])->middleware('throttle:creator-payout-request')->name('creator.earnings.payouts.store');
    Route::post('/payouts/{payout}/cancel', [CreatorEarningsController::class, 'cancelPayout'])->whereUlid('payout')->middleware('throttle:creator-payout-cancel')->name('creator.earnings.payouts.cancel');
});

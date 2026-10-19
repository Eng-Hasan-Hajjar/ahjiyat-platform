<?php

use App\Http\Controllers\Referrals\ReferralController;
use App\Http\Controllers\Referrals\ReferralLandingController;
use Illuminate\Support\Facades\Route;

/*
| E33 - الإحالات والنمو (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF).
|
| /r/{code}   عام (ضيف يلتقط النية؛ المصادَق يُوجَّه للرئيسية). GET فقط، لا يغيّر حالة المستخدم، محدود المعدّل (referral-redirect).
| /referrals  خاص بصاحبه (auth + verified + account.active). كل تعديل POST فقط بحدود معدّل مسماة.
*/

Route::get('/r/{code}', ReferralLandingController::class)->where('code', '[A-Za-z0-9]{1,32}')->middleware('throttle:referral-redirect')->name('referrals.landing');

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/referrals', [ReferralController::class, 'index'])->middleware('throttle:referral-dashboard')->name('referrals.index');
    Route::post('/referrals/rotate', [ReferralController::class, 'rotate'])->middleware('throttle:referral-dashboard')->name('referrals.rotate');
    Route::post('/referrals/share', [ReferralController::class, 'share'])->middleware('throttle:referral-share')->name('referrals.share');
});

<?php

use App\Http\Controllers\SeasonalRecognition\SeasonalSupportersController;
use App\Http\Controllers\SeasonalRecognition\SeasonalWritersController;
use Illuminate\Support\Facades\Route;

/*
| E44 - دوري كتّاب الموسم وتقدير الداعمين (تُضمَّن من routes/web.php بمجموعة web: الجلسة وCSRF مفعّلان لكل مسار كتابة).
| أسماء المسارات seasonal.writers.* / seasonal.supporters.* (لا مسارات seasons.* جديدة). القراءة العامة بلا حساب؛ كل كتابة خلف auth + verified + account.active.
| الأمر الحاسم يبقى بالخدمات (SeasonWriterVotingService / SeasonSupporterCardService): هنا لا أهلية ولا أسعار ولا ميزانية تُقرأ من العميل.
| لا مسار هنا ينهي ترتيبًا أو يمنح جائزة أو يؤكد دفعًا: ذلك من لوحة الإدارة (Filament) حصرًا.
*/

Route::prefix('/seasons/{season:slug}')->name('seasonal.')->group(function () {
    Route::middleware('throttle:seasonal-board')->group(function () {
        Route::get('/writers', [SeasonalWritersController::class, 'show'])->name('writers.show');
        Route::get('/writers/standings', [SeasonalWritersController::class, 'standings'])->name('writers.standings');
        Route::get('/supporters', [SeasonalSupportersController::class, 'show'])->name('supporters.show');
        Route::get('/supporters/standings', [SeasonalSupportersController::class, 'standings'])->name('supporters.standings');
    });

    Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
        Route::post('/writers/{entry}/stars', [SeasonalWritersController::class, 'allocate'])->whereUlid('entry')->middleware('throttle:seasonal-vote')->name('writers.stars');

        Route::post('/supporters/cards', [SeasonalSupportersController::class, 'requestCard'])->middleware('throttle:seasonal-card-request')->name('supporters.cards.store');
    });
});

<?php

use App\Http\Controllers\CreatorSeries\CreatorSeriesStudioController;
use App\Http\Controllers\CreatorSeries\SeriesController;
use App\Http\Middleware\EnsureCreatorStudioAccess;
use Illuminate\Support\Facades\Route;

/*
| E30 (B) - سلاسل المبدعين (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  عام (قراءة فقط، للضيوف): /series/{معرّف عام ULID أو slug}. السلسلة غير الظاهرة = 404 للجميع (مسودة/مرفوضة/مخفية/مؤرشفة/عنصر مخفي/مبدع معلَّق).
|  استوديو المبدع (نفس بوابة E28: ملف معتمَد + بريد موثَّق + حساب غير مجمَّد): كل مسارات {series} بمعرّف ULID عام يُحلّ باستعلام محصور بالمالك.
|  لا مسار GET يكتب شيئًا، ولا مسار لعب جديد: اللعب عبر مسارات أحجيات المجتمع القائمة (E28).
| الصفحات الحرفية تسبق الديناميكية (create قبل {series}). المسارات مسجّلة دائمًا (مسار الإشعارات series.show يجب أن يوجد)، وتعطيل الميزة (CREATOR_SERIES_ENABLED=false) يردّ 404 من المتحكمات.
*/

Route::get('/series/{series}', [SeriesController::class, 'show'])->where('series', '[A-Za-z0-9_-]{3,110}')->middleware('throttle:series-read')->name('series.show');

Route::middleware(['auth', 'verified', 'account.active', EnsureCreatorStudioAccess::class])->prefix('creator')->name('creator.')->group(function () {
    Route::get('/series', [CreatorSeriesStudioController::class, 'index'])->name('series.index');
    Route::get('/series/create', [CreatorSeriesStudioController::class, 'create'])->name('series.create');
    Route::post('/series', [CreatorSeriesStudioController::class, 'store'])->middleware('throttle:creator-draft-create')->name('series.store');

    Route::prefix('/series/{series}')->whereUlid('series')->name('series.')->group(function () {
        Route::get('/edit', [CreatorSeriesStudioController::class, 'edit'])->name('edit');
        Route::put('/', [CreatorSeriesStudioController::class, 'update'])->name('update');
        Route::delete('/', [CreatorSeriesStudioController::class, 'destroy'])->name('destroy');
        Route::post('/submit', [CreatorSeriesStudioController::class, 'submit'])->middleware('throttle:creator-submit')->name('submit');
        Route::post('/withdraw', [CreatorSeriesStudioController::class, 'withdraw'])->middleware('throttle:creator-submit')->name('withdraw');
        Route::post('/revise', [CreatorSeriesStudioController::class, 'revise'])->middleware('throttle:creator-submit')->name('revise');
        Route::post('/archive', [CreatorSeriesStudioController::class, 'archive'])->name('archive');
        Route::post('/unarchive', [CreatorSeriesStudioController::class, 'unarchive'])->name('unarchive');
    });
});

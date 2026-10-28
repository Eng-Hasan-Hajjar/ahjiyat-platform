<?php

use App\Http\Controllers\CreatorSeries\CreatorSeriesStudioController;
use App\Http\Controllers\CreatorSeries\SeriesController;
use App\Http\Controllers\CreatorPremium\CreatorPremiumStudioController;
use App\Http\Controllers\CreatorPremium\SeriesPremiumOrderController;
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

// E43: بدء شراء الوصول لسلسلة مميزة (POST فقط، حساب موثَّق غير مجمَّد). لا يرسل العميل سعرًا ولا عملة ولا مبدعًا ولا purchase_id: الخادم وحده يحدد كل ذلك من العرض.
// الأسماء تتجنب حارس E18 (لا reward|grant|claim|payout|prize). يعيد التوجيه إلى صفحة دفع المزوّد المستضافة (E41) أو يعود برسالة.
Route::post('/series/{series}/premium/order', [SeriesPremiumOrderController::class, 'store'])->whereUlid('series')
    ->middleware(['auth', 'verified', 'account.active', 'throttle:commerce-checkout'])->name('series.premium.order');

Route::middleware(['auth', 'verified', 'account.active', EnsureCreatorStudioAccess::class])->prefix('creator')->name('creator.')->group(function () {
    Route::get('/series', [CreatorSeriesStudioController::class, 'index'])->name('series.index');
    Route::get('/series/create', [CreatorSeriesStudioController::class, 'create'])->name('series.create');
    Route::post('/series', [CreatorSeriesStudioController::class, 'store'])->middleware('throttle:creator-draft-create')->name('series.store');

    // E43: استوديو السلاسل المميزة (عروض البيع + مبيعات المبدع المجمَّعة). الطفرات بحد معدّل مسمّى. لا كلمات E18 في الأسماء.
    Route::prefix('/premium')->name('premium.')->group(function () {
        Route::get('/', [CreatorPremiumStudioController::class, 'index'])->middleware('throttle:series-read')->name('index');

        Route::middleware('throttle:creator-premium-offer')->prefix('/offers')->name('offers.')->group(function () {
            Route::post('/', [CreatorPremiumStudioController::class, 'storeOffer'])->name('store');
            Route::put('/{offer}/price', [CreatorPremiumStudioController::class, 'updatePrice'])->whereUlid('offer')->name('price');
            Route::post('/{offer}/activate', [CreatorPremiumStudioController::class, 'activate'])->whereUlid('offer')->name('activate');
            Route::post('/{offer}/pause', [CreatorPremiumStudioController::class, 'pause'])->whereUlid('offer')->name('pause');
            Route::post('/{offer}/retire', [CreatorPremiumStudioController::class, 'retire'])->whereUlid('offer')->name('retire');
        });
    });

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

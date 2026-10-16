<?php

use App\Http\Controllers\Creators\CommunityPuzzleController;
use App\Http\Controllers\Creators\CreatorApplicationController;
use App\Http\Controllers\Creators\CreatorDashboardController;
use App\Http\Controllers\Creators\CreatorPuzzleController;
use App\Http\Controllers\Creators\CreatorReviewPreviewController;
use App\Http\Controllers\Creators\PublicCreatorController;
use App\Http\Middleware\EnsureCreatorStudioAccess;
use Illuminate\Support\Facades\Route;

/*
| E28 - منظومة المبدعين وأحجيات المجتمع (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  عام (قراءة فقط، للضيوف): صفحة المبدع، قائمة أحجيات المجتمع، صفحة الأحجية (404 لغير الظاهر).
|  لاعب موثَّق غير مجمَّد: لعب/تلميح/بلاغ أحجية مجتمع (مسار منفصل عن /puzzles الرسمي)، وطلب الانضمام كمبدع.
|  استوديو المبدع (ملف معتمَد + بريد موثَّق + حساب غير مجمَّد): كل مسارات {work} بمعرّف ULID عام يُحلّ باستعلام محصور بالمالك.
|  معاينة المراجع (creators.review): نسخة مرسلة، غير عامة.
| الصفحات الحرفية تسبق الديناميكية (create قبل {work}).
*/

Route::get('/creators/{creator:slug}', [PublicCreatorController::class, 'show'])->name('creators.show');
Route::get('/community/puzzles', [CommunityPuzzleController::class, 'index'])->name('community.puzzles.index');
Route::get('/community/puzzles/{work}', [CommunityPuzzleController::class, 'show'])->whereUlid('work')->name('community.puzzles.show');

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::post('/community/puzzles/{work}/attempt', [CommunityPuzzleController::class, 'attempt'])->whereUlid('work')->middleware('throttle:puzzle-attempt')->name('community.puzzles.attempt');
    Route::post('/community/puzzles/{work}/hint', [CommunityPuzzleController::class, 'hint'])->whereUlid('work')->middleware('throttle:puzzle-hint')->name('community.puzzles.hint');
    Route::post('/community/puzzles/{work}/report', [CommunityPuzzleController::class, 'report'])->whereUlid('work')->middleware('throttle:community-report')->name('community.puzzles.report');

    Route::get('/creator/apply', [CreatorApplicationController::class, 'show'])->name('creator.apply');
    Route::post('/creator/apply', [CreatorApplicationController::class, 'store'])->middleware('throttle:creator-apply')->name('creator.apply.store');

    Route::middleware(EnsureCreatorStudioAccess::class)->prefix('creator')->name('creator.')->group(function () {
        Route::get('/', [CreatorDashboardController::class, 'index'])->name('dashboard');

        Route::get('/puzzles', [CreatorPuzzleController::class, 'index'])->name('puzzles.index');
        Route::get('/puzzles/create', [CreatorPuzzleController::class, 'create'])->name('puzzles.create');
        Route::post('/puzzles', [CreatorPuzzleController::class, 'store'])->middleware('throttle:creator-draft-create')->name('puzzles.store');

        Route::prefix('/puzzles/{work}')->whereUlid('work')->name('puzzles.')->group(function () {
            Route::get('/edit', [CreatorPuzzleController::class, 'edit'])->name('edit');
            Route::put('/', [CreatorPuzzleController::class, 'update'])->name('update');
            Route::delete('/', [CreatorPuzzleController::class, 'destroy'])->name('destroy');
            Route::delete('/image', [CreatorPuzzleController::class, 'destroyImage'])->name('image.destroy');
            Route::get('/preview', [CreatorPuzzleController::class, 'preview'])->name('preview');
            Route::post('/preview', [CreatorPuzzleController::class, 'previewCheck'])->middleware('throttle:puzzle-attempt')->name('preview.check');
            Route::post('/submit', [CreatorPuzzleController::class, 'submit'])->middleware('throttle:creator-submit')->name('submit');
            Route::post('/withdraw', [CreatorPuzzleController::class, 'withdraw'])->middleware('throttle:creator-submit')->name('withdraw');
            Route::post('/revise', [CreatorPuzzleController::class, 'revise'])->middleware('throttle:creator-submit')->name('revise');
            Route::post('/archive', [CreatorPuzzleController::class, 'archive'])->name('archive');
            Route::post('/unarchive', [CreatorPuzzleController::class, 'unarchive'])->name('unarchive');
        });
    });
});

Route::middleware(['auth', 'account.active', 'can:creators.review'])->group(function () {
    Route::get('/community/review/{revision}', [CreatorReviewPreviewController::class, 'show'])->whereNumber('revision')->name('community.review.preview');
    Route::post('/community/review/{revision}/check', [CreatorReviewPreviewController::class, 'check'])->whereNumber('revision')->middleware('throttle:puzzle-attempt')->name('community.review.check');
});

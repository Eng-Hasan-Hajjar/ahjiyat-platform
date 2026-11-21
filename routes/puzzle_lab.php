<?php

use App\Http\Controllers\PuzzleLabController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
| E47 - «مختبر الأحجيات» (تدريب فقط؛ تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل POST).
|
|  كل المسارات خلف الدخول نفسه الذي تتطلبه الأحجيات الرسمية (auth + verified + account.active): المسار الأول للحسابات المسجّلة.
|  لا مسار هنا يكتب في أي جدول: الجولات بلا حالة مخزَّنة (App\Services\PuzzleLab\PuzzleLabRounds). لا يوجد أي استدعاء لخدمات المحافظ/المحاولات/التقدّم.
|  المعاينة الإدارية للمسودات بصلاحية puzzles.view (سياسة PuzzleLabExercisePolicy::preview) وإلا 404 كي لا يُكشف وجودها.
|  الأسماء تتجنب حارس E18 (لا reward|grant|claim|payout|prize). الصفحات الحرفية (preview) تسبق الديناميكية ({exercise}).
*/

RateLimiter::for('puzzle-lab-read', fn (Request $request) => Limit::perMinute(120)->by('plr:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('puzzle-lab-start', fn (Request $request) => Limit::perMinute(60)->by('pls:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('puzzle-lab-flip', fn (Request $request) => Limit::perMinute(240)->by('plf:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('puzzle-lab-check', fn (Request $request) => Limit::perMinute(60)->by('plc:'.($request->user()?->id ?: $request->ip())));

Route::middleware(['auth', 'verified', 'account.active'])
    ->prefix('puzzle-lab')
    ->name('puzzle-lab.')
    ->group(function () {
        Route::get('/', [PuzzleLabController::class, 'index'])->middleware('throttle:puzzle-lab-read')->name('index');
        Route::get('/preview/{exercise:slug}', [PuzzleLabController::class, 'preview'])->middleware('throttle:puzzle-lab-read')->name('preview');
        Route::get('/{exercise:slug}', [PuzzleLabController::class, 'show'])->middleware('throttle:puzzle-lab-read')->name('show');

        Route::post('/{exercise:slug}/rounds', [PuzzleLabController::class, 'start'])->middleware('throttle:puzzle-lab-start')->name('rounds.start');
        Route::post('/{exercise:slug}/rounds/flip', [PuzzleLabController::class, 'flip'])->middleware('throttle:puzzle-lab-flip')->name('rounds.flip');
        Route::post('/{exercise:slug}/rounds/check', [PuzzleLabController::class, 'check'])->middleware('throttle:puzzle-lab-check')->name('rounds.check');
    });

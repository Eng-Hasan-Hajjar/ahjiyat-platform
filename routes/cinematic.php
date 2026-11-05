<?php

use App\Http\Controllers\Cinematic\CinematicMediaController;
use App\Http\Controllers\Cinematic\CinematicMediaUploadController;
use App\Http\Controllers\Cinematic\CinematicPlayController;
use App\Http\Controllers\Cinematic\CinematicPreviewController;
use App\Http\Controllers\Cinematic\CinematicStoryController;
use App\Http\Middleware\EnsureCinematicEnabled;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
| CIN-1 - السلاسل السينمائية التفاعلية (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  العلم CINEMATIC_STORIES_ENABLED مغلق افتراضيًا ⇒ كل المسارات 404 (EnsureCinematicEnabled).
|  القراءة عامة للضيوف (القائمة/صفحة القصة/الغلاف الموقَّع) ولا GET يكتب شيئًا. اللعب والتقدّم والوسائط خلف تسجيل الدخول، وكل إجراء POST بحد معدّل مسمّى.
|  الوسائط: رابط موقَّع + دخول + فحص ملكية/نسخة عند كل طلب (CinematicMediaController). المعاينة الإشرافية بصلاحية cinematic-stories.preview بلا تقدّم محفوظ.
|  رفع المكتبة إداري (cinematic-stories.media) تحت admin/.
|  الأسماء تتجنب حارس E18 (لا reward|grant|claim|payout|prize). الصفحات الحرفية تسبق الديناميكية ({story}).
*/

RateLimiter::for('cinematic-read', fn (Request $request) => Limit::perMinute(120)->by('cnr:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('cinematic-media', fn (Request $request) => Limit::perMinute(240)->by('cnm:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('cinematic-act', fn (Request $request) => Limit::perMinute(90)->by('cna:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('cinematic-submit', fn (Request $request) => Limit::perMinute(40)->by('cns:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('cinematic-upload', fn (Request $request) => Limit::perMinute(12)->by('cnu:'.($request->user()?->id ?: $request->ip())));

Route::middleware(EnsureCinematicEnabled::class)->group(function () {
    Route::post('/admin/cinematic/media', [CinematicMediaUploadController::class, 'store'])
        ->middleware(['auth', 'verified', 'throttle:cinematic-upload'])->name('cinematic.admin.media.store');

    Route::prefix('/cinematic')->name('cinematic.')->group(function () {
        Route::get('/', [CinematicStoryController::class, 'index'])->middleware('throttle:cinematic-read')->name('index');

        Route::get('/media/{asset}', [CinematicMediaController::class, 'show'])->whereUlid('asset')
            ->middleware(['auth', 'signed', 'throttle:cinematic-media'])->name('media');
        Route::get('/cover/{asset}', [CinematicMediaController::class, 'cover'])->whereUlid('asset')
            ->middleware(['signed', 'throttle:cinematic-media'])->name('cover');

        Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
            Route::get('/preview/{revision}', [CinematicPreviewController::class, 'show'])->whereUlid('revision')->middleware('throttle:cinematic-read')->name('preview');
            Route::post('/preview/{revision}/scenes/{scene}/submit', [CinematicPreviewController::class, 'submit'])->whereUlid(['revision', 'scene'])->middleware('throttle:cinematic-submit')->name('preview.submit');
        });

        Route::get('/{story}', [CinematicStoryController::class, 'show'])->where('story', '[A-Za-z0-9_-]{3,110}')->middleware('throttle:cinematic-read')->name('show');

        Route::middleware(['auth', 'verified', 'account.active'])->where(['story' => '[A-Za-z0-9_-]{3,110}'])->group(function () {
            Route::get('/{story}/play', [CinematicStoryController::class, 'play'])->middleware('throttle:cinematic-read')->name('play');
            Route::get('/{story}/state', [CinematicPlayController::class, 'state'])->middleware('throttle:cinematic-read')->name('state');
            Route::post('/{story}/scenes/{scene}/start', [CinematicPlayController::class, 'start'])->whereUlid('scene')->middleware('throttle:cinematic-act')->name('scene.start');
            Route::post('/{story}/scenes/{scene}/finish', [CinematicPlayController::class, 'finish'])->whereUlid('scene')->middleware('throttle:cinematic-act')->name('scene.finish');
            Route::post('/{story}/scenes/{scene}/submit', [CinematicPlayController::class, 'submit'])->whereUlid('scene')->middleware('throttle:cinematic-submit')->name('scene.submit');
            Route::post('/{story}/upgrade', [CinematicPlayController::class, 'upgrade'])->middleware('throttle:cinematic-act')->name('upgrade');
        });
    });
});

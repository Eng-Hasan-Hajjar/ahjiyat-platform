<?php

use App\Http\Controllers\Collections\CollectionManageController;
use App\Http\Controllers\Collections\PublicCollectionController;
use App\Http\Controllers\Engagement\CollectionController;
use App\Http\Controllers\Engagement\CommunityFavoriteController;
use App\Http\Controllers\Engagement\CommunityRatingController;
use App\Http\Controllers\Engagement\CreatorFollowController;
use Illuminate\Support\Facades\Route;

/*
| E29 - التفاعل المجتمعي (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
| كلها خلف auth + verified + account.active (المجمَّد لا يتفاعل: يُرد بخطأ من account.active ثم تحرسه الخدمة مرة ثانية).
| كل تعديل POST/PUT/DELETE فقط - لا GET يغيّر حالة. كل مُعدِّل بمحدّد معدّل مسمّى بمفتاح المستخدم (انظر AppServiceProvider).
| لا مسار عام لأي قائمة (مفضلة/متابَعون/مجموعات): خاصة بصاحبها، تُقرأ بـuser_id الجلسة.
| الصفحات الحرفية (create، saved) تسبق الديناميكية ({collection}).
|
| E35: الظهور العام. **مسارات المالك تحت /collections تبقى كلها خلف auth** (E29 كما هي)؛ القراءة العامة الوحيدة هي GET /curated/{collection} (ULID عام، بلا slug بشري)
| وكل ما يغيّر حالة (حفظ/بلاغ) POST/DELETE خلف auth + verified + account.active. اختير /curated لا /collections/{id} لأن المسار نفسه مأخوذ لمالك المجموعة (auth) ولا نريد
| أن يتحوّل «غير المسجَّل → تحويل لتسجيل الدخول» إلى «404/عرض» على مسارات E29. /collections/saved يسبق {collection} (ويحميه whereUlid أيضًا).
*/

// قراءة عامة (ضيف أو مسجَّل): الصفحة تقرر الظهور بنفسها عبر CollectionAccess وتردّ 404 موحّدًا.
Route::get('/curated/{collection}', [PublicCollectionController::class, 'show'])->whereUlid('collection')->middleware('throttle:collection-read')->name('collections.public.show');

Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::put('/community/puzzles/{work}/rating', [CommunityRatingController::class, 'update'])->whereUlid('work')->middleware('throttle:community-rating')->name('community.puzzles.rating.update');
    Route::delete('/community/puzzles/{work}/rating', [CommunityRatingController::class, 'destroy'])->whereUlid('work')->middleware('throttle:community-rating')->name('community.puzzles.rating.destroy');

    Route::post('/community/puzzles/{work}/favorite', [CommunityFavoriteController::class, 'store'])->whereUlid('work')->middleware('throttle:community-favorite')->name('community.puzzles.favorite.store');
    Route::delete('/community/puzzles/{work}/favorite', [CommunityFavoriteController::class, 'destroy'])->whereUlid('work')->middleware('throttle:community-favorite')->name('community.puzzles.favorite.destroy');
    Route::get('/favorites', [CommunityFavoriteController::class, 'index'])->name('favorites.index');

    Route::post('/creators/{creator:slug}/follow', [CreatorFollowController::class, 'store'])->middleware('throttle:creator-follow')->name('creators.follow');
    Route::delete('/creators/{creator:slug}/follow', [CreatorFollowController::class, 'destroy'])->middleware('throttle:creator-follow')->name('creators.unfollow');
    Route::get('/following', [CreatorFollowController::class, 'feed'])->name('following.index');

    Route::post('/community/puzzles/{work}/collections', [CollectionController::class, 'addWork'])->whereUlid('work')->middleware('throttle:collection-actions')->name('community.puzzles.collections.add');

    Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::get('/collections/create', [CollectionController::class, 'create'])->name('collections.create');
    Route::get('/collections/saved', [PublicCollectionController::class, 'saved'])->name('collections.saved');
    Route::post('/collections', [CollectionController::class, 'store'])->middleware('throttle:collection-actions')->name('collections.store');
    Route::prefix('/collections/{collection}')->whereUlid('collection')->group(function () {
        Route::get('/', [CollectionController::class, 'show'])->name('collections.show');
        Route::get('/edit', [CollectionController::class, 'edit'])->name('collections.edit');
        Route::put('/', [CollectionController::class, 'update'])->middleware('throttle:collection-actions')->name('collections.update');
        Route::delete('/', [CollectionController::class, 'destroy'])->middleware('throttle:collection-actions')->name('collections.destroy');
        Route::delete('/items/{work}', [CollectionController::class, 'removeWork'])->whereUlid('work')->middleware('throttle:collection-actions')->name('collections.items.destroy');
        Route::patch('/visibility', [CollectionManageController::class, 'visibility'])->middleware('throttle:collection-publish')->name('collections.visibility');
        Route::patch('/reorder', [CollectionManageController::class, 'reorder'])->middleware('throttle:collection-reorder')->name('collections.reorder');
    });

    // E35: حفظ/إزالة/بلاغ على مجموعة الغير (عامة أو غير مدرجة وظاهرة للمشاهد فقط وإلا 404).
    Route::prefix('/curated/{collection}')->whereUlid('collection')->group(function () {
        Route::post('/save', [PublicCollectionController::class, 'save'])->middleware('throttle:collection-save')->name('collections.public.save');
        Route::delete('/save', [PublicCollectionController::class, 'unsave'])->middleware('throttle:collection-save')->name('collections.public.unsave');
        Route::post('/report', [PublicCollectionController::class, 'report'])->middleware('throttle:collection-report')->name('collections.public.report');
    });
});

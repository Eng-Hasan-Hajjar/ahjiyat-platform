<?php

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
| الصفحات الحرفية (create) تسبق الديناميكية ({collection}).
*/

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
    Route::post('/collections', [CollectionController::class, 'store'])->middleware('throttle:collection-actions')->name('collections.store');
    Route::prefix('/collections/{collection}')->whereUlid('collection')->group(function () {
        Route::get('/', [CollectionController::class, 'show'])->name('collections.show');
        Route::get('/edit', [CollectionController::class, 'edit'])->name('collections.edit');
        Route::put('/', [CollectionController::class, 'update'])->middleware('throttle:collection-actions')->name('collections.update');
        Route::delete('/', [CollectionController::class, 'destroy'])->middleware('throttle:collection-actions')->name('collections.destroy');
        Route::delete('/items/{work}', [CollectionController::class, 'removeWork'])->whereUlid('work')->middleware('throttle:collection-actions')->name('collections.items.destroy');
    });
});

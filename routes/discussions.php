<?php

use App\Http\Controllers\Discussions\DiscussionController;
use App\Models\DiscussionThread;
use Illuminate\Support\Facades\Route;

/*
| E36 - النقاشات (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل التعديلات).
|
|  قراءة (للضيوف): الرابط الثابت (يحوّل إلى الصفحة العامة الصحيحة) وصفحة ردود جذر. تعليق/كيان غير قابل للعرض لهذا المشاهد = 404 موحّد بلا كشف السبب.
|  كتابة (auth + verified + account.active): POST/PATCH/DELETE فقط، محدّدات معدّل مسمّاة، لا GET يكتب. معرّفات التعليقات ULID (whereUlid)؛ نوع الموضوع من قائمة بيضاء (whereIn).
|  المسارات مسجّلة دائمًا (discussions.permalink مطلوب لسجل الإشعارات)، وتعطيل الميزة (DISCUSSIONS_ENABLED=false) يردّ 404 من المتحكم/رفضًا من الخدمة.
|  لا مسار يحوي «/collections» (E29: كل مسار بهذه الكلمة خلف تسجيل الدخول)؛ النوع يُمرَّر بصيغته الداخلية public_collection.
*/

$types = array_keys(DiscussionThread::TYPES);

Route::middleware('throttle:discussion-read')->group(function () use ($types) {
    Route::get('/discussions/comments/{comment}', [DiscussionController::class, 'permalink'])->whereUlid('comment')->name('discussions.permalink');
    Route::get('/discussions/{type}/{subject}/comments/{root}/replies', [DiscussionController::class, 'replies'])
        ->whereIn('type', $types)->whereUlid('subject')->whereUlid('root')->name('discussions.replies');
});

Route::middleware(['auth', 'verified', 'account.active'])->prefix('discussions')->name('discussions.')->group(function () use ($types) {
    Route::post('/{type}/{subject}/comments', [DiscussionController::class, 'store'])
        ->whereIn('type', $types)->whereUlid('subject')->middleware('throttle:discussion-comment')->name('comments.store');

    Route::prefix('/comments/{comment}')->whereUlid('comment')->group(function () {
        Route::post('/replies', [DiscussionController::class, 'reply'])->middleware('throttle:discussion-reply')->name('replies.store');
        Route::patch('/', [DiscussionController::class, 'update'])->middleware('throttle:discussion-edit')->name('comments.update');
        Route::delete('/', [DiscussionController::class, 'destroy'])->middleware('throttle:discussion-edit')->name('comments.destroy');
        Route::post('/like', [DiscussionController::class, 'like'])->middleware('throttle:discussion-like')->name('likes.store');
        Route::delete('/like', [DiscussionController::class, 'unlike'])->middleware('throttle:discussion-like')->name('likes.destroy');
        Route::post('/report', [DiscussionController::class, 'report'])->middleware('throttle:discussion-report')->name('reports.store');
    });
});

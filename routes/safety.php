<?php

use App\Http\Controllers\Safety\SafetyController;
use Illuminate\Support\Facades\Route;

/*
| E38 - صفحة «الأمان والإشراف» للمستخدم المعني (تُضمَّن من routes/web.php بمجموعة web).
|
|  الوسيط safety.access (لا auth ولا account.active عمدًا): مستخدم مصادَق، أو سياق وصول مقيَّد لحساب مجمَّد دخل بكلمة مرور صحيحة.
|  المجمَّد يصل لهذه المسارات **وحدها** (قراءة إجراءاته + اعتراض + سحب + خروج)؛ لا شيء آخر ينفتح له.
|  كل ما يغيّر حالة POST فقط (لا GET يكتب)، CSRF مفعَّل بمجموعة web، معرّفات الإجراءات/الاعتراضات ULID فقط، والملكية تُفرض في الاستعلام (لا IDOR).
*/

Route::middleware('safety.access')->prefix('account/safety')->name('account.safety.')->group(function () {
    Route::middleware('throttle:safety-read')->group(function () {
        Route::get('/', [SafetyController::class, 'index'])->name('index');
        Route::get('/actions/{action}', [SafetyController::class, 'show'])->whereUlid('action')->name('show');
    });

    Route::middleware('throttle:safety-appeal')->group(function () {
        Route::post('/actions/{action}/appeal', [SafetyController::class, 'appeal'])->whereUlid('action')->name('appeal');
        Route::post('/appeals/{appeal}/withdraw', [SafetyController::class, 'withdraw'])->whereUlid('appeal')->name('withdraw');
    });

    Route::post('/leave', [SafetyController::class, 'leave'])->name('leave');
});

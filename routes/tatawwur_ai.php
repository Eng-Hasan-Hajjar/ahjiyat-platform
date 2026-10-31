<?php

use App\Http\Controllers\Help\HelpCenterController;
use App\Http\Controllers\TatawwurAi\FeedbackController;
use App\Http\Controllers\TatawwurAi\HistoryController;
use App\Http\Controllers\TatawwurAi\MessageController;
use App\Http\Controllers\TatawwurAi\StateController;
use App\Http\Middleware\EnsureTatawwurAiEnabled;
use Illuminate\Support\Facades\Route;

/*
| E45 - «تطوّر AI» (المساعد الذكي لمنصة الأحجيات) و«دليل المنصة» (تُضمَّن من routes/web.php بمجموعة web: جلسة + CSRF لكل مسار يغيّر حالة).
|
|  الحالة والرسائل: خلف EnsureTatawwurAiEnabled (المفتاح الشامل مغلق، أو الضيوف معطَّلون، أو الحساب مجمَّد ⇒ 404 قبل أي عمل). الضيف يصل فقط إن فُعّل الضيوف صراحة.
|  الرسالة POST (تغيّر حالة: تحفظ محادثة وتستهلك ميزانية) بمحدِّد معدّل مسمّى + قفل طلب واحد جارٍ؛ لا شيء منها يمنح أو يغيّر اقتصادًا أو ترتيبًا أو سمعة.
|  حذف السجل: auth + account.active ولا يتوقف على تفعيل الميزة (حق خصوصية: يعمل حتى لو أُوقف المساعد). التقييم للأعضاء الموثَّقين فقط.
|  دليل المنصة /help: صفحات قراءة عامة لمقالات معرفة منشورة يدويًا (تبقى متاحة حتى لو أُوقف المساعد)؛ مقالات «الأعضاء» لا تُعرَض لغير الموثَّق.
|  التسمية: يتجنب حارس مسارات E18 كلمات reward|grant|claim|payout|prize في URI واسم المسار والمحدِّد؛ وحارس E26 يمنع تسمية إجراء GET بـ store/update/destroy/send/read وغيرها.
*/
Route::prefix('tatawwur')->name('tatawwur.')->group(function () {
    Route::middleware(EnsureTatawwurAiEnabled::class)->group(function () {
        Route::get('/state', [StateController::class, 'show'])->middleware('throttle:tatawwur-ai-read')->name('state');
        Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:tatawwur-ai-messages')->name('messages.store');
    });

    Route::middleware(['auth', 'verified', 'account.active', EnsureTatawwurAiEnabled::class, 'throttle:tatawwur-ai-actions'])->group(function () {
        Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    });

    Route::middleware('throttle:tatawwur-ai-actions')->group(function () {
        Route::delete('/history', [HistoryController::class, 'destroy'])->name('history.destroy');
        Route::delete('/history/{conversation}', [HistoryController::class, 'destroyConversation'])->whereUlid('conversation')->middleware(['auth', 'account.active'])->name('history.conversation.destroy');
    });
});

Route::prefix('help')->middleware('throttle:tatawwur-ai-read')->group(function () {
    Route::get('/', [HelpCenterController::class, 'index'])->name('help.index');
    Route::get('/{slug}', [HelpCenterController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9\-]{1,118}')->name('help.show');
});

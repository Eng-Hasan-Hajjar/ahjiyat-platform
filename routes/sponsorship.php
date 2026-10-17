<?php

use App\Http\Controllers\Sponsorship\SupporterController;
use Illuminate\Support\Facades\Route;

/*
| E30 - الداعمون والرعاة (تُضمَّن من routes/web.php بمجموعة web). قراءة عامة فقط (GET) بلا أي كتابة: التسجيل والتأكيد من لوحة الإدارة حصرًا (لا مسار يؤكد أو يمنح).
| الظهور: ملف علني ونشط فقط (غيره 404). حدّ قراءة معتدل. لا تُكشف مبالغ ولا ملاحظات ولا مراجع داخلية بأي مسار.
*/

Route::middleware('throttle:supporters-read')->group(function () {
    Route::get('/supporters', [SupporterController::class, 'index'])->name('supporters.index');
    Route::get('/supporters/{supporter:slug}', [SupporterController::class, 'show'])->name('supporters.show');
});

<?php

use App\Http\Controllers\Community\CommunityRankingsController;
use Illuminate\Support\Facades\Route;

/*
| E37 - ترتيبات الجودة والسمعة المجتمعية (تُضمَّن من routes/web.php بمجموعة web).
|
|  عام (للضيوف)، قراءة فقط: لا POST ولا تعديل درجات ولا إبراز من العميل (الإبراز والدرجات حصرًا من الإدارة/الخدمات).
|  تعطيل الميزة (REPUTATION_ENABLED=false) يردّ 404 من المتحكم. المسار ثابت حرفيًا (لا معاملات) فلا يتعارض مع /community/puzzles/{work}.
*/

Route::middleware('throttle:reputation-read')->group(function () {
    Route::get('/community/rankings', [CommunityRankingsController::class, 'index'])->name('community.rankings');
});

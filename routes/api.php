<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\PuzzleController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

// هذا الملف مخصص لتطبيق الموبايل المستقبلي (Kotlin - Android)
// نفس منطق الأعمال بالضبط عبر خدمات app/Services، فقط طبقة نقل مختلفة (JSON بدل Blade).
// E26: إصدار الرمز بحدّ مزدوج (IP + بريد مُجزَّأ/IP)، ولا يصدر لحساب مجمَّد أو لبريد غير موثَّق (نفس شروط اللعب بالويب).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

// E26: كل ما سوى الخروج يتطلب بريدًا موثَّقًا وحسابًا غير مجمَّد (كان رمز مجمَّد/غير موثَّق يكسب جواهر عبر /api/puzzles/{id}/attempt).
Route::middleware(['auth:sanctum', 'verified', 'account.active'])->group(function () {

    Route::get('/puzzles/categories', [PuzzleController::class, 'categories']);
    Route::get('/puzzles', [PuzzleController::class, 'index']);
    Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show']);
    Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
        ->middleware('throttle:20,1');

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);

    Route::get('/progress', [ProgressController::class, 'show']);
    Route::get('/engagement', [EngagementController::class, 'show']);
});
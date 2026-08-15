<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PuzzleController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

// هذا الملف مخصص لتطبيق الموبايل المستقبلي (Kotlin - Android)
// نفس منطق الأعمال بالضبط عبر خدمات app/Services، فقط طبقة نقل مختلفة (JSON بدل Blade).
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/puzzles/categories', [PuzzleController::class, 'categories']);
    Route::get('/puzzles', [PuzzleController::class, 'index']);
    Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show']);
    Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
        ->middleware('throttle:20,1');

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
});

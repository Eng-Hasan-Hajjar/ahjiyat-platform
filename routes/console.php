<?php

use App\Models\GemTransaction;
use App\Models\User;
use App\Services\GemWalletService;
use Illuminate\Support\Facades\Schedule;

// تحويل الجواهر المعلقة إلى متاحة يومياً بعد انتهاء فترة التعليق (config('gems.pending_hold_days'))
Schedule::call(function () {
    $walletService = app(GemWalletService::class);
    $holdDays = config('gems.pending_hold_days');

    User::whereHas('wallet', fn ($q) => $q->where('pending_balance', '>', 0))
        ->with('wallet')
        ->chunk(200, function ($users) use ($walletService, $holdDays) {
            foreach ($users as $user) {
                $releasable = GemTransaction::where('user_id', $user->id)
                    ->where('type', GemTransaction::TYPE_EARN_PENDING)
                    ->where('created_at', '<=', now()->subDays($holdDays))
                    ->sum('amount');

                $alreadyReleased = GemTransaction::where('user_id', $user->id)
                    ->where('type', GemTransaction::TYPE_RELEASE_AVAILABLE)
                    ->sum('amount');

                $toRelease = $releasable - $alreadyReleased;

                if ($toRelease > 0) {
                    $walletService->releasePendingToAvailable($user, (int) $toRelease, 'pending_hold_expired');
                }
            }
        });
})->daily();

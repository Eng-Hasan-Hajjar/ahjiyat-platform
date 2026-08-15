<?php

namespace App\Services;

use App\Models\RedemptionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RedemptionService
{
    public function __construct(protected GemWalletService $wallet) {}

    public function checkEligibility(User $user): array
    {
        $wallet = $user->wallet;
        $eligibility = config('gems.eligibility');

        $reasons = [];

        if (! $user->hasVerifiedEmail()) {
            $reasons[] = 'يجب توثيق البريد الإلكتروني أولاً.';
        }

        if ($user->created_at->diffInDays(now()) < $eligibility['min_account_age_days']) {
            $reasons[] = 'يجب مرور فترة معينة على إنشاء الحساب.';
        }

        $solvedCount = $user->puzzleAttempts()->where('is_correct', true)->count();
        if ($solvedCount < $eligibility['min_puzzles_solved']) {
            $reasons[] = 'يجب حل عدد أكبر من الأحجيات أولاً.';
        }

        if ($user->fraudFlags()->where('resolved', false)->exists()) {
            $reasons[] = 'الحساب عليه مراجعة أمنية قائمة حالياً.';
        }

        if (! $wallet || $wallet->available_balance < config('gems.min_redemption')) {
            $reasons[] = 'الرصيد المتاح أقل من الحد الأدنى للاستبدال.';
        }

        return ['eligible' => empty($reasons), 'reasons' => $reasons];
    }

    public function requestRedemption(User $user, int $gemsAmount, string $rewardDescription): RedemptionRequest
    {
        return DB::transaction(function () use ($user, $gemsAmount, $rewardDescription) {
            $eligibility = $this->checkEligibility($user);

            if (! $eligibility['eligible']) {
                throw new \RuntimeException(implode(' ', $eligibility['reasons']));
            }

            $request = RedemptionRequest::create([
                'user_id' => $user->id,
                'gems_amount' => $gemsAmount,
                'reward_description' => $rewardDescription,
                'status' => RedemptionRequest::STATUS_PENDING,
            ]);

            // نحجز الجواهر فوراً (تُخصم) لمنع طلب استبدال مضاعف لنفس الرصيد،
            // ونرجعها تلقائياً لو الطلب انرفض أو انلغى (انظر reject/cancel تحت).
            $this->wallet->debitAvailable($user, $gemsAmount, "redemption_request:{$request->id}", $request);

            return $request;
        });
    }

    public function approve(RedemptionRequest $request, User $admin, ?string $note = null): void
    {
        $request->update([
            'status' => RedemptionRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);
    }

    public function markFulfilled(RedemptionRequest $request, User $admin, ?string $note = null): void
    {
        $request->update([
            'status' => RedemptionRequest::STATUS_FULFILLED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note ?? $request->admin_note,
        ]);

        $request->user->wallet->increment('lifetime_redeemed', $request->gems_amount);
    }

    public function reject(RedemptionRequest $request, User $admin, string $note): void
    {
        DB::transaction(function () use ($request, $admin, $note) {
            $request->update([
                'status' => RedemptionRequest::STATUS_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'admin_note' => $note,
            ]);

            $this->wallet->refundAvailable(
                $request->user,
                $request->gems_amount,
                "redemption_rejected:{$request->id}",
                $request
            );
        });
    }
}

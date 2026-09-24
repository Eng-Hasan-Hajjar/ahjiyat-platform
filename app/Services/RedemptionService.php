<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Facades\DB;

class RedemptionService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected CurrencyRegistry $currencies,
    ) {}

    public function checkEligibility(User $user, ?Currency $currency = null): array
    {
        $currency ??= $this->currencies->defaultEarnedCurrency();
        $wallet = $this->wallets->balanceFor($user, $currency);
        $eligibility = config('gems.eligibility');

        $reasons = [];

        if (! $currency->is_redeemable) {
            $reasons[] = 'هذه العملة غير قابلة للاستبدال.';
        }

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

        if ($wallet->available_balance < config('gems.min_redemption')) {
            $reasons[] = 'الرصيد المتاح أقل من الحد الأدنى للاستبدال.';
        }

        return ['eligible' => empty($reasons), 'reasons' => $reasons];
    }

    public function requestRedemption(User $user, int $amount, string $rewardDescription, ?Currency $currency = null): RedemptionRequest
    {
        $currency ??= $this->currencies->defaultEarnedCurrency();

        return DB::transaction(function () use ($user, $amount, $rewardDescription, $currency) {
            $eligibility = $this->checkEligibility($user, $currency);

            if (! $eligibility['eligible']) {
                throw new \RuntimeException(implode(' ', $eligibility['reasons']));
            }

            $request = RedemptionRequest::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'gems_amount' => $amount,
                'reward_description' => $rewardDescription,
                'status' => RedemptionRequest::STATUS_PENDING,
            ]);

            $this->wallets->debitAvailable($user, $currency, $amount, "redemption_request:{$request->id}", $request);

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

        $currency = $request->currency ?? $this->currencies->defaultEarnedCurrency();
        $this->wallets->balanceFor($request->user, $currency)->increment('lifetime_redeemed', $request->gems_amount);
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

            $currency = $request->currency ?? $this->currencies->defaultEarnedCurrency();

            $this->wallets->refund(
                $request->user,
                $currency,
                $request->gems_amount,
                "redemption_rejected:{$request->id}",
                $request
            );
        });
    }
}
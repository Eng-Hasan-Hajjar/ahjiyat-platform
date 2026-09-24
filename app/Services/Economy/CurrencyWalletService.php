<?php

namespace App\Services\Economy;

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CurrencyWalletService
{
    public function creditPending(
        User $user,
        Currency $currency,
        int $amount,
        string $reason,
        ?Model $reference = null,
        ?string $idempotencyKey = null,
    ): CurrencyTransaction {
        $this->assertPositiveAmount($amount);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey))) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $idempotencyKey) {
            $wallet = $this->lockedWallet($user, $currency);

            $wallet->increment('pending_balance', $amount);
            $wallet->increment('lifetime_earned', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => CurrencyTransaction::TYPE_EARN_PENDING,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function releasePending(User $user, Currency $currency, int $amount, string $reason): CurrencyTransaction
    {
        return DB::transaction(function () use ($user, $currency, $amount, $reason) {
            $wallet = $this->lockedWallet($user, $currency);

            $amount = min($amount, $wallet->pending_balance);

            $wallet->decrement('pending_balance', $amount);
            $wallet->increment('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => CurrencyTransaction::TYPE_RELEASE_AVAILABLE,
                'reason' => $reason,
            ]);
        });
    }

    public function creditAvailable(
        User $user,
        Currency $currency,
        int $amount,
        string $reason,
        ?Model $reference = null,
        string $type = CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT,
        ?string $idempotencyKey = null,
    ): CurrencyTransaction {
        $this->assertPositiveAmount($amount);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey))) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $type, $idempotencyKey) {
            $wallet = $this->lockedWallet($user, $currency);
            $wallet->increment('available_balance', $amount);
            $wallet->increment('lifetime_earned', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function debitAvailable(
        User $user,
        Currency $currency,
        int $amount,
        string $reason,
        ?Model $reference = null,
        string $type = CurrencyTransaction::TYPE_REDEEM,
    ): CurrencyTransaction {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $type) {
            $wallet = $this->lockedWallet($user, $currency);

            if ($wallet->available_balance < $amount) {
                throw new \RuntimeException('الرصيد المتاح غير كافٍ لإتمام هذه العملية.');
            }

            $wallet->decrement('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => -$amount,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    public function refund(User $user, Currency $currency, int $amount, string $reason, ?Model $reference = null): CurrencyTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference) {
            $wallet = $this->lockedWallet($user, $currency);
            $wallet->increment('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    public function adjust(User $user, Currency $currency, int $amount, string $reason): CurrencyTransaction
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException('قيمة التعديل يجب ألا تساوي صفراً.');
        }

        return DB::transaction(function () use ($user, $currency, $amount, $reason) {
            $wallet = $this->lockedWallet($user, $currency);

            if ($amount < 0 && $wallet->available_balance + $amount < 0) {
                throw new \RuntimeException('لا يمكن إتمام هذا الخصم - سيجعل الرصيد المتاح سالباً.');
            }

            $wallet->increment('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT,
                'reason' => $reason,
            ]);
        });
    }

    public function dailyEarnedToday(User $user, Currency $currency): int
    {
        return (int) CurrencyTransaction::where('user_id', $user->id)
            ->where('currency_id', $currency->id)
            ->where('type', CurrencyTransaction::TYPE_EARN_PENDING)
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    public function balanceFor(User $user, Currency $currency): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['user_id' => $user->id, 'currency_id' => $currency->id],
            ['pending_balance' => 0, 'available_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0],
        );
    }

    protected function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('قيمة العملية يجب أن تكون أكبر من صفر.');
        }
    }

    protected function findByIdempotencyKey(string $key): ?CurrencyTransaction
    {
        return CurrencyTransaction::where('idempotency_key', $key)->first();
    }

    protected function lockedWallet(User $user, Currency $currency): Wallet
    {
        return Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency_id', $currency->id)
            ->lockForUpdate()
            ->firstOrCreate([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
            ]);
    }
}
<?php

namespace App\Services;

use App\Models\GemTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated منذ E9 - طبقة توافق فقط فوق CurrencyWalletService بعملة
 * "الجواهر" الافتراضية (platform-earned) حصراً.
 */
class GemWalletService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected CurrencyRegistry $currencies,
    ) {}

    public function credit(User $user, int $amount, string $reason, ?Model $reference = null): GemTransaction
    {
        $transaction = $this->wallets->creditPending($user, $this->currencies->defaultEarnedCurrency(), $amount, $reason, $reference);

        return GemTransaction::find($transaction->id);
    }

    public function releasePendingToAvailable(User $user, int $amount, string $reason): GemTransaction
    {
        $transaction = $this->wallets->releasePending($user, $this->currencies->defaultEarnedCurrency(), $amount, $reason);

        return GemTransaction::find($transaction->id);
    }

    public function debitAvailable(User $user, int $amount, string $reason, ?Model $reference = null): GemTransaction
    {
        $transaction = $this->wallets->debitAvailable($user, $this->currencies->defaultEarnedCurrency(), $amount, $reason, $reference);

        return GemTransaction::find($transaction->id);
    }

    public function refundAvailable(User $user, int $amount, string $reason, ?Model $reference = null): GemTransaction
    {
        $transaction = $this->wallets->refund($user, $this->currencies->defaultEarnedCurrency(), $amount, $reason, $reference);

        return GemTransaction::find($transaction->id);
    }

    public function adjustAvailable(User $user, int $amount, string $reason): GemTransaction
    {
        $transaction = $this->wallets->adjust($user, $this->currencies->defaultEarnedCurrency(), $amount, $reason);

        return GemTransaction::find($transaction->id);
    }

    public function dailyEarnedToday(User $user): int
    {
        return $this->wallets->dailyEarnedToday($user, $this->currencies->defaultEarnedCurrency());
    }

    protected function lockedWallet(User $user): Wallet
    {
        return $this->wallets->balanceFor($user, $this->currencies->defaultEarnedCurrency());
    }
}
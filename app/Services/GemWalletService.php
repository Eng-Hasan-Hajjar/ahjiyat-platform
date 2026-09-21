<?php

namespace App\Services;

use App\Models\GemTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GemWalletService
{
    public function credit(User $user, int $amount, string $reason, ?Model $reference = null): GemTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            $wallet = $this->lockedWallet($user);

            $wallet->increment('pending_balance', $amount);
            $wallet->increment('lifetime_earned', $amount);

            return GemTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => GemTransaction::TYPE_EARN_PENDING,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    public function releasePendingToAvailable(User $user, int $amount, string $reason): GemTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason) {
            $wallet = $this->lockedWallet($user);

            $amount = min($amount, $wallet->pending_balance);

            $wallet->decrement('pending_balance', $amount);
            $wallet->increment('available_balance', $amount);

            return GemTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => GemTransaction::TYPE_RELEASE_AVAILABLE,
                'reason' => $reason,
            ]);
        });
    }

    public function debitAvailable(User $user, int $amount, string $reason, ?Model $reference = null): GemTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            $wallet = $this->lockedWallet($user);

            if ($wallet->available_balance < $amount) {
                throw new \RuntimeException('الرصيد المتاح غير كافٍ لإتمام هذه العملية.');
            }

            $wallet->decrement('available_balance', $amount);

            return GemTransaction::create([
                'user_id' => $user->id,
                'amount' => -$amount,
                'type' => GemTransaction::TYPE_REDEEM,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    public function refundAvailable(User $user, int $amount, string $reason, ?Model $reference = null): GemTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            $wallet = $this->lockedWallet($user);
            $wallet->increment('available_balance', $amount);

            return GemTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => GemTransaction::TYPE_ADMIN_ADJUSTMENT,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /**
     * تعديل يدوي من الإدارة على الرصيد المتاح - E6. amount موجب (إضافة) أو
     * سالب (خصم)؛ يمنع نزول الرصيد تحت الصفر. يُستخدم فقط عبر
     * wallet.adjust (صلاحية منفصلة وقوية). التدقيق مسؤولية المستدعي.
     */
    public function adjustAvailable(User $user, int $amount, string $reason): GemTransaction
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException('قيمة التعديل يجب ألا تساوي صفراً.');
        }

        return DB::transaction(function () use ($user, $amount, $reason) {
            $wallet = $this->lockedWallet($user);

            if ($amount < 0 && $wallet->available_balance + $amount < 0) {
                throw new \RuntimeException('لا يمكن إتمام هذا الخصم - سيجعل الرصيد المتاح سالباً.');
            }

            $wallet->increment('available_balance', $amount);

            return GemTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => GemTransaction::TYPE_ADMIN_ADJUSTMENT,
                'reason' => $reason,
            ]);
        });
    }

    public function dailyEarnedToday(User $user): int
    {
        return (int) GemTransaction::where('user_id', $user->id)
            ->where('type', GemTransaction::TYPE_EARN_PENDING)
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    protected function lockedWallet(User $user): Wallet
    {
        return Wallet::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrCreate(['user_id' => $user->id]);
    }
}
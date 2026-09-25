<?php

namespace App\Services\Economy;

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * E9.1: خط الدفاع المركزي الوحيد لسلامة الاقتصاد - canEarn()/canSpend()
 * يُفرَضان هنا فقط، لا يُعتمَد على الطبقات الأعلى (PuzzleAttemptService...)
 * للتحقق. adjust()/refund() تبقيان Business Methods منفصلتين عمداً بلا هذا
 * الفحص - الإدارة يجب أن تقدر تُصحِّح رصيد عملة معطَّلة تشغيليًا.
 */
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
        $this->assertCanEarn($currency);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey))) {
            $this->assertIdempotencyConsistent($existing, $user, $currency, $amount, CurrencyTransaction::TYPE_EARN_PENDING);

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

    /**
     * E9.1 (بند 3): amount > 0 دائمًا (أُلزِمَ صراحة الآن). لا معاملة صفرية
     * إن لم يبقَ شيء معلَّق فعليًا - null بدل سجل وهمي بقيمة 0.
     */
    public function releasePending(User $user, Currency $currency, int $amount, string $reason): ?CurrencyTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $currency, $amount, $reason) {
            $wallet = $this->lockedWallet($user, $currency);

            $effectiveAmount = min($amount, $wallet->pending_balance);

            if ($effectiveAmount <= 0) {
                return null;
            }

            $wallet->decrement('pending_balance', $effectiveAmount);
            $wallet->increment('available_balance', $effectiveAmount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $effectiveAmount,
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
            $this->assertIdempotencyConsistent($existing, $user, $currency, $amount, $type);

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
        $this->assertCanSpend($currency);

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

    /**
     * E9.1 (بند 1): عمداً بلا فحص canSpend/canEarn - إرجاع رصيد محجوز مسبقًا
     * (رفض طلب استبدال مثلاً) يجب أن ينجح دائمًا بغضّ النظر عن حالة العملة
     * الحالية، وإلا يُحتجَز رصيد المستخدم بلا رجعة إن عُطِّلت العملة بينهما.
     */
    public function refund(User $user, Currency $currency, int $amount, string $reason, ?Model $reference = null, string $type = CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT): CurrencyTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $currency, $amount, $reason, $reference, $type) {
            $wallet = $this->lockedWallet($user, $currency);
            $wallet->increment('available_balance', $amount);

            return CurrencyTransaction::create([
                'user_id' => $user->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /**
     * E9.1 (بند 1): تعديل الإدارة اليدوي - Business Method منفصلة تمامًا،
     * عمداً بلا فحص canEarn()/canSpend(). القرار موثَّق هنا صراحة: الإدارة
     * يجب أن تقدر تُصحِّح رصيد عملة معطَّلة/منتهية تشغيليًا (تعويض خطأ قبل
     * إعادة تفعيلها مثلاً) - هذا ليس Bypass ضمني بل تصميم مقصود.
     */
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

    /** E9.1 (بند 1): خط الدفاع المركزي - لا كسب لعملة غير نشطة/غير قابلة للكسب/خارج نافذتها الزمنية/منتهية. */
    protected function assertCanEarn(Currency $currency): void
    {
        if (! $currency->canEarn()) {
            throw new \RuntimeException("لا يمكن كسب عملة \"{$currency->name}\" حاليًا (غير نشطة، أو غير قابلة للكسب، أو خارج نافذتها الزمنية، أو منتهية).");
        }
    }

    /** E9.1 (بند 1): خط الدفاع المركزي - لا صرف من عملة غير نشطة/غير قابلة للصرف/منتهية. */
    protected function assertCanSpend(Currency $currency): void
    {
        if (! $currency->canSpend()) {
            throw new \RuntimeException("لا يمكن صرف عملة \"{$currency->name}\" حاليًا (غير نشطة، أو غير قابلة للصرف، أو منتهية).");
        }
    }

    protected function findByIdempotencyKey(string $key): ?CurrencyTransaction
    {
        return CurrencyTransaction::where('idempotency_key', $key)->first();
    }

    /**
     * E9.1 (بند 5): لا نكتفي بإرجاع أي معاملة تحمل نفس المفتاح - نتحقق أنها
     * فعلاً نفس العملية (نفس مستخدم/عملة/قيمة مطلقة/نوع). إن اختلفت: هذا
     * تعارض حقيقي على مفتاح استُخدم لعمليتين مختلفتين تمامًا - Exception،
     * لا إرجاع صامت لنتيجة خاطئة.
     */
    protected function assertIdempotencyConsistent(CurrencyTransaction $existing, User $user, Currency $currency, int $amount, string $type): void
    {
        $matches = $existing->user_id === $user->id
            && $existing->currency_id === $currency->id
            && abs($existing->amount) === abs($amount)
            && $existing->type === $type;

        if (! $matches) {
            throw new \RuntimeException('مفتاح Idempotency هذا مُستخدَم مسبقًا لعملية مختلفة تمامًا - تعارض حقيقي.');
        }
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
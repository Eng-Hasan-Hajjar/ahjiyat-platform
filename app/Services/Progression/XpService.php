<?php

namespace App\Services\Progression;

use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\XpTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class XpService
{
    public function __construct(protected LevelService $levels) {}

    public function grantXp(
        User $user,
        int $amount,
        string $type,
        string $reason,
        ?Model $source = null,
        ?string $idempotencyKey = null,
    ): ?XpTransaction {
        $this->assertPositiveAmount($amount);

        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($idempotencyKey))) {
            $this->assertIdempotencyConsistent($existing, $user, $amount, $type);

            return $existing;
        }

        return DB::transaction(function () use ($user, $amount, $type, $reason, $source, $idempotencyKey) {
            $progression = $this->lockedProgression($user);

            $transaction = XpTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => $type,
                'reason' => $reason,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $progression->increment('total_xp', $amount);
            $progression->update(['last_xp_at' => now()]);

            $this->levels->recalculateFor($user, $progression->fresh());

            return $transaction;
        });
    }

    protected function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('قيمة XP يجب أن تكون أكبر من صفر.');
        }
    }

    protected function findByIdempotencyKey(string $key): ?XpTransaction
    {
        return XpTransaction::where('idempotency_key', $key)->first();
    }

    protected function assertIdempotencyConsistent(XpTransaction $existing, User $user, int $amount, string $type): void
    {
        $matches = $existing->user_id === $user->id
            && $existing->amount === $amount
            && $existing->type === $type;

        if (! $matches) {
            throw new \RuntimeException('مفتاح Idempotency هذا مُستخدَم مسبقًا لعملية XP مختلفة تمامًا - تعارض حقيقي.');
        }
    }

    protected function lockedProgression(User $user): PlayerProgression
    {
        return PlayerProgression::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrCreate(['user_id' => $user->id]);
    }
}
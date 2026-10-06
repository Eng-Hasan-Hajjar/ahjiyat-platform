<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * تحدٍّ بين صديقين على أحجية واحدة (E17-A). الكتابة عبر FriendChallengeService فقط. active_pair_key مضبوط ما دام pending/accepted وNULL بعدها
 * (UNIQUE: تحدٍّ نشط واحد لكل زوج). لا Mass Assignment خارج الحقول التي يضبطها الـDomain.
 */
class FriendChallenge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED];

    protected $fillable = ['challenger_id', 'opponent_id', 'puzzle_id', 'status', 'active_pair_key', 'expires_at', 'accepted_at', 'completed_at', 'winner_user_id', 'is_draw'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'completed_at' => 'datetime', 'is_draw' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (FriendChallenge $c) {
            $c->public_id ??= (string) Str::ulid();

            if ($c->challenger_id === $c->opponent_id) {
                throw new \InvalidArgumentException('لا تحدّي مع النفس.');
            }
        });
    }

    public function challenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'challenger_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opponent_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(FriendChallengeResult::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function involves(User $user): bool
    {
        return $user->getKey() === $this->challenger_id || $user->getKey() === $this->opponent_id;
    }

    public function otherSide(User $user): User
    {
        return $user->getKey() === $this->challenger_id ? $this->opponent : $this->challenger;
    }

    /** منتهٍ بالوقت (مشتق: لا نعتمد على الحالة المخزَّنة وحدها). */
    public function isExpired(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true) && $this->expires_at->lessThanOrEqualTo(now());
    }

    /** الحالة الفعلية للعرض/القرار: expired مشتقة من الوقت حتى لو لم تُجسَّد بعد. */
    public function effectiveStatus(): string
    {
        return $this->isExpired() ? self::STATUS_EXPIRED : $this->status;
    }

    public function scopeActiveBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->where('active_pair_key', self::pairKey($a, $b));
    }

    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('challenger_id', $userId)->orWhere('opponent_id', $userId));
    }

    public static function pairKey(int $a, int $b): string
    {
        return Friendship::pairKey($a, $b);
    }

    /** يُلغي أي تحدٍّ نشط بين الطرفين (حظر/إزالة صداقة). يُستدعى داخل معاملة المستدعي. @return int عدد ما أُلغي */
    public static function cancelActiveBetween(int $a, int $b): int
    {
        return static::query()->activeBetween($a, $b)->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['status' => self::STATUS_CANCELLED, 'active_pair_key' => null]);
    }
}

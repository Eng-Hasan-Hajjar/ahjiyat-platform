<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * علاقة صداقة واحدة لكل زوج. pair_key (min:max) يُحسَب دائمًا من الطرفين ويفرض UNIQUE على الزوج بأي اتجاه.
 * لا Mass Assignment خارج الحقول الأربعة؛ الخدمة (FriendshipService) هي المسار الوحيد للكتابة.
 */
class Friendship extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = ['requester_id', 'addressee_id', 'status', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Friendship $f) {
            if ($f->requester_id === $f->addressee_id) {
                throw new \InvalidArgumentException('لا صداقة مع النفس.');
            }

            $f->pair_key = self::pairKey((int) $f->requester_id, (int) $f->addressee_id);
        });
    }

    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    public function scopeForPair(Builder $query, int $a, int $b): Builder
    {
        return $query->where('pair_key', self::pairKey($a, $b));
    }

    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('requester_id', $userId)->orWhere('addressee_id', $userId));
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** دعوة فريق (E19-B). pending_key UNIQUE يمنع دعوتين معلّقتين لنفس (فريق، مستخدم). الكتابة عبر TeamInvitationService فقط؛ المعرّف العام ULID. */
class TeamInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['team_id', 'invited_user_id', 'invited_by', 'status', 'pending_key', 'expires_at', 'responded_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (TeamInvitation $i) => $i->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->lessThanOrEqualTo(now());
    }

    public static function pendingKey(int $teamId, int $userId): string
    {
        return "{$teamId}:{$userId}";
    }
}

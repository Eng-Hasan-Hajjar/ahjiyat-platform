<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** طلب انضمام (E19-B). pending_key UNIQUE: طلب معلّق واحد لكل (فريق، مستخدم). الكتابة عبر TeamJoinRequestService فقط؛ المعرّف العام ULID. */
class TeamJoinRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['team_id', 'user_id', 'status', 'pending_key', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (TeamJoinRequest $r) => $r->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function pendingKey(int $teamId, int $userId): string
    {
        return "{$teamId}:{$userId}";
    }
}

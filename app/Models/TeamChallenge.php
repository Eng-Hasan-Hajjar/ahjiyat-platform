<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * تحدّي فريق ضد فريق (E20-A). الحالة والفائز والتعادل والمواعيد **لا تُملأ بالتعيين الجماعي** (ليست fillable): يكتبها TeamChallengeService/TeamChallengeFinalizer فقط
 * (الخادم يشتقها). المعرّف العام ULID. active_key (UNIQUE) يمنع تحدّيين نشطين لنفس الفريقين والأحجية بأي اتجاه.
 */
class TeamChallenge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED];

    protected $fillable = ['challenger_team_id', 'opponent_team_id', 'created_by_user_id', 'puzzle_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'play_ends_at' => 'datetime', 'completed_at' => 'datetime', 'is_draw' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (TeamChallenge $c) {
            $c->public_id ??= (string) Str::ulid();

            if ($c->challenger_team_id === $c->opponent_team_id) {
                throw new \InvalidArgumentException('لا يتحدّى فريق نفسه.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** مفتاح التحدّي النشط: غير موجّه (A→B = B→A) ويشمل الأحجية. */
    public static function activeKey(int $teamA, int $teamB, int $puzzleId): string
    {
        return min($teamA, $teamB).':'.max($teamA, $teamB).':'.$puzzleId;
    }

    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'challenger_team_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'opponent_team_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TeamChallengeParticipant::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(TeamChallengeResult::class);
    }

    public function scopeInvolvingTeam(Builder $query, int $teamId): Builder
    {
        return $query->where(fn ($q) => $q->where('challenger_team_id', $teamId)->orWhere('opponent_team_id', $teamId));
    }

    /** الحالة الفعلية للعرض: معلّق/جارٍ تجاوز مهلته يظهر منتهيًا قبل أن يُجسّده الأمر الدوري. */
    public function effectiveStatus(): string
    {
        return match (true) {
            $this->status === self::STATUS_PENDING && $this->expires_at->lessThanOrEqualTo(now()) => self::STATUS_EXPIRED,
            default => $this->status,
        };
    }

    public function isPlayable(): bool
    {
        return $this->status === self::STATUS_ACCEPTED && $this->play_ends_at !== null && $this->play_ends_at->greaterThan(now());
    }

    public function otherTeamId(int $teamId): int
    {
        return $teamId === $this->challenger_team_id ? $this->opponent_team_id : $this->challenger_team_id;
    }
}

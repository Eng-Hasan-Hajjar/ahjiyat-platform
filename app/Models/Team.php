<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فريق (E19). ليس محفظة ولا مصدر عملة/XP/مكافأة ولا أفضلية: لا حقل لذلك. slug ثابت بعد الإنشاء (روابط عامة). members_count عدّاد ذري تكتبه الخدمات فقط
 * (ليس fillable). الكتابة عبر TeamService/TeamMembershipService.
 */
class Team extends Model
{
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    public const JOIN_OPEN = 'open';

    public const JOIN_REQUEST = 'request';

    public const JOIN_INVITE_ONLY = 'invite_only';

    protected $fillable = ['name', 'name_key', 'slug', 'description', 'visibility', 'join_policy', 'owner_id', 'max_members', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'max_members' => 'integer', 'members_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Team $team) {
            if ($team->isDirty('slug')) {
                throw new \InvalidArgumentException('معرّف الفريق ثابت بعد الإنشاء (روابطه عامة).');
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(TeamJoinRequest::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** السعة الفعلية: max_members (إن وُجد) محدودًا بالسقف المطلق. */
    public function capacity(): int
    {
        $cap = (int) config('teams.max_members_cap', 50);

        return $this->max_members === null ? $cap : min($this->max_members, $cap);
    }

    public function isFull(): bool
    {
        return $this->members_count >= $this->capacity();
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    /** الفِرق الظاهرة بالدليل العام: مفعَّلة وعامة. */
    public function scopeDirectory(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('visibility', self::VISIBILITY_PUBLIC);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عضوية (E19-A). حارس الثوابت: المالك لا يُزال ولا يُخفَّض ولا يُرقَّى أحد إلى مالك إلا ضمن نقل الملكية الذري (allowOwnerChange). owner_team_id يُشتق من الدور
 * (يملأه الحارس) فيفرض UNIQUE مالكًا واحدًا لكل فريق. الأدوار تعداد مغلق owner|admin|member بلا محرك صلاحيات ديناميكي.
 */
class TeamMembership extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    /** تُفعَّل داخل نقل الملكية/معالجة حذف المالك فقط. */
    protected static bool $allowOwnerChange = false;

    protected $fillable = ['team_id', 'user_id', 'role', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (TeamMembership $m) {
            if (! in_array($m->role, self::ROLES, true)) {
                throw new \InvalidArgumentException('دور عضوية غير صالح.');
            }

            $m->owner_team_id = $m->role === self::ROLE_OWNER ? $m->team_id : null;
        });

        static::updating(function (TeamMembership $m) {
            if ($m->isDirty('role') && ! self::$allowOwnerChange
                && ($m->getOriginal('role') === self::ROLE_OWNER || $m->role === self::ROLE_OWNER)) {
                throw new \InvalidArgumentException('دور المالك لا يتغير إلا بنقل الملكية.');
            }
        });

        static::deleting(function (TeamMembership $m) {
            if ($m->role === self::ROLE_OWNER && ! self::$allowOwnerChange) {
                throw new \InvalidArgumentException('لا يُزال المالك: انقل الملكية أولًا أو عطّل الفريق.');
            }
        });
    }

    /** ينفّذ $callback وحارس المالك مرفوع (نقل الملكية/معالجة حذف المالك فقط). */
    public static function allowingOwnerChange(\Closure $callback): mixed
    {
        self::$allowOwnerChange = true;

        try {
            return $callback();
        } finally {
            self::$allowOwnerChange = false;
        }
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isManager(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }
}

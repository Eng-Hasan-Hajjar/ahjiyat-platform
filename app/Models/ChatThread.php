<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * غرفة دردشة (E21): ثلاثة أنواع مغلقة فقط. الهوية والنوع والفريق وطرفا الدردشة المباشرة **غير قابلة للتغيير** بعد الإنشاء (حارس النموذج)،
 * ولا شيء منها قابل للتعيين الجماعي (guarded=*): تكتبها ChatThreadService وحدها. الثنائي قانوني (الأصغر = one) فلا غرفتان لنفس الطرفين.
 */
class ChatThread extends Model
{
    public const TYPE_DIRECT = 'direct';

    public const TYPE_TEAM = 'team';

    public const TYPE_GLOBAL = 'global';

    public const TYPES = [self::TYPE_DIRECT, self::TYPE_TEAM, self::TYPE_GLOBAL];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ChatThread $t) {
            if (! in_array($t->type, self::TYPES, true)) {
                throw new \InvalidArgumentException('نوع غرفة غير معروف.');
            }

            $t->public_id ??= (string) Str::ulid();
            $direct = $t->direct_user_one_id !== null || $t->direct_user_two_id !== null || $t->direct_key !== null;

            match ($t->type) {
                self::TYPE_DIRECT => ($t->direct_user_one_id === null || $t->direct_user_two_id === null || (int) $t->direct_user_one_id >= (int) $t->direct_user_two_id
                    || $t->direct_key !== self::directKey($t->direct_user_one_id, $t->direct_user_two_id) || $t->team_id !== null || $t->slug !== null)
                    ? throw new \InvalidArgumentException('غرفة مباشرة غير قانونية: الطرفان مرتّبان (الأصغر أولًا) والمفتاح موحَّد.') : null,
                self::TYPE_TEAM => ($t->team_id === null || $direct || $t->slug !== null) ? throw new \InvalidArgumentException('غرفة الفريق تتطلب فريقًا فقط.') : null,
                self::TYPE_GLOBAL => ($t->slug !== config('chat.global.slug', 'global') || $t->team_id !== null || $direct) ? throw new \InvalidArgumentException('الغرفة العامة واحدة بمعرّفها الثابت.') : null,
            };
        });

        static::updating(function (ChatThread $t) {
            if ($t->isDirty(['public_id', 'type', 'team_id', 'direct_user_one_id', 'direct_user_two_id', 'direct_key', 'slug'])) {
                throw new \InvalidArgumentException('هوية الغرفة ثابتة بعد الإنشاء.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** مفتاح الثنائي القانوني: A↔B وB↔A يعطيان المفتاح نفسه. */
    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function isTeam(): bool
    {
        return $this->type === self::TYPE_TEAM;
    }

    public function isGlobal(): bool
    {
        return $this->type === self::TYPE_GLOBAL;
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->isDirect() && in_array($userId, [(int) $this->direct_user_one_id, (int) $this->direct_user_two_id], true);
    }

    public function otherParticipantId(int $userId): ?int
    {
        return match (true) {
            ! $this->isDirect() => null,
            (int) $this->direct_user_one_id === $userId => $this->direct_user_two_id ? (int) $this->direct_user_two_id : null,
            (int) $this->direct_user_two_id === $userId => $this->direct_user_one_id ? (int) $this->direct_user_one_id : null,
            default => null,
        };
    }
}

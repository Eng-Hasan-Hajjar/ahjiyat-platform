<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * حدث تنافسي (E17-B). status يدوي مغلق (draft|published|completed|cancelled)؛ "قادم/مباشر/منتهٍ" مشتقة بـphase() من الوقت.
 * حقول العدالة (الأحجية والمواعيد والسعة) مقفلة بعد النشر بحارس على مستوى النموذج: لا تعديل يغيّر قواعد منافسة جارية.
 */
class CompetitiveEvent extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PHASE_DRAFT = 'draft';

    public const PHASE_CANCELLED = 'cancelled';

    public const PHASE_UPCOMING = 'upcoming';

    public const PHASE_LIVE = 'live';

    public const PHASE_ENDED = 'ended';          // انتهى الوقت ولم تُعتمد النتائج بعد

    public const PHASE_COMPLETED = 'completed';  // اعتُمدت النتائج النهائية

    /** حقول لا تتغيّر بعد مغادرة حالة draft. */
    protected const LOCKED_AFTER_PUBLISH = ['puzzle_id', 'starts_at', 'ends_at', 'registration_starts_at', 'registration_ends_at', 'max_participants'];

    protected $fillable = ['title', 'slug', 'description', 'puzzle_id', 'starts_at', 'ends_at', 'registration_starts_at', 'registration_ends_at', 'max_participants', 'is_featured'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'registration_starts_at' => 'datetime', 'registration_ends_at' => 'datetime',
            'published_at' => 'datetime', 'finalized_at' => 'datetime', 'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CompetitiveEvent $event) {
            if ($event->ends_at !== null && $event->starts_at !== null && $event->ends_at->lessThanOrEqualTo($event->starts_at)) {
                throw new \InvalidArgumentException('نهاية المنافسة يجب أن تكون بعد بدايتها.');
            }
        });

        static::updating(function (CompetitiveEvent $event) {
            if ($event->getOriginal('status') !== self::STATUS_DRAFT && $event->isDirty(self::LOCKED_AFTER_PUBLISH)) {
                throw new \InvalidArgumentException('لا يمكن تعديل الأحجية أو المواعيد أو السعة بعد نشر المنافسة.');
            }
        });
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CompetitiveEventParticipant::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(CompetitiveEventResult::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** المرحلة المشتقة (لا حالات يدوية متعارضة). */
    public function phase(?Carbon $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->status === self::STATUS_DRAFT => self::PHASE_DRAFT,
            $this->status === self::STATUS_CANCELLED => self::PHASE_CANCELLED,
            $this->status === self::STATUS_COMPLETED => self::PHASE_COMPLETED,
            $now->lessThan($this->starts_at) => self::PHASE_UPCOMING,
            $now->lessThan($this->ends_at) => self::PHASE_LIVE,
            default => self::PHASE_ENDED,
        };
    }

    public function isLive(?Carbon $now = null): bool
    {
        return $this->phase($now) === self::PHASE_LIVE;
    }

    public function isFull(): bool
    {
        return $this->max_participants !== null && $this->participants_count >= $this->max_participants;
    }

    /** نافذة التسجيل مفتوحة الآن؟ (منشورة، غير منتهية، وضمن نافذة التسجيل إن وُجدت؛ وبلا نافذة صريحة: حتى نهاية المنافسة). */
    public function registrationOpen(?Carbon $now = null): bool
    {
        $now ??= now();

        if (! in_array($this->phase($now), [self::PHASE_UPCOMING, self::PHASE_LIVE], true)) {
            return false;
        }

        if ($this->registration_starts_at !== null && $now->lessThan($this->registration_starts_at)) {
            return false;
        }

        $closes = $this->registration_ends_at !== null ? $this->registration_ends_at->min($this->ends_at) : $this->ends_at;

        return $now->lessThan($closes);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_COMPLETED]);
    }
}

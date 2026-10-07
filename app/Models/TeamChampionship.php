<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * بطولة فرق (E20-D). الحالة والبطل والاعتماد ولقطة النقاط **ليست fillable** (تكتبها TeamChampionshipService بإجراءات مجال). الحارس: بعد النشر لا تتغير المواعيد ولا لقطة
 * النقاط؛ وبعد الاعتماد/الإلغاء لا يتغير شيء (إلا علامة إشعار البدء). upcoming/live/ended مشتقة من الوقت لا من الحالة.
 */
class TeamChampionship extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PHASE_UPCOMING = 'upcoming';

    public const PHASE_LIVE = 'live';

    public const PHASE_ENDED = 'ended';

    protected $fillable = ['title', 'slug', 'description', 'starts_at', 'ends_at', 'is_featured'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime', 'finalized_at' => 'datetime', 'started_notified_at' => 'datetime',
            'is_featured' => 'boolean', 'points_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TeamChampionship $c) {
            if ($c->ends_at !== null && $c->starts_at !== null && $c->ends_at->lessThanOrEqualTo($c->starts_at)) {
                throw new \InvalidArgumentException('نهاية البطولة يجب أن تكون بعد بدايتها.');
            }
        });

        static::updating(function (TeamChampionship $c) {
            $original = $c->getOriginal('status');

            if (in_array($original, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true) && collect($c->getDirty())->except(['updated_at', 'started_notified_at'])->isNotEmpty()) {
                throw new \InvalidArgumentException('البطولة المعتمَدة أو الملغاة لا تتغير.');
            }

            if ($original !== self::STATUS_DRAFT && $c->isDirty(['starts_at', 'ends_at', 'points_snapshot'])) {
                throw new \InvalidArgumentException('مواعيد البطولة ولقطة نقاطها مقفلة بعد النشر.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(CompetitiveEvent::class, 'team_championship_events', 'team_championship_id', 'competitive_event_id')->withPivot('sort_order')->orderByPivot('sort_order')->orderBy('competitive_events.id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(TeamChampionshipResult::class);
    }

    public function champion(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'champion_team_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_COMPLETED]);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function phase(): string
    {
        return match (true) {
            now()->lessThan($this->starts_at) => self::PHASE_UPCOMING,
            now()->lessThan($this->ends_at) => self::PHASE_LIVE,
            default => self::PHASE_ENDED,
        };
    }

    /** النقاط الفعّالة: اللقطة المحفوظة عند النشر، وإلا إعداد التطبيق (معاينة المسودة). */
    public function pointsMap(): array
    {
        $map = $this->points_snapshot ?? config('teams.championships.points', []);

        return collect($map)->mapWithKeys(fn ($p, $r) => [(int) $r => (int) $p])->all();
    }
}

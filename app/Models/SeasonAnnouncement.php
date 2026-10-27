<?php

namespace App\Models;

use App\Services\Seasons\SeasonCache;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E42 - إعلان/إسقاط محتوى موسمي. نص عادي فقط (يُنقّى عند الحفظ بـPlainText ويُهرَّب عند العرض). الإعلان لا يعني إشعارًا: notify_audience قرار إداري صريح (NULL = لا إشعار)،
 * وnotified_at علامة إرسال ذرية. $guarded=['*']: الكتابة بـforceFill من SeasonBuilderService حصرًا.
 */
class SeasonAnnouncement extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $row): void {
            if ($row->published_at !== null && $row->expires_at !== null && $row->expires_at->lessThanOrEqualTo($row->published_at)) {
                throw new \InvalidArgumentException('انتهاء الإعلان يجب أن يكون بعد نشره.');
            }
        });

        static::updating(function (self $row): void {
            foreach (['public_id', 'season_id'] as $immutable) {
                if ($row->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }
        });

        static::saved(fn () => SeasonCache::flush());
        static::deleted(fn () => SeasonCache::flush());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(SeasonPhase::class, 'season_phase_id');
    }

    /** منشور وبلغ موعده ولم ينتهِ: المسودة (published_at فارغ) والمجدول والمنتهي لا يظهرون للجمهور. */
    public function scopeVisible(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->whereNotNull('season_announcements.published_at')
            ->where('season_announcements.published_at', '<=', $now)
            ->where(fn ($w) => $w->whereNull('season_announcements.expires_at')->orWhere('season_announcements.expires_at', '>', $now));
    }

    public function isVisible(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->published_at !== null
            && $this->published_at->lte($now)
            && ($this->expires_at === null || $this->expires_at->gt($now));
    }

    /** draft | scheduled | live | expired — للإدارة. */
    public function statusAt(?CarbonInterface $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->published_at === null => 'draft',
            $this->published_at->gt($now) => 'scheduled',
            $this->expires_at !== null && $this->expires_at->lte($now) => 'expired',
            default => 'live',
        };
    }
}

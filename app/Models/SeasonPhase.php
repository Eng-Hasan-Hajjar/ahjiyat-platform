<?php

namespace App\Models;

use App\Services\Seasons\SeasonCache;
use App\Services\Seasons\SeasonTheme;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E42 - مرحلة/فصل من الموسم: **بيانات جدولة وتحرير فقط** (ليست مهمة ولا مرحلة حملة ولا لوحة صدارة ولا محرك مكافآت).
 * الفترة نصف مفتوحة [starts_at, ends_at). لا mass assignment: الكتابة بـforceFill من SeasonBuilderService حصرًا (يفرض عدم التداخل والبقاء ضمن نافذة الموسم).
 */
class SeasonPhase extends Model
{
    public const STATE_UPCOMING = 'upcoming';

    public const STATE_CURRENT = 'current';

    public const STATE_ENDED = 'ended';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'went_live_at' => 'datetime',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
            'theme_config' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $phase): void {
            $phase->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $phase): void {
            if ($phase->starts_at !== null && $phase->ends_at !== null && $phase->ends_at->lessThanOrEqualTo($phase->starts_at)) {
                throw new \InvalidArgumentException('نهاية المرحلة يجب أن تكون بعد بدايتها.');
            }
        });

        static::updating(function (self $phase): void {
            foreach (['public_id', 'season_id'] as $immutable) {
                if ($phase->isDirty($immutable)) {
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

    /** upcoming | current | ended — من الفترة نصف المفتوحة فقط (لا حالة مخزَّنة). */
    public function stateAt(?CarbonInterface $now = null): string
    {
        $now ??= now();

        return match (true) {
            $now->lt($this->starts_at) => self::STATE_UPCOMING,
            $now->lt($this->ends_at) => self::STATE_CURRENT,
            default => self::STATE_ENDED,
        };
    }

    public function isCurrent(?CarbonInterface $now = null): bool
    {
        return $this->stateAt($now) === self::STATE_CURRENT;
    }

    /** رمز اللون المسموح فقط (قائمة بيضاء)، وإلا null فيرث لون الموسم. */
    public function themeAccent(): ?string
    {
        return SeasonTheme::accentToken($this->theme_config['accent'] ?? null);
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_CURRENT => 'الحالية',
            self::STATE_UPCOMING => 'القادمة',
            default => 'المنتهية',
        };
    }
}

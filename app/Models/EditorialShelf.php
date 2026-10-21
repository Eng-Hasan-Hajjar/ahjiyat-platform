<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E35 - رف تحريري: قائمة يختارها فريق المنصة يدويًا لموضع محدود (اكتشاف/رئيسية). لا يحوي محتوى بل مراجع لأنواع مغلقة (EditorialContentType)
 * تُحلّ وقت القراءة. تكتبه EditorialShelfService وحدها (صلاحية + تدقيق). لا تعيين جماعي.
 */
class EditorialShelf extends Model
{
    public const PLACEMENT_DISCOVER = 'discover';

    public const PLACEMENT_HOME = 'home';

    public const PLACEMENTS = [self::PLACEMENT_DISCOVER => 'صفحة الاكتشاف', self::PLACEMENT_HOME => 'الصفحة الرئيسية'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT => 'مسودة', self::STATUS_ACTIVE => 'فعّال', self::STATUS_ARCHIVED => 'مؤرشف'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (EditorialShelf $shelf) {
            $shelf->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
            'max_items' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(EditorialShelfItem::class)->orderBy('position')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** فعّال الآن: الحالة active ∧ ضمن نافذة الجدولة (بتوقيت المنصة = UTC). تُحسب وقت القراءة لا بمهمة مجدولة. */
    public function scopeLiveAt(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('editorial_shelves.status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('editorial_shelves.starts_at')->orWhere('editorial_shelves.starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('editorial_shelves.ends_at')->orWhere('editorial_shelves.ends_at', '>', $at));
    }

    public function isLiveAt(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at <= $at)
            && ($this->ends_at === null || $this->ends_at > $at);
    }
}

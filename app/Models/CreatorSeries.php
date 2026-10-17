<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E30 - سلسلة مبدع: الهوية الثابتة (public_id + slug) + لقطة النشر الحالية + الإخفاء/الأرشفة. المحتوى نفسه بالنسخ (CreatorSeriesRevision).
 *
 * لا عمود ظهور مخزَّن: الظهور العام يُشتقّ حيًّا في CreatorSeriesVisibilityService (منشورة ∧ غير مخفية/مؤرشفة ∧ مبدع معتمَد وحسابه سليم ∧ كل عناصر
 * النسخة المنشورة ظاهرة الآن). لا اقتصاد: لا جواهر/XP/مهام/صدارة. لا تعيين جماعي: الكتابة عبر خدمات السلاسل فقط.
 */
class CreatorSeries extends Model
{
    public const HIDDEN_MODERATION = 'moderation';

    protected $table = 'creator_series';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CreatorSeries $series) {
            $series->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'archived_at' => 'datetime',
            'hidden_at' => 'datetime',
            'published_items_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CreatorSeriesRevision::class);
    }

    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesRevision::class, 'latest_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesRevision::class, 'published_revision_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function isPublished(): bool
    {
        return $this->published_revision_id !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** رابط الغلاف المنشور (لقطة السلسلة). */
    public function publishedCoverUrl(): ?string
    {
        return app(\App\Services\Creators\CreatorMediaService::class)->url($this->published_cover_path);
    }
}

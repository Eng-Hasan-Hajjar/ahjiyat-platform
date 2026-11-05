<?php

namespace App\Models\Cinematic;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * CIN-1 - نسخة محتوى القصة. المنشورة لا تُعدَّل منطقيًا (الاستبدال الوحيد المسموح: ملف وسائط لمشهد، عبر CinematicAuthoringService::replaceMedia).
 * live_slot=1 لمنشورة واحدة فقط لكل قصة (unique(story_id, live_slot)).
 */
class CinematicRevision extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_UNPUBLISHED = 'unpublished';

    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_IN_REVIEW];

    protected $table = 'cinematic_revisions';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicRevision $revision) {
            $revision->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'readiness' => 'array',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(CinematicStory::class, 'story_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CinematicStage::class, 'revision_id')->orderBy('position')->orderBy('id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CinematicChapter::class, 'revision_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(CinematicScene::class, 'revision_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}

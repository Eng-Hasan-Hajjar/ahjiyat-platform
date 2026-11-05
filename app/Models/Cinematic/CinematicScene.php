<?php

namespace App\Models\Cinematic;

use App\Models\Puzzle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * CIN-1 - مشهد. config = الإعداد **العام** فقط (نصوص/حوار/تعريف عرض التفاعل بلا حلّه). الحل في CinematicSceneSolution (مشفّر، خادمي).
 * لا تُرجَع هذه الموديل إلى المتصفح مباشرة: العرض عبر CinematicScenePayload الذي يبني قائمة بيضاء.
 */
class CinematicScene extends Model
{
    public const TYPE_VIDEO = 'video';

    public const TYPE_NARRATIVE = 'narrative';

    public const TYPE_PUZZLE = 'puzzle';

    public const TYPE_INTERACTION = 'interaction';

    public const TYPE_EXPLORATION = 'exploration';

    public const TYPE_TRANSITION = 'transition';

    public const TYPES = [
        self::TYPE_VIDEO, self::TYPE_NARRATIVE, self::TYPE_PUZZLE, self::TYPE_INTERACTION, self::TYPE_EXPLORATION, self::TYPE_TRANSITION,
    ];

    protected $table = 'cinematic_scenes';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicScene $scene) {
            $scene->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_optional' => 'boolean',
            'is_skippable' => 'boolean',
            'allow_replay' => 'boolean',
            'awaiting_media' => 'boolean',
            'duration_hint_seconds' => 'integer',
            'unlock_rule' => 'array',
            'config' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** المشاهد التي تُنتظر وسائطها أو تتطلب وسيطًا: فيديو، أو تفاعل/استكشاف/سرد بمرجع فيديو اختياري. */
    public function needsVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CinematicRevision::class, 'revision_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CinematicStage::class, 'stage_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CinematicChapter::class, 'chapter_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class, 'puzzle_id');
    }

    public function solution(): HasOne
    {
        return $this->hasOne(CinematicSceneSolution::class, 'scene_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(CinematicMediaAsset::class, 'video_asset_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(CinematicMediaAsset::class, 'poster_asset_id');
    }

    public function subtitle(): BelongsTo
    {
        return $this->belongsTo(CinematicMediaAsset::class, 'subtitle_asset_id');
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(CinematicMediaAsset::class, 'audio_asset_id');
    }
}

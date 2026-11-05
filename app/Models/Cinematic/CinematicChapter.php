<?php

namespace App\Models\Cinematic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** CIN-1 - فصل داخل مرحلة. */
class CinematicChapter extends Model
{
    protected $table = 'cinematic_chapters';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicChapter $chapter) {
            $chapter->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_optional' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CinematicStage::class, 'stage_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(CinematicScene::class, 'chapter_id')->orderBy('position')->orderBy('id');
    }
}

<?php

namespace App\Models\Cinematic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** CIN-1 - مرحلة داخل نسخة. لا حد أقصى مبرمج: السقف من config('cinematic_stories.limits.max_stages'). */
class CinematicStage extends Model
{
    protected $table = 'cinematic_stages';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicStage $stage) {
            $stage->public_id ??= (string) Str::ulid();
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

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CinematicRevision::class, 'revision_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(CinematicChapter::class, 'stage_id')->orderBy('position')->orderBy('id');
    }
}

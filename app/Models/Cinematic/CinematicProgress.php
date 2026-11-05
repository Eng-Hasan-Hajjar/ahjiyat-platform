<?php

namespace App\Models\Cinematic;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** CIN-1 - تقدّم لاعب في قصة، مثبَّت على النسخة التي بدأ عليها. القرار للخادم فقط؛ لا ربط بالمحفظة ولا بالخبرة. */
class CinematicProgress extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'cinematic_progress';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicProgress $progress) {
            $progress->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(CinematicStory::class, 'story_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CinematicRevision::class, 'revision_id');
    }

    public function states(): HasMany
    {
        return $this->hasMany(CinematicSceneState::class, 'progress_id');
    }
}

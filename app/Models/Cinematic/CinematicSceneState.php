<?php

namespace App\Models\Cinematic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CIN-1 - حالة مشهد ضمن تقدّم. unique(progress_id, scene_id) + completed_at يُكتب مرة واحدة = لا إتمام مزدوج. */
class CinematicSceneState extends Model
{
    public const MODE_WATCHED = 'watched';

    public const MODE_SKIPPED = 'skipped';

    public const MODE_READ = 'read';

    public const MODE_SOLVED = 'solved';

    public const MODE_INTERACTED = 'interacted';

    public const MODE_CARRIED = 'carried';

    protected $table = 'cinematic_scene_states';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'attempts_count' => 'integer',
            'replay_count' => 'integer',
        ];
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(CinematicProgress::class, 'progress_id');
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(CinematicScene::class, 'scene_id');
    }
}

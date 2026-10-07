<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نتيجة فريق بتحدٍّ (E20-C): تُكتب مرة واحدة بالمنفِّذ فقط ولا حقل fillable (لا تعيين جماعي لنقاط أو ترتيب). */
class TeamChallengeResult extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['finalized_at' => 'datetime'];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(TeamChallenge::class, 'team_challenge_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

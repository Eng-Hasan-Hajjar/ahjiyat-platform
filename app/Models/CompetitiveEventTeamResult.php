<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ترتيب فريق النهائي بحدث معتمَد (E19-D): يُكتب مرة واحدة عند الاعتماد بـTeamCompetitiveRankingService فقط، ثابت تاريخيًا. */
class CompetitiveEventTeamResult extends Model
{
    protected $fillable = ['competitive_event_id', 'team_id', 'score', 'counted_members', 'total_duration_ms', 'rank'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class, 'competitive_event_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

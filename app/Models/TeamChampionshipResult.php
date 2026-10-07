<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ترتيب فريق النهائي ببطولة (E20-D): يُكتب مرة واحدة عند الاعتماد بخدمة البطولة فقط، ولا حقل fillable. */
class TeamChampionshipResult extends Model
{
    protected $guarded = ['*'];

    public function championship(): BelongsTo
    {
        return $this->belongsTo(TeamChampionship::class, 'team_championship_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

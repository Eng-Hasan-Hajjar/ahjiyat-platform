<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E30 - خطة تعزيز منافسة مقفلة قبل بدئها: لقطة التمويل والنسبة. بعد القفل لا تتغير (المساهمات المتأخرة لا تُغيّر جوائز منافسة جارية).
 * منافسة بلا خطة مقفلة وقت التوزيع = بلا تعزيز (فشل مغلق).
 */
class SponsorshipEventRewardPlan extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['funding_snapshot_minor' => 'integer', 'funding_step_minor' => 'integer', 'boost_bps' => 'integer', 'locked_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class, 'competitive_event_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }
}

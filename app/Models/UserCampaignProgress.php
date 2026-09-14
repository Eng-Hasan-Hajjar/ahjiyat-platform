<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل تقدّم عام لأي CampaignStep لا تُبنى على PuzzleAttempt (اليوم: narrative
 * فقط). مصدر حقيقة خطوة puzzle يبقى دائماً PuzzleAttempt + AttemptContext -
 * هذا الجدول لا يُستخدم له إطلاقاً ولن يُستخدم. مصمَّم ليخدم لاحقاً
 * reflection/manual_review/external_event بلا أي تعديل Schema.
 */
class UserCampaignProgress extends Model
{
    use HasFactory;

    protected $table = 'user_campaign_progress';

    protected $fillable = [
        'user_id', 'campaign_step_id', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'campaign_step_id');
    }
}
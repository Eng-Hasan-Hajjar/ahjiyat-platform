<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مصدر حقيقة narrative وreflection معاً - كلاهما "لا PuzzleAttempt، فقط
 * completed_at". response_payload تُستخدم فقط لـreflection (نص الإجابة)،
 * تبقى null لأي narrative عادية.
 */
class UserCampaignProgress extends Model
{
    use HasFactory;

    protected $table = 'user_campaign_progress';

    protected $fillable = [
        'user_id', 'campaign_step_id', 'started_at', 'completed_at', 'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'response_payload' => 'array',
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
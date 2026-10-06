<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نتيجة مشارك بحدث: قيم يحسبها السيرفر فقط (صحة، نقاط، مدة). final_rank تُكتب مرة عند الإنهاء. الكتابة عبر الخدمات فقط. */
class CompetitiveEventResult extends Model
{
    protected $fillable = ['competitive_event_id', 'user_id', 'game_session_id', 'is_correct', 'score', 'duration_ms', 'completed_at', 'final_rank'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class, 'competitive_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

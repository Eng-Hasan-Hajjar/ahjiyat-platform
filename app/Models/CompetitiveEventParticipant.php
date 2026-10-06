<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مشاركة مستخدم بحدث. الكتابة عبر CompetitiveEventService فقط (العدّاد الذري يحمي السعة). */
class CompetitiveEventParticipant extends Model
{
    public const STATUS_REGISTERED = 'registered';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['competitive_event_id', 'user_id', 'team_id_snapshot', 'status', 'registered_at', 'completed_at'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'completed_at' => 'datetime'];
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

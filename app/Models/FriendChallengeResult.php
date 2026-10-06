<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نتيجة طرف بتحدٍّ: كلها قيم يحسبها السيرفر (صحة، مدة، نقاط). الكتابة عبر FriendChallengeService فقط. */
class FriendChallengeResult extends Model
{
    protected $fillable = ['friend_challenge_id', 'user_id', 'game_session_id', 'is_correct', 'duration_ms', 'score', 'completed_at'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(FriendChallenge::class, 'friend_challenge_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

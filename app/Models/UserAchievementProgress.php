<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievementProgress extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'achievement_id', 'current_value', 'unlocked_at', 'reward_granted_at'];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'reward_granted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked_at !== null;
    }
}
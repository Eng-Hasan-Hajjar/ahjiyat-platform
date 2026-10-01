<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserQuestProgress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'quest_definition_id', 'period_type', 'period_key', 'period_start', 'period_end',
        'current_value', 'target_value_snapshot', 'completed_at', 'reward_granted_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'completed_at' => 'datetime',
            'reward_granted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function questDefinition(): BelongsTo
    {
        return $this->belongsTo(QuestDefinition::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}

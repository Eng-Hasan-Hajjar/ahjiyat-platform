<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLevelUnlock extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'level_definition_id', 'unlocked_at', 'reward_granted_at'];

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

    public function level(): BelongsTo
    {
        return $this->belongsTo(LevelDefinition::class, 'level_definition_id');
    }
}
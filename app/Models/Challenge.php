<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    protected $fillable = [
        'title', 'description', 'type', 'starts_at', 'ends_at',
        'bonus_gem_pool', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function puzzles(): BelongsToMany
    {
        return $this->belongsToMany(Puzzle::class, 'challenge_puzzle');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }

    public function isOpen(): bool
    {
        return $this->is_active && now()->between($this->starts_at, $this->ends_at);
    }
}

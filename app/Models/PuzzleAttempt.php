<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PuzzleAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'puzzle_id', 'attempt_number', 'is_correct',
        'used_hint', 'time_taken_seconds', 'submission_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'used_hint' => 'boolean',
            'submission_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }
}
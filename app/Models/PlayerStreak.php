<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E13: ملخَّص مُجسَّد فقط - PuzzleAttempt الصحيحة هي مصدر الحقيقة التاريخي (راجع StreakService::recalculateFromHistory). */
class PlayerStreak extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'current_streak', 'longest_streak', 'last_active_date', 'last_qualified_at'];

    protected function casts(): array
    {
        return [
            'last_active_date' => 'date',
            'last_qualified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E28 - سجل لعب المجتمع، منفصل عمدًا عن puzzle_attempts: لا يغذّي الاقتصاد/XP/المهام/السلسلة/الإنجازات/لوحة الصدارة/التأهيل بالبناء.
 * للتجربة (حالة الحل/المحاولات) وتحليلات المبدع والرائج فقط. تكتبه CommunityPlayService وحدها.
 */
class CommunityPuzzleAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }
}

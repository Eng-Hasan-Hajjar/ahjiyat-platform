<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E29 - «مفضلة» (مفهوم واحد فقط؛ لا إعجاب منفصل). خاصة بصاحبها: لا تُعرض قائمة أحد لأحد؛ العدد المجمَّع فقط علني.
 * تكتبه CommunityFavoriteService وحدها (مع العدّاد بمعاملة واحدة).
 */
class CommunityPuzzleFavorite extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}

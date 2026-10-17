<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E30 - عنصر سلسلة: عمل مجتمع منشور بموضع صريح داخل نسخة. الفريد: (النسخة، العمل) و(النسخة، الموضع). */
class CreatorSeriesItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesRevision::class, 'creator_series_revision_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}

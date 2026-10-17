<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E29 - عضوية عمل مبدع (الهوية الثابتة لا النسخة القانونية) في مجموعة شخصية. فريدة لكل (مجموعة، عمل). تكتبها CollectionService وحدها. */
class UserCollectionItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(UserCollection::class, 'user_collection_id');
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }
}

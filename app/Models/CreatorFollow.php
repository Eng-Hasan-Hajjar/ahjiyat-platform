<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E29 - متابعة مستخدم لمبدع. قائمة ما يتابعه المستخدم خاصة، وهوية المتابعين لا تُعرض لأحد (حتى للمبدع): العدد المجمَّع فقط.
 * تكتبه CreatorFollowService وحدها (مع followers_count بمعاملة واحدة).
 */
class CreatorFollow extends Model
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }
}

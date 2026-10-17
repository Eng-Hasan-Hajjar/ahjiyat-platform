<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E29 - مجموعة شخصية **خاصة فقط** (لا مشاركة عامة بهذه المرحلة). معرّفها العام ULID، ويُحلّ دائمًا باستعلام محصور بالمالك (غير المالك = 404).
 * العنوان والوصف نص عادي (وسوم HTML مرفوضة عند الإدخال وتُهرَّب عند العرض). تكتبها CollectionService وحدها.
 */
class UserCollection extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (UserCollection $collection) {
            $collection->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(UserCollectionItem::class);
    }
}

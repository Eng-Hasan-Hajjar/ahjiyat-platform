<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E35 - حفظ مجموعة عامة/غير مدرجة. هوية الحافظ خاصة: لا واجهة عامة ولا لوحة مالك تعرضها (العدّاد المجمَّع saves_count وحده علني).
 * فريد لكل (مستخدم، مجموعة). تكتبه CollectionSaveService وحدها. بلا مكافأة ولا أثر اقتصادي.
 */
class UserCollectionSave extends Model
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

    public function collection(): BelongsTo
    {
        return $this->belongsTo(UserCollection::class, 'user_collection_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E37 - لقطة السمعة المجتمعية: **مشتقّة** من سجلات السلطة (تقييمات/مفضّلات/متابعات/حفظ مجموعات/إعجابات تعليقات/أعمال ظاهرة)، ليست سلطة على شيء.
 * حذفها أو إعادة بنائها لا يمسّ حالة المبدع ولا محفظة ولا محتوى ولا متابعين ولا تقييمات ولا إنجازات. تكتبها CommunityReputationService وحدها (upsert ذرّي)؛
 * لا يكتب العميل ولا المدير أي درجة (guarded + لا حقل إدخال). الدرجات صحيحة 0..10000.
 */
class CommunityReputationSnapshot extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'creator_eligible' => 'boolean',
            'rising_eligible' => 'boolean',
            'curator_eligible' => 'boolean',
            'helpful_eligible' => 'boolean',
            'first_published_at' => 'datetime',
            'last_published_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** متوسط التقييم الخام بخانة عشرية (للعرض مع العدد فقط)، أو null بلا تقييمات. */
    public function averageRating(): ?float
    {
        $count = (int) $this->getAttribute('ratings_count');

        return $count > 0 ? round(((int) $this->getAttribute('ratings_sum')) / $count, 1) : null;
    }
}

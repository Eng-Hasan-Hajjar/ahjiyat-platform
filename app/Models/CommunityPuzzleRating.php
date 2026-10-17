<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E29 - تقييم نجوم 1–5 لعمل مبدع (هوية ثابتة، لا نسخة قانونية). مصدر الحقيقة؛ العدّادات على العمل مشتقّة منه.
 * user_id يصير NULL إن حُذف المستخدم (يبقى التقييم مجهولًا فيبقى المتوسط دقيقًا). تكتبه CommunityRatingService وحدها: لا تعيين جماعي.
 */
class CommunityPuzzleRating extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
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

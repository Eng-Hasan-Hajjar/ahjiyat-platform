<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E36 - نقاش واحد لكل كيان عام. يُنشأ كسولًا عند أول تعليق (لا نقاش مسبق لكل أحجية/سلسلة/مجموعة). الفرادة (subject_type, subject_id) بقيد قاعدة بيانات.
 * subject_type من القائمة البيضاء هنا فقط (لا اسم صنف من طلب HTTP أبدًا). comments_count عدّاد مشتقّ تحدّثه DiscussionCommentService/DiscussionModerationService وحدهما.
 */
class DiscussionThread extends Model
{
    public const TYPE_COMMUNITY_PUZZLE = 'community_puzzle';

    public const TYPE_CREATOR_SERIES = 'creator_series';

    public const TYPE_PUBLIC_COLLECTION = 'public_collection';

    /** القائمة البيضاء الوحيدة لأنواع الموضوع (مفتاح => تسمية). official_puzzle غير منفَّذ عمدًا (توثيق E36). */
    public const TYPES = [
        self::TYPE_COMMUNITY_PUZZLE => 'أحجية مجتمع',
        self::TYPE_CREATOR_SERIES => 'سلسلة مبدع',
        self::TYPE_PUBLIC_COLLECTION => 'مجموعة عامة',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'comments_count' => 'integer',
        ];
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DiscussionComment::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->subject_type] ?? (string) $this->subject_type;
    }
}

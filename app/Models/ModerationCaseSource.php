<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E38 - مؤشر من الحالة إلى بلاغ/إشارة مجالية (لا نسخ لمحتوى البلاغ). source_type من القائمة البيضاء فقط.
 * التعديل الوحيد المسموح: active_marker/detached_at/detached_by (إغلاق الحالة أو فصل المصدر). لا حذف.
 */
class ModerationCaseSource extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_PUZZLE_REPORT = 'community_puzzle_report';

    public const TYPE_COLLECTION_REPORT = 'user_collection_report';

    public const TYPE_DISCUSSION_REPORT = 'discussion_comment_report';

    public const TYPE_CHAT_REPORT = 'chat_message_report';

    public const TYPE_FRAUD_FLAG = 'fraud_flag';

    public const TYPES = [
        self::TYPE_PUZZLE_REPORT, self::TYPE_COLLECTION_REPORT, self::TYPE_DISCUSSION_REPORT, self::TYPE_CHAT_REPORT, self::TYPE_FRAUD_FLAG,
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $source): void {
            $illegal = array_diff(array_keys($source->getDirty()), ['active_marker', 'detached_at', 'detached_by']);

            if ($illegal !== []) {
                throw new LogicException('مصدر الحالة لا يقبل تعديل هذه الأعمدة.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('مصادر الحالات لا تُحذف.');
        });
    }

    protected function casts(): array
    {
        return ['detached_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'moderation_case_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}

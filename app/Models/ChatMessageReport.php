<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بلاغ عن رسالة (E21). التصنيف مغلق (config/chat.php) والحالة: pending|reviewed|dismissed|actioned. لا تعيين جماعي: تكتبه ChatReportService. */
class ChatMessageReport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_REVIEWED, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}

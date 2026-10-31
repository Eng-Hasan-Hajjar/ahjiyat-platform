<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E45 - تقييم 👍/👎 لردّ مساعد. يخزّن معرّف الرسالة والتقييم وسببًا مغلقًا اختياريًا فقط (لا نص حر، لا محتوى محادثة). لا يغيّر سلوك النموذج تلقائيًا:
 * يستعمله المحرر لتحسين المعرفة. بعد حذف الرسالة/المستخدم تبقى الصفوف بلا هوية (SET NULL) إلى أن يُنظَّف حسب الاحتفاظ.
 */
class AiFeedback extends Model
{
    public const RATING_UP = 'up';

    public const RATING_DOWN = 'down';

    public const RATINGS = [self::RATING_UP, self::RATING_DOWN];

    public const REASONS = ['inaccurate', 'incomplete', 'unsafe', 'off_topic', 'other'];

    protected $table = 'ai_feedback';

    protected $guarded = ['*'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E36 - إعجاب (نوع تفاعل وحيد) بتعليق. فريد (مستخدم، تعليق). من أعجب خاص: لا واجهة تعرضه، والعدّاد المجمَّع وحده علني. لا أثر اقتصادي. */
class DiscussionCommentLike extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(DiscussionComment::class, 'discussion_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

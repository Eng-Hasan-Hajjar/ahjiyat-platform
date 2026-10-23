<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E38 - دليل/ملاحظة مشرف على حالة. نص عادي فقط (لا ملفات، لا HTML)، إلحاقي، وللمشرفين فقط. */
class ModerationEvidence extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const KIND_NOTE = 'moderator_note';

    public const KIND_SNAPSHOT = 'content_snapshot';

    public const KINDS = [self::KIND_NOTE => 'ملاحظة مشرف', self::KIND_SNAPSHOT => 'لقطة نصية للمحتوى المُبلَّغ عنه'];

    protected $table = 'moderation_evidence';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'moderation_case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}

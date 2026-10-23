<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E38 - حدث في التسلسل الزمني للحالة. إلحاقي فقط (لا تعديل ولا حذف). يُقرأ للمشرفين فقط. */
class ModerationCaseEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'moderation_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

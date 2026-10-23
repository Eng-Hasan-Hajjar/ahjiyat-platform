<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E38 - اعتراض مستخدم على إجراء. اعتراض فعّال واحد لكل إجراء (active_marker + unique). نص عادي فقط، بلا مرفقات.
 * decision_note داخلية للمشرفين؛ decision_message هي ما يراه المستخدم. لا حذف.
 */
class ModerationAppeal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_PENDING => 'بانتظار المراجعة',
        self::STATUS_IN_REVIEW => 'قيد المراجعة',
        self::STATUS_ACCEPTED => 'مقبول',
        self::STATUS_REJECTED => 'مرفوض',
        self::STATUS_WITHDRAWN => 'مسحوب',
    ];

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_IN_REVIEW];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $appeal): void {
            $appeal->public_id ??= (string) Str::ulid();
        });

        static::deleting(function (): void {
            throw new LogicException('الاعتراضات لا تُحذف.');
        });
    }

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'decided_at' => 'datetime', 'conflict_flag' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class, 'moderation_action_id');
    }

    public function appellant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appellant_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}

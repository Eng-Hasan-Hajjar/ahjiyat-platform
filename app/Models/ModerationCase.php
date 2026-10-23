<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * E38 - حالة إشراف. تنسّق فوق البلاغات المجالية (لا تنسخها) وتجمع الأدلة والتسلسل الزمني والإجراءات.
 * لا تُحذف أبدًا (الحذف مرفوض هنا). لا تعيين جماعي: تكتبها CaseService حصرًا.
 * **الأولوية لا تُشتقّ من الشهرة/السمعة/الدعم**، وعدد البلاغات ليس حكمًا.
 */
class ModerationCase extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_AWAITING_USER = 'awaiting_user';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN => 'مفتوحة',
        self::STATUS_INVESTIGATING => 'قيد التحقيق',
        self::STATUS_AWAITING_USER => 'بانتظار المستخدم',
        self::STATUS_RESOLVED => 'محسومة',
        self::STATUS_CLOSED => 'مغلقة',
    ];

    /** الحالات الفعّالة: تحتجز مصادرها (active_marker=1) ولا يجوز أن يكون بلاغ واحد في حالتين فعّالتين. */
    public const ACTIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_INVESTIGATING, self::STATUS_AWAITING_USER];

    public const PRIORITIES = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'مرتفعة', 'urgent' => 'عاجلة'];

    public const RESOLUTIONS = [
        'action_taken' => 'اتُّخذ إجراء',
        'no_violation' => 'لا مخالفة',
        'insufficient_evidence' => 'أدلة غير كافية',
    ];

    /** مفاتيح موضوع الحالة المسموحة (قائمة بيضاء مغلقة؛ لا اسم فئة من العميل أبدًا). */
    public const SUBJECT_TYPES = ['community_puzzle', 'user_collection', 'discussion_comment', 'chat_message', 'user'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            $case->public_id ??= (string) Str::ulid();
        });

        static::deleting(function (): void {
            throw new LogicException('حالات الإشراف لا تُحذف.');
        });
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime', 'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
        ];
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

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    public function categoryLabel(): string
    {
        return (string) config('trust_safety.categories.'.$this->category.'.label', config('trust_safety.categories.other.label'));
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ModerationCaseSource::class, 'moderation_case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ModerationCaseEvent::class, 'moderation_case_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ModerationEvidence::class, 'moderation_case_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'moderation_case_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}

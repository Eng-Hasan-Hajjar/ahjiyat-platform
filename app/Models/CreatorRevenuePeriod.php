<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * E39 - فترة إيراد مبدعين [starts_at, ends_at) بمجمّع صريح. تكتبها CreatorRevenuePeriodService حصرًا ($guarded=['*'] + forceFill).
 * الانتقالات المسموحة فقط؛ بعد finalized/cancelled لا يتغير أي عمود أبدًا؛ لا حذف. محتوى الفترة (التواريخ/المجمّع/العملة) لا يُعدَّل إلا في draft.
 */
class CreatorRevenuePeriod extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW = 'review';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_REVIEW => 'قيد المراجعة',
        self::STATUS_FINALIZED => 'مُقفلة',
        self::STATUS_CANCELLED => 'ملغاة',
    ];

    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_REVIEW => [self::STATUS_DRAFT, self::STATUS_FINALIZED, self::STATUS_CANCELLED],
        self::STATUS_FINALIZED => [],
        self::STATUS_CANCELLED => [],
    ];

    /** حالات تُحتسب في فحص عدم التداخل (كل ما ليس ملغى). */
    public const LIVE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REVIEW, self::STATUS_FINALIZED];

    /** أعمدة المحتوى: لا تُعدَّل خارج draft. */
    public const CONTENT_COLUMNS = ['label', 'starts_at', 'ends_at', 'currency', 'pool_minor'];

    protected $table = 'creator_revenue_periods';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pool_minor' => 'integer',
            'total_units' => 'integer',
            'eligible_creators' => 'integer',
            'allocated_minor' => 'integer',
            'unallocated_minor' => 'integer',
            'policy_snapshot' => 'array',
            'review_summary' => 'array',
            'reviewed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $period): void {
            $period->public_id ??= (string) Str::ulid();
        });

        static::updating(function (self $period): void {
            $original = (string) $period->getOriginal('status');

            if (in_array($original, [self::STATUS_FINALIZED, self::STATUS_CANCELLED], true)) {
                throw new LogicException('الفترة المُقفلة أو الملغاة لا تقبل التعديل.');
            }

            foreach (['public_id', 'created_by'] as $immutable) {
                if ($period->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            if ($period->isDirty('status') && ! in_array($period->status, self::TRANSITIONS[$original] ?? [], true)) {
                throw new LogicException("انتقال غير مسموح للفترة: {$original} ← {$period->status}.");
            }

            if ($original !== self::STATUS_DRAFT && $period->isDirty(self::CONTENT_COLUMNS)) {
                throw new LogicException('محتوى الفترة لا يُعدَّل خارج المسودة.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('فترات الإيراد لا تُحذف.');
        });
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CreatorRevenueAllocation::class, 'creator_revenue_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}

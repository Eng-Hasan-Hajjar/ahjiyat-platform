<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E30 - نسخة سلسلة (سجل التاريخ): العنوان/الوصف/الغلاف + عناصرها المرتبة + حالة سير العمل والمراجعة.
 * draft → pending_review → approved | changes_requested | rejected. المعتمدة لا تُعدَّل أبدًا (التعديل = نسخة جديدة).
 */
class CreatorSeriesRevision extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_CHANGES_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'revision_number' => 'integer'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreatorSeriesItem::class)->orderBy('position');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED], true);
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    /** رابط غلاف النسخة (يضعه المخدّم وحده من رفع صالح). */
    public function coverUrl(): ?string
    {
        return app(\App\Services\Creators\CreatorMediaService::class)->url($this->cover_path);
    }
}

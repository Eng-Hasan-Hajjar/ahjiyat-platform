<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E28 - نسخة من عمل المبدع: حمولة التأليف + حالة سير العمل + المراجعة + ربط الأحجية القانونية (puzzle_id) عند الاعتماد.
 *
 * content مشفَّرة (encrypted:array) لأنها تحوي الجواب الخام (يحتاجه المبدع والمراجع فقط) ومخفية من التسلسل (hidden) فلا تتسرّب بأي JSON.
 * النسخة المعتمدة والمرفوضة جامدتان: أي تعديل عليهما يُرفض بالنموذج (سجل التاريخ لا يُعاد كتابته).
 */
class CreatorPuzzleRevision extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_CHANGES_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $guarded = ['*'];

    protected $hidden = ['content'];

    protected static function booted(): void
    {
        static::updating(function (CreatorPuzzleRevision $revision) {
            if (in_array($revision->getOriginal('status'), [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                $changed = array_diff(array_keys($revision->getDirty()), ['updated_at']);

                if ($changed !== []) {
                    throw new \LogicException('النسخ المعتمدة والمرفوضة جامدة ولا تُعدَّل.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'content' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'puzzle_category_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
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

    /** رابط صورة الأحجية (يضعه المخدّم وحده من رفع صالح). */
    public function imageUrl(): ?string
    {
        return app(\App\Services\Creators\CreatorMediaService::class)->url($this->image_path);
    }
}

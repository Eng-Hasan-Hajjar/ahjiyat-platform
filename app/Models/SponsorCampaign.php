<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E14 (بند 19/579): status هي مصدر الحقيقة الوحيد لحالة الحملة - عمدًا
 * بلا is_active/approved منفصلَين. الانتهاء الزمني مُشتَق من ends_at وقت
 * العرض (isCurrentlyWithinSchedule())، لا حالة "ended" مُخزَّنة (درس E13.1).
 */
class SponsorCampaign extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, self::STATUS_APPROVED,
        self::STATUS_PAUSED, self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'internal_key', 'sponsor_name', 'campaign_name', 'status',
        'starts_at', 'ends_at', 'priority', 'notes', 'review_note',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function creatives(): HasMany
    {
        return $this->hasMany(SponsorCreative::class);
    }

    public function activeCreatives(): HasMany
    {
        return $this->creatives()->where('is_active', true)->orderBy('sort_order');
    }

    public function placements(): BelongsToMany
    {
        return $this->belongsToMany(AdPlacement::class, 'campaign_ad_placement');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** E14.1 (درس E13.1 صراحةً): now() الحقيقي مقابل الجدول - لا تداخل يوم كامل. */
    public function isCurrentlyWithinSchedule(): bool
    {
        $now = now();

        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function isServable(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->isCurrentlyWithinSchedule();
    }
}

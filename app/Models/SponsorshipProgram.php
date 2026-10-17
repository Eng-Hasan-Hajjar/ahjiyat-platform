<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E30 - برنامج رعاية لهدف رسمي. الهدف من قائمة بيضاء (TARGETS) ويُتحقق من وجوده ورسميته بـSponsorshipProgramService؛ أحجيات/محتوى المجتمع ليست هدفًا أبدًا.
 * خطة التعزيز (funding_step_minor/boost_per_step_bps/max_boost_bps) حقول ضبط فقط؛ كل الحساب والمنح بـSponsorshipRewardService.
 * لا تعيين جماعي: كل كتابة عبر الخدمة (تدقيق).
 */
class SponsorshipProgram extends Model
{
    public const TARGET_PLATFORM = 'platform';

    public const TARGET_SEASON = 'season';

    public const TARGET_CAMPAIGN = 'campaign';

    public const TARGET_COMPETITIVE_EVENT = 'competitive_event';

    public const TARGETS = [self::TARGET_PLATFORM, self::TARGET_SEASON, self::TARGET_CAMPAIGN, self::TARGET_COMPETITIVE_EVENT];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CLOSED, self::STATUS_CANCELLED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'goal_amount_minor' => 'integer',
            'show_public_amounts' => 'boolean',
            'show_goal_progress' => 'boolean',
            'reward_boost_enabled' => 'boolean',
            'funding_step_minor' => 'integer',
            'boost_per_step_bps' => 'integer',
            'max_boost_bps' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'reward_plan_locked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SponsorshipContribution::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(SponsorshipProgramRole::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** نشط الآن: حالة active ضمن نافذة البدء/الانتهاء (إن وُجدت). */
    public function scopeActiveNow(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($table.'.status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull($table.'.starts_at')->orWhere($table.'.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull($table.'.ends_at')->orWhere($table.'.ends_at', '>=', now()));
    }

    public function isActiveNow(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo(now()))
            && ($this->ends_at === null || $this->ends_at->greaterThanOrEqualTo(now()));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}

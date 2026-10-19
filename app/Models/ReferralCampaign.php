<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E33 - حملة دعوة (إعدادات فقط). كل تغيير حالة/مكافآت يمر بـReferralCampaignService (تدقيق + حد معدّل + تفريغ الكاش).
 * الحملة لا تغيّر إحالة سبق تأهيلها: لقطة المكافأة تُنسخ إلى user_referrals عند التأهيل.
 */
class ReferralCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ENDED];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'rewards_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'default_slot' => 'integer',
            'min_account_age_hours' => 'integer',
            'referrer_reward_gems' => 'integer',
            'referred_reward_gems' => 'integer',
            'daily_cap' => 'integer',
            'monthly_cap' => 'integer',
            'lifetime_cap' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(UserReferral::class);
    }

    public function isDefault(): bool
    {
        return (int) $this->default_slot === 1;
    }

    /** نشطة فعليًا الآن: الحالة active وضمن نافذتها الزمنية. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** هل تمنح مكافآت؟ يتطلب المفتاح العام + تفعيل الحملة + مبلغًا موجبًا لجانب واحد على الأقل. */
    public function rewardsActive(): bool
    {
        return (bool) config('referrals.rewards.enabled')
            && $this->rewards_enabled
            && ((int) $this->referrer_reward_gems > 0 || (int) $this->referred_reward_gems > 0);
    }

    /** @return array{daily: int, monthly: int, lifetime: int} */
    public function effectiveCaps(): array
    {
        $base = (array) config('referrals.rewards.caps');
        $abs = (array) config('referrals.rewards.absolute_caps');

        return [
            'daily' => min((int) ($this->daily_cap ?? $base['daily']), (int) $abs['daily']),
            'monthly' => min((int) ($this->monthly_cap ?? $base['monthly']), (int) $abs['monthly']),
            'lifetime' => min((int) ($this->lifetime_cap ?? $base['lifetime']), (int) $abs['lifetime']),
        ];
    }

    public function effectiveMinAgeHours(): int
    {
        return max(0, min(720, (int) ($this->min_account_age_hours ?? config('referrals.qualification.min_account_age_hours'))));
    }
}

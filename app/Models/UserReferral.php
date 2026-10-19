<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E33 - إحالة واحدة لكل حساب مُحال (UNIQUE referred_user_id). المحيل لا يتغيّر بعد الإنشاء (لا مسار تحديث له بأي خدمة).
 * التسجيل وحده ≠ إحالة مؤهَّلة: الحالة تبدأ pending وتنتقل فقط عبر ReferralQualificationService / ReferralReviewService.
 * لا تعيين جماعي (guarded) ولا بيانات خام (IP/UA) في أي عمود.
 */
class UserReferral extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_REWARDED = 'rewarded';

    public const STATUS_DISQUALIFIED = 'disqualified';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PENDING_REVIEW, self::STATUS_QUALIFIED, self::STATUS_REWARDED, self::STATUS_DISQUALIFIED];

    /** الحالات التي تُعدّ «مؤهَّلة» في التحليلات (تعليق المراجعة ليس مؤهَّلًا حتى يُعتمد). */
    public const QUALIFIED_STATUSES = [self::STATUS_QUALIFIED, self::STATUS_REWARDED];

    public const SOURCE_LINK = 'link';

    public const SOURCE_SHARE_PARAM = 'share_param';

    public const REWARD_NONE = 'none';

    public const REWARD_PENDING = 'pending';

    public const REWARD_GRANTED = 'granted';

    public const REWARD_SKIPPED = 'skipped';

    /** أسباب الاستبعاد (قائمة مغلقة؛ لا نص حر من العميل). */
    public const DISQUALIFY_REASONS = [
        'self_referral' => 'إحالة ذاتية',
        'fake_accounts' => 'حسابات وهمية متعددة',
        'automation' => 'نشاط آلي',
        'policy_violation' => 'مخالفة السياسة',
        'duplicate_identity' => 'هوية مكررة',
        'other' => 'سبب آخر',
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'risk_signals' => 'array',
            'action_at' => 'datetime',
            'qualified_at' => 'datetime',
            'held_at' => 'datetime',
            'reward_reserved_at' => 'datetime',
            'referrer_granted_at' => 'datetime',
            'referred_granted_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'disqualified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'snap_referrer_gems' => 'integer',
            'snap_referred_gems' => 'integer',
            'snap_campaign_id' => 'integer',
            'min_account_age_hours' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ReferralCampaign::class, 'referral_campaign_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeQualified(Builder $q): Builder
    {
        return $q->whereIn('status', self::QUALIFIED_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PENDING_REVIEW], true);
    }
}

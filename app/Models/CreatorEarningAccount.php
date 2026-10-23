<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * E39 - حساب أرباح المبدع: لقطة أرصدة **مشتقة** من الدفتر + بوابة التسييل (منفصلة عن CreatorProfile.status) + تعليق الدفعات.
 * يكتبه CreatorLedgerService حصرًا. لا رصيد سالب أبدًا، والعملة والمالك ثابتان، ولا حذف.
 */
class CreatorEarningAccount extends Model
{
    public const STATUS_ENABLED = 'enabled';

    public const STATUS_PAUSED = 'paused';

    public const STATUSES = [
        self::STATUS_ENABLED => 'مُفعَّل',
        self::STATUS_PAUSED => 'موقوف مؤقتًا',
    ];

    protected $table = 'creator_earning_accounts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'available_minor' => 'integer',
            'reserved_minor' => 'integer',
            'paid_lifetime_minor' => 'integer',
            'monetization_enabled_at' => 'datetime',
            'monetization_changed_at' => 'datetime',
            'hold_placed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $account): void {
            foreach (['available_minor', 'reserved_minor', 'paid_lifetime_minor'] as $column) {
                if ((int) $account->{$column} < 0) {
                    throw new LogicException('رصيد حساب الأرباح لا يكون سالبًا.');
                }
            }
        });

        static::updating(function (self $account): void {
            foreach (['public_id', 'creator_profile_id', 'user_id', 'currency'] as $immutable) {
                if ($account->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('حسابات أرباح المبدعين لا تُحذف.');
        });
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CreatorEarningEntry::class, 'creator_earning_account_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(CreatorPayout::class, 'creator_earning_account_id');
    }

    public function holdCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'hold_case_id');
    }

    public function isEnabled(): bool
    {
        return $this->monetization_status === self::STATUS_ENABLED;
    }

    public function isOnHold(): bool
    {
        return $this->hold_reason_code !== null && $this->hold_reason_code !== '';
    }
}

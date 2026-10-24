<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E40 - مطالبة بمكافأة شريحة (واحدة لكل مستخدم+مكافأة، unique في القاعدة). لقطات (الاسم/الشريحة/المسار/الكمية) ثابتة، والربط بالمنح الفعلي (حركة مخزون أو امتياز) للمطابقة. لا تعديل ولا حذف.
 */
class UserPassRewardClaim extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_COLLECT_ALL = 'collect_all';

    public $timestamps = false;

    protected $table = 'user_pass_reward_claims';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['tier_number' => 'integer', 'quantity' => 'integer', 'claimed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $claim): void {
            $claim->claimed_at ??= now();
        });

        static::updating(function (): void {
            throw new LogicException('سجل الاستلام للإضافة فقط.');
        });

        static::deleting(function (): void {
            throw new LogicException('سجل الاستلام لا يُحذف.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(UserPassProgress::class, 'user_pass_progress_id');
    }

    public function tierReward(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPassTierReward::class, 'ahjiyat_pass_tier_reward_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function isPremium(): bool
    {
        return $this->track === AhjiyatPassTierReward::TRACK_PREMIUM;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E40 - مكافأة شريحة: مرجع **عنصر متجر قائم** (StoreItem) لمسار free أو premium (واحدة لكل مسار/شريحة، unique في القاعدة).
 * لا محرك مكافآت ثانٍ: المنح الفعلي عبر InventoryService/EntitlementService عند الاستلام. تُقفل بنشر المسار.
 */
class AhjiyatPassTierReward extends Model
{
    public const TRACK_FREE = 'free';

    public const TRACK_PREMIUM = 'premium';

    public const TRACKS = [self::TRACK_FREE => 'مجاني', self::TRACK_PREMIUM => 'مميز'];

    protected $table = 'ahjiyat_pass_tier_rewards';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $reward) => $reward->assertParentUnlocked());
        static::updating(function (self $reward): void {
            foreach (['ahjiyat_pass_id', 'ahjiyat_pass_tier_id', 'track'] as $immutable) {
                if ($reward->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            $reward->assertParentUnlocked();
        });
        static::deleting(fn (self $reward) => $reward->assertParentUnlocked());
    }

    protected function assertParentUnlocked(): void
    {
        $pass = AhjiyatPass::query()->find($this->ahjiyat_pass_id);

        if ($pass !== null && $pass->locked_at !== null) {
            throw new LogicException('مكافآت مسار منشور مقفلة.');
        }
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPassTier::class, 'ahjiyat_pass_tier_id');
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPass::class, 'ahjiyat_pass_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function isPremium(): bool
    {
        return $this->track === self::TRACK_PREMIUM;
    }
}

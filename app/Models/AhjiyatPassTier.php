<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * E40 - شريحة (Tier) في مسار الأحجيات. required_points **تراكمي** (الشريحة n تُبلَغ حين تصل نقاط المسار إلى required_points).
 * تُعدَّل وتُضاف وتُحذف في **المسودة فقط**: بعد نشر المسار (locked_at) أي تعديل/حذف يُرفض هنا فلا يُعاد كتابة تاريخ اللاعبين.
 */
class AhjiyatPassTier extends Model
{
    protected $table = 'ahjiyat_pass_tiers';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['tier_number' => 'integer', 'required_points' => 'integer', 'is_milestone' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $tier) => $tier->assertParentUnlocked());
        static::updating(function (self $tier): void {
            if ($tier->isDirty('ahjiyat_pass_id')) {
                throw new LogicException('الشريحة لا تنتقل بين المسارات.');
            }

            $tier->assertParentUnlocked();
        });
        static::deleting(fn (self $tier) => $tier->assertParentUnlocked());
    }

    protected function assertParentUnlocked(): void
    {
        $pass = AhjiyatPass::query()->find($this->ahjiyat_pass_id);

        if ($pass !== null && $pass->locked_at !== null) {
            throw new LogicException('شرائح مسار منشور مقفلة.');
        }
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPass::class, 'ahjiyat_pass_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(AhjiyatPassTierReward::class, 'ahjiyat_pass_tier_id');
    }
}

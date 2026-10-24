<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * E40 - تقدّم لاعب في مسار: صف كسول واحد لكل (مستخدم، مسار). pass_points وhighest_tier **لقطة مشتقة** من دفتر النقاط (pass_point_grants) وعتبات الشرائح.
 * premium_unlocked_at هو **السلطة الوحيدة** على ملكية المميز لهذا المسار (تكتبه خدمة التحقق وتمحوه خدمة الاسترداد حصرًا). لا يُحذف عبر Eloquent.
 * $guarded=['*']: العميل لا يصل إلى أي حقل منه.
 */
class UserPassProgress extends Model
{
    protected $table = 'user_pass_progress';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'pass_points' => 'integer',
            'highest_tier' => 'integer',
            'premium_unlocked_at' => 'datetime',
            'joined_at' => 'datetime',
            'last_progress_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $progress): void {
            foreach (['user_id', 'ahjiyat_pass_id'] as $immutable) {
                if ($progress->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('تقدّم المسار لا يُحذف.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPass::class, 'ahjiyat_pass_id');
    }

    public function premiumPurchase(): BelongsTo
    {
        return $this->belongsTo(DigitalPurchase::class, 'premium_purchase_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(PassPointGrant::class, 'user_pass_progress_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(UserPassRewardClaim::class, 'user_pass_progress_id');
    }

    public function hasPremium(): bool
    {
        return $this->premium_unlocked_at !== null;
    }
}

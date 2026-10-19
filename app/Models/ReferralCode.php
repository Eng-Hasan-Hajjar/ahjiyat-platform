<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E33 - رمز إحالة شخصي. يولّده الخادم حصرًا (ReferralCodeService)، فريد ومستقر. التدوير يعطّل القديم (active_for_user_id = NULL) ولا يحذفه.
 * لا تعيين جماعي؛ العدّادات تقريبية (clicks_count نقرات فريدة بالساعة) ولا تُستعمل لأي مكافأة.
 */
class ReferralCode extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['clicks_count' => 'integer', 'shares_count' => 'integer', 'rotated_at' => 'datetime', 'active_for_user_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(UserReferral::class);
    }

    public function isActive(): bool
    {
        return $this->active_for_user_id !== null && (int) $this->active_for_user_id === (int) $this->user_id;
    }
}

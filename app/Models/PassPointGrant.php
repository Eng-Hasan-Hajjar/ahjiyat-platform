<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E40 - دفتر نقاط المسار (إضافة فقط). source_key فريد في القاعدة = إكمال مهمة واحد لا يمنح مرتين. لا تعديل ولا حذف.
 */
class PassPointGrant extends Model
{
    public const SOURCE_MISSION = 'mission';

    public $timestamps = false;

    protected $table = 'pass_point_grants';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            $grant->created_at ??= now();
        });

        static::updating(function (): void {
            throw new LogicException('دفتر نقاط المسار للإضافة فقط.');
        });

        static::deleting(function (): void {
            throw new LogicException('دفتر نقاط المسار لا يُحذف.');
        });
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(UserPassProgress::class, 'user_pass_progress_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPassMission::class, 'ahjiyat_pass_mission_id');
    }
}

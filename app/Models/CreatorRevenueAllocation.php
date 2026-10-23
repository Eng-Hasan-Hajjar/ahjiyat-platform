<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E39 - تخصيص مبدع داخل فترة مُقفلة. إلحاقي فقط (لا تعديل ولا حذف). unique (period, creator) بقاعدة البيانات.
 * remainder_bonus_minor = 1 إن نال المبدع وحدة صغرى إضافية من توزيع «أكبر باقٍ» (للشفافية في الكشف).
 */
class CreatorRevenueAllocation extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'creator_revenue_allocations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'raw_units' => 'integer',
            'qualified_units' => 'integer',
            'unique_players' => 'integer',
            'period_total_units' => 'integer',
            'allocated_minor' => 'integer',
            'remainder_bonus_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            $allocation->public_id ??= (string) Str::ulid();
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CreatorRevenuePeriod::class, 'creator_revenue_period_id');
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }
}

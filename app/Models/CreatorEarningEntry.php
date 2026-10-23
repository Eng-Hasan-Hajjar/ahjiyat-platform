<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E39 - قيد في دفتر أرباح المبدع. إلحاقي فقط. شكل الحركات الثلاث (available/reserved/paid) مرتبط بنوع القيد ويُفرض عند الإدراج
 * (دفاع فوق الخدمة): أي قيد بشكل لا يطابق نوعه يُرفض قبل بلوغ القاعدة. source_key فريد = idempotency.
 */
class CreatorEarningEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const TYPE_REVENUE_SHARE = 'revenue_share';

    public const TYPE_ADJUSTMENT_CREDIT = 'adjustment_credit';

    public const TYPE_ADJUSTMENT_DEBIT = 'adjustment_debit';

    public const TYPE_PAYOUT_RESERVE = 'payout_reserve';

    public const TYPE_PAYOUT_RELEASE = 'payout_release';

    public const TYPE_PAYOUT_SETTLE = 'payout_settle';

    public const TYPES = [
        self::TYPE_REVENUE_SHARE => 'حصة إيراد',
        self::TYPE_ADJUSTMENT_CREDIT => 'تعديل (إضافة)',
        self::TYPE_ADJUSTMENT_DEBIT => 'تعديل (خصم)',
        self::TYPE_PAYOUT_RESERVE => 'حجز لدفعة',
        self::TYPE_PAYOUT_RELEASE => 'فكّ حجز',
        self::TYPE_PAYOUT_SETTLE => 'صرف دفعة',
    ];

    /** نوع القيد ← إشارة كل حركة: '+' موجبة، '-' سالبة، '0' صفر. */
    public const SHAPES = [
        self::TYPE_REVENUE_SHARE => ['+', '0', '0'],
        self::TYPE_ADJUSTMENT_CREDIT => ['+', '0', '0'],
        self::TYPE_ADJUSTMENT_DEBIT => ['-', '0', '0'],
        self::TYPE_PAYOUT_RESERVE => ['-', '+', '0'],
        self::TYPE_PAYOUT_RELEASE => ['+', '-', '0'],
        self::TYPE_PAYOUT_SETTLE => ['0', '-', '+'],
    ];

    protected $table = 'creator_earning_entries';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'available_delta_minor' => 'integer',
            'reserved_delta_minor' => 'integer',
            'paid_delta_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->public_id ??= (string) Str::ulid();
            $entry->assertShape();
        });
    }

    /** @throws LogicException */
    public function assertShape(): void
    {
        $shape = self::SHAPES[$this->type] ?? null;

        if ($shape === null) {
            throw new LogicException('نوع قيد غير معروف.');
        }

        $deltas = [(int) $this->available_delta_minor, (int) $this->reserved_delta_minor, (int) $this->paid_delta_minor];

        foreach ($shape as $i => $sign) {
            $ok = match ($sign) {
                '+' => $deltas[$i] > 0,
                '-' => $deltas[$i] < 0,
                default => $deltas[$i] === 0,
            };

            if (! $ok) {
                throw new LogicException('شكل حركات القيد لا يطابق نوعه.');
            }
        }

        // الحجز/الفكّ/الصرف تنقل المبلغ نفسه بين خانتين بلا خلق أو إتلاف.
        $moved = match ($this->type) {
            self::TYPE_PAYOUT_RESERVE, self::TYPE_PAYOUT_RELEASE => $deltas[0] + $deltas[1],
            self::TYPE_PAYOUT_SETTLE => $deltas[1] + $deltas[2],
            default => 0,
        };

        if ($moved !== 0) {
            throw new LogicException('القيد يخلق أو يُتلف مبلغًا بدل نقله.');
        }

        if ($this->source_key === null || $this->source_key === '') {
            throw new LogicException('source_key مطلوب لكل قيد.');
        }
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CreatorEarningAccount::class, 'creator_earning_account_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}

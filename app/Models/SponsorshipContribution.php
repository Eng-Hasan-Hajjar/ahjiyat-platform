<?php

namespace App\Models;

use App\Services\Sponsorship\SponsorshipException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E30 - مساهمة. مصدر الحقيقة المالي الوحيد للتمويل/المستويات/الصدارة/التعزيز (status = confirmed فقط). المبالغ أعداد صحيحة بالوحدة الصغرى.
 *
 * حماية بنيوية (لا تعتمد على حسن نية المنادي): بعد مغادرة pending لا يمكن تعديل الحقول المالية (المبلغ/العملة/المعادل/الداعم/البرنامج) إطلاقًا؛
 * التصحيح = استرداد ثم مساهمة جديدة. الانتقالات المسموحة فقط: pending → confirmed|cancelled و confirmed → refunded. لا حذف أبدًا.
 * لا تعيين جماعي ($guarded): الكتابة عبر ContributionService فقط.
 */
class SponsorshipContribution extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_REFUNDED];

    /** @var list<string> حقول لا تتغير بعد مغادرة pending. */
    public const FINANCIAL_FIELDS = ['original_amount_minor', 'currency', 'accounting_amount_minor', 'accounting_currency', 'supporter_profile_id', 'sponsorship_program_id'];

    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (SponsorshipContribution $c) {
            $c->public_id ??= (string) Str::ulid();
        });

        static::updating(function (SponsorshipContribution $c) {
            $was = (string) $c->getOriginal('status');

            if ($c->isDirty('status') && ! in_array((string) $c->status, self::TRANSITIONS[$was] ?? [], true)) {
                throw new SponsorshipException('انتقال حالة غير مسموح لهذه المساهمة.');
            }

            if ($was !== self::STATUS_PENDING) {
                foreach (self::FINANCIAL_FIELDS as $field) {
                    if ($c->isDirty($field)) {
                        throw new SponsorshipException('الحقول المالية لمساهمة غير معلّقة محمية؛ صحّح بالاسترداد ثم سجّل مساهمة جديدة.');
                    }
                }
            }
        });

        static::deleting(function () {
            throw new SponsorshipException('لا تُحذف المساهمات؛ التاريخ المالي يبقى (إلغاء أو استرداد بدل الحذف).');
        });
    }

    protected function casts(): array
    {
        return [
            'original_amount_minor' => 'integer',
            'accounting_amount_minor' => 'integer',
            'is_anonymous' => 'boolean',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(SupporterProfile::class, 'supporter_profile_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}

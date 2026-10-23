<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E39 - طلب دفعة يدوية لمبدع. لا علاقة له بـSponsorshipPayment (الوارد). لا وجهة تحويل ولا بيانات بنكية: وسيلة الدفع وسم فقط.
 * التحولات المسموحة فقط؛ المبلغ والعملة والحساب ثابتة؛ open_account_id يُشتقّ آليًا من الحالة (مفتوح ⇒ معرّف الحساب، وإلا NULL)
 * فيصير «طلب مفتوح واحد لكل حساب» قيدًا بقاعدة البيانات؛ المرجع الخارجي لا يُكتب إلا مرة. لا حذف.
 */
class CreatorPayout extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED => 'مطلوبة',
        self::STATUS_UNDER_REVIEW => 'قيد المراجعة',
        self::STATUS_APPROVED => 'معتمدة بانتظار الصرف',
        self::STATUS_PAID => 'مصروفة',
        self::STATUS_REJECTED => 'مرفوضة',
        self::STATUS_CANCELLED => 'ملغاة',
    ];

    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED];

    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_APPROVED => [self::STATUS_PAID, self::STATUS_REJECTED],
        self::STATUS_PAID => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'creator_payouts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'open_account_id' => 'integer',
            'requested_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payout): void {
            $payout->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $payout): void {
            $payout->open_account_id = in_array($payout->status, self::OPEN_STATUSES, true) ? (int) $payout->creator_earning_account_id : null;
        });

        static::updating(function (self $payout): void {
            $original = (string) $payout->getOriginal('status');

            foreach (['public_id', 'creator_earning_account_id', 'creator_profile_id', 'user_id', 'amount_minor', 'currency', 'method_code', 'provider', 'requested_at'] as $immutable) {
                if ($payout->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            if ($payout->isDirty('status') && ! in_array($payout->status, self::TRANSITIONS[$original] ?? [], true)) {
                throw new LogicException("انتقال غير مسموح للدفعة: {$original} ← {$payout->status}.");
            }

            if ($payout->isDirty('external_reference') && $payout->getOriginal('external_reference') !== null) {
                throw new LogicException('المرجع الخارجي لا يُغيَّر بعد تسجيله.');
            }

            if (! in_array($original, self::OPEN_STATUSES, true) && $payout->isDirty()) {
                throw new LogicException('الدفعة المنتهية لا تقبل التعديل.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('طلبات الدفع لا تُحذف.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CreatorEarningAccount::class, 'creator_earning_account_id');
    }

    public function creatorProfile(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}

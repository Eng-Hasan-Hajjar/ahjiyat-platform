<?php

namespace App\Models;

use App\Services\Payments\PaymentException;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * E32 - طلب دعم/دفع. يتتبّع مسار الدفع فقط؛ **المساهمة المؤكَّدة (SponsorshipContribution) هي الوحيدة التي تُحتسب مالًا** (تمويل/مستوى/صدارة/تعزيز).
 * طلب في أي حالة غير verified/refunded لا يؤثر في أي مجموع أو مستوى أو تعزيز.
 *
 * آلة الحالات (7 حالات فقط، لا حالات غامضة):
 *   awaiting_payment → submitted | verified (تحقق مباشر من المالية دون ضغط «حوّلت») | cancelled | expired | rejected
 *   submitted        → verified | rejected | cancelled | awaiting_payment (طلب توضيح)
 *   verified         → refunded
 *   rejected | cancelled | expired | refunded → (نهائية)
 * الحقول المالية/الهوية (المبلغ/العملة/البرنامج/المستخدم/المزوّد/المرجع) لا تتغير بعد الإنشاء إطلاقًا؛ ربط المساهمة مرة واحدة فقط (null → قيمة) ولا يتغير.
 * لا حذف أبدًا. لا تعيين جماعي ($guarded): الكتابة عبر SponsorshipPaymentService حصرًا (قفل + تدقيق).
 */
class SponsorshipPayment extends Model
{
    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_AWAITING, self::STATUS_SUBMITTED, self::STATUS_VERIFIED, self::STATUS_REJECTED,
        self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REFUNDED,
    ];

    /** مفتوحة = تنتظر إجراءً (تُحتسب في سقف الطلبات المفتوحة لكل مستخدم). */
    public const OPEN_STATUSES = [self::STATUS_AWAITING, self::STATUS_SUBMITTED];

    /** رموز سبب الرفض المسموحة (قائمة بيضاء؛ لا نص حر في العمود). */
    public const FAILURE_CODES = ['amount_mismatch', 'reference_missing', 'proof_unreadable', 'not_received', 'duplicate', 'suspected_fraud', 'other'];

    /** @var list<string> حقول لا تتغير بعد الإنشاء. */
    public const IMMUTABLE_FIELDS = ['public_id', 'reference', 'sponsorship_program_id', 'user_id', 'provider', 'amount_minor', 'currency', 'is_anonymous'];

    private const TRANSITIONS = [
        self::STATUS_AWAITING => [self::STATUS_SUBMITTED, self::STATUS_VERIFIED, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REJECTED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_AWAITING],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDED],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_REFUNDED => [],
    ];

    protected $guarded = ['*'];

    /** حقول داخلية لا تُسلسَل أبدًا (JSON/API/عرض). */
    protected $hidden = ['review_note', 'proof_path', 'proof_sha256', 'provider_reference', 'request_key', 'failure_code'];

    protected static function booted(): void
    {
        static::creating(function (SponsorshipPayment $p) {
            $p->public_id ??= (string) Str::ulid();
        });

        static::updating(function (SponsorshipPayment $p) {
            $was = (string) $p->getOriginal('status');

            if ($p->isDirty('status') && ! in_array((string) $p->status, self::TRANSITIONS[$was] ?? [], true)) {
                throw new PaymentException('انتقال حالة غير مسموح لطلب الدعم هذا.');
            }

            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($p->isDirty($field)) {
                    throw new PaymentException('حقول الطلب الأساسية محمية؛ أنشئ طلبًا جديدًا.');
                }
            }

            // الربط بالمساهمة مرة واحدة: null → قيمة فقط.
            if ($p->isDirty('sponsorship_contribution_id') && $p->getOriginal('sponsorship_contribution_id') !== null) {
                throw new PaymentException('ربط المساهمة لا يتغير بعد تحديده.');
            }

            // المعادل المحاسبي يُسجَّل مرة عند التحقق ولا يُعدَّل بعده.
            if (($p->isDirty('accounting_amount_minor') || $p->isDirty('accounting_currency')) && $p->getOriginal('accounting_amount_minor') !== null) {
                throw new PaymentException('المعادل المحاسبي محمي بعد التحقق.');
            }
        });

        static::deleting(function () {
            throw new PaymentException('لا تُحذف طلبات الدعم؛ التاريخ المالي يبقى (إلغاء/رفض/انتهاء/استرداد بدل الحذف).');
        });
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'accounting_amount_minor' => 'integer',
            'proof_size' => 'integer',
            'is_anonymous' => 'boolean',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'clarification_requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
            'proof_uploaded_at' => 'datetime',
            'proof_purged_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supporter(): BelongsTo
    {
        return $this->belongsTo(SupporterProfile::class, 'supporter_profile_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(SponsorshipContribution::class, 'sponsorship_contribution_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->getModel()->getTable().'.status', self::OPEN_STATUSES);
    }

    public function isStatus(string ...$statuses): bool
    {
        return in_array($this->status, $statuses, true);
    }

    public function isOpen(): bool
    {
        return $this->isStatus(...self::OPEN_STATUSES);
    }

    public function hasProof(): bool
    {
        return $this->proof_path !== null && $this->proof_purged_at === null;
    }

    /** المبلغ الأصلي منسَّقًا للعرض (لا وحدات صغرى خام في الواجهات). */
    public function amountLabel(): string
    {
        return SponsorshipMoney::format((int) $this->amount_minor, (string) $this->currency);
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_AWAITING => 'بانتظار الدفع',
            self::STATUS_SUBMITTED => 'بانتظار التحقق',
            self::STATUS_VERIFIED => 'تم التحقق',
            self::STATUS_REJECTED => 'مرفوض',
            self::STATUS_CANCELLED => 'ملغى',
            self::STATUS_EXPIRED => 'منتهي',
            self::STATUS_REFUNDED => 'مُسترد',
        ];
    }

    /** @return array<string, string> */
    public static function failureLabels(): array
    {
        return [
            'amount_mismatch' => 'المبلغ المحوَّل لا يطابق الطلب',
            'reference_missing' => 'رمز المرجع غير ظاهر في التحويل',
            'proof_unreadable' => 'الإثبات غير واضح أو غير مقروء',
            'not_received' => 'لم يصلنا التحويل',
            'duplicate' => 'طلب مكرر',
            'suspected_fraud' => 'اشتباه بعملية غير سليمة',
            'other' => 'سبب آخر',
        ];
    }
}

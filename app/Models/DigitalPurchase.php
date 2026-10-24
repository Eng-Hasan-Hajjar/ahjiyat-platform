<?php

namespace App\Models;

use App\Services\Commerce\CommerceProviderState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * E40 - شراء رقمي استهلاكي (المسار المميز). **ليس** SponsorshipPayment (وارد الرعاية) ولا StorePurchase (محفظة العملات الداخلية).
 * لقطات المنتج والمبلغ والعملة ثابتة؛ الحالات بتحولات مسموحة فقط؛ open_key يُشتقّ آليًا من الحالة (مفتوح/محقَّق ⇒ «type:product:user»، وإلا NULL) فيصير
 * «طلب مفتوح/محقَّق واحد لكل مستخدم/منتج» قيدًا بقاعدة البيانات. الصفوف لا تُحذف. $guarded=['*']: لا mass assignment (الكتابة من PassPremiumService حصرًا).
 * لا رقم بطاقة ولا CVV ولا PIN ولا OTP ولا كلمة مرور بنكية في أي عمود (انظر الترحيل).
 *
 * E41: يبقى **سلطة** الشراء الاستهلاكي. أعمدة المزوّد (provider_session_id/payment_reference/refund_reference/provider_status) بيانات تعريف خارجية فقط:
 *  - المراجع ثابتة متى تأسست (استثناء مضبوط: استبدال الجلسة المنتهية يرفع checkout_generation في الحفظ نفسه).
 *  - provider_status مطبَّعة وتتقدّم صعودًا فقط (الحدث القديم لا يُرجعها).
 *  - review_flag علم تشغيلي لا حالة دفع. الحالات الطرفية (rejected/cancelled/expired/refunded) تبقى طرفية: الدفعة المتأخرة عليها تُعلَّم للمراجعة ولا تُحيي الطلب.
 *  - انتقال جديد وحيد: awaiting_payment ← verified (دفع مستضاف مؤكَّد من المزوّد عبر مسار التحقق الموحَّد). اليدوي يمرّ بـsubmitted كما في E40.
 */
class DigitalPurchase extends Model
{
    public const PRODUCT_PASS_PREMIUM = 'ahjiyat_pass_premium';

    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_AWAITING => 'بانتظار الدفع',
        self::STATUS_SUBMITTED => 'قيد التحقق',
        self::STATUS_VERIFIED => 'مؤكَّد',
        self::STATUS_REJECTED => 'مرفوض',
        self::STATUS_CANCELLED => 'ملغى',
        self::STATUS_EXPIRED => 'منتهي الصلاحية',
        self::STATUS_REFUNDED => 'مسترد',
    ];

    /** حالات تُبقي open_key (مفتوح أو محقَّق). */
    public const KEYED_STATUSES = [self::STATUS_AWAITING, self::STATUS_SUBMITTED, self::STATUS_VERIFIED];

    public const OPEN_STATUSES = [self::STATUS_AWAITING, self::STATUS_SUBMITTED];

    public const TRANSITIONS = [
        self::STATUS_AWAITING => [self::STATUS_SUBMITTED, self::STATUS_VERIFIED, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REJECTED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDED],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_REFUNDED => [],
    ];

    /** أسباب علم المراجعة المالية (review_flag). نصوص عربية للإدارة؛ المشتري يرى رسالة محايدة فقط. */
    public const REVIEW_FLAGS = [
        'amount_mismatch' => 'مبلغ الدفع لا يطابق سعر الطلب',
        'currency_mismatch' => 'عملة الدفع لا تطابق عملة الطلب',
        'reference_conflict' => 'مرجع المزوّد لا يطابق جلسة الطلب',
        'late_paid_cancelled' => 'وصلت دفعة بعد إلغاء الطلب',
        'late_paid_expired' => 'وصلت دفعة بعد انتهاء الطلب',
        'late_paid_rejected' => 'وصلت دفعة بعد رفض الطلب',
        'paid_after_refund' => 'وصلت دفعة بعد استرداد الطلب',
        'duplicate_payment' => 'دفعة ثانية لطلب محقَّق',
        'fulfillment_blocked' => 'تعذّر فتح المنتج رغم تأكّد الدفع',
        'refund_claimed_items' => 'استرداد من المزوّد بعد استلام مكافآت مميزة',
        'refund_unmatched' => 'استرداد من المزوّد لا يطابق حالة الطلب',
        'provider_status_conflict' => 'حالة المزوّد لا تطابق حالة الطلب',
    ];

    public const REVIEW_RESOLUTIONS = ['dismissed', 'refunded', 'verified'];

    protected $table = 'digital_purchases';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'product_id' => 'integer',
            'proof_size' => 'integer',
            'refund_kept_items' => 'integer',
            'checkout_generation' => 'integer',
            'refund_attempts' => 'integer',
            'refund_claims_confirmed' => 'boolean',
            'checkout_created_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'provider_paid_at' => 'datetime',
            'provider_last_synced_at' => 'datetime',
            'refund_requested_at' => 'datetime',
            'review_flagged_at' => 'datetime',
            'review_resolved_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'proof_uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchase): void {
            $purchase->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $purchase): void {
            $purchase->open_key = in_array($purchase->status, self::KEYED_STATUSES, true)
                ? $purchase->product_type.':'.$purchase->product_id.':'.$purchase->user_id
                : null;
        });

        static::updating(function (self $purchase): void {
            foreach (['public_id', 'user_id', 'product_type', 'product_id', 'product_public_id', 'product_name', 'amount_minor', 'currency', 'provider', 'reference_code', 'request_key'] as $immutable) {
                if ($purchase->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            // E41: مراجع المزوّد ثابتة متى تأسست. الاستثناء الوحيد: استبدال الجلسة (جلسة منتهية) يرفع checkout_generation في الحفظ نفسه.
            foreach (['provider_payment_reference', 'provider_refund_reference'] as $reference) {
                if ($purchase->isDirty($reference) && $purchase->getOriginal($reference) !== null) {
                    throw new LogicException("العمود {$reference} ثابت.");
                }
            }

            $replacing = $purchase->isDirty('checkout_generation') && (int) $purchase->checkout_generation > (int) $purchase->getOriginal('checkout_generation');

            if ($purchase->isDirty('provider_session_id') && $purchase->getOriginal('provider_session_id') !== null && ! $replacing) {
                throw new LogicException('العمود provider_session_id ثابت.');
            }

            if ($purchase->isDirty('checkout_generation') && ! $replacing) {
                throw new LogicException('جيل الجلسة يتقدّم فقط.');
            }

            if ($purchase->isDirty('provider_status')) {
                $from = CommerceProviderState::tryFrom((string) $purchase->getOriginal('provider_status'));
                $to = CommerceProviderState::tryFrom((string) $purchase->provider_status);

                // الاستثناء الوحيد: جلسة جديدة (checkout_generation يرتفع في الحفظ نفسه) بعد فشل/إلغاء/انتظار الجلسة السابقة تبدأ دورة مزوّد جديدة. المدفوع/المسترد لا يتراجع أبدًا.
                $freshSession = $replacing && $from !== null && in_array($from, [CommerceProviderState::Pending, CommerceProviderState::Failed, CommerceProviderState::Cancelled], true);

                if ($to === null || ($from !== null && $to->rank() < $from->rank() && ! $freshSession)) {
                    throw new LogicException('حالة المزوّد لا تتراجع.');
                }
            }

            $original = (string) $purchase->getOriginal('status');

            if ($purchase->isDirty('status') && ! in_array($purchase->status, self::TRANSITIONS[$original] ?? [], true)) {
                throw new LogicException("انتقال غير مسموح للشراء: {$original} ← {$purchase->status}.");
            }

            if ($purchase->isDirty('transfer_reference') && $purchase->getOriginal('transfer_reference') !== null && $original !== self::STATUS_AWAITING) {
                throw new LogicException('مرجع التحويل لا يُغيَّر بعد التقديم.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('المشتريات الرقمية لا تُحذف.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DigitalPurchaseEvent::class)->orderBy('id');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(CommerceProviderEvent::class)->orderBy('id');
    }

    /** شراء عبر مزوّد غير اليدوي (جلسة دفع مستضافة). اليدوي يمرّ بمسار E40 كما هو. */
    public function isHosted(): bool
    {
        return $this->provider !== 'manual';
    }

    /** علم مراجعة مالية قائم لم يُحلّ بعد. */
    public function needsReview(): bool
    {
        return $this->review_flag !== null && $this->review_resolved_at === null;
    }

    public function providerState(): ?CommerceProviderState
    {
        return CommerceProviderState::tryFrom((string) $this->provider_status);
    }

    public function reviewLabel(): ?string
    {
        return $this->review_flag === null ? null : (self::REVIEW_FLAGS[$this->review_flag] ?? $this->review_flag);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function hasProof(): bool
    {
        return filled($this->proof_path);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}

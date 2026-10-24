<?php

namespace App\Models;

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
        self::STATUS_AWAITING => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REJECTED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDED],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_REFUNDED => [],
    ];

    protected $table = 'digital_purchases';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'product_id' => 'integer',
            'proof_size' => 'integer',
            'refund_kept_items' => 'integer',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E44 - بطاقة داعم موسمية = طلب دفع E32 واحد (sponsorship_payment_id فريد) + **لقطة ثابتة** للسعر والنقاط والنمط وقت الطلب.
 *
 * pending → active (يتحقق الدفع) | void (رفض/إلغاء/انتهاء)؛ active → refunded. لا بطاقة تمنح شيئًا قبل التحقق: رفع الإثبات لا يمنح نقاطًا ولا مستوى ولا نمطًا.
 * مستوى اللاعب الحالي = أعلى بطاقة active (مشتق في SeasonSupporterCardService)؛ نقاط كل بطاقة نشطة تُحتسب من لقطتها هي. لا حذف أبدًا. $guarded=['*'].
 */
class SeasonSupporterCard extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_VOID = 'void';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_VOID, self::STATUS_REFUNDED];

    /** مفتوحة = تنتظر دفعًا/تحققًا (تُحتسب في سقف الطلبات المفتوحة). */
    public const OPEN_STATUSES = [self::STATUS_PENDING];

    public const SNAPSHOT_FIELDS = ['public_id', 'season_id', 'package_id', 'user_id', 'sponsorship_payment_id', 'tier_key', 'price_minor', 'currency', 'support_points', 'name_style_key', 'social_mention_eligible', 'is_anonymous'];

    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_ACTIVE, self::STATUS_VOID],
        self::STATUS_ACTIVE => [self::STATUS_REFUNDED],
        self::STATUS_VOID => [],
        self::STATUS_REFUNDED => [],
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'support_points' => 'integer',
            'social_mention_eligible' => 'boolean',
            'is_anonymous' => 'boolean',
            'activated_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c): void {
            $c->public_id ??= (string) Str::ulid();
        });

        static::updating(function (self $c): void {
            foreach (self::SNAPSHOT_FIELDS as $field) {
                if ($c->isDirty($field)) {
                    throw new LogicException("العمود {$field} ثابت (لقطة وقت الطلب).");
                }
            }

            if ($c->isDirty('status') && ! in_array((string) $c->status, self::TRANSITIONS[(string) $c->getOriginal('status')] ?? [], true)) {
                throw new LogicException('انتقال حالة غير مسموح لبطاقة الداعم.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('بطاقات الداعم لا تُحذف؛ التاريخ يبقى (إلغاء/استرداد بدل الحذف).');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SeasonSupporterPackage::class, 'package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SponsorshipPayment::class, 'sponsorship_payment_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E43 - عرض بيع سلسلة مبدع: الحالة التجارية (حالة/سعر/عملة) لسلسلة مميزة، بدورة حياة منفصلة عن محتوى السلسلة ونسخها. المحتوى يبقى سلطة E30، والدفع سلطة E41، والمال سلطة E39.
 *
 * - الحالات مجموعة مغلقة بانتقالات مسموحة فقط؛ retired نهائية (يُنشأ بعدها عرض جديد إن لزم).
 * - live_series_id مشتق آليًا (= creator_series_id لغير المسحوب وإلا NULL) فيصير «عرض حيّ واحد لكل سلسلة» قيدًا بقاعدة البيانات.
 * - first_activated_at يُكتب مرة واحدة ولا يتغير (حدّ التجميد المتقادم للأعمال)، والعملة تتجمد بعده. السعر يتغير بخدمة العروض فقط ويسري على المستقبل.
 * - $guarded=['*']: لا mass assignment (الكتابة من CreatorSeriesOfferService حصرًا). لا حذف أبدًا.
 */
class CreatorSeriesSaleOffer extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_ACTIVE => 'نشط',
        self::STATUS_PAUSED => 'موقوف',
        self::STATUS_RETIRED => 'مسحوب',
    ];

    /** عرض «حيّ» (يحجز موضع السلسلة الوحيد). */
    public const LIVE_STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED];

    /** حالات تجعل المحتوى مميزًا (محميًا بالدفع): النشط والموقوف. الموقوف لا يبيع لكنه يبقى مميزًا فلا يتسرّب المحتوى بإيقاف البيع. */
    public const LOCKING_STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAUSED];

    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_RETIRED],
        self::STATUS_ACTIVE => [self::STATUS_PAUSED, self::STATUS_RETIRED],
        self::STATUS_PAUSED => [self::STATUS_ACTIVE, self::STATUS_RETIRED],
        self::STATUS_RETIRED => [],
    ];

    public const PAUSE_CREATOR = 'creator';

    public const PAUSE_ADMIN = 'admin';

    public const PAUSE_MODERATION = 'moderation';

    public const PAUSE_REASONS = [
        self::PAUSE_CREATOR => 'أوقفه المبدع',
        self::PAUSE_ADMIN => 'أوقفته الإدارة',
        self::PAUSE_MODERATION => 'إيقاف إشرافي',
    ];

    protected $table = 'creator_series_sale_offers';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'live_series_id' => 'integer',
            'first_activated_at' => 'datetime',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'retired_at' => 'datetime',
            'price_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            $offer->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $offer): void {
            $offer->live_series_id = in_array($offer->status, self::LIVE_STATUSES, true) ? (int) $offer->creator_series_id : null;
        });

        static::updating(function (self $offer): void {
            foreach (['public_id', 'creator_series_id', 'creator_profile_id'] as $immutable) {
                if ($offer->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            if ($offer->isDirty('first_activated_at') && $offer->getOriginal('first_activated_at') !== null) {
                throw new LogicException('وقت أول تفعيل يُكتب مرة واحدة.');
            }

            if ($offer->isDirty('currency') && $offer->getOriginal('first_activated_at') !== null) {
                throw new LogicException('عملة العرض تتجمد بعد أول تفعيل.');
            }

            $original = (string) $offer->getOriginal('status');

            if ($offer->isDirty('status') && ! in_array($offer->status, self::TRANSITIONS[$original] ?? [], true)) {
                throw new LogicException("انتقال غير مسموح للعرض: {$original} ← {$offer->status}.");
            }
        });

        static::deleting(function (): void {
            throw new LogicException('عروض البيع لا تُحذف؛ تُسحب (retired) وتبقى تاريخًا.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isRetired(): bool
    {
        return $this->status === self::STATUS_RETIRED;
    }

    /** يجعل محتوى السلسلة مميزًا (نشط أو موقوف). */
    public function isLocking(): bool
    {
        return in_array($this->status, self::LOCKING_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E43 - البيع المباشر: اللقطة المحاسبية الثابتة التي تربط شراء E41 (DigitalPurchase) بالمبدع والسلسلة والإجمالي وحصتي المبدع والمنصة وقيد دفتر E39. صف واحد لكل شراء (قيد فريد).
 *
 * - كل الأعمدة التجارية ثابتة بعد الإنشاء (لقطة وقت التحقق). المتغير الوحيد: حالة الاسترداد والمراجعة (status وreversal_entry_id وrefunded_at وأعمدة review_*).
 * - credited ← refunded | needs_finance_review ؛ needs_finance_review ← refunded | written_off ؛ refunded وwritten_off نهائيتان.
 * - needs_finance_review: المشتري استُردّ فعلًا عند المزوّد لكن عكس حصة المبدع غير آمن (دُفعت أو لا يكفي المتاح): لا رصيد سالب، لا حذف، لا مصادرة آلية، وتبقى الحالة موثَّقة حتى قرار بشري.
 * - $guarded=['*']. لا حذف أبدًا. لا بيانات مشترٍ في الصف.
 */
class CreatorDirectSale extends Model
{
    public const STATUS_CREDITED = 'credited';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_NEEDS_REVIEW = 'needs_finance_review';

    public const STATUS_WRITTEN_OFF = 'written_off';

    public const STATUSES = [
        self::STATUS_CREDITED => 'مُسجَّل للمبدع',
        self::STATUS_REFUNDED => 'مسترد ومعكوس',
        self::STATUS_NEEDS_REVIEW => 'يحتاج مراجعة مالية',
        self::STATUS_WRITTEN_OFF => 'استرداد تتحمّله المنصة',
    ];

    public const TRANSITIONS = [
        self::STATUS_CREDITED => [self::STATUS_REFUNDED, self::STATUS_NEEDS_REVIEW],
        self::STATUS_NEEDS_REVIEW => [self::STATUS_REFUNDED, self::STATUS_WRITTEN_OFF],
        self::STATUS_REFUNDED => [],
        self::STATUS_WRITTEN_OFF => [],
    ];

    public const ROUNDING_CREATOR_FLOOR = 'creator_floor';

    public const REVIEW_REVERSAL_BLOCKED = 'reversal_blocked';

    public const RESOLUTIONS = ['reversed', 'written_off'];

    private const IMMUTABLE = [
        'public_id', 'digital_purchase_id', 'creator_profile_id', 'user_id', 'creator_series_id', 'creator_series_sale_offer_id', 'creator_earning_account_id',
        'series_public_id', 'series_title', 'creator_display_name', 'gross_minor', 'creator_share_minor', 'platform_share_minor', 'currency',
        'creator_bps', 'platform_bps', 'rounding_rule', 'credit_entry_id', 'sold_at',
    ];

    protected $table = 'creator_direct_sales';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'creator_share_minor' => 'integer',
            'platform_share_minor' => 'integer',
            'creator_bps' => 'integer',
            'platform_bps' => 'integer',
            'sold_at' => 'datetime',
            'refunded_at' => 'datetime',
            'review_flagged_at' => 'datetime',
            'review_resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sale): void {
            $sale->public_id ??= (string) Str::ulid();

            if ((int) $sale->creator_share_minor + (int) $sale->platform_share_minor !== (int) $sale->gross_minor) {
                throw new LogicException('مجموع حصتي المبدع والمنصة يجب أن يساوي الإجمالي.');
            }

            if ((int) $sale->creator_bps + (int) $sale->platform_bps !== 10000) {
                throw new LogicException('مجموع نقاط الأساس يجب أن يساوي 10000.');
            }
        });

        static::updating(function (self $sale): void {
            foreach (self::IMMUTABLE as $column) {
                if ($sale->isDirty($column)) {
                    throw new LogicException("العمود {$column} ثابت.");
                }
            }

            if ($sale->isDirty('reversal_entry_id') && $sale->getOriginal('reversal_entry_id') !== null) {
                throw new LogicException('قيد العكس يُربط مرة واحدة.');
            }

            $original = (string) $sale->getOriginal('status');

            if ($sale->isDirty('status') && ! in_array($sale->status, self::TRANSITIONS[$original] ?? [], true)) {
                throw new LogicException("انتقال غير مسموح للبيع المباشر: {$original} ← {$sale->status}.");
            }
        });

        static::deleting(function (): void {
            throw new LogicException('سجلات البيع المباشر لا تُحذف.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(DigitalPurchase::class, 'digital_purchase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(CreatorSeriesSaleOffer::class, 'creator_series_sale_offer_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CreatorEarningAccount::class, 'creator_earning_account_id');
    }

    public function creditEntry(): BelongsTo
    {
        return $this->belongsTo(CreatorEarningEntry::class, 'credit_entry_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(CreatorEarningEntry::class, 'reversal_entry_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function needsReview(): bool
    {
        return $this->status === self::STATUS_NEEDS_REVIEW;
    }

    /** حصة المبدع الصافية الباقية له بعد الاسترداد المعكوس فقط (المعلَّق للمراجعة أو المتحمَّل من المنصة يُعدّ باقيًا للمبدع). */
    public function netCreatorMinor(): int
    {
        return $this->status === self::STATUS_REFUNDED ? 0 : (int) $this->creator_share_minor;
    }
}

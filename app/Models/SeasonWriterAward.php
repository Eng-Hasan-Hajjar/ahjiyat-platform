<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E44 - قرار جائزة لكاتب بعد إنهاء الترتيب. يُنشأ من **لقطة خطة الجوائز** في الإنهاء (فرادة (writer_entry_id, plan_index) ⇒ الإنهاء/الإعادة لا تضاعف).
 *
 * - cosmetic: تُمنح بخدمة الاستحقاقات القائمة (EntitlementService) بمفتاح مصدر ثابت؛ status planned → granted | failed.
 * - monetary: **التزام قابل للتدقيق فقط** (amount_minor + currency). لا دفع آلي ولا تحويل عملات ولا مساس برصيد المبدع: status planned → pending_finance → settled | cancelled،
 *   والتسوية تسجيل يدوي بمرجع من مستخدم بصلاحية seasonal-recognition.award (لا قيد دفتر E39 تلقائي).
 * - Writer Stars وحدها لا تنتج أرباحًا أبدًا. المبلغ والعملة ثابتان بعد الإنشاء. لا حذف.
 */
class SeasonWriterAward extends Model
{
    public const KIND_COSMETIC = 'cosmetic';

    public const KIND_MONETARY = 'monetary';

    public const KINDS = [self::KIND_COSMETIC, self::KIND_MONETARY];

    public const STATUS_PLANNED = 'planned';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_PENDING_FINANCE = 'pending_finance';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PLANNED, self::STATUS_GRANTED, self::STATUS_PENDING_FINANCE, self::STATUS_SETTLED, self::STATUS_CANCELLED, self::STATUS_FAILED];

    public const PLACEMENTS = [SeasonWriterEntry::OUTCOME_FIRST, SeasonWriterEntry::OUTCOME_SECOND, SeasonWriterEntry::OUTCOME_THIRD];

    public const IMMUTABLE_FIELDS = ['public_id', 'season_id', 'writer_entry_id', 'user_id', 'plan_index', 'placement', 'kind', 'label', 'store_item_id', 'amount_minor', 'currency'];

    private const TRANSITIONS = [
        self::STATUS_PLANNED => [self::STATUS_GRANTED, self::STATUS_PENDING_FINANCE, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_FAILED => [self::STATUS_GRANTED, self::STATUS_CANCELLED],
        self::STATUS_PENDING_FINANCE => [self::STATUS_SETTLED, self::STATUS_CANCELLED],
        self::STATUS_GRANTED => [],
        self::STATUS_SETTLED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'plan_index' => 'integer',
            'amount_minor' => 'integer',
            'granted_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a): void {
            $a->public_id ??= (string) Str::ulid();

            if (! in_array($a->kind, self::KINDS, true) || ! in_array($a->placement, self::PLACEMENTS, true)) {
                throw new LogicException('نوع الجائزة أو المركز غير مسموح.');
            }

            if ($a->kind === self::KIND_MONETARY && ((int) $a->amount_minor <= 0 || (string) $a->currency === '')) {
                throw new LogicException('الجائزة المالية تحتاج مبلغًا موجبًا بالوحدة الصغرى وعملة.');
            }

            if ($a->kind === self::KIND_COSMETIC && ($a->amount_minor !== null || $a->currency !== null)) {
                throw new LogicException('الجائزة التجميلية لا تحمل مبلغًا.');
            }
        });

        static::updating(function (self $a): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($a->isDirty($field)) {
                    throw new LogicException("العمود {$field} ثابت.");
                }
            }

            if ($a->isDirty('status') && ! in_array((string) $a->status, self::TRANSITIONS[(string) $a->getOriginal('status')] ?? [], true)) {
                throw new LogicException('انتقال حالة غير مسموح لقرار الجائزة.');
            }

            // التسوية اليدوية تتطلب كلاً من المنفِّذ والمرجع (أثر تدقيق كامل).
            if ($a->isDirty('status') && $a->status === self::STATUS_SETTLED && ($a->settled_by === null || trim((string) $a->settlement_ref) === '')) {
                throw new LogicException('تسوية الالتزام المالي تتطلب منفِّذًا ومرجعًا.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('قرارات الجوائز لا تُحذف؛ تُلغى بحالتها.');
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

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SeasonWriterEntry::class, 'writer_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isMonetary(): bool
    {
        return $this->kind === self::KIND_MONETARY;
    }
}

<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * E40 - مسار الأحجيات: منتج تقدّم دوري (مجاني + مميز) **منفصل تمامًا عن الموسم** (لا season_id ولا أي ربط بدورة حياته).
 *
 * الحالة المخزَّنة draft|scheduled|active|ended|archived؛ **الحالة الفعلية تُشتقّ من التواريخ** (effectiveStatus) فلا نثق بحقل يدوي حين تكون التواريخ هي السلطة.
 * الفترة نصف مفتوحة [starts_at, ends_at): لا نقاط جديدة عند ends_at أو بعدها. المطالبة تنتهي عند claim_ends_at.
 * live_slot = 1 فقط للمخزَّن active (يُشتقّ آليًا عند الحفظ) وunique في القاعدة: مسار فعّال واحد عالميًا.
 *
 * الحقول الحرجة (التواريخ، مهلة المطالبة، السعر والعملة، المفتاح الداخلي) **تُقفل بالنشر** (locked_at): أي تعديل لها بعد القفل يُرفض هنا حتى لو تجاوز أحدهم الخدمة.
 * الاستثناء الوحيد المقصود: PassLifecycleService::end يُنهي المسار مبكرًا عبر unlocking([...]). لا حذف لمسار نُشر أبدًا (المسودة غير المنشورة فقط).
 * $guarded=['*']: لا mass assignment؛ الكتابة بـforceFill من الخدمات حصرًا.
 */
class AhjiyatPass extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_SCHEDULED => 'مجدول',
        self::STATUS_ACTIVE => 'فعّال',
        self::STATUS_ENDED => 'منتهٍ',
        self::STATUS_ARCHIVED => 'مؤرشف',
    ];

    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SCHEDULED],
        self::STATUS_SCHEDULED => [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ENDED],
        self::STATUS_ACTIVE => [self::STATUS_ENDED],
        self::STATUS_ENDED => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
    ];

    public const LIVE_SLOT = 1;

    /** حقول تُقفل بالنشر. @var list<string> */
    public const LOCKED_FIELDS = ['internal_key', 'starts_at', 'ends_at', 'claim_grace_days', 'claim_ends_at', 'premium_enabled', 'premium_price_minor', 'currency'];

    protected $table = 'ahjiyat_passes';

    protected $guarded = ['*'];

    /** @var list<string> */
    protected array $unlockedFields = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'claim_ends_at' => 'datetime',
            'premium_sales_start_at' => 'datetime',
            'premium_sales_end_at' => 'datetime',
            'published_at' => 'datetime',
            'locked_at' => 'datetime',
            'ended_at' => 'datetime',
            'archived_at' => 'datetime',
            'premium_enabled' => 'boolean',
            'premium_price_minor' => 'integer',
            'claim_grace_days' => 'integer',
            'total_tiers' => 'integer',
            'live_slot' => 'integer',
            'is_featured' => 'boolean',
            'theme_config' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pass): void {
            $pass->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $pass): void {
            $pass->live_slot = $pass->status === self::STATUS_ACTIVE ? self::LIVE_SLOT : null;
        });

        static::updating(function (self $pass): void {
            if ($pass->isDirty('public_id')) {
                throw new LogicException('المعرّف العام للمسار ثابت.');
            }

            $original = (string) $pass->getOriginal('status');

            if ($pass->isDirty('status') && ! in_array($pass->status, self::TRANSITIONS[$original] ?? [], true)) {
                throw new LogicException("انتقال غير مسموح للمسار: {$original} ← {$pass->status}.");
            }

            if ($pass->getOriginal('locked_at') !== null) {
                foreach (self::LOCKED_FIELDS as $field) {
                    if ($pass->isDirty($field) && ! in_array($field, $pass->unlockedFields, true)) {
                        throw new LogicException("الحقل {$field} مقفل بعد نشر المسار.");
                    }
                }
            }
        });

        static::deleting(function (self $pass): void {
            if ($pass->locked_at !== null || $pass->status !== self::STATUS_DRAFT) {
                throw new LogicException('المسار المنشور لا يُحذف (أرشِفه).');
            }
        });
    }

    /**
     * يسمح بتعديل حقول مقفلة محدّدة داخل الدالة فقط (للخدمات المخوّلة: إنهاء مبكر). يعيد القفل دائمًا ولو رُمي استثناء.
     *
     * @param  list<string>  $fields
     */
    public function unlocking(array $fields, Closure $callback): mixed
    {
        $previous = $this->unlockedFields;
        $this->unlockedFields = $fields;

        try {
            return $callback($this);
        } finally {
            $this->unlockedFields = $previous;
        }
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(AhjiyatPassTier::class, 'ahjiyat_pass_id')->orderBy('tier_number');
    }

    public function tierRewards(): HasMany
    {
        return $this->hasMany(AhjiyatPassTierReward::class, 'ahjiyat_pass_id');
    }

    public function missions(): HasMany
    {
        return $this->hasMany(AhjiyatPassMission::class, 'ahjiyat_pass_id')->orderBy('sort_order')->orderBy('id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserPassProgress::class, 'ahjiyat_pass_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** الحالة الفعلية بالتواريخ: المسودة والمؤرشف صريحان؛ غيرهما يُشتقّ من الفترة نصف المفتوحة. */
    public function effectiveStatus(?CarbonInterface $now = null): string
    {
        $now ??= now();

        if (in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ARCHIVED], true)) {
            return $this->status;
        }

        return match (true) {
            $now->lt($this->starts_at) => self::STATUS_SCHEDULED,
            $now->lt($this->ends_at) => self::STATUS_ACTIVE,
            default => self::STATUS_ENDED,
        };
    }

    public function isActiveNow(?CarbonInterface $now = null): bool
    {
        return $this->effectiveStatus($now) === self::STATUS_ACTIVE;
    }

    /** يظهر للاعبين؟ الفعّال والمنتهي والمؤرشف فقط (المسودة والمجدول لا يظهران). */
    public function isPubliclyVisible(?CarbonInterface $now = null): bool
    {
        return in_array($this->effectiveStatus($now), [self::STATUS_ACTIVE, self::STATUS_ENDED, self::STATUS_ARCHIVED], true);
    }

    /** نافذة الاستلام: من البدء حتى claim_ends_at (حصرًا). المؤرشف مغلق. */
    public function isClaimWindowOpen(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->locked_at !== null
            && $this->status !== self::STATUS_ARCHIVED
            && $this->status !== self::STATUS_DRAFT
            && $now->gte($this->starts_at)
            && $now->lt($this->claim_ends_at);
    }

    /** مبيعات المميز مفتوحة الآن؟ (مفعّل + سعر + ضمن نافذة البيع + المسار فعّال، ولا بيع بعد انتهاء المسار). */
    public function isPremiumSalesOpen(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if (! $this->premium_enabled || $this->premium_price_minor === null || $this->premium_price_minor <= 0 || blank($this->currency)) {
            return false;
        }

        if (! $this->isActiveNow($now)) {
            return false;
        }

        if ($this->premium_sales_start_at !== null && $now->lt($this->premium_sales_start_at)) {
            return false;
        }

        if ($this->premium_sales_end_at !== null && $now->gte($this->premium_sales_end_at)) {
            return false;
        }

        return true;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->effectiveStatus()] ?? $this->status;
    }

    public function bannerUrl(): ?string
    {
        if (blank($this->banner_path)) {
            return null;
        }

        return Storage::disk((string) config('ahjiyat_pass.banner.disk', 'public'))->url($this->banner_path);
    }

    public function themeAccent(): string
    {
        $accent = (string) ($this->theme_config['accent'] ?? '');

        return in_array($accent, (array) config('ahjiyat_pass.theme.accents', []), true) ? $accent : 'amber';
    }

    public function themeBackground(): string
    {
        $bg = (string) ($this->theme_config['background'] ?? '');

        return in_array($bg, (array) config('ahjiyat_pass.theme.backgrounds', []), true) ? $bg : 'aurora';
    }

    public function tagline(): ?string
    {
        $text = trim((string) ($this->theme_config['tagline'] ?? ''));

        return $text === '' ? null : $text;
    }
}

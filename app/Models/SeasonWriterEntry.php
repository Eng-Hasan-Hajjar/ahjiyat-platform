<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * E44 - عضوية كاتب في قائمة موسم: ملف مبدع معتمد (CreatorProfile) اختير لموسم بعينه. **حالة مشاركة موسمية لا دور عام**؛ لا هوية مبدع ثانية.
 *
 * - (season_id, creator_profile_id) فريد: يظهر المبدع مرة واحدة في قائمة الموسم. user_id لقطة ثابتة من مالك الملف.
 * - stars_total / voters_count / first_star_at / last_star_at: مجاميع حيّة مخزَّنة تكتبها SeasonWriterVotingService وحدها داخل معاملة التصويت (دفتر التوزيع هو السلطة؛ reconcile يعيد البناء).
 * - final_* / outcome / finalized_at: لقطة يكتبها SeasonWriterFinalizer وحدها ولا تتغيّر بعدها (لا أثر رجعي لأي بيانات لاحقة).
 * - outcome مجموعة مغلقة؛ ineligible_next_season = قاعدة الدوري «لا يُضاف للموسم التالي فقط» وليست إيقافًا ولا حظرًا ولا عقوبة.
 * - $guarded=['*']؛ الحذف محجوب عند وجود نجوم أو إنهاء (الخدمة تحذف قبل فتح التصويت فقط).
 */
class SeasonWriterEntry extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_WITHDRAWN];

    public const OUTCOME_FIRST = 'first_place';

    public const OUTCOME_SECOND = 'second_place';

    public const OUTCOME_THIRD = 'third_place';

    public const OUTCOME_PARTICIPANT = 'participant';

    public const OUTCOME_INELIGIBLE_NEXT = 'ineligible_next_season';

    public const OUTCOMES = [self::OUTCOME_FIRST, self::OUTCOME_SECOND, self::OUTCOME_THIRD, self::OUTCOME_PARTICIPANT, self::OUTCOME_INELIGIBLE_NEXT];

    /** المراكز الثلاثة الأولى بالترتيب. */
    public const PODIUM = [1 => self::OUTCOME_FIRST, 2 => self::OUTCOME_SECOND, 3 => self::OUTCOME_THIRD];

    public const IMMUTABLE_FIELDS = ['public_id', 'season_id', 'creator_profile_id', 'user_id', 'joined_at'];

    public const FINAL_FIELDS = ['final_rank', 'final_stars', 'final_voters_count', 'outcome', 'finalized_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'first_star_at' => 'datetime',
            'last_star_at' => 'datetime',
            'finalized_at' => 'datetime',
            'stars_total' => 'integer',
            'voters_count' => 'integer',
            'final_rank' => 'integer',
            'final_stars' => 'integer',
            'final_voters_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $e): void {
            $e->public_id ??= (string) Str::ulid();
        });

        static::updating(function (self $e): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($e->isDirty($field)) {
                    throw new LogicException("العمود {$field} ثابت.");
                }
            }

            // اللقطة النهائية تُكتب مرة واحدة ولا تتغير؛ وبعدها لا تُحرَّك المجاميع المخزَّنة (التصويت مغلق).
            if ($e->getOriginal('finalized_at') !== null) {
                foreach (array_merge(self::FINAL_FIELDS, ['stars_total', 'voters_count', 'status']) as $field) {
                    if ($e->isDirty($field)) {
                        throw new LogicException('الترتيب النهائي مجمَّد ولا يتغير.');
                    }
                }
            }
        });

        static::deleting(function (self $e): void {
            if ($e->finalized_at !== null || (int) $e->stars_total > 0 || (int) $e->voters_count > 0) {
                throw new LogicException('كاتب له نجوم أو ترتيب نهائي لا يُحذف؛ يمكن سحبه من القائمة فقط.');
            }
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seriesLinks(): HasMany
    {
        return $this->hasMany(SeasonWriterSeries::class, 'writer_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SeasonWriterStarAllocation::class, 'writer_entry_id');
    }

    public function awards(): HasMany
    {
        return $this->hasMany(SeasonWriterAward::class, 'writer_entry_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }
}

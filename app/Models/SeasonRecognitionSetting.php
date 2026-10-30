<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * E44 - إعدادات التقدير الموسمي: صف واحد لكل موسم يُنشأ كسولًا (دوري الكتّاب + تقدير الداعمين). مصدر الحقيقة لنافذة التصويت وسعة القائمة وخطة الجوائز ومفتاح الموسم.
 *
 * الفعّال = المفتاح العام (config/seasonal_recognition.php) ∧ مفتاح الموسم هنا. إيقاف أيٍّ منهما يمنع الإجراءات **الجديدة** فقط ولا يمسح تاريخًا.
 * القيود الثابتة (سعة 1..100 صلبة، نجوم الناخب 1..10، الإقصاء 0..20، إغلاق ≥ فتح) تُفرض هنا وفي الخدمة؛ قاعدة «الإغلاق قبل النهاية بسبعة أيام» تحتاج نافذة الموسم فتفرضها SeasonWriterSettingsService.
 * بعد الإنهاء تتجمّد إعدادات الدوري كلها؛ لا تعيين جماعي ($guarded) ولا حذف.
 */
class SeasonRecognitionSetting extends Model
{
    /** الحدّ الصلب المطلق لقائمة الكتّاب في موسم واحد (ثابت منتج: لا يُعدَّل بالإعدادات). */
    public const HARD_MAX_CAPACITY = 100;

    public const HARD_MAX_STARS = 10;

    /** حقول الدوري التي تتجمّد بعد الإنهاء. */
    public const WRITER_CONFIG_FIELDS = [
        'writers_enabled', 'writers_capacity', 'writers_voting_opens_at', 'writers_voting_closes_at',
        'writers_stars_per_voter', 'writers_eliminated_count', 'writers_reward_plan', 'previous_season_id',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'writers_enabled' => 'boolean',
            'supporters_enabled' => 'boolean',
            'writers_capacity' => 'integer',
            'writers_stars_per_voter' => 'integer',
            'writers_eliminated_count' => 'integer',
            'writers_voting_opens_at' => 'datetime',
            'writers_voting_closes_at' => 'datetime',
            'writers_finalized_at' => 'datetime',
            'supporters_finalized_at' => 'datetime',
            'writers_reward_plan' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            $maxCapacity = min(self::HARD_MAX_CAPACITY, max(1, (int) config('seasonal_recognition.writers.max_capacity', self::HARD_MAX_CAPACITY)));

            if ((int) $s->writers_capacity < 1 || (int) $s->writers_capacity > $maxCapacity) {
                throw new InvalidArgumentException("سعة قائمة الكتّاب بين 1 و{$maxCapacity} (الحد الأقصى المطلق 100).");
            }

            if ((int) $s->writers_stars_per_voter < 1 || (int) $s->writers_stars_per_voter > self::HARD_MAX_STARS) {
                throw new InvalidArgumentException('نجوم الناخب في الموسم بين 1 و10.');
            }

            $maxEliminated = max(0, (int) config('seasonal_recognition.writers.max_eliminated_count', 20));

            if ((int) $s->writers_eliminated_count < 0 || (int) $s->writers_eliminated_count > $maxEliminated) {
                throw new InvalidArgumentException("عدد المستبعدين بين 0 و{$maxEliminated}.");
            }

            if ($s->writers_voting_opens_at !== null && $s->writers_voting_closes_at !== null && $s->writers_voting_closes_at->lessThanOrEqualTo($s->writers_voting_opens_at)) {
                throw new InvalidArgumentException('إغلاق التصويت يجب أن يكون بعد فتحه.');
            }
        });

        static::updating(function (self $s): void {
            if ($s->isDirty('season_id')) {
                throw new LogicException('موسم الإعدادات ثابت.');
            }

            // الدوري المُنهى لا يتغيّر: لا فتح ولا نافذة ولا خطة جوائز ولا سعة. (علامة الإنهاء نفسها تُكتب مرة واحدة.)
            if ($s->getOriginal('writers_finalized_at') !== null) {
                if ($s->isDirty('writers_finalized_at') || $s->isDirty('writers_finalized_by')) {
                    throw new LogicException('الإنهاء يُسجَّل مرة واحدة فقط.');
                }

                foreach (self::WRITER_CONFIG_FIELDS as $field) {
                    if ($s->isDirty($field)) {
                        throw new LogicException('إعدادات دوري الكتّاب مجمَّدة بعد الإنهاء.');
                    }
                }
            }

            if ($s->getOriginal('supporters_finalized_at') !== null && ($s->isDirty('supporters_finalized_at') || $s->isDirty('supporters_finalized_by'))) {
                throw new LogicException('إنهاء داعمي الموسم يُسجَّل مرة واحدة فقط.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('إعدادات التقدير الموسمي لا تُحذف.');
        });
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function previousSeason(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'previous_season_id');
    }

    public function writersFinalized(): bool
    {
        return $this->writers_finalized_at !== null;
    }

    public function supportersFinalized(): bool
    {
        return $this->supporters_finalized_at !== null;
    }
}

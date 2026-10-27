<?php

namespace App\Models;

use App\Services\Seasons\SeasonCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E42 - رابط محتوى بين الموسم وكيان موجود (حملة إضافية | منافسة | مجموعة مهام). **عرض وتنسيق فقط**: لا نسخ لتقدّم ولا نتائج ولا مكافآت ولا تواريخ.
 * لكل نوع مفتاح أجنبي حقيقي؛ content_type يحدّد أيّها غير فارغ ويُفرض هنا (والفرادة بقيد قاعدة البيانات). $guarded=['*']: الكتابة من SeasonBuilderService حصرًا.
 */
class SeasonContentItem extends Model
{
    public const TYPE_CAMPAIGN = 'campaign';

    public const TYPE_COMPETITIVE_EVENT = 'competitive_event';

    public const TYPE_MISSION_POOL = 'mission_pool';

    /** نوع المحتوى ⇐ عمود المفتاح الأجنبي. */
    public const COLUMNS = [
        self::TYPE_CAMPAIGN => 'campaign_id',
        self::TYPE_COMPETITIVE_EVENT => 'competitive_event_id',
        self::TYPE_MISSION_POOL => 'mission_pool_id',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $column = self::COLUMNS[$item->content_type] ?? null;

            if ($column === null) {
                throw new \InvalidArgumentException('نوع محتوى غير معروف.');
            }

            foreach (self::COLUMNS as $type => $other) {
                $filled = $item->getAttribute($other) !== null;

                if (($other === $column) !== $filled) {
                    throw new \InvalidArgumentException('الرابط يخص كيانًا واحدًا من نوعه فقط.');
                }
            }
        });

        static::updating(function (self $item): void {
            foreach (['season_id', 'content_type', ...array_values(self::COLUMNS)] as $immutable) {
                if ($item->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت: احذف الرابط وأنشئ آخر.");
                }
            }
        });

        static::saved(fn () => SeasonCache::flush());
        static::deleted(fn () => SeasonCache::flush());
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function competitiveEvent(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class);
    }

    public function missionPool(): BelongsTo
    {
        return $this->belongsTo(MissionPool::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(SeasonPhase::class, 'season_phase_id');
    }

    public function targetId(): int
    {
        return (int) $this->getAttribute(self::COLUMNS[$this->content_type]);
    }
}

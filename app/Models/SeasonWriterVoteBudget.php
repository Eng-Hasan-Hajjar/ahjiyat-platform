<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * E44 - ميزانية النجوم الموسمية للناخب: **حساب واحد = ميزانية واحدة لكل موسم** (لا تتضاعف بتعدد الصفات). تُنشأ كسولًا عند أول تصويت صالح؛ لا صفوف مسبقة لملايين المستخدمين.
 *
 * total_stars لقطة وقت الإنشاء؛ spent_stars = مجموع توزيعات الناخب الحالية، ويكتبه SeasonWriterVotingService وحده تحت قفل الصف. القيد 0 ≤ spent ≤ total يُفرض هنا أيضًا كخط دفاع أخير.
 */
class SeasonWriterVoteBudget extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['total_stars' => 'integer', 'spent_stars' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $b): void {
            if ((int) $b->total_stars < 1 || (int) $b->total_stars > SeasonRecognitionSetting::HARD_MAX_STARS) {
                throw new InvalidArgumentException('حجم الميزانية بين 1 و10 نجوم.');
            }

            if ((int) $b->spent_stars < 0 || (int) $b->spent_stars > (int) $b->total_stars) {
                throw new InvalidArgumentException('المنصرف من النجوم خارج الميزانية.');
            }
        });

        static::updating(function (self $b): void {
            if ($b->isDirty('season_id') || $b->isDirty('total_stars')) {
                throw new LogicException('موسم الميزانية وحجمها ثابتان.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('الميزانية الموسمية لا تُحذف.');
        });
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remaining(): int
    {
        return max(0, (int) $this->total_stars - (int) $this->spent_stars);
    }
}

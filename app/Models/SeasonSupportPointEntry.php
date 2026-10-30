<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E44 - دفتر نقاط الدعم (**إلحاق فقط**): السلطة على نقاط التقدير الموسمية. نقاط الدعم ليست مالًا ولا جواهر ولا XP ولا نقاط تمريرة ولا درجة منافسة ولا أرباح مبدع، ولا تُصرف ولا تُحوَّل.
 *
 * - source_key فريد ⇒ منح واحد لكل مصدر (مشتريات المتجر: store_purchase:{id}:grant، البطاقات: supporter_card:{id}:grant).
 * - الاسترداد قيد سالب (reason=reversal) يشير للأصل بـreverses_entry_id الفريد ⇒ عكس واحد بالضبط، ويعاكس **لقطة** الأصل لا الإعداد الحالي. الأصل لا يُحذف ولا يُعدَّل.
 * - is_public لقطة خصوصية وقت المنح: نقاط مصدر مجهول (E30) لا تدخل المجاميع العلنية.
 * - لا تعديل ولا حذف أبدًا (النموذج يرفضهما)؛ التصحيح بقيد جديد فقط.
 */
class SeasonSupportPointEntry extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_STORE_PURCHASE = 'store_purchase';

    public const SOURCE_SUPPORTER_CARD = 'supporter_card';

    public const SOURCES = [self::SOURCE_STORE_PURCHASE, self::SOURCE_SUPPORTER_CARD];

    public const REASON_GRANT = 'grant';

    public const REASON_REVERSAL = 'reversal';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'points_delta' => 'integer',
            'is_public' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public static function grantKey(string $sourceType, int $sourceId): string
    {
        return $sourceType.':'.$sourceId.':grant';
    }

    public static function reversalKey(string $sourceType, int $sourceId): string
    {
        return $sourceType.':'.$sourceId.':reversal';
    }

    protected static function booted(): void
    {
        static::creating(function (self $e): void {
            if (! in_array($e->source_type, self::SOURCES, true)) {
                throw new LogicException('مصدر نقاط الدعم غير مسموح.');
            }

            $max = (int) config('seasonal_recognition.supporters.max_support_points_per_purchase', 1000000);

            if ($e->reason === self::REASON_GRANT) {
                if ((int) $e->points_delta <= 0 || (int) $e->points_delta > $max || $e->reverses_entry_id !== null) {
                    throw new LogicException('منح نقاط الدعم عدد صحيح موجب ضمن السقف ولا يعكس شيئًا.');
                }
            } elseif ($e->reason === self::REASON_REVERSAL) {
                if ((int) $e->points_delta >= 0 || $e->reverses_entry_id === null) {
                    throw new LogicException('عكس نقاط الدعم قيد سالب مرتبط بالأصل.');
                }
            } else {
                throw new LogicException('سبب قيد نقاط الدعم غير مسموح.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('دفتر نقاط الدعم للإلحاق فقط؛ صحّح بقيد جديد.');
        });

        static::deleting(function (): void {
            throw new LogicException('دفتر نقاط الدعم لا يُحذف منه شيء.');
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

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}

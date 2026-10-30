<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

/**
 * E44 - باقة بطاقة داعم لموسم (silver | gold | diamond). **الخادم سلطة السعر والعملة والنقاط والنمط**؛ العميل يرسل هوية الباقة فقط.
 *
 * - (season_id, tier_key) فريد. كل باقة مربوطة ببرنامج رعاية E30 للموسم: الدفع الحقيقي يمرّ كاملًا عبر SponsorshipPayment (E32) → تحقق → SponsorshipContribution (لا محرك دفع ثانٍ).
 * - تغيير الباقة يسري على **الطلبات المستقبلية** فقط: كل بطاقة (SeasonSupporterCard) تحمل لقطة ثابتة من السعر/النقاط/النمط وقت الطلب.
 * - name_style_key من قائمة بيضاء مغلقة (رموز تصميم فقط؛ لا CSS ولا لون حر يُخزَّن). نقاط الدعم ≠ السعر بالسنتات.
 * - لا حذف أبدًا (تُعطَّل بدل ذلك). $guarded=['*']؛ الكتابة عبر SeasonSupporterPackageService.
 */
class SeasonSupporterPackage extends Model
{
    public const TIER_SILVER = 'silver';

    public const TIER_GOLD = 'gold';

    public const TIER_DIAMOND = 'diamond';

    /** المستويات المسموحة بترتيب الرتبة (الأدنى أولًا). */
    public const TIERS = [self::TIER_SILVER, self::TIER_GOLD, self::TIER_DIAMOND];

    public const IMMUTABLE_FIELDS = ['season_id', 'tier_key', 'sponsorship_program_id'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'support_points' => 'integer',
            'sort_order' => 'integer',
            'social_mention_eligible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** رتبة المستوى (1..3) أو 0 إن لم يكن معروفًا: تُستعمل لاختيار «أعلى مستوى». */
    public static function rankOf(?string $tierKey): int
    {
        $index = array_search((string) $tierKey, self::TIERS, true);

        return $index === false ? 0 : $index + 1;
    }

    protected static function booted(): void
    {
        static::saving(function (self $p): void {
            if (! in_array($p->tier_key, self::TIERS, true)) {
                throw new InvalidArgumentException('مستوى البطاقة غير مسموح (silver|gold|diamond).');
            }

            if (! in_array($p->name_style_key, (array) config('seasonal_recognition.supporters.name_styles', self::TIERS), true)) {
                throw new InvalidArgumentException('نمط الاسم خارج القائمة البيضاء.');
            }

            if ((int) $p->price_minor <= 0) {
                throw new InvalidArgumentException('سعر الباقة عدد صحيح موجب بالوحدة الصغرى.');
            }

            if (! preg_match('/^[A-Z]{3}$/', (string) $p->currency)) {
                throw new InvalidArgumentException('عملة الباقة رمز من 3 أحرف كبيرة.');
            }

            $max = (int) config('seasonal_recognition.supporters.max_support_points_per_purchase', 1000000);

            if ((int) $p->support_points < 0 || (int) $p->support_points > $max) {
                throw new InvalidArgumentException("نقاط الدعم بين 0 و{$max}.");
            }

            if (trim((string) $p->name) === '') {
                throw new InvalidArgumentException('اسم الباقة مطلوب.');
            }
        });

        static::updating(function (self $p): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($p->isDirty($field)) {
                    throw new LogicException("العمود {$field} ثابت.");
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('باقات الداعمين لا تُحذف؛ عطّلها بدل ذلك.');
        });
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(SponsorshipProgram::class, 'sponsorship_program_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(SeasonSupporterCard::class, 'package_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E44 - مجاميع الداعم الموسمية (صف كسول لكل (موسم، لاعب) عند أول نقطة/بطاقة؛ لا صفوف مسبقة). مشتقّة من دفتر النقاط وبطاقات الدعم ويعاد بناؤها بالمطابقة.
 *
 * - points_total: كل النقاط؛ public_points_total: النقاط **العلنية** فقط (مصدر غير مجهول) وعليها يقوم الترتيب العام حتى لا يتسرب المجهول في المجاميع.
 * - card_tier_key: مستوى البطاقة الحالي مخزَّنًا للقراءة السريعة (السلطة = البطاقات النشطة).
 * - social_*: طابور التنويه الاجتماعي (eligible → queued → published | skipped) تسجيل إداري يدوي فقط؛ لا ربط بأي واجهة شبكة اجتماعية.
 * - final_*: لقطة الترتيب النهائي يكتبها SeasonSupporterFinalizer وحدها ولا تتغيّر بعدها (المجاميع الحيّة قد تتغير باسترداد متأخر دون المساس باللقطة؛ التصحيح بإجراء إداري موثَّق).
 */
class SeasonSupporterStat extends Model
{
    public const SOCIAL_ELIGIBLE = 'eligible';

    public const SOCIAL_QUEUED = 'queued';

    public const SOCIAL_PUBLISHED = 'published';

    public const SOCIAL_SKIPPED = 'skipped';

    public const SOCIAL_STATUSES = [self::SOCIAL_ELIGIBLE, self::SOCIAL_QUEUED, self::SOCIAL_PUBLISHED, self::SOCIAL_SKIPPED];

    public const FINAL_FIELDS = ['final_rank', 'final_points', 'final_card_tier_key', 'finalized_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'points_total' => 'integer',
            'public_points_total' => 'integer',
            'final_rank' => 'integer',
            'final_points' => 'integer',
            'first_point_at' => 'datetime',
            'last_point_at' => 'datetime',
            'social_updated_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $s): void {
            if ($s->isDirty('season_id') || $s->isDirty('user_id')) {
                throw new LogicException('موسم الداعم ومستخدمه ثابتان.');
            }

            if ($s->getOriginal('finalized_at') !== null) {
                foreach (self::FINAL_FIELDS as $field) {
                    if ($s->isDirty($field)) {
                        throw new LogicException('الترتيب النهائي للداعمين مجمَّد ولا يتغير.');
                    }
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('مجاميع الداعمين لا تُحذف.');
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

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }
}

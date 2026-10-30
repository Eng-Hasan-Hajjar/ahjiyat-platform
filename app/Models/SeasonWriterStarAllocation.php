<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * E44 - توزيع نجوم (ناخب ⇒ كاتب): **دفتر السلطة** لمجاميع الكتّاب. صف واحد لكل زوج (writer_entry_id, voter_user_id)، وتوزيع الصفر يحذف الصف (voters_count = عدد الصفوف).
 *
 * لا يُعرض علنًا أبدًا (خصوصية التصويت). يكتبه SeasonWriterVotingService وحده في معاملة تقفل الميزانية ثم هذا الصف ثم الكاتب. مستقل تمامًا عن CommunityPuzzleRating (دلالة 1-5 مختلفة).
 */
class SeasonWriterStarAllocation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['stars' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $a): void {
            if ((int) $a->stars < 1 || (int) $a->stars > SeasonRecognitionSetting::HARD_MAX_STARS) {
                throw new InvalidArgumentException('توزيع النجوم لكاتب واحد بين 1 و10 (الصفر يحذف التوزيع).');
            }
        });

        static::updating(function (self $a): void {
            foreach (['season_id', 'writer_entry_id', 'voter_user_id'] as $immutable) {
                if ($a->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SeasonWriterEntry::class, 'writer_entry_id');
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voter_user_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}

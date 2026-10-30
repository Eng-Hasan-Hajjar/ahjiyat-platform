<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E44 - ربط صريح بين سلسلة مبدع وعضوية كاتب في موسم. **هذا الربط وحده** هو ما يُحتسب أهليةً لتصويت اللاعبين وتحليلات الموسم؛ لا نستنتج من كل ما نشره المبدع يومًا.
 *
 * (writer_entry_id, creator_series_id) فريد ⇒ لا ربط مكرر. ملكية السلسلة للمبدع نفسه تُفرض في SeasonWriterRosterService (لا هنا). linked_at هو الحدّ الأدنى لوقت لعب اللاعب المعتبر لهذه السلسلة.
 * الربط ثابت: يُحذف (إلغاء الربط) قبل فتح التصويت فقط وبالخدمة؛ لا تعديل.
 */
class SeasonWriterSeries extends Model
{
    protected $table = 'season_writer_series';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('ربط السلسلة ثابت؛ ألغِه وأنشئ ربطًا جديدًا قبل فتح التصويت.');
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SeasonWriterEntry::class, 'writer_entry_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}

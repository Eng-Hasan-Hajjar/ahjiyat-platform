<?php

namespace App\Services\Engagement;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E29 - التحديث الذرّي الوحيد لعدّادات التفاعل المشتقّة. تعبير SQL واحد (col = col + n) لا قراءة-ثم-كتابة، فلا يضيع تحديث تحت التزامن،
 * والنقصان محصور عند الصفر (CASE) فلا يصير سالبًا أبدًا ولو انحرف العدّاد. الأعمدة من قائمة بيضاء ثابتة بالكود (لا مدخلات).
 * تحديث عبر منشئ الاستعلام لا النموذج: لا أحداث نماذج ولا لمس updated_at ولا إعادة اشتقاق is_visible.
 */
final class EngagementCounters
{
    private const ALLOWED = [
        'creator_puzzles' => ['ratings_count', 'ratings_sum', 'favorites_count'],
        'creator_profiles' => ['followers_count'],
        'user_collections' => ['saves_count'], // E35: عدّاد حفظ المجموعات (مميَّزون بقيد فريد)
    ];

    /** @param array<string, int> $deltas عمود => فرق (موجب أو سالب) */
    public static function adjust(string $table, int $id, array $deltas): void
    {
        $sets = [];

        foreach ($deltas as $column => $delta) {
            if (! in_array($column, self::ALLOWED[$table] ?? [], true)) {
                throw new InvalidArgumentException("Counter {$table}.{$column} is not adjustable.");
            }

            $delta = (int) $delta;

            if ($delta === 0) {
                continue;
            }

            $sets[$column] = DB::raw($delta > 0
                ? "{$column} + {$delta}"
                : "CASE WHEN {$column} >= ".(-$delta)." THEN {$column} - ".(-$delta).' ELSE 0 END');
        }

        if ($sets !== []) {
            DB::table($table)->where('id', $id)->update($sets);
        }
    }
}

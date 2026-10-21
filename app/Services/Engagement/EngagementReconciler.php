<?php

namespace App\Services\Engagement;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\UserCollection;
use Illuminate\Support\Facades\DB;

/**
 * E29 - مصالحة العدّادات المشتقّة مع صفوف التفاعل (المصدر الوحيد للحقيقة). على الطلب فقط، لا جدولة دقيقة ولا مسح كامل مجدول.
 *
 *  - يمرّ بالأعمال والمبدعين على دفعات بمؤشر id (ذاكرة ثابتة)، وبكل دفعة 2–3 استعلامات تجميع محصورة بمعرّفات الدفعة.
 *  - dry-run: يكشف الانحراف فقط. الإصلاح: يعيد حساب الصف **تحت قفله** من الصفوف المصدر ويكتب القيم المطلقة (لا يتعارض مع كتابات حيّة).
 *  - لا يلمس أي بيانات تفاعل ولا الاقتصاد: يكتب أعمدة العدّادات حصرًا.
 */
class EngagementReconciler
{
    /** @return array{works: int, creators: int, collections: int, drift: list<array{table: string, id: int, column: string, stored: int, actual: int}>, fixed: int} */
    public function run(bool $dryRun = true, int $chunk = 500): array
    {
        $chunk = max(10, $chunk);
        $drift = [];
        $fixed = 0;
        $works = 0;
        $creators = 0;
        $collections = 0;

        CreatorPuzzle::query()->select('id', 'ratings_count', 'ratings_sum', 'favorites_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$works, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $works += count($ids);

            $ratings = DB::table('community_puzzle_ratings')->whereIn('creator_puzzle_id', $ids)->groupBy('creator_puzzle_id')
                ->selectRaw('creator_puzzle_id as id, COUNT(*) as c, COALESCE(SUM(rating), 0) as s')->get()->keyBy('id');
            $favorites = DB::table('community_puzzle_favorites')->whereIn('creator_puzzle_id', $ids)->groupBy('creator_puzzle_id')
                ->selectRaw('creator_puzzle_id as id, COUNT(*) as c')->get()->keyBy('id');

            foreach ($rows as $row) {
                $actual = [
                    'ratings_count' => (int) ($ratings[$row->id]->c ?? 0),
                    'ratings_sum' => (int) ($ratings[$row->id]->s ?? 0),
                    'favorites_count' => (int) ($favorites[$row->id]->c ?? 0),
                ];

                $rowDrift = false;

                foreach ($actual as $column => $value) {
                    if ((int) $row->{$column} !== $value) {
                        $drift[] = ['table' => 'creator_puzzles', 'id' => (int) $row->id, 'column' => $column, 'stored' => (int) $row->{$column}, 'actual' => $value];
                        $rowDrift = true;
                    }
                }

                if ($rowDrift && ! $dryRun) {
                    $this->repairWork((int) $row->id);
                    $fixed++;
                }
            }
        });

        CreatorProfile::query()->select('id', 'followers_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$creators, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $creators += count($ids);

            $follows = DB::table('creator_follows')->whereIn('creator_profile_id', $ids)->groupBy('creator_profile_id')
                ->selectRaw('creator_profile_id as id, COUNT(*) as c')->get()->keyBy('id');

            foreach ($rows as $row) {
                $actual = (int) ($follows[$row->id]->c ?? 0);

                if ((int) $row->followers_count !== $actual) {
                    $drift[] = ['table' => 'creator_profiles', 'id' => (int) $row->id, 'column' => 'followers_count', 'stored' => (int) $row->followers_count, 'actual' => $actual];

                    if (! $dryRun) {
                        $this->repairCreator((int) $row->id);
                        $fixed++;
                    }
                }
            }
        });

        // E35: saves_count للمجموعات (الصفوف user_collection_saves هي الحقيقة). المجموعات الخاصة بلا حفظ تُفحص أيضًا بلا كلفة (دفعة + تجميع واحد).
        UserCollection::query()->select('id', 'saves_count')->chunkById($chunk, function ($rows) use (&$drift, &$fixed, &$collections, $dryRun) {
            $ids = $rows->pluck('id')->all();
            $collections += count($ids);

            $saves = DB::table('user_collection_saves')->whereIn('user_collection_id', $ids)->groupBy('user_collection_id')
                ->selectRaw('user_collection_id as id, COUNT(*) as c')->get()->keyBy('id');

            foreach ($rows as $row) {
                $actual = (int) ($saves[$row->id]->c ?? 0);

                if ((int) $row->saves_count !== $actual) {
                    $drift[] = ['table' => 'user_collections', 'id' => (int) $row->id, 'column' => 'saves_count', 'stored' => (int) $row->saves_count, 'actual' => $actual];

                    if (! $dryRun) {
                        $this->repairCollection((int) $row->id);
                        $fixed++;
                    }
                }
            }
        });

        return ['works' => $works, 'creators' => $creators, 'collections' => $collections, 'drift' => $drift, 'fixed' => $fixed];
    }

    protected function repairWork(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('creator_puzzles')->where('id', $id)->lockForUpdate()->first(['id']);

            $r = DB::table('community_puzzle_ratings')->where('creator_puzzle_id', $id)->selectRaw('COUNT(*) as c, COALESCE(SUM(rating), 0) as s')->first();
            $f = DB::table('community_puzzle_favorites')->where('creator_puzzle_id', $id)->count();

            DB::table('creator_puzzles')->where('id', $id)->update(['ratings_count' => (int) $r->c, 'ratings_sum' => (int) $r->s, 'favorites_count' => (int) $f]);
        });
    }

    protected function repairCollection(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('user_collections')->where('id', $id)->lockForUpdate()->first(['id']);
            DB::table('user_collections')->where('id', $id)->update(['saves_count' => DB::table('user_collection_saves')->where('user_collection_id', $id)->count()]);
        });
    }

    protected function repairCreator(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('creator_profiles')->where('id', $id)->lockForUpdate()->first(['id']);
            DB::table('creator_profiles')->where('id', $id)->update(['followers_count' => DB::table('creator_follows')->where('creator_profile_id', $id)->count()]);
        });
    }
}

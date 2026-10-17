<?php

namespace App\Services\CreatorSeries;

use App\Models\CreatorProfile;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E30 - تحليلات تجميعية لسلاسل المبدع (للمالك فقط): بدايات العناصر، اللاعبون المميَّزون، الإكمالات، ونسبة الإكمال. **أرقام مجمَّعة بلا أي هوية لاعب.**
 *
 *  - المصدر community_puzzle_attempts (سجل لعب المجتمع) على عناصر النسخة المنشورة الحالية فقط؛ إكمال اللاعب = كل العناصر محلولة على نسخها المنشورة الحالية (نفس قاعدة التقدّم).
 *  - starts = عدد أزواج (لاعب، عنصر) التي بدأها اللاعبون؛ players = لاعبون مميَّزون؛ completions = لاعبون أكملوا كل العناصر.
 *  - لا تتبّع «المشاهدات»: طلب GET لا يكتب شيئًا أبدًا (لا عدّاد مشاهدات ولا جدول مشاهدات).
 *  - مخزَّنة TTL محدود (creator_series.analytics.cache_ttl) لكل مبدع؛ الكاش للعرض فقط وليس سلطة لأي قرار.
 *  - استعلامان مجمَّعان ثابتان مهما كبر عدد اللاعبين (التجميع بقاعدة البيانات لا بالـPHP).
 */
class CreatorSeriesAnalytics
{
    /** @return array<int, array{players:int, starts:int, completions:int, completion_rate:int}> مفتاحها معرّف السلسلة الداخلي */
    public function forCreator(CreatorProfile $profile): array
    {
        return CachedSnapshot::remember(
            'creator-series:analytics:'.$profile->id,
            (int) config('creator_series.analytics.cache_ttl', 600),
            fn () => $this->compute((int) $profile->id),
        );
    }

    /** @return array{players:int, starts:int, completions:int, completion_rate:int} مجموع كل سلاسل المبدع */
    public function totals(array $perSeries): array
    {
        $players = array_sum(array_column($perSeries, 'players'));
        $completions = array_sum(array_column($perSeries, 'completions'));

        return [
            'players' => (int) $players,
            'starts' => (int) array_sum(array_column($perSeries, 'starts')),
            'completions' => (int) $completions,
            'completion_rate' => $players > 0 ? (int) floor($completions * 100 / $players) : 0,
        ];
    }

    protected function compute(int $profileId): array
    {
        $perUser = DB::table('creator_series as s')
            ->join('creator_series_items as i', 'i.creator_series_revision_id', '=', 's.published_revision_id')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->join('community_puzzle_attempts as a', 'a.creator_puzzle_id', '=', 'w.id')
            ->where('s.creator_profile_id', $profileId)
            ->whereNotNull('s.published_revision_id')
            ->groupBy('s.id', 's.published_items_count', 'a.user_id')
            ->selectRaw('s.id as series_id, s.published_items_count as total_items, a.user_id as user_id')
            ->selectRaw('COUNT(DISTINCT a.creator_puzzle_id) as started')
            ->selectRaw('COUNT(DISTINCT CASE WHEN a.is_correct AND a.puzzle_id = w.published_puzzle_id THEN a.creator_puzzle_id END) as solved');

        $rows = DB::query()->fromSub($perUser, 't')
            ->groupBy('series_id')
            ->selectRaw('series_id, COUNT(*) as players, SUM(started) as starts')
            ->selectRaw('SUM(CASE WHEN solved >= total_items THEN 1 ELSE 0 END) as completions')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $players = (int) $row->players;
            $completions = (int) $row->completions;
            $out[(int) $row->series_id] = [
                'players' => $players,
                'starts' => (int) $row->starts,
                'completions' => $completions,
                'completion_rate' => $players > 0 ? (int) floor($completions * 100 / $players) : 0,
            ];
        }

        return $out;
    }
}

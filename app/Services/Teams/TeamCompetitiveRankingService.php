<?php

namespace App\Services\Teams;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveEventTeamResult;
use App\Models\Team;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ترتيب الفرق بالأحداث التنافسية (E19-D). **لا يغيّر شيئًا من منافسة الأفراد**: لا نقاط لاعب، ولا مراكزه، ولا جوائز E18. مصدر الحقيقة: نتائج **صحيحة** بأحداث **معتمَدة**.
 *
 * - **الانتماء = لقطة الفريق عند التسجيل** (competitive_event_participants.team_id_snapshot)، **لا العضوية الحالية** أبدًا: انتقال لاعب أو مغادرته لا ينقل نتيجة تاريخية.
 *   من سجّل قبل E19 أو بلا فريق: لقطته NULL فلا يدخل أي فريق (لا ربط تخميني).
 * - **الصيغة:** نقاط الفريق = مجموع **أفضل N نتيجة صحيحة** لأعضائه بالحدث (N = teams.ranking_top_n = 3). درجات E17 قابلة للجمع عدلًا (الأحجية والسقف الزمني واحد
 *   للجميع). السقف N يمنع أفضلية الحجم: فريق بعشرين عضوًا لا يتفوق بكثرة الأعضاء. (فريق بأقل من N مشاركين يُجمع له ما لديه: ميزته الحجم الأصغر أن يلعب أقل، لا أكثر.)
 * - **كسر التعادل الثابت:** نقاط أعلى ← مجموع مدد أقل (للنتائج المحتسَبة) ← أعضاء محتسَبون أكثر ← معرّف الفريق الأصغر. الحتمية: المدخلات نفسها ← الترتيب نفسه.
 * - **الثبات التاريخي:** يُخزَّن الترتيب عند الاعتماد مرة واحدة (UNIQUE) فلا يتغير بتعطيل فريق أو انتقال لاعبين لاحقًا. الفرق **المفعَّلة عند الاعتماد** فقط تدخل.
 * - بلا أي اقتصاد: لا عملة ولا XP ولا مكافأة للفرق.
 */
class TeamCompetitiveRankingService
{
    /**
     * يحسب الترتيب من النتائج الحالية (حتمي، بلا كتابة).
     *
     * @return list<array{team_id: int, score: int, counted: int, duration: int, rank: int}>
     */
    public function compute(CompetitiveEvent $event): array
    {
        // مُتدفَّق (cursor) مرتّب بالفريق ثم الأفضل: الذاكرة O(عدد الفرق) لا O(المشاركين).
        $rows = CompetitiveEventResult::query()
            ->join('competitive_event_participants as p', fn ($j) => $j->on('p.competitive_event_id', '=', 'competitive_event_results.competitive_event_id')->on('p.user_id', '=', 'competitive_event_results.user_id'))
            ->join('teams as t', 't.id', '=', 'p.team_id_snapshot')
            ->where('competitive_event_results.competitive_event_id', $event->getKey())
            ->where('competitive_event_results.is_correct', true)
            ->where('t.is_active', true)
            ->orderBy('p.team_id_snapshot')->orderByDesc('competitive_event_results.score')->orderBy('competitive_event_results.duration_ms')
            ->orderBy('competitive_event_results.completed_at')->orderBy('competitive_event_results.id')
            ->select('p.team_id_snapshot as team_id', 'competitive_event_results.score as score', 'competitive_event_results.duration_ms as duration')
            ->cursor();

        // الصيغة المشتركة (E19 + E20): TeamScoreCalculator هو المصدر الوحيد لمجموع أفضل N وترتيبه.
        $calc = app(TeamScoreCalculator::class);

        return $calc->ranked($calc->aggregate($rows));
    }

    /** يخزّن الترتيب النهائي لحدث معتمَد مرة واحدة (Idempotent، آمن للتوازي). @return int عدد الفرق المخزَّنة (0 إن سبق أو لا فرق) */
    public function finalize(CompetitiveEvent $event): int
    {
        if ($event->status !== CompetitiveEvent::STATUS_COMPLETED) {
            return 0;
        }

        return DB::transaction(function () use ($event) {
            $locked = CompetitiveEvent::query()->lockForUpdate()->find($event->getKey());

            if ($locked === null || $locked->team_rankings_finalized_at !== null) {
                return 0;
            }

            $rows = $this->compute($locked);

            foreach ($rows as $row) {
                DB::table('competitive_event_team_results')->insertOrIgnore([[
                    'competitive_event_id' => $locked->getKey(), 'team_id' => $row['team_id'], 'score' => $row['score'],
                    'counted_members' => $row['counted'], 'total_duration_ms' => $row['duration'], 'rank' => $row['rank'],
                    'created_at' => now(), 'updated_at' => now(),
                ]]);
            }

            DB::table('competitive_events')->where('id', $locked->getKey())->update(['team_rankings_finalized_at' => now()]);

            return count($rows);
        });
    }

    /** المعتمَدة بلا ترتيب فرق مخزَّن بعد (شبكة أمان للأمر الدوري). @return int */
    public function finalizePending(int $limit = 50): int
    {
        $done = 0;

        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_COMPLETED)->whereNull('team_rankings_finalized_at')->orderBy('id')->limit($limit)->get()
            ->each(function (CompetitiveEvent $event) use (&$done) {
                $this->finalize($event);
                $done++;
            });

        return $done;
    }

    /** هل لهذا الحدث بيانات فرق أصلًا (مشارك بلقطة)؟ لإظهار تبويب الفرق فقط حين يلزم. */
    public function hasTeamData(CompetitiveEvent $event): bool
    {
        return DB::table('competitive_event_participants')->where('competitive_event_id', $event->getKey())->whereNotNull('team_id_snapshot')->exists();
    }

    /**
     * ترتيب الفرق لعرضه: نهائي (مخزَّن) بعد الاعتماد، وإلا مؤقت (محسوب الآن، غير نهائي).
     *
     * @return array{final: bool, rows: Collection<int, object>}
     */
    public function standings(CompetitiveEvent $event): array
    {
        if ($event->team_rankings_finalized_at !== null) {
            $rows = CompetitiveEventTeamResult::query()->where('competitive_event_id', $event->getKey())->with('team:id,name,slug,is_active')->orderBy('rank')->limit(100)->get()
                ->map(fn ($r) => (object) ['rank' => $r->rank, 'team' => $r->team, 'score' => $r->score, 'counted' => $r->counted_members, 'duration' => $r->total_duration_ms]);

            return ['final' => true, 'rows' => $rows];
        }

        $computed = collect($this->compute($event))->take(100);
        $teams = Team::query()->whereIn('id', $computed->pluck('team_id'))->get(['id', 'name', 'slug', 'is_active'])->keyBy('id');

        return ['final' => false, 'rows' => $computed->map(fn ($r) => (object) ['rank' => $r['rank'], 'team' => $teams[$r['team_id']], 'score' => $r['score'], 'counted' => $r['counted'], 'duration' => $r['duration']])];
    }

    /** إحصاءات الفريق من ترتيباته المخزَّنة النهائية. @return array{events: int, wins: int, top3: int, best_rank: ?int, avg_rank: ?float} */
    public function stats(Team $team): array
    {
        $row = CompetitiveEventTeamResult::query()->where('team_id', $team->getKey())
            ->selectRaw('count(*) as events, sum(case when rank = 1 then 1 else 0 end) as wins, sum(case when rank <= 3 then 1 else 0 end) as top3, min(rank) as best, avg(rank) as avg_rank')->first();

        return [
            'events' => (int) $row->events, 'wins' => (int) $row->wins, 'top3' => (int) $row->top3,
            'best_rank' => $row->best === null ? null : (int) $row->best, 'avg_rank' => $row->avg_rank === null ? null : round((float) $row->avg_rank, 1),
        ];
    }

    /** آخر ترتيبات الفريق النهائية. */
    public function recent(Team $team, int $limit = 5): Collection
    {
        return CompetitiveEventTeamResult::query()->where('team_id', $team->getKey())->with('event:id,title,slug,ends_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * جدول الميداليات العام للفرق (E19-D14): لا نقاط مخترعة عبر الأحداث. الترتيب بأعداد شفافة: انتصارات ← مراكز أولى-ثلاثة ← أحداث ← معرّف أصغر.
     * الفرق المفعَّلة فقط. قرار منتجي لاحق قد يستبدله بنقاط مرجَّحة.
     */
    public function medalTable(int $perPage = 20): LengthAwarePaginator
    {
        $page = DB::table('competitive_event_team_results as r')->join('teams as t', 't.id', '=', 'r.team_id')->where('t.is_active', true)
            ->selectRaw('r.team_id, count(*) as events, sum(case when r.rank = 1 then 1 else 0 end) as wins, sum(case when r.rank <= 3 then 1 else 0 end) as top3')
            ->groupBy('r.team_id')->orderByDesc('wins')->orderByDesc('top3')->orderByDesc('events')->orderBy('r.team_id')->paginate($perPage);

        $teams = Team::query()->whereIn('id', $page->pluck('team_id'))->get(['id', 'name', 'slug', 'members_count'])->keyBy('id');
        $page->getCollection()->transform(fn ($r) => (object) ['team' => $teams[$r->team_id], 'events' => (int) $r->events, 'wins' => (int) $r->wins, 'top3' => (int) $r->top3]);

        return $page;
    }
}

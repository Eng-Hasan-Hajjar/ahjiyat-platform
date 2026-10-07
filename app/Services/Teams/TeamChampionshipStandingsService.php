<?php

namespace App\Services\Teams;

use App\Models\CompetitiveEvent;
use App\Models\TeamChampionship;
use Illuminate\Support\Facades\DB;

/**
 * ترتيب البطولة (E20-D). **مشتق** من ترتيب الفرق المخزَّن النهائي بالأحداث المعتمَدة (competitive_event_team_results، E19): كل حدث يمنح نقاطًا بحسب **مركز** الفريق فيه
 * (خريطة rank→points هيكلية، لقطتها محفوظة بالبطولة عند النشر) — **لا جمع لدرجات خام عبر أحداث مختلفة** (درجات أحجيات مختلفة غير قابلة للمقارنة)، ولا إعادة ترتيب داخل البطولة
 * (مراكز E19 كما هي، بما فيها التعادل). تُحتسب فقط الأحداث **المعتمَدة وبترتيب فرق مخزَّن**؛ الملغاة والجارية لا تمنح نقاطًا.
 * كسر التعادل الثابت: نقاط أعلى ← انتصارات أحداث أكثر ← مراكز أول-ثلاثة أكثر ← أفضل مركز ← معرّف فريق أصغر. حتمي: المدخلات نفسها ← الترتيب نفسه. استعلام مجمَّع واحد (بلا N+1).
 */
class TeamChampionshipStandingsService
{
    /** الأحداث المرتبطة التي تمنح نقاطًا الآن: معتمَدة وبترتيب فرق مخزَّن. @return list<int> */
    public function contributingEventIds(TeamChampionship $championship): array
    {
        return $championship->events()->where('competitive_events.status', CompetitiveEvent::STATUS_COMPLETED)->whereNotNull('competitive_events.team_rankings_finalized_at')
            ->pluck('competitive_events.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<array{team_id: int, points: int, events_count: int, event_wins: int, top3_count: int, best_rank: int, rank: int}>
     */
    public function compute(TeamChampionship $championship): array
    {
        $eventIds = $this->contributingEventIds($championship);

        if ($eventIds === []) {
            return [];
        }

        // CASE هيكلي من خريطة أعداد صحيحة فقط (لا نص حر ولا منطق تنفيذي).
        $case = 'case r.rank';

        foreach ($championship->pointsMap() as $rank => $points) {
            $case .= ' when '.(int) $rank.' then '.(int) $points;
        }

        $case .= ' else 0 end';

        $rows = DB::table('competitive_event_team_results as r')->whereIn('r.competitive_event_id', $eventIds)
            ->selectRaw("r.team_id as team_id, sum({$case}) as points, count(*) as events_count, sum(case when r.rank = 1 then 1 else 0 end) as event_wins, sum(case when r.rank <= 3 then 1 else 0 end) as top3_count, min(r.rank) as best_rank")
            ->groupBy('r.team_id')->get();

        $teams = $rows->map(fn ($r) => ['team_id' => (int) $r->team_id, 'points' => (int) $r->points, 'events_count' => (int) $r->events_count, 'event_wins' => (int) $r->event_wins,
            'top3_count' => (int) $r->top3_count, 'best_rank' => (int) $r->best_rank, 'rank' => 0])->all();

        usort($teams, fn ($a, $b) => [$b['points'], $b['event_wins'], $b['top3_count'], $a['best_rank'], $a['team_id']] <=> [$a['points'], $a['event_wins'], $a['top3_count'], $b['best_rank'], $b['team_id']]);

        foreach ($teams as $i => &$t) {
            $t['rank'] = $i + 1;
        }

        return $teams;
    }

    /** للعرض: النهائي المخزَّن بعد الاعتماد، وإلا المحسوب الآن (مؤقت). @return array{final: bool, rows: \Illuminate\Support\Collection} */
    public function standings(TeamChampionship $championship): array
    {
        $limit = (int) config('teams.championships.standings_limit', 100);

        if ($championship->status === TeamChampionship::STATUS_COMPLETED) {
            $rows = $championship->results()->with('team:id,name,slug,is_active')->orderBy('rank')->limit($limit)->get()
                ->map(fn ($r) => (object) ['rank' => $r->rank, 'team' => $r->team, 'points' => $r->points, 'events_count' => $r->events_count, 'event_wins' => $r->event_wins, 'top3_count' => $r->top3_count]);

            return ['final' => true, 'rows' => $rows];
        }

        $computed = collect($this->compute($championship))->take($limit);
        $teams = \App\Models\Team::query()->whereIn('id', $computed->pluck('team_id'))->get(['id', 'name', 'slug', 'is_active'])->keyBy('id');

        return ['final' => false, 'rows' => $computed->map(fn ($r) => (object) ['rank' => $r['rank'], 'team' => $teams[$r['team_id']], 'points' => $r['points'], 'events_count' => $r['events_count'],
            'event_wins' => $r['event_wins'], 'top3_count' => $r['top3_count']])];
    }
}

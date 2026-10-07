<?php

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * مجد الفريق (E20-E1..E4): إحصاءات ومرويات **مشتقة بالكامل** من النتائج المخزَّنة النهائية (نتائج التحدّيات، ترتيب الأحداث، ترتيب البطولات)، بلا عدّادات قابلة للتعديل ولا اقتصاد: تعرُّف
 * فقط. تبقى كما هي ولو عُطِّل الفريق أو تغيّر اسمه أو أعضاؤه (مصدرها معرّف الفريق التاريخي لا العضوية الحالية). استعلامات مجمَّعة قليلة (بلا N+1).
 */
class TeamGloryService
{
    public function __construct(protected TeamCompetitiveRankingService $ranking) {}

    /** @return array{challenges: array{played: int, won: int, lost: int, drawn: int}, events: array, championships: array{participated: int, won: int, top3: int}} */
    public function stats(Team $team): array
    {
        $id = $team->getKey();

        $c = DB::table('team_challenge_results as r')->join('team_challenges as c', 'c.id', '=', 'r.team_challenge_id')->where('r.team_id', $id)->where('c.status', TeamChallenge::STATUS_COMPLETED)
            ->selectRaw('count(*) as played, sum(case when c.winner_team_id = ? then 1 else 0 end) as won, sum(case when c.is_draw = 1 then 1 else 0 end) as drawn', [$id])->first();
        $played = (int) $c->played;
        $won = (int) $c->won;
        $drawn = (int) $c->drawn;

        $ch = DB::table('team_championship_results as r')->join('team_championships as c', 'c.id', '=', 'r.team_championship_id')->where('r.team_id', $id)->where('c.status', TeamChampionship::STATUS_COMPLETED)
            ->selectRaw('count(*) as participated, sum(case when r.rank = 1 then 1 else 0 end) as won, sum(case when r.rank <= 3 then 1 else 0 end) as top3')->first();

        return [
            'challenges' => ['played' => $played, 'won' => $won, 'lost' => $played - $won - $drawn, 'drawn' => $drawn],
            'events' => $this->ranking->stats($team),
            'championships' => ['participated' => (int) $ch->participated, 'won' => (int) $ch->won, 'top3' => (int) $ch->top3],
        ];
    }

    /** خزانة المجد: البطولات المُحرَزة وأفضل المراكز (الأحدث أولًا، محدودة). @return array{championships_won: Collection, championship_top3: Collection} */
    public function trophies(Team $team, int $limit = 6): array
    {
        $rows = DB::table('team_championship_results as r')->join('team_championships as c', 'c.id', '=', 'r.team_championship_id')->where('r.team_id', $team->getKey())
            ->where('c.status', TeamChampionship::STATUS_COMPLETED)->where('r.rank', '<=', 3)->orderByDesc('c.finalized_at')->orderByDesc('c.id')->limit($limit * 2)
            ->get(['c.title', 'c.slug', 'c.finalized_at', 'r.rank', 'r.points']);

        return ['championships_won' => $rows->where('rank', 1)->take($limit)->values(), 'championship_top3' => $rows->where('rank', '>', 1)->take($limit)->values()];
    }

    /** آخر المباريات المعتمَدة للفريق (خصم، نتيجة، تاريخ). */
    public function recentMatches(Team $team, int $limit = 5): Collection
    {
        return TeamChallenge::query()->involvingTeam($team->getKey())->where('status', TeamChallenge::STATUS_COMPLETED)->with(['challenger:id,name,slug', 'opponent:id,name,slug', 'results'])
            ->orderByDesc('completed_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (TeamChallenge $c) => (object) [
                'challenge' => $c, 'opponent' => $c->challenger_team_id === $team->getKey() ? $c->opponent : $c->challenger,
                'outcome' => $c->is_draw ? 'draw' : ($c->winner_team_id === $team->getKey() ? 'win' : 'loss'),
                'score' => (int) $c->results->firstWhere('team_id', $team->getKey())?->score, 'their_score' => (int) $c->results->firstWhere('team_id', $c->otherTeamId($team->getKey()))?->score,
            ]);
    }
}

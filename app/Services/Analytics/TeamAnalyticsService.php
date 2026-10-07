<?php

namespace App\Services\Analytics;

use App\Models\CompetitiveEventTeamResult;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Support\AnalyticsCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** تحليلات الفرق (E19-E14): أرقام مجمَّعة فقط (لا هويات ولا بيانات خاصة). مخزَّنة 10 دقائق بمفتاح AnalyticsCache المُرقَّم. لا اقتصاد. */
class TeamAnalyticsService
{
    /** تحدّيات الفرق (E20): أعداد مجمَّعة بلا هويات. @return array<string, mixed> */
    protected function challenges(): array
    {
        $by = TeamChallenge::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $completed = (int) ($by[TeamChallenge::STATUS_COMPLETED] ?? 0);
        $draws = TeamChallenge::query()->where('status', TeamChallenge::STATUS_COMPLETED)->where('is_draw', true)->count();
        $active = DB::table('team_challenges as c')->join('teams as t', fn ($j) => $j->on('t.id', '=', 'c.challenger_team_id')->orOn('t.id', '=', 'c.opponent_team_id'))
            ->where('c.status', TeamChallenge::STATUS_COMPLETED)->selectRaw('t.name as name, count(*) as played')->groupBy('t.id', 't.name')->orderByDesc('played')->orderBy('t.id')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->name, 'played' => (int) $r->played])->all();

        return [
            'created' => (int) $by->sum(), 'accepted' => TeamChallenge::query()->whereNotNull('accepted_at')->count(), 'completed' => $completed, 'expired' => (int) ($by[TeamChallenge::STATUS_EXPIRED] ?? 0),
            'draw_rate' => $completed === 0 ? 0.0 : round($draws / $completed * 100, 1), 'most_active' => $active,
        ];
    }

    /** بطولات الفرق (E20). @return array<string, mixed> */
    protected function championships(): array
    {
        $champions = DB::table('team_championships as c')->join('teams as t', 't.id', '=', 'c.champion_team_id')->where('c.status', TeamChampionship::STATUS_COMPLETED)
            ->selectRaw('t.name as name, count(*) as titles')->groupBy('t.id', 't.name')->orderByDesc('titles')->orderBy('t.id')->limit(5)->get()->map(fn ($r) => ['name' => $r->name, 'titles' => (int) $r->titles])->all();

        return [
            'total' => TeamChampionship::query()->where('status', '!=', TeamChampionship::STATUS_DRAFT)->count(),
            'completed' => TeamChampionship::query()->where('status', TeamChampionship::STATUS_COMPLETED)->count(),
            'participants' => (int) DB::table('team_championship_results')->distinct()->count('team_id'), 'champions' => $champions,
        ];
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return Cache::remember(AnalyticsCache::key('teams.overview'), now()->addMinutes(10), function () {
            $active = Team::query()->where('is_active', true)->count();
            $members = TeamMembership::query()->join('teams as t', 't.id', '=', 'team_memberships.team_id')->where('t.is_active', true)->count();
            $top = CompetitiveEventTeamResult::query()->join('teams as t', 't.id', '=', 'competitive_event_team_results.team_id')->where('t.is_active', true)
                ->selectRaw('t.name as name, sum(case when competitive_event_team_results.rank = 1 then 1 else 0 end) as wins, count(*) as events')
                ->groupBy('t.id', 't.name')->orderByDesc('wins')->orderByDesc('events')->orderBy('t.id')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->name, 'wins' => (int) $r->wins, 'events' => (int) $r->events])->all();

            return [
                'total' => Team::query()->count(),
                'active' => $active,
                'members_in_teams' => $members,
                'avg_size' => $active === 0 ? 0.0 : round($members / $active, 1),
                'pending_invitations' => TeamInvitation::query()->where('status', TeamInvitation::STATUS_PENDING)->where('expires_at', '>', now())->count(),
                'pending_requests' => TeamJoinRequest::query()->where('status', TeamJoinRequest::STATUS_PENDING)->count(),
                'competing_teams' => CompetitiveEventTeamResult::query()->distinct()->count('team_id'),
                'top_teams' => $top,
                'challenges' => $this->challenges(),
                'championships' => $this->championships(),
            ];
        });
    }
}

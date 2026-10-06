<?php

namespace App\Services\Analytics;

use App\Models\CompetitiveEventTeamResult;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Support\AnalyticsCache;
use Illuminate\Support\Facades\Cache;

/** تحليلات الفرق (E19-E14): أرقام مجمَّعة فقط (لا هويات ولا بيانات خاصة). مخزَّنة 10 دقائق بمفتاح AnalyticsCache المُرقَّم. لا اقتصاد. */
class TeamAnalyticsService
{
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
            ];
        });
    }
}

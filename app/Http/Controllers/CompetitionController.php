<?php

namespace App\Http\Controllers;

use App\Models\CompetitiveEvent;
use App\Services\Competitive\CompetitiveEventService;
use App\Services\Competitive\CompetitiveLeaderboardService;
use Illuminate\Http\Request;

/** الصفحات العامة للمنافسات (قراءة فقط): القائمة (قادمة/مباشرة/منتهية بحسب الوقت) وصفحة الحدث مع الترتيب. لا تعديل بأي GET. */
class CompetitionController extends Controller
{
    public function __construct(protected CompetitiveEventService $events, protected CompetitiveLeaderboardService $board) {}

    public function index()
    {
        $now = now();
        $limit = (int) config('competitive.events_per_section', 12);
        $published = fn () => CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED);

        return view('competitions.index', [
            'live' => $published()->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->orderByDesc('is_featured')->orderBy('ends_at')->limit($limit)->get(),
            'upcoming' => $published()->where('starts_at', '>', $now)->orderByDesc('is_featured')->orderBy('starts_at')->limit($limit)->get(),
            'ended' => CompetitiveEvent::query()
                ->where(fn ($q) => $q->where('status', CompetitiveEvent::STATUS_COMPLETED)
                    ->orWhere(fn ($w) => $w->where('status', CompetitiveEvent::STATUS_PUBLISHED)->where('ends_at', '<=', $now)))
                ->orderByDesc('ends_at')->limit($limit)->get(),
        ]);
    }

    public function show(Request $request, CompetitiveEvent $event)
    {
        abort_if($event->status === CompetitiveEvent::STATUS_DRAFT, 404);

        $viewer = $request->user();
        $scope = $this->board->scopeFor($viewer, $request->query('scope'));

        // E19: تبويب الفرق يظهر فقط حين توجد بيانات فرق (مشارك بلقطة). نهائي بعد الاعتماد، ومؤقت قبله. لا يمسّ ترتيب الأفراد.
        $teams = app(\App\Services\Teams\TeamCompetitiveRankingService::class);
        $hasTeams = $teams->hasTeamData($event);
        $tab = $hasTeams && $request->query('tab') === 'teams' ? 'teams' : 'players';

        return view('competitions.show', [
            'hasTeams' => $hasTeams,
            'tab' => $tab,
            'teamStandings' => $tab === 'teams' ? $teams->standings($event) : null,
            'event' => $event->load(['puzzle:id,title,difficulty,time_limit_seconds', 'rewardRules' => fn ($q) => $q->where('is_active', true), 'rewardRules.currency:id,name', 'rewardRules.storeItem:id,name']),
            'myGrant' => $viewer === null ? null : \App\Models\CompetitiveRewardGrant::query()->where('competitive_event_id', $event->id)->where('user_id', $viewer->id)->where('status', 'granted')->first(['reward_label', 'final_rank']),
            'state' => $this->events->joinState($viewer, $event),
            'board' => $this->board->page($event, $viewer, $scope),
            'scope' => $scope,
        ]);
    }
}

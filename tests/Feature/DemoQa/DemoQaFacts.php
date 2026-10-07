<?php

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\CompetitiveEventTeamResult;
use App\Models\CompetitiveRewardGrant;
use App\Models\FriendChallenge;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
use App\Models\TeamInvitation;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Social\BlockService;
use App\Services\Social\FriendRelation;
use App\Services\Social\FriendshipService;
use App\Services\Teams\TeamCompetitiveRankingService;
use Database\Seeders\DemoQaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حقائق الديمو تُحسب **مرة واحدة** لكل عملية اختبار (البذر ~15 ثانية): أول اختبار يطلبها يبذر ثم يلتقط كل ما تحتاجه الاختبارات المسمّاة (كمصفوفات)، وتعيد الاختبارات استعمالها.
 * (فحص الواجهات عبر HTTP يبذر بنفسه لأنه يحتاج قاعدة حيّة.) كذلك تُجري هنا التشغيلين الثاني والثالث وتقيس الفرق بعدد صفوف كل الجداول (Idempotency).
 */
function demoQaFacts($test): array
{
    static $facts = null;

    if ($facts !== null) {
        return $facts;
    }

    $test->seed(DemoQaSeeder::class);
    $first = demoQaCounts();
    $test->seed(DemoQaSeeder::class);
    $test->seed(DemoQaSeeder::class);
    $third = demoQaCounts();

    return $facts = demoQaCompute() + ['counts_first' => $first, 'counts_third' => $third];
}

function demoQaCounts(): array
{
    $out = [];

    foreach (Schema::getTableListing() as $table) {
        $name = preg_replace('/^.*\./', '', $table);

        if (! in_array($name, ['migrations', 'cache', 'cache_locks', 'sessions'], true)) {
            $out[$name] = DB::table($name)->count();
        }
    }

    ksort($out);

    return $out;
}

function demoQaUser(string $key): User
{
    return User::query()->where('email', $key.'@ahjiyat.test')->firstOrFail();
}

function demoQaCompute(): array
{
    $friends = app(FriendshipService::class);
    $blocks = app(BlockService::class);
    $yousef = demoQaUser('yousef');
    $emailKey = fn (User $u) => explode('@', $u->email)[0];
    $f = [];

    // ---- اجتماعي
    $f['relations'] = collect(['sara', 'noureddine', 'yaser', 'layan', 'jana', 'reem', 'khaled', 'lama', 'firas', 'omar', 'dana'])
        ->mapWithKeys(fn ($k) => [$k => $friends->relationBetween($yousef, demoQaUser($k))->value])->all();
    $f['friends'] = collect($friends->friendIds($yousef))->map(fn ($id) => $emailKey(User::find($id)))->sort()->values()->all();
    $f['blocks'] = ['yousef_blocked_lama' => $blocks->hasBlocked($yousef, demoQaUser('lama')), 'firas_blocked_yousef' => $blocks->hasBlocked(demoQaUser('firas'), $yousef)];
    $f['dana'] = ['enabled' => (bool) demoQaUser('dana')->friend_requests_enabled, 'can_send' => $friends->canSendTo(demoQaUser('sara'), demoQaUser('dana'))];
    $f['friendships'] = ['accepted' => DB::table('friendships')->where('status', 'accepted')->count(), 'pending' => DB::table('friendships')->where('status', 'pending')->count()];
    $f['users'] = ['yousef' => $yousef->only(['name', 'email', 'role', 'is_frozen', 'profile_visibility']) + ['verified' => $yousef->hasVerifiedEmail(), 'password_ok' => password_verify('password', $yousef->password)],
        'tarek_frozen' => (bool) demoQaUser('tarek')->is_frozen, 'huda_frozen' => (bool) demoQaUser('huda')->is_frozen, 'muath_unverified' => ! demoQaUser('muath')->hasVerifiedEmail(),
        'count' => User::query()->count(), 'demo_visible_public' => User::query()->where('profile_visibility', 'public')->count()];

    // ---- فرق
    $f['teams'] = Team::query()->with('owner:id,email')->orderBy('id')->get()->mapWithKeys(fn (Team $t) => [$t->name => [
        'owner' => $emailKey($t->owner), 'policy' => $t->join_policy, 'visibility' => $t->visibility, 'members' => $t->members_count, 'max' => $t->max_members, 'active' => (bool) $t->is_active,
        'roles' => TeamMembership::query()->where('team_id', $t->id)->with('user:id,email')->get()->mapWithKeys(fn ($m) => [$emailKey($m->user) => $m->role])->all(),
        'real_count' => TeamMembership::query()->where('team_id', $t->id)->count(),
    ]])->all();
    $f['pending_requests'] = TeamJoinRequest::query()->where('status', 'pending')->with('user:id,email', 'team:id,name')->get()->map(fn ($r) => $emailKey($r->user).'→'.$r->team->name)->all();
    $f['accepted_requests'] = TeamJoinRequest::query()->where('status', 'accepted')->count();
    $f['pending_invitations'] = TeamInvitation::query()->where('status', 'pending')->with('invitedUser:id,email', 'team:id,name')->get()->map(fn ($i) => $emailKey($i->invitedUser).'←'.$i->team->name)->all();
    $f['one_team_each'] = TeamMembership::query()->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->get()->count();

    // ---- منافسات فردية
    $f['events'] = CompetitiveEvent::query()->orderBy('id')->get()->mapWithKeys(fn ($e) => [$e->slug => ['status' => $e->status, 'participants' => $e->participants_count, 'starts_past' => $e->starts_at->isPast(), 'ends_future' => $e->ends_at->isFuture(),
        'team_rankings' => $e->team_rankings_finalized_at !== null]])->all();
    $f['ranks'] = collect(['demo-cup-1', 'demo-cup-2', 'demo-cup-3', 'demo-cup-4'])->mapWithKeys(function ($slug) use ($emailKey) {
        $event = CompetitiveEvent::query()->where('slug', $slug)->first();
        $rows = CompetitiveEventResult::query()->where('competitive_event_id', $event->id)->whereNotNull('final_rank')->orderBy('final_rank')->with('user:id,email')->get();

        return [$slug => ['top3' => $rows->take(3)->map(fn ($r) => $emailKey($r->user))->all(), 'ranks' => $rows->pluck('final_rank')->all(), 'yousef' => $rows->first(fn ($r) => $emailKey($r->user) === 'yousef')?->final_rank, 'count' => $rows->count()]];
    })->all();
    $f['friend_challenges'] = FriendChallenge::query()->orderBy('id')->get()->map(fn ($c) => [$c->status, $c->is_draw ? 'draw' : ($c->winner_user_id ? $emailKey(User::find($c->winner_user_id)) : null)])->all();

    // ---- جوائز وتعرّف
    $cup3 = CompetitiveEvent::query()->where('slug', 'demo-cup-3')->first();
    $f['rewards'] = ['grants' => CompetitiveRewardGrant::query()->count(), 'granted' => CompetitiveRewardGrant::query()->where('status', 'granted')->count(), 'cup3_participants' => CompetitiveEventResult::query()->where('competitive_event_id', $cup3->id)->count(),
        'duplicates' => CompetitiveRewardGrant::query()->select('competitive_event_id', 'user_id')->groupBy('competitive_event_id', 'user_id')->havingRaw('count(*) > 1')->get()->count(),
        'reem_rmd' => (int) DB::table('currency_transactions')->where('user_id', demoQaUser('reem')->id)->where('reason', 'competitive_event_reward')->sum('amount'),
        'yousef_rmd' => (int) DB::table('currency_transactions')->where('user_id', $yousef->id)->where('reason', 'competitive_event_reward')->sum('amount')];
    $f['comp_achievements'] = DB::table('user_achievement_progress as a')->join('achievements as d', 'd.id', '=', 'a.achievement_id')->where('a.user_id', $yousef->id)->where('d.category', 'competitive')
        ->get(['d.internal_key', 'a.current_value', 'a.unlocked_at'])->mapWithKeys(fn ($r) => [$r->internal_key => [(int) $r->current_value, $r->unlocked_at !== null]])->all();

    // ---- منافسة الفرق
    $ranking = app(TeamCompetitiveRankingService::class);
    $f['team_event_standings'] = collect(['demo-cup-1', 'demo-cup-2', 'demo-cup-3', 'demo-cup-4'])->mapWithKeys(function ($slug) use ($ranking) {
        $event = CompetitiveEvent::query()->where('slug', $slug)->first();
        $stored = CompetitiveEventTeamResult::query()->where('competitive_event_id', $event->id)->orderBy('rank')->with('team:id,name')->get();
        $recomputed = collect($ranking->compute($event))->map(fn ($r) => [$r['team_id'], $r['score'], $r['rank']])->all();

        return [$slug => ['order' => $stored->map(fn ($r) => $r->team->name)->all(), 'match' => $stored->map(fn ($r) => [$r->team_id, $r->score, $r->rank])->all() === $recomputed]];
    })->all();
    $f['null_snapshots'] = DB::table('competitive_event_participants')->whereNull('team_id_snapshot')->count();
    $f['snapshot_mismatch'] = DB::table('competitive_event_participants as p')->join('team_memberships as m', 'm.user_id', '=', 'p.user_id')->whereColumn('p.team_id_snapshot', '!=', 'm.team_id')->count();
    $f['team_challenges'] = TeamChallenge::query()->orderBy('id')->with(['challenger:id,name', 'opponent:id,name', 'winner:id,name', 'participants', 'results'])->get()->map(fn (TeamChallenge $c) => [
        'pair' => $c->challenger->name.'|'.$c->opponent->name, 'status' => $c->status, 'winner' => $c->winner?->name, 'draw' => (bool) $c->is_draw,
        'participants' => $c->participants->count(), 'locked' => $c->participants->whereNotNull('locked_at')->count(), 'roles_set' => $c->participants->whereNotNull('role_snapshot')->count(),
        'results' => $c->results->count(), 'bad_team' => $c->participants->filter(fn ($p) => ! TeamMembership::query()->where('user_id', $p->user_id)->where('team_id', $p->team_id)->exists())->count(),
    ])->all();
    $f['championships'] = TeamChampionship::query()->orderBy('id')->with('champion:id,name')->get()->mapWithKeys(fn (TeamChampionship $c) => [$c->slug => [
        'status' => $c->status, 'champion' => $c->champion?->name, 'events' => $c->events()->count(), 'phase' => $c->phase(),
        'standing' => DB::table('team_championship_results as r')->join('teams as t', 't.id', '=', 'r.team_id')->where('r.team_championship_id', $c->id)->orderBy('r.rank')->get(['t.name', 'r.points', 'r.rank'])->map(fn ($r) => [$r->name, (int) $r->points, (int) $r->rank])->all(),
        'linked_ok' => $c->events()->get()->every(fn ($e) => in_array($e->status, ['published', 'completed'], true)),
    ]])->all();

    // ---- إشعارات
    $f['notifications'] = collect(['yousef', 'sara', 'reem', 'omar', 'kenan'])->mapWithKeys(fn ($k) => [$k => [
        'total' => DB::table('notifications')->where('notifiable_id', demoQaUser($k)->id)->count(), 'unread' => DB::table('notifications')->where('notifiable_id', demoQaUser($k)->id)->whereNull('read_at')->count(),
    ]])->all();
    $f['duplicate_keys'] = DB::table('notifications')->select('notifiable_id', 'idempotency_key')->groupBy('notifiable_id', 'idempotency_key')->havingRaw('count(*) > 1')->get()->count();
    $f['notification_types'] = DB::table('notifications')->where('idempotency_key', 'like', 'demo-qa:%')->distinct()->pluck('type_key')->sort()->values()->all();

    // ---- اقتصاد/تقدّم/متجر/التزام
    $f['ledger'] = ['wallet_mismatch' => DB::table('wallets')->get()->filter(function ($w) {
        $tx = DB::table('currency_transactions')->where('user_id', $w->user_id)->where('currency_id', $w->currency_id)->get()->groupBy('type')->map(fn ($g) => (int) $g->sum('amount'));

        return (int) $w->pending_balance !== (int) ($tx['earn_pending'] ?? 0) || (int) $w->available_balance !== (int) ($tx['admin_adjustment'] ?? 0) + (int) ($tx['earn_available'] ?? 0) - abs((int) ($tx['spend'] ?? 0));
    })->count(), 'xp_mismatch' => DB::table('player_progressions as p')->whereRaw('p.total_xp != coalesce((select sum(amount) from xp_transactions x where x.user_id = p.user_id), 0)')->count(),
        'wallets' => DB::table('wallets')->count(), 'negative' => DB::table('wallets')->where('available_balance', '<', 0)->orWhere('pending_balance', '<', 0)->count()];
    $f['levels'] = DB::table('player_progressions')->join('users', 'users.id', '=', 'player_progressions.user_id')->get(['users.email', 'player_progressions.current_level', 'player_progressions.total_xp'])->mapWithKeys(fn ($r) => [explode('@', $r->email)[0] => [(int) $r->current_level, (int) $r->total_xp]])->all();
    $f['store'] = ['owned' => DB::table('user_inventory_items')->where('user_id', $yousef->id)->where('quantity', '>', 0)->count(), 'items' => DB::table('store_items')->where('sku', 'like', 'demo-%')->count(),
        'equipped' => DB::table('user_cosmetic_loadouts')->where('user_id', $yousef->id)->count(), 'sara_equipped' => DB::table('user_cosmetic_loadouts')->where('user_id', demoQaUser('sara')->id)->count()];
    $f['streak'] = DB::table('player_streaks')->where('user_id', $yousef->id)->value('current_streak');
    $f['quests_today'] = DB::table('user_quest_progress as q')->join('quest_definitions as d', 'd.id', '=', 'q.quest_definition_id')->where('q.user_id', $yousef->id)->where('q.period_type', 'daily')->whereDate('q.period_start', now()->toDateString())
        ->get(['d.internal_key', 'q.current_value', 'q.target_value_snapshot', 'q.completed_at'])->mapWithKeys(fn ($r) => [$r->internal_key => [(int) $r->current_value, (int) $r->target_value_snapshot, $r->completed_at !== null]])->all();
    $campaign = \App\Models\Campaign::query()->where('slug', 'aseel-season-01')->first();
    $progress = app(\App\Services\CampaignProgressService::class);
    $steps = \App\Models\CampaignStep::query()->get();
    $f['campaign'] = collect(['sara', 'yousef', 'reem', 'kenan'])->mapWithKeys(fn ($k) => [$k => ['done' => $steps->filter(fn ($s) => $progress->isStepCompleted(demoQaUser($k), $s))->count(), 'completed' => $progress->isCampaignCompleted(demoQaUser($k), $campaign)]])->all();
    $f['fraud_flags'] = DB::table('fraud_flags')->count();

    // ---- دردشة E21
    $unread = app(\App\Services\Chat\ChatUnreadService::class);
    $threads = app(\App\Services\Chat\ChatThreadService::class);
    $teamThread = \App\Models\ChatThread::query()->where('type', 'team')->first();
    $global = $threads->global();
    $emails = fn ($q) => $q->pluck('sender_id')->unique()->map(fn ($id) => explode('@', User::query()->whereKey($id)->value('email'))[0])->sort()->values()->all();
    $f['chat'] = [
        'threads' => \App\Models\ChatThread::query()->selectRaw('type, count(*) c')->groupBy('type')->pluck('c', 'type')->all(),
        'direct' => $unread->directList($yousef)->mapWithKeys(fn ($c) => [$c['other']->name => ['messages' => \App\Models\ChatMessage::where('chat_thread_id', $c['thread']->id)->count(), 'unread' => $c['unread']]])->all(),
        'team' => ['messages' => \App\Models\ChatMessage::where('chat_thread_id', $teamThread->id)->count(), 'senders' => $emails(\App\Models\ChatMessage::where('chat_thread_id', $teamThread->id)), 'edited' => \App\Models\ChatMessage::where('chat_thread_id', $teamThread->id)->whereNotNull('edited_at')->count(),
            'unread' => $unread->forThread($yousef, $teamThread), 'team_name' => $teamThread->team->name],
        'global' => ['messages' => \App\Models\ChatMessage::where('chat_thread_id', $global->id)->count(), 'senders' => count($emails(\App\Models\ChatMessage::where('chat_thread_id', $global->id))), 'deleted' => \App\Models\ChatMessage::where('chat_thread_id', $global->id)->whereNotNull('deleted_at')->count(),
            'visible_to_yousef' => app(\App\Services\Chat\ChatMessageService::class)->page($global, $yousef)['messages']->pluck('sender_id')->map(fn ($id) => explode('@', User::query()->whereKey($id)->value('email'))[0])->all(), 'unread' => $unread->forThread($yousef, $global)],
        'unread_total' => $unread->total($yousef),
        'reports' => \App\Models\ChatMessageReport::query()->with('message.sender:id,email', 'message.thread:id,type', 'reporter:id,email')->get()->map(fn ($r) => [$r->status, $r->category, $emailKey($r->message->sender), $emailKey($r->reporter), $r->message->thread->type])->all(),
        'mutes' => \App\Models\ChatMute::active()->with('user:id,email')->get()->map(fn ($m) => $emailKey($m->user))->all(),
        'yousef_send' => app(\App\Services\Chat\ChatAccess::class)->sendBlocker($yousef, $global),
        'chat_notifications' => DB::table('notifications')->where('idempotency_key', 'like', '%chat%')->count(),
        'chat_tables' => collect(['chat_threads', 'chat_messages', 'chat_read_states', 'chat_message_reports', 'chat_mutes'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
    ];
    // التحليلات تُحسب وقت البذر (القاعدة تتراجع بعد أول اختبار فلا تصلح للاستعلام لاحقًا).
    $f['analytics'] = collect([\App\Services\Analytics\UserAnalyticsService::class, \App\Services\Analytics\PuzzleAnalyticsService::class, \App\Services\Analytics\ProgressionAnalyticsService::class,
        \App\Services\Analytics\EngagementAnalyticsService::class, \App\Services\Analytics\StoreAnalyticsService::class, \App\Services\Analytics\SecurityAnalyticsService::class,
        \App\Services\Analytics\CompetitiveAnalyticsService::class, \App\Services\Analytics\TeamAnalyticsService::class])->mapWithKeys(fn ($s) => [class_basename($s) => demoQaSignal(demoQaOverview($s))])->all();
    $f['team_analytics'] = app(\App\Services\Analytics\TeamAnalyticsService::class)->overview();
    $f['counts'] = ['admin_audit' => DB::table('operational_audit_logs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'jobs' => DB::table('jobs')->count()];

    return $f;
}


/** overview لخدمات التحليلات: أغلبها بفترة (آخر 90 يومًا يغطي كل بيانات الديمو)، وفرق بلا فترة. */
function demoQaOverview(string $service): array
{
    return $service === \App\Services\Analytics\TeamAnalyticsService::class ? app($service)->overview() : app($service)->overview(\App\Support\AnalyticsPeriod::fromPreset('last_90_days'));
}

/** كود ملف بلا تعليقات (لفحوص ثابتة لا تتأثر بالشرح). */
function demoQaCode(string $file): string
{
    $code = file_get_contents($file);
    $code = preg_replace('#/\*.*?\*/#s', '', $code);
    $code = preg_replace('#(?m)(^|\s)//[^\n]*#', '$1', $code);

    return preg_replace('#\{\{--.*?--\}\}#s', '', $code);
}


/** هل في المصفوفة رقم موجب في أي عمق؟ (دليل أن التحليل ليس كله أصفارًا). */
function demoQaSignal($data): bool
{
    foreach ((array) $data as $v) {
        if ((is_array($v) && demoQaSignal($v)) || (is_numeric($v) && $v > 0)) {
            return true;
        }
    }

    return false;
}

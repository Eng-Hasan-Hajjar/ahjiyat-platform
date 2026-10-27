<?php

namespace App\Services\Seasons;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\MissionPool;
use App\Models\Season;
use App\Models\SeasonContentItem;
use App\Models\SeasonPhase;
use App\Models\User;
use App\Services\AhjiyatPass\PassDefinitionCache;
use App\Services\AhjiyatPass\PassProgressService;
use App\Services\CampaignProgressService;
use App\Services\Engagement\QuestPeriodService;
use App\Services\SeasonLifecycleService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E42 - نموذج قراءة مركز الموسم (Season Hub): يجمع ما يخص الموسم من مصادره ويعرضه، **ولا يكتب شيئًا ولا يمنح شيئًا ولا يملك اقتصادًا**.
 *
 *  - الكيانات المرتبطة (حملات إضافية/منافسات/مجموعات مهام/مسار) تبقى سلطة بياناتها وتقدّمها ومكافآتها وفحوص أهليتها؛ المركز يقرأ حالة المستخدم منها فقط.
 *  - لا بيانات مستخدمين آخرين: كل استعلام مستخدم مقيَّد بـuser_id للمستخدم الحالي. مجموعة المهام لا تكشف قائمتها (مخفية عمدًا إلا لمن أُسندت له): تُعرض
 *    مهام المستخدم المُسندة له فعلًا للفترة الحالية، أما الضيف فلا يرى سوى اسم المجموعة.
 *  - المسار: قراءة فقط عبر PassProgressService::find (لا ensure/heal) وPassDefinitionCache::track. لا أسعار ولا شراء ولا مكافآت هنا.
 *  - عدد الاستعلامات **ثابت** بغض النظر عن حجم البيانات (whereIn مجمّعة، سقوف الروابط من config/seasons.php).
 *  - الجزء العام المملوك للموسم (مراحل/روابط/إعلانات) يُخزَّن كمصفوفات بسيطة في SeasonCache (يُبطَل بكل كتابة)؛ أما الحالات الزمنية فتُحسب وقت القراءة دائمًا.
 */
final class SeasonHubService
{
    public function __construct(
        protected SeasonLifecycleService $lifecycle,
        protected CampaignProgressService $campaigns,
        protected PassProgressService $passProgress,
        protected PassDefinitionCache $passCache,
        protected QuestPeriodService $periods,
        protected SeasonCalendarService $calendar,
    ) {}

    /**
     * @param  array{percentage?: int, available?: bool, current_step_url?: ?string, completed?: bool}  $primary  ملخص الحملة الأساسية من المتحكم (يحسبه CampaignProgressService أصلًا).
     * @return array<string, mixed>
     */
    public function build(Season $season, ?User $user, array $primary = [], ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $season->loadMissing('campaign');

        $state = $this->lifecycle->state($season, $now);
        $def = $this->definition($season);

        $phases = SeasonPhaseResolver::ordered($this->hydratePhases($def['phases']));
        $current = SeasonPhaseResolver::current($phases, $now);
        $next = SeasonPhaseResolver::next($phases, $now);

        $links = collect($def['links']);
        $phaseNames = $phases->mapWithKeys(fn (SeasonPhase $p) => [$p->getKey() => $p->name]);

        $campaignRows = $this->campaignRows($links, $user, $phaseNames);
        $eventRows = $this->competitionRows($links, $user, $phaseNames, $now);
        $missionRows = $this->missionRows($links, $user, $phaseNames, $now);
        $pass = $this->passRow($season, $user, $now);

        $phaseRows = $phases->values()->map(fn (SeasonPhase $p, int $i) => $this->phaseRow($p, $i + 1, $now))->all();
        $announcements = $this->announcements($def['announcements'], $now);

        $calendar = $this->calendar->items($this->spans($season, $phases, $campaignRows, $eventRows, $pass), $now);

        return [
            'state' => $state,
            'state_label' => $this->lifecycle->stateLabel($state),
            'is_live' => $state === SeasonLifecycleService::STATE_LIVE,
            'window_start' => $this->lifecycle->windowStart($season),
            'window_end' => $this->lifecycle->windowEnd($season),
            'phases' => $phaseRows,
            'current_phase' => $current ? $this->phaseRow($current, SeasonPhaseResolver::position($phases, $current), $now) : null,
            'next_phase' => $next ? $this->phaseRow($next, SeasonPhaseResolver::position($phases, $next), $now) : null,
            'phase_summary' => $this->phaseSummary($phases, $current, $now),
            'announcements' => $announcements,
            'campaigns' => $campaignRows,
            'competitions' => $eventRows,
            'missions' => $missionRows,
            'pass' => $pass,
            'calendar' => $calendar,
            'indicators' => $this->indicators($user, $primary, $campaignRows, $eventRows, $missionRows, $pass),
            'next_action' => $this->nextAction($state, $user, $primary, $pass, $eventRows, $missionRows, $next),
            'has_extras' => $campaignRows !== [] || $eventRows !== [] || $missionRows !== [] || $pass !== null,
        ];
    }

    // ───────────────────────── الجزء العام المملوك للموسم (كاش بإبطال بالكتابة) ─────────────────────────

    /** @return array{phases: list<array<string, mixed>>, links: list<array<string, mixed>>, announcements: list<array<string, mixed>>} */
    public function definition(Season $season): array
    {
        return SeasonCache::remember('hub:'.$season->getKey(), function () use ($season): array {
            return [
                'phases' => $season->phases()->get()->map(fn (SeasonPhase $p) => [
                    'id' => $p->getKey(),
                    'name' => $p->name,
                    'description' => $p->description,
                    'starts_at' => $p->starts_at->toIso8601String(),
                    'ends_at' => $p->ends_at->toIso8601String(),
                    'sort_order' => $p->sort_order,
                    'is_featured' => $p->is_featured,
                    'theme_config' => $p->theme_config,
                ])->all(),
                'links' => $season->contentItems()->get()->map(fn (SeasonContentItem $i) => [
                    'id' => $i->getKey(),
                    'type' => $i->content_type,
                    'target_id' => $i->targetId(),
                    'label' => $i->display_label,
                    'phase_id' => $i->season_phase_id,
                    'is_featured' => $i->is_featured,
                    'sort_order' => $i->sort_order,
                ])->all(),
                'announcements' => $season->announcements()->whereNotNull('published_at')->orderByDesc('published_at')->orderByDesc('id')
                    ->limit((int) config('seasons.limits.announcements_per_season', 60))->get()
                    ->map(fn ($a) => [
                        'id' => $a->getKey(),
                        'title' => $a->title,
                        'body' => $a->body,
                        'published_at' => $a->published_at->toIso8601String(),
                        'expires_at' => $a->expires_at?->toIso8601String(),
                        'phase_id' => $a->season_phase_id,
                        'is_featured' => $a->is_featured,
                    ])->all(),
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, SeasonPhase>
     */
    protected function hydratePhases(array $rows)
    {
        return collect($rows)->map(fn (array $r) => (new SeasonPhase)->forceFill($r));
    }

    /** @return array<string, mixed> */
    protected function phaseRow(SeasonPhase $p, int $position, CarbonInterface $now): array
    {
        $state = $p->stateAt($now);

        return [
            'id' => $p->getKey(),
            'name' => $p->name,
            'description' => $p->description,
            'starts_at' => $p->starts_at,
            'ends_at' => $p->ends_at,
            'state' => $state,
            'label' => SeasonPhase::stateLabel($state),
            'position' => $position,
            'accent' => $p->themeAccent(),
            'color' => SeasonTheme::accentColor($p->themeAccent()),
            'is_featured' => (bool) $p->is_featured,
            'days_left' => $state === SeasonPhase::STATE_CURRENT ? max(0, (int) ceil(($p->ends_at->getTimestamp() - $now->getTimestamp()) / 86400)) : null,
        ];
    }

    protected function phaseSummary($phases, ?SeasonPhase $current, CarbonInterface $now): ?string
    {
        $total = $phases->count();

        if ($total === 0) {
            return null;
        }

        if ($current !== null) {
            return 'المرحلة '.SeasonPhaseResolver::position($phases, $current).' من '.$total;
        }

        return $phases->every(fn (SeasonPhase $p) => $p->stateAt($now) === SeasonPhase::STATE_ENDED)
            ? 'اكتملت مراحل الموسم'
            : 'لم تبدأ المراحل بعد';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function announcements(array $rows, CarbonInterface $now): array
    {
        return collect($rows)
            ->map(function (array $r) {
                $r['published_at'] = Carbon::parse($r['published_at']);
                $r['expires_at'] = $r['expires_at'] !== null ? Carbon::parse($r['expires_at']) : null;

                return $r;
            })
            ->filter(fn (array $r) => $r['published_at']->lte($now) && ($r['expires_at'] === null || $r['expires_at']->gt($now)))
            ->sort(fn (array $a, array $b) => [$b['is_featured'], $b['published_at']->getTimestamp(), $b['id']] <=> [$a['is_featured'], $a['published_at']->getTimestamp(), $a['id']])
            ->take(max(1, (int) config('seasons.hub.announcements_visible', 5)))
            ->values()->all();
    }

    // ───────────────────────── الكيانات المرتبطة (طازجة؛ الحالة من مصدرها) ─────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $links
     * @return list<int>
     */
    protected function ids($links, string $type): array
    {
        return $links->where('type', $type)->pluck('target_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $links
     * @param  \Illuminate\Support\Collection<int, string>  $phaseNames
     * @return list<array<string, mixed>>
     */
    protected function campaignRows($links, ?User $user, $phaseNames): array
    {
        $ids = $this->ids($links, SeasonContentItem::TYPE_CAMPAIGN);

        if ($ids === []) {
            return [];
        }

        $models = Campaign::query()->whereIn('id', $ids)->get(['id', 'slug', 'title', 'description', 'is_active', 'starts_at', 'ends_at'])->keyBy('id');
        $completed = [];
        $started = [];

        if ($user !== null) {
            $completed = DB::table('user_campaign_completions')->where('user_id', $user->getKey())->whereIn('campaign_id', $ids)->pluck('campaign_id')->map(fn ($v) => (int) $v)->all();
            $started = DB::table('user_campaign_progress as p')
                ->join('campaign_steps as s', 's.id', '=', 'p.campaign_step_id')
                ->join('campaign_gates as g', 'g.id', '=', 's.campaign_gate_id')
                ->join('campaign_stages as st', 'st.id', '=', 'g.campaign_stage_id')
                ->where('p.user_id', $user->getKey())->whereIn('st.campaign_id', $ids)
                ->distinct()->pluck('st.campaign_id')->map(fn ($v) => (int) $v)->all();
        }

        $rows = [];

        foreach ($links->where('type', SeasonContentItem::TYPE_CAMPAIGN)->sortBy(['sort_order', 'id']) as $link) {
            $campaign = $models->get($link['target_id']);

            if ($campaign === null) {
                continue;
            }

            $available = $this->campaigns->isCampaignAvailable($campaign);
            $rows[] = [
                'title' => $link['label'] ?: $campaign->title,
                'description' => $campaign->description,
                'url' => route('campaigns.show', $campaign),
                'available' => $available,
                'availability' => $this->campaignAvailability($campaign),
                'user_state' => $user === null ? null : (in_array($campaign->getKey(), $completed, true) ? 'completed' : (in_array($campaign->getKey(), $started, true) ? 'in_progress' : 'not_started')),
                'phase' => $link['phase_id'] !== null ? $phaseNames->get($link['phase_id']) : null,
                'is_featured' => (bool) $link['is_featured'],
                'starts_at' => $campaign->starts_at,
                'ends_at' => $campaign->ends_at,
            ];
        }

        return $rows;
    }

    protected function campaignAvailability(Campaign $campaign): string
    {
        return match (true) {
            ! $campaign->is_active => 'غير متاحة حالياً',
            $campaign->starts_at?->isFuture() => 'قريباً',
            $campaign->ends_at?->isPast() => 'انتهت',
            default => 'مفتوحة الآن',
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $links
     * @param  \Illuminate\Support\Collection<int, string>  $phaseNames
     * @return list<array<string, mixed>>
     */
    protected function competitionRows($links, ?User $user, $phaseNames, CarbonInterface $now): array
    {
        $ids = $this->ids($links, SeasonContentItem::TYPE_COMPETITIVE_EVENT);

        if ($ids === []) {
            return [];
        }

        $events = CompetitiveEvent::query()->public()->whereIn('id', $ids)
            ->get(['id', 'slug', 'title', 'status', 'starts_at', 'ends_at', 'registration_starts_at', 'registration_ends_at', 'max_participants', 'participants_count'])
            ->keyBy('id');
        $registered = $user === null ? [] : CompetitiveEventParticipant::query()->where('user_id', $user->getKey())->whereIn('competitive_event_id', $ids)
            ->pluck('competitive_event_id')->map(fn ($v) => (int) $v)->all();

        $labels = [
            CompetitiveEvent::PHASE_UPCOMING => 'قريباً',
            CompetitiveEvent::PHASE_LIVE => 'جارية الآن',
            CompetitiveEvent::PHASE_ENDED => 'انتهت',
            CompetitiveEvent::PHASE_COMPLETED => 'اكتملت',
        ];
        $rows = [];

        foreach ($links->where('type', SeasonContentItem::TYPE_COMPETITIVE_EVENT)->sortBy(['sort_order', 'id']) as $link) {
            $event = $events->get($link['target_id']);

            if ($event === null) {
                continue; // مسودة/ملغاة: لا تظهر للجمهور
            }

            $phase = $event->phase($now);
            $rows[] = [
                'title' => $link['label'] ?: $event->title,
                'url' => route('competitions.show', $event),
                'phase' => $phase,
                'label' => $labels[$phase] ?? 'غير متاحة',
                'registration_open' => $event->registrationOpen($now),
                'is_full' => $event->isFull(),
                'registered' => $user === null ? null : in_array($event->getKey(), $registered, true),
                'season_phase' => $link['phase_id'] !== null ? $phaseNames->get($link['phase_id']) : null,
                'is_featured' => (bool) $link['is_featured'],
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
            ];
        }

        return $rows;
    }

    /**
     * مجموعة المهام لا تكشف قائمتها: المستخدم يرى مهامه المُسندة له فعلًا للفترة الحالية (قراءة جدول الإسناد والتقدّم الموجودين)، والضيف لا يرى سوى الاسم.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $links
     * @param  \Illuminate\Support\Collection<int, string>  $phaseNames
     * @return list<array<string, mixed>>
     */
    protected function missionRows($links, ?User $user, $phaseNames, CarbonInterface $now): array
    {
        $ids = $this->ids($links, SeasonContentItem::TYPE_MISSION_POOL);

        if ($ids === []) {
            return [];
        }

        $pools = MissionPool::query()->whereIn('id', $ids)->where('is_active', true)
            ->get(['id', 'name', 'description', 'period_type', 'starts_at', 'ends_at'])
            ->filter(fn (MissionPool $p) => $p->isWithinWindow($now))->keyBy('id');

        if ($pools->isEmpty()) {
            return [];
        }

        $mine = collect();

        if ($user !== null) {
            $keys = [$this->periods->dailyContext($now)->periodKey, $this->periods->weeklyContext($now)->periodKey];
            $mine = DB::table('user_mission_assignments as a')
                ->join('quest_definitions as q', 'q.id', '=', 'a.quest_definition_id')
                ->leftJoin('user_quest_progress as p', function ($join) {
                    $join->on('p.user_id', '=', 'a.user_id')->on('p.quest_definition_id', '=', 'a.quest_definition_id')->on('p.period_key', '=', 'a.period_key');
                })
                ->where('a.user_id', $user->getKey())->whereIn('a.mission_pool_id', $pools->keys()->all())->whereIn('a.period_key', $keys)
                ->orderBy('a.slot')->orderBy('a.id')
                ->get(['a.mission_pool_id', 'q.name', 'q.target_value', 'p.current_value', 'p.target_value_snapshot', 'p.completed_at'])
                ->groupBy('mission_pool_id');
        }

        $rows = [];

        foreach ($links->where('type', SeasonContentItem::TYPE_MISSION_POOL)->sortBy(['sort_order', 'id']) as $link) {
            $pool = $pools->get($link['target_id']);

            if ($pool === null) {
                continue;
            }

            $assigned = ($mine->get($pool->getKey()) ?? collect())->map(function ($r) {
                $target = (int) ($r->target_value_snapshot ?? $r->target_value);

                return [
                    'name' => $r->name,
                    'current' => min((int) ($r->current_value ?? 0), max(1, $target)),
                    'target' => max(1, $target),
                    'completed' => $r->completed_at !== null,
                ];
            })->values()->all();

            $rows[] = [
                'title' => $link['label'] ?: $pool->name,
                'description' => $pool->description,
                'period' => $pool->period_type === 'weekly' ? 'أسبوعية' : 'يومية',
                'assigned' => $assigned,
                'season_phase' => $link['phase_id'] !== null ? $phaseNames->get($link['phase_id']) : null,
                'is_featured' => (bool) $link['is_featured'],
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    protected function passRow(Season $season, ?User $user, CarbonInterface $now): ?array
    {
        if ($season->ahjiyat_pass_id === null) {
            return null;
        }

        $season->loadMissing('pass');
        $pass = $season->pass;

        if ($pass === null || ! $pass->isPubliclyVisible($now)) {
            return null;
        }

        $active = $pass->isActiveNow($now);
        $row = [
            'name' => $pass->name,
            'subtitle' => $pass->subtitle,
            'status' => $pass->effectiveStatus($now),
            'status_label' => $active ? 'جارٍ الآن' : 'انتهى',
            'active' => $active,
            'starts_at' => $pass->starts_at,
            'ends_at' => $pass->ends_at,
            'url' => $user === null ? route('login') : ($active ? route('pass.index') : route('pass.archive.show', $pass->public_id)),
            'user' => null,
        ];

        if ($user !== null) {
            $track = $this->passCache->track($pass);
            $progress = $this->passProgress->find($user, $pass);
            $points = (int) ($progress?->pass_points ?? 0);
            $nextTier = $track->nextTier($points);
            $row['user'] = [
                'joined' => $progress !== null,
                'tier' => $track->tierAt($points),
                'max_tier' => $track->maxTier(),
                'points' => $points,
                'next_tier_points' => $nextTier['points'] ?? null,
                'premium' => $progress?->hasPremium() ?? false,
            ];
        }

        return $row;
    }

    // ───────────────────────── مقاطع الجدول والمؤشرات والإجراء التالي ─────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<int, SeasonPhase>  $phases
     * @param  list<array<string, mixed>>  $campaignRows
     * @param  list<array<string, mixed>>  $eventRows
     * @param  array<string, mixed>|null  $pass
     * @return list<array<string, mixed>>
     */
    protected function spans(Season $season, $phases, array $campaignRows, array $eventRows, ?array $pass): array
    {
        $home = route('seasons.show', $season);
        $spans = [];

        foreach ($phases as $p) {
            $spans[] = ['type' => SeasonCalendarService::TYPE_PHASE, 'key' => 'phase:'.$p->getKey(), 'title' => $p->name, 'starts' => $p->starts_at, 'ends' => $p->ends_at, 'url' => $home.'#phases', 'badge' => 'مرحلة'];
        }

        foreach ($eventRows as $i => $e) {
            $spans[] = ['type' => 'competitive_event', 'key' => 'event:'.$i, 'title' => $e['title'], 'starts' => $e['starts_at'], 'ends' => $e['ends_at'], 'url' => $e['url'], 'badge' => 'منافسة'];
        }

        foreach ($campaignRows as $i => $c) {
            $spans[] = ['type' => 'campaign', 'key' => 'campaign:'.$i, 'title' => $c['title'], 'starts' => $c['starts_at'], 'ends' => $c['ends_at'], 'url' => $c['url'], 'badge' => 'حملة'];
        }

        if ($pass !== null) {
            $spans[] = ['type' => SeasonCalendarService::TYPE_PASS, 'key' => 'pass', 'title' => $pass['name'], 'starts' => $pass['starts_at'], 'ends' => $pass['ends_at'], 'url' => $pass['url'], 'badge' => 'مسار'];
        }

        return $spans;
    }

    /**
     * مؤشرات وصفية صادقة (مقام حقيقي دائمًا): لا نقاط موسمية مستقلة ولا ترتيب ولا حساب جديد.
     *
     * @param  array<string, mixed>  $primary
     * @param  list<array<string, mixed>>  $campaignRows
     * @param  list<array<string, mixed>>  $eventRows
     * @param  list<array<string, mixed>>  $missionRows
     * @param  array<string, mixed>|null  $pass
     * @return list<array{label: string, value: string}>
     */
    protected function indicators(?User $user, array $primary, array $campaignRows, array $eventRows, array $missionRows, ?array $pass): array
    {
        if ($user === null) {
            return [];
        }

        $out = [];

        if (isset($primary['percentage'])) {
            $out[] = ['label' => 'الرحلة الرئيسية', 'value' => $primary['percentage'].'٪'];
        }

        if ($campaignRows !== []) {
            $done = count(array_filter($campaignRows, fn ($r) => $r['user_state'] === 'completed'));
            $out[] = ['label' => 'الحملات الإضافية', 'value' => $done.' من '.count($campaignRows).' مكتملة'];
        }

        if ($eventRows !== []) {
            $joined = count(array_filter($eventRows, fn ($r) => $r['registered'] === true));
            $out[] = ['label' => 'المنافسات', 'value' => 'مسجّل في '.$joined.' من '.count($eventRows)];
        }

        $assigned = collect($missionRows)->pluck('assigned')->flatten(1);

        if ($assigned->isNotEmpty()) {
            $out[] = ['label' => 'المهام الموسمية', 'value' => $assigned->where('completed', true)->count().' من '.$assigned->count().' مكتملة'];
        }

        if ($pass !== null && $pass['user'] !== null && $pass['user']['joined']) {
            $out[] = ['label' => 'مسار الأحجيات', 'value' => 'الشريحة '.$pass['user']['tier'].' من '.$pass['user']['max_tier']];
        }

        return $out;
    }

    /**
     * الإجراء التالي حتمي بترتيب ثابت (لا عشوائية ولا تخمين): الرحلة الرئيسية ← المسار الجاري ← منافسة مسجَّل فيها وجارية أو منافسة تسجيلها مفتوح ← مهام موسمية غير مكتملة ← بداية المرحلة القادمة.
     * لا إجراء إلا لموسم مباشر ولمستخدم مسجَّل الدخول.
     *
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>|null  $pass
     * @param  list<array<string, mixed>>  $eventRows
     * @param  list<array<string, mixed>>  $missionRows
     * @param  array<string, mixed>|null  $next
     * @return array{label: string, url: ?string, reason: string}|null
     */
    protected function nextAction(string $state, ?User $user, array $primary, ?array $pass, array $eventRows, array $missionRows, ?SeasonPhase $next): ?array
    {
        if ($state !== SeasonLifecycleService::STATE_LIVE || $user === null) {
            return null;
        }

        if (($primary['available'] ?? false) && ! empty($primary['current_step_url'])) {
            return ['label' => 'تابع رحلتك الرئيسية', 'url' => $primary['current_step_url'], 'reason' => 'خطوتك التالية جاهزة.'];
        }

        if ($pass !== null && $pass['active']) {
            return ['label' => 'افتح مسار الأحجيات', 'url' => $pass['url'], 'reason' => 'المسار الموسمي جارٍ الآن.'];
        }

        foreach ($eventRows as $e) {
            if ($e['phase'] === CompetitiveEvent::PHASE_LIVE && $e['registered'] === true) {
                return ['label' => 'العب منافستك', 'url' => $e['url'], 'reason' => 'أنت مسجّل في منافسة جارية: '.$e['title']];
            }
        }

        foreach ($eventRows as $e) {
            if ($e['registration_open'] && $e['registered'] === false && ! $e['is_full']) {
                return ['label' => 'سجّل في منافسة', 'url' => $e['url'], 'reason' => 'التسجيل مفتوح: '.$e['title']];
            }
        }

        foreach ($missionRows as $m) {
            if (collect($m['assigned'])->contains(fn ($a) => ! $a['completed'])) {
                return ['label' => 'أكمل مهامك الموسمية', 'url' => route('missions.index'), 'reason' => 'لديك مهام موسمية غير مكتملة.'];
            }
        }

        if ($next !== null) {
            return ['label' => 'المرحلة القادمة: '.$next->name, 'url' => null, 'reason' => 'تبدأ '.$next->starts_at->translatedFormat('j F')];
        }

        return null;
    }
}

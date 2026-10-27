<?php

namespace App\Services\Seasons;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Season;
use App\Models\SeasonContentItem;
use App\Models\User;
use App\Services\Analytics\CampaignAnalyticsService;
use App\Services\Engagement\QuestPeriodService;
use App\Services\SeasonLifecycleService;
use Illuminate\Support\Facades\DB;

/**
 * E42 - تحليلات الموسم للمشرفين: **تجميعات من مصادر موجودة فقط** (تقدّم الحملات، مشاركات المنافسات، إسناد المهام، أعداد المسار)، بلا جدول تتبّع جديد ولا بيانات فردية.
 * لا مبالغ ولا أسعار ولا هويات مستخدمين: أعداد ونسب فقط. صلاحية seasons.view تُفحص داخل الخدمة. حملات الأرقام الثقيلة تأتي من CampaignAnalyticsService (كاشه 10 دقائق).
 *
 * ما لم يُنفَّذ عمدًا (لا مصدر بيانات له بلا تتبّع جديد، ولا نخمّنه): مشاهدات صفحة الموسم، النقر على بطاقة المسار، تفاعل الأرشيف، توزيع اللاعبين على المراحل الحالية.
 * تظهر في الواجهة تحت «غير متاح بعد» بدل أرقام مختلقة.
 */
final class SeasonAnalyticsService
{
    public const NOT_TRACKED = ['مشاهدات صفحة الموسم', 'النقر على بطاقة المسار', 'تفاعل الأرشيف', 'توزيع اللاعبين على المراحل'];

    public function __construct(
        protected SeasonLifecycleService $lifecycle,
        protected CampaignAnalyticsService $campaigns,
        protected QuestPeriodService $periods,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws SeasonException
     */
    public function overview(User $actor, Season $season): array
    {
        if (! $actor->can('seasons.view')) {
            throw new SeasonException('forbidden', 'لا تملك صلاحية عرض تحليلات المواسم.');
        }

        $season->loadMissing('campaign', 'pass');
        $now = now();
        $state = $this->lifecycle->state($season, $now);
        $items = $season->contentItems()->get();

        return [
            'state' => $state,
            'state_label' => $this->lifecycle->stateLabel($state),
            'window_start' => $this->lifecycle->windowStart($season),
            'window_end' => $this->lifecycle->windowEnd($season),
            'primary' => $season->campaign ? $this->campaignRow($season->campaign) : null,
            'extra_campaigns' => $this->extraCampaigns($items),
            'phases' => $season->phases()->get()->map(fn ($p) => ['name' => $p->name, 'state' => $p->stateAt($now), 'label' => \App\Models\SeasonPhase::stateLabel($p->stateAt($now)), 'notified' => $p->went_live_at !== null])->all(),
            'competitions' => $this->competitions($items),
            'mission_pools' => $this->missionPools($items, $now),
            'pass' => $this->pass($season),
            'announcements' => $this->announcements($season, $now),
            'not_tracked' => self::NOT_TRACKED,
        ];
    }

    /** @return array<string, mixed> */
    protected function campaignRow(Campaign $campaign): array
    {
        $stats = $this->campaigns->forCampaign($campaign);

        return [
            'title' => $campaign->title,
            'participants' => (int) $stats['participants'],
            'completed' => (int) $stats['completed'],
            'completion_rate' => (float) $stats['completion_rate'],
            'active' => (int) $stats['active_participants'],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function extraCampaigns($items): array
    {
        $ids = $items->where('content_type', SeasonContentItem::TYPE_CAMPAIGN)->pluck('campaign_id')->all();

        return $ids === [] ? [] : Campaign::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'title'])->map(fn (Campaign $c) => $this->campaignRow($c))->all();
    }

    /** @return list<array<string, mixed>> */
    protected function competitions($items): array
    {
        $ids = $items->where('content_type', SeasonContentItem::TYPE_COMPETITIVE_EVENT)->pluck('competitive_event_id')->all();

        return $ids === [] ? [] : CompetitiveEvent::query()->whereIn('id', $ids)->orderBy('starts_at')->get(['id', 'title', 'status', 'starts_at', 'ends_at', 'participants_count'])
            ->map(fn (CompetitiveEvent $e) => ['title' => $e->title, 'phase' => $e->phase(), 'participants' => (int) $e->participants_count])->all();
    }

    /**
     * عدد اللاعبين المُسنَد إليهم من كل مجموعة في الفترتين الحاليتين (يومي/أسبوعي). عدّ فقط - لا قائمة مهام ولا هويات.
     *
     * @return list<array<string, mixed>>
     */
    protected function missionPools($items, $now): array
    {
        $ids = $items->where('content_type', SeasonContentItem::TYPE_MISSION_POOL)->pluck('mission_pool_id')->all();

        if ($ids === []) {
            return [];
        }

        $keys = [$this->periods->dailyContext($now)->periodKey, $this->periods->weeklyContext($now)->periodKey];
        $counts = DB::table('user_mission_assignments')->whereIn('mission_pool_id', $ids)->whereIn('period_key', $keys)
            ->select('mission_pool_id', DB::raw('COUNT(DISTINCT user_id) as users'))->groupBy('mission_pool_id')->pluck('users', 'mission_pool_id');

        return \App\Models\MissionPool::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'name', 'is_active'])
            ->map(fn ($p) => ['name' => $p->name, 'active' => (bool) $p->is_active, 'assigned_users' => (int) ($counts[$p->id] ?? 0)])->all();
    }

    /** @return array<string, mixed>|null أعداد فقط (لا أسعار ولا طلبات ولا مبالغ). */
    protected function pass(Season $season): ?array
    {
        if ($season->pass === null) {
            return null;
        }

        $row = DB::table('user_pass_progress')->where('ahjiyat_pass_id', $season->pass->getKey())
            ->selectRaw('COUNT(*) as joined, SUM(CASE WHEN premium_unlocked_at IS NULL THEN 0 ELSE 1 END) as premium')->first();

        return ['name' => $season->pass->name, 'joined' => (int) ($row->joined ?? 0), 'premium' => (int) ($row->premium ?? 0)];
    }

    /** @return array<string, int> */
    protected function announcements(Season $season, $now): array
    {
        $counts = ['draft' => 0, 'scheduled' => 0, 'live' => 0, 'expired' => 0, 'notified' => 0];

        foreach ($season->announcements()->get() as $a) {
            $counts[$a->statusAt($now)]++;
            $counts['notified'] += $a->notified_at !== null ? 1 : 0;
        }

        return $counts;
    }
}

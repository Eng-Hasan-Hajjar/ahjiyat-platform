<?php

namespace App\Services\LiveOps;

use App\Models\Campaign;
use App\Models\MissionPool;
use App\Models\QuestDefinition;
use App\Services\LiveOps\Calendar\EventCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * E34 - مخطِّط LiveOps (طبقة قراءة/إدارة فوق نماذج قائمة؛ لا تخزّن شيئًا ولا تغيّر شيئًا): يجمع الجدول الزمني الحالي للأحداث (من التقويم نفسه)
 * والمهام والمجموعات، ويكشف **تحذيرات** تعارض/إفراط. التحذير لا يمنع إجراءً إداريًا صالحًا؛ الحدّ الصلب الوحيد على تفعيل مجموعة
 * يرفع الحدّ الأعلى النظري لمهام المستخدم المتزامنة فوق الحدّ الصلب المضبوط (غير آمن فعلًا، رفض مفهوم السبب).
 *
 * لا نموذج مالي مخترَع: "الثقل" = مجموع مبالغ العملة المُعلَنة بتعريفات المهام (أعلى كسب نظري لمستخدم واحد في الفترة) مقارنةً بعتبات config فقط.
 * الرعاية لا تُطبَّق على المهام تلقائيًا ولا يقرأ هذا المخطِّط أي مبلغ رعاية.
 */
class LiveOpsPlannerService
{
    public function __construct(
        protected EventCalendarService $calendar,
        protected MissionAssignmentService $assignments,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $now = now();
        $quests = QuestDefinition::query()->with('rewardCurrency:id,short_name')->get();
        $pooled = array_flip($this->assignments->pooledQuestIds());
        $live = $quests->filter(fn (QuestDefinition $q) => $q->is_active && $this->withinWindow($q, $now));

        $pools = MissionPool::query()->withCount('quests')->orderBy('priority')->orderBy('id')->get();

        $potential = $this->potential($live, $pooled, $pools);

        return [
            'now' => $now,
            'timezone' => $this->calendar->timezone(),
            'events' => $this->calendar->items(),
            'quests' => [
                'daily' => $this->questSummary($live, $pooled, QuestDefinition::PERIOD_DAILY),
                'weekly' => $this->questSummary($live, $pooled, QuestDefinition::PERIOD_WEEKLY),
            ],
            'pools' => $pools,
            'potential' => $potential,
            'warnings' => $this->warnings($quests, $live, $pooled, $pools, $potential, $now),
        ];
    }

    protected function withinWindow(QuestDefinition $q, Carbon $now): bool
    {
        return ($q->starts_at === null || ! $now->lt($q->starts_at)) && ($q->ends_at === null || ! $now->gt($q->ends_at));
    }

    /** @return array{active:int, global:int, pooled:int} */
    protected function questSummary(Collection $live, array $pooled, string $type): array
    {
        $of = $live->where('period_type', $type);

        return [
            'active' => $of->count(),
            'global' => $of->reject(fn (QuestDefinition $q) => isset($pooled[$q->id]))->count(),
            'pooled' => $of->filter(fn (QuestDefinition $q) => isset($pooled[$q->id]))->count(),
        ];
    }

    /**
     * أعلى حدّ نظري لما يراه/يكسبه مستخدم واحد في الفترة: مهام عامة كلها + (لكل نوع فترة) أكبر مساهمة مجموعة (أفضل selection_count مهمة مكافأةً).
     *
     * @return array{missions:int, daily_reward:int, weekly_reward:int}
     */
    public function potential(Collection $live, array $pooled, Collection $pools): array
    {
        $reward = fn (QuestDefinition $q) => (int) ($q->reward_currency_amount ?? 0);
        $missions = 0;
        $rewards = ['daily' => 0, 'weekly' => 0];

        foreach ([QuestDefinition::PERIOD_DAILY, QuestDefinition::PERIOD_WEEKLY] as $type) {
            $global = $live->where('period_type', $type)->reject(fn (QuestDefinition $q) => isset($pooled[$q->id]));
            $missions += $global->count();
            $rewards[$type] += $global->sum($reward);

            $bestMissions = 0;
            $bestReward = 0;

            foreach ($pools->where('is_active', true)->where('period_type', $type) as $pool) {
                $members = $live->where('period_type', $type)->filter(fn (QuestDefinition $q) => $this->isMember($pool, $q));
                $top = $members->sortByDesc($reward)->take((int) $pool->selection_count);
                $bestMissions = max($bestMissions, $top->count());
                $bestReward = max($bestReward, $top->sum($reward));
            }

            $missions += $bestMissions;
            $rewards[$type] += $bestReward;
        }

        return ['missions' => $missions, 'daily_reward' => (int) $rewards['daily'], 'weekly_reward' => (int) $rewards['weekly']];
    }

    protected function isMember(MissionPool $pool, QuestDefinition $q): bool
    {
        return in_array($q->id, $this->memberIds($pool), true);
    }

    /** @var array<int, list<int>> */
    private array $memberCache = [];

    /** @return list<int> */
    protected function memberIds(MissionPool $pool): array
    {
        return $this->memberCache[$pool->id] ??= \Illuminate\Support\Facades\DB::table('mission_pool_quests')
            ->where('mission_pool_id', $pool->id)->pluck('quest_definition_id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * @param  array<int,int>  $pooled
     * @param  array{missions:int, daily_reward:int, weekly_reward:int}  $potential
     * @return list<array{code:string, level:string, message:string}>
     */
    protected function warnings(Collection $quests, Collection $live, array $pooled, Collection $pools, array $potential, Carbon $now): array
    {
        $out = [];
        $warn = (int) config('liveops.budget.max_concurrent_missions_warn', 12);
        $hard = (int) config('liveops.budget.max_concurrent_missions_hard', 40);

        if ($potential['missions'] > $hard) {
            $out[] = ['code' => 'missions_over_hard_cap', 'level' => 'critical', 'message' => "الحدّ الأعلى النظري لمهام المستخدم المتزامنة ({$potential['missions']}) تجاوز الحدّ الصلب ({$hard}). خفّض المهام الفعّالة أو selection_count."];
        } elseif ($potential['missions'] > $warn) {
            $out[] = ['code' => 'too_many_missions', 'level' => 'warning', 'message' => "مهام متزامنة كثيرة لمستخدم واحد ({$potential['missions']} > {$warn}). قد يرهق اللاعب؛ راجع المجموعات."];
        }

        foreach ([['daily', 'daily_gems_warn', 'اليومية', 'daily_reward'], ['weekly', 'weekly_gems_warn', 'الأسبوعية', 'weekly_reward']] as [$type, $key, $label, $field]) {
            $limit = (int) config("liveops.budget.{$key}", 0);

            if ($limit > 0 && $potential[$field] > $limit) {
                $out[] = ['code' => "reward_heavy_{$type}", 'level' => 'warning', 'message' => "مجموع مكافآت المهام {$label} (حدّ أعلى نظري لمستخدم واحد) {$potential[$field]} يتجاوز العتبة {$limit}."];
            }
        }

        foreach ($quests as $q) {
            if ($q->starts_at !== null && $q->ends_at !== null && $q->ends_at->lte($q->starts_at)) {
                $out[] = ['code' => 'invalid_window', 'level' => 'warning', 'message' => "نافذة زمنية غير صالحة للمهمة «{$q->name}» (النهاية قبل البداية)."];
            } elseif ($q->is_active && $q->ends_at !== null && $now->gt($q->ends_at)) {
                $out[] = ['code' => 'expired_but_active', 'level' => 'info', 'message' => "المهمة «{$q->name}» منتهية النافذة لكنها ما زالت مفعّلة (لا تظهر للاعبين)."];
            }
        }

        foreach ($pools->where('is_active', true) as $pool) {
            $valid = $live->where('period_type', $pool->period_type)->filter(fn (QuestDefinition $q) => $this->isMember($pool, $q))->count();

            if ($valid < (int) $pool->selection_count) {
                $out[] = ['code' => 'pool_thin', 'level' => 'info', 'message' => "المجموعة «{$pool->name}» فعّالة بـ{$valid} مهمة صالحة فقط وselection_count = {$pool->selection_count}."];
            }
        }

        // حملات مستقلة بنفس العنوان وبنوافذ متداخلة (تكرار محتمل يربك اللاعب).
        $campaigns = Campaign::query()->where('is_active', true)->get(['id', 'title', 'starts_at', 'ends_at']);

        foreach ($campaigns->groupBy(fn (Campaign $c) => mb_strtolower(trim($c->title))) as $title => $group) {
            if ($group->count() > 1 && $this->anyOverlap($group)) {
                $out[] = ['code' => 'duplicate_campaign_overlap', 'level' => 'warning', 'message' => "حملتان فعّالتان بنفس العنوان «{$group->first()->title}» بنوافذ متداخلة."];
            }
        }

        return $out;
    }

    /** @param  Collection<int, Campaign>  $group */
    protected function anyOverlap(Collection $group): bool
    {
        $items = $group->values();

        for ($i = 0; $i < $items->count(); $i++) {
            for ($j = $i + 1; $j < $items->count(); $j++) {
                $a = $items[$i];
                $b = $items[$j];
                $aStart = $a->starts_at?->getTimestamp() ?? 0;
                $bStart = $b->starts_at?->getTimestamp() ?? 0;
                $aEnd = $a->ends_at?->getTimestamp() ?? PHP_INT_MAX;
                $bEnd = $b->ends_at?->getTimestamp() ?? PHP_INT_MAX;

                if ($aStart <= $bEnd && $bStart <= $aEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * يُستدعى من MissionPoolService::activate: هل يتجاوز تفعيل هذه المجموعة الحدّ الصلب لمهام المستخدم المتزامنة؟ (الاستثناء الوحيد "غير الآمن").
     */
    public function wouldExceedHardCap(MissionPool $pool): bool
    {
        $hard = (int) config('liveops.budget.max_concurrent_missions_hard', 40);
        $quests = QuestDefinition::query()->get();
        $now = now();
        $pooled = array_flip($this->assignments->pooledQuestIds());
        $live = $quests->filter(fn (QuestDefinition $q) => $q->is_active && $this->withinWindow($q, $now));
        $pools = MissionPool::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $pool->id))->get()
            ->each(fn (MissionPool $p) => $p->is_active = true);

        return $this->potential($live, $pooled, $pools)['missions'] > $hard;
    }
}

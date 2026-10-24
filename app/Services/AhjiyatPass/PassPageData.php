<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Models\AhjiyatPassTierReward;
use App\Models\DigitalPurchase;
use App\Models\PassPointGrant;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserPassRewardClaim;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Support\Facades\Cache;

/**
 * E40 - بيانات صفحة المسار للاعب (قراءة شبه صرفة، استعلامات محدودة بعدد ثابت لا يتناسب مع عدد اللاعبين):
 *  تعريف المسار من الكاش المحلي (يُبنى على الشرائح/المكافآت/المهام)، ثم لحالة اللاعب: صف تقدّم + مطالباته + منح نقاطه الأخيرة + تقدّم مهامه في الفترة الحالية + آخر طلب شراء.
 *  لا تخزين لأي بيانات لاعب في الكاش. الصفحة تنضم كسولًا (ensure) وتستعيد ما فاته (heal) مرة كل دقيقة كحدّ أقصى لكل لاعب.
 *  لا استعلام لكل صف (لا N+1): كل ما سبق استعلامات مجمّعة.
 */
class PassPageData
{
    public function __construct(
        protected PassLifecycleService $lifecycle,
        protected PassDefinitionCache $cache,
        protected PassProgressService $progress,
        protected PassPointService $points,
        protected QuestPeriodService $periods,
    ) {}

    /** المسار الذي تعرضه الصفحة الآن: الفعّال، وإلا آخر مسار منتهٍ ما زالت مهلة استلامه مفتوحة. */
    public function displayPass(): ?AhjiyatPass
    {
        $current = $this->lifecycle->current();

        if ($current !== null) {
            return $current;
        }

        return $this->lifecycle->claimWindowPasses()->first(fn (AhjiyatPass $p): bool => $p->ends_at->lte(now()));
    }

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, ?AhjiyatPass $pass = null): array
    {
        $pass ??= $this->displayPass();

        if ($pass === null) {
            return ['pass' => null];
        }

        return $this->build($user, $pass);
    }

    /**
     * ملخص صغير لودجت الرئيسية: قراءة فقط (لا انضمام ولا استعادة ولا كتابة). null عند التعطيل/التجميد/غياب مسار، أو مسار منتهٍ بلا شيء للاستلام.
     *
     * @return array<string, mixed>|null
     */
    public function widgetFor(User $user): ?array
    {
        if (! (bool) config('ahjiyat_pass.enabled', true) || $user->is_frozen) {
            return null;
        }

        $pass = $this->displayPass();

        if ($pass === null) {
            return null;
        }

        $track = $this->cache->track($pass);
        $progress = $this->progress->find($user, $pass);
        $points = (int) ($progress?->pass_points ?? 0);
        $highest = (int) ($progress?->highest_tier ?? $track->tierAt($points));
        $claimedIds = $progress === null ? [] : UserPassRewardClaim::query()->where('user_pass_progress_id', $progress->getKey())->pluck('ahjiyat_pass_tier_reward_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        $claimable = $pass->isClaimWindowOpen() ? count($track->claimable($highest, (bool) $progress?->hasPremium(), $claimedIds)) : 0;
        $active = $pass->isActiveNow();

        if (! $active && $claimable === 0) {
            return null;
        }

        $next = $track->nextTier($points);
        $previous = $this->previousThreshold($track, $points);

        return [
            'pass' => $pass,
            'active' => $active,
            'tier' => $highest,
            'max_tier' => $track->maxTier(),
            'points' => $points,
            'next_remaining' => $next === null ? 0 : max(0, (int) $next['points'] - $points),
            'ratio' => $next === null ? 1.0 : min(1.0, max(0.0, ($points - $previous) / max(1, (int) $next['points'] - $previous))),
            'claimable_count' => $claimable,
            'has_premium' => (bool) $progress?->hasPremium(),
            'days_left' => $active ? max(0, (int) ceil(now()->diffInSeconds($pass->ends_at, false) / 86400)) : 0,
        ];
    }

    /** @return array<string, mixed> */
    public function build(User $user, AhjiyatPass $pass): array
    {
        $track = $this->cache->track($pass);
        $active = $pass->isActiveNow();
        $claimOpen = $pass->isClaimWindowOpen();
        $progress = $this->progress->find($user, $pass);

        if ($progress === null && $active && ! $user->is_frozen) {
            $progress = $this->progress->ensure($user, $pass);
        }

        if ($progress !== null && $claimOpen && ! $user->is_frozen && Cache::add('ahjiyat_pass:heal:'.$pass->getKey().':'.$user->getKey(), 1, 60)) {
            try {
                if ($this->points->healFor($user, $pass) > 0) {
                    $progress = $this->progress->find($user, $pass);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $points = (int) ($progress?->pass_points ?? 0);
        $highest = (int) ($progress?->highest_tier ?? $track->tierAt($points));
        $hasPremium = (bool) $progress?->hasPremium();
        $claimedIds = $progress === null ? [] : UserPassRewardClaim::query()
            ->where('user_pass_progress_id', $progress->getKey())
            ->pluck('ahjiyat_pass_tier_reward_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $claimable = $claimOpen && ! $user->is_frozen ? $track->claimable($highest, $hasPremium, $claimedIds) : [];
        $next = $track->nextTier($points);
        $previousPoints = $this->previousThreshold($track, $points);

        return [
            'pass' => $pass,
            'state' => $active ? 'active' : 'ended',
            'claim_open' => $claimOpen,
            'frozen' => (bool) $user->is_frozen,
            'track' => $track,
            'progress' => $progress,
            'points' => $points,
            'tier' => $highest,
            'max_tier' => $track->maxTier(),
            'next_tier' => $next,
            'next_remaining' => $next === null ? 0 : max(0, (int) $next['points'] - $points),
            'ratio' => $next === null ? 1.0 : min(1.0, max(0.0, ($points - $previousPoints) / max(1, (int) $next['points'] - $previousPoints))),
            'has_premium' => $hasPremium,
            'claimed_ids' => $claimedIds,
            'claimable' => $claimable,
            'claimable_count' => count($claimable),
            'tiers' => $this->tierRows($track, $highest, $hasPremium, $claimedIds, $claimOpen),
            'missions' => $this->missions($user, $pass, $track, $progress),
            'recent' => $progress === null ? collect() : $this->recent($progress),
            'premium' => $this->premium($user, $pass, $track, $hasPremium),
            'days_left' => $active ? max(0, (int) ceil(now()->diffInSeconds($pass->ends_at, false) / 86400)) : 0,
            'claim_days_left' => $claimOpen ? max(0, (int) ceil(now()->diffInSeconds($pass->claim_ends_at, false) / 86400)) : 0,
        ];
    }

    protected function previousThreshold(PassTrack $track, int $points): int
    {
        $previous = 0;

        foreach ($track->tiers as $tier) {
            if ($points >= (int) $tier['points']) {
                $previous = (int) $tier['points'];
            } else {
                break;
            }
        }

        return $previous;
    }

    /**
     * @param  array<int, true>  $claimedIds
     * @return list<array<string, mixed>>
     */
    protected function tierRows(PassTrack $track, int $highest, bool $hasPremium, array $claimedIds, bool $claimOpen): array
    {
        $rows = [];

        foreach ($track->tiers as $tier) {
            $number = (int) $tier['number'];
            $reached = $number <= $highest;
            $slots = $track->rewards[$number] ?? ['free' => null, 'premium' => null];

            $rows[] = [
                'number' => $number,
                'points' => (int) $tier['points'],
                'label' => $tier['label'],
                'milestone' => (bool) $tier['milestone'],
                'reached' => $reached,
                'free' => $this->rewardState($slots['free'] ?? null, $reached, true, $claimedIds, $claimOpen),
                'premium' => $this->rewardState($slots['premium'] ?? null, $reached, $hasPremium, $claimedIds, $claimOpen),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $reward
     * @param  array<int, true>  $claimedIds
     * @return array<string, mixed>|null
     */
    protected function rewardState(?array $reward, bool $reached, bool $trackOpen, array $claimedIds, bool $claimOpen): ?array
    {
        if ($reward === null) {
            return null;
        }

        $state = match (true) {
            isset($claimedIds[(int) $reward['id']]) => 'claimed',
            ! $reached => 'locked_tier',
            ! $trackOpen => 'locked_premium',
            ! $claimOpen => 'expired',
            default => 'claimable',
        };

        return $reward + ['state' => $state];
    }

    /** @return array{easy: list<array<string, mixed>>, medium: list<array<string, mixed>>, hard: list<array<string, mixed>>} */
    protected function missions(User $user, AhjiyatPass $pass, PassTrack $track, ?UserPassProgress $progress): array
    {
        $groups = [AhjiyatPassMission::EASY => [], AhjiyatPassMission::MEDIUM => [], AhjiyatPassMission::HARD => []];

        if ($track->missions === []) {
            return $groups;
        }

        $quests = QuestDefinition::query()->whereIn('id', array_keys($track->missions))->get()->keyBy('id');
        $keys = array_values(array_unique(array_filter([
            $this->periods->dailyContext()->periodKey,
            $this->periods->weeklyContext()->periodKey,
        ])));

        $current = UserQuestProgress::query()
            ->where('user_id', $user->getKey())
            ->whereIn('quest_definition_id', array_keys($track->missions))
            ->whereIn('period_key', $keys)
            ->get()
            ->keyBy('quest_definition_id');

        $taken = $progress === null ? collect() : PassPointGrant::query()
            ->where('user_pass_progress_id', $progress->getKey())
            ->whereNotNull('ahjiyat_pass_mission_id')
            ->selectRaw('ahjiyat_pass_mission_id, COUNT(*) as taken, SUM(points) as earned')
            ->groupBy('ahjiyat_pass_mission_id')
            ->get()
            ->keyBy('ahjiyat_pass_mission_id');

        $ordered = $track->missions;
        uasort($ordered, fn (array $a, array $b): int => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);

        foreach ($ordered as $questId => $mission) {
            $quest = $quests->get($questId);

            if ($quest === null) {
                continue;
            }

            $row = $current->get($questId);
            $done = (int) ($taken->get($mission['id'])->taken ?? 0);
            $remaining = max(0, (int) $mission['max_completions'] - $done);

            $groups[$mission['difficulty']][] = [
                'id' => $mission['id'],
                'name' => (string) $quest->name,
                'description' => $quest->description,
                'period' => (string) $quest->period_type,
                'points' => (int) $mission['points'],
                'featured' => (bool) $mission['featured'],
                'week' => $mission['week'],
                'target' => (int) ($row?->target_value_snapshot ?? $quest->target_value),
                'value' => (int) ($row?->current_value ?? 0),
                'completed_now' => (bool) $row?->completed_at,
                'times_counted' => $done,
                'max_completions' => (int) $mission['max_completions'],
                'remaining' => $remaining,
                'earned' => (int) ($taken->get($mission['id'])->earned ?? 0),
                'available' => $quest->is_active && $remaining > 0 && $pass->isActiveNow(),
            ];
        }

        return $groups;
    }

    /** @return \Illuminate\Support\Collection<int, PassPointGrant> */
    protected function recent(UserPassProgress $progress)
    {
        return PassPointGrant::query()
            ->where('user_pass_progress_id', $progress->getKey())
            ->with('mission.quest:id,name')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('ahjiyat_pass.page.recent_points_limit', 8)))
            ->get();
    }

    /** @return array<string, mixed> */
    protected function premium(User $user, AhjiyatPass $pass, PassTrack $track, bool $owned): array
    {
        $purchase = DigitalPurchase::query()
            ->where('user_id', $user->getKey())
            ->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)
            ->where('product_id', $pass->getKey())
            ->orderByDesc('id')
            ->first();

        $premiumRewards = array_values(array_filter($track->allRewards(), fn (array $r): bool => $r['track'] === AhjiyatPassTierReward::TRACK_PREMIUM));

        return [
            'enabled' => (bool) $pass->premium_enabled,
            'sales_open' => $pass->isPremiumSalesOpen(),
            'price_label' => $pass->premium_price_minor ? SponsorshipMoney::format((int) $pass->premium_price_minor, (string) $pass->currency) : null,
            'owned' => $owned,
            'purchase' => $purchase,
            'rewards' => $premiumRewards,
            'rewards_count' => count($premiumRewards),
            'sales_end_at' => $pass->premium_sales_end_at ?? $pass->ends_at,
        ];
    }
}

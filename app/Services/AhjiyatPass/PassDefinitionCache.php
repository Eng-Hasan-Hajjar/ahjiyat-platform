<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * E40 - كاش تعريف المسار فقط: (أ) المسار الفعّال الحالي، (ب) مسار الشرائح/المكافآت/المهام لكل مسار.
 * **لا يُخزَّن أبدًا** ما هو خاص بلاعب (نقاطه/مطالباته/ملكيته للمميز): تُقرأ دائمًا من القاعدة.
 * الإبطال: flush() يرفع رقم إصدار (كل مسارات البناء/دورة الحياة تستدعيه) + TTL قصير كحدّ أعلى + صلاحية داخلية بحدود زمنية (لا نخدم مسارًا انتهى ولا نُخفي مسارًا بدأ).
 */
class PassDefinitionCache
{
    private const VERSION_KEY = 'ahjiyat_pass:definition_version';

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    public function ttl(): int
    {
        return max(10, (int) config('ahjiyat_pass.cache.definition_seconds', 300));
    }

    /** المسار الفعّال الآن بالتواريخ (منشور وقيد النافذة) أو null. */
    public function current(): ?AhjiyatPass
    {
        $key = 'ahjiyat_pass:current:v'.$this->version();
        $cached = Cache::get($key);
        $now = now();

        if (is_array($cached) && isset($cached['valid_until']) && $now->lt(Carbon::parse($cached['valid_until']))) {
            return $cached['pass'] === null ? null : $this->hydrate($cached['pass']);
        }

        $pass = AhjiyatPass::query()
            ->whereNotNull('locked_at')
            ->whereIn('status', [AhjiyatPass::STATUS_SCHEDULED, AhjiyatPass::STATUS_ACTIVE])
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderByDesc('starts_at')
            ->first();

        $ceiling = $now->copy()->addSeconds($this->ttl());

        if ($pass !== null) {
            $validUntil = $pass->ends_at->lt($ceiling) ? $pass->ends_at : $ceiling;
        } else {
            $nextStart = AhjiyatPass::query()
                ->whereNotNull('locked_at')
                ->where('status', AhjiyatPass::STATUS_SCHEDULED)
                ->where('starts_at', '>', $now)
                ->min('starts_at');
            $validUntil = $nextStart !== null && Carbon::parse($nextStart)->lt($ceiling) ? Carbon::parse($nextStart) : $ceiling;
        }

        Cache::put($key, ['pass' => $pass?->getAttributes(), 'valid_until' => $validUntil->toIso8601String()], $this->ttl());

        return $pass;
    }

    public function track(AhjiyatPass $pass): PassTrack
    {
        $key = 'ahjiyat_pass:track:'.$pass->getKey().':v'.$this->version();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return PassTrack::fromArray($cached);
        }

        $track = $this->build($pass);

        // مسودة: لا نخزّن (تتغيّر باستمرار). المنشور فقط.
        if ($pass->locked_at !== null) {
            Cache::put($key, $track->toArray(), $this->ttl());
        }

        return $track;
    }

    public function build(AhjiyatPass $pass): PassTrack
    {
        $tiers = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('tier_number')->get();
        $rewards = AhjiyatPassTierReward::query()->where('ahjiyat_pass_id', $pass->getKey())->with('item:id,name,item_type,cosmetic_slot,image_path')->get();
        $missions = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('sort_order')->orderBy('id')->get();

        $tierList = [];
        $numberByTierId = [];

        foreach ($tiers as $tier) {
            $numberByTierId[$tier->id] = (int) $tier->tier_number;
            $tierList[] = ['id' => (int) $tier->id, 'number' => (int) $tier->tier_number, 'points' => (int) $tier->required_points, 'label' => $tier->label, 'milestone' => (bool) $tier->is_milestone];
        }

        $rewardMap = [];

        foreach ($tierList as $tier) {
            $rewardMap[$tier['number']] = ['free' => null, 'premium' => null];
        }

        foreach ($rewards as $reward) {
            $number = $numberByTierId[$reward->ahjiyat_pass_tier_id] ?? null;

            if ($number === null || $reward->item === null) {
                continue;
            }

            $rewardMap[$number][$reward->track] = [
                'id' => (int) $reward->id,
                'tier' => $number,
                'track' => $reward->track,
                'item_id' => (int) $reward->store_item_id,
                'item_name' => (string) $reward->item->name,
                'item_type' => (string) $reward->item->item_type,
                'slot' => $reward->item->cosmetic_slot,
                'quantity' => (int) $reward->quantity,
                'image' => $reward->item->image_path,
            ];
        }

        $missionMap = [];

        foreach ($missions as $mission) {
            $missionMap[(int) $mission->quest_definition_id] = [
                'id' => (int) $mission->id,
                'quest_id' => (int) $mission->quest_definition_id,
                'difficulty' => (string) $mission->difficulty,
                'points' => (int) $mission->pass_points,
                'max_completions' => (int) $mission->max_completions,
                'week' => $mission->week_number === null ? null : (int) $mission->week_number,
                'featured' => (bool) $mission->is_featured,
                'sort' => (int) $mission->sort_order,
            ];
        }

        return new PassTrack((int) $pass->getKey(), $tierList, $rewardMap, $missionMap);
    }

    /** @param array<string, mixed> $attributes */
    protected function hydrate(array $attributes): AhjiyatPass
    {
        /** @var AhjiyatPass $pass */
        $pass = (new AhjiyatPass)->newFromBuilder($attributes);

        return $pass;
    }
}

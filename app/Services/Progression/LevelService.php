<?php

namespace App\Services\Progression;

use App\Models\LevelDefinition;
use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\UserLevelUnlock;

use Illuminate\Support\Facades\DB;
class LevelService
{
    public function __construct(protected ProgressionRewardService $rewards) {}

    public function recalculateFor(User $user, PlayerProgression $progression): void
    {
        $highestEligible = LevelDefinition::where('is_active', true)
            ->where('xp_required_total', '<=', $progression->total_xp)
            ->orderByDesc('level_number')
            ->first();

        if ($highestEligible === null || $highestEligible->level_number <= $progression->current_level) {
            return;
        }

        $newlyCrossedLevels = LevelDefinition::where('is_active', true)
            ->where('level_number', '>', $progression->current_level)
            ->where('level_number', '<=', $highestEligible->level_number)
            ->orderBy('level_number')
            ->get();

               foreach ($newlyCrossedLevels as $level) {
            DB::transaction(function () use ($user, $level) {
                $unlock = UserLevelUnlock::firstOrCreate(
                    ['user_id' => $user->id, 'level_definition_id' => $level->id],
                    ['unlocked_at' => now()],
                );

                if ($unlock->reward_granted_at === null) {
                    $this->rewards->grantLevelRewards($level, $user, $unlock);
                    $unlock->update(['reward_granted_at' => now()]);
                }
            });
        }   

        $progression->update(['current_level' => $highestEligible->level_number]);
    }

    public function progressionFor(User $user): PlayerProgression
    {
        return PlayerProgression::firstOrCreate(['user_id' => $user->id]);
    }

       /**
     * لا تُلقي أبدًا حتى لو لم يوجد Level 1 بقاعدة البيانات إطلاقًا (بيئة
     * اختبار لم تُشغِّل ProgressionSeeder، مثلًا) - إرجاع كائن احتياطي غير
     * محفوظ بدل ModelNotFoundException، والتي كانت فعليًا تُصبح استجابة
     * 404 (اكتشاف انحدار حقيقي كسر PublicProfileTest/ProfilePrivacyTest
     * من E11 - أُصلِح هنا).
     */
    public function currentLevelFor(User $user): LevelDefinition
    {
        $progression = $this->progressionFor($user);

        return LevelDefinition::where('level_number', $progression->current_level)->first()
            ?? LevelDefinition::where('level_number', 1)->first()
            ?? new LevelDefinition(['level_number' => 1, 'name' => 'المستوى الأول', 'xp_required_total' => 0]);
    }

    public function nextLevelFor(User $user): ?LevelDefinition
    {
        $progression = $this->progressionFor($user);

        return LevelDefinition::where('is_active', true)
            ->where('level_number', '>', $progression->current_level)
            ->orderBy('level_number')
            ->first();
    }

    public function progressPercentFor(User $user): float
    {
        $progression = $this->progressionFor($user);
        $current = $this->currentLevelFor($user);
        $next = $this->nextLevelFor($user);

        if ($next === null) {
            return 100.0;
        }

        $range = $next->xp_required_total - $current->xp_required_total;

        if ($range <= 0) {
            return 100.0;
        }

        $progressedInLevel = $progression->total_xp - $current->xp_required_total;

        return round(min(100, max(0, ($progressedInLevel / $range) * 100)), 1);
    }
}
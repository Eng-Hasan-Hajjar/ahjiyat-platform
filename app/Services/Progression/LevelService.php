<?php

namespace App\Services\Progression;

use App\Models\LevelDefinition;
use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\UserLevelUnlock;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
class LevelService
{
    /** E24: كتالوج المستويات للعرض فقط. recalculateFor (مصدر الحقيقة لترقية المستوى/المكافآت) يقرأ القاعدة مباشرةً دومًا ولا يمرّ بالكاش. */
    public const CATALOG_CACHE_KEY = 'levels.catalog.v1';

    public function __construct(protected ProgressionRewardService $rewards) {}

    /** يُبطل كاش كتالوج المستويات (يُستدعى من أحداث LevelDefinition). فشل الكاش لا يكسر الحفظ. */
    public static function forgetCatalog(): void
    {
        try {
            Cache::forget(self::CATALOG_CACHE_KEY);
        } catch (\Throwable) {
            // لا شيء: انتهاء الصلاحية (TTL) يحدّ أي تأخّر.
        }
    }

    /**
     * كل المستويات مرتّبة برقمها (جدول صغير تحرّره الإدارة). يُخزَّن مصفوفات سمات لا كائنات (لا تبعية على تسلسل الأصناف عند النشر) وبمدة محدودة.
     *
     * @return Collection<int, LevelDefinition>
     */
    protected function catalog(): Collection
    {
        $load = fn () => LevelDefinition::query()->orderBy('level_number')->get();
        $ttl = (int) config('performance.cache.level_catalog_ttl', 3600);

        if ($ttl <= 0) {
            return $load();
        }

        try {
            $rows = Cache::remember(self::CATALOG_CACHE_KEY, $ttl, fn () => $load()->map(fn (LevelDefinition $l) => $l->getAttributes())->all());
        } catch (\Throwable) {
            return $load();
        }

        return LevelDefinition::hydrate($rows);
    }

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
    public function currentLevelFor(User $user, ?PlayerProgression $progression = null): LevelDefinition
    {
        $progression ??= $this->progressionFor($user);
        $catalog = $this->catalog();

        return $catalog->firstWhere('level_number', $progression->current_level)
            ?? $catalog->firstWhere('level_number', 1)
            ?? new LevelDefinition(['level_number' => 1, 'name' => 'المستوى الأول', 'xp_required_total' => 0]);
    }

    public function nextLevelFor(User $user, ?PlayerProgression $progression = null): ?LevelDefinition
    {
        $progression ??= $this->progressionFor($user);

        return $this->catalog()
            ->first(fn (LevelDefinition $l) => $l->is_active && $l->level_number > $progression->current_level);
    }

    public function progressPercentFor(User $user, ?PlayerProgression $progression = null): float
    {
        $progression ??= $this->progressionFor($user);
        $current = $this->currentLevelFor($user, $progression);
        $next = $this->nextLevelFor($user, $progression);

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

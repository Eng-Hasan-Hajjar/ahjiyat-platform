<?php

use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionPoolService;
use App\Services\LiveOps\RetentionAnalytics;
use App\Services\PuzzleAttemptService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E34 (LiveOps) - مساعدات مشتركة. SQLite افتراضيًا وتعمل كما هي على MySQL (phpunit.mysql.xml.dist). لا شبكة ولا حزم.
| كل تهيئة للمجموعات تمرّ بالخدمة الرسمية (MissionPoolService)؛ لا كتابة مباشرة للحالة إلا عند اختبار الحراسة نفسها.
| الكاشات المشتركة تُعطَّل افتراضيًا بالاختبارات (cache_seconds = 0) لحتمية النتائج؛ اختبارات الكاش تفعّلها صراحةً.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';
require_once __DIR__.'/../Notifications/NotificationTestHelpers.php';
require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e34_seed')) {
    function e34_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        config([
            'liveops.pools.cache_seconds' => 0,
            'liveops.calendar.cache_seconds' => 0,
            'liveops.analytics.cache_seconds' => 0,
            'liveops.notifications.daily_cap' => 1,
            'liveops.notifications.mission_expiring' => true,
            'liveops.notifications.event_starting_soon' => true,
            'liveops.notifications.weekly_missions_available' => false,
            'liveops.notifications.comeback_available' => false,
            'liveops.comeback.min_inactive_days' => 7,
            'liveops.limits.action_per_minute' => 1000, // اختبارات حدّ المعدّل تضبطه صراحةً
        ]);
        MissionAssignmentService::flushSnapshot();
        EventCalendarService::flush();
        RetentionAnalytics::flush();
    }

    function e34_admin(): User
    {
        $u = e16User();
        $u->assignRole('administrator');

        return $u;
    }

    function e34_moderator(): User
    {
        $u = e16User();
        $u->assignRole('moderator');

        return $u;
    }

    function e34_manager(): User
    {
        $u = e16User();
        $u->assignRole('content-manager');

        return $u;
    }

    /** لاعب موثَّق بعمر حساب محدد (افتراضيًا 20 يومًا، أي ليس "جديدًا" بقاعدة 14 يومًا). */
    function e34_user(array $attrs = [], int $daysOld = 20): User
    {
        $u = e16User($attrs);
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($daysOld)]);

        return $u->fresh();
    }

    /** مهمة يومية/أسبوعية (حلّ أحجيات) نشطة، عامة ما لم تُضَمّ لمجموعة. */
    function e34_quest(array $over = []): QuestDefinition
    {
        return QuestDefinition::factory()->create($over);
    }

    function e34_weekly(array $over = []): QuestDefinition
    {
        return QuestDefinition::factory()->weekly(3)->create($over);
    }

    /**
     * مجموعة بالخدمة الرسمية. $quests تُضمّ بالترتيب. $activate=false تتركها معطّلة.
     *
     * @param  list<QuestDefinition>  $quests
     */
    function e34_pool(array $quests = [], array $over = [], bool $activate = true, ?User $actor = null): MissionPool
    {
        $actor ??= e34_admin();
        $svc = app(MissionPoolService::class);
        $pool = $svc->create($actor, $over + [
            'name' => 'مجموعة '.fake()->unique()->numerify('####'), 'slug' => 'p'.fake()->unique()->numerify('######'),
            'period_type' => QuestDefinition::PERIOD_DAILY, 'selection_count' => 2,
        ]);

        foreach ($quests as $i => $q) {
            $svc->attach($actor, $pool, $q, $i);
        }

        if ($activate && $quests !== []) {
            $pool = $svc->activate($actor, $pool);
        }

        return $pool->fresh();
    }

    /** n مهام يومية جديدة (target 1). @return list<QuestDefinition> */
    function e34_quests(int $n, array $over = []): array
    {
        return array_map(fn () => e34_quest($over), range(1, $n));
    }

    /** حلّ أحجية رسمية صحيحة عبر المسار الرسمي الكامل (محاولة ⇒ تقدّم ⇒ مهام ⇒ سلسلة ⇒ معالم). */
    function e34_solve(User $user, ?PuzzleCategory $category = null): void
    {
        $puzzle = Puzzle::factory()->create(array_filter([
            'answer_raw' => 'صح', 'xp_reward' => 0, 'gem_reward' => 0, 'puzzle_category_id' => $category?->id,
        ], fn ($v) => $v !== null)); // الأصفار تُبقى (لا مكافأة افتراضية من المصنع)
        app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    }

    function e34_streak(User $user, int $current, int $longest, ?string $lastActive): PlayerStreak
    {
        return PlayerStreak::query()->updateOrCreate(['user_id' => $user->id], [
            'current_streak' => $current, 'longest_streak' => $longest, 'last_active_date' => $lastActive,
        ]);
    }

    /** المهام الظاهرة للمستخدم بمعرّفاتها (يومية ثم أسبوعية)، عبر مثيل خدمة جديد (لا ذاكرة مؤقتة قديمة). @return list<int> */
    function e34_visible_ids(User $user, string $type = QuestDefinition::PERIOD_DAILY): array
    {
        $quests = app(\App\Services\Engagement\QuestService::class);

        return $quests->visibleQuestsFor($user, $type)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    /** نفس السؤال عبر isVisibleTo (التكافؤ). */
    function e34_visible_via_isVisible(User $user, string $type = QuestDefinition::PERIOD_DAILY): array
    {
        $quests = app(\App\Services\Engagement\QuestService::class);

        return QuestDefinition::query()->where('period_type', $type)->orderBy('id')->get()
            ->filter(fn (QuestDefinition $q) => $quests->isVisibleTo($user, $q))
            ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    /** كود PHP بلا تعليقات (للتدقيق الساكن لا يخدعه نص التوثيق). */
    function e34_code_only(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /** عدد الاستعلامات المنفَّذة داخل $fn. */
    function e34_count_queries(\Closure $fn): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $fn();

        return $n;
    }
}

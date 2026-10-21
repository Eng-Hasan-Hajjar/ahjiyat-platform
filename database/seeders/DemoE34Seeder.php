<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\Engagement\QuestEvaluatorRegistry;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\MissionAssignmentService;
use App\Services\LiveOps\MissionPoolService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E34 - بيانات Demo لمركز المهام والتقويم والعودة.   php artisan db:seed --class=DemoE34Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق liveops.test).
 * - المجموعات والتفعيل عبر الخدمة الرسمية (MissionPoolService: صلاحية + تدقيق + حد معدّل)، لا كتابة مباشرة لحالتها.
 * - Idempotent: كل سجل بمفتاح/slug/بريد ثابت (updateOrCreate / فحص وجود) ⇒ إعادة التشغيل لا تكرّر شيئًا.
 * - تولّد: مهمة يومية عامة، مهمة أسبوعية عامة، مجموعة مهام يومية (4 مهام، تُسند مهمتان لكل لاعب)، حدثان (جارٍ + قادم)،
 *   لاعب بسلسلة 7 أيام فعّالة، ولاعب مؤهَّل لتجربة العودة (آخر لعب قبل 10 أيام). لا جواهر ولا مكافآت عملة.
 */
class DemoE34Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-liveops-admin@liveops.test';

    public const STREAK_EMAIL = 'demo-e34-streak@liveops.test';

    public const COMEBACK_EMAIL = 'demo-e34-comeback@liveops.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE34Seeder');
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(StreakMilestoneAchievementsSeeder::class);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير LiveOps التجريبي');
        $admin->syncRoles(['administrator']);

        $this->quest('demo_e34_daily_solve_2', 'حلّ أحجيتين اليوم', QuestDefinition::PERIOD_DAILY, 2, 5, 1);
        $this->quest('demo_e34_weekly_solve_8', 'ثماني أحجيات هذا الأسبوع', QuestDefinition::PERIOD_WEEKLY, 8, 20, 2);

        $pool = $this->pool($admin);

        $this->campaign('demo-e34-live', 'حملة تجريبية جارية', now()->subDays(2), now()->addDays(2));
        $this->campaign('demo-e34-upcoming', 'حملة تجريبية قادمة', now()->addDays(3), now()->addDays(10));
        EventCalendarService::flush();
        MissionAssignmentService::flushSnapshot();

        $streak = $this->user(self::STREAK_EMAIL, 'لاعب بسلسلة (تجريبي)');
        $this->streak($streak, 7, 7, now()->toDateString());

        $comeback = $this->user(self::COMEBACK_EMAIL, 'لاعب عائد (تجريبي)');
        $this->streak($comeback, 3, 5, now()->subDays(10)->toDateString());

        $this->command?->info('DemoE34: مهام='.QuestDefinition::query()->where('internal_key', 'like', 'demo_e34_%')->count()
            .' · مجموعة='.$pool->slug.' ('.($pool->fresh()->is_active ? 'فعّالة' : 'معطّلة').')');
        $this->command?->info('حسابات: '.self::ADMIN_EMAIL.' ، '.self::STREAK_EMAIL.' ، '.self::COMEBACK_EMAIL.' / '.self::PASSWORD);
    }

    protected function quest(string $key, string $name, string $period, int $target, int $xp, int $sort): QuestDefinition
    {
        $existing = QuestDefinition::query()->where('internal_key', $key)->first();

        if ($existing !== null) {
            return $existing; // لا نمسّ مهمة قد صارت مستعملة (الحارس يمنع تغيير حقولها الحساسة)
        }

        return QuestDefinition::query()->create([
            'internal_key' => $key, 'name' => $name, 'description' => 'مهمة تجريبية.', 'period_type' => $period,
            'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED, 'target_value' => $target, 'xp_reward' => $xp,
            'is_active' => true, 'sort_order' => $sort,
        ]);
    }

    protected function pool(User $admin): MissionPool
    {
        $service = app(MissionPoolService::class);
        $pool = MissionPool::query()->where('slug', 'demo-e34-daily-pool')->first()
            ?? $service->create($admin, [
                'name' => 'مجموعة المهام اليومية التجريبية', 'slug' => 'demo-e34-daily-pool', 'description' => 'تجريبية',
                'period_type' => QuestDefinition::PERIOD_DAILY, 'selection_count' => 2, 'priority' => 100, 'eligibility_rule' => 'all', 'personalized' => true,
            ]);

        $members = DB::table('mission_pool_quests')->where('mission_pool_id', $pool->id)->pluck('quest_definition_id')->all();

        foreach ([['a', 1, 3], ['b', 2, 5], ['c', 3, 8], ['d', 1, 4]] as $i => [$suffix, $target, $xp]) {
            $quest = $this->quest("demo_e34_pool_{$suffix}", "مهمة المجموعة {$suffix}", QuestDefinition::PERIOD_DAILY, $target, $xp, 10 + $i);

            if (! in_array($quest->id, $members, true)) {
                $service->attach($admin, $pool, $quest, $i);
            }
        }

        if (! $pool->is_active) {
            $pool = $service->activate($admin, $pool);
        }

        return $pool;
    }

    protected function campaign(string $slug, string $title, $starts, $ends): void
    {
        if (Campaign::query()->where('slug', $slug)->exists()) {
            return;
        }

        Campaign::query()->create(['slug' => $slug, 'title' => $title, 'description' => 'حملة تجريبية لعرض التقويم.', 'is_active' => true, 'starts_at' => $starts, 'ends_at' => $ends]);
    }

    protected function streak(User $user, int $current, int $longest, string $lastActive): void
    {
        PlayerStreak::query()->updateOrCreate(['user_id' => $user->id], [
            'current_streak' => $current, 'longest_streak' => $longest, 'last_active_date' => $lastActive, 'last_qualified_at' => $lastActive.' 12:00:00',
        ]);
    }

    protected function user(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }
}

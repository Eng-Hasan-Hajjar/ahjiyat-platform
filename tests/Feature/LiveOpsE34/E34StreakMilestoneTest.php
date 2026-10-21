<?php

use App\Models\Achievement;
use App\Models\CurrencyTransaction;
use App\Models\GemTransaction;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Models\XpTransaction;
use App\Services\LiveOps\StreakMilestoneService;
use App\Services\Progression\Achievements\StreakLongestDaysEvaluator;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Database\Seeders\StreakMilestoneAchievementsSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function e34_unlocked(User $u, string $key): bool
{
    $a = Achievement::query()->where('internal_key', $key)->first();

    return $a !== null && UserAchievementProgress::query()->where('user_id', $u->id)->where('achievement_id', $a->id)->whereNotNull('unlocked_at')->exists();
}

test('Z4-01: thresholds come from config, are sorted, unique and positive only', function () {
    config(['liveops.streak_milestones' => [30, 3, 3, 7, 0, -4]]);

    expect(app(StreakMilestoneService::class)->thresholds())->toBe([3, 7, 30]);
});

test('Z4-02: the seeder creates one reward-free achievement per milestone and is idempotent', function () {
    $thresholds = app(StreakMilestoneService::class)->thresholds();

    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $this->seed(StreakMilestoneAchievementsSeeder::class);

    $rows = Achievement::query()->where('condition_type', AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)->orderBy('target_value')->get();

    expect($rows->pluck('target_value')->map(fn ($v) => (int) $v)->all())->toBe($thresholds)
        ->and($rows->pluck('xp_reward')->unique()->all())->toBe([0])
        ->and($rows->pluck('internal_key')->all())->toBe(array_map(fn ($d) => "streak_{$d}_days", $thresholds));
});

test('Z4-03: reaching a milestone through real official solves unlocks it exactly once and never again on later solves', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 2, 2, now()->subDay()->toDateString());

    e34_solve($u); // اليوم الثالث ⇒ 3 أيام
    expect(e34_unlocked($u, 'streak_3_days'))->toBeTrue()->and(e34_unlocked($u, 'streak_7_days'))->toBeFalse();

    $first = UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->value('unlocked_at');
    e34_solve($u);
    e34_solve($u);

    expect(UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->count())->toBe(1)
        ->and(UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->value('unlocked_at')->equalTo($first))->toBeTrue();
});

test('Z4-04: missing days is never punished - the milestone, longest streak and wallets stay untouched while the display shows the effective streak', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 2, 2, now()->subDay()->toDateString());
    e34_solve($u);
    $xp = XpTransaction::query()->count();
    $gems = GemTransaction::query()->count() + CurrencyTransaction::query()->count();

    Carbon::setTestNow(now()->addDays(9)); // غياب 9 أيام
    $status = app(StreakMilestoneService::class)->statusFor($u);

    expect($status['current'])->toBe(0)->and($status['longest'])->toBe(3)->and($status['reached'])->toBe([3])
        ->and(e34_unlocked($u, 'streak_3_days'))->toBeTrue()
        ->and(PlayerStreak::query()->where('user_id', $u->id)->value('longest_streak'))->toBe(3)
        ->and(XpTransaction::query()->count())->toBe($xp)
        ->and(GemTransaction::query()->count() + CurrencyTransaction::query()->count())->toBe($gems);
    expect(XpTransaction::query()->where('amount', '<', 0)->count())->toBe(0);
});

test('Z4-05: status reports reached milestones and the next milestone not yet earned, with the days still needed on the current streak', function () {
    $u = e34_user();
    e34_streak($u, 5, 8, now()->subDay()->toDateString());

    $s = app(StreakMilestoneService::class)->statusFor($u);
    expect($s)->toMatchArray(['current' => 5, 'longest' => 8, 'reached' => [3, 7], 'next' => 14, 'days_to_next' => 9]);

    $new = e34_user();
    expect(app(StreakMilestoneService::class)->statusFor($new))->toMatchArray(['current' => 0, 'longest' => 0, 'reached' => [], 'next' => 3, 'days_to_next' => 3]);

    $top = e34_user();
    e34_streak($top, 120, 120, now()->toDateString());
    expect(app(StreakMilestoneService::class)->statusFor($top))->toMatchArray(['reached' => [3, 7, 14, 30, 60, 100], 'next' => null, 'days_to_next' => null]);
});

test('Z4-06: the effective streak is the stored value only while the last active day is today or yesterday', function () {
    $mk = fn (int $current, ?string $last) => new PlayerStreak(['current_streak' => $current, 'longest_streak' => 9, 'last_active_date' => $last]);

    expect(StreakMilestoneService::effectiveCurrent($mk(4, now()->toDateString())))->toBe(4)
        ->and(StreakMilestoneService::effectiveCurrent($mk(4, now()->subDay()->toDateString())))->toBe(4)
        ->and(StreakMilestoneService::effectiveCurrent($mk(4, now()->subDays(2)->toDateString())))->toBe(0)
        ->and(StreakMilestoneService::effectiveCurrent($mk(4, null)))->toBe(4)
        ->and(StreakMilestoneService::effectiveCurrent($mk(0, now()->toDateString())))->toBe(0)
        ->and(StreakMilestoneService::effectiveCurrent(null))->toBe(0);
});

test('Z4-07: unlocking a milestone grants no gems, currency or XP (recognition only; any reward is an explicit admin choice on the achievement)', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 6, 6, now()->subDay()->toDateString());
    $currency = GemTransaction::query()->count() + CurrencyTransaction::query()->count();

    e34_solve($u); // 7 أيام ⇒ يفتح 3 و7

    expect(e34_unlocked($u, 'streak_3_days'))->toBeTrue()->and(e34_unlocked($u, 'streak_7_days'))->toBeTrue()
        ->and(XpTransaction::query()->where('type', XpTransaction::TYPE_ACHIEVEMENT_REWARD)->count())->toBe(0)
        ->and(GemTransaction::query()->count() + CurrencyTransaction::query()->count())->toBe($currency);
});

test('Z4-08: the hook is idempotent and does no achievement work for players below the first milestone', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $svc = app(StreakMilestoneService::class);

    $low = e34_user();
    e34_streak($low, 1, 2, now()->toDateString());
    expect(e34_count_queries(fn () => $svc->onStreakUpdated($low)))->toBe(1); // قراءة longest فقط

    $u = e34_user();
    e34_streak($u, 3, 3, now()->toDateString());
    $svc->onStreakUpdated($u);
    $svc->onStreakUpdated($u);
    $svc->onStreakUpdated($u);

    expect(UserAchievementProgress::query()->where('user_id', $u->id)->whereNotNull('unlocked_at')->count())->toBe(1);
});

test('Z4-09: the seeder never rewrites a milestone that is already in use', function () {
    $this->seed(StreakMilestoneAchievementsSeeder::class);
    $u = e34_user();
    e34_streak($u, 3, 3, now()->toDateString());
    app(StreakMilestoneService::class)->onStreakUpdated($u);

    $a = Achievement::query()->where('internal_key', 'streak_3_days')->first();
    $a->forceFill(['name' => 'اسم مخصّص'])->save();

    $this->seed(StreakMilestoneAchievementsSeeder::class);

    expect(Achievement::query()->where('internal_key', 'streak_3_days')->value('name'))->toBe('اسم مخصّص')
        ->and(Achievement::query()->where('condition_type', AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)->count())->toBe(6);
});

test('Z4-10: the evaluator reads the longest streak only (current streak and logins are irrelevant) and is registered with its event group', function () {
    $u = e34_user();
    e34_streak($u, 1, 12, now()->subDays(30)->toDateString());
    $a = new Achievement(['condition_type' => AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS, 'target_value' => 7]);

    $ev = new StreakLongestDaysEvaluator;
    expect($ev->supports($a))->toBeTrue()->and($ev->currentValue($u, $a))->toBe(12)
        ->and($ev->currentValue(e34_user(), $a))->toBe(0)
        ->and(AchievementEvaluatorRegistry::ALL_TYPES)->toContain(AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS)
        ->and(AchievementEvaluatorRegistry::EVENT_GROUPS)->toHaveKey('streak_updated');
});

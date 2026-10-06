<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Models\CompetitiveRewardGrant;
use App\Models\CompetitiveRewardRule;
use App\Models\CurrencyTransaction;
use App\Models\StoreItem;
use App\Services\Competitive\Rewards\CompetitiveRewardRuleException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

test('48/49/50: a player can neither choose a reward nor send a rank, an amount or XP - no route accepts them and submit ignores the fields', function () {
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 10]);
    $user = e16User();
    e17Forward(2 * 3600_000);
    e17Events()->register($user, $event);
    e17Events()->start($user, $event);
    e17Forward(30_000);

    $this->actingAs($user)->post(route('competitions.submit', $event), [
        'answer' => 'خطأ', 'reward' => 1, 'reward_id' => 1, 'rank' => 1, 'final_rank' => 1, 'amount' => 999999, 'xp' => 99999, 'currency_amount' => 999999, 'winner' => $user->id, 'grant' => 1,
    ]);

    expect(e18Pending($user, $currency))->toBe(0)->and(e18Xp($user))->toBe(0)->and(CompetitiveRewardGrant::count())->toBe(0);

    // لا مسار ويب (لاعب) يحمل كلمة جائزة/منح/مطالبة.
    $names = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => ! str_starts_with((string) $r->getName(), 'filament.') && ! str_starts_with($r->uri(), 'admin') && ! str_starts_with($r->uri(), 'livewire'));
    foreach ($names as $route) {
        expect(strtolower($route->uri().'|'.$route->getName()))->not->toMatch('/(reward|grant|claim|payout|prize)/');
    }
    foreach (['post', 'put', 'patch', 'delete'] as $verb) {
        $this->actingAs($user)->{$verb}("/competitions/{$event->slug}/reward", ['amount' => 1000])->assertStatus(404);
    }
});

test('51: private grant data is never reachable by another user - no route by grant id, nothing in other profiles or pages', function () {
    $event = e18Event();
    $currency = e18Currency(['name' => 'عملة سرية']);
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 777]);
    [$winner, $snoop] = [e16User(), e16User()];
    e18Run($event, [[$winner, E17_ANSWER, 10_000]]);
    $grant = e18Grant($event, $winner);

    foreach (["/competitions/{$event->slug}/grants/{$grant->id}", "/players/{$winner->public_id}/rewards", "/rewards/{$grant->id}", "/competitions/rewards/{$grant->id}"] as $url) {
        $this->actingAs($snoop)->get($url)->assertNotFound();
    }

    // صفحات اللاعب والقاعة لا تكشف ما مُنح لشخص بعينه (قواعد الحدث نفسها علنية بصفحة الحدث بالتصميم).
    foreach ([route('players.competitive', $winner), route('players.show', $winner), route('competitions.hall-of-fame')] as $url) {
        $html = $this->actingAs($snoop)->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('777 عملة سرية')->and($html)->not->toMatch('/grant[_-]?id|competitive-reward:/i');
    }
    // الحدث نفسه يعرض قاعدة الجائزة (علنية)، لكن "جائزتك" لا تظهر إلا لصاحبها.
    $this->actingAs($snoop)->get(route('competitions.show', $event))->assertDontSee('جائزتك');
    $this->actingAs($winner)->get(route('competitions.show', $event))->assertSee('جائزتك');
});

test('53: GET pages never change economy or grant state', function () {
    $event = e18Event();
    e18Rule($event, ['reward_type' => 'xp', 'amount' => 25]);
    $user = e16User();
    e18Run($event, [[$user, E17_ANSWER, 10_000]]);
    $before = [CompetitiveRewardGrant::count(), e17Snapshot(), DB::table('notifications')->count()];

    foreach ([route('competitions.index'), route('competitions.show', $event), route('competitions.hall-of-fame'), route('players.competitive', $user), route('players.show', $user)] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
        $this->get($url)->assertOk();
    }

    expect([CompetitiveRewardGrant::count(), e17Snapshot(), DB::table('notifications')->count()])->toBe($before);
});

test('54: there is no pay-to-win - owning currency, store items and entitlements changes neither eligibility nor the amount', function () {
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'xp', 'amount' => 100]);
    [$rich, $poor] = [e16User(), e16User()];
    $currency = e18Currency();
    app(\App\Services\Economy\CurrencyWalletService::class)->creditAvailable($rich, $currency, 50_000, 'test_funding');
    foreach (StoreItem::factory()->count(3)->create() as $item) {
        app(\App\Services\Store\InventoryService::class)->grant($rich, $item, 5, 'test');
    }
    app(\App\Services\Store\EntitlementService::class)->grant($rich, StoreItem::factory()->entitlement('vip.rich')->create());

    e18Run($event, [[$poor, E17_ANSWER, 10_000], [$rich, E17_ANSWER, 10_500]]);

    expect(e18Xp($poor))->toBe(100)->and(e18Xp($rich))->toBe(100)->and(e18Grant($event, $rich)->amount)->toBe(e18Grant($event, $poor)->amount)
        ->and(e18Grant($event, $poor)->final_rank)->toBe(1)->and(e18Grant($event, $rich)->final_rank)->toBe(2); // الترتيب بالأداء لا بالملكية
});

test('54b/55/56/A7: no multiplier, cash, crypto, gift card or wager reward exists - the supported types are exactly currency, xp and store item', function () {
    $event = e18Event();

    foreach (['cash', 'crypto', 'gift_card', 'bet', 'jackpot', 'multiplier'] as $type) {
        expect(fn () => e18Rule($event, ['reward_type' => $type, 'amount' => 10]))->toThrow(CompetitiveRewardRuleException::class, 'غير مدعوم');
    }
    $consts = collect((new ReflectionClass(CompetitiveRewardRule::class))->getConstants())->filter(fn ($v, $k) => str_starts_with($k, 'TYPE_'))->values()->all();
    expect($consts)->toBe(['currency', 'xp', 'store_item']);

    // قاعدة بيانات: لا حقل نقد/رهان/مضاعف بالقواعد ولا بالمنح.
    foreach (['competitive_reward_rules', 'competitive_reward_grants'] as $table) {
        foreach (\Illuminate\Support\Facades\Schema::getColumnListing($table) as $column) {
            expect($column)->not->toMatch('/(cash|payout|crypto|stake|bet|wager|multiplier|boost|bonus_pct|gift)/i');
        }
    }
});

test('57: friend challenges never feed rewards, grants or competitive achievements', function () {
    (new \Database\Seeders\CompetitiveAchievementsSeeder)->run();
    [$a, $b] = e17Friends();
    $before = e17Snapshot();

    foreach (range(1, 2) as $round) {
        $challenge = e17Challenge($a, $b);
        e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
        e17PlayChallenge($b, $challenge, E17_ANSWER, 40_000);
    }

    expect(CompetitiveRewardGrant::count())->toBe(0)->and(e17Snapshot())->toBe($before)->and(CurrencyTransaction::where('reason', 'competitive_event_reward')->count())->toBe(0)
        ->and(\App\Models\UserAchievementProgress::whereNotNull('unlocked_at')->count())->toBe(0);
});

test('58: a granted history survives catalog changes - deactivated items keep their snapshot and referenced catalog rows cannot be deleted', function () {
    $event = e18Event();
    $item = StoreItem::factory()->cosmeticAvatar()->create(['name' => 'شارة البطل']);
    e18Rule($event, ['reward_type' => 'store_item', 'store_item_id' => $item->id, 'amount' => 1]);
    $user = e16User();
    e18Run($event, [[$user, E17_ANSWER, 10_000]]);

    $item->update(['is_active' => false, 'name' => 'اسم جديد']);
    $grant = e18Grant($event, $user);

    expect($grant->status)->toBe('granted')->and($grant->reward_label)->toBe('شارة البطل')                    // لقطة الوصف ثابتة
        ->and(fn () => $item->delete())->toThrow(\Exception::class)                                            // حارس E10 أو RESTRICT بالقاعدة: رفض في الحالتين
        ->and(StoreItem::whereKey($item->id)->exists())->toBeTrue()->and(e18Qty($user, $item))->toBe(1)->and(e18Grant($event, $user)->final_rank)->toBe(1);
});

test('static economy audit: no direct balance mutation anywhere in the E18 reward code - economy goes only through the official services', function () {
    $files = array_merge(
        glob(app_path('Services/Competitive/Rewards/*.php')), [app_path('Services/Competitive/CompetitiveStatsService.php'), app_path('Services/Analytics/CompetitiveAnalyticsService.php')],
        glob(app_path('Models/CompetitiveReward*.php')), [app_path('Jobs/DistributeCompetitiveRewardsChunk.php'), app_path('Jobs/EvaluateCompetitiveAchievementsChunk.php'),
            app_path('Listeners/QueueCompetitiveRewardDistribution.php'), app_path('Listeners/SendCompetitiveRewardGrantedNotification.php'), app_path('Listeners/QueueCompetitiveAchievementEvaluation.php')],
        [app_path('Http/Controllers/PlayerCompetitiveController.php'), app_path('Http/Controllers/CompetitionHallOfFameController.php')],
        glob(resource_path('views/players/competitive.blade.php')), glob(resource_path('views/competitions/hall-of-fame.blade.php')),
    );
    expect(count($files))->toBeGreaterThan(14);
    $forbidden = ['wallet->increment', '->increment(', '->decrement(', 'pending_balance', 'available_balance', 'lifetime_earned', 'total_xp', 'xp +=', 'currency +=', 'balance +=', 'balance -=', 'DB::table(\'wallets\')', 'DB::table(\'player_progressions\')', 'DB::table(\'currency_transactions\')', 'DB::table(\'xp_transactions\')'];

    foreach ($files as $file) {
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'], '', file_get_contents($file));

        foreach ($forbidden as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not contain {$needle}");
        }
    }

    // وقناة الاقتصاد الوحيدة بالتوزيع هي الخدمات الأربع المعروفة.
    $service = file_get_contents(app_path('Services/Competitive/Rewards/CompetitiveRewardDistributionService.php'));
    foreach (['creditPending(', 'grantXp(', 'inventory->grant(', 'entitlements->grant('] as $call) {
        expect($service)->toContain($call);
    }
});

test('static client-trust audit: no E18 controller or view reads rank, reward, amount, winner or score from the request - rewards are server-driven', function () {
    foreach ([app_path('Http/Controllers/PlayerCompetitiveController.php'), app_path('Http/Controllers/CompetitionHallOfFameController.php')] as $file) {
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents($file));
        expect(preg_match('/\$request->(input|get|post|query|only|all|integer)\(\s*[\'"](rank|reward|reward_id|amount|xp|winner|score|final_rank|grant)[\'"]/', $code))->toBe(0, basename($file));
    }
    foreach (glob(resource_path('views/{players/competitive,competitions/hall-of-fame}.blade.php'), GLOB_BRACE) as $view) {
        expect(file_get_contents($view))->not->toMatch('/<form\b[^>]*method=["\']?post/i')->and(file_get_contents($view))->not->toContain('name="reward');
    }
});

test('static hygiene and privacy audit of the E18 code: no debug leftovers, no raw HTML, no private fields in the player-facing views', function () {
    $files = array_merge(
        glob(app_path('Services/Competitive/Rewards/*.php')), glob(app_path('Models/CompetitiveReward*.php')),
        [app_path('Services/Competitive/CompetitiveStatsService.php'), app_path('Jobs/DistributeCompetitiveRewardsChunk.php'), app_path('Jobs/EvaluateCompetitiveAchievementsChunk.php'),
            app_path('Http/Controllers/PlayerCompetitiveController.php'), app_path('Http/Controllers/CompetitionHallOfFameController.php'),
            resource_path('views/players/competitive.blade.php'), resource_path('views/competitions/hall-of-fame.blade.php')],
    );

    foreach ($files as $file) {
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'], '', file_get_contents($file));

        foreach (['/\bdd\(/', '/\bdump\(/', '/\bray\(/', '/\bvar_dump\(/', '/TODO/', '/FIXME/', '/console\.log/', '/\{!!/'] as $bad) {
            expect(preg_match($bad, $code))->toBe(0, basename($file)." must not match {$bad}");
        }
        if (str_contains($file, 'views')) {
            foreach (['email', 'phone', 'wallet', 'password', 'is_frozen', 'idempotency', 'failure_reason', 'competitive_reward_grant'] as $private) {
                expect(stripos($code, $private))->toBeFalse(basename($file)." view must not touch {$private}");
            }
        }
    }
});

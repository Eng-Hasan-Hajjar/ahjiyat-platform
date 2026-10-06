<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Models\Achievement;
use App\Models\CompetitiveEvent;
use App\Models\User;
use App\Models\UserAchievementProgress;
use App\Services\Competitive\CompetitiveStatsService;
use Carbon\Carbon;
use Database\Seeders\CompetitiveAchievementsSeeder;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e18Stats(): CompetitiveStatsService
{
    return app(CompetitiveStatsService::class);
}

function e18Unlocked(User $user, string $key): bool
{
    $a = Achievement::where('internal_key', $key)->first();

    return $a !== null && UserAchievementProgress::where('user_id', $user->id)->where('achievement_id', $a->id)->whereNotNull('unlocked_at')->exists();
}

test('26/27/28: wins, top-3 finishes and best rank are derived from finalized correct results - and live, cancelled or wrong results never count', function () {
    $user = e16User();
    e18Finalized($user, 1);
    e18Finalized($user, 3);
    e18Finalized($user, 4);
    e18Finalized($user, 1, correct: false);                               // نتيجة خاطئة: لا فوز
    $live = e17Event();                                                    // جارٍ، لم يُعتمد: لا يُحتسب
    e17Events()->register($user, $live);
    $cancelled = e18Finalized($user, 1);
    $cancelled->forceFill(['status' => 'cancelled'])->save();

    $s = e18Stats()->eventStats($user);

    expect($s['events_won'])->toBe(1)->and($s['top3'])->toBe(2)->and($s['best_rank'])->toBe(1)->and($s['events_valid_finalized'])->toBe(3)
        ->and(e18Stats()->eventStats(e16User()))->toMatchArray(['events_won' => 0, 'top3' => 0, 'best_rank' => null, 'events_participated' => 0]);

    $other = e16User();
    e18Finalized($other, 4);
    expect(e18Stats()->eventStats($other)['best_rank'])->toBe(4)->and(e18Stats()->eventStats($other)['events_won'])->toBe(0);
});

test('29: friend challenge wins, losses and draws are derived from completed challenges only', function () {
    [$me, $w, $l, $d] = [e16User(), e16User(), e16User(), e16User()];
    foreach ([$w, $l, $d] as $f) {
        e16Befriend($me, $f);
    }
    $win = e17Challenge($me, $w);
    e17PlayChallenge($me, $win, E17_ANSWER, 10_000);
    e17PlayChallenge($w, $win, E17_ANSWER, 40_000);
    $loss = e17Challenge($me, $l);
    e17PlayChallenge($me, $loss, E17_ANSWER, 40_000);
    e17PlayChallenge($l, $loss, E17_ANSWER, 10_000);
    $draw = e17Challenge($me, $d);
    e17Challenges()->start($me, $draw);
    e17Challenges()->start($d, $draw);
    e17Forward(20_000);
    e17Challenges()->submit($me, $draw->refresh(), ['answer' => E17_ANSWER]);
    e17Challenges()->submit($d, $draw->refresh(), ['answer' => E17_ANSWER]);
    e17Challenge($me, e16User(), null, false); // معلّق: لا يُحتسب (الصديق الجديد بلا صداقة => يرفض)
})->throws(\App\Services\Competitive\CompetitiveException::class);

test('29b: the challenge stats are exactly 1 win, 1 loss and 1 draw (pending challenges never count)', function () {
    [$me, $w, $l, $d, $p] = [e16User(), e16User(), e16User(), e16User(), e16User()];
    foreach ([$w, $l, $d, $p] as $f) {
        e16Befriend($me, $f);
    }
    $win = e17Challenge($me, $w);
    e17PlayChallenge($me, $win, E17_ANSWER, 10_000);
    e17PlayChallenge($w, $win, E17_ANSWER, 40_000);
    $loss = e17Challenge($me, $l);
    e17PlayChallenge($me, $loss, E17_ANSWER, 40_000);
    e17PlayChallenge($l, $loss, E17_ANSWER, 10_000);
    $draw = e17Challenge($me, $d);
    e17Challenges()->start($me, $draw);
    e17Challenges()->start($d, $draw);
    e17Forward(20_000);
    e17Challenges()->submit($me, $draw->refresh(), ['answer' => E17_ANSWER]);
    e17Challenges()->submit($d, $draw->refresh(), ['answer' => E17_ANSWER]);
    e17Challenge($me, $p, null, false);

    expect(e18Stats()->challengeStats($me))->toBe(['wins' => 1, 'losses' => 1, 'draws' => 1])->and(e18Stats()->challengeStats($w))->toBe(['wins' => 0, 'losses' => 1, 'draws' => 0])
        ->and(e18Stats()->challengeStats($l))->toBe(['wins' => 1, 'losses' => 0, 'draws' => 0]);
});

test('30: the statistics never depend on the client - query strings and form fields cannot change them, and the service has no mutator', function () {
    $user = e16User(['name' => 'Stats Player']);
    e18Finalized($user, 2);

    $page = $this->get(route('players.competitive', [$user, 'won' => 99, 'top3' => 99, 'best_rank' => 1, 'events_won' => 50]))->assertOk();

    expect($page->viewData('stats')['events_won'])->toBe(0)->and($page->viewData('stats')['top3'])->toBe(1)->and($page->viewData('stats')['best_rank'])->toBe(2);
    $mutators = collect((new ReflectionClass(CompetitiveStatsService::class))->getMethods(ReflectionMethod::IS_PUBLIC))->pluck('name')->reject(fn ($n) => str_starts_with($n, '__'))->all();
    expect($mutators)->toBe(['eventStats', 'challengeStats', 'trophies', 'history']); // قراءة فقط
});

test('31: the trophy cabinet shows only finalized, correct, top-3 results - never live, cancelled, wrong or lower ranks', function () {
    $user = e16User();
    $gold = e18Finalized($user, 1, eventAttrs: ['title' => 'منافسة ذهبية']);
    e18Finalized($user, 3, eventAttrs: ['title' => 'منافسة برونزية']);
    e18Finalized($user, 4, eventAttrs: ['title' => 'منافسة رابعة']);
    e18Finalized($user, 2, correct: false, eventAttrs: ['title' => 'منافسة خاطئة']);
    $live = e17Event(['title' => 'منافسة جارية']);
    e17Events()->register($user, $live);
    $cancelled = e18Finalized($user, 1, eventAttrs: ['title' => 'منافسة ملغاة']);
    $cancelled->forceFill(['status' => 'cancelled'])->save();

    $page = $this->get(route('players.competitive', $user))->assertOk();

    $titles = $page->viewData('trophies')->getCollection()->map(fn ($t) => $t->event->title)->sort()->values()->all();

    $page->assertSee('🥇')->assertSee('🥉');
    expect($titles)->toBe(['منافسة برونزية', 'منافسة ذهبية'])->and($page->viewData('trophies')->total())->toBe(2); // الخزانة: الأوائل الثلاثة الصحيحة المعتمَدة فقط

    // التاريخ (قسم مستقل) يعرض كل ما اعتُمد: الرابع والخاطئ، لكن لا الجاري ولا الملغى.
    $history = $page->viewData('history')->getCollection()->map(fn ($r) => $r->event->title)->all();
    expect($history)->toContain('منافسة رابعة', 'منافسة خاطئة')->and($history)->not->toContain('منافسة جارية')->and($history)->not->toContain('منافسة ملغاة');
});

test('32: the public profile and the competitive page expose only aggregate recognition - rewards, challenge records and ledger data stay private to the owner', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $event = e18Event();
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => ($currency = e18Currency(['name' => 'جواهر المنافسة']))->id, 'amount' => 321]);
    $winner = e16User(['name' => 'Champion', 'email' => 'champion@secret.test']);
    e18Run($event, [[$winner, E17_ANSWER, 10_000]]);
    $viewer = e16User();

    $public = $this->actingAs($viewer)->get(route('players.show', $winner))->assertOk()->assertSee('المنافسات')->assertSee('فوز');
    $comp = $this->actingAs($viewer)->get(route('players.competitive', $winner))->assertOk();
    $own = $this->actingAs($winner)->get(route('players.competitive', $winner))->assertOk();

    foreach ([$public->getContent(), $comp->getContent()] as $html) {
        expect($html)->not->toContain('جواهر المنافسة')->and($html)->not->toContain('321')->and($html)->not->toContain('champion@secret.test')
            ->and($html)->not->toContain('competitive-reward')->and($html)->not->toContain('تحدّيات الأصدقاء (لك وحدك)');
    }
    $own->assertSee('321 جواهر المنافسة')->assertSee('تحدّيات الأصدقاء (لك وحدك)'); // المالك وحده
    // والأهم: الخاص لا يصل إلى طبقة العرض أصلًا لغير المالك (لا يكفي أن يحجبه القالب).
    expect($comp->viewData('trophies')->getCollection()->pluck('reward_label')->filter()->all())->toBe([])->and($comp->viewData('challenges'))->toBeNull()
        ->and($own->viewData('trophies')->getCollection()->pluck('reward_label')->filter()->all())->toBe(['321 جواهر المنافسة'])->and($own->viewData('challenges'))->not->toBeNull();
    expect($own->getContent())->not->toContain('champion@secret.test')->and($own->getContent())->not->toMatch('/grant[_-]?id/i');
});

test('privacy follows the existing profile visibility - a private profile has no competitive page for others', function () {
    $user = e16User(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    e18Finalized($user, 1);

    $this->get(route('players.competitive', $user))->assertNotFound();
    $this->actingAs(e16User())->get(route('players.competitive', $user))->assertNotFound();
    $this->actingAs($user)->get(route('players.competitive', $user))->assertOk();
});

test('33: the competition history is paginated (10 per page) and the cabinet is paginated (6 per page)', function () {
    $user = e16User();
    foreach (range(1, 12) as $i) {
        e18Finalized($user, $i <= 8 ? 1 : 5, eventAttrs: ['title' => sprintf('Event %02d', $i)]);
    }

    $p1 = $this->get(route('players.competitive', $user))->assertOk();
    $p2 = $this->get(route('players.competitive', [$user, 'history_page' => 2, 'trophies_page' => 2]))->assertOk();

    expect($p1->viewData('history')->count())->toBe(10)->and($p1->viewData('history')->total())->toBe(12)->and($p1->viewData('trophies')->count())->toBe(6)->and($p1->viewData('trophies')->total())->toBe(8)
        ->and($p2->viewData('history')->count())->toBe(2)->and($p2->viewData('trophies')->count())->toBe(2);
});

test('C8/C9: competitive achievements are configurable condition types - the finalized win unlocks them, a loser or a friend-challenge winner does not', function () {
    (new CompetitiveAchievementsSeeder)->run();
    (new CompetitiveAchievementsSeeder)->run(); // آمن لإعادة التشغيل
    expect(Achievement::where('category', 'competitive')->count())->toBe(5)->and(Achievement::where('category', 'competitive')->sum('xp_reward'))->toBe(0);

    $event = e18Event();
    [$winner, $second, $loser] = [e16User(), e16User(), e16User()];
    e18Run($event, [[$winner, E17_ANSWER, 10_000], [$second, E17_ANSWER, 20_000], [$loser, 'خطأ', 5_000]]);

    expect(e18Unlocked($winner, 'competitive_first_win'))->toBeTrue()->and(e18Unlocked($winner, 'competitive_first_top3'))->toBeTrue()
        ->and(e18Unlocked($winner, 'competitive_three_wins'))->toBeFalse()
        ->and(e18Unlocked($second, 'competitive_first_win'))->toBeFalse()->and(e18Unlocked($second, 'competitive_first_top3'))->toBeTrue()
        ->and(e18Unlocked($loser, 'competitive_first_top3'))->toBeFalse();

    // تحدٍّ بين صديقين لا يفتح إنجازات المنافسة (منع الحصاد).
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 40_000);
    expect(e18Unlocked($a, 'competitive_first_win'))->toBeFalse()->and(e18Stats()->eventStats($a)['events_won'])->toBe(0);
});

test('the achievement registry knows the three competitive condition types and the event group that evaluates them', function () {
    $registry = app(\App\Services\Progression\AchievementEvaluatorRegistry::class);

    foreach (['competitive_events_won', 'competitive_top3_finishes', 'competitive_events_completed'] as $type) {
        expect($registry->isValidConditionType($type))->toBeTrue()->and($registry->evaluatorFor($type))->not->toBeNull()->and($registry->options())->toHaveKey($type);
    }
    expect($registry->conditionTypesForEvent('competitive_event_finalized'))->toHaveCount(3);
});

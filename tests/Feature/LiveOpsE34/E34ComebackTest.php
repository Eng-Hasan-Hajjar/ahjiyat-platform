<?php

use App\Models\GemTransaction;
use App\Models\CurrencyTransaction;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\XpTransaction;
use App\Services\LiveOps\ComebackService;
use App\Services\LiveOps\MissionCenterService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

test('Z5-01: eligibility starts exactly at the configured number of inactive official-play days and follows the config', function () {
    $svc = app(ComebackService::class);
    $mk = function (int $daysAgo) {
        $u = e34_user();
        e34_streak($u, 2, 4, now()->subDays($daysAgo)->toDateString());

        return $u;
    };

    expect($svc->isEligible($mk(0)))->toBeFalse()
        ->and($svc->isEligible($mk(1)))->toBeFalse()
        ->and($svc->isEligible($mk(6)))->toBeFalse()
        ->and($svc->isEligible($mk(7)))->toBeTrue()
        ->and($svc->isEligible($mk(30)))->toBeTrue();

    config(['liveops.comeback.min_inactive_days' => 3]);
    expect($svc->isEligible($mk(3)))->toBeTrue()->and($svc->isEligible($mk(2)))->toBeFalse();
});

test('Z5-02: a player with no official play history is a new player, not a returner; old accounts without a streak row fall back to their last correct attempt', function () {
    $svc = app(ComebackService::class);

    expect($svc->isEligible(e34_user([], 90)))->toBeFalse()->and($svc->stateFor(e34_user()))->toBeNull();

    $legacy = e34_user([], 90);
    $puzzle = Puzzle::factory()->create();
    PuzzleAttempt::query()->forceCreate(['user_id' => $legacy->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12)]);

    expect($svc->isEligible($legacy))->toBeTrue()->and($svc->inactiveDays($legacy))->toBe(12);

    $wrongOnly = e34_user([], 90);
    PuzzleAttempt::query()->forceCreate(['user_id' => $wrongOnly->id, 'puzzle_id' => $puzzle->id, 'attempt_number' => 1, 'is_correct' => false, 'used_hint' => false, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12)]);
    expect($svc->isEligible($wrongOnly))->toBeFalse(); // محاولة خاطئة ليست لعبًا موثوقًا
});

test('Z5-03: the comeback state clears by itself on the first official correct solve', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());
    $svc = app(ComebackService::class);
    expect($svc->stateFor($u))->not->toBeNull();

    e34_solve($u);

    expect(app(ComebackService::class)->stateFor($u))->toBeNull()
        ->and(PlayerStreak::query()->where('user_id', $u->id)->value('last_active_date')->toDateString())->toBe(now()->toDateString());
});

test('Z5-04: visiting pages or logging in never clears it - only real official play does', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());

    $this->actingAs($u)->get('/missions')->assertOk();
    $this->actingAs($u)->get('/')->assertOk();
    $this->actingAs($u)->get('/events')->assertOk();
    $this->post('/logout');

    expect(app(ComebackService::class)->isEligible($u))->toBeTrue();
});

test('Z5-05: there is no comeback gem reward - no config key, no wallet or XP movement, and no reward promise on the page', function () {
    expect(array_keys((array) config('liveops.comeback')))->toBe(['min_inactive_days', 'notify_max_days']);

    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());
    $before = [XpTransaction::query()->count(), GemTransaction::query()->count(), CurrencyTransaction::query()->count()];

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    expect([XpTransaction::query()->count(), GemTransaction::query()->count(), CurrencyTransaction::query()->count()])->toBe($before);
    foreach (['مكافأة العودة', 'جواهر مجانية', 'هدية العودة', 'ستفقد', 'فاتك', 'اشترِ'] as $phrase) {
        expect($html)->not->toContain($phrase);
    }
});

test('Z5-06: suggestions are easy, active, official, unsolved puzzles chosen deterministically per player and day, at most three items', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());

    $ok = collect(range(1, 4))->map(fn ($i) => Puzzle::factory()->create(['title' => "سهلة {$i}", 'difficulty' => 'easy']));
    Puzzle::factory()->create(['title' => 'صعبة', 'difficulty' => 'hard']);
    Puzzle::factory()->create(['title' => 'معطّلة', 'difficulty' => 'easy', 'is_active' => false]);
    $solved = Puzzle::factory()->create(['title' => 'محلولة', 'difficulty' => 'easy']);
    PuzzleAttempt::query()->forceCreate(['user_id' => $u->id, 'puzzle_id' => $solved->id, 'attempt_number' => 1, 'is_correct' => true, 'used_hint' => false, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12)]);

    $svc = app(ComebackService::class);
    $first = $svc->suggestions($u);
    $second = app(ComebackService::class)->suggestions($u);

    expect($first)->toBe($second)->and(count($first))->toBeLessThanOrEqual(3);

    $puzzleLine = collect($first)->firstWhere('kind', 'puzzle');
    expect($puzzleLine)->not->toBeNull();
    expect(collect($ok)->contains(fn ($p) => str_contains($puzzleLine['title'], $p->title)))->toBeTrue()
        ->and($puzzleLine['title'])->not->toContain('صعبة')->not->toContain('معطّلة')->not->toContain('محلولة');
});

test('Z5-07: the mission center welcomes returners with a calm card and shows nothing for active players', function () {
    $returner = e34_user();
    e34_streak($returner, 3, 5, now()->subDays(10)->toDateString());
    $active = e34_user();
    e34_streak($active, 3, 5, now()->toDateString());
    e34_quest();

    $html = $this->actingAs($returner)->get('/missions')->assertOk()->getContent();
    expect($html)->toContain('مرحبًا بعودتك')->toContain(now()->subDays(10)->toDateString());

    $this->actingAs($active)->get('/missions')->assertOk()->assertDontSee('مرحبًا بعودتك');
});

test('Z5-08: the comeback suggestions include one light in-progress mission when the player has one', function () {
    $u = e34_user();
    e34_streak($u, 3, 5, now()->subDays(10)->toDateString());
    e34_quest(['name' => 'مهمة خفيفة جدًا', 'target_value' => 2]);

    $data = app(MissionCenterService::class)->forUser($u, true);

    expect($data['comeback'])->not->toBeNull()
        ->and(collect($data['comeback']['suggestions'])->pluck('kind')->all())->toContain('mission')
        ->and(collect($data['comeback']['suggestions'])->firstWhere('kind', 'mission')['title'])->toContain('مهمة خفيفة جدًا');
});

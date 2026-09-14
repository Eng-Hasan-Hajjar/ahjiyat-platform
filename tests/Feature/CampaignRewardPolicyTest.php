<?php

use App\Models\CampaignStep;
use App\Models\GemTransaction;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\GameSessionService;
use App\Services\PuzzleAttemptService;

function campaignStepWithReward(string $rewardMode, ?int $overrideAmount = null, array $puzzleOverrides = []): CampaignStep
{
    $puzzle = Puzzle::factory()->create(array_merge([
        'answer_raw' => 'صح',
        'max_attempts' => 3,
        'gem_reward' => 10,
    ], $puzzleOverrides));

    return CampaignStep::factory()->puzzle()->create([
        'puzzle_id' => $puzzle->id,
        'reward_mode' => $rewardMode,
        'reward_override_amount' => $overrideAmount,
    ]);
}

test('standalone puzzle reward is unchanged', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 12]);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');

    expect($result['gems_awarded'])->toBe(12);
});

test('a campaign step with reward_mode=inherit awards the puzzle normal reward', function () {
    $step = campaignStepWithReward('inherit', null, ['gem_reward' => 10]);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($step->id));

    expect($result['gems_awarded'])->toBe(10);
});

test('a campaign step with reward_mode=override awards the override amount, not the puzzle default', function () {
    $step = campaignStepWithReward('override', 50, ['gem_reward' => 10]);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($step->id));

    expect($result['gems_awarded'])->toBe(50);
});

test('a campaign step with reward_mode=none awards zero and creates no transaction', function () {
    $step = campaignStepWithReward('none', null, ['gem_reward' => 10]);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($step->id));

    expect($result['gems_awarded'])->toBe(0)
        ->and(GemTransaction::where('user_id', $user->id)->count())->toBe(0);
});

test('override reward still respects the daily earn cap', function () {
    config(['gems.daily_earn_cap' => 30]);
    $step = campaignStepWithReward('override', 1000, ['gem_reward' => 10]);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($step->id));

    expect($result['gems_awarded'])->toBe(30);
});

test('a wrong attempt in any campaign reward mode earns zero', function () {
    $step = campaignStepWithReward('override', 999);
    $user = User::factory()->create();

    $result = app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'خطأ', false, [], App\GameEngine\Support\AttemptContext::campaignStep($step->id));

    expect($result['gems_awarded'])->toBe(0);
});

test('a duplicate correct attempt on the same campaign step does not reward twice', function () {
    $step = campaignStepWithReward('override', 20);
    $user = User::factory()->create();
    $context = App\GameEngine\Support\AttemptContext::campaignStep($step->id);

    app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح', false, [], $context);
    $user->wallet->refresh();
    $firstBalance = $user->wallet->pending_balance;

    expect(fn () => app(PuzzleAttemptService::class)->attempt($user, $step->puzzle, 'صح', false, [], $context))
        ->toThrow(RuntimeException::class);

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe($firstBalance);
});

test('the same puzzle resolves a different reward policy per context', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'max_attempts' => 5, 'gem_reward' => 10]);
    $overrideStep = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id, 'reward_mode' => 'override', 'reward_override_amount' => 77]);
    $noneStep = CampaignStep::factory()->puzzle()->create(['puzzle_id' => $puzzle->id, 'reward_mode' => 'none']);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $resultA = app(PuzzleAttemptService::class)->attempt($userA, $puzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($overrideStep->id));
    $resultB = app(PuzzleAttemptService::class)->attempt($userB, $puzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($noneStep->id));

    expect($resultA['gems_awarded'])->toBe(77)
        ->and($resultB['gems_awarded'])->toBe(0);
});

test('a context pointing to a step whose puzzle_id differs cannot steal an override reward', function () {
    $overriddenPuzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 5]);
    $otherPuzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 5]);

    // الخطوة مربوطة بـotherPuzzle، وreward_mode=override بقيمة كبيرة
    $step = CampaignStep::factory()->puzzle()->create([
        'puzzle_id' => $otherPuzzle->id,
        'reward_mode' => 'override',
        'reward_override_amount' => 999,
    ]);
    $user = User::factory()->create();

    // نحاول حل overriddenPuzzle (المختلفة) بنفس Context خطوة otherPuzzle
    $result = app(PuzzleAttemptService::class)->attempt(
        $user, $overriddenPuzzle, 'صح', false, [], App\GameEngine\Support\AttemptContext::campaignStep($step->id)
    );

    // يجب أن يحصل على مكافأة overriddenPuzzle الطبيعية (5) لا الـoverride المسروقة (999)
    expect($result['gems_awarded'])->toBe(5);
});

test('a stateful campaign GameSession uses the campaign reward policy after finalize', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.25, 'y' => 0.25, 'radius' => 0.05]]],
        'gem_reward' => 10,
    ]);
    $step = CampaignStep::factory()->puzzle()->create([
        'puzzle_id' => $puzzle->id,
        'reward_mode' => 'override',
        'reward_override_amount' => 42,
    ]);
    $user = User::factory()->create();

    $session = app(GameSessionService::class)->start($user, $puzzle, App\GameEngine\Support\AttemptContext::campaignStep($step->id));
    $result = app(GameSessionService::class)->reveal($session, 0.25, 0.25);

    expect($result['completed'])->toBeTrue()
        ->and($result['gems_awarded'])->toBe(42);
});

test('a standalone GameSession still uses the default puzzle reward after finalize', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => [['x' => 0.25, 'y' => 0.25, 'radius' => 0.05]]],
        'gem_reward' => 18,
    ]);
    $user = User::factory()->create();

    $session = app(GameSessionService::class)->start($user, $puzzle);
    $result = app(GameSessionService::class)->reveal($session, 0.25, 0.25);

    expect($result['gems_awarded'])->toBe(18);
});
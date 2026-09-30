<?php

use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\CampaignPuzzleService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->campaignPuzzles = app(CampaignPuzzleService::class);
    $this->user = User::factory()->create();
});

function e12MakePuzzleStep(int $puzzleXp, string $xpMode, ?int $xpOverride = null): CampaignStep
{
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => $puzzleXp]);

    return CampaignStep::factory()->puzzle()->create([
        'puzzle_id' => $puzzle->id,
        'xp_mode' => $xpMode,
        'xp_override_amount' => $xpOverride,
    ]);
}

test('E12 req 197: xp_mode=inherit grants exactly the puzzle own XP once', function () {
    $step = e12MakePuzzleStep(20, CampaignStep::XP_MODE_INHERIT);

    $this->campaignPuzzles->attempt($this->user, $step, 'صح', false, []);

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(20);
});

test('E12 req 198/234: xp_mode=override grants the step amount, never puzzle+step combined', function () {
    $step = e12MakePuzzleStep(20, CampaignStep::XP_MODE_OVERRIDE, 35);

    $this->campaignPuzzles->attempt($this->user, $step, 'صح', false, []);

    expect($this->user->fresh()->playerProgression->total_xp)->toBe(35);
});

test('E12 req 199: xp_mode=none grants zero XP even though the puzzle itself has an xp_reward', function () {
    $step = e12MakePuzzleStep(20, CampaignStep::XP_MODE_NONE);

    $this->campaignPuzzles->attempt($this->user, $step, 'صح', false, []);

    expect(\App\Models\PlayerProgression::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('E12 req 183/39: a campaign puzzle solve never grants both puzzle XP and campaign-step XP - exactly one XpTransaction row', function () {
    $step = e12MakePuzzleStep(20, CampaignStep::XP_MODE_OVERRIDE, 35);

    $this->campaignPuzzles->attempt($this->user, $step, 'صح', false, []);

    expect(XpTransaction::where('user_id', $this->user->id)->count())->toBe(1);
});

test('a campaign-context puzzle solve is tagged as campaign_step type, not puzzle_solve', function () {
    $step = e12MakePuzzleStep(20, CampaignStep::XP_MODE_INHERIT);

    $this->campaignPuzzles->attempt($this->user, $step, 'صح', false, []);

    $transaction = XpTransaction::where('user_id', $this->user->id)->first();
    expect($transaction->type)->toBe(XpTransaction::TYPE_CAMPAIGN_STEP);
});

test('the same puzzle solved standalone (outside any campaign) uses its own default resolution independently', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'xp_reward' => 20]);

    app(PuzzleAttemptService::class)->attempt($this->user, $puzzle, 'صح');

    $transaction = XpTransaction::where('user_id', $this->user->id)->first();
    expect($transaction->type)->toBe(XpTransaction::TYPE_PUZZLE_SOLVE)
        ->and($this->user->fresh()->playerProgression->total_xp)->toBe(20);
});
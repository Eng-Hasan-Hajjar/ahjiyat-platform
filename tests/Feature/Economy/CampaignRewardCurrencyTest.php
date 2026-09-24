<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\CampaignPuzzleService;
use App\Services\Economy\CurrencyRegistry;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->service = app(CampaignPuzzleService::class);
});

function makeE9TestCampaignPuzzleStep(array $stepAttributes = []): CampaignStep
{
    $campaign = Campaign::factory()->create();
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 8]);

    return CampaignStep::factory()->create(array_merge([
        'campaign_gate_id' => $gate->id,
        'sort_order' => 1,
        'kind' => CampaignStep::KIND_PUZZLE,
        'puzzle_id' => $puzzle->id,
        'reward_mode' => CampaignStep::REWARD_MODE_INHERIT,
    ], $stepAttributes));
}

test('inherit reward mode uses the puzzle currency and amount unchanged', function () {
    $step = makeE9TestCampaignPuzzleStep();
    $user = User::factory()->create();

    $result = $this->service->attempt($user, $step, 'صح', false, []);

    expect($result['gems_awarded'])->toBe(8);

    $legacy = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    expect($user->wallet->fresh()->currency_id)->toBe($legacy->id)
        ->and($user->wallet->fresh()->pending_balance)->toBe(8);
});

test('override reward mode grants the configured currency and amount, not the puzzle default', function () {
    $eventCurrency = Currency::factory()->create();
    $step = makeE9TestCampaignPuzzleStep([
        'reward_mode' => CampaignStep::REWARD_MODE_OVERRIDE,
        'reward_override_amount' => 40,
        'reward_currency_id' => $eventCurrency->id,
    ]);
    $user = User::factory()->create();

    $result = $this->service->attempt($user, $step, 'صح', false, []);

    expect($result['gems_awarded'])->toBe(40);

    $eventWallet = \App\Models\Wallet::where('user_id', $user->id)->where('currency_id', $eventCurrency->id)->first();
    expect($eventWallet->pending_balance)->toBe(40)
        ->and($user->wallet->fresh()->pending_balance)->toBe(0);
});

test('none reward mode grants no reward and no wallet is touched', function () {
    $step = makeE9TestCampaignPuzzleStep(['reward_mode' => CampaignStep::REWARD_MODE_NONE]);
    $user = User::factory()->create();

    $result = $this->service->attempt($user, $step, 'صح', false, []);

    expect($result['gems_awarded'])->toBe(0)
        ->and($user->wallet->fresh()->pending_balance)->toBe(0);
});
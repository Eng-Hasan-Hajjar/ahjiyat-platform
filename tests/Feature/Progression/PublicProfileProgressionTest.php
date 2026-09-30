<?php

use App\Models\LevelDefinition;
use App\Models\User;
use App\Services\Progression\XpService;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى 2']);
});

test('E12 req 213/276: a public profile shows the current level number', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    app(XpService::class)->grantXp($player, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $this->get(route('players.show', $player))->assertOk()->assertSee('المستوى 2');
});

test('E12 req 133/278: a public profile never leaks any XP transaction reason or amount', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    app(XpService::class)->grantXp($player, 77, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'سبب-سري-للاختبار');

    $response = $this->get(route('players.show', $player));

    $response->assertOk()
        ->assertDontSee('سبب-سري-للاختبار')
        ->assertDontSee('77');
});

test('a public profile shows the count of unlocked achievements, not any internal keys', function () {
    $achievement = \App\Models\Achievement::factory()->puzzlesSolvedTotal(1)->create(['internal_key' => 'super_secret_internal_key']);
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);

    $puzzle = \App\Models\Puzzle::factory()->create(['answer_raw' => 'صح']);
    app(\App\Services\PuzzleAttemptService::class)->attempt($player, $puzzle, 'صح');
    app(\App\Services\Progression\AchievementService::class)->evaluateForEvent('puzzle_solved', $player);

    $response = $this->get(route('players.show', $player));

    $response->assertOk()
        ->assertSee('1', false)
        ->assertDontSee('super_secret_internal_key');
});

test('privacy semantics from E11 are unchanged - a private profile still returns 404 to a stranger', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    app(XpService::class)->grantXp($player, 50, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('players.show', $player))->assertNotFound();
});
<?php

use App\Models\Puzzle;
use App\Models\User;

test('an authenticated user can view a puzzle', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create();

    $this->actingAs($user)
        ->get(route('puzzles.show', $puzzle))
        ->assertOk()
        ->assertSee($puzzle->prompt);
});

test('submitting the correct legacy answer redirects with a success message and awards gems', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب', 'gem_reward' => 8]);

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), ['answer' => 'الجواب'])
        ->assertRedirect(route('puzzles.show', $puzzle))
        ->assertSessionHas('success');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(8);
});

test('submitting a wrong answer redirects back with an error message and awards no gems', function () {
    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب']);

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), ['answer' => 'شيء آخر'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(0);
});

test('a sequence puzzle can be solved by submitting a structured JSON payload', function () {
    $user = User::factory()->create();

    $puzzle = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'validation_type' => 'sequence_match',
        'score_mode' => 'flat',
        'renderer' => 'games.sequence',
        'game_config' => ['items' => ['أ', 'ب', 'ج']],
        'gem_reward' => 15,
    ]);

    $this->actingAs($user)
        ->post(route('puzzles.attempt', $puzzle), [
            'submission' => json_encode(['order' => [0, 1, 2]]),
        ])
        ->assertRedirect(route('puzzles.show', $puzzle))
        ->assertSessionHas('success');

    $user->wallet->refresh();
    expect($user->wallet->pending_balance)->toBe(15);
});

test('the sequence renderer never exposes the correct order in the rendered HTML', function () {
    $user = User::factory()->create();

    $puzzle = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'validation_type' => 'sequence_match',
        'score_mode' => 'flat',
        'renderer' => 'games.sequence',
        'game_config' => ['items' => ['أ', 'ب', 'ج']],
    ]);

    $response = $this->actingAs($user)->get(route('puzzles.show', $puzzle));

    // solution_data (الترتيب الصحيح) يجب ألا يظهر إطلاقاً بمصدر الصفحة
    $response->assertDontSee('"order":[0,1,2]', false);
});
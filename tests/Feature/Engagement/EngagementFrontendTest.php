<?php

use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

test('the quests page renders daily and weekly quests with correct progress and aria attributes', function () {
    QuestDefinition::factory()->daily(3)->create(['name' => 'مهمة يومية تجريبية']);
    QuestDefinition::factory()->weekly(10)->create(['name' => 'هدف أسبوعي تجريبي']);

    $response = $this->actingAs($this->user)->get(route('quests.show'));

    $response->assertOk()
        ->assertSee('مهمة يومية تجريبية')
        ->assertSee('هدف أسبوعي تجريبي')
        ->assertSee('aria-valuemin', false)
        ->assertSee('aria-valuemax', false)
        ->assertSee('aria-valuenow', false);
});

test('E13 req 369: no payment CTA appears anywhere on the quests page', function () {
    QuestDefinition::factory()->daily(1)->create();

    $response = $this->actingAs($this->user)->get(route('quests.show'));

    $response->assertOk()
        ->assertDontSee('اشترِ')
        ->assertDontSee('Buy Premium')
        ->assertDontSee('Buy Currency')
        ->assertDontSee('Quest Boost');
});

test('E13 req 370: no claim button appears - rewards are automatic', function () {
    QuestDefinition::factory()->daily(1)->create();

    $response = $this->actingAs($this->user)->get(route('quests.show'));

    $response->assertOk()->assertDontSee('Claim Reward')->assertDontSee('طالب بالمكافأة');
});

test('E13 req 372: no countdown timer pressure language appears', function () {
    QuestDefinition::factory()->daily(1)->create();

    $response = $this->actingAs($this->user)->get(route('quests.show'));

    $response->assertOk()
        ->assertDontSee('ستفقد')
        ->assertDontSee('باقي دقائق')
        ->assertDontSee('ادفع للحفاظ');
});

test('the dashboard shows a quest/streak summary card linking to /quests for an authenticated user', function () {
    $response = $this->actingAs($this->user)->get('/');

    $response->assertOk()->assertSee(route('quests.show'), false);
});

test('a completed quest with a reward that failed shows an honest in-progress state, not a false success', function () {
    $currency = \App\Models\Currency::factory()->create(['is_earnable' => false, 'is_active' => true]);
    $quest = QuestDefinition::factory()->daily(1)->create(['reward_currency_id' => $currency->id, 'reward_currency_amount' => 10]);
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    app(PuzzleAttemptService::class)->attempt($this->user, $puzzle, 'صح');

    $response = $this->actingAs($this->user)->get(route('quests.show'));

    $response->assertOk()->assertSee('جارٍ معالجة المكافأة');
});

test('the read-only API endpoint returns a safe engagement payload with no internal metadata', function () {
    QuestDefinition::factory()->daily(1)->create();

    $response = $this->actingAs($this->user)->getJson('/api/engagement');

    $response->assertOk()->assertJsonStructure(['daily_quests', 'weekly_quests', 'current_streak', 'longest_streak']);
});

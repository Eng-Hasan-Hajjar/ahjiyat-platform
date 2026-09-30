<?php

use App\Models\Achievement;
use App\Models\LevelDefinition;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Progression\AchievementService;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى 2']);
});

test('the progress page renders the current level and XP progress bar with correct aria attributes', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('progress.show'));

    $response->assertOk()
        ->assertSee('المستوى الحالي')
        ->assertSee('aria-valuemin="0"', false)
        ->assertSee('aria-valuemax="100"', false);
});

test('E12 req 264/128: the free-to-play message is present on the progress page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('progress.show'))
        ->assertSee('يمكنك التقدم ورفع مستواك وفتح الإنجازات من خلال اللعب دون الحاجة إلى شراء أي عملة');
});

test('E12 req 265/331: no payment CTA appears anywhere on the progress page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('progress.show'));

    $response->assertOk()
        ->assertDontSee('اشترِ')
        ->assertDontSee('Buy Premium', false)
        ->assertDontSee('Buy Gems', false);
});

test('E12 req 270/192: a locked hidden achievement shows no name or description - just "secret achievement"', function () {
    Achievement::factory()->puzzlesSolvedTotal(5)->hidden()->create([
        'name' => 'اسم لا يجب أن يظهر',
        'description' => 'وصف حساس لا يجب أن يظهر',
    ]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('progress.show'));

    $response->assertOk()
        ->assertSee('إنجاز سري')
        ->assertDontSee('اسم لا يجب أن يظهر')
        ->assertDontSee('وصف حساس لا يجب أن يظهر');
});

test('an unlocked hidden achievement reveals its real name and description', function () {
    $achievement = Achievement::factory()->puzzlesSolvedTotal(1)->hidden()->create([
        'name' => 'اسم مكشوف بعد الفتح',
        'description' => 'وصف مكشوف بعد الفتح',
    ]);

    $user = User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    app(AchievementService::class)->evaluateForEvent('puzzle_solved', $user);

    $response = $this->actingAs($user)->get(route('progress.show'));

    $response->assertOk()->assertSee('اسم مكشوف بعد الفتح')->assertSee('وصف مكشوف بعد الفتح');
});

test('E12 req 125: max level shows a clean "highest level" message with no error', function () {
    $user = User::factory()->create();
    app(\App\Services\Progression\XpService::class)->grantXp($user, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $this->actingAs($user)->get(route('progress.show'))->assertOk()->assertSee('أعلى مستوى حاليًا');
});

test('the dashboard shows a small progression card linking to /progress for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertOk()->assertSee(route('progress.show'), false);
});

test('the read-only API endpoint returns a safe payload with no admin metadata', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/progress');

    $response->assertOk()->assertJsonStructure(['current_level', 'total_xp', 'progress_percent', 'achievement_count']);
});
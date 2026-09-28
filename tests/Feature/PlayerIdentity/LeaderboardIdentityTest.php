<?php

use App\Models\PuzzleAttempt;
use App\Models\Puzzle;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

function makeE11SolvedAttempt(User $user): void
{
    PuzzleAttempt::create([
        'user_id' => $user->id,
        'puzzle_id' => Puzzle::factory()->create()->id,
        'attempt_number' => 1,
        'is_correct' => true,
    ]);
}

test('leaderboard ranking order is unaffected by cosmetic identity - purely solved-count driven', function () {
    $strongUser = User::factory()->create(['name' => 'الأقوى']);
    $weakUser = User::factory()->create(['name' => 'الأضعف']);

    makeE11SolvedAttempt($strongUser);
    makeE11SolvedAttempt($strongUser);
    makeE11SolvedAttempt($weakUser);

    $response = $this->get(route('leaderboard.index'));
    $content = $response->getContent();

    expect(strpos($content, 'الأقوى'))->toBeLessThan(strpos($content, 'الأضعف'));
});

test('a public profile user is shown as a clickable link on the leaderboard', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC, 'name' => 'لاعب رابط']);
    makeE11SolvedAttempt($user);

    $this->get(route('leaderboard.index'))->assertOk()->assertSee(route('players.show', $user), false);
});

test('a private profile user appears on the leaderboard by name but with no profile link', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE, 'name' => 'لاعب خاص']);
    makeE11SolvedAttempt($user);

    $this->get(route('leaderboard.index'))
        ->assertOk()
        ->assertSee('لاعب خاص')
        ->assertDontSee(route('players.show', $user), false);
});

test('equipped avatar/title render on the leaderboard row without breaking the page', function () {
    $user = User::factory()->create();
    makeE11SolvedAttempt($user);
    $avatar = StoreItem::factory()->cosmeticAvatar()->create(['image_path' => 'store-items/lb-avatar.png']);
    app(InventoryService::class)->grant($user, $avatar, 1, 'test');
    app(CosmeticLoadoutService::class)->equip($user, $avatar);

    $this->get(route('leaderboard.index'))->assertOk()->assertSee('lb-avatar.png', false);
});
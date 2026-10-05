<?php

require_once __DIR__.'/SocialTestHelpers.php';

use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Social\BlockService;

/** لاعب بعدد من المحاولات الصحيحة (يحدد ترتيبه بلوحة الصدارة). */
function e16Solve(User $user, int $count): User
{
    PuzzleAttempt::factory()->count($count)->create(['user_id' => $user->id, 'is_correct' => true]);

    return $user;
}

function e16Board($response): array
{
    return $response->viewData('topUsers')->mapWithKeys(fn ($u) => [$u->name => (int) $u->solved_count])->all();
}

test('35: the friends scope shows me plus accepted friends only', function () {
    $me = e16Solve(e16User(['name' => 'Me Player']), 3);
    $friend = e16Solve(e16User(['name' => 'Friend Player']), 5);
    $other = e16Solve(e16User(['name' => 'Other Player']), 9);
    e16Befriend($me, $friend);

    $board = e16Board($this->actingAs($me)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk());

    expect($board)->toBe(['Friend Player' => 5, 'Me Player' => 3])->and(array_keys($board))->not->toContain($other->name);
});

test('36/37/38: pending, blocked and removed users never appear in the friends scope', function () {
    $me = e16Solve(e16User(['name' => 'Me Player']), 1);
    $pending = e16Solve(e16User(['name' => 'Pending Player']), 7);
    $incoming = e16Solve(e16User(['name' => 'Incoming Player']), 7);
    $blocked = e16Solve(e16User(['name' => 'Blocked Player']), 7);
    $removed = e16Solve(e16User(['name' => 'Removed Player']), 7);
    $kept = e16Solve(e16User(['name' => 'Kept Player']), 2);

    e16Svc()->sendRequest($me, $pending);
    e16Svc()->sendRequest($incoming, $me);
    e16Befriend($me, $blocked);
    app(BlockService::class)->block($me, $blocked);
    e16Befriend($me, $removed);
    e16Svc()->remove($removed, $me);
    e16Befriend($me, $kept);

    $board = e16Board($this->actingAs($me)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk());

    expect(array_keys($board))->toBe(['Kept Player', 'Me Player']);
});

test('39: the global ranking is exactly what it was - friendships change nothing, and friends-scope scores equal the global ones', function () {
    $users = collect(['Alpha' => 6, 'Bravo' => 4, 'Charlie' => 4, 'Delta' => 1])->map(fn ($n, $name) => e16Solve(e16User(['name' => $name]), $n));
    $viewer = $users['Delta'];

    $before = e16Board($this->actingAs($viewer)->get(route('leaderboard.index'))->assertOk());

    e16Befriend($viewer, $users['Alpha']);
    e16Svc()->sendRequest($viewer, $users['Bravo']);
    app(BlockService::class)->block($viewer, $users['Charlie']);

    $after = e16Board($this->actingAs($viewer)->get(route('leaderboard.index'))->assertOk());
    $explicit = e16Board($this->actingAs($viewer)->get(route('leaderboard.index', ['scope' => 'global']))->assertOk());
    $friends = e16Board($this->actingAs($viewer)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk());

    expect($after)->toBe($before)->and(array_keys($after))->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta'])->and($explicit)->toBe($before)
        ->and($friends)->toBe(['Alpha' => $before['Alpha'], 'Delta' => $before['Delta']]); // نقاط الأصدقاء = نقاطهم بالعام
});

test('a guest asking for the friends scope gets the global board; the scope chips are shown to signed-in users only', function () {
    $a = e16Solve(e16User(['name' => 'Solo Player']), 2);
    e16Solve(e16User(['name' => 'Another Player']), 3);

    $guest = $this->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk();

    expect(array_keys(e16Board($guest)))->toBe(['Another Player', 'Solo Player'])->and($guest->viewData('scope'))->toBe('global');
    $guest->assertDontSee('نطاق لوحة الصدارة');
    $this->actingAs($a)->get(route('leaderboard.index'))->assertOk()->assertSee('نطاق لوحة الصدارة')->assertSee('الأصدقاء');
});

test('the friends scope with no friends shows only me, and an empty state when nobody has solved anything', function () {
    $me = e16User(['name' => 'Lonely Player']);

    $this->actingAs($me)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk()->assertSee('لا أحد منكم (أنت وأصدقاؤك) لديه محاولات صحيحة بعد.');

    e16Solve($me, 1);
    expect(array_keys(e16Board($this->actingAs($me)->get(route('leaderboard.index', ['scope' => 'friends']))->assertOk())))->toBe(['Lonely Player']);
});

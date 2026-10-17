<?php

use App\Models\CommunityPuzzleRating;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\Engagement\CommunityRatingService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA1-1: a guest cannot rate (redirected to login, nothing stored)', function () {
    $work = e29_work();

    $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertRedirect(route('login'));
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-2: an unverified user cannot rate', function () {
    $work = e29_work();
    $user = e16User(['email_verified_at' => null]);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertRedirect(route('verification.notice'));
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-3: a frozen user cannot rate', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    $user->forceFill(['is_frozen' => true])->save();

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4]);
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
    expect(fn () => e29_rate($user->fresh(), $work, 4))->toThrow(\App\Services\Engagement\EngagementException::class);
});

test('AA1-4: a user with no attempt cannot rate', function () {
    $work = e29_work();
    $user = e16User();

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4])->assertSessionHas('error');
    expect(CommunityPuzzleRating::query()->count())->toBe(0)->and(e29_counters($work)['count'])->toBe(0);
});

test('AA1-5: a user who attempted (even wrongly) can rate', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4])->assertRedirect()->assertSessionHas('engagement_status');
    expect(CommunityPuzzleRating::query()->where('user_id', $user->id)->value('rating'))->toBe(4);
});

test('AA1-6: the creator cannot rate their own work', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    $this->actingAs($creator)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertSessionHas('error');
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-7: a rating below 1 is rejected server-side', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    foreach ([0, -1, 'abc', '', null] as $bad) {
        $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => $bad])->assertSessionHasErrors('rating');
    }

    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-8: a rating above 5 is rejected server-side (HTTP and service)', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 6])->assertSessionHasErrors('rating');
    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4.5])->assertSessionHasErrors('rating');
    expect(fn () => app(CommunityRatingService::class)->set($user, $work->fresh(), 99))->toThrow(\App\Services\Engagement\EngagementException::class);
    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA1-9: a rating is stored once per user and work', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 3]);
    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 3]);

    expect(CommunityPuzzleRating::query()->where('user_id', $user->id)->count())->toBe(1)->and(e29_counters($work)['count'])->toBe(1);
});

test('AA1-10: a second PUT updates the same rating row', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 2]);
    $id = CommunityPuzzleRating::query()->where('user_id', $user->id)->value('id');
    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5]);

    $row = CommunityPuzzleRating::query()->where('user_id', $user->id)->get();
    expect($row)->toHaveCount(1)->and((int) $row[0]->id)->toBe((int) $id)->and((int) $row[0]->rating)->toBe(5);
});

test('AA1-11: deleting a rating works and is idempotent', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    e29_rate($user, $work, 4);

    $this->actingAs($user)->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertRedirect();
    $this->actingAs($user)->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertRedirect();

    expect(CommunityPuzzleRating::query()->count())->toBe(0)->and(e29_counters($work))->toBe(['count' => 0, 'sum' => 0, 'favorites' => 0]);
});

test('AA1-12: the database itself rejects a duplicate (user, work) rating', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    e29_rate($user, $work, 4);

    expect(fn () => (new CommunityPuzzleRating)->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $work->id, 'rating' => 5])->save())
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA1-13: ratings_count equals the number of rating rows', function () {
    $work = e29_work();
    e29_ratings($work, [5, 4, 3, 1]);

    expect(e29_counters($work)['count'])->toBe(4)->and(CommunityPuzzleRating::query()->where('creator_puzzle_id', $work->id)->count())->toBe(4);
});

test('AA1-14: ratings_sum equals the sum of the rating rows', function () {
    $work = e29_work();
    e29_ratings($work, [5, 4, 3, 1]);

    expect(e29_counters($work)['sum'])->toBe(13)->and((int) CommunityPuzzleRating::query()->where('creator_puzzle_id', $work->id)->sum('rating'))->toBe(13);
});

test('AA1-15: the average is sum / count rounded to one decimal', function () {
    $work = e29_work();
    e29_ratings($work, [5, 4, 4]);

    expect($work->fresh()->averageRating())->toBe(4.3);
    expect(e29_work()->averageRating())->toBeNull();
});

test('AA1-16: changing a rating adjusts the aggregate by the difference only', function () {
    $work = e29_work();
    [$a, $b] = e29_ratings($work, [5, 3]);

    e29_rate($b, $work, 1);

    expect(e29_counters($work))->toBe(['count' => 2, 'sum' => 6, 'favorites' => 0]);
});

test('AA1-17: deleting a rating removes it from the aggregate', function () {
    $work = e29_work();
    [$a, $b] = e29_ratings($work, [5, 3]);

    app(CommunityRatingService::class)->remove($a, $work->fresh());

    expect(e29_counters($work))->toBe(['count' => 1, 'sum' => 3, 'favorites' => 0]);
});

test('AA1-18: a hidden work cannot be newly rated, while old ratings are kept and removable', function () {
    $work = e29_work();
    [$old] = e29_ratings($work, [5]);
    $late = e29_player_who_played($work);
    e29_hide($work);

    expect(fn () => e29_rate($late, $work, 4))->toThrow(\App\Services\Engagement\EngagementException::class);
    expect(fn () => e29_rate($old, $work, 1))->toThrow(\App\Services\Engagement\EngagementException::class);
    expect(e29_counters($work)['count'])->toBe(1);

    $this->actingAs($old)->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertRedirect();
    expect(e29_counters($work)['count'])->toBe(0);
});

test('AA1-19: publishing a new revision keeps the existing ratings and counters', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 4]);

    app(CreatorPuzzleService::class)->revise($creator, $work->public_id);
    app(CreatorPuzzleService::class)->updateDraft($creator, $work->public_id, e28_data('text', ['title' => 'عنوان معدّل بنسخة جديدة']));
    $submitted = app(CreatorPuzzleService::class)->submit($creator, $work->public_id);
    app(CreatorReviewService::class)->approve($submitted, e28_reviewer());

    expect(e29_counters($work))->toBe(['count' => 2, 'sum' => 9, 'favorites' => 0])->and(CommunityPuzzleRating::query()->where('creator_puzzle_id', $work->id)->count())->toBe(2);
});

test('AA1-20: rating grants zero economy, XP, quest, streak or achievement effect', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);
    $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertOk();   // الصفحة نفسها تُنشئ صف التقدّم الكسول؛ نثبّت اللقطة بعدها
    $before = e28_world($user);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertSessionHas('engagement_status');
    $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertOk();

    expect(e28_world($user))->toBe($before);
});

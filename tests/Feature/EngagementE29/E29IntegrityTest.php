<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\CreatorFollowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA10-99: a duplicate rating submission never double counts', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    foreach (range(1, 5) as $i) { e29_rate($user, $work, 4); }

    expect(e29_counters($work))->toBe(['count' => 1, 'sum' => 4, 'favorites' => 0]);
    // محاكاة سباق خاسر: الصف موجود فعلًا -> القيد الفريد يمنع الثاني، والعدّاد لا يتحرك
    expect(fn () => (new CommunityPuzzleRating)->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $work->id, 'rating' => 4])->save())->toThrow(\Illuminate\Database\QueryException::class);
    expect(e29_counters($work)['count'])->toBe(1);
});

test('AA10-100: a duplicate favorite never double counts', function () {
    $work = e29_work();
    $user = e16User();

    foreach (range(1, 5) as $i) { e29_favorite($user, $work); }

    expect(e29_counters($work)['favorites'])->toBe(1)->and(CommunityPuzzleFavorite::query()->count())->toBe(1);
});

test('AA10-101: a duplicate follow never double counts', function () {
    $creator = e28_creator();
    $user = e16User();

    foreach (range(1, 5) as $i) { e29_follow($user, $creator); }

    expect(e29_followers($creator))->toBe(1)->and(CreatorFollow::query()->count())->toBe(1);
});

test('AA10-102: deleting can never drive a counter negative, even if the counter had drifted', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $rater = e29_player_who_played($work);
    $fav = e16User();
    $follower = e16User();
    e29_rate($rater, $work, 5);
    e29_favorite($fav, $work);
    e29_follow($follower, $creator);

    DB::table('creator_puzzles')->where('id', $work->id)->update(['ratings_count' => 0, 'ratings_sum' => 2, 'favorites_count' => 0]);
    DB::table('creator_profiles')->where('id', e29_profile($creator)->id)->update(['followers_count' => 0]);

    app(CommunityRatingService::class)->remove($rater, $work->fresh());
    app(CommunityFavoriteService::class)->remove($fav, $work->fresh());
    app(CreatorFollowService::class)->unfollow($follower, e29_profile($creator));

    expect(e29_counters($work))->toBe(['count' => 0, 'sum' => 0, 'favorites' => 0])->and(e29_followers($creator))->toBe(0);
});

function e29_corrupt(CreatorPuzzle $work, \App\Models\User $creator): void
{
    DB::table('creator_puzzles')->where('id', $work->id)->update(['ratings_count' => 40, 'ratings_sum' => 7, 'favorites_count' => 9]);
    DB::table('creator_profiles')->where('id', e29_profile($creator)->id)->update(['followers_count' => 77]);
}

test('AA10-103: reconcile --dry-run detects the mismatch and changes nothing', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 3]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), $creator);
    e29_corrupt($work, $creator);

    $exit = Artisan::call('community:reconcile-engagement', ['--dry-run' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)->and($out)->toContain('انحراف مكتشف: 4')->and($out)->toContain('dry-run')
        ->and(e29_counters($work))->toBe(['count' => 40, 'sum' => 7, 'favorites' => 9])->and(e29_followers($creator))->toBe(77);
});

test('AA10-104: reconcile fix mode repairs every counter from the rows and is idempotent', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 3]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), $creator);
    e29_corrupt($work, $creator);

    expect(Artisan::call('community:reconcile-engagement'))->toBe(0);
    expect(e29_counters($work))->toBe(['count' => 2, 'sum' => 8, 'favorites' => 1])->and(e29_followers($creator))->toBe(1);

    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true]))->toBe(0)->and(Artisan::output())->toContain('انحراف مكتشف: 0');
    expect(Artisan::call('community:reconcile-engagement'))->toBe(0)->and(e29_counters($work)['count'])->toBe(2);
});

test('AA10-105: hide and restore keep every rating, favorite and follow (nothing is deleted or double counted)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 4]);
    e29_favorite(e16User(), $work);
    e29_follow(e16User(), $creator);
    $before = [e29_counters($work), e29_followers($creator)];

    e29_hide($work);
    expect(CommunityPuzzleRating::query()->count())->toBe(2)->and(e29_counters($work))->toBe($before[0]);
    e29_unhide($work);

    expect(e29_counters($work))->toBe($before[0])->and(e29_followers($creator))->toBe($before[1]);
    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('حفظوها');
});

test('AA10-106: suspending a creator keeps follows and ratings; restoring brings them back to the feed', function () {
    $creator = e28_creator();
    $work = e29_work($creator, ['title' => 'يعود بعد الاستعادة']);
    $follower = e16User();
    e29_follow($follower, $creator);
    e29_ratings($work, [5]);
    $admin = e28_reviewer('administrator');

    app(CreatorApplicationService::class)->suspend(e29_profile($creator), $admin, 'تعليق تجريبي للاختبار فقط.');
    expect(CreatorFollow::query()->count())->toBe(1)->and(CommunityPuzzleRating::query()->count())->toBe(1);
    expect(e29_feed_section($this->actingAs($follower)->get(route('following.index'))->getContent()))->toBe('');
    $this->actingAs($follower)->get(route('creators.show', e29_profile($creator)->slug))->assertNotFound();

    app(CreatorApplicationService::class)->restore(e29_profile($creator)->fresh(), $admin, 'استعادة تجريبية.');

    expect(e29_feed_section($this->actingAs($follower)->get(route('following.index'))->getContent()))->toContain('يعود بعد الاستعادة');
});

test('AA10-107: deleting a user removes private lists, fixes counters, and keeps their rating anonymously in the aggregate', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $other = e29_player_who_played($work);
    e29_rate($user, $work, 5);
    e29_rate($other, $work, 3);
    e29_favorite($user, $work);
    e29_follow($user, $creator);
    $c = e29_collection($user);
    app(CollectionService::class)->add($user, $c->public_id, $work->fresh());

    $user->delete();

    expect(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(CreatorFollow::query()->count())->toBe(0)
        ->and(UserCollection::query()->count())->toBe(0)->and(UserCollectionItem::query()->count())->toBe(0)
        ->and(e29_counters($work))->toBe(['count' => 2, 'sum' => 8, 'favorites' => 0])->and(e29_followers($creator))->toBe(0)
        ->and(CommunityPuzzleRating::query()->whereNull('user_id')->count())->toBe(1);
    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true]))->toBe(0);
});

test('AA10-108: deleting a work (admin hard delete) cascades its interactions and leaves no orphan rows', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    e29_rate($user, $work, 4);
    e29_favorite($user, $work);
    $c = e29_collection($user);
    app(CollectionService::class)->add($user, $c->public_id, $work->fresh());

    DB::table('creator_puzzles')->where('id', $work->id)->delete();

    expect(CommunityPuzzleRating::query()->count())->toBe(0)->and(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(UserCollectionItem::query()->count())->toBe(0)
        ->and(UserCollection::query()->count())->toBe(1);
});

test('AA10-109: the whole engagement flow touches no wallet, XP, quest, streak, achievement, campaign or official attempt', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();
    $before = e28_world($user);
    $creatorBefore = e28_world($creator);

    e29_rate($user, $work, 5);
    e29_favorite($user, $work);
    e29_follow($user, $creator);
    $c = e29_collection($user);
    app(CollectionService::class)->add($user, $c->public_id, $work->fresh());
    e28_published($creator, 'text');   // إشعار نشر لمتابع
    foreach (['favorites.index', 'following.index', 'collections.index', 'discover.index'] as $name) { $this->actingAs($user)->get(route($name))->assertOk(); }

    expect(e28_world($user))->toBe($before)->and(e28_world($creator))->toBe($creatorBefore);
});

test('AA10-110: the reconciler repairs a large chunked set (chunk size smaller than the table)', function () {
    $creator = e28_creator();
    $works = collect(range(1, 6))->map(fn () => e29_work($creator));
    $works->each(fn ($w) => e29_favorite(e16User(), $w));
    $works->each(fn ($w) => DB::table('creator_puzzles')->where('id', $w->id)->update(['favorites_count' => 50]));

    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true, '--chunk' => 50]))->toBe(1);
    expect(Artisan::call('community:reconcile-engagement', ['--chunk' => 50]))->toBe(0);
    expect($works->every(fn ($w) => e29_counters($w)['favorites'] === 1))->toBeTrue();
});

<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\UserCollection;
use App\Services\Creators\CreatorAnalyticsService;
use Database\Seeders\DemoEngagementSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_stats(\App\Models\User $creator): array
{
    Cache::flush();

    return app(CreatorAnalyticsService::class)->forCreator(e29_profile($creator));
}

test('AA12-121: creator analytics expose followers, favorites received, ratings, average and top work (aggregates only)', function () {
    config(['engagement.top_rated.min_ratings' => 3, 'engagement.ratings.display_min' => 3]);
    $creator = e28_creator();
    $best = e29_work($creator, ['title' => 'أفضل أعمالي']);
    $other = e29_work($creator, ['title' => 'عمل آخر']);
    e29_ratings($best, [5, 5, 4]);
    e29_ratings($other, [3]);
    e29_favorite(e16User(['name' => 'اسم لا يجب أن يظهر']), $best);
    e29_follow(e16User(), $creator);
    e29_follow(e16User(), $creator);

    $stats = e29_stats($creator);

    expect($stats['followers'])->toBe(2)->and($stats['favorites_received'])->toBe(1)->and($stats['ratings_count'])->toBe(4)->and($stats['average_rating'])->toBe(4.3)
        ->and($stats['top_rated_work']['title'])->toBe('أفضل أعمالي')->and($stats['top_rated_work']['count'])->toBe(3);
    expect(json_encode($stats))->not->toContain('اسم لا يجب أن يظهر')->and(json_encode($stats))->not->toContain('@');
});

test('AA12-122: analytics hide the average below the minimum sample and ignore hidden works', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_ratings($work, [5, 5]);

    $stats = e29_stats($creator);
    expect($stats['average_rating'])->toBeNull()->and($stats['ratings_count'])->toBe(2)->and($stats['top_rated_work'])->toBeNull();

    e29_ratings($work, [5, 5, 5]);
    expect(e29_stats($creator)['average_rating'])->toBe(5.0);

    e29_hide($work);
    $hidden = e29_stats($creator);
    expect($hidden['ratings_count'])->toBe(0)->and($hidden['favorites_received'])->toBe(0);
});

test('AA12-123: analytics reuse the E28 cache (second read costs zero queries) and the dashboard renders them', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    e29_follow(e16User(), $creator);
    e29_ratings($work, [4, 4, 4]);
    Cache::flush();

    $profile = e29_profile($creator);
    $first = app(CreatorAnalyticsService::class)->forCreator($profile);
    $queries = e27_queries(fn () => app(CreatorAnalyticsService::class)->forCreator($profile));

    expect($queries)->toBe(0)->and($first['followers'])->toBe(1);
    $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk()->assertSee('متابعون')->assertSee('حفظوا أعمالك')->assertSee('متوسط التقييم')->assertSee('4.0 من 5');
});

test('AA12-124: the admin summary aggregates follows, ratings, favorites, collections, top rated and most followed (cached, no identities)', function () {
    config(['engagement.top_rated.min_ratings' => 3]);
    $creator = e28_creator();
    $work = e29_work($creator, ['title' => 'الأعلى تقييمًا للإدارة']);
    e29_ratings($work, [5, 5, 4]);
    e29_favorite($fan = e16User(['name' => 'معجب خاص']), $work);
    e29_follow($fan, $creator);
    e29_collection($fan, 'مجموعة المعجب');
    Cache::flush();

    $s = app(CreatorAnalyticsService::class)->adminSummary();

    expect($s['total_follows'])->toBe(1)->and($s['total_ratings'])->toBe(3)->and($s['total_favorites'])->toBe(1)->and($s['total_collections'])->toBe(1)
        ->and($s['top_rated'][0]['title'])->toBe('الأعلى تقييمًا للإدارة')->and($s['most_followed'][0]['followers'])->toBe(1);
    expect(json_encode($s))->not->toContain('معجب خاص')->and(e27_queries(fn () => app(CreatorAnalyticsService::class)->adminSummary()))->toBe(0);
});

test('AA12-125: the creator page shows the quality summary only past the minimum, and follow states per viewer', function () {
    config(['engagement.creator_quality.min_ratings' => 5]);
    $creator = e28_creator();
    $slug = e29_profile($creator)->slug;
    $work = e29_work($creator);
    e29_ratings($work, [5, 5, 5, 5]);

    expect($this->get(route('creators.show', $slug))->assertOk()->getContent())->not->toContain('متوسط تقييم أعماله')->toContain('سجّل الدخول للمتابعة')->toContain('0 متابعين');

    e29_ratings($work, [4]);
    $html = $this->get(route('creators.show', $slug))->assertOk()->getContent();
    expect($html)->toContain('متوسط تقييم أعماله')->toContain('4.8 من 5');

    $viewer = e16User();
    expect($this->actingAs($viewer)->get(route('creators.show', $slug))->getContent())->toContain(route('creators.follow', $slug))->not->toContain('إلغاء متابعة هذا المبدع')->not->toContain('تتابعه');
    e29_follow($viewer, $creator);
    expect($this->actingAs($viewer)->get(route('creators.show', $slug))->getContent())->toContain('إلغاء متابعة هذا المبدع')->toContain('تتابعه')->toContain('1 متابع');
    $self = $this->actingAs($creator)->get(route('creators.show', $slug))->getContent();
    expect($self)->not->toContain(route('creators.follow', $slug))->not->toContain('سجّل الدخول للمتابعة');
    expect($this->actingAs(e16User(['email_verified_at' => null]))->get(route('creators.show', $slug))->getContent())->toContain('وثّق بريدك الإلكتروني لتتمكن من المتابعة');
});

test('AA12-126: a small sample is never marketed as a 5.0 average', function () {
    $work = e29_work();
    e29_ratings($work, [5]);

    $show = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    $index = $this->get(route('community.puzzles.index'))->assertOk()->getContent();

    foreach ([$show, $index] as $html) {
        expect($html)->not->toContain('متوسط التقييم')->toContain('1 تقييم');
    }

    e29_ratings($work, [5, 5]);
    expect($this->get(route('community.puzzles.show', $work->public_id))->getContent())->toContain('متوسط التقييم 5.0 من 5 بحسب 3 تقييم');
});

test('AA12-127: the rating control is a keyboard-accessible radio group with a legend and text labels (not colour alone)', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);
    e29_rate($viewer, $work, 3);

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    $panel = e29_section($html, 'رأي المجتمع') ?: $html;

    expect($html)->toContain('<legend')->toContain('تقييمك لهذه الأحجية')->toContain('role="radiogroup"')->toContain('dir="rtl"')
        ->and(substr_count($html, 'class="peer sr-only"'))->toBe(5)
        ->and($html)->toContain('3 من 5')->toContain('تقييمك الحالي: 3 من 5')->toContain('peer-focus-visible:ring-2')
        ->and(preg_match_all('/<input type="radio" name="rating" id="rating-\d" value="\d"[^>]*checked/', $html))->toBe(1);
});

test('AA12-128: a user who has not played sees the gate message instead of the stars', function () {
    $work = e29_work();
    $viewer = e16User();

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect($html)->toContain('جرّب الأحجية ولو بمحاولة واحدة')->not->toContain('role="radiogroup"');
});

test('AA12-129: the owner and unverified users see no interaction controls on the puzzle page', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    $owner = $this->actingAs($creator)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($owner)->toContain('هذه أحجيتك؛ لا يمكنك تقييمها')->not->toContain('role="radiogroup"')->not->toContain('احفظ في المفضلة');

    $unverified = $this->actingAs(e16User(['email_verified_at' => null]))->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($unverified)->toContain('يلزم بريد إلكتروني موثَّق')->not->toContain('role="radiogroup"');
});

test('AA12-130: the community and discover pages offer the private-list entries only to verified users', function () {
    $guest = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($guest)->not->toContain(route('following.index'));
    $user = $this->actingAs(e16User())->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($user)->toContain(route('following.index'))->toContain(route('favorites.index'))->toContain(route('collections.index'));
    $disc = $this->actingAs(e16User())->get(route('discover.index'))->assertOk()->getContent();
    expect($disc)->toContain(route('following.index'))->toContain(route('collections.index'));
});

test('AA12-131: a saved work is marked on the card for its viewer only', function () {
    $work = e29_work(null, ['title' => 'عمل للعلامة']);
    $viewer = e16User();
    e29_favorite($viewer, $work);

    expect($this->actingAs($viewer)->get(route('community.puzzles.index'))->getContent())->toContain('محفوظة');
    $other = $this->actingAs(e16User())->get(route('community.puzzles.index'))->getContent();
    expect(e29_section($other, 'أحجيات المجتمع'))->not->toContain('محفوظة');
});

test('AA13-132: the demo seeder is idempotent, uses only official services, keeps counters exact and touches no economy', function () {
    $this->seed(DemoEngagementSeeder::class);
    $snap = fn () => [CommunityPuzzleRating::query()->count(), CommunityPuzzleFavorite::query()->count(), CreatorFollow::query()->count(), UserCollection::query()->count(), \App\Models\UserCollectionItem::query()->count(), \App\Models\User::query()->count()];
    $first = $snap();

    $this->seed(DemoEngagementSeeder::class);

    expect($snap())->toBe($first)->and($first[0])->toBeGreaterThan(0)->and($first[1])->toBeGreaterThan(0)->and($first[2])->toBeGreaterThan(0)->and($first[3])->toBeGreaterThanOrEqual(2)
        ->and(\App\Models\XpTransaction::query()->count())->toBe(0)->and(\App\Models\GemTransaction::query()->count())->toBe(0)->and(\App\Models\PuzzleAttempt::query()->count())->toBe(0);
    expect(Artisan::call('community:reconcile-engagement', ['--dry-run' => true]))->toBe(0);
});

test('AA13-133: the engagement demo seeder refuses to run in production', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect(fn () => app(DemoEngagementSeeder::class)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CommunityPuzzleRating::query()->count())->toBe(0);
});

test('AA13-134: the engagement indexes and unique constraints exist (schema contract)', function () {
    $indexes = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

    expect($indexes('community_puzzle_ratings'))->toContain('community_ratings_user_work_unique')
        ->and($indexes('community_puzzle_favorites'))->toContain('community_favorites_user_work_unique')
        ->and($indexes('creator_follows'))->toContain('creator_follows_user_creator_unique')->toContain('creator_follows_fanout_index')
        ->and($indexes('user_collection_items'))->toContain('collection_items_collection_work_unique')
        ->and($indexes('creator_puzzles'))->toContain('creator_puzzles_rated_visible_index')
        ->and($indexes('creator_profiles'))->toContain('creator_profiles_followed_index');
});

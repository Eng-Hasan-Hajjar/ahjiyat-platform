<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA9-88: rating IDOR is denied: another user can neither read, overwrite nor delete someone else\'s rating', function () {
    $work = e29_work();
    [$a, $b] = [e29_player_who_played($work), e29_player_who_played($work)];
    e29_rate($a, $work, 5);

    $this->actingAs($b)->delete(route('community.puzzles.rating.destroy', $work->public_id), ['user_id' => $a->id, 'id' => CommunityPuzzleRating::query()->value('id')])->assertRedirect();
    $this->actingAs($b)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 1, 'user_id' => $a->id])->assertRedirect();

    $rows = CommunityPuzzleRating::query()->orderBy('user_id')->get(['user_id', 'rating'])->mapWithKeys(fn ($r) => [(int) $r->user_id => (int) $r->rating])->all();
    expect($rows)->toBe([$a->id => 5, $b->id => 1])->and(e29_counters($work)['sum'])->toBe(6);
});

test('AA9-89: favorite IDOR is denied (a user only ever touches their own row)', function () {
    $work = e29_work();
    [$a, $b] = [e16User(), e16User()];
    e29_favorite($a, $work);

    $this->actingAs($b)->delete(route('community.puzzles.favorite.destroy', $work->public_id), ['user_id' => $a->id])->assertRedirect();
    $this->actingAs($b)->post(route('community.puzzles.favorite.store', $work->public_id), ['user_id' => $a->id]);

    expect(CommunityPuzzleFavorite::query()->where('user_id', $a->id)->count())->toBe(1)->and(CommunityPuzzleFavorite::query()->where('user_id', $b->id)->count())->toBe(1);
});

test('AA9-90: follow ownership is safe (a client-supplied user_id is ignored)', function () {
    $creator = e28_creator();
    [$a, $b] = [e16User(), e16User()];

    $this->actingAs($b)->post(route('creators.follow', e29_profile($creator)->slug), ['user_id' => $a->id, 'follower_id' => $a->id])->assertRedirect();

    expect(CreatorFollow::query()->pluck('user_id')->all())->toBe([$b->id]);
    $this->actingAs($a)->delete(route('creators.unfollow', e29_profile($creator)->slug))->assertRedirect();
    expect(CreatorFollow::query()->count())->toBe(1);
});

test('AA9-91: collection IDOR is denied on every verb (including item removal)', function () {
    $owner = e16User();
    $intruder = e16User();
    $work = e29_work();
    $c = e29_collection($owner, 'لا تُمس');
    app(\App\Services\Engagement\CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($intruder)->delete(route('collections.items.destroy', [$c->public_id, $work->public_id]))->assertNotFound();
    $this->actingAs($intruder)->delete(route('collections.destroy', $c->public_id))->assertNotFound();
    $this->actingAs($intruder)->put(route('collections.update', $c->public_id), ['title' => 'مخترَق'])->assertNotFound();

    expect(UserCollection::query()->find($c->id)->title)->toBe('لا تُمس')->and(UserCollectionItem::query()->count())->toBe(1);
});

test('AA9-92: CSRF protects every mutating engagement route (419 without a token, nothing changes)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $c = e29_collection($user);
    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($user);
        $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 5])->assertStatus(419);
        $this->delete(route('community.puzzles.rating.destroy', $work->public_id))->assertStatus(419);
        $this->post(route('community.puzzles.favorite.store', $work->public_id))->assertStatus(419);
        $this->delete(route('community.puzzles.favorite.destroy', $work->public_id))->assertStatus(419);
        $this->post(route('creators.follow', e29_profile($creator)->slug))->assertStatus(419);
        $this->delete(route('creators.unfollow', e29_profile($creator)->slug))->assertStatus(419);
        $this->post(route('collections.store'), ['title' => 'بلا رمز'])->assertStatus(419);
        $this->put(route('collections.update', $c->public_id), ['title' => 'بلا رمز'])->assertStatus(419);
        $this->delete(route('collections.destroy', $c->public_id))->assertStatus(419);
        $this->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertStatus(419);
        $this->delete(route('collections.items.destroy', [$c->public_id, $work->public_id]))->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CommunityPuzzleRating::query()->count() + CommunityPuzzleFavorite::query()->count() + CreatorFollow::query()->count() + UserCollectionItem::query()->count())->toBe(0)
        ->and(UserCollection::query()->find($c->id)->title)->not->toBe('بلا رمز');
});

test('AA9-93: the named rate limiters throttle (rating, favorite, follow, collection actions) per user, not per IP', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e29_player_who_played($work);
    $other = e29_player_who_played($work);

    $this->actingAs($user);
    foreach (range(1, 20) as $i) { $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4]); }
    $this->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 4])->assertStatus(429);
    $this->actingAs($other)->put(route('community.puzzles.rating.update', $work->public_id), ['rating' => 3])->assertStatus(302);   // مستخدم آخر من نفس العنوان غير مخنوق

    $this->actingAs($user);
    foreach (range(1, 30) as $i) { $this->post(route('community.puzzles.favorite.store', $work->public_id)); }
    $this->post(route('community.puzzles.favorite.store', $work->public_id))->assertStatus(429);

    foreach (range(1, 15) as $i) { $this->post(route('creators.follow', e29_profile($creator)->slug)); }
    $this->post(route('creators.follow', e29_profile($creator)->slug))->assertStatus(429);

    foreach (range(1, 30) as $i) { $this->post(route('collections.store'), ['title' => 'مجموعة '.$i.' اختبار']); }
    $this->post(route('collections.store'), ['title' => 'الحادية والثلاثون'])->assertStatus(429);
    expect(UserCollection::query()->where('user_id', $user->id)->count())->toBeLessThanOrEqual((int) config('engagement.collections.max_per_user'));
});

test('AA9-94: there is no GET mutation: only read pages answer GET, mutations answer 405', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e16User();
    $c = e29_collection($user);
    $readActions = ['index', 'feed', 'create', 'show', 'edit'];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_contains((string) $route->getActionName(), 'Controllers\\Engagement\\')) {
            continue;
        }

        $checked++;
        $method = explode('@', (string) $route->getActionName())[1] ?? '';

        if (! in_array($method, $readActions, true)) {
            expect($route->methods())->not->toContain('GET')->and($route->methods())->not->toContain('HEAD');
        }
    }

    expect($checked)->toBeGreaterThan(15);
    $this->actingAs($user)->get('/community/puzzles/'.$work->public_id.'/favorite')->assertStatus(405);
    $this->actingAs($user)->get('/community/puzzles/'.$work->public_id.'/rating?rating=5')->assertStatus(405);
    $this->actingAs($user)->get('/creators/'.e29_profile($creator)->slug.'/follow')->assertStatus(405);
    $this->actingAs($user)->get('/community/puzzles/'.$work->public_id.'/collections')->assertStatus(405);
    expect(CommunityPuzzleFavorite::query()->count() + CreatorFollow::query()->count())->toBe(0);
});

test('AA9-95: a collection title or description with markup is escaped on every page (defence in depth)', function () {
    $user = e16User();
    $c = new UserCollection;
    $c->forceFill(['user_id' => $user->id, 'title' => '<script>alert("xss")</script>', 'description' => '<img src=x onerror=alert(1)>'])->save();
    $work = e29_work();
    app(\App\Services\Engagement\CollectionService::class)->add($user, $c->public_id, $work->fresh());

    foreach ([route('collections.index'), route('collections.show', $c->public_id), route('collections.edit', $c->public_id), route('community.puzzles.show', $work->public_id)] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>alert("xss")</script>')->and($html)->not->toContain('<img src=x onerror=alert(1)>');
    }

    expect($this->actingAs($user)->get(route('collections.index'))->getContent())->toContain('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
});

test('AA9-96: mass assignment cannot set any counter or engagement row', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    expect(fn () => CreatorPuzzle::query()->find($work->id)->fill(['ratings_count' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => CreatorPuzzle::query()->find($work->id)->fill(['ratings_sum' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => CreatorPuzzle::query()->find($work->id)->fill(['favorites_count' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => e29_profile($creator)->fill(['followers_count' => 99]))->toThrow(MassAssignmentException::class);
    expect(fn () => CommunityPuzzleRating::query()->create(['user_id' => 1, 'creator_puzzle_id' => $work->id, 'rating' => 5]))->toThrow(MassAssignmentException::class);
    expect(fn () => UserCollection::query()->create(['user_id' => 1, 'title' => 'x']))->toThrow(MassAssignmentException::class);
    expect(e29_counters($work))->toBe(['count' => 0, 'sum' => 0, 'favorites' => 0]);
});

test('AA9-97: the client cannot set the average or the rating aggregate through the request', function () {
    $work = e29_work();
    $user = e29_player_who_played($work);

    $this->actingAs($user)->put(route('community.puzzles.rating.update', $work->public_id), [
        'rating' => 4, 'average' => 5, 'average_rating' => 5, 'ratings_count' => 500, 'ratings_sum' => 2500, 'favorites_count' => 99,
    ])->assertRedirect();

    expect(e29_counters($work))->toBe(['count' => 1, 'sum' => 4, 'favorites' => 0])->and($work->fresh()->averageRating())->toBe(4.0);
});

test('AA9-98: the client cannot set followers_count or the favorites counter through the request', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', e29_profile($creator)->slug), ['followers_count' => 999])->assertRedirect();
    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id), ['favorites_count' => 999])->assertRedirect();

    expect(e29_followers($creator))->toBe(1)->and(e29_counters($work)['favorites'])->toBe(1);
});

test('AA9-99: unverified, guest and frozen users are blocked on the private lists too', function () {
    $unverified = e16User(['email_verified_at' => null]);
    $frozen = e16User();
    $frozen->forceFill(['is_frozen' => true])->save();

    $pages = ['favorites.index', 'following.index', 'collections.index', 'collections.create'];

    foreach ($pages as $name) {   // الضيف أولًا (actingAs يبقى فعّالًا بعد استدعائه)
        $this->get(route($name))->assertRedirect(route('login'));
    }

    foreach ($pages as $name) {
        $this->actingAs($unverified)->get(route($name))->assertRedirect(route('verification.notice'));
    }

    foreach ($pages as $name) {
        $this->actingAs($frozen->fresh())->get(route($name))->assertRedirect();
    }
});

test('AA9-100: no rating can be reported and no rating row is ever exposed publicly', function () {
    $work = e29_work();
    [$user] = e29_ratings($work, [5, 4, 3, 5, 4]);

    expect(array_keys(\App\Models\CommunityPuzzleReport::REASONS))->not->toContain('rating');
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName())->filter()->all();
    expect(collect($names)->filter(fn ($n) => str_contains($n, 'rating') && str_contains($n, 'report'))->all())->toBe([]);

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($html)->not->toContain($user->email)->and($html)->not->toContain((string) $user->name);
});

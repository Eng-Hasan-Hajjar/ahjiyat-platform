<?php

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Services\Engagement\CollectionService;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA6-59: a verified user creates a private collection with a ULID public id', function () {
    $user = e16User();

    $this->actingAs($user)->post(route('collections.store'), ['title' => 'ألغاز المساء', 'description' => 'مجموعة هادئة.'])->assertRedirect();

    $c = UserCollection::query()->where('user_id', $user->id)->firstOrFail();
    expect($c->title)->toBe('ألغاز المساء')->and(strlen($c->public_id))->toBe(26)->and(\Illuminate\Support\Str::isUlid($c->public_id))->toBeTrue();
});

test('AA6-60: the title is validated (required, min, max)', function () {
    $user = e16User();

    foreach (['', ' ', 'أ', str_repeat('ب', 61)] as $bad) {
        $this->actingAs($user)->post(route('collections.store'), ['title' => $bad])->assertSessionHasErrors('title');
    }

    $this->actingAs($user)->post(route('collections.store'), ['title' => 'عنوان صالح', 'description' => str_repeat('و', 301)])->assertSessionHasErrors('description');
    expect(UserCollection::query()->count())->toBe(0);
});

test('AA6-61: markup in the title or description is rejected', function () {
    $user = e16User();

    $this->actingAs($user)->post(route('collections.store'), ['title' => '<script>alert(1)</script>'])->assertSessionHasErrors('title');
    $this->actingAs($user)->post(route('collections.store'), ['title' => 'عنوان', 'description' => '<img src=x onerror=alert(1)>'])->assertSessionHasErrors('description');
    expect(UserCollection::query()->count())->toBe(0);
});

test('AA6-62: a collection is private (guests are sent to login)', function () {
    $owner = e16User();
    $c = e29_collection($owner);

    $this->get(route('collections.index'))->assertRedirect(route('login'));
    $this->get(route('collections.show', $c->public_id))->assertRedirect(route('login'));
    $this->get(route('collections.edit', $c->public_id))->assertRedirect(route('login'));
});

test('AA6-63: the owner can view the collection and its items', function () {
    $owner = e16User();
    $c = e29_collection($owner, 'مجموعة العرض');
    $work = e29_work(null, ['title' => 'عنصر داخل المجموعة']);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($owner)->get(route('collections.index'))->assertOk()->assertSee('مجموعة العرض');
    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('عنصر داخل المجموعة')->assertSee('إزالة من المجموعة');
});

test('AA6-64: another user is denied every collection route (404, nothing changes)', function () {
    $owner = e16User();
    $other = e16User();
    $c = e29_collection($owner, 'خاصة بصاحبها');
    $work = e29_work();

    $this->actingAs($other)->get(route('collections.show', $c->public_id))->assertNotFound();
    $this->actingAs($other)->get(route('collections.edit', $c->public_id))->assertNotFound();
    $this->actingAs($other)->put(route('collections.update', $c->public_id), ['title' => 'اختطاف العنوان'])->assertNotFound();
    $this->actingAs($other)->delete(route('collections.destroy', $c->public_id))->assertNotFound();
    $this->actingAs($other)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertNotFound();

    expect(UserCollection::query()->find($c->id)->title)->toBe('خاصة بصاحبها')->and(UserCollectionItem::query()->count())->toBe(0);
});

test('AA6-65: the owner updates title and description', function () {
    $owner = e16User();
    $c = e29_collection($owner);

    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'عنوان جديد', 'description' => 'وصف جديد'])->assertRedirect(route('collections.show', $c->public_id));

    expect($c->fresh()->title)->toBe('عنوان جديد')->and($c->fresh()->description)->toBe('وصف جديد')->and($c->fresh()->public_id)->toBe($c->public_id);
});

test('AA6-66: deleting a collection deletes memberships only, never works, favorites or ratings', function () {
    $owner = e29_player_who_played($work = e29_work());
    e29_rate($owner, $work, 4);
    e29_favorite($owner, $work);
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($owner)->delete(route('collections.destroy', $c->public_id))->assertRedirect(route('collections.index'));

    expect(UserCollection::query()->count())->toBe(0)->and(UserCollectionItem::query()->count())->toBe(0)
        ->and(CreatorPuzzleExists($work))->toBeTrue()->and(CommunityPuzzleFavorite::query()->count())->toBe(1)->and(CommunityPuzzleRating::query()->count())->toBe(1)
        ->and(e29_counters($work))->toBe(['count' => 1, 'sum' => 4, 'favorites' => 1]);
});

function CreatorPuzzleExists($work): bool
{
    return \App\Models\CreatorPuzzle::query()->whereKey($work->id)->exists();
}

test('AA6-67: adding a puzzle to an existing collection or to a new one from the puzzle page', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner, 'قائمة');

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertRedirect()->assertSessionHas('engagement_status');
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['new_title' => 'قائمة سريعة جديدة'])->assertRedirect();

    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(1)
        ->and(UserCollection::query()->where('user_id', $owner->id)->where('title', 'قائمة سريعة جديدة')->first()->items()->count())->toBe(1);
});

test('AA6-68: a duplicate item is prevented (service and database)', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id]);
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id])->assertRedirect();

    expect(UserCollectionItem::query()->count())->toBe(1);
    expect(fn () => (new UserCollectionItem)->forceFill(['user_collection_id' => $c->id, 'creator_puzzle_id' => $work->id])->save())->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA6-69: removing a puzzle from a collection works', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    $this->actingAs($owner)->delete(route('collections.items.destroy', [$c->public_id, $work->public_id]))->assertRedirect(route('collections.show', $c->public_id));

    expect(UserCollectionItem::query()->count())->toBe(0);
});

test('AA6-70: the per-collection item cap is enforced', function () {
    config(['engagement.collections.max_items' => 2]);
    $owner = e16User();
    $c = e29_collection($owner);
    $creator = e28_creator();
    [$a, $b, $d] = [e29_work($creator), e29_work($creator), e29_work($creator)];
    $svc = app(CollectionService::class);

    $svc->add($owner, $c->public_id, $a->fresh());
    $svc->add($owner, $c->public_id, $b->fresh());
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $d->public_id), ['collection' => $c->public_id])->assertSessionHas('error');

    expect(UserCollectionItem::query()->count())->toBe(2);
});

test('AA6-71: the per-user collection cap is enforced', function () {
    config(['engagement.collections.max_per_user' => 2]);
    $owner = e16User();

    e29_collection($owner, 'الأولى');
    e29_collection($owner, 'الثانية');
    $this->actingAs($owner)->post(route('collections.store'), ['title' => 'الثالثة المرفوضة'])->assertSessionHas('error');

    expect(UserCollection::query()->where('user_id', $owner->id)->count())->toBe(2);
});

test('AA6-72: hiding a work preserves the membership; restoring brings it back', function () {
    $owner = e16User();
    $work = e29_work(null, ['title' => 'عنوان عضوية مخفية']);
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());

    e29_hide($work);
    $html = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect(UserCollectionItem::query()->count())->toBe(1)->and($html)->toContain('هذا المحتوى غير متاح حاليًا')->and($html)->not->toContain('عنوان عضوية مخفية')->and($html)->not->toContain('مخالفة تجريبية');

    e29_unhide($work);
    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('عنوان عضوية مخفية')->assertDontSee('هذا المحتوى غير متاح حاليًا');
});

test('AA6-73: a hidden item cannot be played through its collection', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);
    app(CollectionService::class)->add($owner, $c->public_id, $work->fresh());
    e29_hide($work);

    $html = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect(e29_section($html, 'أحجيات المجموعة'))->not->toContain(route('community.puzzles.show', $work->public_id));
    $this->actingAs($owner)->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs($owner)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
    $other = e16User();
    expect(fn () => app(CollectionService::class)->add($other, e29_collection($other)->public_id, $work->fresh()))->toThrow(\App\Services\Engagement\EngagementException::class);
});

test('AA6-74: no public collection route exists and every collection route sits behind auth', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'collections') || str_contains($r->uri(), '/collections'));

    expect($routes->count())->toBeGreaterThan(5);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('auth');
    }

    $owner = e16User();
    $c = e29_collection($owner, 'اسم فريد لمجموعة خاصة');
    $this->get('/collections/'.$c->public_id)->assertRedirect(route('login'));
    $this->get('/c/'.$c->public_id)->assertNotFound();
    $this->get(route('creators.show', 'x'.$c->public_id))->assertNotFound();
});

test('AA6-75: the cap/quick-create flow cannot leave an empty collection behind when the add fails', function () {
    $owner = e16User();
    $work = e29_work();
    e29_hide($work);

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['new_title' => 'لا يجب أن تبقى'])->assertNotFound();
    expect(UserCollection::query()->count())->toBe(0);
});

test('AA6-76: the add form needs exactly one of an existing collection or a new title', function () {
    $owner = e16User();
    $work = e29_work();
    $c = e29_collection($owner);

    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), [])->assertSessionHasErrors('collection');
    $this->actingAs($owner)->post(route('community.puzzles.collections.add', $work->public_id), ['collection' => $c->public_id, 'new_title' => 'كلاهما'])->assertSessionHasErrors('collection');
    expect(UserCollectionItem::query()->count())->toBe(0);
});

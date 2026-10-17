<?php

use App\Models\CommunityPuzzleFavorite;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

test('AA2-21: a guest cannot favorite', function () {
    $work = e29_work();

    $this->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect(route('login'));
    $this->get(route('favorites.index'))->assertRedirect(route('login'));
    expect(CommunityPuzzleFavorite::query()->count())->toBe(0);
});

test('AA2-22: a verified user can favorite a visible community puzzle', function () {
    $work = e29_work();
    $user = e16User();

    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect()->assertSessionHas('engagement_status');

    expect(CommunityPuzzleFavorite::query()->where('user_id', $user->id)->where('creator_puzzle_id', $work->id)->count())->toBe(1)
        ->and(e29_counters($work)['favorites'])->toBe(1);
});

test('AA2-23: a duplicate favorite is safe and idempotent', function () {
    $work = e29_work();
    $user = e16User();

    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id));
    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect();

    expect(CommunityPuzzleFavorite::query()->count())->toBe(1)->and(e29_counters($work)['favorites'])->toBe(1);
    expect(fn () => (new CommunityPuzzleFavorite)->forceFill(['user_id' => $user->id, 'creator_puzzle_id' => $work->id])->save())->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA2-24: unfavorite removes the row and the count (idempotent)', function () {
    $work = e29_work();
    $user = e16User();
    e29_favorite($user, $work);

    $this->actingAs($user)->delete(route('community.puzzles.favorite.destroy', $work->public_id))->assertRedirect();
    $this->actingAs($user)->delete(route('community.puzzles.favorite.destroy', $work->public_id))->assertRedirect();

    expect(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(e29_counters($work)['favorites'])->toBe(0);
});

test('AA2-25: the public favorites count equals the number of saves and shows no identities', function () {
    $work = e29_work();
    $users = [e16User(['name' => 'حافظ سري واحد']), e16User(['name' => 'حافظ سري اثنان']), e16User(['name' => 'حافظ سري ثلاثة'])];

    foreach ($users as $u) {
        e29_favorite($u, $work);
    }

    $html = $this->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect(e29_counters($work)['favorites'])->toBe(3)->and($html)->toContain('3 حفظوها')
        ->and($html)->not->toContain('حافظ سري')->and($html)->not->toContain($users[0]->email);
});

test('AA2-26: the creator cannot favorite their own work (conservative rule)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);

    $this->actingAs($creator)->post(route('community.puzzles.favorite.store', $work->public_id))->assertSessionHas('error');

    expect(CommunityPuzzleFavorite::query()->count())->toBe(0)->and(e29_counters($work)['favorites'])->toBe(0);
});

test('AA2-27: the favorites page is private to its owner (newest saved first, empty state with CTA)', function () {
    $creator = e28_creator();
    $first = e29_work($creator, ['title' => 'أحجية محفوظة أولًا']);
    $second = e29_work($creator, ['title' => 'أحجية محفوظة ثانيًا']);
    $user = e16User();

    $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertSee('لم تحفظ أي أحجية بعد')->assertSee('تصفّح أحجيات المجتمع');

    e29_favorite($user, $first);
    e29_favorite($user, $second);

    $html = $this->actingAs($user)->get(route('favorites.index'))->assertOk()->getContent();
    expect(strpos($html, 'أحجية محفوظة ثانيًا'))->toBeLessThan(strpos($html, 'أحجية محفوظة أولًا'));
});

test('AA2-28: another user can never see someone else\'s favorites list', function () {
    $work = e29_work(null, ['title' => 'عنوان خاص بمفضلة أ']);
    $a = e16User();
    $b = e16User();
    e29_favorite($a, $work);

    $this->actingAs($b)->get(route('favorites.index'))->assertOk()->assertDontSee('عنوان خاص بمفضلة أ');
    $this->actingAs($b)->get(route('favorites.index', ['user' => $a->id, 'user_id' => $a->id]))->assertOk()->assertDontSee('عنوان خاص بمفضلة أ');

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_contains($u, 'favorites'))->values()->all();
    expect($uris)->toBe(['favorites']);
});

test('AA2-29: a hidden favorite is not playable and shows only a neutral placeholder', function () {
    $work = e29_work(null, ['title' => 'عنوان سيُخفى إشرافيًا']);
    $user = e16User();
    e29_favorite($user, $work);
    e29_hide($work);

    $html = $this->actingAs($user)->get(route('favorites.index'))->assertOk()->getContent();
    expect($html)->toContain('هذا المحتوى غير متاح حاليًا')->and($html)->not->toContain('عنوان سيُخفى إشرافيًا')->and($html)->not->toContain('مخالفة تجريبية');

    $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    $this->actingAs($user)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertNotFound();
});

test('AA2-30: hiding keeps the favorite row and the public counter', function () {
    $work = e29_work();
    $user = e16User();
    e29_favorite($user, $work);

    e29_hide($work);

    expect(CommunityPuzzleFavorite::query()->where('user_id', $user->id)->count())->toBe(1)->and(e29_counters($work)['favorites'])->toBe(1);
});

test('AA2-31: restoring a hidden work brings it back into the favorites list', function () {
    $work = e29_work(null, ['title' => 'تعود بعد الاستعادة']);
    $user = e16User();
    e29_favorite($user, $work);
    e29_hide($work);
    $this->actingAs($user)->get(route('favorites.index'))->assertDontSee('تعود بعد الاستعادة');

    e29_unhide($work);

    $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertSee('تعود بعد الاستعادة')->assertDontSee('هذا المحتوى غير متاح حاليًا');
});

test('AA2-32: a favorite grants zero rewards, XP, quests or streak', function () {
    $work = e29_work();
    $user = e16User();
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();   // يثبّت صف التقدّم الكسول قبل اللقطة
    $before = e28_world($user);

    $this->actingAs($user)->post(route('community.puzzles.favorite.store', $work->public_id))->assertRedirect();
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();

    expect(e28_world($user))->toBe($before);
});

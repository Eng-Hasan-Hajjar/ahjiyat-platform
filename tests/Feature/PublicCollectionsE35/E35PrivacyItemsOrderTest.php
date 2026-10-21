<?php

use App\Models\User;
use App\Models\UserCollectionItem;
use App\Services\Collections\CollectionOrderService;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementException;
use App\Services\Social\BlockService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e35_seed());

/* ===================== AM2. PRIVACY / BLOCKS ===================== */

test('E35-013: the profile-privacy decision is respected (public needs a public profile; unlisted shows a neutral label to those who cannot see the profile)', function () {
    $owner = e16User(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    $c = e35_collection($owner, e35_works(2));

    // «عامة» تشترط ملفًا عامًا
    expect(fn () => app(CollectionVisibilityService::class)->publish($owner, $c->public_id))->toThrow(EngagementException::class);
    expect($c->fresh()->visibility)->toBe('private');

    // «غير مدرجة» مسموحة، لكن اسم صاحب الملف الخاص لا يظهر (نسبة محايدة)
    app(CollectionVisibilityService::class)->makeUnlisted($owner, $c->public_id);
    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($html)->not->toContain($owner->name)->and($html)->toContain('أحد أعضاء المنصة');

    // مجموعة عامة، ثم يجعل المالك ملفه خاصًا: تخرج من البحث والاكتشاف والملف فورًا ويبقى الرابط بنسبة محايدة
    $owner2 = e16User();
    $pub = e35_public($owner2, 3, 'عامة ثم ملف خاص');
    $owner2->forceFill(['profile_visibility' => User::VISIBILITY_PRIVATE])->save();
    Cache::flush();

    $this->get(route('search.index', ['q' => 'ملف خاص', 'type' => 'collections']))->assertOk()->assertDontSee('عامة ثم ملف خاص');
    $this->get(route('discover.index'))->assertDontSee('عامة ثم ملف خاص');
    $page = $this->get(route('collections.public.show', $pub->public_id))->assertOk()->getContent();
    expect($page)->not->toContain($owner2->name);
});

test('E35-014: block semantics are respected in both directions (public page, Search, Discover, save)', function () {
    $owner = e16User();
    $viewer = e16User();
    $c = e35_public($owner, 3, 'مجموعة بين محظورين');

    app(BlockService::class)->block($owner, $viewer);
    Cache::flush();

    $this->actingAs($viewer)->get(route('collections.public.show', $c->public_id))->assertNotFound();
    $this->actingAs($viewer)->get(route('search.index', ['q' => 'محظورين', 'type' => 'collections']))->assertDontSee('مجموعة بين محظورين');
    $this->actingAs($viewer)->get(route('discover.index'))->assertDontSee('مجموعة بين محظورين');
    $this->actingAs($viewer)->post(route('collections.public.save', $c->public_id))->assertNotFound();
    // طرف ثالث غير محظور يراها
    $this->actingAs(e16User())->get(route('collections.public.show', $c->public_id))->assertOk();

    // الاتجاه المعاكس (المشاهد حظر الأمين)
    $other = e16User();
    app(BlockService::class)->block($other, $owner);
    $this->actingAs($other)->get(route('collections.public.show', $c->public_id))->assertNotFound();
});

test('E35-015: the owner private data is absent from the public page (email, internal ids, other private collections)', function () {
    $owner = e16User(['email' => 'owner-secret@example.test']);
    $c = e35_public($owner, 2, 'عامة نظيفة');
    e35_collection($owner, e35_works(1), 'private', 'خاصة لا تظهر أبدًا');

    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($html)->not->toContain('owner-secret@example.test')
        ->and($html)->not->toContain('خاصة لا تظهر أبدًا')
        ->and($html)->not->toContain('user_id')
        ->and($html)->not->toContain('moderation_reason')
        ->and($html)->not->toContain('password');
});

test('E35-016: no internal numeric user/collection id is needed or exposed publicly (ULID urls only)', function () {
    $owner = e16User();
    $c = e35_public($owner, 2, 'بدون معرفات داخلية');

    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect(\Illuminate\Support\Str::isUlid($c->public_id))->toBeTrue()
        ->and($html)->toContain(route('players.show', $owner))
        ->and($html)->not->toContain('/players/'.$owner->id.'"')
        ->and($html)->not->toContain('/curated/'.$c->id.'"');
    // معرّف رقمي بدل ULID لا يحلّ المسار
    $this->get('/curated/'.$c->id)->assertNotFound();
});

/* ===================== AM3. ITEMS ===================== */

test('E35-017: a visible work can be added to a collection', function () {
    $owner = e16User();
    [$work] = e35_works(1);
    $c = e29_collection($owner);

    expect(app(CollectionService::class)->add($owner, $c->public_id, $work)['changed'])->toBeTrue();
    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(1);
});

test('E35-018: a hidden work cannot be newly added', function () {
    $owner = e16User();
    [$work] = e35_works(1);
    e29_hide($work);
    $c = e29_collection($owner);

    expect(fn () => app(CollectionService::class)->add($owner, $c->public_id, $work->fresh()))->toThrow(EngagementException::class);
    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(0);
});

test('E35-019: an existing membership of a work that later becomes hidden is preserved', function () {
    $owner = e16User();
    [$work] = e35_works(1);
    $c = e35_collection($owner, [$work]);

    e29_hide($work);

    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(1);
});

test('E35-020: a hidden work is not leaked on the public page (title, count); the owner sees a neutral placeholder', function () {
    $owner = e16User();
    $creator = e28_creator();
    $visible = e29_work($creator, ['title' => 'عمل ظاهر للجميع'])->fresh();
    $secret = e29_work($creator, ['title' => 'عمل سيُخفى سرًّا'])->fresh();
    $c = e35_collection($owner, [$visible, $secret], 'public', 'مجموعة بعمل مخفي');

    e29_hide($secret);

    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($html)->toContain('عمل ظاهر للجميع')->and($html)->not->toContain('عمل سيُخفى سرًّا');
    $header = preg_match('/data-collection-count>\s*([^<]+)</u', $html, $m) === 1 ? trim($m[1]) : '';
    expect($header)->toBe('1 أحجية');

    $own = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect($own)->not->toContain('عمل سيُخفى سرًّا')->and($own)->toContain('إزالة من المجموعة');
});

test('E35-021: a restored work reappears in the public collection', function () {
    $owner = e16User();
    $creator = e28_creator();
    $a = e29_work($creator, ['title' => 'أول ظاهر'])->fresh();
    $b = e29_work($creator, ['title' => 'ثانٍ سيعود'])->fresh();
    $c = e35_collection($owner, [$a, $b], 'public');

    e29_hide($b);
    $this->get(route('collections.public.show', $c->public_id))->assertDontSee('ثانٍ سيعود');

    e29_unhide($b);
    $this->get(route('collections.public.show', $c->public_id))->assertSee('ثانٍ سيعود');
});

test('E35-022: a duplicate item is prevented', function () {
    $owner = e16User();
    [$work] = e35_works(1);
    $c = e35_collection($owner, [$work]);

    expect(app(CollectionService::class)->add($owner, $c->public_id, $work->fresh())['changed'])->toBeFalse();
    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(1);
});

test('E35-023: the item cap is enforced', function () {
    config(['engagement.collections.max_items' => 2]);
    $owner = e16User();
    $works = e35_works(3);
    $c = e35_collection($owner, array_slice($works, 0, 2));

    expect(fn () => app(CollectionService::class)->add($owner, $c->public_id, $works[2]))->toThrow(EngagementException::class);
    expect(UserCollectionItem::query()->where('user_collection_id', $c->id)->count())->toBe(2);
});

/* ===================== AM4. ORDERING ===================== */

test('E35-024: the initial order is deterministic (insertion order, positions 1..n)', function () {
    $owner = e16User();
    $works = e35_works(4);
    $c = e35_collection($owner, $works);

    expect(e35_order($c))->toBe(array_map(fn ($w) => $w->public_id, $works));
    expect(DB::table('user_collection_items')->where('user_collection_id', $c->id)->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all())->toBe([1, 2, 3, 4]);
    // وعرض المالك بالترتيب نفسه
    $html = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    $pos = array_map(fn ($w) => strpos($html, $w->public_id), $works);
    expect($pos)->toBe(collect($pos)->sort()->values()->all());
});

test('E35-025: the owner reorders (full list and single step)', function () {
    $owner = e16User();
    $works = e35_works(3);
    $c = e35_collection($owner, $works);
    $ids = array_map(fn ($w) => $w->public_id, $works);

    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['order' => [$ids[2], $ids[0], $ids[1]]])->assertOk()->assertJson(['ok' => true, 'changed' => true]);
    expect(e35_order($c))->toBe([$ids[2], $ids[0], $ids[1]]);

    $this->actingAs($owner)->patch(route('collections.reorder', $c->public_id), ['work' => $ids[1], 'direction' => 'up'])->assertRedirect();
    expect(e35_order($c))->toBe([$ids[2], $ids[1], $ids[0]]);

    // الحدّ: نقل الأول لأعلى لا يغيّر شيئًا
    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['work' => $ids[2], 'direction' => 'up'])->assertOk()->assertJson(['changed' => false]);
});

test('E35-026: duplicate ids in a reorder request are rejected without writing', function () {
    $owner = e16User();
    $works = e35_works(3);
    $c = e35_collection($owner, $works);
    $ids = array_map(fn ($w) => $w->public_id, $works);

    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['order' => [$ids[0], $ids[0], $ids[1]]])->assertStatus(422);
    expect(e35_order($c))->toBe($ids);
});

test('E35-027: ids that are not in the collection (missing/malformed) are rejected', function () {
    $owner = e16User();
    $works = e35_works(2);
    $c = e35_collection($owner, $works);
    $ids = array_map(fn ($w) => $w->public_id, $works);
    $ghost = (string) \Illuminate\Support\Str::ulid();

    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['order' => [$ids[1], $ghost]])->assertStatus(422);
    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['order' => [$ids[1], 'not-a-ulid']])->assertStatus(422);
    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['work' => $ghost, 'direction' => 'up'])->assertStatus(422);
    expect(e35_order($c))->toBe($ids);
});

test('E35-028: a foreign item (a work only in someone else\'s collection) is rejected', function () {
    $owner = e16User();
    $other = e16User();
    $mine = e35_works(2);
    $theirs = e35_works(1)[0];
    $c = e35_collection($owner, $mine);
    e35_collection($other, [$theirs], 'private', 'لغيري');
    $ids = array_map(fn ($w) => $w->public_id, $mine);

    $this->actingAs($owner)->patchJson(route('collections.reorder', $c->public_id), ['order' => [$theirs->public_id, $ids[0]]])->assertStatus(422);
    expect(e35_order($c))->toBe($ids);
});

test('E35-029: reordering another user\'s collection is denied (404) and changes nothing', function () {
    $owner = e16User();
    $intruder = e16User();
    $works = e35_works(2);
    $c = e35_collection($owner, $works, 'public');
    $ids = array_map(fn ($w) => $w->public_id, $works);

    $this->actingAs($intruder)->patchJson(route('collections.reorder', $c->public_id), ['order' => array_reverse($ids)])->assertNotFound();
    $this->actingAs($intruder)->patch(route('collections.reorder', $c->public_id), ['work' => $ids[0], 'direction' => 'down'])->assertNotFound();
    expect(e35_order($c))->toBe($ids);
});

test('E35-030: back-to-back / stale reorders keep positions a consistent permutation (also with an add in between)', function () {
    $owner = e16User();
    $works = e35_works(4);
    $c = e35_collection($owner, array_slice($works, 0, 3));
    $ids = array_map(fn ($w) => $w->public_id, array_slice($works, 0, 3));
    $order = app(CollectionOrderService::class);

    $order->reorder($owner, $c->public_id, [$ids[2], $ids[1], $ids[0]]);
    // طلب قديم (لقطة قبل الإضافة) ثم إضافة ثم طلب قديم ثانٍ: لا موضع مكرر ولا صف ضائع
    app(CollectionService::class)->add($owner, $c->public_id, $works[3]);
    $order->reorder($owner, $c->public_id, [$ids[0], $ids[1]]);
    $order->move($owner, $c->public_id, $works[3]->public_id, 'up');

    $positions = DB::table('user_collection_items')->where('user_collection_id', $c->id)->pluck('position')->map(fn ($p) => (int) $p)->sort()->values()->all();
    expect($positions)->toBe([1, 2, 3, 4])->and(count(e35_order($c)))->toBe(4)->and(count(array_unique(e35_order($c))))->toBe(4);
});

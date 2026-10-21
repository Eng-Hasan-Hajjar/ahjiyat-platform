<?php

use App\Models\UserCollection;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Engagement\EngagementException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e35_seed());

test('E35-001: an existing (pre-E35) collection row defaults to private', function () {
    $owner = e16User();
    DB::table('user_collections')->insert(['user_id' => $owner->id, 'public_id' => (string) \Illuminate\Support\Str::ulid(), 'title' => 'مجموعة قديمة', 'created_at' => now(), 'updated_at' => now()]);

    $row = UserCollection::query()->where('user_id', $owner->id)->firstOrFail();
    expect($row->visibility)->toBe('private')->and((int) $row->saves_count)->toBe(0)->and((bool) $row->is_moderation_hidden)->toBeFalse();
    // وكذلك المجموعات الجديدة من الخدمة
    expect(e29_collection($owner, 'جديدة')->visibility)->toBe('private');
});

test('E35-002: the owner views a private collection on the owner route', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(1), 'private', 'خاصة بي');

    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('خاصة بي')->assertSee('ظهور المجموعة');
});

test('E35-003: a non-owner and a guest cannot read a private collection (404 on the public URL, 404 on the owner route)', function () {
    $owner = e16User();
    $other = e16User();
    $c = e35_collection($owner, e35_works(1), 'private', 'سرّ المالك');

    $this->actingAs($other)->get(route('collections.show', $c->public_id))->assertNotFound();
    $this->actingAs($other)->get(route('collections.public.show', $c->public_id))->assertNotFound();
    auth()->logout();
    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();
    // حتى المالك لا يرى الخاصة عبر الرابط العام (مساره collections.show)
    $this->actingAs($owner)->get(route('collections.public.show', $c->public_id))->assertNotFound();
});

test('E35-004: the owner publishes a collection as public (acknowledgement required)', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(2));

    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'public'])->assertSessionHasErrors('acknowledge');
    expect($c->fresh()->visibility)->toBe('private');

    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'public', 'acknowledge' => '1'])->assertRedirect(route('collections.show', $c->public_id));
    $fresh = $c->fresh();
    expect($fresh->visibility)->toBe('public')->and($fresh->first_published_at)->not->toBeNull();
});

test('E35-005: any viewer (even a guest) can open a public collection', function () {
    $owner = e16User();
    $c = e35_public($owner, 2, 'مجموعة للجميع');

    $this->get(route('collections.public.show', $c->public_id))->assertOk()->assertSee('مجموعة للجميع')->assertSee($owner->name);
    $this->actingAs(e16User())->get(route('collections.public.show', $c->public_id))->assertOk();
});

test('E35-006: an unlisted collection opens by direct URL', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(1), 'unlisted', 'بالرابط فقط');

    $this->get(route('collections.public.show', $c->public_id))->assertOk()->assertSee('بالرابط فقط');
    expect($c->visibility)->toBe('unlisted');
});

test('E35-007: an unlisted collection never appears in Search', function () {
    $owner = e16User();
    e35_collection($owner, e35_works(2), 'unlisted', 'غير مدرجة فريدة');
    e35_public($owner, 2, 'عامة فريدة الظهور');

    $html = $this->get(route('search.index', ['q' => 'فريدة', 'type' => 'collections']))->assertOk()->getContent();
    expect($html)->toContain('عامة فريدة الظهور')->and($html)->not->toContain('غير مدرجة فريدة');
});

test('E35-008: an unlisted collection never appears in Discover', function () {
    $owner = e16User();
    e35_collection($owner, e35_works(4), 'unlisted', 'مجموعة غير مدرجة مخفية عن الاكتشاف');
    Cache::flush();

    $this->get(route('discover.index'))->assertOk()->assertDontSee('مجموعة غير مدرجة مخفية عن الاكتشاف');
});

test('E35-009: a private collection never appears in Search', function () {
    $owner = e16User();
    e35_collection($owner, e35_works(2), 'private', 'خاصة سرية بالبحث');

    $this->actingAs($owner)->get(route('search.index', ['q' => 'سرية', 'type' => 'collections']))->assertOk()->assertDontSee('خاصة سرية بالبحث');
});

test('E35-010: a private collection never appears in Discover', function () {
    $owner = e16User();
    e35_collection($owner, e35_works(4), 'private', 'خاصة سرية بالاكتشاف');
    Cache::flush();

    $this->actingAs($owner)->get(route('discover.index'))->assertOk()->assertDontSee('خاصة سرية بالاكتشاف');
});

test('E35-011: switching public back to private removes public access immediately', function () {
    $owner = e16User();
    $c = e35_public($owner, 3, 'ثم أصبحت خاصة');

    $this->get(route('collections.public.show', $c->public_id))->assertOk();

    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'private'])->assertRedirect();
    auth()->logout();
    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();
    $this->get(route('search.index', ['q' => 'خاصة', 'type' => 'collections']))->assertDontSee('ثم أصبحت خاصة');
});

test('E35-012: a frozen user cannot publish (service guard reads the live state)', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(2));
    $owner->forceFill(['is_frozen' => true])->save();

    expect(fn () => app(CollectionVisibilityService::class)->publish($owner->fresh(), $c->public_id))->toThrow(EngagementException::class);
    expect(fn () => app(CollectionVisibilityService::class)->makeUnlisted($owner->fresh(), $c->public_id))->toThrow(EngagementException::class);
    expect($c->fresh()->visibility)->toBe('private');
    // الرجوع إلى «خاصة» مسموح دائمًا (تقليل التعرض)
    expect(app(CollectionVisibilityService::class)->makePrivate($owner->fresh(), $c->public_id)['changed'])->toBeFalse();
});

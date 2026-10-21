<?php

use App\Models\EditorialShelf;
use App\Models\EditorialShelfItem;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\Editorial\EditorialContentType;
use App\Services\Editorial\EditorialShelfResolver;
use App\Services\Editorial\EditorialShelfService;
use App\Services\Editorial\EditorialShelves;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../PublicCollectionsE35/_support.php';
require_once __DIR__.'/../CreatorSeriesE30/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

beforeEach(function () {
    e35_seed();
    config(['collections.shelves.cache_seconds' => 0]);
});

afterEach(fn () => Carbon::setTestNow());

/* ===================== AM10. EDITORIAL SHELVES ===================== */

test('E35-073: an administrator creates a draft shelf (service and admin pages)', function () {
    $admin = e35_admin();
    $shelf = app(EditorialShelfService::class)->create($admin, ['internal_key' => 'first-draft', 'title' => 'مسودة أولى', 'subtitle' => 'وصف قصير', 'placement' => 'home']);

    expect($shelf->status)->toBe('draft')->and(strlen($shelf->public_id))->toBe(26)->and((int) $shelf->created_by)->toBe($admin->id);
    $this->actingAs($admin)->get('/admin/editorial-shelves')->assertOk()->assertSee('مسودة أولى');
    $this->actingAs($admin)->get('/admin/editorial-shelves/create')->assertOk();
    // الاسم الداخلي فريد، والتنسيق مغلق
    expect(fn () => app(EditorialShelfService::class)->create($admin, ['internal_key' => 'first-draft', 'title' => 'مكرر', 'placement' => 'home']))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => app(EditorialShelfService::class)->create($admin, ['internal_key' => 'Bad Key!', 'title' => 'سيئ', 'placement' => 'home']))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => app(EditorialShelfService::class)->create($admin, ['internal_key' => 'good-key', 'title' => '<b>وسم</b>', 'placement' => 'home']))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => app(EditorialShelfService::class)->create($admin, ['internal_key' => 'good-key', 'title' => 'موضع غير مسموح', 'placement' => 'footer']))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('E35-074: users without editorial.manage / editorial.view are denied', function () {
    $c = e35_public(null, 3);
    $shelf = e35_shelf([e35_ref('public_collection', $c->id)]);

    foreach ([e16User(), e35_moderator()] as $who) {
        expect(fn () => app(EditorialShelfService::class)->create($who, ['internal_key' => 'nope-key', 'title' => 'ممنوع', 'placement' => 'home']))->toThrow(HttpException::class);
        expect(fn () => app(EditorialShelfService::class)->archive($who, $shelf))->toThrow(HttpException::class);
        $this->actingAs($who)->get('/admin/editorial-shelves')->assertForbidden();
    }

    expect($shelf->fresh()->status)->toBe('active');
});

test('E35-075: an active shelf renders on Discover with its title, subtitle and cards', function () {
    [$w1, $w2] = e35_works(2);
    e35_shelf([e35_ref('community_puzzle', $w1->id), e35_ref('community_puzzle', $w2->id)], 'picks', 'discover', ['title' => 'اختيارات المحررين', 'subtitle' => 'من فريق المنصة']);

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    $section = e29_section($html, 'اختيارات المحررين');
    expect($section)->not->toBe('')->and($section)->toContain('من فريق المنصة')->and($section)->toContain($w1->published_title)->and($section)->toContain($w2->published_title)->and($section)->toContain('data-discover-section="shelf-');
});

test('E35-076: a future (scheduled) shelf does not render until its start', function () {
    [$w] = e35_works(1);
    e35_shelf([e35_ref('community_puzzle', $w->id)], 'later', 'discover', ['title' => 'رف قادم', 'starts_at' => now()->addDays(3)]);

    $this->get(route('discover.index'))->assertOk()->assertDontSee('رف قادم');

    Carbon::setTestNow(now()->addDays(4));
    Cache::flush();
    $this->get(route('discover.index'))->assertOk()->assertSee('رف قادم');
});

test('E35-077: an expired shelf stops rendering at ends_at without any scheduled job', function () {
    [$w] = e35_works(1);
    e35_shelf([e35_ref('community_puzzle', $w->id)], 'short', 'discover', ['title' => 'رف ينتهي', 'ends_at' => now()->addDays(2)]);

    $this->get(route('discover.index'))->assertSee('رف ينتهي');

    Carbon::setTestNow(now()->addDays(3));
    Cache::flush();
    $this->get(route('discover.index'))->assertDontSee('رف ينتهي');
    // لا يُفعَّل رف انتهت نافذته
    $admin = e35_admin();
    $svc = app(EditorialShelfService::class);
    $expired = $svc->create($admin, ['internal_key' => 'already-over', 'title' => 'منتهٍ', 'placement' => 'home', 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]);
    [$w2] = e35_works(1);
    $svc->setItems($admin, $expired, [e35_ref('community_puzzle', $w2->id)]);
    expect(fn () => $svc->activate($admin, $expired->fresh()))->toThrow(InvalidArgumentException::class);
});

test('E35-078: every allowed content type resolves to a card (official puzzle, community puzzle, series, campaign, season, event, collection)', function () {
    $official = e27_puzzle('أحجية رسمية للرف', ['is_active' => true]);
    [$work] = e35_works(1);
    $series = e30s_published(e28_creator());
    $campaign = e27_campaign('حملة للرف');
    $season = e27_season('موسم للرف');
    $event = e17Event(['title' => 'منافسة للرف']);
    $collection = e35_public(null, 3, 'مجموعة للرف');

    $refs = [
        ['official_puzzle', $official->id], ['community_puzzle', $work->id], ['creator_series', $series->id], ['campaign', $campaign->id],
        ['season', $season->id], ['competitive_event', $event->id], ['public_collection', $collection->id],
    ];
    $cards = app(EditorialShelfResolver::class)->resolve($refs, null);

    foreach ($refs as [$type, $id]) {
        expect(isset($cards[$type.':'.$id]))->toBeTrue($type);
    }
    expect(array_map(fn ($c) => $c->title, array_values($cards)))->toContain('أحجية رسمية للرف')->toContain('حملة للرف')->toContain('منافسة للرف')->toContain('مجموعة للرف');
    // كل رابط من route() بالخادم
    foreach ($cards as $card) {
        expect(str_starts_with($card->url, config('app.url')))->toBeTrue();
    }
    // وبحث الإدارة الخادمي محدود وبالعنوان لكل نوع
    $search = app(EditorialShelfResolver::class)->search(EditorialContentType::PublicCollection, 'للرف');
    expect($search)->toBe([$collection->id => 'مجموعة للرف']);
});

test('E35-079: an arbitrary content_type (class names, tables, urls) is rejected', function () {
    $admin = e35_admin();
    $shelf = app(EditorialShelfService::class)->create($admin, ['internal_key' => 'strict-types', 'title' => 'أنواع صارمة', 'placement' => 'home']);

    foreach (['App\\Models\\User', 'users', 'https://evil.test', 'puzzle', '<script>', ''] as $bad) {
        expect(fn () => app(EditorialShelfService::class)->setItems($admin, $shelf, [[$bad, 1]]))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => app(EditorialShelfService::class)->setItems($admin, $shelf, [['community_puzzle', 'abc']]))->toThrow(InvalidArgumentException::class);
    expect(EditorialShelfItem::query()->count())->toBe(0);
});

test('E35-080: a duplicate shelf item is prevented (service and database unique)', function () {
    $admin = e35_admin();
    [$w] = e35_works(1);
    $shelf = app(EditorialShelfService::class)->create($admin, ['internal_key' => 'dups', 'title' => 'تكرار', 'placement' => 'home']);

    expect(fn () => app(EditorialShelfService::class)->setItems($admin, $shelf, [e35_ref('community_puzzle', $w->id), e35_ref('community_puzzle', $w->id)]))->toThrow(InvalidArgumentException::class);

    app(EditorialShelfService::class)->setItems($admin, $shelf, [e35_ref('community_puzzle', $w->id)]);
    expect(fn () => DB::table('editorial_shelf_items')->insert(['editorial_shelf_id' => $shelf->id, 'content_type' => 'community_puzzle', 'content_id' => $w->id, 'position' => 2, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

test('E35-081: item order is deterministic (explicit position, replace preserves the order given)', function () {
    [$a, $b, $c] = e35_works(3);
    $shelf = e35_shelf([e35_ref('community_puzzle', $c->id), e35_ref('community_puzzle', $a->id), e35_ref('community_puzzle', $b->id)], 'ordered', 'discover', ['title' => 'رف مرتّب']);

    expect($shelf->items()->get()->pluck('content_id')->map(fn ($i) => (int) $i)->all())->toBe([$c->id, $a->id, $b->id])->and($shelf->items()->pluck('position')->map(fn ($p) => (int) $p)->all())->toBe([1, 2, 3]);
    $html = e29_section($this->get(route('discover.index'))->getContent(), 'رف مرتّب');
    $pos = array_map(fn ($w) => strpos($html, $w->published_title), [$c, $a, $b]);
    expect($pos)->toBe(collect($pos)->sort()->values()->all());

    app(EditorialShelfService::class)->setItems(e35_admin(), $shelf, [e35_ref('community_puzzle', $b->id), e35_ref('community_puzzle', $c->id)]);
    expect($shelf->items()->get()->pluck('content_id')->map(fn ($i) => (int) $i)->all())->toBe([$b->id, $c->id]);
});

test('E35-082: hidden / ineligible content is omitted from the shelf live', function () {
    $creator = e28_creator();
    $keep = e29_work($creator, ['title' => 'يبقى ظاهرًا'])->fresh();
    $gone = e29_work($creator, ['title' => 'سيُخفى من الرف'])->fresh();
    $col = e35_public(null, 3, 'مجموعة ستُخفى من الرف');
    e35_shelf([e35_ref('community_puzzle', $keep->id), e35_ref('community_puzzle', $gone->id), e35_ref('public_collection', $col->id)], 'omit', 'discover', ['title' => 'رف الإخفاء']);

    $before = e29_section($this->get(route('discover.index'))->getContent(), 'رف الإخفاء');
    expect($before)->toContain('سيُخفى من الرف')->and($before)->toContain('مجموعة ستُخفى من الرف');

    e29_hide($gone);
    e35_hide_collection($col);
    $after = e29_section($this->get(route('discover.index'))->getContent(), 'رف الإخفاء');
    expect($after)->toContain('يبقى ظاهرًا')->and($after)->not->toContain('سيُخفى من الرف')->and($after)->not->toContain('مجموعة ستُخفى من الرف');
});

test('E35-083: an empty shelf is omitted (no section), and an item-less shelf cannot be activated', function () {
    $creator = e28_creator();
    $w = e29_work($creator)->fresh();
    e35_shelf([e35_ref('community_puzzle', $w->id)], 'will-empty', 'discover', ['title' => 'رف سيفرغ']);
    e29_hide($w);

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    expect($html)->not->toContain('رف سيفرغ')->and(e29_section($html, 'رف سيفرغ'))->toBe('');

    $admin = e35_admin();
    $empty = app(EditorialShelfService::class)->create($admin, ['internal_key' => 'no-items', 'title' => 'بلا عناصر', 'placement' => 'home']);
    expect(fn () => app(EditorialShelfService::class)->activate($admin, $empty))->toThrow(InvalidArgumentException::class);
});

test('E35-084: max_items is enforced (display cap and the hard cap)', function () {
    $works = e35_works(5);
    $items = array_map(fn ($w) => e35_ref('community_puzzle', $w->id), $works);
    e35_shelf($items, 'capped', 'discover', ['title' => 'رف محدود', 'max_items' => 2]);

    $cards = app(EditorialShelves::class)->forPlacement('discover', null);
    expect($cards)->toHaveCount(1)->and($cards[0]['cards'])->toHaveCount(2);

    $admin = e35_admin();
    expect(fn () => app(EditorialShelfService::class)->create($admin, ['internal_key' => 'too-many', 'title' => 'فوق السقف', 'placement' => 'home', 'max_items' => 999]))->toThrow(\Illuminate\Validation\ValidationException::class);
    config(['collections.shelves.max_items_cap' => 3]);
    $shelf = app(EditorialShelfService::class)->create($admin, ['internal_key' => 'cap-items', 'title' => 'سقف العناصر', 'placement' => 'home']);
    expect(fn () => app(EditorialShelfService::class)->setItems($admin, $shelf, array_slice($items, 0, 4)))->toThrow(InvalidArgumentException::class);
});

test('E35-085: a Discover shelf renders on Discover only', function () {
    [$w] = e35_works(1);
    e35_shelf([e35_ref('community_puzzle', $w->id)], 'd-only', 'discover', ['title' => 'رف الاكتشاف فقط']);

    $this->get(route('discover.index'))->assertSee('رف الاكتشاف فقط');
    $this->get(route('home'))->assertOk()->assertDontSee('رف الاكتشاف فقط');
});

test('E35-086: a Home shelf renders on Home (guests and members) and not on Discover', function () {
    [$w] = e35_works(1);
    e35_shelf([e35_ref('community_puzzle', $w->id)], 'h-only', 'home', ['title' => 'رف الرئيسية فقط']);

    $this->get(route('home'))->assertOk()->assertSee('رف الرئيسية فقط')->assertSee('data-home-shelf', false);
    $this->actingAs(e16User())->get(route('home'))->assertOk()->assertSee('رف الرئيسية فقط');
    $this->get(route('discover.index'))->assertDontSee('رف الرئيسية فقط');
});

test('E35-087: shelf audit events are recorded (created, updated, activated, items_changed, archived)', function () {
    $admin = e35_admin();
    $svc = app(EditorialShelfService::class);
    [$w] = e35_works(1);

    $shelf = $svc->create($admin, ['internal_key' => 'audited', 'title' => 'رف مدقَّق', 'placement' => 'home']);
    $svc->update($admin, $shelf, ['title' => 'رف مدقَّق معدّل']);
    $svc->setItems($admin, $shelf, [e35_ref('community_puzzle', $w->id)]);
    $svc->activate($admin, $shelf->fresh());
    $svc->archive($admin, $shelf->fresh());

    foreach (['shelf_created', 'shelf_updated', 'shelf_items_changed', 'shelf_activated', 'shelf_archived'] as $action) {
        $row = OperationalAuditLog::query()->where('action', $action)->first();
        expect($row)->not->toBeNull($action)->and((int) $row->actor_user_id)->toBe($admin->id);
    }
    // المؤرشف نهائي: لا تعديل ولا إعادة تفعيل
    expect(fn () => $svc->update($admin, $shelf->fresh(), ['title' => 'x y']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->activate($admin, $shelf->fresh()))->toThrow(InvalidArgumentException::class);
    // والمؤرشف يختفي من الصفحات فورًا
    $this->get(route('home'))->assertDontSee('رف مدقَّق معدّل');
});

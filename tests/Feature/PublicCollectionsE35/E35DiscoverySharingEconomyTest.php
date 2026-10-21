<?php

use App\Models\EditorialShelf;
use App\Models\PlayerStreak;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\Collections\CollectionDiscovery;
use App\Services\Collections\CollectionOrderService;
use App\Services\Collections\CollectionSaveService;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Editorial\EditorialContentType;
use App\Services\Editorial\EditorialShelfService;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e35_seed());

/* ===================== AM7. SEARCH / DISCOVERY ===================== */

test('E35-051: a public collection is searchable by its title', function () {
    e35_public(null, 2, 'ألغاز الغروب الذهبي');

    $this->get(route('search.index', ['q' => 'الغروب', 'type' => 'collections']))->assertOk()->assertSee('ألغاز الغروب الذهبي');
    $this->get(route('search.index', ['q' => 'الغروب']))->assertOk()->assertSee('ألغاز الغروب الذهبي');
});

test('E35-052: a collection result carries the "مجموعة" badge and its own type group', function () {
    $c = e35_public(null, 2, 'مجموعة الشارة');

    $json = e27_suggest($this, 'الشارة');
    $group = collect($json['groups'])->firstWhere('type', 'collections');
    expect($group)->not->toBeNull()->and($group['label'])->toBe('مجموعات المجتمع');
    $item = $group['items'][0];
    expect($item['title'])->toBe('مجموعة الشارة')->and($item['badge'])->toBe('مجموعة')->and($item['url'])->toBe('/curated/'.$c->public_id);
});

test('E35-053: the curator identity in results is safe (display name only; no email / id / slug leakage)', function () {
    $owner = e16User(['name' => 'أمينة المساء', 'email' => 'curator-secret@example.test']);
    e35_public($owner, 2, 'هوية آمنة للأمين');

    $json = $this->getJson(route('search.suggest', ['q' => 'آمنة']))->assertOk()->json();
    $raw = json_encode($json, JSON_UNESCAPED_UNICODE);
    expect($raw)->toContain('بقلم أمينة المساء')->and($raw)->not->toContain('curator-secret@example.test')->and($raw)->not->toContain('"user_id"');
});

test('E35-054: a collection with zero visible items is excluded (Search, Discover, profile); Discover also needs the minimum items', function () {
    $owner = e16User();
    $creator = e28_creator();
    $works = e35_works(3, $creator);
    $c = e35_collection($owner, $works, 'public', 'ستفرغ من الأعمال الظاهرة');
    $few = e35_collection($owner, array_slice($works, 0, 2), 'public', 'عملان فقط');
    foreach ($works as $w) {
        e29_hide($w);
    }
    Cache::flush();

    $this->get(route('search.index', ['q' => 'ستفرغ', 'type' => 'collections']))->assertDontSee('ستفرغ من الأعمال الظاهرة');
    $this->get(route('discover.index'))->assertDontSee('ستفرغ من الأعمال الظاهرة');
    $this->get(route('players.show', $owner))->assertOk()->assertDontSee('ستفرغ من الأعمال الظاهرة');

    // عملان ظاهران: تظهر في البحث (حدّ 1) لا في الاكتشاف (حدّ 3)
    foreach ($works as $w) {
        e29_unhide($w);
    }
    e29_hide($works[2]);
    Cache::flush();
    $this->get(route('search.index', ['q' => 'عملان', 'type' => 'collections']))->assertSee('عملان فقط');
    $this->get(route('discover.index'))->assertDontSee('عملان فقط');
});

test('E35-055: Discover shows a "مجموعات من المجتمع" section with the qualifying public collection', function () {
    $c = e35_public(null, 3, 'اختيارات المجتمع الرائعة');
    Cache::flush();

    $html = $this->get(route('discover.index'))->assertOk()->getContent();
    $section = e35_discover_section($html);
    expect($section)->not->toBe('')->and($section)->toContain('اختيارات المجتمع الرائعة')->and($section)->toContain(route('collections.public.show', $c->public_id))->and($section)->toContain('مجموعة');
});

test('E35-056: curator diversity — one curator cannot dominate the section (per-owner cap)', function () {
    $owner = e16User();
    $creator = e28_creator();
    $works = e35_works(3, $creator);
    foreach (['أولى', 'ثانية', 'ثالثة'] as $name) {
        e35_collection($owner, $works, 'public', 'مجموعة '.$name.' للأمين نفسه');
    }
    $other = e35_public(null, 3, 'مجموعة أمين آخر');
    Cache::flush();

    $cards = app(CollectionDiscovery::class)->cards(null);
    $titles = array_map(fn ($c) => $c->title, $cards);
    expect(count(array_filter($titles, fn ($t) => str_contains($t, 'للأمين نفسه'))))->toBe(1)->and($titles)->toContain('مجموعة أمين آخر');

    config(['collections.discover.per_owner_cap' => 2]);
    expect(count(array_filter(array_map(fn ($c) => $c->title, app(CollectionDiscovery::class)->cards(null)), fn ($t) => str_contains($t, 'للأمين نفسه'))))->toBe(2);
});

test('E35-057: a hidden collection is excluded even when the cached pool still contains it', function () {
    $c = e35_public(null, 3, 'في الكاش لكنها مخفية');
    Cache::flush();
    $pool = app(CollectionDiscovery::class)->pool();
    expect(array_key_exists($c->id, $pool))->toBeTrue();

    e35_hide_collection($c);
    Cache::put(CollectionDiscovery::CACHE_KEY, $pool, 300);

    expect(array_map(fn ($card) => $card->title, app(CollectionDiscovery::class)->cards(null)))->not->toContain('في الكاش لكنها مخفية');
    // كذلك الكاش لا يحمل عناوين أو أسماء (معرّفات ودرجات فقط)
    expect(json_encode(Cache::get(CollectionDiscovery::CACHE_KEY)))->not->toContain('في الكاش');
});

test('E35-058: unlisted and private collections are excluded from the Discover pool and the Search source', function () {
    $owner = e16User();
    $works = e35_works(3);
    e35_collection($owner, $works, 'unlisted', 'غير مدرجة خارج المصادر');
    e35_collection($owner, $works, 'private', 'خاصة خارج المصادر');
    Cache::flush();

    expect(app(CollectionDiscovery::class)->pool())->toBe([]);
    expect(array_map(fn ($c) => $c->title, app(CollectionDiscovery::class)->cards(null)))->toBe([]);
    $this->get(route('search.index', ['q' => 'خارج', 'type' => 'collections']))->assertDontSee('خارج المصادر');
});

/* ===================== AM8. SHARING / SEO ===================== */

test('E35-059: a public collection has a clean canonical URL (no tracking query)', function () {
    $c = e35_public(null, 2);
    $url = route('collections.public.show', $c->public_id);

    $html = $this->get($url.'?utm_source=x&ref=ABC123')->assertOk()->getContent();
    expect($html)->toContain('<link rel="canonical" href="'.$url.'">')->and($html)->toContain('<meta property="og:url" content="'.$url.'">')
        ->and($html)->not->toContain('name="robots" content="noindex');
});

test('E35-060: a public collection exposes the E33 share component with the canonical link', function () {
    $c = e35_public(null, 2, 'مجموعة للمشاركة');

    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($html)->toContain('data-share-component')->and($html)->toContain('data-share-kind="collection"')->and($html)->toContain(str_replace('/', '\\\\\\/', route('collections.public.show', $c->public_id)));
    $payload = app(ShareLinkBuilder::class)->build('collection', $c->public_id, $c->title);
    expect($payload['url'])->toBe(route('collections.public.show', $c->public_id))->and($payload['url_ref'])->toBeNull();
});

test('E35-061: an unlisted collection is shareable by link (owner page and public page)', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(1), 'unlisted', 'قابلة للمشاركة بالرابط');

    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('data-share-kind="collection"', false);
    $this->get(route('collections.public.show', $c->public_id))->assertOk()->assertSee('data-share-kind="collection"', false);
});

test('E35-062: a private collection is not publicly shareable (no share component, no public URL)', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(1), 'private', 'خاصة غير قابلة للمشاركة');

    $html = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect($html)->not->toContain('data-share-component')->and($html)->not->toContain('data-collection-public-link');
    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();
});

test('E35-063: unlisted pages are noindex,nofollow while public pages are indexable', function () {
    $owner = e16User();
    $unlisted = e35_collection($owner, e35_works(1), 'unlisted', 'غير مفهرسة');
    $public = e35_public(null, 2, 'مفهرسة');

    expect($this->get(route('collections.public.show', $unlisted->public_id))->assertOk()->getContent())->toContain('<meta name="robots" content="noindex, nofollow">');
    expect($this->get(route('collections.public.show', $public->public_id))->assertOk()->getContent())->not->toContain('<meta name="robots" content="noindex');
});

test('E35-064: the OG/meta description is escaped plain text (no attribute breakout)', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(2), 'public', 'وصف آمن', 'ألغاز "ذكية" & مسلية — لا وسوم');

    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($html)->toContain('content="ألغاز &quot;ذكية&quot; &amp; مسلية — لا وسوم"')->and($html)->not->toContain('content="ألغاز "ذكية"');
});

test('E35-065: referral attribution coexists with the canonical page (share with my invite link; canonical stays clean)', function () {
    config(['referrals.enabled' => true]);
    $sharer = e16User();
    $c = e35_public(null, 2, 'مجموعة بدعوة');
    $code = app(\App\Services\Referrals\ReferralCodeService::class)->ensureFor($sharer)->code;

    $payload = app(ShareLinkBuilder::class)->build('collection', $c->public_id, $c->title, null, $sharer);
    expect($payload['url_ref'])->toBe(route('collections.public.show', $c->public_id).'?ref='.$code);

    $html = $this->get($payload['url_ref'])->assertOk()->getContent();
    expect($html)->toContain('<link rel="canonical" href="'.route('collections.public.show', $c->public_id).'">');
    $this->actingAs($sharer)->postJson(route('referrals.share'), ['kind' => 'collection', 'target' => 'copy', 'with_ref' => true])->assertSuccessful();
});

test('E35-066: no open redirect — query params never steer redirects; the share builder only uses named routes', function () {
    $c = e35_public(null, 2);
    $saver = e16User();

    $this->get(route('collections.public.show', $c->public_id).'?next=https://evil.test&redirect=//evil.test')->assertOk();
    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));
    $res = $this->actingAs($saver)->delete(route('collections.public.unsave', $c->public_id), ['from' => 'https://evil.test']);
    // back() يبقى على مضيف التطبيق نفسه (لا يتبع حقل from ولا معاملات الاستعلام إلى مضيف خارجي)
    expect(parse_url((string) $res->headers->get('Location'), PHP_URL_HOST))->toBe('localhost');
    $off = $this->actingAs($saver)->delete(route('collections.public.unsave', $c->public_id), ['from' => 'saved']);
    expect((string) $off->headers->get('Location'))->toBe(route('collections.saved'));
    $payload = app(ShareLinkBuilder::class)->build('collection', 'https://evil.test', 'x');
    expect(str_starts_with($payload['url'], rtrim(config('app.url'), '/')))->toBeTrue();
});

/* ===================== AM9. ECONOMY ===================== */

test('E35-067: publishing a collection grants zero gems / currency', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(2));
    $this->actingAs($owner)->get(route('discover.index'));
    $before = e28_world($owner);
    $snap = e16Snapshot();

    app(CollectionVisibilityService::class)->publish($owner, $c->public_id);

    expect(e28_world($owner)['wallet'])->toBe($before['wallet'])->and(e16Snapshot())->toBe($snap)->and($before['counts']['GemTransaction'])->toBe(e28_world($owner)['counts']['GemTransaction']);
});

test('E35-068: publishing / unpublishing / reordering grants zero XP', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(3));
    $this->actingAs($owner)->get(route('discover.index'));
    $before = e28_world($owner);

    $svc = app(CollectionVisibilityService::class);
    $svc->publish($owner, $c->public_id);
    $svc->makePrivate($owner, $c->public_id);
    $svc->makeUnlisted($owner, $c->public_id);
    app(CollectionOrderService::class)->reorder($owner, $c->public_id, array_reverse(e35_order($c)));

    expect(e28_world($owner))->toBe($before);
});

test('E35-069: saving a collection grants zero gems to the saver and the owner', function () {
    $owner = e16User();
    $c = e35_public($owner, 2);
    $saver = e16User();
    $this->actingAs($saver)->get(route('discover.index'));
    $this->actingAs($owner)->get(route('discover.index'));
    $a = [e28_world($saver), e28_world($owner)];

    app(CollectionSaveService::class)->save($saver, $c->public_id);
    expect([e28_world($saver), e28_world($owner)])->toBe($a);
});

test('E35-070: featuring a collection on an editorial shelf grants zero rewards to its owner', function () {
    $owner = e16User();
    $c = e35_public($owner, 3);
    $this->actingAs($owner)->get(route('discover.index'));
    $before = e28_world($owner);
    $snap = e16Snapshot();

    $admin = e35_admin();
    $svc = app(EditorialShelfService::class);
    $shelf = $svc->create($admin, ['internal_key' => 'feature-zero', 'title' => 'رف التجربة', 'placement' => 'discover']);
    $svc->setItems($admin, $shelf, [[EditorialContentType::PublicCollection->value, $c->id]]);
    $svc->activate($admin, $shelf->fresh());

    expect(e28_world($owner))->toBe($before)->and(e16Snapshot())->toBe($snap);
    expect(\Illuminate\Notifications\DatabaseNotification::query()->where('notifiable_id', $owner->id)->count())->toBe(0);
});

test('E35-071: collection activity does not progress official quests', function () {
    $owner = e16User();
    $quest = \App\Models\QuestDefinition::factory()->create();
    $c = e35_collection($owner, e35_works(2));
    $this->actingAs($owner)->get(route('discover.index'));
    $before = UserQuestProgress::query()->count();
    $sum = (int) UserQuestProgress::query()->sum('current_value');

    app(CollectionVisibilityService::class)->publish($owner, $c->public_id);
    app(CollectionSaveService::class)->save(e16User(), $c->public_id);
    $this->actingAs($owner)->get(route('collections.show', $c->public_id));

    expect(UserQuestProgress::query()->count())->toBe($before)->and((int) UserQuestProgress::query()->sum('current_value'))->toBe($sum);
});

test('E35-072: collection activity does not affect the streak', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(2));
    $saver = e16User();
    $this->actingAs($owner)->get(route('discover.index'));
    $before = [PlayerStreak::query()->count(), (int) PlayerStreak::query()->sum('current_streak'), (int) PlayerStreak::query()->sum('longest_streak')];

    app(CollectionVisibilityService::class)->publish($owner, $c->public_id);
    app(CollectionSaveService::class)->save($saver, $c->public_id);
    $this->actingAs($saver)->get(route('collections.saved'));

    expect([PlayerStreak::query()->count(), (int) PlayerStreak::query()->sum('current_streak'), (int) PlayerStreak::query()->sum('longest_streak')])->toBe($before);
});

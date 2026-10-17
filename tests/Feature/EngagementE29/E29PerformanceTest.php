<?php

use App\Models\CreatorProfile;
use App\Services\Engagement\CreatorFollowService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

/*
| E29 - ميزانيات الاستعلام. الأرقام المثبَّتة قياسات فعلية (لا تقديرية) مع هامش صغير؛ تتبع الحالة لا حجم البيانات:
| ما يهم بالاختبارات: **تساوي** العدد بين حجمين مختلفين (لا N+1) + سقف مطلق يلتقط أي انجراف كبير مستقبلًا.
*/

test('AA11-105: the puzzle page query count is bounded and independent of rating volume', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);
    $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk();   // تسخين
    $few = e27_queries(fn () => $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk());

    e29_ratings($work, array_fill(0, 25, 4));
    $many = e27_queries(fn () => $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk());
    $this->app['auth']->forgetGuards();
    $guest = e27_queries(fn () => $this->get(route('community.puzzles.show', $work->public_id))->assertOk());

    // القياس الفعلي: لاعب 15 (E28 كان يقيس 17 بحالة محلولة)، ضيف 5 = رقم E28 نفسه: التفاعل لا يضيف استعلامًا واحدًا للضيف (المتوسط والعدّادات أعمدة العمل نفسه).
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(17)->and($guest)->toBe(5);
});

test('AA11-106: the favorites list is bounded (same queries for 2 and 12 items)', function () {
    $user = e16User();
    $creator = e28_creator();
    foreach (range(1, 2) as $i) { e29_favorite($user, e29_work($creator)); }
    $this->actingAs($user)->get(route('favorites.index'))->assertOk();
    $two = e27_queries(fn () => $this->get(route('favorites.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_favorite($user, e29_work(e28_creator())); }
    $twelve = e27_queries(fn () => $this->get(route('favorites.index'))->assertOk());

    expect($twelve)->toBe($two)->and($twelve)->toBeLessThanOrEqual(14);
});

test('AA11-107: the following feed is bounded (same queries for 2 and 12 items)', function () {
    $user = e16User();
    foreach (range(1, 2) as $i) { e29_work($c = e28_creator()); e29_follow($user, $c); }
    $this->actingAs($user)->get(route('following.index'))->assertOk();
    $two = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_work($c = e28_creator()); e29_follow($user, $c); }
    $twelve = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    expect($twelve)->toBe($two)->and($twelve)->toBeLessThanOrEqual(14);
});

test('AA11-108: the creator page is bounded for guests and signed-in viewers', function () {
    $creator = e28_creator();
    $slug = e29_profile($creator)->slug;
    e29_work($creator);
    $viewer = e16User();
    $this->get(route('creators.show', $slug))->assertOk();
    $guestOne = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());
    $this->actingAs($viewer)->get(route('creators.show', $slug))->assertOk();
    $userOne = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());

    foreach (range(1, 10) as $i) { e29_favorite($viewer, e29_work($creator)); }
    $userMany = e27_queries(fn () => $this->get(route('creators.show', $slug))->assertOk());
    $this->app['auth']->forgetGuards();

    // الضيف 8 = رقم E28 (7) + استعلام E30 واحد محدود لقسم «سلاسله» (قاعدة الظهور المركزية، LIMIT ثابت) - لا استعلام تفاعل للضيف؛ المسجَّل +استعلامان فقط فوق الجرس/التقدّم: حالة المتابعة (exists) + مفضلة البطاقات (دفعة واحدة).
    expect($userMany)->toBe($userOne)->and($userMany)->toBeLessThanOrEqual(18)->and($guestOne)->toBe(8);
});

test('AA11-109: 1 vs 12 community cards cost the same queries (no N+1), saved state is one batched query', function () {
    $creator = e28_creator();
    $viewer = e16User();
    e29_favorite($viewer, e29_work($creator));
    $this->actingAs($viewer)->get(route('community.puzzles.index'))->assertOk();
    $one = e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());

    foreach (range(1, 11) as $i) { $w = e29_work(e28_creator()); $i % 2 ? e29_favorite($viewer, $w) : e29_ratings($w, [5, 4]); }
    $twelve = e27_queries(fn () => $this->get(route('community.puzzles.index'))->assertOk());

    expect($twelve)->toBe($one)->and($twelve)->toBeLessThanOrEqual(16);
});

test('AA11-110: the discover page query count is bounded with engagement signals present', function () {
    $viewer = e16User();
    $creator = e28_creator();
    e29_follow($viewer, $creator);
    e29_work($creator);
    foreach (range(1, 6) as $i) { $w = e29_work(e28_creator()); e29_ratings($w, [5, 5, 4, 5, 5]); e29_favorite($viewer, $w); }
    e27_cold();
    $this->actingAs($viewer)->get(route('discover.index'))->assertOk();
    $warm = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());
    e27_cold();
    $cold = e27_queries(fn () => $this->get(route('discover.index'))->assertOk());

    // القياس: دافئ 27، بارد 34 لمستخدم مسجَّل لديه متابعة ومفضلة وأعمال مقيَّمة. (+3 فوق E28: الأعلى تقييمًا، من أتابعهم، تفضيلات المشاهد.)
    expect($warm)->toBeLessThanOrEqual(30)->and($cold)->toBeLessThanOrEqual(38);
});

test('AA11-111: the star control and engagement panel render for a player without extra per-card work', function () {
    $work = e29_work();
    $viewer = e29_player_who_played($work);

    $html = $this->actingAs($viewer)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();

    expect($html)->toContain('id="engagement"')->toContain('role="radiogroup"')->toContain('name="rating"')->toContain('احفظ في المفضلة')->toContain('أضف إلى مجموعة');
    expect(substr_count($html, 'type="radio" name="rating"'))->toBe(5);
});

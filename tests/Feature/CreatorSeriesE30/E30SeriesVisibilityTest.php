<?php

use App\Models\CreatorSeries;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Creators\CreatorApplicationService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30s_seed());

test('AM11-103: a published series is public for guests (by public id and by slug) with the community badge and no rewards wording', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'سلسلة الغيوم المضيئة', 'description' => 'ثلاث ألغاز عن السماء.']);

    foreach ([$series->public_id, $series->slug] as $key) {
        $this->get(route('series.show', $key))->assertOk()->assertSee('سلسلة الغيوم المضيئة')->assertSee('ثلاث ألغاز عن السماء.')->assertSee('من المجتمع')->assertSee('بلا جوائز');
    }

    $html = $this->get(route('series.show', $series->public_id))->getContent();
    expect(substr_count($html, 'data-series-card'))->toBe(0);
    e28_assert_private_absent($html, $creator);
    e28_assert_no_secrets($html);
    // RTL: التخطيط العام يضبط dir=rtl
    expect($html)->toContain('dir="rtl"');
});

test('AM11-104: a draft (and a pending) series is not public', function () {
    $creator = e28_creator();
    $draft = e30s_draft($creator)->series()->first();
    $pending = e30s_pending($creator)->series()->first();

    foreach ([$draft, $pending] as $series) {
        $this->get(route('series.show', $series->public_id))->assertNotFound();
        $this->get(route('series.show', $series->slug))->assertNotFound();
        expect(e30s_visible($series))->toBeFalse();
    }
});

test('AM11-105: a rejected series is not public', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);
    app(CreatorSeriesReviewService::class)->reject($revision, e28_reviewer(), 'لا تحقق الحد الأدنى من الجودة.');

    $this->get(route('series.show', $revision->series()->value('public_id')))->assertNotFound();
});

test('AM11-106: a suspended creator hides the series everywhere and un-suspending restores it', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'سلسلة المبدع المعلَّق']);
    $profile = $creator->creatorProfile()->first();

    $this->get(route('series.show', $series->public_id))->assertOk();
    $this->get(route('creators.show', $profile->slug))->assertOk()->assertSee('سلسلة المبدع المعلَّق');

    app(CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'تعليق تجريبي للاختبار هنا.');
    $this->get(route('series.show', $series->public_id))->assertNotFound();
    $this->get(route('search.index', ['q' => 'المعلَّق']))->assertOk()->assertDontSee('سلسلة المبدع المعلَّق');
    expect(e30s_visible($series))->toBeFalse();

    app(CreatorApplicationService::class)->restore($profile->fresh(), e28_reviewer('administrator'));
    $this->get(route('series.show', $series->public_id))->assertOk();
    expect(e30s_visible($series))->toBeTrue();
});

test('AM11-106b: a frozen creator account hides the series too', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);

    $creator->forceFill(['is_frozen' => true])->save();
    $this->get(route('series.show', $series->public_id))->assertNotFound();

    $creator->forceFill(['is_frozen' => false])->save();
    $this->get(route('series.show', $series->public_id))->assertOk();
});

test('AM11-107: a hidden required puzzle hides the whole series', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);

    $this->get(route('series.show', $series->public_id))->assertOk();
    e30s_hide_work($works[1]);

    $this->get(route('series.show', $series->public_id))->assertNotFound();
    expect(e30s_visible($series))->toBeFalse()
        ->and(app(CreatorSeriesVisibilityService::class)->blockers($series->fresh()))->not->toBe([])
        ->and(app(CreatorSeriesVisibilityService::class)->hiddenItemCount((int) $series->fresh()->published_revision_id))->toBe(1);
});

test('AM11-108: restoring the puzzle restores the eligible series (and an ineligible one stays hidden)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $seriesA = e30s_published($creator, [$works[0], $works[1]]);
    $seriesB = e30s_published($creator, [$works[1], $works[2]]);

    e30s_hide_work($works[1]);
    expect(e30s_visible($seriesA))->toBeFalse()->and(e30s_visible($seriesB))->toBeFalse();

    e30s_restore_work($works[1]);
    expect(e30s_visible($seriesA))->toBeTrue()->and(e30s_visible($seriesB))->toBeTrue();

    // سلسلة مخفية إشرافيًا تبقى مخفية حتى باستعادة الأحجية
    app(CreatorSeriesReviewService::class)->hide($seriesA->fresh(), e28_reviewer(), 'سبب إخفاء إشرافي واضح.');
    e30s_hide_work($works[0]);
    e30s_restore_work($works[0]);
    expect(e30s_visible($seriesA))->toBeFalse()->and(e30s_visible($seriesB))->toBeTrue();

    app(CreatorSeriesReviewService::class)->restore($seriesA->fresh(), e28_reviewer());
    expect(e30s_visible($seriesA))->toBeTrue();
});

test('AM11-109: an archived series is hidden and un-archiving restores it', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);

    app(CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('series.show', $series->public_id))->assertNotFound();
    expect(e30s_visible($series))->toBeFalse();

    app(CreatorSeriesService::class)->unarchive($creator, $series->public_id);
    $this->get(route('series.show', $series->public_id))->assertOk();
});

test('AM11-110: Search finds a visible series (own type tab) and hides every blocked state', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'سلسلة العنقاء الفريدة']);

    $this->get(route('search.index', ['q' => 'العنقاء']))->assertOk()->assertSee('سلسلة العنقاء الفريدة')->assertSee('سلسلة من المجتمع');
    $this->get(route('search.index', ['q' => 'العنقاء', 'type' => 'creator_series']))->assertOk()->assertSee('سلسلة العنقاء الفريدة');
    $json = json_encode($this->getJson(route('search.suggest', ['q' => 'العنقاء']))->assertOk()->json(), JSON_UNESCAPED_UNICODE);
    expect($json)->toContain('العنقاء')->and($json)->not->toContain('"id"')->and($json)->not->toContain('creator_profile');

    // لا تُكتشف السلسلة بمحتوى الأحجيات ولا بجواب
    $this->get(route('search.index', ['q' => 'القلم', 'type' => 'creator_series']))->assertOk()->assertDontSee('سلسلة العنقاء الفريدة');

    e30s_hide_work($works[0]);
    $this->get(route('search.index', ['q' => 'العنقاء']))->assertOk()->assertDontSee('سلسلة العنقاء الفريدة');
    e30s_restore_work($works[0]);

    app(CreatorSeriesService::class)->archive($creator, $series->public_id);
    $this->get(route('search.index', ['q' => 'العنقاء', 'type' => 'creator_series']))->assertDontSee('سلسلة العنقاء الفريدة');
    app(CreatorSeriesService::class)->unarchive($creator, $series->public_id);

    app(CreatorSeriesReviewService::class)->hide($series->fresh(), e28_reviewer(), 'سبب إخفاء إشرافي واضح.');
    $this->get(route('search.index', ['q' => 'العنقاء']))->assertOk()->assertDontSee('سلسلة العنقاء الفريدة');
});

test('AM11-110b: a draft or pending series never appears in Search', function () {
    $creator = e28_creator();
    e30s_draft($creator, null, ['title' => 'سلسلة مسودة سرية']);
    e30s_pending($creator, null, ['title' => 'سلسلة معلقة سرية']);

    $this->get(route('search.index', ['q' => 'سرية']))->assertOk()->assertDontSee('مسودة سرية')->assertDontSee('معلقة سرية');
});

test('AM11-111: Discovery lists visible series and hides blocked ones immediately, even while ids are cached', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works, ['title' => 'سلسلة الفجر الجديد']);

    $this->get(route('discover.index'))->assertOk()->assertSee('سلاسل من المجتمع')->assertSee('سلسلة الفجر الجديد')->assertSee('سلسلة من المجتمع');
    expect(Cache::has('discover.series.newest'))->toBeTrue();

    e30s_hide_work($works[2]);   // المعرّفات ما زالت بالكاش، والظهور حيّ
    $this->get(route('discover.index'))->assertOk()->assertDontSee('سلسلة الفجر الجديد');
    e30s_restore_work($works[2]);
    $this->get(route('discover.index'))->assertOk()->assertSee('سلسلة الفجر الجديد');

    $profile = $creator->creatorProfile()->first();
    app(CreatorApplicationService::class)->suspend($profile, e28_reviewer('administrator'), 'تعليق تجريبي للاختبار هنا.');
    $this->get(route('discover.index'))->assertOk()->assertDontSee('سلسلة الفجر الجديد');
});

test('AM11: followers get a "series from people you follow" section, others do not', function () {
    $creator = e28_creator();
    $follower = e16User();
    $other = e16User();
    e29_follow($follower, $creator);
    e30s_published($creator, null, ['title' => 'سلسلة من متابَع']);

    $this->actingAs($follower)->get(route('discover.index'))->assertOk()->assertSee('سلاسل جديدة ممّن تتابعهم')->assertSee('من مبدع تتابعه');
    $this->actingAs($other)->get(route('discover.index'))->assertOk()->assertDontSee('سلاسل جديدة ممّن تتابعهم');
});

test('AM11: the creator public profile shows Series next to Puzzles, only the visible ones', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $visible = e30s_published($creator, $works, ['title' => 'سلسلة ظاهرة بالملف']);
    $hidden = e30s_published($creator, [$works[0], $works[1]], ['title' => 'سلسلة مؤرشفة بالملف']);
    app(CreatorSeriesService::class)->archive($creator, $hidden->public_id);
    $slug = $creator->creatorProfile()->value('slug');

    $this->get(route('creators.show', $slug))->assertOk()->assertSee('سلاسله')->assertSee('سلسلة ظاهرة بالملف')->assertDontSee('سلسلة مؤرشفة بالملف')->assertSee('أحجياته');
});

test('AM11: an unknown or malformed series key is a clean 404', function () {
    foreach (['nope', '01ZZZZZZZZZZZZZZZZZZZZZZZZ', str_repeat('a', 40)] as $key) {
        $this->get('/series/'.$key)->assertNotFound();
    }
});

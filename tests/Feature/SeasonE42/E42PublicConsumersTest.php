<?php

use App\Models\Campaign;
use App\Models\Season;
use App\Models\SeasonContentItem;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\Seasons\SeasonCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

/*
| E42 / الفهرس والأرشيف والصفحة الرئيسية والبحث والاكتشاف والتقويم: كلها تقرأ الحالة من المصدر الواحد (SeasonLifecycleService / نطاقات Season).
*/

beforeEach(function () {
    e42_seed();
    config(['liveops.calendar.cache_seconds' => 0]);
});
afterEach(fn () => Carbon::setTestNow());

function e42_titled(string $title, array $o = []): Season
{
    $o['campaign'] = ($o['campaign'] ?? []) + ['title' => $title];

    return e42_season($o + ['structure' => false]);
}

/** عناوين مجموعة الموسم في اقتراحات البحث (الحملات تُفهرَس مستقلة بمجموعتها؛ نفحص المواسم فقط). */
function e42_search_seasons(string $q): array
{
    $groups = test()->getJson(route('search.suggest', ['q' => $q]))->assertOk()->json('groups');

    return collect($groups)->firstWhere('type', 'seasons')['items'] ?? [];
}

/** عناوين عناصر التقويم من نوع الموسم فقط (حملة موسم غير منشور تظهر كحملة مستقلة وهذا سلوك الأساس القديم). */
function e42_calendar_titles(string $type = EventCalendarService::TYPE_SEASON): array
{
    Cache::flush();

    return app(EventCalendarService::class)->items()->filter(fn ($i) => $i->type === $type)->map(fn ($i) => $i->title)->values()->all();
}

// ───────────────────────────── الفهرس ─────────────────────────────

test('E42-PB-01: the index separates live / upcoming / past and hides drafts and unannounced seasons', function () {
    e42_titled('موسم جارٍ', ['window' => ['2026-12-01', '2026-12-30']]);
    e42_titled('موسم قادم', ['window' => ['2026-12-20', '2026-12-30']]);
    e42_titled('موسم منتهٍ', ['window' => ['2026-11-01', '2026-11-20']]);
    e42_titled('موسم مسودة', ['published' => false, 'window' => ['2026-12-01', '2026-12-30']]);
    e42_titled('موسم غير معلن', ['window' => ['2026-12-20', '2026-12-30'], 'season' => ['announcement_at' => '2026-12-15']]);

    $html = $this->get(route('seasons.index'))->assertOk()->getContent();

    expect($html)->toContain('موسم جارٍ')->toContain('موسم قادم')->toContain('موسم منتهٍ')->not->toContain('موسم مسودة')->not->toContain('موسم غير معلن')
        ->and($html)->toContain('مباشر الآن')->toContain('قادمة')->toContain('مواسم سابقة');
});

test('E42-PB-02: legacy windowless seasons keep their old buckets (campaign dates / inactive campaign)', function () {
    e42_titled('قديم نشط');
    e42_titled('قديم انتهت حملته', ['campaign' => ['ends_at' => '2026-12-01']]);
    e42_titled('قديم حملة موقوفة', ['campaign' => ['is_active' => false]]);

    $html = $this->get(route('seasons.index'))->assertOk()->getContent();
    $live = mb_strpos($html, 'قديم نشط');
    $past = mb_strpos($html, 'قديم انتهت حملته');
    $inactive = mb_strpos($html, 'قديم حملة موقوفة');

    expect($live)->not->toBeFalse()->and($past)->not->toBeFalse()->and($inactive)->not->toBeFalse()
        ->and($live)->toBeLessThan($inactive)           // الموقوفة ضمن «قادمة» بعد الجارية
        ->and($inactive)->toBeLessThan($past);           // والمنتهية في «مواسم سابقة»
});

test('E42-PB-03: past seasons are previewed with a cap and an archive link when there are more', function () {
    config(['seasons.index.past_preview' => 2]);
    foreach (range(1, 3) as $i) {
        e42_titled('منتهٍ رقم '.$i, ['window' => ['2026-10-0'.$i, '2026-10-2'.$i]]);
    }

    $html = $this->get(route('seasons.index'))->assertOk()->getContent();

    expect(substr_count($html, 'منتهٍ رقم'))->toBe(2)->and($html)->toContain(route('seasons.archive'));
});

// ───────────────────────────── الأرشيف ─────────────────────────────

test('E42-PB-04: the archive lists ended and archived seasons only, newest first, paginated; /seasons/archive is not a slug', function () {
    config(['seasons.index.archive_per_page' => 2]);
    e42_titled('أرشيف أ', ['window' => ['2026-09-01', '2026-09-20']]);
    e42_titled('أرشيف ب', ['window' => ['2026-10-01', '2026-10-20']]);
    e42_titled('أرشيف ج', ['window' => ['2026-11-01', '2026-11-20'], 'season' => ['archive_at' => '2026-12-01']]);
    e42_titled('جارٍ الآن', ['window' => ['2026-12-01', '2026-12-30']]);
    e42_titled('قادم', ['window' => ['2026-12-20', '2026-12-30']]);
    e42_titled('مسودة قديمة', ['published' => false, 'window' => ['2026-08-01', '2026-08-20']]);

    $first = $this->get(route('seasons.archive'))->assertOk()->getContent();

    expect($first)->toContain('أرشيف ج')->toContain('أرشيف ب')->not->toContain('أرشيف أ')
        ->not->toContain('جارٍ الآن')->not->toContain('مسودة قديمة')
        ->and(mb_strpos($first, 'أرشيف ج'))->toBeLessThan(mb_strpos($first, 'أرشيف ب'));

    $second = $this->get(route('seasons.archive', ['page' => 2]))->assertOk()->getContent();
    expect($second)->toContain('أرشيف أ')->not->toContain('أرشيف ج');

    expect(route('seasons.archive', [], false))->toBe('/seasons/archive');
});

test('E42-PB-05: the archive marks the viewer\'s own completion with one query for the page (no N+1)', function () {
    foreach (range(1, 4) as $i) {
        e42_titled('منتهٍ '.$i, ['window' => ['2026-10-0'.$i, '2026-10-1'.$i]]);
    }
    $user = e16User();
    $other = e42_titled('أخير', ['window' => ['2026-10-20', '2026-10-25']]);
    \Illuminate\Support\Facades\DB::table('user_campaign_completions')->insert(['user_id' => $user->id, 'campaign_id' => $other->campaign_id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($user);
    $this->get(route('seasons.archive'))->assertOk(); // تسخين (جلسة/كاش) كي يقيس العدّان حالة دافئة متماثلة
    $few = e42_queries(fn () => $this->get(route('seasons.archive'))->assertOk());

    foreach (range(5, 9) as $i) {
        e42_titled('منتهٍ '.$i, ['window' => ['2026-10-0'.($i % 9 + 1), '2026-10-1'.($i % 9)]]);
    }
    $more = e42_queries(fn () => $this->get(route('seasons.archive'))->assertOk());

    expect($more)->toBe($few); // 5 مواسم ثم 10 بصفحة واحدة: لا استعلام لكل موسم
});

test('E42-PB-05b: the index query budget is constant - 2 vs 12 seasons across live / upcoming / past cost the same queries (no per-season lookup)', function () {
    $seed = function (int $n, string $tag): void {
        foreach (range(1, $n) as $i) {
            e42_titled($tag.' جارٍ '.$i, ['window' => ['2026-12-01', '2026-12-30']]);
            e42_titled($tag.' قادم '.$i, ['window' => ['2026-12-20', '2026-12-30']]);
            e42_titled($tag.' منتهٍ '.$i, ['window' => ['2026-10-01', '2026-10-2'.($i % 9)]]);
        }
    };

    $measure = function (): int {
        $this->get(route('seasons.index'))->assertOk(); // تسخين (جلسة/كاش)

        return e42_queries(fn () => $this->get(route('seasons.index'))->assertOk());
    };

    $seed(1, 'أ');
    $guestFew = $measure();
    $this->actingAs(e16User());
    $userFew = $measure();
    app('auth')->forgetGuards(); // عودة إلى ضيف

    $seed(4, 'ب'); // 12 موسمًا بدل 3 (4 أضعاف)
    $guestMany = $measure();
    $this->actingAs(e16User());
    $userMany = $measure();

    // لا استعلام لكل موسم: الحملة تُحمَّل بـwith() دفعة واحدة، والحالة تُشتقّ في PHP، والمنتهي مقصوص بـ past_preview.
    expect($guestMany)->toBe($guestFew)->and($userMany)->toBe($userFew)
        ->and($guestMany)->toBeLessThan(25)->and($userMany)->toBeLessThan(35);
});

// ───────────────────────────── الصفحة الرئيسية ─────────────────────────────

test('E42-PB-06: the home featured season must be visible, live by the explicit window and its campaign available', function () {
    e42_titled('مميز منتهٍ', ['window' => ['2026-11-01', '2026-11-20'], 'season' => ['is_featured' => true]]);
    e42_titled('مميز غير معلن', ['window' => ['2026-12-20', '2026-12-30'], 'season' => ['is_featured' => true, 'announcement_at' => '2026-12-15']]);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('مميز منتهٍ')->not->toContain('مميز غير معلن');

    e42_titled('مميز جارٍ', ['window' => ['2026-12-01', '2026-12-30'], 'season' => ['is_featured' => true]]);
    $this->get('/')->assertOk()->assertSee('مميز جارٍ');
});

test('E42-PB-07: an explicit window ignores a paused campaign for visibility but the campaign stays unplayable', function () {
    $season = e42_titled('نافذة صريحة موقوفة', ['window' => ['2026-12-01', '2026-12-30'], 'campaign' => ['is_active' => false]]);

    $html = $this->actingAs(e16User())->get(route('seasons.show', $season))->assertOk()->getContent();

    expect(e42_life()->state($season->fresh('campaign')))->toBe('live')
        ->and($html)->toContain('غير متاحة حالياً'); // الرسالة للاعب المسجّل: اللعب يتبع إتاحة الحملة نفسها
});

// ───────────────────────────── البحث والاكتشاف والتقويم ─────────────────────────────

test('E42-PB-08: search and discover show only publicly visible seasons with truthful badges', function () {
    e42_titled('بحث موسم ظاهر', ['window' => ['2026-12-01', '2026-12-30']]);
    e42_titled('بحث موسم منتهٍ', ['window' => ['2026-11-01', '2026-11-20']]);
    e42_titled('بحث موسم مسودة', ['published' => false, 'window' => ['2026-12-01', '2026-12-30']]);
    e42_titled('بحث موسم سري', ['window' => ['2026-12-20', '2026-12-30'], 'season' => ['announcement_at' => '2026-12-18']]);

    $titles = collect(e42_search_seasons('بحث موسم'))->pluck('title')->all();

    expect($titles)->toContain('بحث موسم ظاهر')->toContain('بحث موسم منتهٍ')->not->toContain('بحث موسم مسودة')->not->toContain('بحث موسم سري');

    $badges = collect(e42_search_seasons('بحث موسم'))->pluck('badge', 'title');
    expect($badges['بحث موسم ظاهر'])->toBe('مباشر')->and($badges['بحث موسم منتهٍ'])->toBe('انتهى');

    $discover = collect(app(\App\Services\Discovery\DiscoveryService::class)->hub(null)['sections'])->firstWhere('key', 'seasons');
    $cards = collect($discover['cards'] ?? [])->pluck('title')->all();
    expect($cards)->toContain('بحث موسم ظاهر')->not->toContain('بحث موسم مسودة')->not->toContain('بحث موسم سري');
});

test('E42-PB-09: the event calendar lists live/upcoming seasons by their explicit window, never ended, draft or unannounced ones', function () {
    $live = e42_titled('تقويم جارٍ', ['window' => ['2026-12-01 00:00:00', '2026-12-30 00:00:00']]);
    $upcoming = e42_titled('تقويم قادم', ['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00']]);
    e42_titled('تقويم منتهٍ', ['window' => ['2026-11-01', '2026-11-20']]);
    e42_titled('تقويم مسودة', ['published' => false, 'window' => ['2026-12-01', '2026-12-30']]);
    e42_titled('تقويم غير معلن', ['window' => ['2026-12-20', '2026-12-30'], 'season' => ['announcement_at' => '2026-12-18']]);

    $titles = e42_calendar_titles();

    expect($titles)->toContain('تقويم جارٍ')->toContain('تقويم قادم')
        ->not->toContain('تقويم منتهٍ')->not->toContain('تقويم مسودة')->not->toContain('تقويم غير معلن');
});

test('E42-PB-10: a campaign linked as an extra season campaign is not duplicated as a standalone calendar entry', function () {
    $season = e42_titled('تقويم موسم', ['window' => ['2026-12-01', '2026-12-30']]);
    $extra = e42_campaign(['title' => 'حملة إضافية مربوطة']);
    $solo = e42_campaign(['title' => 'حملة مستقلة']);
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);

    $titles = e42_calendar_titles(EventCalendarService::TYPE_CAMPAIGN);

    expect($titles)->toContain('حملة مستقلة')->not->toContain('حملة إضافية مربوطة');
});

test('E42-PB-11: editing season data refreshes the calendar cache (write flushes it too)', function () {
    config(['liveops.calendar.cache_seconds' => 300]);
    Cache::flush();
    $season = e42_titled('تقويم يتغيّر', ['window' => ['2026-12-01 00:00:00', '2026-12-30 00:00:00']]);
    expect(app(EventCalendarService::class)->items()->filter(fn ($i) => $i->type === EventCalendarService::TYPE_SEASON)->map(fn ($i) => $i->title)->all())->toContain('تقويم يتغيّر');

    e42_builder()->archive(e42_admin(), $season); // ينتهي الآن
    SeasonCache::flush();

    expect(app(EventCalendarService::class)->items()->filter(fn ($i) => $i->type === EventCalendarService::TYPE_SEASON)->map(fn ($i) => $i->title)->all())->not->toContain('تقويم يتغيّر');
});

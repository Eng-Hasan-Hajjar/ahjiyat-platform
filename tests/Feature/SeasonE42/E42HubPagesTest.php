<?php

use App\Models\CompetitiveEvent;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonContentItem;
use App\Models\UserPassProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

/*
| E42 / صفحة الموسم ومركزه: توافق الموسم القديم، المراحل، الإعلانات، الربط، الخصوصية، وثبات عدد الاستعلامات.
| المركز للقراءة فقط: لا يُنشئ تقدّمًا ولا يكشف بيانات آخرين.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── التوافق مع الموسم القديم ─────────────────────────────

test('E42-HB-01: a legacy season (no phases, no links, no announcements) renders without any E42 section', function () {
    $season = e42_season();

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    foreach (['مراحل الموسم', 'إعلانات الموسم', 'في هذا الموسم', 'الجدول الزمني', 'خطوتك التالية في الموسم'] as $section) {
        expect($html)->not->toContain($section);
    }
});

test('E42-HB-02: unpublished and not-yet-announced seasons are 404 for the public, previewable by an admin', function () {
    $draft = e42_live(['published' => false]);
    $hidden = e42_season(['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00'], 'season' => ['announcement_at' => '2026-12-15 00:00:00']]);
    $admin = e16User(['role' => 'admin']); // المعاينة تتبع User::isAdmin() القائم (عمود role) كما في OfficialSeasonTest

    $this->get(route('seasons.show', $draft))->assertNotFound();
    $this->get(route('seasons.show', $hidden))->assertNotFound();
    $this->actingAs(e16User())->get(route('seasons.show', $hidden))->assertNotFound();
    $this->actingAs($admin)->get(route('seasons.show', $hidden))->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-12-15 00:00:01'));
    $this->get(route('seasons.show', $hidden))->assertOk();
});

// ───────────────────────────── المراحل ─────────────────────────────

test('E42-HB-03: phases render in order with the current one marked, and the summary follows the clock', function () {
    $season = e42_live();
    e42_phase($season, 'الفصل الثاني', '2026-12-12 00:00:00', '2026-12-20 00:00:00');
    e42_phase($season, 'الفصل الأول', '2026-12-01 00:00:00', '2026-12-12 00:00:00');

    $html = $this->get(route('seasons.show', $season))->assertOk()->assertSee('مراحل الموسم')->getContent();

    expect(mb_strpos($html, 'الفصل الأول'))->toBeLessThan(mb_strpos($html, 'الفصل الثاني'))
        ->and($html)->toContain('المرحلة 1 من 2')
        ->and($html)->toContain('aria-current="step"');

    Carbon::setTestNow(Carbon::parse('2026-12-25 00:00:00'));
    $this->get(route('seasons.show', $season))->assertOk()->assertSee('اكتملت مراحل الموسم');
});

test('E42-HB-04: phase text is escaped on output even if it reached the database by another route', function () {
    $season = e42_live();
    $phase = e42_phase($season, 'نظيفة', '2026-12-01 00:00:00', '2026-12-20 00:00:00');
    DB::table('season_phases')->where('id', $phase->id)->update(['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(2)>']);
    \App\Services\Seasons\SeasonCache::flush();

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')->and($html)->not->toContain('<img src=x onerror=alert(2)>')
        ->and($html)->toContain('&lt;script&gt;');
});

// ───────────────────────────── الإعلانات ─────────────────────────────

test('E42-HB-05: only published, unexpired announcements show - drafts, scheduled and expired stay hidden; featured first; capped', function () {
    config(['seasons.hub.announcements_visible' => 2]);
    $season = e42_live();
    $admin = e42_admin();
    $b = e42_builder();

    $b->createAnnouncement($admin, $season, ['title' => 'خبر منشور قديم', 'body' => 'ن', 'published_at' => '2026-12-01 00:00:00']);
    $b->createAnnouncement($admin, $season, ['title' => 'خبر مثبت', 'body' => 'ن', 'published_at' => '2026-12-02 00:00:00', 'is_featured' => true]);
    $b->createAnnouncement($admin, $season, ['title' => 'خبر حديث', 'body' => 'ن', 'published_at' => '2026-12-09 00:00:00']);
    $b->createAnnouncement($admin, $season, ['title' => 'مسودة سرية', 'body' => 'ن']);
    $b->createAnnouncement($admin, $season, ['title' => 'مجدول لاحقًا', 'body' => 'ن', 'published_at' => '2026-12-20 00:00:00']);
    $b->createAnnouncement($admin, $season, ['title' => 'منتهي الصلاحية', 'body' => 'ن', 'published_at' => '2026-12-03 00:00:00', 'expires_at' => '2026-12-04 00:00:00']);

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    expect($html)->toContain('خبر مثبت')->toContain('خبر حديث')
        ->not->toContain('مسودة سرية')->not->toContain('مجدول لاحقًا')->not->toContain('منتهي الصلاحية')->not->toContain('خبر منشور قديم'); // السقف 2

    Carbon::setTestNow(Carbon::parse('2026-12-21 00:00:00'));
    \App\Services\Seasons\SeasonCache::flush();
    $this->get(route('seasons.show', $season))->assertOk()->assertSee('مجدول لاحقًا'); // الظهور بالزمن لا بإعادة الحفظ
});

test('E42-HB-06: announcement text is escaped on output', function () {
    $season = e42_live();
    $row = e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => 'عنوان', 'body' => 'نص', 'publish_now' => true]);
    DB::table('season_announcements')->where('id', $row->id)->update(['title' => '"><script>x()</script>', 'body' => '<b onclick=y()>جريء</b>']);
    \App\Services\Seasons\SeasonCache::flush();

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    expect($html)->not->toContain('<script>x()</script>')->and($html)->not->toContain('<b onclick=y()>');
});

// ───────────────────────────── المحتوى المرتبط والخصوصية ─────────────────────────────

test('E42-HB-07: linked campaign, competition and mission pool render for a guest without any per-user data', function () {
    $season = e42_live();
    $extra = e42_campaign(['title' => 'حملة الإضافة']);
    $event = e17Event(['title' => 'منافسة الشتاء']);
    $pool = e34_pool(e34_quests(2, ['name' => 'مهمة سرية الاسم']), ['name' => 'مجموعة الموسم']);
    $admin = e42_admin();
    e42_builder()->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);
    e42_builder()->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id);
    e42_builder()->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, $pool->id);

    $html = $this->get(route('seasons.show', $season))->assertOk()->assertSee('في هذا الموسم')->getContent();

    expect($html)->toContain('حملة الإضافة')->toContain('منافسة الشتاء')->toContain('مجموعة الموسم')
        ->and($html)->not->toContain('مهمة سرية الاسم')            // قائمة المجموعة لا تُكشف للضيف
        ->and($html)->not->toContain('تقدّمك في الموسم')
        ->and($html)->toContain('سجّل الدخول لترى مهامك');
});

test('E42-HB-08: a mission pool shows only the viewer\'s own assignments, never another user\'s', function () {
    $season = e42_live();
    $quests = e34_quests(4, []);
    $pool = e34_pool($quests, ['selection_count' => 2]);
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_MISSION_POOL, $pool->id);
    $mine = e34_user();
    $theirs = e34_user();
    e34_visible_ids($mine);   // يُسنَد له
    e34_visible_ids($theirs); // ويُسنَد لغيره

    $myAssigned = DB::table('user_mission_assignments as a')->join('quest_definitions as q', 'q.id', '=', 'a.quest_definition_id')
        ->where('a.user_id', $mine->id)->pluck('q.name')->all();
    $theirAssigned = DB::table('user_mission_assignments as a')->join('quest_definitions as q', 'q.id', '=', 'a.quest_definition_id')
        ->where('a.user_id', $theirs->id)->pluck('q.name')->all();

    $row = e42_hub()->build($season->fresh(), $mine)['missions'][0];

    expect($row['assigned'])->toHaveCount(count($myAssigned))
        ->and(collect($row['assigned'])->pluck('name')->sort()->values()->all())->toBe(collect($myAssigned)->sort()->values()->all());

    foreach (array_diff($theirAssigned, $myAssigned) as $foreign) {
        expect(json_encode($row, JSON_UNESCAPED_UNICODE))->not->toContain($foreign);
    }
});

test('E42-HB-09: draft and cancelled competitions linked to a season never show; an inactive pool never shows', function () {
    $season = e42_live();
    $admin = e42_admin();
    $draft = e17Event(['title' => 'منافسة مسودة'], CompetitiveEvent::STATUS_DRAFT);
    $live = e17Event(['title' => 'منافسة ظاهرة']);
    $cancelled = e17Event(['title' => 'منافسة ملغاة']);
    $pool = e34_pool(e34_quests(2), ['name' => 'مجموعة معطلة'], false);
    $b = e42_builder();
    $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $draft->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $live->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $cancelled->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, $pool->id);
    $cancelled->forceFill(['status' => CompetitiveEvent::STATUS_CANCELLED])->save(); // تُلغى بعد الربط

    $html = $this->get(route('seasons.show', $season))->assertOk()->getContent();

    expect($html)->toContain('منافسة ظاهرة')->not->toContain('منافسة مسودة')->not->toContain('منافسة ملغاة')->not->toContain('مجموعة معطلة');
});

test('E42-HB-10: the linked pass is read-only - no progress row is created by viewing, no price or purchase data, hidden while draft', function () {
    $season = e42_live();
    $draftPass = e40_pass(['publish' => false, 'name' => 'مسار مسودة']);
    $livePass = e40_pass(['name' => 'مسار الموسم', 'price' => 777]);
    $user = e16User();
    $admin = e42_admin();

    e42_builder()->linkPass($admin, $season, $draftPass->id);
    $this->actingAs($user)->get(route('seasons.show', $season))->assertOk()->assertDontSee('مسار مسودة');

    e42_builder()->linkPass($admin, $season, $livePass->id);
    $before = UserPassProgress::query()->count();
    $html = $this->actingAs($user)->get(route('seasons.show', $season))->assertOk()->assertSee('مسار الموسم')->getContent();

    expect(UserPassProgress::query()->count())->toBe($before)
        ->and($html)->not->toContain('777')->and($html)->not->toContain('7.77');
});

test('E42-HB-11: per-user indicators are honest (real denominators) and only for signed-in users', function () {
    $season = e42_live();
    $extra = e42_campaign();
    $event = e17Event();
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id);
    $user = e16User();

    $guest = e42_hub()->build($season->fresh(), null);
    $mine = e42_hub()->build($season->fresh(), $user);
    $labels = collect($mine['indicators'])->pluck('value', 'label');

    expect($guest['indicators'])->toBe([])
        ->and($labels['الحملات الإضافية'])->toBe('0 من 1 مكتملة')
        ->and($labels['المنافسات'])->toBe('مسجّل في 0 من 1');
});

// ───────────────────────────── الإجراء التالي ─────────────────────────────

test('E42-HB-12: next action is deterministic - primary journey first, none for guests or non-live seasons', function () {
    $season = e42_live();
    $user = e16User();

    $primary = ['percentage' => 10, 'available' => true, 'current_step_url' => 'https://example.test/step'];
    $action = e42_hub()->build($season->fresh(), $user, $primary)['next_action'];

    expect($action['url'])->toBe('https://example.test/step')
        ->and(e42_hub()->build($season->fresh(), null, $primary)['next_action'])->toBeNull();

    $ended = e42_season(['window' => ['2026-11-01 00:00:00', '2026-11-20 00:00:00']]);
    expect(e42_hub()->build($ended->fresh(), $user, $primary)['next_action'])->toBeNull();
});

// ───────────────────────────── ثبات الاستعلامات ─────────────────────────────

test('E42-HB-13: hub query count does not grow with the amount linked (no N+1) - 1 of each vs 6 of each', function () {
    $user = e16User();
    $one = e42_live();
    $many = e42_live();
    $admin = e42_admin();
    $b = e42_builder();

    $fill = function ($season, int $n) use ($b, $admin) {
        foreach (range(1, $n) as $i) {
            $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, e42_campaign()->id);
            $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, e17Event()->id);
            e42_phase($season, 'مرحلة '.$i, '2026-12-0'.$i.' 00:00:00', '2026-12-0'.$i.' 12:00:00');
            $b->createAnnouncement($admin, $season, ['title' => 'خبر '.$i, 'body' => 'ن', 'publish_now' => true]);
        }
        $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, e34_pool(e34_quests(3))->id);
    };
    $fill($one, 1);
    $fill($many, 6);

    // تسخين كاش التعريف ثم القياس عند الكاش الدافئ.
    e42_hub()->build($one->fresh(), $user);
    e42_hub()->build($many->fresh(), $user);

    $q1 = e42_queries(fn () => e42_hub()->build($one->fresh(), $user));
    $q6 = e42_queries(fn () => e42_hub()->build($many->fresh(), $user));

    expect($q6)->toBe($q1)->and($q6)->toBeLessThan(40);

    // وللضيف كذلك.
    expect(e42_queries(fn () => e42_hub()->build($many->fresh(), null)))->toBe(e42_queries(fn () => e42_hub()->build($one->fresh(), null)));
});

test('E42-HB-14: the show page is read-only - viewing writes nothing (no progress, no notifications, no assignments for a guest)', function () {
    $season = e42_live();
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_MISSION_POOL, e34_pool(e34_quests(2))->id);
    $user = e16User();

    $tables = ['user_pass_progress', 'user_mission_assignments', 'user_campaign_progress', 'user_campaign_completions', 'notifications'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => \Illuminate\Support\Facades\Schema::hasTable($t) ? DB::table($t)->count() : null]);

    $this->actingAs($user)->get(route('seasons.show', $season))->assertOk();
    $this->get(route('seasons.show', $season))->assertOk();

    foreach ($tables as $t) {
        if ($before[$t] !== null) {
            expect(DB::table($t)->count())->toBe($before[$t], $t);
        }
    }
});

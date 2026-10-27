<?php

use App\Events\SeasonStarted;
use App\Models\Campaign;
use App\Models\Season;
use App\Services\SeasonLifecycleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/_support.php';

/*
| E42 / الحالة المشتقّة للموسم: مسودة | قريبًا | مباشر | منتهٍ | مؤرشف. لا حالة مخزَّنة، والنافذة الفارغة تتبع الحملة الأساسية (السلوك القديم حرفيًا).
| مرآة SQL (publiclyVisible / ended / notEnded) يجب أن تطابق PHP على مصفوفة كاملة من التوليفات.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── مصفوفة الحالات الموثَّقة ─────────────────────────────

test('E42-LC-01: documented state matrix (legacy windowless behaviour + explicit windows)', function (array $campaign, array $season, bool $published, string $expected) {
    $s = Season::factory()->create($season + [
        'campaign_id' => Campaign::factory()->create($campaign + ['is_active' => true])->id,
        'is_published' => $published,
    ]);

    expect(e42_life()->state($s->fresh('campaign')))->toBe($expected);
})->with([
    'draft: unpublished whatever the window' => [[], ['starts_at' => '2026-12-01', 'ends_at' => '2026-12-30'], false, 'draft'],
    'legacy: active campaign with no dates is live' => [[], [], true, 'live'],
    'legacy: inactive campaign without window is scheduled (unavailable), never ended' => [['is_active' => false], [], true, 'scheduled'],
    'legacy: campaign start in the future is scheduled' => [['starts_at' => '2026-12-15'], [], true, 'scheduled'],
    'legacy: campaign ended in the past is ended' => [['ends_at' => '2026-12-01'], [], true, 'ended'],
    'legacy: inactive campaign that already ended is NOT ended (unavailable)' => [['is_active' => false, 'ends_at' => '2026-12-01'], [], true, 'scheduled'],
    'explicit window live ignores campaign is_active=false' => [['is_active' => false], ['starts_at' => '2026-12-01', 'ends_at' => '2026-12-30'], true, 'live'],
    'explicit window overrides the campaign dates' => [['ends_at' => '2026-12-01'], ['starts_at' => '2026-12-01', 'ends_at' => '2026-12-30'], true, 'live'],
    'explicit window in the future is scheduled' => [[], ['starts_at' => '2026-12-20', 'ends_at' => '2026-12-30'], true, 'scheduled'],
    'explicit window in the past is ended' => [[], ['starts_at' => '2026-11-01', 'ends_at' => '2026-11-20'], true, 'ended'],
    'start only: season start passed, campaign has no end → live' => [[], ['starts_at' => '2026-12-01'], true, 'live'],
    'end only: season ended' => [[], ['ends_at' => '2026-12-05'], true, 'ended'],
    'announcement in the future hides a live season (scheduled)' => [[], ['starts_at' => '2026-12-12', 'announcement_at' => '2026-12-11', 'ends_at' => '2026-12-30'], true, 'scheduled'],
    'announcement passed + window live → live' => [[], ['starts_at' => '2026-12-09', 'announcement_at' => '2026-12-05', 'ends_at' => '2026-12-30'], true, 'live'],
    'archive_at passed → archived' => [[], ['starts_at' => '2026-11-01', 'ends_at' => '2026-11-20', 'archive_at' => '2026-12-01'], true, 'archived'],
    'archive_at in the future keeps it ended' => [[], ['starts_at' => '2026-11-01', 'ends_at' => '2026-11-20', 'archive_at' => '2027-01-01'], true, 'ended'],
]);

test('E42-LC-02: window edges - the season is live from starts_at inclusive and until ends_at inclusive (legacy end inclusivity), ended one second later', function () {
    $s = e42_season(['window' => ['2026-12-10 12:00:00', '2026-12-10 13:00:00'], 'structure' => false]);
    $life = e42_life();

    expect($life->state($s->fresh('campaign'), Carbon::parse('2026-12-10 11:59:59')))->toBe('scheduled')
        ->and($life->state($s->fresh('campaign'), Carbon::parse('2026-12-10 12:00:00')))->toBe('live')
        ->and($life->state($s->fresh('campaign'), Carbon::parse('2026-12-10 13:00:00')))->toBe('live')
        ->and($life->state($s->fresh('campaign'), Carbon::parse('2026-12-10 13:00:01')))->toBe('ended');
});

test('E42-LC-03: state labels are Arabic and cover every state', function () {
    $life = e42_life();

    foreach ([SeasonLifecycleService::STATE_DRAFT, SeasonLifecycleService::STATE_SCHEDULED, SeasonLifecycleService::STATE_LIVE, SeasonLifecycleService::STATE_ENDED, SeasonLifecycleService::STATE_ARCHIVED] as $state) {
        expect($life->stateLabel($state))->toBeString()->not->toBe($state);
    }
});

// ───────────────────────────── مرآة SQL = PHP ─────────────────────────────

test('E42-LC-04: SQL scopes mirror the PHP state across a full combination matrix', function () {
    $campaigns = [
        'active-nodates' => ['is_active' => true],
        'inactive-nodates' => ['is_active' => false],
        'active-running' => ['is_active' => true, 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-20'],
        'active-future' => ['is_active' => true, 'starts_at' => '2026-12-20'],
        'active-ended' => ['is_active' => true, 'ends_at' => '2026-12-01'],
        'inactive-ended' => ['is_active' => false, 'ends_at' => '2026-12-01'],
        'inactive-future' => ['is_active' => false, 'starts_at' => '2026-12-20'],
    ];
    $windows = [
        'none' => [],
        'past' => ['starts_at' => '2026-11-01', 'ends_at' => '2026-11-20'],
        'live' => ['starts_at' => '2026-12-09', 'ends_at' => '2026-12-11'],
        'future' => ['starts_at' => '2026-12-20', 'ends_at' => '2026-12-30'],
        'end-past' => ['ends_at' => '2026-12-05'],
        'end-future' => ['ends_at' => '2026-12-30'],
        'start-past' => ['starts_at' => '2026-12-01'],
        'start-future' => ['starts_at' => '2026-12-25'],
        'announce-future' => ['starts_at' => '2026-12-12', 'announcement_at' => '2026-12-11', 'ends_at' => '2026-12-30'],
        'announce-past' => ['starts_at' => '2026-12-05', 'announcement_at' => '2026-12-01', 'ends_at' => '2026-12-30'],
        'archived' => ['starts_at' => '2026-11-01', 'ends_at' => '2026-11-20', 'archive_at' => '2026-12-01'],
        'archive-later' => ['starts_at' => '2026-11-01', 'ends_at' => '2026-11-20', 'archive_at' => '2027-01-01'],
        'live-archive-later' => ['starts_at' => '2026-12-01', 'ends_at' => '2026-12-30', 'archive_at' => '2027-01-15'],
    ];

    $life = e42_life();
    $visibleEnded = [];
    $visibleNotEnded = [];
    $visible = [];
    $count = 0;

    foreach ($campaigns as $cAttrs) {
        foreach ($windows as $wAttrs) {
            foreach ([true, false] as $published) {
                $campaign = Campaign::factory()->create($cAttrs);

                try {
                    // إنشاء مباشر بكود/معرّف صريحين: مصنع الموسم يولّد كودًا فريدًا من 1..99 فقط وهذه المصفوفة أكبر من ذلك.
                    $n = $count + 1;
                    $s = Season::withoutEvents(fn () => Season::query()->create($wAttrs + ['campaign_id' => $campaign->id, 'is_published' => $published, 'code' => 'M'.$n, 'slug' => 'matrix-'.$n]));
                } catch (\InvalidArgumentException) {
                    continue; // توليفة غير صالحة بحراسة النموذج نفسها
                }

                $count++;
                $state = $life->state($s->fresh('campaign'));
                $isVisible = $published && ($s->announcement_at === null || $s->announcement_at->lte(now()));

                if ($isVisible) {
                    $visible[] = $s->id;
                    in_array($state, ['ended', 'archived'], true) ? $visibleEnded[] = $s->id : $visibleNotEnded[] = $s->id;
                }
            }
        }
    }

    expect($count)->toBeGreaterThan(150);

    $ids = fn ($q) => $q->pluck('seasons.id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    sort($visible);
    sort($visibleEnded);
    sort($visibleNotEnded);

    expect($ids(Season::query()->publiclyVisible()))->toBe($visible)
        ->and($ids(Season::query()->publiclyVisible()->ended()))->toBe($visibleEnded)
        ->and($ids(Season::query()->publiclyVisible()->notEnded()))->toBe($visibleNotEnded);
});

// ───────────────────────────── بدء الموسم مرة واحدة ─────────────────────────────

test('E42-LC-05: an explicit-window season starts exactly once, at its own start time (SeasonStarted once)', function () {
    Event::fake([SeasonStarted::class]);

    $s = e42_season(['window' => ['2026-12-12 00:00:00', '2026-12-30 00:00:00'], 'campaign' => ['is_active' => false], 'structure' => false]);

    expect($s->fresh()->went_live_at)->toBeNull();
    Event::assertNotDispatched(SeasonStarted::class);

    Carbon::setTestNow(Carbon::parse('2026-12-12 00:01:00'));

    expect(e42_life()->syncLiveState($s))->toBeTrue()
        ->and(e42_life()->syncLiveState($s))->toBeFalse()
        ->and($s->fresh()->went_live_at)->not->toBeNull();
    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

test('E42-LC-06: editing a window into the past never retro-fires the start twice, and a started season cannot be deleted', function () {
    Event::fake([SeasonStarted::class]);
    $s = e42_live(['structure' => false]);

    expect($s->fresh()->went_live_at)->not->toBeNull();
    Event::assertDispatchedTimes(SeasonStarted::class, 1);

    $s->fresh()->update(['ends_at' => '2026-12-31 00:00:00']);
    e42_life()->syncLiveState($s);
    Event::assertDispatchedTimes(SeasonStarted::class, 1);

    expect(fn () => $s->fresh()->delete())->toThrow(\LogicException::class);
});

test('E42-LC-07: the model guards reject an inverted window, a late announcement, an early archive and a reserved slug', function () {
    $campaign = Campaign::factory()->create();

    expect(fn () => Season::factory()->create(['campaign_id' => $campaign->id, 'starts_at' => '2026-12-10', 'ends_at' => '2026-12-09']))->toThrow(\InvalidArgumentException::class);

    $c2 = Campaign::factory()->create();
    expect(fn () => Season::factory()->create(['campaign_id' => $c2->id, 'starts_at' => '2026-12-10', 'ends_at' => '2026-12-20', 'announcement_at' => '2026-12-11']))->toThrow(\InvalidArgumentException::class);

    $c3 = Campaign::factory()->create();
    expect(fn () => Season::factory()->create(['campaign_id' => $c3->id, 'starts_at' => '2026-12-10', 'ends_at' => '2026-12-20', 'archive_at' => '2026-12-15']))->toThrow(\InvalidArgumentException::class);

    $c4 = Campaign::factory()->create();
    expect(fn () => Season::factory()->create(['campaign_id' => $c4->id, 'slug' => 'archive']))->toThrow(\InvalidArgumentException::class);
});

test('E42-LC-08: isLive keeps its legacy contract (visible, not archived, availability live)', function () {
    $live = e42_season(['structure' => false]);
    $draft = e42_season(['published' => false, 'structure' => false]);
    $archived = e42_season(['window' => ['2026-11-01', '2026-11-20'], 'season' => ['archive_at' => '2026-12-01'], 'structure' => false]);

    expect(e42_life()->isLive($live->fresh('campaign')))->toBeTrue()
        ->and(e42_life()->isLive($draft->fresh('campaign')))->toBeFalse()
        ->and(e42_life()->isLive($archived->fresh('campaign')))->toBeFalse();
});

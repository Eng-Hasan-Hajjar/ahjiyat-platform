<?php

use App\Jobs\DispatchSeasonAnnouncementChunk;
use App\Jobs\DispatchSeasonPhaseStartedChunk;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonPhase;
use App\Services\Notifications\NotificationType;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonProcessor;
use App\Services\SeasonLifecycleService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E42 / المعالج المجدول (seasons:process): بدء المراحل، الإعلانات المطلوب إشعارها، تجميد النافذة. idempotent وذري، بلا بثّ جماعي، والطابور sync في الاختبار.
*/

beforeEach(function () {
    e42_seed();
    config(['queue.default' => 'sync']);
});
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── بدء المراحل ─────────────────────────────

test('E42-PR-01: a phase that starts notifies the primary campaign participants once; the first phase and stale phases stay silent', function () {
    $season = e42_live();
    $first = e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
    $second = e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00'); // بدأت قبل 30 دقيقة
    $joined = e42_join($season);
    $stranger = e16User(['email_verified_at' => now()]);

    $stats = e42_processor()->run();

    expect($stats['phases_started'])->toBe(1)->and($stats['phases_silent'])->toBe(1)
        ->and(e42_notes('season_phase_started', $joined))->toHaveCount(1)
        ->and(e42_notes('season_phase_started', $stranger))->toHaveCount(0)
        ->and($first->fresh()->went_live_at)->not->toBeNull()
        ->and($second->fresh()->went_live_at)->not->toBeNull();

    $row = e42_notes('season_phase_started', $joined)->first();
    expect($row->idempotency_key)->toBe("season-phase:{$second->id}:{$joined->id}")
        ->and($row->data['title'])->toContain('الثانية')
        ->and($row->data['action_route'])->toBe('seasons.show');

    // تكرار التشغيل: لا شيء جديد.
    $again = e42_processor()->run();
    expect(array_sum($again))->toBe(0)->and(e42_notes('season_phase_started'))->toHaveCount(1);
});

test('E42-PR-02: a future phase is not claimed early; it fires exactly when the clock reaches it', function () {
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 00:00:00');
    $later = e42_phase($season, 'القادمة', '2026-12-12 00:00:00', '2026-12-20 00:00:00');
    $joined = e42_join($season);

    e42_processor()->run();
    expect($later->fresh()->went_live_at)->toBeNull()->and(e42_notes('season_phase_started', $joined))->toHaveCount(0);

    Carbon::setTestNow(Carbon::parse('2026-12-12 00:05:00'));
    $stats = e42_processor()->run();

    expect($stats['phases_started'])->toBe(1)
        ->and(e42_notes('season_phase_started', $joined))->toHaveCount(1)
        ->and(array_sum(e42_processor()->run()))->toBe(0);
});

test('E42-PR-03: a stale phase (back-filled or enabled late) is marked processed silently - no flood', function () {
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-02 00:00:00');
    $old = e42_phase($season, 'قديمة', '2026-12-03 00:00:00', '2026-12-20 00:00:00'); // بدأت قبل أكثر من 24 ساعة
    $joined = e42_join($season);

    $stats = e42_processor()->run();

    expect($stats['phases_started'])->toBe(0)->and($stats['phases_silent'])->toBe(2)
        ->and($old->fresh()->went_live_at)->not->toBeNull()
        ->and(e42_notes('season_phase_started', $joined))->toHaveCount(0);
});

test('E42-PR-04: phases of draft, unannounced, upcoming and ended seasons never notify', function () {
    $draft = e42_live(['published' => false]);
    $ended = e42_season(['window' => ['2026-11-01 00:00:00', '2026-12-01 00:00:00']]);
    $upcoming = e42_season(['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00']]);
    $hidden = e42_season(['window' => ['2026-12-12 00:00:00', '2026-12-30 00:00:00'], 'season' => ['announcement_at' => '2026-12-11 00:00:00']]);

    foreach ([[$draft, '2026-12-10 11:30:00', '2026-12-20 00:00:00'], [$ended, '2026-11-20 00:00:00', '2026-11-30 00:00:00'], [$upcoming, '2026-12-21 00:00:00', '2026-12-25 00:00:00'], [$hidden, '2026-12-13 00:00:00', '2026-12-20 00:00:00']] as [$s, $a, $b]) {
        e42_phase($s, 'م', $a, $b);
        e42_join($s);
    }

    e42_processor()->run();

    expect(e42_notes('season_phase_started'))->toHaveCount(0)
        ->and(SeasonPhase::query()->whereHas('season', fn ($q) => $q->whereIn('id', [$draft->id, $upcoming->id, $hidden->id]))->whereNotNull('went_live_at')->count())->toBe(0);
});

test('E42-PR-05: unverified and frozen users are never notified; the job is replay-safe (semantic idempotency key)', function () {
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
    $second = e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00');
    $ok = e42_join($season);
    e42_join($season, ['email_verified_at' => null]);
    e42_join($season, ['is_frozen' => true]);

    e42_processor()->run();
    DispatchSeasonPhaseStartedChunk::dispatchSync($second->id);
    DispatchSeasonPhaseStartedChunk::dispatchSync($second->id);

    expect(e42_notes('season_phase_started'))->toHaveCount(1)->and(e42_notes('season_phase_started', $ok))->toHaveCount(1);
});

test('E42-PR-06: the chunked job reaches every participant exactly once across chunks', function () {
    config(['player_notifications.chunk_size' => 2]);
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
    e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00');
    $users = collect(range(1, 5))->map(fn () => e42_join($season));

    e42_processor()->run();

    expect(e42_notes('season_phase_started'))->toHaveCount(5)
        ->and(e42_notes('season_phase_started')->pluck('notifiable_id')->unique()->count())->toBe(5)
        ->and($users->every(fn ($u) => e42_notes('season_phase_started', $u)->count() === 1))->toBeTrue();
});

test('E42-PR-07: the job refuses an unclaimed phase and a season that is no longer live', function () {
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
    $second = e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00');
    e42_join($season);

    DispatchSeasonPhaseStartedChunk::dispatchSync($second->id); // لم تُحجَز بعد
    expect(e42_notes('season_phase_started'))->toHaveCount(0);

    SeasonPhase::query()->whereKey($second->id)->update(['went_live_at' => now()]);
    Season::query()->whereKey($season->id)->update(['is_published' => false]);
    DispatchSeasonPhaseStartedChunk::dispatchSync($second->id);

    expect(e42_notes('season_phase_started'))->toHaveCount(0);
});

// ───────────────────────────── الإعلانات ─────────────────────────────

test('E42-PR-08: an announcement with an explicit notify decision notifies participants once; audience "all" reaches verified non-participants too', function () {
    $season = e42_live();
    $admin = e42_admin();
    $mine = e42_join($season);
    $stranger = e16User(['email_verified_at' => now()]);
    $b = e42_builder();

    $b->createAnnouncement($admin, $season, ['title' => 'للمشاركين', 'body' => 'ن', 'published_at' => '2026-12-10 11:30:00', 'notify_audience' => 'participants']);
    $b->createAnnouncement($admin, $season, ['title' => 'للجميع', 'body' => 'ن', 'published_at' => '2026-12-10 11:45:00', 'notify_audience' => 'all']);
    $b->createAnnouncement($admin, $season, ['title' => 'بلا إشعار', 'body' => 'ن', 'published_at' => '2026-12-10 11:00:00']);

    $stats = e42_processor()->run();

    expect($stats['announcements_sent'])->toBe(2)
        ->and(e42_notes('season_announcement_published', $mine))->toHaveCount(2)
        ->and(e42_notes('season_announcement_published', $stranger))->toHaveCount(1)
        ->and(e42_notes('season_announcement_published', $stranger)->first()->data['body'])->toContain('للجميع')
        ->and(SeasonAnnouncement::query()->whereNull('notify_audience')->whereNotNull('notified_at')->count())->toBe(0);

    // الإداري نفسه مستخدم موثَّق فيصله إشعار «للجميع» أيضًا: 2 (مشارك) + 1 (غريب) + 1 (الإداري).
    expect(array_sum(e42_processor()->run()))->toBe(0)->and(e42_notes('season_announcement_published'))->toHaveCount(4);
});

test('E42-PR-09: drafts, scheduled, stale and expired announcements never send a live notification', function () {
    $season = e42_live();
    $admin = e42_admin();
    $user = e42_join($season);
    $b = e42_builder();

    $b->createAnnouncement($admin, $season, ['title' => 'مجدول', 'body' => 'ن', 'published_at' => '2026-12-15 00:00:00', 'notify_audience' => 'all']);
    $b->createAnnouncement($admin, $season, ['title' => 'قديم', 'body' => 'ن', 'published_at' => '2026-12-05 00:00:00', 'notify_audience' => 'all']);
    $b->createAnnouncement($admin, $season, ['title' => 'منتهٍ', 'body' => 'ن', 'published_at' => '2026-12-10 10:00:00', 'expires_at' => '2026-12-10 11:00:00', 'notify_audience' => 'all']);

    $stats = e42_processor()->run();

    expect($stats['announcements_sent'])->toBe(0)->and($stats['announcements_silent'])->toBe(2)
        ->and(e42_notes('season_announcement_published', $user))->toHaveCount(0);

    Carbon::setTestNow(Carbon::parse('2026-12-15 00:10:00'));
    expect(e42_processor()->run()['announcements_sent'])->toBe(1)
        ->and(e42_notes('season_announcement_published', $user))->toHaveCount(1);
});

test('E42-PR-10: announcement job honours the atomic claim, the season state and replays without duplicates', function () {
    $season = e42_live();
    $row = e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => 'خبر', 'body' => 'ن', 'published_at' => '2026-12-10 11:30:00', 'notify_audience' => 'participants']);
    e42_join($season);

    DispatchSeasonAnnouncementChunk::dispatchSync($row->id); // لم يُحجَز
    expect(e42_notes('season_announcement_published'))->toHaveCount(0);

    e42_processor()->run();
    DispatchSeasonAnnouncementChunk::dispatchSync($row->id);
    DispatchSeasonAnnouncementChunk::dispatchSync($row->id);
    expect(e42_notes('season_announcement_published'))->toHaveCount(1);
});

test('E42-PR-11: notification body is plain text (title excerpt) and the center resolves seasons.show', function () {
    $season = e42_live();
    e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => 'عنوان الإعلان الجديد', 'body' => 'ن', 'published_at' => '2026-12-10 11:30:00', 'notify_audience' => 'participants']);
    $user = e42_join($season);

    e42_processor()->run();
    $row = e42_notes('season_announcement_published', $user)->first();

    expect($row->data['body'])->toBe('عنوان الإعلان الجديد')
        ->and(app(\App\Services\Notifications\NotificationUrlResolver::class)->resolve($row->data))->toBe('/seasons/'.$season->slug)
        ->and(NotificationType::SeasonAnnouncementPublished->category()->value)->toBe('season');
});

// ───────────────────────────── تجميد النافذة والأمر المجدول ─────────────────────────────

test('E42-PR-12: reaching archive_at freezes the effective window once', function () {
    $season = e42_season(['campaign' => ['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-20 00:00:00']]);
    Season::withoutEvents(fn () => $season->forceFill(['archive_at' => '2026-12-01 00:00:00'])->saveQuietly());

    $stats = e42_processor()->run();
    expect($stats['windows_frozen'])->toBe(1)->and($season->fresh()->ends_at->toDateString())->toBe('2026-11-20');

    expect(e42_processor()->run()['windows_frozen'])->toBe(0);
});

test('E42-PR-13: one failing item never stops the rest (isolated failure, counted)', function () {
    $good = e42_live();
    $bad = e42_live();
    foreach ([$good, $bad] as $s) {
        e42_phase($s, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
        e42_phase($s, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00');
        e42_join($s);
    }

    $lifecycle = new class(app(\App\Services\CampaignProgressService::class), $bad->id) extends SeasonLifecycleService
    {
        public function __construct(\App\Services\CampaignProgressService $c, protected int $explodeFor)
        {
            parent::__construct($c);
        }

        public function state(Season $season, ?\Carbon\CarbonInterface $now = null): string
        {
            if ($season->id === $this->explodeFor) {
                throw new RuntimeException('boom');
            }

            return parent::state($season, $now);
        }
    };

    $stats = (new SeasonProcessor($lifecycle, app(SeasonBuilderService::class)))->run();

    expect($stats['failed'])->toBeGreaterThanOrEqual(1)->and($stats['phases_started'])->toBe(1)
        ->and(e42_notes('season_phase_started'))->toHaveCount(1);
});

test('E42-PR-14: seasons:process runs from the command line, reports counts and is scheduled every five minutes', function () {
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
    e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00');
    e42_join($season);

    expect(Artisan::call('seasons:process'))->toBe(0)
        ->and(Artisan::output())->toContain('مراحل بدأت وأُشعر بها: 1');

    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'seasons:process'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/5 * * * *');
});

test('E42-PR-15: processing grants nothing - no XP, currency, wallet, quest, streak or pass writes', function () {
    $season = e42_live();
    e42_phase($season, 'الأولى', '2026-11-30 00:00:00', '2026-12-10 11:00:00');
    e42_phase($season, 'الثانية', '2026-12-10 11:30:00', '2026-12-20 00:00:00');
    e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => 'خبر', 'body' => 'ن', 'published_at' => '2026-12-10 11:40:00', 'notify_audience' => 'all']);
    e42_join($season);

    $tables = ['xp_transactions', 'currency_transactions', 'wallets', 'user_quest_progress', 'player_streaks', 'user_pass_progress', 'pass_point_transactions', 'user_campaign_completions', 'store_purchases'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => \Illuminate\Support\Facades\Schema::hasTable($t) ? DB::table($t)->count() : null]);

    e42_processor()->run();

    foreach ($tables as $t) {
        if ($before[$t] !== null) {
            expect(DB::table($t)->count())->toBe($before[$t], $t);
        }
    }
});

// ───────────────────────────── تذكير «ينتهي قريبًا» لموسم بنهاية صريحة ─────────────────────────────

test('E42-PR-16: a season with an explicit end reminds by ITS end (once, season type) and a campaign-end reminder is not duplicated', function () {
    config(['player_notifications.ending_soon_window_hours' => 24]);
    $season = e42_season(['window' => ['2026-12-01 00:00:00', '2026-12-10 20:00:00'], 'campaign' => ['ends_at' => '2027-03-01 00:00:00']]);
    $user = e42_join($season);

    Artisan::call('notifications:dispatch-lifecycle-reminders');
    Artisan::call('notifications:dispatch-lifecycle-reminders');

    expect(e42_notes('season_ending_soon', $user))->toHaveCount(1)
        ->and(e42_notes('campaign_ending_soon', $user))->toHaveCount(0)
        ->and(e42_notes('season_ending_soon', $user)->first()->idempotency_key)->toBe("season-ending-soon:{$user->id}:{$season->id}");
});

test('E42-PR-17: a campaign ending inside a LATER explicit season window is reminded as a campaign ending, never as the season ending', function () {
    config(['player_notifications.ending_soon_window_hours' => 24]);
    $season = e42_season(['window' => ['2026-12-01 00:00:00', '2027-01-20 00:00:00'], 'campaign' => ['ends_at' => '2026-12-10 20:00:00']]);
    $user = e42_join($season);

    Artisan::call('notifications:dispatch-lifecycle-reminders');

    expect(e42_notes('season_ending_soon', $user))->toHaveCount(0)->and(e42_notes('campaign_ending_soon', $user))->toHaveCount(1);
});

<?php

use App\Models\CompetitiveEventParticipant;
use App\Models\User;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\LiveOpsReminderService;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\PlatformSettingsService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 21:30:00', 'UTC')); // الأربعاء، داخل آخر 4 ساعات من اليوم
});
afterEach(fn () => Carbon::setTestNow());

function e34_notes(User $u, NotificationType $t): int
{
    return DatabaseNotification::query()->where('notifiable_id', $u->id)->where('type_key', $t->value)->count();
}

function e34_reminders(): LiveOpsReminderService
{
    return app(LiveOpsReminderService::class);
}

/** تقدّم مفتوح (بدأه ولم يكمله) على مهمة يومية. */
function e34_open_progress(User $u, int $current = 1, int $target = 3): void
{
    $quest = e34_quest(['target_value' => $target]);
    $p = app(QuestService::class)->progressFor($u, $quest, app(QuestPeriodService::class)->dailyContext());
    \App\Models\UserQuestProgress::query()->whereKey($p->id)->update(['current_value' => $current]);
}

test('Z7-01: registry integrity - four new types, no new category, none of them a re-engagement type, calm copy without pressure', function () {
    $new = [NotificationType::MissionExpiring, NotificationType::WeeklyMissionsAvailable, NotificationType::EventStartingSoon, NotificationType::ComebackAvailable];

    expect(count(NotificationCategory::cases()))->toBe(8)->and(count(NotificationCategory::optional()))->toBe(7)
        ->and(NotificationType::reEngagementKeys())->toEqualCanonicalizing(['streak_at_risk', 'daily_quests_available', 'season_ending_soon', 'campaign_ending_soon', 'competitive_event_ending_soon']);

    foreach ($new as $t) {
        expect($t->isReEngagement())->toBeFalse()->and($t->category())->toBeInstanceOf(NotificationCategory::class)->and($t->icon())->not->toBe('');
        $text = $t->title(['count' => 2, 'title' => 'حدث', 'hours' => 5]).' '.$t->body(['count' => 2, 'title' => 'حدث', 'hours' => 5]);
        foreach (['اشترِ', 'ستفقد', 'باقي دقائق', 'آخر فرصة', 'سارع', 'لا تفوّت'] as $bad) {
            expect($text)->not->toContain($bad);
        }
    }
    expect(LiveOpsReminderService::typeKeys())->toBe(array_map(fn ($t) => $t->value, $new));
});

test('Z7-02: mission-expiring reaches only players with an in-progress mission, only in the last hours of the local day', function () {
    $started = e34_user();
    e34_open_progress($started);
    $notStarted = e34_user();
    $done = e34_user();
    $q = e34_quest();
    $p = app(QuestService::class)->progressFor($done, $q, app(QuestPeriodService::class)->dailyContext());
    \App\Models\UserQuestProgress::query()->whereKey($p->id)->update(['current_value' => 1, 'completed_at' => now(), 'reward_granted_at' => now()]);

    $stats = e34_reminders()->missionExpiring();

    expect($stats['created'])->toBe(1)->and(e34_notes($started, NotificationType::MissionExpiring))->toBe(1)
        ->and(e34_notes($notStarted, NotificationType::MissionExpiring))->toBe(0)->and(e34_notes($done, NotificationType::MissionExpiring))->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
    expect(e34_reminders()->missionExpiring())->toMatchArray(['skipped' => 'before_window', 'created' => 0]);
});

test('Z7-03: reruns are idempotent - the same player is never notified twice for the same period', function () {
    $u = e34_user();
    e34_open_progress($u);

    $first = e34_reminders()->missionExpiring();
    $second = e34_reminders()->missionExpiring();
    $third = e34_reminders()->run()['mission_expiring'];

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)->and($second['already'])->toBe(1)->and($third['created'])->toBe(0)
        ->and(e34_notes($u, NotificationType::MissionExpiring))->toBe(1);
});

test('Z7-04: category preferences and the global switch are respected; frozen and unverified accounts are skipped', function () {
    $optedOut = e34_user();
    e34_open_progress($optedOut);
    app(NotificationPreferenceService::class)->update($optedOut, ['quest_enabled' => false]);

    $frozen = e34_user(['is_frozen' => true]);
    e34_open_progress($frozen);
    $unverified = e34_user(['email_verified_at' => null]);
    e34_open_progress($unverified);

    $stats = e34_reminders()->missionExpiring();

    expect($stats['created'])->toBe(0)->and($stats['suppressed'])->toBe(1)->and($stats['ineligible'])->toBe(2)
        ->and(DatabaseNotification::query()->count())->toBe(0);

    $ok = e34_user();
    e34_open_progress($ok);
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    expect(e34_reminders()->missionExpiring())->toMatchArray(['skipped' => 'disabled', 'created' => 0]);
});

test('Z7-05: the LiveOps daily cap holds across types for one player', function () {
    $u = e34_user();
    e34_open_progress($u);
    $event = e17Event(['title' => 'منافسة الليلة', 'starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(9)]);
    CompetitiveEventParticipant::query()->create(['competitive_event_id' => $event->id, 'user_id' => $u->id, 'status' => 'registered', 'registered_at' => now()]);

    $a = e34_reminders()->missionExpiring();
    $b = e34_reminders()->eventStarting();

    expect($a['created'])->toBe(1)->and($b['created'])->toBe(0)->and($b['over_budget'])->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $u->id)->count())->toBe(1);

    config(['liveops.notifications.daily_cap' => 2]);
    expect(e34_reminders()->eventStarting()['created'])->toBe(1);
});

test('Z7-06: event-starting-soon goes only to registered players of a published event starting inside the window, once per event', function () {
    $registered = e34_user();
    $stranger = e34_user();
    $soon = e17Event(['title' => 'تبدأ قريبًا', 'starts_at' => now()->addHours(6), 'ends_at' => now()->addHours(10)]);
    $far = e17Event(['title' => 'بعد يومين', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]);
    $draft = e17Event(['title' => 'مسودة', 'starts_at' => now()->addHours(6), 'ends_at' => now()->addHours(9)], \App\Models\CompetitiveEvent::STATUS_DRAFT);

    foreach ([$soon, $far, $draft] as $e) {
        CompetitiveEventParticipant::query()->create(['competitive_event_id' => $e->id, 'user_id' => $registered->id, 'status' => 'registered', 'registered_at' => now()]);
    }

    $first = e34_reminders()->eventStarting();
    $second = e34_reminders()->eventStarting();

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)
        ->and(e34_notes($registered, NotificationType::EventStartingSoon))->toBe(1)->and(e34_notes($stranger, NotificationType::EventStartingSoon))->toBe(0);
});

test('Z7-07: comeback notices are off by default, then go once per absence to players inside the allowed range', function () {
    $in = e34_user();
    e34_streak($in, 2, 4, now()->subDays(10)->toDateString());
    $tooFresh = e34_user();
    e34_streak($tooFresh, 2, 4, now()->subDays(3)->toDateString());
    $tooOld = e34_user();
    e34_streak($tooOld, 2, 4, now()->subDays(80)->toDateString());

    expect(e34_reminders()->comeback())->toMatchArray(['skipped' => 'disabled', 'created' => 0]);

    config(['liveops.notifications.comeback_available' => true]);
    $first = e34_reminders()->comeback();
    $second = e34_reminders()->comeback();

    expect($first['created'])->toBe(1)->and($second['created'])->toBe(0)
        ->and(e34_notes($in, NotificationType::ComebackAvailable))->toBe(1)
        ->and(e34_notes($tooFresh, NotificationType::ComebackAvailable))->toBe(0)->and(e34_notes($tooOld, NotificationType::ComebackAvailable))->toBe(0);
});

test('Z7-08: weekly missions are announced only on the platform week start, only to recently active players, and are off by default', function () {
    e34_weekly();
    $active = e34_user();
    e34_streak($active, 2, 2, now()->toDateString());
    $lapsed = e34_user();
    e34_streak($lapsed, 2, 2, now()->subDays(30)->toDateString());

    expect(e34_reminders()->weeklyMissions())->toMatchArray(['skipped' => 'disabled']);

    config(['liveops.notifications.weekly_missions_available' => true]);
    expect(e34_reminders()->weeklyMissions())->toMatchArray(['skipped' => 'not_week_start', 'created' => 0]); // الأربعاء

    Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00', 'UTC')); // الإثنين
    e34_streak($active, 2, 2, now()->toDateString());
    $stats = e34_reminders()->weeklyMissions();

    expect($stats['created'])->toBe(1)->and(e34_notes($active, NotificationType::WeeklyMissionsAvailable))->toBe(1)->and(e34_notes($lapsed, NotificationType::WeeklyMissionsAvailable))->toBe(0);
});

test('Z7-09: delivery is chunked - every eligible player is reached exactly once however small the chunk', function () {
    config(['liveops.notifications.chunk_size' => 2]);
    $users = collect(range(1, 5))->map(function () {
        $u = e34_user();
        e34_open_progress($u);

        return $u;
    });

    $stats = e34_reminders()->missionExpiring();

    expect($stats['created'])->toBe(5)->and($stats['candidates'])->toBe(5);
    foreach ($users as $u) {
        expect(e34_notes($u, NotificationType::MissionExpiring))->toBe(1);
    }
});

test('Z7-10: the command is registered on an hourly schedule and runs cleanly', function () {
    expect(Artisan::call('liveops:remind'))->toBe(0)->and(Artisan::output())->toContain('mission_expiring')->toContain('comeback');

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('liveops:remind');
});

test('Z7-11: one failing notification never stops the rest of the batch', function () {
    $a = e34_user();
    $b = e34_user();
    e34_open_progress($a);
    e34_open_progress($b);

    $calls = 0;
    $this->mock(\App\Services\Notifications\NotificationDispatcher::class, function ($m) use (&$calls) {
        $m->shouldReceive('dispatch')->andReturnUsing(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('boom');
            }

            return \App\Services\Notifications\DispatchResult::Created;
        });
    });

    $stats = e34_reminders()->missionExpiring();

    expect($calls)->toBe(2)->and($stats['failed'])->toBe(1)->and($stats['created'])->toBe(1);
});

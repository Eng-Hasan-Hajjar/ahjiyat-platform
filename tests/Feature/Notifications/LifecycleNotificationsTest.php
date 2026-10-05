<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Events\CampaignCompletedForUser;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\PlayerStreak;
use App\Models\Season;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\UserQuestProgress;
use App\Services\CampaignNarrativeService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\Notifications\ReEngagementPolicy;
use App\Services\Notifications\ReEngagementService;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
    $this->settings = app(PlatformSettingsService::class);
    config(['player_notifications.ending_soon_window_hours' => 24]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * حملة بمرحلة وبوابة وخطوتَي سرد، بلا أحداث النموذج (الإتاحة/البدء ميزتان مستقلتان لا تلوّثان هذه الاختبارات).
 * $mode: standalone = بلا موسم | season = موسم منشور | draft = موسم غير منشور. ends_at الافتراضي بعد 10 ساعات (داخل النافذة).
 */
function e15lMake(string $mode = 'standalone', array $campaign = []): array
{
    $c = Campaign::withoutEvents(fn () => Campaign::factory()->create($campaign + [
        'is_active' => true, 'slug' => 'lc-'.Str::lower(Str::random(6)), 'title' => 'حملة '.Str::random(4), 'ends_at' => now()->addHours(10),
    ]));
    $stage = CampaignStage::factory()->create(['campaign_id' => $c->id, 'sort_order' => 1]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);
    $steps = [
        CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 1]),
        CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => 2]),
    ];
    $season = $mode === 'standalone' ? null : Season::withoutEvents(fn () => Season::factory()->create([
        'campaign_id' => $c->id, 'slug' => 'ls-'.Str::lower(Str::random(6)), 'is_published' => $mode === 'season',
    ]));

    return [$c, $season, $steps];
}

/** تقدّم فعلي لخطوة واحدة بلا المرور بنقطة الإكمال (لا إكمال حملة ولا إشعار إكمال). */
function e15lProgress(User $user, CampaignStep $step): void
{
    UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now(), 'completed_at' => now()]);
}

/** إكمال حقيقي عبر خدمة السرد (يمرّ بنقطة الإكمال المركزية فيُسجَّل الإكمال ويُطلَق الحدث). */
function e15lComplete(User $user, array $steps): void
{
    foreach ($steps as $step) {
        app(CampaignNarrativeService::class)->complete($user, $step);
    }
}

function e15lRun(): void
{
    Artisan::call('notifications:dispatch-lifecycle-reminders');
}

function e15lNotes(string $type, ?User $user = null)
{
    return DatabaseNotification::where('type_key', $type)->when($user, fn ($q) => $q->where('notifiable_id', $user->id))->get();
}

function e15lSnapshot(): array
{
    return [
        DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count(), UserQuestProgress::count(), PlayerStreak::count(),
        StorePurchase::count(), UserCampaignProgress::count(), DB::table('user_campaign_completions')->count(), DB::table('user_stage_unlocks')->count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
    ];
}

// ============================ A: دلالات إشعار إكمال الموسم ============================

test('A1: a standalone campaign completion sends campaign_completed only', function () {
    [$c, , $steps] = e15lMake('standalone');
    $user = User::factory()->create();

    e15lComplete($user, $steps);
    $row = e15lNotes('campaign_completed', $user)->first();

    expect(e15lNotes('campaign_completed', $user))->toHaveCount(1)->and(e15lNotes('season_completed', $user))->toHaveCount(0)
        ->and($row->idempotency_key)->toBe("campaign-completed:{$user->id}:{$c->id}")
        ->and($row->category)->toBe('campaign')
        ->and($row->data['title'])->toContain('أكملت الحملة بنجاح')->toContain($c->title)
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/campaigns/'.$c->slug);
});

test('A2/A3: a campaign bound to a PUBLISHED season sends season_completed instead - one notification, never both', function () {
    [$c, $season, $steps] = e15lMake('season');
    $user = User::factory()->create();

    e15lComplete($user, $steps);
    $row = e15lNotes('season_completed', $user)->first();

    expect(e15lNotes('season_completed', $user))->toHaveCount(1)->and(e15lNotes('campaign_completed', $user))->toHaveCount(0)
        ->and(DatabaseNotification::where('notifiable_id', $user->id)->whereIn('type_key', ['season_completed', 'campaign_completed'])->count())->toBe(1)
        ->and($row->idempotency_key)->toBe("season-completed:{$user->id}:{$season->id}")
        ->and($row->category)->toBe('season')
        ->and($row->data['title'])->toContain('أكملت الموسم بنجاح')->toContain($c->title)
        ->and($row->data['action_route'])->toBe('seasons.show')
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/seasons/'.$season->slug);
});

test('A4: with a published season the SEASON preference decides - off means neither notification, no campaign fallback', function () {
    [, , $steps] = e15lMake('season');
    $off = User::factory()->create();
    $campaignOff = User::factory()->create();
    app(NotificationPreferenceService::class)->update($off, ['season_enabled' => false]);
    app(NotificationPreferenceService::class)->update($campaignOff, ['campaign_enabled' => false]);

    e15lComplete($off, $steps);
    e15lComplete($campaignOff, $steps);

    expect(DatabaseNotification::where('notifiable_id', $off->id)->whereIn('type_key', ['season_completed', 'campaign_completed'])->count())->toBe(0)
        ->and(e15lNotes('season_completed', $campaignOff))->toHaveCount(1); // تفضيل الحملات لا يخصّ إشعار الموسم
});

test('A5: a campaign whose season is NOT published is a plain campaign for the player - campaign_completed', function () {
    [$c, , $steps] = e15lMake('draft');
    $user = User::factory()->create();

    e15lComplete($user, $steps);
    $row = e15lNotes('campaign_completed', $user)->first();

    expect(e15lNotes('campaign_completed', $user))->toHaveCount(1)->and(e15lNotes('season_completed', $user))->toHaveCount(0)
        ->and($row->data['action_route'])->toBe('campaigns.show')
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/campaigns/'.$c->slug);
});

test('A6: retrying CampaignCompletedForUser never duplicates either notification', function (string $mode) {
    [$c, , $steps] = e15lMake($mode);
    $user = User::factory()->create();
    e15lComplete($user, $steps);

    for ($i = 0; $i < 3; $i++) {
        event(new CampaignCompletedForUser($user->id, $c->id));
    }

    expect(DatabaseNotification::where('notifiable_id', $user->id)->whereIn('type_key', ['season_completed', 'campaign_completed'])->count())->toBe(1);
})->with(['standalone' => ['standalone'], 'published season' => ['season']]);

test('A7: a failing notification pipeline never breaks the campaign completion (season variant too)', function () {
    [$c, , $steps] = e15lMake('season');
    $user = User::factory()->create();
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));

    e15lComplete($user, $steps);

    expect(DB::table('user_campaign_completions')->where('user_id', $user->id)->where('campaign_id', $c->id)->exists())->toBeTrue()
        ->and(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(2)
        ->and(DatabaseNotification::where('notifiable_id', $user->id)->count())->toBe(0);
});

test('A8: the decision lives in the notification listener - CampaignCompletionService is untouched by seasons', function () {
    $service = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path('Services/CampaignCompletionService.php')));

    expect(stripos($service, 'season'))->toBeFalse()->and(str_contains($service, 'NotificationType'))->toBeFalse();
});

// ============================ B: Season ending soon ============================

test('B7: a published season with a user who has real progress, has not completed, inside the window -> season_ending_soon', function () {
    [$c, $season, $steps] = e15lMake('season');
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);

    e15lRun();
    $row = e15lNotes('season_ending_soon', $user)->first();

    expect(e15lNotes('season_ending_soon', $user))->toHaveCount(1)->and(e15lNotes('campaign_ending_soon', $user))->toHaveCount(0)
        ->and($row->idempotency_key)->toBe("season-ending-soon:{$user->id}:{$season->id}")
        ->and($row->category)->toBe('season')
        ->and($row->data['title'])->toContain('ينتهي الموسم قريبًا')->toContain($c->title)
        ->and($row->data['body'])->toContain('24')
        ->and($row->data['action_route'])->toBe('seasons.show')
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/seasons/'.$season->slug);
});

test('B8/C6: no mass notification - a user without real progress, an unverified user and a frozen user get nothing', function (string $mode, string $type) {
    [, , $steps] = e15lMake($mode);
    $none = User::factory()->create();
    $unverified = User::factory()->create(['email_verified_at' => null]);
    $frozen = User::factory()->create(['is_frozen' => true]);
    $startedOnly = User::factory()->create();
    UserCampaignProgress::create(['user_id' => $startedOnly->id, 'campaign_step_id' => $steps[0]->id, 'started_at' => now(), 'completed_at' => null]); // بدأ ولم يُكمل: ليس تقدّمًا فعليًا
    e15lProgress($unverified, $steps[0]);
    e15lProgress($frozen, $steps[0]);

    e15lRun();

    expect(e15lNotes($type))->toHaveCount(0)->and(DatabaseNotification::where('notifiable_id', $none->id)->count())->toBe(0);
})->with([
    'season' => ['season', 'season_ending_soon'],
    'standalone campaign' => ['standalone', 'campaign_ending_soon'],
]);

test('B9/C18: a user who already completed the campaign is never reminded (the others still are)', function (string $mode, string $type) {
    [, , $steps] = e15lMake($mode);
    [$done, $pending] = [User::factory()->create(), User::factory()->create()];
    e15lComplete($done, $steps);
    e15lProgress($pending, $steps[0]);

    e15lRun();

    expect(e15lNotes($type, $done))->toHaveCount(0)->and(e15lNotes($type, $pending))->toHaveCount(1);
})->with([
    'season' => ['season', 'season_ending_soon'],
    'standalone campaign' => ['standalone', 'campaign_ending_soon'],
]);

test('B10/C16/C17: exactly ONE ending-soon type per campaign - published season: season only; standalone or draft season: campaign only', function (string $mode, ?string $expected) {
    [, , $steps] = e15lMake($mode);
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);

    e15lRun();

    $types = DatabaseNotification::where('notifiable_id', $user->id)->whereIn('type_key', ['season_ending_soon', 'campaign_ending_soon'])->pluck('type_key')->all();
    expect($types)->toBe([$expected]);
})->with([
    'published season -> season_ending_soon only' => ['season', 'season_ending_soon'],
    'draft season -> campaign_ending_soon only' => ['draft', 'campaign_ending_soon'],
    'standalone -> campaign_ending_soon only' => ['standalone', 'campaign_ending_soon'],
]);

test('B11/B12: outside the window nothing is sent - far end, already ended, window edge in, one hour past the edge out', function (string $when, int $count) {
    [, , $steps] = e15lMake('season', ['ends_at' => now()->modify($when)]);
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);

    e15lRun();

    expect(e15lNotes('season_ending_soon', $user))->toHaveCount($count);
})->with([
    'far away (3 days)' => ['+3 days', 0],
    'already ended' => ['-1 hour', 0],
    'exactly at the window edge (24h)' => ['+24 hours', 1],
    'one hour beyond the edge (25h)' => ['+25 hours', 0],
    'one minute left' => ['+1 minute', 1],
]);

test('an unavailable campaign never reminds: inactive, not started yet, or no end date at all', function (array $attrs) {
    [, , $steps] = e15lMake('season', $attrs);
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);

    e15lRun();

    expect(DatabaseNotification::where('notifiable_id', $user->id)->count())->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'starts in the future' => [['starts_at' => '+2 hours']],
    'no ends_at' => [['ends_at' => null]],
]);

test('B13/C20: running the scheduler repeatedly creates exactly one reminder', function (string $mode, string $type) {
    [, , $steps] = e15lMake($mode);
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);

    e15lRun();
    e15lRun();
    Carbon::setTestNow(now()->addHours(3)); // ساعات لاحقة بنفس اليوم
    e15lRun();

    expect(e15lNotes($type, $user))->toHaveCount(1);
})->with([
    'season' => ['season', 'season_ending_soon'],
    'standalone campaign' => ['standalone', 'campaign_ending_soon'],
]);

test('B14: a published season respects the SEASON preference (the campaign preference does not govern it)', function () {
    [, , $steps] = e15lMake('season');
    [$seasonOff, $campaignOff] = [User::factory()->create(), User::factory()->create()];
    app(NotificationPreferenceService::class)->update($seasonOff, ['season_enabled' => false]);
    app(NotificationPreferenceService::class)->update($campaignOff, ['campaign_enabled' => false]);
    e15lProgress($seasonOff, $steps[0]);
    e15lProgress($campaignOff, $steps[0]);

    e15lRun();

    expect(e15lNotes('season_ending_soon', $seasonOff))->toHaveCount(0)->and(e15lNotes('season_ending_soon', $campaignOff))->toHaveCount(1);
});

// ============================ C: Standalone campaign ending soon ============================

test('C15: a standalone campaign with real progress, not completed, inside the window -> campaign_ending_soon', function () {
    [$c, , $steps] = e15lMake('standalone');
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);

    e15lRun();
    $row = e15lNotes('campaign_ending_soon', $user)->first();

    expect(e15lNotes('campaign_ending_soon', $user))->toHaveCount(1)->and(e15lNotes('season_ending_soon', $user))->toHaveCount(0)
        ->and($row->idempotency_key)->toBe("campaign-ending-soon:{$user->id}:{$c->id}")
        ->and($row->category)->toBe('campaign')
        ->and($row->data['title'])->toContain('تنتهي الحملة قريبًا')->toContain($c->title)
        ->and($row->data['action_route'])->toBe('campaigns.show')
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/campaigns/'.$c->slug);
});

test('C19: a standalone campaign respects the CAMPAIGN preference', function () {
    [, , $steps] = e15lMake('standalone');
    [$campaignOff, $seasonOff] = [User::factory()->create(), User::factory()->create()];
    app(NotificationPreferenceService::class)->update($campaignOff, ['campaign_enabled' => false]);
    app(NotificationPreferenceService::class)->update($seasonOff, ['season_enabled' => false]);
    e15lProgress($campaignOff, $steps[0]);
    e15lProgress($seasonOff, $steps[0]);

    e15lRun();

    expect(e15lNotes('campaign_ending_soon', $campaignOff))->toHaveCount(0)->and(e15lNotes('campaign_ending_soon', $seasonOff))->toHaveCount(1);
});

// ============================ مشترك ============================

test('S21: global notifications off - no ending-soon notification at all', function () {
    $this->settings->set('notifications', 'notifications_enabled', false);
    [, , $stepsS] = e15lMake('season');
    [, , $stepsC] = e15lMake('standalone');
    $user = User::factory()->create();
    e15lProgress($user, $stepsS[0]);
    e15lProgress($user, $stepsC[0]);

    e15lRun();

    expect(DatabaseNotification::where('notifiable_id', $user->id)->count())->toBe(0);
});

test('S22: a failing notification pipeline never breaks the command or any gameplay state', function () {
    [$c, , $steps] = e15lMake('standalone');
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));
    $before = e15lSnapshot();

    $this->artisan('notifications:dispatch-lifecycle-reminders')->expectsOutputToContain('فشل: 1')->assertExitCode(0);

    expect(e15lSnapshot())->toBe($before)->and(e15lNotes('campaign_ending_soon'))->toHaveCount(0);
});

test('S23: opening and reading an ending-soon notification grants nothing', function () {
    [$c, $season, $steps] = e15lMake('season');
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);
    e15lRun();
    $note = e15lNotes('season_ending_soon', $user)->first();
    $before = e15lSnapshot();

    $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('seasons.show', $season));
    $this->post(route('notifications.read-all'));

    expect($note->fresh()->read_at)->not->toBeNull()->and(e15lSnapshot())->toBe($before);
});

test('S24: the scheduler is read-only: it creates no gameplay progress, quest, streak, reward or completion', function () {
    [, , $stepsS] = e15lMake('season');
    [, , $stepsC] = e15lMake('standalone');
    $users = User::factory()->count(3)->create();
    $users->each(function ($u) use ($stepsS, $stepsC) {
        e15lProgress($u, $stepsS[0]);
        e15lProgress($u, $stepsC[0]);
    });
    $before = e15lSnapshot();

    e15lRun();
    e15lRun();

    expect(e15lSnapshot())->toBe($before)->and(DatabaseNotification::count())->toBeGreaterThan(0);
});

test('S25: users are independent - one user reminder never affects another', function () {
    [, , $steps] = e15lMake('season');
    [$a, $b, $c] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    e15lProgress($a, $steps[0]);
    e15lComplete($c, $steps); // مكتمل
    app(NotificationPreferenceService::class)->update($b, ['season_enabled' => false]);
    e15lProgress($b, $steps[0]);

    e15lRun();

    expect(e15lNotes('season_ending_soon', $a))->toHaveCount(1)->and(e15lNotes('season_ending_soon', $b))->toHaveCount(0)->and(e15lNotes('season_ending_soon', $c))->toHaveCount(0);
});

// ============================ مكافحة الإزعاج والأولوية ============================

test('the daily re-engagement budget caps ending-soon reminders per user; the remainder goes out the next day', function () {
    config(['player_notifications.ending_soon_window_hours' => 72]);
    [, , $stepsA] = e15lMake('standalone', ['ends_at' => now()->addHours(30)]);
    [, , $stepsB] = e15lMake('standalone', ['ends_at' => now()->addHours(60)]);
    $user = User::factory()->create();
    e15lProgress($user, $stepsA[0]);
    e15lProgress($user, $stepsB[0]);

    e15lRun();
    e15lRun();
    expect(e15lNotes('campaign_ending_soon', $user))->toHaveCount(1); // الميزانية الافتراضية 1 يوميًا

    Carbon::setTestNow(now()->addHours(26)); // اليوم التالي، والحملتان لم تنتهيا بعد
    e15lRun();

    expect(e15lNotes('campaign_ending_soon', $user))->toHaveCount(2);
});

test('priority: a daily-quests reminder never blocks ending-soon, but a streak warning (higher priority) does', function () {
    [, , $steps] = e15lMake('standalone');
    [$withDaily, $withStreak] = [User::factory()->create(), User::factory()->create()];
    e15lProgress($withDaily, $steps[0]);
    e15lProgress($withStreak, $steps[0]);
    e15Note($withDaily, ['type' => NotificationType::DailyQuestsAvailable]);
    e15Note($withStreak, ['type' => NotificationType::StreakAtRisk]);

    e15lRun();

    expect(e15lNotes('campaign_ending_soon', $withDaily))->toHaveCount(1)->and(e15lNotes('campaign_ending_soon', $withStreak))->toHaveCount(0);
});

test('priority: the policy counts by priority - streak ignores ending-soon and daily, ending-soon ignores daily, daily counts everything', function () {
    $user = User::factory()->create();
    e15Note($user, ['type' => NotificationType::DailyQuestsAvailable]);
    e15Note($user, ['type' => NotificationType::SeasonEndingSoon]);
    $policy = app(ReEngagementPolicy::class);
    $id = $user->id;

    expect($policy->usedToday([$id], NotificationType::StreakAtRisk)[$id] ?? 0)->toBe(0)
        ->and($policy->usedToday([$id], NotificationType::SeasonEndingSoon)[$id] ?? 0)->toBe(1)
        ->and($policy->usedToday([$id])[$id] ?? 0)->toBe(2);
});

test('priority end to end: an ending-soon reminder sent earlier today never blocks the later streak warning', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 19:00:00', 'UTC')); // داخل نافذة تحذير السلسلة
    [, , $steps] = e15lMake('standalone', ['ends_at' => now()->addHours(10)]);
    $user = User::factory()->create();
    e15lProgress($user, $steps[0]);
    e15Streak($user, 5, '2026-10-09');

    e15lRun();                                              // ميزانية اليوم (1) استُهلكت بتذكير ينتهي-قريبًا
    $stats = app(ReEngagementService::class)->dispatchStreakRisk();

    expect(e15lNotes('campaign_ending_soon', $user))->toHaveCount(1)->and(e15lNotes('streak_at_risk', $user))->toHaveCount(1)->and($stats['created'])->toBe(1);
});

test('the command is scheduled hourly and registered BEFORE the E15 re-engagement command', function () {
    Artisan::call('schedule:list');
    $out = Artisan::output();
    $lifecycle = strpos($out, 'notifications:dispatch-lifecycle-reminders');
    $reengagement = strpos($out, 'notifications:dispatch-reengagement');

    expect($lifecycle)->not->toBeFalse()->and($reengagement)->not->toBeFalse()->and($lifecycle)->toBeLessThan($reengagement);
});

test('single shared logic: the command holds none; the service reuses the availability and progress definitions (no copies)', function () {
    $strip = fn (string $path) => preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path($path)));
    $command = $strip('Console/Commands/DispatchLifecycleReminders.php');
    $service = $strip('Services/Notifications/LifecycleReminderService.php');

    foreach (['ends_at', 'is_active', 'starts_at', 'isCampaignAvailable', 'user_campaign_progress', 'puzzle_attempts'] as $needle) {
        expect(str_contains($command, $needle))->toBeFalse("command must not contain {$needle}");
    }

    expect(str_contains($service, '->isAvailable('))->toBeTrue()
        ->and(str_contains($service, 'isCampaignAvailable'))->toBeFalse()
        ->and(str_contains($service, 'starts_at'))->toBeFalse()
        ->and(str_contains($service, 'CampaignProgressPairs::usersForCampaignQuery'))->toBeTrue()
        ->and(str_contains($service, 'user_campaign_progress'))->toBeFalse()
        ->and(str_contains($service, 'puzzle_attempts'))->toBeFalse();
});

<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Events\StageUnlockedForUser;
use App\GameEngine\Support\AttemptContext;
use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\Season;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Models\UserQuestProgress;
use App\Services\CampaignNarrativeService;
use App\Services\CampaignProgressService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use App\Services\QualificationService;
use App\Services\StageUnlockService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** حملة بمراحل (بوابة واحدة لكل مرحلة، وعدد خطوات سرد لكل مرحلة). بلا أحداث إتاحة الحملة (ميزة مستقلة). */
function e15uCampaign(array $stepsPerStage = [2, 1, 1], array $campaign = []): array
{
    $c = Campaign::withoutEvents(fn () => Campaign::factory()->create($campaign + ['is_active' => true, 'slug' => 'stg-'.Str::lower(Str::random(6))]));
    $stages = [];
    $steps = [];

    foreach ($stepsPerStage as $i => $count) {
        $n = $i + 1;
        $stage = CampaignStage::factory()->create(['campaign_id' => $c->id, 'sort_order' => $n, 'title' => "المرحلة {$n}"]);
        $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id, 'sort_order' => 1]);
        $stages[$n] = $stage;

        for ($k = 1; $k <= $count; $k++) {
            $steps[$n][$k] = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id, 'sort_order' => $k]);
        }
    }

    return [$c, $stages, $steps];
}

function e15uDone(User $user, CampaignStep $step): void
{
    app(CampaignNarrativeService::class)->complete($user, $step);
}

/** تقدّم "قديم" كتبه مستخدم قبل الميزة: بلا مرور بنقطة الإكمال، فلا سجل فتح له. */
function e15uOld(User $user, CampaignStep $step, string $when = '-30 days'): void
{
    UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now()->modify($when), 'completed_at' => now()->modify($when)]);
}

function e15uLedger(User $user): array
{
    return DB::table('user_stage_unlocks')->where('user_id', $user->id)->pluck('campaign_stage_id')->sort()->values()->all();
}

function e15uNotes(?User $user = null)
{
    return DatabaseNotification::where('type_key', 'stage_unlocked')->when($user, fn ($q) => $q->where('notifiable_id', $user->id))->get();
}

// ============================ الاكتشاف ============================

test('the first stage never generates an unlock: not for a brand-new user viewing the campaign, not while completing its own steps', function () {
    Event::fake([StageUnlockedForUser::class]);
    [$c, , $steps] = e15uCampaign([2, 1]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('campaigns.show', $c))->assertOk();
    e15uDone($user, $steps[1][1]); // خطوة من أصل خطوتين

    expect(DB::table('user_stage_unlocks')->count())->toBe(0);
    Event::assertNotDispatched(StageUnlockedForUser::class);
});

test('a user who has not met the real condition gets no unlock', function () {
    Event::fake([StageUnlockedForUser::class]);
    [, $stages, $steps] = e15uCampaign([2, 1, 1]);
    $user = User::factory()->create();

    e15uDone($user, $steps[1][1]);

    expect(app(CampaignProgressService::class)->isStageUnlocked($user, $stages[2]->fresh()))->toBeFalse()
        ->and(e15uLedger($user))->toBe([]);
    Event::assertNotDispatched(StageUnlockedForUser::class);
});

test('completing the real condition opens the next stage: one ledger row, one event carrying only ids', function () {
    Event::fake([StageUnlockedForUser::class]);
    [$c, $stages, $steps] = e15uCampaign([2, 1, 1]);
    $user = User::factory()->create();

    e15uDone($user, $steps[1][1]);
    e15uDone($user, $steps[1][2]);

    expect(app(CampaignProgressService::class)->isStageUnlocked($user, $stages[2]->fresh()))->toBeTrue() // الفتح الفعلي مشتق كما كان
        ->and(app(CampaignProgressService::class)->isStageUnlocked($user, $stages[3]->fresh()))->toBeFalse()
        ->and(e15uLedger($user))->toBe([$stages[2]->id]);
    Event::assertDispatchedTimes(StageUnlockedForUser::class, 1);
    Event::assertDispatched(StageUnlockedForUser::class, fn ($e) => $e->userId === $user->id && $e->campaignId === $c->id && $e->stageId === $stages[2]->id);
});

test('repeating the request, re-completing the step or re-running the hook never repeats the row, the event or the notification', function () {
    $fired = 0;
    Event::listen(StageUnlockedForUser::class, function () use (&$fired) {
        $fired++;
    });
    [, $stages, $steps] = e15uCampaign([2, 1, 1]);
    $user = User::factory()->create();

    e15uDone($user, $steps[1][1]);
    e15uDone($user, $steps[1][2]);

    for ($i = 0; $i < 3; $i++) {
        e15uDone($user, $steps[1][2]);
        app(QualificationService::class)->afterStepCompletion($user, $steps[1][2]);
        StageUnlockService::recordSafely($user, $steps[1][1]);
    }

    expect(e15uLedger($user))->toBe([$stages[2]->id])->and($fired)->toBe(1)->and(e15uNotes($user))->toHaveCount(1);
});

test('viewing or refreshing campaign pages (guest or user) never creates a row, an event or a notification', function () {
    [$c, $stages, $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();
    e15uDone($user, $steps[1][1]);
    $rows = DB::table('user_stage_unlocks')->count();
    $notes = e15uNotes()->count();

    for ($i = 0; $i < 3; $i++) {
        $this->get(route('campaigns.show', $c))->assertOk();
        $this->actingAs($user)->get(route('campaigns.show', $c))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('campaigns.show', $c))->assertOk();
    }

    expect(DB::table('user_stage_unlocks')->count())->toBe($rows)->and(e15uNotes()->count())->toBe($notes)->and($rows)->toBe(1);
});

test('another user is completely independent', function () {
    [, $stages, $steps] = e15uCampaign([1, 1]);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];

    e15uDone($a, $steps[1][1]);
    expect(e15uLedger($a))->toBe([$stages[2]->id])->and(e15uLedger($b))->toBe([])->and(e15uNotes($b))->toHaveCount(0);

    e15uDone($b, $steps[1][1]);
    expect(e15uLedger($b))->toBe([$stages[2]->id])->and(e15uNotes($a))->toHaveCount(1)->and(e15uNotes($b))->toHaveCount(1);
});

test('a puzzle-step completion (derived from a correct attempt, no progress row) is detected too - recent announces, old stays silent', function () {
    $fired = 0;
    Event::listen(StageUnlockedForUser::class, function () use (&$fired) {
        $fired++;
    });
    [$c, $stages, $steps] = e15uCampaign([1, 1]);
    $puzzle = Puzzle::factory()->create();
    $steps[1][1]->update(['kind' => CampaignStep::KIND_PUZZLE, 'puzzle_id' => $puzzle->id]);
    $step = $steps[1][1]->fresh();

    $attempt = fn (User $u, string $when) => PuzzleAttempt::factory()->create([
        'user_id' => $u->id, 'puzzle_id' => $puzzle->id, 'is_correct' => true,
        'context_type' => AttemptContext::TYPE_CAMPAIGN_STEP, 'context_id' => $step->id, 'created_at' => now()->modify($when),
    ]);

    $recent = User::factory()->create();
    $attempt($recent, 'now');
    app(QualificationService::class)->afterStepCompletion($recent, $step);

    $old = User::factory()->create();
    $attempt($old, '-30 days');
    app(QualificationService::class)->afterStepCompletion($old, $step);

    expect(e15uLedger($recent))->toBe([$stages[2]->id])->and(e15uLedger($old))->toBe([$stages[2]->id])
        ->and($fired)->toBe(1)->and(e15uNotes($recent))->toHaveCount(1)->and(e15uNotes($old))->toHaveCount(0);
});

// ============================ الإشعار ============================

test('exactly one stage_unlocked notification per user and stage, with a semantic key, plain content and an internal campaign link', function () {
    [$c, $stages, $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();

    e15uDone($user, $steps[1][1]);
    $row = e15uNotes($user)->first();

    expect(e15uNotes($user))->toHaveCount(1)
        ->and($row->category)->toBe('campaign')
        ->and($row->idempotency_key)->toBe("stage-unlocked:{$user->id}:{$stages[2]->id}")
        ->and($row->data['title'])->toContain('المرحلة 2')
        ->and($row->data['body'])->toContain($c->title)->toContain('المرحلة 2')
        ->and($row->data['action_route'])->toBe('campaigns.show')
        ->and($row->data['action_params'])->toBe(['campaign' => $c->slug])
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/campaigns/'.$c->slug);
});

test('the link goes to the published season page when the campaign has one, otherwise to the campaign page', function (bool $published, string $route, string $prefix) {
    [$c, , $steps] = e15uCampaign([1, 1]);
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $c->id, 'slug' => 'linked-season', 'is_published' => $published]));
    $user = User::factory()->create();

    e15uDone($user, $steps[1][1]);
    $data = e15uNotes($user)->first()->data;

    expect($data['action_route'])->toBe($route)
        ->and(app(NotificationUrlResolver::class)->resolve($data))->toBe($prefix.($published ? 'linked-season' : $c->slug));
})->with([
    'published season' => [true, 'seasons.show', '/seasons/'],
    'draft season (its page is a 404 for players)' => [false, 'campaigns.show', '/campaigns/'],
]);

test('the dispatcher lets the caller choose ONLY among the type allowed routes - anything else falls back to the default', function () {
    $user = User::factory()->create();
    $d = app(NotificationDispatcher::class);

    $d->dispatch($user, NotificationType::StageUnlocked, [], 'route-a', [], ['season' => 'x'], 'seasons.show');
    $d->dispatch($user, NotificationType::StageUnlocked, [], 'route-b', [], [], 'profile.edit');
    $d->dispatch($user, NotificationType::StageUnlocked, [], 'route-c', [], [], 'https://evil.example/x');

    $routes = DatabaseNotification::whereIn('idempotency_key', ['route-a', 'route-b', 'route-c'])->orderBy('idempotency_key')->get()->map(fn ($n) => $n->data['action_route'])->all();

    expect($routes)->toBe(['seasons.show', 'campaigns.show', 'campaigns.show']);
});

test('global notifications off or the campaign preference off: progress and the unlock record happen normally, only the notification is suppressed', function (string $how) {
    if ($how === 'global') {
        app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    }

    [, $stages, $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();

    if ($how === 'preference') {
        app(NotificationPreferenceService::class)->update($user, ['campaign_enabled' => false]);
    }

    e15uDone($user, $steps[1][1]);

    expect(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(1)
        ->and(app(CampaignProgressService::class)->isStageUnlocked($user, $stages[2]->fresh()))->toBeTrue()
        ->and(e15uLedger($user))->toBe([$stages[2]->id])
        ->and(e15uNotes($user))->toHaveCount(0);
})->with(['global' => ['global'], 'preference' => ['preference']]);

test('a failing notification pipeline never rolls back the progress', function () {
    [, $stages, $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));

    e15uDone($user, $steps[1][1]);

    expect(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(1)
        ->and(e15uLedger($user))->toBe([$stages[2]->id])
        ->and(e15uNotes($user))->toHaveCount(0);
});

test('a failing StageUnlockService never breaks the progress either', function () {
    [, $stages, $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();
    $this->app->bind(StageUnlockService::class, fn () => throw new RuntimeException('stage unlock is down'));

    e15uDone($user, $steps[1][1]);

    expect(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(1)
        ->and(app(CampaignProgressService::class)->isStageUnlocked($user, $stages[2]->fresh()))->toBeTrue()
        ->and(e15uLedger($user))->toBe([]);
});

test('opening and reading the notification grants nothing: no XP, currency, quest, streak, purchase, wallet or progress change', function () {
    [$c, , $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();
    e15uDone($user, $steps[1][1]);
    $note = e15uNotes($user)->first();

    $snapshot = fn () => [
        DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count(), UserQuestProgress::count(), PlayerStreak::count(),
        StorePurchase::count(), UserCampaignProgress::count(), DB::table('user_stage_unlocks')->count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
    ];
    $before = $snapshot();

    $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('campaigns.show', $c));
    $this->post(route('notifications.read-all'));

    expect($note->fresh()->read_at)->not->toBeNull()->and($snapshot())->toBe($before);
});

// ============================ الحماية من الإشعارات التاريخية ============================

test('a NEW completion while an older stage is unrecorded announces only the stage this step really opened (never the historical one)', function () {
    [, $stages, $steps] = e15uCampaign([1, 1, 1]);
    $user = User::factory()->create();
    e15uOld($user, $steps[1][1]); // أنهى المرحلة 1 قبل الميزة: المرحلة 2 مفتوحة لكن بلا سجل

    e15uDone($user, $steps[2][1]); // إكمال جديد داخل المرحلة 2 يفتح المرحلة 3

    expect(e15uLedger($user))->toBe([$stages[3]->id])
        ->and(e15uNotes($user))->toHaveCount(1)
        ->and(e15uNotes($user)->first()->idempotency_key)->toBe("stage-unlocked:{$user->id}:{$stages[3]->id}");
});

test('window protection: before the backfill runs, re-completing an old step records the old unlock silently and announces nothing', function () {
    Event::fake([StageUnlockedForUser::class]);
    [, $stages, $steps] = e15uCampaign([1, 1, 1, 1]);
    $user = User::factory()->create();
    e15uOld($user, $steps[1][1]); // المرحلة 2 مفتوحة منذ شهر بلا سجل (لم تُشغَّل التعبئة بعد)

    e15uDone($user, $steps[1][1]); // إعادة إكمال: نقطة الإكمال تُستدعى من جديد

    expect(e15uLedger($user))->toBe([$stages[2]->id])->and(e15uNotes($user))->toHaveCount(0);
    Event::assertNotDispatched(StageUnlockedForUser::class);
});

// ============================ التعبئة الرجعية ============================

test('the backfill records currently-open stages silently: no events, no notifications, never the first stage, idempotent', function () {
    Event::fake([StageUnlockedForUser::class]);
    [, $stages, $steps] = e15uCampaign([1, 1, 1, 1]);
    [$old, $untouched] = [User::factory()->create(), User::factory()->create()];
    e15uOld($old, $steps[1][1]);
    e15uOld($old, $steps[2][1]); // المراحل 2 و3 مفتوحة، و4 مقفلة

    $this->artisan('campaigns:backfill-stage-unlocks')->expectsOutputToContain('سجلات فتح جديدة: 2')->assertExitCode(0);

    expect(e15uLedger($old))->toBe([$stages[2]->id, $stages[3]->id])->and(e15uLedger($untouched))->toBe([])->and(DatabaseNotification::count())->toBe(0);
    Event::assertNotDispatched(StageUnlockedForUser::class);

    $this->artisan('campaigns:backfill-stage-unlocks')->expectsOutputToContain('سجلات فتح جديدة: 0')->assertExitCode(0);
    expect(DB::table('user_stage_unlocks')->count())->toBe(2);
});

test('an old user after the backfill gets no historical unlock notification - only a genuinely new unlock announces', function () {
    [, $stages, $steps] = e15uCampaign([1, 1, 1, 1]);
    $old = User::factory()->create();
    e15uOld($old, $steps[1][1]);
    e15uOld($old, $steps[2][1]);
    Artisan::call('campaigns:backfill-stage-unlocks');

    e15uDone($old, $steps[2][1]);   // إعادة إكمال قديمة
    e15uDone($old, $steps[3][1]);   // جديد: يفتح المرحلة 4

    expect(e15uLedger($old))->toBe([$stages[2]->id, $stages[3]->id, $stages[4]->id])
        ->and(e15uNotes($old))->toHaveCount(1)
        ->and(e15uNotes($old)->first()->idempotency_key)->toBe("stage-unlocked:{$old->id}:{$stages[4]->id}");
});

test('the backfill works in bounded chunks over users with real campaign progress (puzzle-only players included)', function () {
    [, $stages, $steps] = e15uCampaign([1, 1]);
    $users = User::factory()->count(5)->create();
    $users->each(fn ($u) => e15uOld($u, $steps[1][1]));
    User::factory()->count(3)->create(); // بلا تقدّم: لا يُمسّون

    $this->artisan('campaigns:backfill-stage-unlocks', ['--chunk' => 2])->expectsOutputToContain('مستخدمون: 5')->assertExitCode(0);

    expect(DB::table('user_stage_unlocks')->count())->toBe(5)
        ->and(DB::table('user_stage_unlocks')->where('campaign_stage_id', $stages[2]->id)->count())->toBe(5);
});

test('the backfill is a manual command: it is not scheduled', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->not->toContain('backfill-stage-unlocks');
});

// ============================ لا نسخة ثانية من منطق الفتح ============================

test('the unlock rules stay derived: StageUnlockService reuses isStageUnlocked, and QualificationService gained a single call', function () {
    $service = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path('Services/StageUnlockService.php')));
    $qualification = file_get_contents(app_path('Services/QualificationService.php'));

    expect(str_contains($service, '->isStageUnlocked('))->toBeTrue()
        ->and(str_contains($service, 'function isStageUnlocked'))->toBeFalse()
        ->and(str_contains($service, 'isStageCompleted'))->toBeFalse()
        ->and(substr_count($qualification, 'StageUnlockService::recordSafely'))->toBe(1);
});

test('the unlock is atomic: if another process records the same unlock between detection and insert, this call fires nothing', function () {
    Event::fake([StageUnlockedForUser::class]);
    [, $stages, $steps] = e15uCampaign([1, 1]);
    $user = User::factory()->create();
    UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $steps[1][1]->id, 'started_at' => now(), 'completed_at' => now()]); // بلا المرور بالخطاف

    // يحاكي عملية أخرى تُدخل سجل الفتح بعد أن قرّرت هذه أنه غير مسجَّل وقبل إدخالها.
    $racing = new class(app(CampaignProgressService::class)) extends StageUnlockService
    {
        protected function insertUnlock(User $user, CampaignStage $stage): bool
        {
            DB::table('user_stage_unlocks')->insertOrIgnore([['user_id' => $user->id, 'campaign_stage_id' => $stage->id, 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()]]);

            return parent::insertUnlock($user, $stage);
        }
    };

    expect($racing->recordAfterStepCompletion($user, $steps[1][1]))->toBe(0)->and(e15uLedger($user))->toBe([$stages[2]->id]);
    Event::assertNotDispatched(StageUnlockedForUser::class);
});

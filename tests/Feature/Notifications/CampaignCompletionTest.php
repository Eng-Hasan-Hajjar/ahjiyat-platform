<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Events\CampaignCompletedForUser;
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
use App\Services\CampaignCompletionService;
use App\Services\CampaignNarrativeService;
use App\Services\CampaignProgressService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use App\Services\QualificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** حملة بمراحل (بوابة واحدة لكل مرحلة، وعدد خطوات سرد لكل مرحلة). بلا أحداث إتاحة الحملة (ميزة مستقلة). */
function e15ccCampaign(array $stepsPerStage = [1, 2], array $campaign = []): array
{
    $c = Campaign::withoutEvents(fn () => Campaign::factory()->create($campaign + ['is_active' => true, 'slug' => 'cmp-'.Str::lower(Str::random(6))]));
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

function e15ccFinish(User $user, array $steps): void
{
    foreach ($steps as $stageSteps) {
        foreach ($stageSteps as $step) {
            app(CampaignNarrativeService::class)->complete($user, $step);
        }
    }
}

/** تقدّم "قديم" كتبه مستخدم قبل الميزة: بلا مرور بنقطة الإكمال، فلا سجل إكمال له. */
function e15ccOld(User $user, array $steps, string $when = '-30 days'): void
{
    foreach ($steps as $stageSteps) {
        foreach ($stageSteps as $step) {
            UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now()->modify($when), 'completed_at' => now()->modify($when)]);
        }
    }
}

function e15ccRows(User $user): array
{
    return DB::table('user_campaign_completions')->where('user_id', $user->id)->pluck('campaign_id')->sort()->values()->all();
}

function e15ccNotes(?User $user = null)
{
    return DatabaseNotification::where('type_key', 'campaign_completed')->when($user, fn ($q) => $q->where('notifiable_id', $user->id))->get();
}

// ============================ الاكتشاف ============================

test('an incomplete campaign never records a completion: some steps done, or everything but the last step', function () {
    Event::fake([CampaignCompletedForUser::class]);
    [, , $steps] = e15ccCampaign([1, 2]);
    $user = User::factory()->create();

    app(CampaignNarrativeService::class)->complete($user, $steps[1][1]);
    app(CampaignNarrativeService::class)->complete($user, $steps[2][1]); // تبقّت الخطوة الأخيرة

    expect(DB::table('user_campaign_completions')->count())->toBe(0);
    Event::assertNotDispatched(CampaignCompletedForUser::class);
});

test('completing the real last condition records the first completion once, and the event carries only ids', function () {
    Event::fake([CampaignCompletedForUser::class]);
    [$c, , $steps] = e15ccCampaign([1, 2]);
    $user = User::factory()->create();

    e15ccFinish($user, $steps);

    expect(app(CampaignProgressService::class)->isCampaignCompleted($user, $c->fresh()))->toBeTrue() // الإكمال الفعلي مشتق كما كان
        ->and(e15ccRows($user))->toBe([$c->id]);
    Event::assertDispatchedTimes(CampaignCompletedForUser::class, 1);
    Event::assertDispatched(CampaignCompletedForUser::class, fn ($e) => $e->userId === $user->id && $e->campaignId === $c->id);
});

test('repeating the request, re-completing steps or re-running the hook never repeats the row, the event or the notification', function () {
    $fired = 0;
    Event::listen(CampaignCompletedForUser::class, function () use (&$fired) {
        $fired++;
    });
    [$c, , $steps] = e15ccCampaign([1, 2]);
    $user = User::factory()->create();
    e15ccFinish($user, $steps);

    for ($i = 0; $i < 3; $i++) {
        e15ccFinish($user, $steps);
        app(QualificationService::class)->afterStepCompletion($user, $steps[2][2]);
        CampaignCompletionService::recordSafely($user, $steps[1][1]);
    }

    expect(e15ccRows($user))->toBe([$c->id])->and($fired)->toBe(1)->and(e15ccNotes($user))->toHaveCount(1);
});

test('viewing or refreshing the campaign page (guest or user) never creates a row, an event or a notification', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();
    e15ccFinish($user, $steps);
    $rows = DB::table('user_campaign_completions')->count();
    $notes = e15ccNotes()->count();

    for ($i = 0; $i < 3; $i++) {
        $this->get(route('campaigns.show', $c))->assertOk();
        $this->actingAs($user)->get(route('campaigns.show', $c))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('campaigns.show', $c))->assertOk();
    }

    expect(DB::table('user_campaign_completions')->count())->toBe($rows)->and(e15ccNotes()->count())->toBe($notes)->and($rows)->toBe(1);
});

test('another user is completely independent', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];

    e15ccFinish($a, $steps);
    expect(e15ccRows($a))->toBe([$c->id])->and(e15ccRows($b))->toBe([])->and(e15ccNotes($b))->toHaveCount(0);

    app(CampaignNarrativeService::class)->complete($b, $steps[1][1]); // أنهى نصفها فقط
    expect(e15ccRows($b))->toBe([])->and(e15ccNotes($a))->toHaveCount(1);

    e15ccFinish($b, $steps);
    expect(e15ccRows($b))->toBe([$c->id])->and(e15ccNotes($a))->toHaveCount(1)->and(e15ccNotes($b))->toHaveCount(1);
});

test('empty containers are never complete (the existing source of truth says so): no stages, a stage without gates', function () {
    Event::fake([CampaignCompletedForUser::class]);
    $user = User::factory()->create();

    $noStages = Campaign::withoutEvents(fn () => Campaign::factory()->create(['is_active' => true]));
    expect(app(CampaignCompletionService::class)->backfill($user, $noStages->fresh()))->toBeFalse();

    [$c, , $steps] = e15ccCampaign([1]);
    CampaignStage::factory()->create(['campaign_id' => $c->id, 'sort_order' => 2, 'title' => 'مرحلة فارغة بلا بوابات']);
    e15ccFinish($user, $steps);

    expect(DB::table('user_campaign_completions')->count())->toBe(0);
    Event::assertNotDispatched(CampaignCompletedForUser::class);
});

test('a puzzle-step completion (derived from a correct attempt, no progress row) is detected too - recent announces, old stays silent', function () {
    $fired = 0;
    Event::listen(CampaignCompletedForUser::class, function () use (&$fired) {
        $fired++;
    });
    [$c, , $steps] = e15ccCampaign([1]);
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

    expect(e15ccRows($recent))->toBe([$c->id])->and(e15ccRows($old))->toBe([$c->id])
        ->and($fired)->toBe(1)->and(e15ccNotes($recent))->toHaveCount(1)->and(e15ccNotes($old))->toHaveCount(0);
});

test('the completion is atomic: if another process records the same completion between detection and insert, this call fires nothing', function () {
    Event::fake([CampaignCompletedForUser::class]);
    [$c, , $steps] = e15ccCampaign([1]);
    $user = User::factory()->create();
    UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $steps[1][1]->id, 'started_at' => now(), 'completed_at' => now()]); // بلا المرور بالخطاف

    $racing = new class(app(CampaignProgressService::class)) extends CampaignCompletionService
    {
        protected function insertCompletion(User $user, Campaign $campaign): bool
        {
            DB::table('user_campaign_completions')->insertOrIgnore([['user_id' => $user->id, 'campaign_id' => $campaign->id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]]);

            return parent::insertCompletion($user, $campaign);
        }
    };

    expect($racing->recordAfterStepCompletion($user, $steps[1][1]))->toBeFalse()->and(e15ccRows($user))->toBe([$c->id]);
    Event::assertNotDispatched(CampaignCompletedForUser::class);
});

// ============================ الإشعار ============================

test('exactly one campaign_completed notification per user and campaign, with a semantic key, plain content and an internal link', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();

    e15ccFinish($user, $steps);
    $row = e15ccNotes($user)->first();

    expect(e15ccNotes($user))->toHaveCount(1)
        ->and($row->category)->toBe('campaign')
        ->and($row->idempotency_key)->toBe("campaign-completed:{$user->id}:{$c->id}")
        ->and($row->data['title'])->toContain('أكملت الحملة بنجاح')->toContain($c->title)
        ->and($row->data['body'])->toContain($c->title)
        ->and($row->data['action_route'])->toBe('campaigns.show')
        ->and(app(NotificationUrlResolver::class)->resolve($row->data))->toBe('/campaigns/'.$c->slug);
});

test('the link goes to the published season page when the campaign has one, otherwise to the campaign page', function (bool $published, string $route, string $prefix) {
    [$c, , $steps] = e15ccCampaign([1]);
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $c->id, 'slug' => 'done-season', 'is_published' => $published]));
    $user = User::factory()->create();

    e15ccFinish($user, $steps);
    $data = e15ccNotes($user)->first()->data;

    expect($data['action_route'])->toBe($route)
        ->and(app(NotificationUrlResolver::class)->resolve($data))->toBe($prefix.($published ? 'done-season' : $c->slug));
})->with([
    'published season' => [true, 'seasons.show', '/seasons/'],
    'draft season (its page is a 404 for players)' => [false, 'campaigns.show', '/campaigns/'],
]);

test('global notifications off or the campaign preference off: the completion is recorded normally, only the notification is suppressed', function (string $how) {
    if ($how === 'global') {
        app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    }

    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();

    if ($how === 'preference') {
        app(NotificationPreferenceService::class)->update($user, ['campaign_enabled' => false]);
    }

    e15ccFinish($user, $steps);

    expect(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(2)
        ->and(app(CampaignProgressService::class)->isCampaignCompleted($user, $c->fresh()))->toBeTrue()
        ->and(e15ccRows($user))->toBe([$c->id])
        ->and(e15ccNotes($user))->toHaveCount(0);
})->with(['global' => ['global'], 'preference' => ['preference']]);

test('a failing notification pipeline never rolls back the progress or the completion', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));

    e15ccFinish($user, $steps);

    expect(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(2)
        ->and(e15ccRows($user))->toBe([$c->id])
        ->and(e15ccNotes($user))->toHaveCount(0);
});

test('a failing CampaignCompletionService never breaks the progress either', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();
    $this->app->bind(CampaignCompletionService::class, fn () => throw new RuntimeException('completion service is down'));

    e15ccFinish($user, $steps);

    expect(UserCampaignProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count())->toBe(2)
        ->and(app(CampaignProgressService::class)->isCampaignCompleted($user, $c->fresh()))->toBeTrue()
        ->and(e15ccRows($user))->toBe([]);
});

test('opening and reading the notification grants nothing: no XP, currency, quest, streak, purchase, wallet or progress change', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();
    e15ccFinish($user, $steps);
    $note = e15ccNotes($user)->first();

    $snapshot = fn () => [
        DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count(), UserQuestProgress::count(), PlayerStreak::count(),
        StorePurchase::count(), UserCampaignProgress::count(), DB::table('user_campaign_completions')->count(), DB::table('user_stage_unlocks')->count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
    ];
    $before = $snapshot();

    $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('campaigns.show', $c));
    $this->post(route('notifications.read-all'));

    expect($note->fresh()->read_at)->not->toBeNull()->and($snapshot())->toBe($before);
});

// ============================ تاريخية السجل ============================

test('a completion is historical: deactivating the campaign, reactivating it, or an admin adding a stage later never resets it or fires again', function () {
    $fired = 0;
    Event::listen(CampaignCompletedForUser::class, function () use (&$fired) {
        $fired++;
    });
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();
    e15ccFinish($user, $steps);
    $firstAt = DB::table('user_campaign_completions')->where('user_id', $user->id)->value('completed_at');

    $c->update(['is_active' => false]);
    expect(e15ccRows($user))->toBe([$c->id]); // التعطيل لا يمسح الإكمال

    $c->update(['is_active' => true]);
    $newStage = CampaignStage::factory()->create(['campaign_id' => $c->id, 'sort_order' => 3, 'title' => 'مرحلة أضافها المدير']);
    $newStep = CampaignStep::factory()->create(['campaign_gate_id' => CampaignGate::factory()->create(['campaign_stage_id' => $newStage->id])->id]);
    app(CampaignNarrativeService::class)->complete($user, $newStep); // الحملة صارت "غير مكتملة" بالتعريف الحالي ثم اكتملت من جديد
    e15ccFinish($user, $steps);

    expect(e15ccRows($user))->toBe([$c->id])->and($fired)->toBe(1)->and(e15ccNotes($user))->toHaveCount(1)
        ->and((string) DB::table('user_campaign_completions')->where('user_id', $user->id)->value('completed_at'))->toBe((string) $firstAt);
});

test('window protection: before the backfill runs, re-completing an old step records the old completion silently and announces nothing', function () {
    Event::fake([CampaignCompletedForUser::class]);
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $user = User::factory()->create();
    e15ccOld($user, $steps); // أكملها قبل الميزة بلا سجل (لم تُشغَّل التعبئة بعد)

    app(CampaignNarrativeService::class)->complete($user, $steps[2][1]); // إعادة إكمال: نقطة الإكمال تُستدعى من جديد

    expect(e15ccRows($user))->toBe([$c->id])->and(e15ccNotes($user))->toHaveCount(0);
    Event::assertNotDispatched(CampaignCompletedForUser::class);
});

// ============================ التعبئة الرجعية ============================

test('the backfill records old completions silently - inactive and ended campaigns included, incomplete ones skipped, idempotent', function () {
    Event::fake([CampaignCompletedForUser::class]);
    [$active, , $stepsA] = e15ccCampaign([1, 1]);
    [$inactive, , $stepsI] = e15ccCampaign([1], ['is_active' => false]);
    [$ended, , $stepsE] = e15ccCampaign([1], ['ends_at' => now()->subDay()]);
    [$partial, , $stepsP] = e15ccCampaign([1, 1]);
    [$user, $untouched] = [User::factory()->create(), User::factory()->create()];

    e15ccOld($user, $stepsA);
    e15ccOld($user, $stepsI);
    e15ccOld($user, $stepsE);
    e15ccOld($user, [1 => $stepsP[1]]); // أنهى نصف الحملة فقط

    $this->artisan('campaigns:backfill-completions')->expectsOutputToContain('سجلات إكمال جديدة: 3')->assertExitCode(0);

    expect(e15ccRows($user))->toBe(collect([$active, $inactive, $ended])->pluck('id')->sort()->values()->all())
        ->and(e15ccRows($untouched))->toBe([])
        ->and(DatabaseNotification::count())->toBe(0);
    Event::assertNotDispatched(CampaignCompletedForUser::class);

    $this->artisan('campaigns:backfill-completions')->expectsOutputToContain('سجلات إكمال جديدة: 0')->assertExitCode(0);
    expect(DB::table('user_campaign_completions')->count())->toBe(3);
});

test('an old user after the backfill gets no historical completion notification', function () {
    [$c, , $steps] = e15ccCampaign([1, 1]);
    $old = User::factory()->create();
    e15ccOld($old, $steps);
    Artisan::call('campaigns:backfill-completions');

    e15ccFinish($old, $steps); // إعادة إكمال
    app(QualificationService::class)->afterStepCompletion($old, $steps[2][1]);

    expect(e15ccRows($old))->toBe([$c->id])->and(e15ccNotes($old))->toHaveCount(0);
});

test('the backfill works in bounded chunks over users with real campaign progress', function () {
    [, , $steps] = e15ccCampaign([1, 1]);
    User::factory()->count(5)->create()->each(fn ($u) => e15ccOld($u, $steps));
    User::factory()->count(3)->create(); // بلا تقدّم: لا يُمسّون

    $this->artisan('campaigns:backfill-completions', ['--chunk' => 2])->expectsOutputToContain('مستخدمون: 5')->assertExitCode(0);

    expect(DB::table('user_campaign_completions')->count())->toBe(5);
});

test('the backfill is a manual command: it is not scheduled', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->not->toContain('backfill-completions');
});

// ============================ لا نسخة ثانية من المنطق ============================

test('the completion rules stay derived and the shared definitions are not copied', function () {
    $strip = fn (string $path) => preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path($path)));
    $service = $strip('Services/CampaignCompletionService.php');
    $qualification = file_get_contents(app_path('Services/QualificationService.php'));

    expect(str_contains($service, '->isCampaignCompleted('))->toBeTrue()
        ->and(str_contains($service, 'function isCampaignCompleted'))->toBeFalse()
        ->and(str_contains($service, 'isStageCompleted'))->toBeFalse()
        ->and(str_contains($service, 'isGateCompleted'))->toBeFalse()
        ->and(substr_count($qualification, 'CampaignCompletionService::recordSafely'))->toBe(1)
        ->and(substr_count($qualification, 'StageUnlockService::recordSafely'))->toBe(1);

    foreach (['Console/Commands/BackfillCampaignCompletions.php', 'Console/Commands/BackfillStageUnlocks.php'] as $cmd) {
        $code = $strip($cmd);
        expect(str_contains($code, 'CampaignProgressPairs::'))->toBeTrue("{$cmd} must use the shared definition")
            ->and(str_contains($code, 'user_campaign_progress'))->toBeFalse("{$cmd} must not copy it");
    }

    foreach (['Services/CampaignCompletionService.php', 'Services/StageUnlockService.php'] as $svc) {
        expect(str_contains($strip($svc), 'StepCompletionRecency::isRecent'))->toBeTrue("{$svc} must share the recency definition");
    }
});

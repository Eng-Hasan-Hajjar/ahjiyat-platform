<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Events\SeasonStarted;
use App\Jobs\DispatchSeasonStartedChunk;
use App\Models\Campaign;
use App\Models\PlayerStreak;
use App\Models\Season;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\CampaignProgressService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use App\Services\SeasonLifecycleService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->lifecycle = app(SeasonLifecycleService::class);
});

afterEach(fn () => Carbon::setTestNow());

/** حملة بلا أحداث النموذج: إتاحة الحملة ميزة مستقلة (CampaignAvailableTest) ولا تُلوِّث إشعارات هذه الاختبارات. */
function e15Camp(array $attrs = []): Campaign
{
    return Campaign::withoutEvents(fn () => Campaign::factory()->create($attrs));
}

/** موسم بلا تشغيل أحداث نموذج الموسم (لا خطافات) ليتحكم الاختبار بلحظة المزامنة بنفسه. */
function e15Season(array $campaign = [], array $season = []): Season
{
    $c = e15Camp($campaign + ['is_active' => true, 'starts_at' => null, 'ends_at' => null]);

    return Season::withoutEvents(fn () => Season::factory()->create($season + ['campaign_id' => $c->id, 'is_published' => true]));
}

const E15_SEASON_MIGRATION = 'database/migrations/2026_10_08_000001_add_went_live_at_to_seasons_table.php';

// ============================ متى يبدأ الموسم ومتى لا يبدأ ============================

test('a season that is not live never starts: unpublished, inactive campaign, future start, already finished', function (array $campaign, array $season) {
    Event::fake([SeasonStarted::class]);
    $s = e15Season($campaign, $season);

    expect($this->lifecycle->syncLiveState($s))->toBeFalse()->and($s->fresh()->went_live_at)->toBeNull();
    Event::assertNotDispatched(SeasonStarted::class);
})->with([
    'unpublished' => [[], ['is_published' => false]],
    'published but campaign inactive' => [['is_active' => false], []],
    'published + active but starts_at is in the future' => [['starts_at' => '+2 hours'], []],
    'published + active but the window already ended' => [['ends_at' => '-1 hour'], []],
]);

test('when the start time arrives went_live_at is recorded ONCE and SeasonStarted fires ONCE, however often the scheduler runs', function () {
    Carbon::setTestNow('2026-10-10 10:00:00');
    Event::fake([SeasonStarted::class]);
    $s = e15Season(['starts_at' => now()->addHours(2)]);

    $this->artisan('seasons:sync-live-state')->assertExitCode(0);
    expect($s->fresh()->went_live_at)->toBeNull();
    Event::assertNotDispatched(SeasonStarted::class);

    Carbon::setTestNow('2026-10-10 12:30:00');
    $this->artisan('seasons:sync-live-state')->expectsOutputToContain('بدأ منها فعلًا: 1');
    $recorded = $s->fresh()->went_live_at->toDateTimeString();
    expect($recorded)->toBe('2026-10-10 12:30:00');

    Carbon::setTestNow('2026-10-10 15:00:00');
    $this->artisan('seasons:sync-live-state')->expectsOutputToContain('فُحصت 0 مواسم');
    $this->lifecycle->syncLiveState($s);
    $this->lifecycle->syncLiveState($s->id);
    $this->artisan('seasons:sync-live-state');

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
    expect($s->fresh()->went_live_at->toDateTimeString())->toBe($recorded);
});

test('the transition is atomic: if another process wins the race, this call updates nothing and fires nothing', function () {
    Event::fake([SeasonStarted::class]);
    $s = e15Season();

    // يحاكي عملية أخرى تسجّل البدء بين قراءة الموسم وتحديثه.
    $racing = new class(app(CampaignProgressService::class), $s->id) extends SeasonLifecycleService
    {
        public function __construct(CampaignProgressService $c, protected int $id)
        {
            parent::__construct($c);
        }

        public function isLive(Season $season): bool
        {
            Season::query()->whereKey($this->id)->update(['went_live_at' => '2026-01-01 00:00:00']);

            return true;
        }
    };

    expect($racing->syncLiveState($s))->toBeFalse()
        ->and($s->fresh()->went_live_at->toDateTimeString())->toBe('2026-01-01 00:00:00'); // قيمة "العملية الأخرى" لم تُستبدَل
    Event::assertNotDispatched(SeasonStarted::class);
});

test('went_live_at is written once: deactivating then reactivating (campaign or publication) never fires SeasonStarted again', function () {
    Event::fake([SeasonStarted::class]);
    $campaign = e15Camp(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]); // يبدأ فعليًا عند الإنشاء
    $first = $season->fresh()->went_live_at;

    expect($first)->not->toBeNull();
    Event::assertDispatchedTimes(SeasonStarted::class, 1);

    Carbon::setTestNow(now()->addDay());
    $campaign->update(['is_active' => false]);
    $campaign->update(['is_active' => true]);
    $season->update(['is_published' => false]);
    $season->update(['is_published' => true]);
    $this->lifecycle->syncLiveState($season);
    $this->artisan('seasons:sync-live-state');

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
    expect($season->fresh()->went_live_at->toDateTimeString())->toBe($first->toDateTimeString());
});

test('the event fires only AFTER commit: a rolled-back transition leaves no event and no went_live_at', function () {
    Event::fake([SeasonStarted::class]);
    $s = e15Season();

    try {
        DB::transaction(function () use ($s) {
            $this->lifecycle->syncLiveState($s);
            throw new RuntimeException('outer transaction fails');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(SeasonStarted::class);
    expect($s->fresh()->went_live_at)->toBeNull();

    $this->lifecycle->syncLiveState($s);
    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

// ============================ خطافات الانتقالات الحقيقية ============================

test('publishing a season (is_published false -> true) syncs and starts a live season once', function () {
    Event::fake([SeasonStarted::class]);
    $campaign = e15Camp(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);
    Event::assertNotDispatched(SeasonStarted::class);

    $season->update(['is_published' => true]);

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

test('activating the campaign (is_active false -> true) syncs its published season', function () {
    Event::fake([SeasonStarted::class]);
    $campaign = e15Camp(['is_active' => false]);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    Event::assertNotDispatched(SeasonStarted::class);

    $campaign->update(['is_active' => true]);

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

test('changing campaign starts_at (future -> past) syncs', function () {
    Event::fake([SeasonStarted::class]);
    $campaign = e15Camp(['is_active' => true, 'starts_at' => now()->addDay()]);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    Event::assertNotDispatched(SeasonStarted::class);

    $campaign->update(['starts_at' => now()->subMinute()]);

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

test('changing campaign ends_at (past -> future) syncs a season that never started', function () {
    Event::fake([SeasonStarted::class]);
    $campaign = e15Camp(['is_active' => true, 'ends_at' => now()->subHour()]);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    Event::assertNotDispatched(SeasonStarted::class);

    $campaign->update(['ends_at' => now()->addDay()]);

    Event::assertDispatchedTimes(SeasonStarted::class, 1);
});

test('unrelated campaign edits do not trigger a sync (the hook watches only is_active/starts_at/ends_at)', function () {
    Event::fake([SeasonStarted::class]);
    $s = e15Season(); // مباشر فعليًا لكن بلا تسجيل بدء (الخطافات معطّلة عند بنائه)

    $s->campaign->update(['title' => 'عنوان جديد فقط']);

    expect($s->fresh()->went_live_at)->toBeNull();
    Event::assertNotDispatched(SeasonStarted::class);
});

test('a sync failure inside a save hook never breaks the admin save', function () {
    $campaign = e15Camp(['is_active' => false]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->app->bind(SeasonLifecycleService::class, fn () => throw new RuntimeException('lifecycle is down'));

    $campaign->update(['is_active' => true]);
    $season->update(['is_featured' => true, 'is_published' => false]);

    expect($campaign->fresh()->is_active)->toBeTrue()->and($season->fresh()->is_published)->toBeFalse();
});

// ============================ المواسم القديمة: لا إشعارات رجعية ============================

test('seasons that were already live before the migration are backfilled silently - no event, no notification, ever', function () {
    Artisan::call('migrate:rollback', ['--path' => E15_SEASON_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('seasons', 'went_live_at'))->toBeFalse();

    $mk = fn (array $campaign, array $season = []) => Season::withoutEvents(fn () => Season::factory()->create($season + [
        'campaign_id' => e15Camp($campaign + ['is_active' => true, 'starts_at' => null, 'ends_at' => null])->id,
        'is_published' => true,
    ]));

    $liveNow = $mk([]);
    $liveWindow = $mk(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $endedWhileActive = $mk(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]); // بدأ سابقًا وانتهى
    $upcoming = $mk(['starts_at' => now()->addDays(3)]);
    $unpublished = $mk([], ['is_published' => false]);
    $inactive = $mk(['is_active' => false]);
    User::factory()->count(3)->create();

    Event::fake([SeasonStarted::class]);
    Artisan::call('migrate', ['--path' => E15_SEASON_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('seasons', 'went_live_at'))->toBeTrue();

    $filled = DB::table('seasons')->whereNotNull('went_live_at')->pluck('id')->sort()->values()->all();
    expect($filled)->toBe(collect([$liveNow, $liveWindow, $endedWhileActive])->pluck('id')->sort()->values()->all())
        ->and(DB::table('seasons')->whereIn('id', [$upcoming->id, $unpublished->id, $inactive->id])->whereNotNull('went_live_at')->count())->toBe(0);

    // بعد الترحيل: لا حدث ولا إشعار للقديمة، حتى لو شُغّلت المزامنة أو مُدِّد موسم منتهٍ.
    $this->artisan('seasons:sync-live-state')->assertExitCode(0);
    $endedWhileActive->campaign->update(['ends_at' => now()->addWeek()]);
    Event::assertNotDispatched(SeasonStarted::class);
    expect(DatabaseNotification::count())->toBe(0);

    // أما "القادم" فيُنبَّه عند بدئه الفعلي الأول فقط.
    Carbon::setTestNow(now()->addDays(4));
    $this->artisan('seasons:sync-live-state');
    Event::assertDispatchedTimes(SeasonStarted::class, 1);
    Event::assertDispatched(SeasonStarted::class, fn ($e) => $e->season->id === $upcoming->id);
});

// ============================ الربط بإشعارات E15 ============================

test('SeasonStarted creates exactly one season_started notification per eligible user - and never repeats', function () {
    $eligible = User::factory()->count(4)->create();
    $optedOut = User::factory()->create();
    app(NotificationPreferenceService::class)->update($optedOut, ['season_enabled' => false]);
    $unverified = User::factory()->create(['email_verified_at' => null]);
    $frozen = User::factory()->create(['is_frozen' => true]);

    $campaign = e15Camp(['title' => 'موسم الاختبار الكبير', 'is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'slug' => 'big-test-season', 'is_published' => true]);

    $rows = DatabaseNotification::where('type_key', 'season_started')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('notifiable_id')->sort()->values()->all())->toBe($eligible->pluck('id')->sort()->values()->all())
        ->and($rows->every(fn ($r) => $r->category === 'season'))->toBeTrue()
        ->and($rows->first()->data['title'])->toContain('موسم الاختبار الكبير')
        ->and($rows->first()->data['action_route'])->toBe('seasons.show')
        ->and($rows->first()->data['action_params'])->toBe(['season' => 'big-test-season'])
        ->and(app(NotificationUrlResolver::class)->resolve($rows->first()->data))->toBe('/seasons/big-test-season')
        ->and($rows->first()->idempotency_key)->toBe("season-start:{$season->id}:{$rows->first()->notifiable_id}");

    foreach ([$optedOut, $unverified, $frozen] as $excluded) {
        expect(DatabaseNotification::where('notifiable_id', $excluded->id)->count())->toBe(0);
    }

    // إعادة تشغيل الدفعة، وإعادة المزامنة، وإعادة التفعيل: لا تكرار.
    DispatchSeasonStartedChunk::dispatch($season->id, 0);
    DispatchSeasonStartedChunk::dispatch($season->id, 0);
    $this->lifecycle->syncLiveState($season);
    $campaign->update(['is_active' => false]);
    $campaign->update(['is_active' => true]);

    expect(DatabaseNotification::where('type_key', 'season_started')->count())->toBe(4);
});

test('the global notifications switch suppresses season notifications (the season still starts)', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    User::factory()->count(2)->create();

    $campaign = e15Camp(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    expect($season->fresh()->went_live_at)->not->toBeNull()->and(DatabaseNotification::count())->toBe(0);
});

test('the fan-out runs in bounded chunks and still reaches every user exactly once', function () {
    config(['player_notifications.chunk_size' => 3]);
    User::factory()->count(8)->create();

    $campaign = e15Camp(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $perUser = DatabaseNotification::where('type_key', 'season_started')->pluck('notifiable_id')->countBy();

    expect($perUser)->toHaveCount(8)->and($perUser->every(fn ($n) => $n === 1))->toBeTrue();
});

test('the season notification grants nothing: no XP, currency, quest progress, streak or purchase', function () {
    User::factory()->count(3)->create();
    $snapshot = fn () => [
        DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count(), UserQuestProgress::count(),
        PlayerStreak::count(), StorePurchase::count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
    ];
    $before = $snapshot();

    $campaign = e15Camp(['is_active' => true]);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    expect(DatabaseNotification::where('type_key', 'season_started')->count())->toBe(3)->and($snapshot())->toBe($before);
});

test('a broken notification pipeline never breaks the season: it still starts and the admin save succeeds', function () {
    User::factory()->count(2)->create();
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));

    $campaign = e15Camp(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $campaign->update(['title' => 'عنوان بعد العطل']);

    expect($season->fresh()->went_live_at)->not->toBeNull()
        ->and($campaign->fresh()->title)->toBe('عنوان بعد العطل')
        ->and(DatabaseNotification::count())->toBe(0);
});

// ============================ الجدولة وعدم تكرار المنطق ============================

test('the sync command is registered with the scheduler (every five minutes)', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('seasons:sync-live-state');
});

test('single source of truth: the live definition lives only in SeasonLifecycleService (command, listener, job and hooks hold no second copy)', function () {
    foreach (['Console/Commands/SyncSeasonLiveState.php', 'Listeners/SendSeasonStartedNotifications.php', 'Jobs/DispatchSeasonStartedChunk.php'] as $file) {
        // الكود فقط: نُسقِط التعليقات (الشرح بها لا يعدّ نسخة ثانية من المنطق).
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path($file)));

        foreach (['isCampaignAvailable', 'starts_at', 'ends_at', 'is_active', "'is_published', true)->whereNull"] as $needle) {
            if ($file === 'Console/Commands/SyncSeasonLiveState.php' && $needle === "'is_published', true)->whereNull") {
                continue; // الأمر يضيّق المرشَّحين فقط (منشور ولم يبدأ) ولا يقرّر "مباشر".
            }

            expect(str_contains($code, $needle))->toBeFalse("{$file} must not contain {$needle}");
        }
    }

    expect(str_contains(file_get_contents(app_path('Services/SeasonLifecycleService.php')), 'isCampaignAvailable'))->toBeTrue();
});

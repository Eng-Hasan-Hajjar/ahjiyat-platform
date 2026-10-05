<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Events\CampaignBecameAvailable;
use App\Jobs\DispatchCampaignAvailableChunk;
use App\Models\Campaign;
use App\Models\PlayerStreak;
use App\Models\Season;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\CampaignLifecycleService;
use App\Services\CampaignProgressService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->lifecycle = app(CampaignLifecycleService::class);
});

afterEach(fn () => Carbon::setTestNow());

/** حملة بلا أحداث النموذج ليتحكم الاختبار بلحظة المزامنة بنفسه. */
function e15cCampaign(array $attrs = []): Campaign
{
    return Campaign::withoutEvents(fn () => Campaign::factory()->create($attrs + ['is_active' => true, 'starts_at' => null, 'ends_at' => null]));
}

const E15_CAMPAIGN_MIGRATION = 'database/migrations/2026_10_09_000001_add_became_available_at_to_campaigns_table.php';

// ============================ متى تُتاح الحملة ومتى لا ============================

test('a campaign that is not available never becomes available: inactive, future start, already ended', function (array $attrs) {
    Event::fake([CampaignBecameAvailable::class]);
    $c = e15cCampaign($attrs);

    expect($this->lifecycle->syncAvailability($c))->toBeFalse()->and($c->fresh()->became_available_at)->toBeNull();
    Event::assertNotDispatched(CampaignBecameAvailable::class);
})->with([
    'inactive' => [['is_active' => false]],
    'active but starts_at is in the future' => [['starts_at' => '+2 hours']],
    'active but the window already ended' => [['ends_at' => '-1 hour']],
]);

test('when starts_at is reached became_available_at is recorded ONCE and the event fires ONCE, however often the scheduler runs', function () {
    Carbon::setTestNow('2026-10-10 10:00:00');
    Event::fake([CampaignBecameAvailable::class]);
    $c = e15cCampaign(['starts_at' => now()->addHours(2)]);

    $this->artisan('campaigns:sync-availability')->assertExitCode(0);
    expect($c->fresh()->became_available_at)->toBeNull();
    Event::assertNotDispatched(CampaignBecameAvailable::class);

    Carbon::setTestNow('2026-10-10 12:30:00');
    $this->artisan('campaigns:sync-availability')->expectsOutputToContain('وأُتيح منها فعلًا: 1');
    $recorded = $c->fresh()->became_available_at->toDateTimeString();
    expect($recorded)->toBe('2026-10-10 12:30:00');

    Carbon::setTestNow('2026-10-10 15:00:00');
    $this->artisan('campaigns:sync-availability')->expectsOutputToContain('فُحصت 0 حملات');
    $this->lifecycle->syncAvailability($c);
    $this->lifecycle->syncAvailability($c->id);
    $this->artisan('campaigns:sync-availability');

    Event::assertDispatchedTimes(CampaignBecameAvailable::class, 1);
    expect($c->fresh()->became_available_at->toDateTimeString())->toBe($recorded);
});

test('the transition is atomic: if another process wins the race this call updates nothing and fires nothing', function () {
    Event::fake([CampaignBecameAvailable::class]);
    $c = e15cCampaign();

    $racing = new class(app(CampaignProgressService::class), $c->id) extends CampaignLifecycleService
    {
        public function __construct(CampaignProgressService $p, protected int $id)
        {
            parent::__construct($p);
        }

        public function isAvailable(Campaign $campaign): bool
        {
            Campaign::query()->whereKey($this->id)->update(['became_available_at' => '2026-01-01 00:00:00']);

            return true;
        }
    };

    expect($racing->syncAvailability($c))->toBeFalse()
        ->and($c->fresh()->became_available_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
    Event::assertNotDispatched(CampaignBecameAvailable::class);
});

test('first-ever availability only: deactivating and reactivating, or moving the window, never fires a second event', function () {
    Event::fake([CampaignBecameAvailable::class]);
    $c = Campaign::factory()->create(['is_active' => true]); // متاحة فعليًا عند الإنشاء
    $first = $c->fresh()->became_available_at;

    expect($first)->not->toBeNull();
    Event::assertDispatchedTimes(CampaignBecameAvailable::class, 1);

    Carbon::setTestNow(now()->addDay());
    $c->update(['is_active' => false]);
    $c->update(['is_active' => true]);
    $c->update(['starts_at' => now()->addDay()]);
    $c->update(['starts_at' => now()->subHour()]);
    $c->update(['ends_at' => now()->subMinute()]);
    $c->update(['ends_at' => now()->addWeek()]);
    $this->lifecycle->syncAvailability($c);
    $this->artisan('campaigns:sync-availability');

    Event::assertDispatchedTimes(CampaignBecameAvailable::class, 1);
    expect($c->fresh()->became_available_at->toDateTimeString())->toBe($first->toDateTimeString());
});

test('the event fires only AFTER commit: a rolled-back transition leaves no event and no became_available_at', function () {
    Event::fake([CampaignBecameAvailable::class]);
    $c = e15cCampaign();

    try {
        DB::transaction(function () use ($c) {
            $this->lifecycle->syncAvailability($c);
            throw new RuntimeException('outer transaction fails');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CampaignBecameAvailable::class);
    expect($c->fresh()->became_available_at)->toBeNull();

    $this->lifecycle->syncAvailability($c);
    Event::assertDispatchedTimes(CampaignBecameAvailable::class, 1);
});

// ============================ خطافات الحفظ ============================

test('every real reason triggers the sync: created available, is_active, starts_at and ends_at', function () {
    Event::fake([CampaignBecameAvailable::class]);

    Campaign::factory()->create(['is_active' => true]);                                                       // أُنشئت متاحة
    $a = Campaign::factory()->create(['is_active' => false]);  $a->update(['is_active' => true]);              // تفعيل
    $b = Campaign::factory()->create(['is_active' => true, 'starts_at' => now()->addDay()]); $b->update(['starts_at' => now()->subMinute()]); // بدء
    $c = Campaign::factory()->create(['is_active' => true, 'ends_at' => now()->subHour()]);  $c->update(['ends_at' => now()->addDay()]);     // تمديد حملة لم تُتَح قط

    Event::assertDispatchedTimes(CampaignBecameAvailable::class, 4);
});

test('unrelated campaign edits do not trigger a sync (the hook watches only creation and is_active/starts_at/ends_at)', function () {
    Event::fake([CampaignBecameAvailable::class]);
    $c = e15cCampaign(); // متاحة لكن بلا تسجيل (الخطافات معطّلة عند بنائها)

    $c->update(['title' => 'عنوان جديد فقط', 'description' => 'وصف']);

    expect($c->fresh()->became_available_at)->toBeNull();
    Event::assertNotDispatched(CampaignBecameAvailable::class);
});

test('a sync failure inside the save hook never breaks the admin save', function () {
    $c = Campaign::factory()->create(['is_active' => false]);
    $this->app->bind(CampaignLifecycleService::class, fn () => throw new RuntimeException('lifecycle is down'));

    $c->update(['is_active' => true, 'title' => 'حُفظت رغم العطل']);

    expect($c->fresh()->is_active)->toBeTrue()->and($c->fresh()->title)->toBe('حُفظت رغم العطل');
});

// ============================ الحملات القديمة: لا إشعارات رجعية ============================

test('campaigns that were already available before the migration are backfilled silently - no event, no notification, ever', function () {
    Artisan::call('migrate:rollback', ['--path' => E15_CAMPAIGN_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('campaigns', 'became_available_at'))->toBeFalse();

    $availableNow = e15cCampaign();
    $liveWindow = e15cCampaign(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $endedWhileActive = e15cCampaign(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
    $upcoming = e15cCampaign(['starts_at' => now()->addDays(3)]);
    $inactive = e15cCampaign(['is_active' => false]);
    User::factory()->count(3)->create();

    Event::fake([CampaignBecameAvailable::class]);
    Artisan::call('migrate', ['--path' => E15_CAMPAIGN_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('campaigns', 'became_available_at'))->toBeTrue();

    $filled = DB::table('campaigns')->whereNotNull('became_available_at')->pluck('id')->sort()->values()->all();
    expect($filled)->toBe(collect([$availableNow, $liveWindow, $endedWhileActive])->pluck('id')->sort()->values()->all())
        ->and(DB::table('campaigns')->whereIn('id', [$upcoming->id, $inactive->id])->whereNotNull('became_available_at')->count())->toBe(0);

    $this->artisan('campaigns:sync-availability')->assertExitCode(0);
    $endedWhileActive->update(['ends_at' => now()->addWeek()]); // تمديد حملة منتهية قديمة
    Event::assertNotDispatched(CampaignBecameAvailable::class);
    expect(DatabaseNotification::count())->toBe(0);

    Carbon::setTestNow(now()->addDays(4)); // "القادمة" تُنبَّه عند أول إتاحة فعلية فقط
    $this->artisan('campaigns:sync-availability');
    Event::assertDispatchedTimes(CampaignBecameAvailable::class, 1);
    Event::assertDispatched(CampaignBecameAvailable::class, fn ($e) => $e->campaign->id === $upcoming->id);
});

// ============================ الربط بإشعارات E15 ============================

test('availability creates exactly one campaign_available notification per eligible user - and never repeats', function () {
    $eligible = User::factory()->count(4)->create();
    $optedOut = User::factory()->create();
    app(NotificationPreferenceService::class)->update($optedOut, ['campaign_enabled' => false]);
    $unverified = User::factory()->create(['email_verified_at' => null]);
    $frozen = User::factory()->create(['is_frozen' => true]);

    $campaign = Campaign::factory()->create(['title' => 'حملة الاختبار الكبرى', 'slug' => 'big-test-campaign', 'is_active' => true]);

    $rows = DatabaseNotification::where('type_key', 'campaign_available')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('notifiable_id')->sort()->values()->all())->toBe($eligible->pluck('id')->sort()->values()->all())
        ->and($rows->every(fn ($r) => $r->category === 'campaign'))->toBeTrue()
        ->and($rows->first()->data['title'])->toContain('حملة الاختبار الكبرى')
        ->and($rows->first()->data['action_route'])->toBe('campaigns.show')
        ->and($rows->first()->data['action_params'])->toBe(['campaign' => 'big-test-campaign'])
        ->and(app(NotificationUrlResolver::class)->resolve($rows->first()->data))->toBe('/campaigns/big-test-campaign')
        ->and($rows->first()->idempotency_key)->toBe("campaign-available:{$campaign->id}:{$rows->first()->notifiable_id}");

    foreach ([$optedOut, $unverified, $frozen] as $excluded) {
        expect(DatabaseNotification::where('notifiable_id', $excluded->id)->count())->toBe(0);
    }

    DispatchCampaignAvailableChunk::dispatch($campaign->id, 0);
    DispatchCampaignAvailableChunk::dispatch($campaign->id, 0);
    $this->lifecycle->syncAvailability($campaign);
    $campaign->update(['is_active' => false]);
    $campaign->update(['is_active' => true]);

    expect(DatabaseNotification::where('type_key', 'campaign_available')->count())->toBe(4);
});

test('the notification leads to a page every user can actually reach (the campaign is globally available)', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['slug' => 'reachable-campaign', 'is_active' => true]);
    $url = app(NotificationUrlResolver::class)->resolve(DatabaseNotification::where('type_key', 'campaign_available')->first()->data);

    expect($url)->toBe('/campaigns/reachable-campaign');
    $this->get($url)->assertOk();                       // ضيف
    $this->actingAs($user)->get($url)->assertOk();      // أي مستخدم
});

test('a campaign bound to a season is announced by season_started - no duplicate campaign_available (the event itself still fires)', function () {
    User::factory()->count(3)->create();
    $fired = [];
    Event::listen(CampaignBecameAvailable::class, function ($e) use (&$fired) {
        $fired[] = $e->campaign->id;
    });

    $campaign = e15cCampaign();
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]));
    $this->lifecycle->syncAvailability($campaign);

    expect($fired)->toBe([$campaign->id])->and(DatabaseNotification::where('type_key', 'campaign_available')->count())->toBe(0);
});

test('no notification about a campaign that is no longer available when the queued job finally runs (its page would be a 404)', function () {
    $users = User::factory()->count(3)->create();
    $c = e15cCampaign(['is_active' => false]);
    DB::table('campaigns')->where('id', $c->id)->update(['became_available_at' => now()->subHour()]); // أُتيحت سابقًا ثم أُوقفت

    DispatchCampaignAvailableChunk::dispatch($c->id, 0);
    expect(DatabaseNotification::count())->toBe(0);

    $c->update(['is_active' => true]);
    DispatchCampaignAvailableChunk::dispatch($c->id, 0);
    expect(DatabaseNotification::where('type_key', 'campaign_available')->count())->toBe(3);
});

test('the global notifications switch suppresses campaign notifications (the campaign still becomes available)', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    User::factory()->count(2)->create();

    $c = Campaign::factory()->create(['is_active' => true]);

    expect($c->fresh()->became_available_at)->not->toBeNull()->and(DatabaseNotification::count())->toBe(0);
});

test('the fan-out runs in bounded chunks and still reaches every user exactly once', function () {
    config(['player_notifications.chunk_size' => 3]);
    User::factory()->count(8)->create();

    Campaign::factory()->create(['is_active' => true]);

    $perUser = DatabaseNotification::where('type_key', 'campaign_available')->pluck('notifiable_id')->countBy();

    expect($perUser)->toHaveCount(8)->and($perUser->every(fn ($n) => $n === 1))->toBeTrue();
});

test('opening and reading the notification grants nothing: no XP, currency, quest, streak, purchase or wallet change', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $note = DatabaseNotification::where('type_key', 'campaign_available')->where('notifiable_id', $user->id)->first();

    $snapshot = fn () => [
        DB::table('xp_transactions')->count(), DB::table('currency_transactions')->count(), UserQuestProgress::count(),
        PlayerStreak::count(), StorePurchase::count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
    ];
    $before = $snapshot();

    $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('campaigns.show', $campaign));
    $this->post(route('notifications.read-all'));

    expect($note->fresh()->read_at)->not->toBeNull()->and($snapshot())->toBe($before);
});

test('a broken notification pipeline never breaks the campaign: it still becomes available and the admin save succeeds', function () {
    User::factory()->count(2)->create();
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));

    $c = Campaign::factory()->create(['is_active' => true]);
    $c->update(['title' => 'عنوان بعد العطل']);

    expect($c->fresh()->became_available_at)->not->toBeNull()->and($c->fresh()->title)->toBe('عنوان بعد العطل')->and(DatabaseNotification::count())->toBe(0);
});

// ============================ الجدولة وعدم تكرار المنطق ============================

test('the sync command is registered with the scheduler (every five minutes)', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('campaigns:sync-availability');
});

test('single source of truth: the availability definition lives only in CampaignProgressService, reused by CampaignLifecycleService (no second copy)', function () {
    foreach (['Console/Commands/SyncCampaignAvailability.php', 'Listeners/SendCampaignAvailableNotifications.php', 'Jobs/DispatchCampaignAvailableChunk.php'] as $file) {
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path($file)));

        foreach (['isCampaignAvailable', 'starts_at', 'ends_at'] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse("{$file} must not contain {$needle}");
        }

        if ($file !== 'Console/Commands/SyncCampaignAvailability.php') {
            expect(str_contains($code, 'is_active'))->toBeFalse("{$file} must not contain is_active"); // الأمر يضيّق المرشَّحين فقط
        }
    }

    $service = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents(app_path('Services/CampaignLifecycleService.php')));
    expect(str_contains($service, 'isCampaignAvailable'))->toBeTrue()->and(str_contains($service, 'starts_at'))->toBeFalse();
});

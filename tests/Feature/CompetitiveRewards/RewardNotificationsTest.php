<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Events\CompetitiveRewardGranted;
use App\Models\CompetitiveRewardGrant;
use App\Models\User;
use App\Services\Competitive\Rewards\CompetitiveRewardDistributionService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e18Notes(User $user)
{
    return e16Notes('competitive_reward_granted', $user);
}

test('41/E9: a granted reward sends ONE notification with a semantic key, the right placement text and a link to the event page', function () {
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'xp', 'amount' => 80]);
    e18Rule($event, ['min_rank' => 2, 'max_rank' => 5, 'reward_type' => 'xp', 'amount' => 20]);
    e18Rule($event, ['kind' => 'participation', 'min_rank' => null, 'max_rank' => null, 'reward_type' => 'xp', 'amount' => 5]);
    $users = collect(range(1, 7))->map(fn () => e16User())->all();

    e18Run($event, array_map(fn ($u, $i) => [$u, E17_ANSWER, $i * 5_000], $users, range(1, 7)));
    $grant = e18Grant($event, $users[0]);
    $note = e18Notes($users[0])->sole();

    expect($note->idempotency_key)->toBe("competitive-reward-granted:{$grant->id}")->and($note->category)->toBe('competitive')
        ->and($note->data['title'])->toBe("حصلت على جائزة المركز الأول في {$event->title}")->and($note->data['body'])->toBe('مُنحت لك: 80 نقطة خبرة.')
        ->and(app(NotificationUrlResolver::class)->resolve($note->data))->toBe("/competitions/{$event->slug}")
        ->and(e18Notes($users[1])->sole()->data['title'])->toBe("حصلت على جائزة المركز الثاني في {$event->title}")
        ->and(e18Notes($users[6])->sole()->data['title'])->toBe("حصلت على جائزة المشاركة في {$event->title}")
        ->and(e16Notes('competitive_reward_granted'))->toHaveCount(7);

    foreach (range(1, 3) as $i) {
        event(new CompetitiveRewardGranted($grant->id)); // إعادة بث
    }
    expect(e18Notes($users[0]))->toHaveCount(1);
});

test('42/E8: a failed reward sends no success notification - not even from a replayed event', function () {
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    $user = e16User();
    $currency->update(['is_earnable' => false]);

    e18Run($event, [[$user, E17_ANSWER, 10_000]]);
    $grant = e18Grant($event, $user);
    event(new CompetitiveRewardGranted($grant->id));
    event(new CompetitiveRewardGranted($grant->id));

    expect($grant->status)->toBe('failed')->and(e18Notes($user))->toHaveCount(0);
});

test('43: a failed reward that succeeds on retry produces exactly one notification - and a second retry adds none', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    $user = e16User();
    $currency->update(['is_earnable' => false]);
    e18Run($event, [[$user, E17_ANSWER, 10_000]]);
    expect(e18Notes($user))->toHaveCount(0);

    $currency->update(['is_earnable' => true]);
    $admin = e16User();
    $admin->givePermissionTo('competitive_events.rewards.retry');
    app(CompetitiveRewardDistributionService::class)->retryFailed($event, $admin);
    app(CompetitiveRewardDistributionService::class)->retryFailed($event, $admin);

    expect(e18Grant($event, $user)->status)->toBe('granted')->and(e18Notes($user))->toHaveCount(1)->and(e18Pending($user, $currency))->toBe(100);
});

test('44/45: with the competitive preference off, or global notifications off, the reward is still granted and nothing is notified', function () {
    $event = e18Event();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'xp', 'amount' => 40]);
    [$optedOut, $global] = [e16User(), e16User()];
    app(NotificationPreferenceService::class)->update($optedOut, ['competitive_enabled' => false]);

    e17Forward(2 * 3600_000);
    e17PlayEvent($optedOut, $event, E17_ANSWER, 10_000);
    e17PlayEvent($global, $event, E17_ANSWER, 5_000);
    e17Forward(5 * 3600_000);
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false); // الإيقاف الشامل وقت الاعتماد
    app(\App\Services\Competitive\CompetitiveEventFinalizer::class)->finalize($event->refresh());

    expect(e18Xp($optedOut))->toBe(40)->and(e18Xp($global))->toBe(40)->and(e18Grant($event, $optedOut)->status)->toBe('granted')->and(e18Grant($event, $global)->status)->toBe('granted')
        ->and(DatabaseNotification::where('type_key', 'competitive_reward_granted')->count())->toBe(0);

    // التفضيل وحده (والإشعارات الشاملة مفعّلة): المستخدم الذي لم يُطفئ يُشعَر.
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', true);
    $second = e18Event(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(5)]);
    e18Rule($second, ['reward_type' => 'xp', 'amount' => 10]);
    e17Forward(2 * 3600_000);
    e17PlayEvent($optedOut, $second, E17_ANSWER, 5_000);
    e17PlayEvent($global, $second, E17_ANSWER, 9_000);
    e17Forward(5 * 3600_000);
    app(\App\Services\Competitive\CompetitiveEventFinalizer::class)->finalize($second->refresh());

    expect(e18Notes($optedOut))->toHaveCount(0)->and(e18Notes($global))->toHaveCount(0)->and(e18Xp($optedOut))->toBe(50); // الأول فاز فقط: المركز الثاني بلا قاعدة هنا
});

test('46: a failing notification pipeline never rolls back a granted reward', function () {
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 60]);
    $user = e16User();

    e18Run($event, [[$user, E17_ANSWER, 10_000]]);

    expect(e18Grant($event, $user)->status)->toBe('granted')->and(e18Pending($user, $currency))->toBe(60)->and($event->refresh()->status)->toBe('completed')
        ->and(DatabaseNotification::count())->toBe(0);
});

test('47: opening a reward notification grants nothing - no second reward, no new ledger row, no state change', function () {
    $event = e18Event();
    $currency = e18Currency();
    e18Rule($event, ['reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 75]);
    $user = e16User();
    e18Run($event, [[$user, E17_ANSWER, 10_000]]);
    $note = e18Notes($user)->sole();
    $before = [e17Snapshot(), CompetitiveRewardGrant::count(), e18Pending($user, $currency)];

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('competitions.show', $event));
    }
    $this->actingAs($user)->post(route('notifications.read-all'));

    expect([e17Snapshot(), CompetitiveRewardGrant::count(), e18Pending($user, $currency)])->toBe($before)->and($note->fresh()->read_at)->not->toBeNull();
});

test('the competitive reward type is registered in the notification registry with a real route and the competitive category', function () {
    $type = \App\Services\Notifications\NotificationType::CompetitiveRewardGranted;

    expect($type->category())->toBe(\App\Services\Notifications\NotificationCategory::Competitive)->and($type->allowedRoutes())->toBe(['competitions.show'])
        ->and(\Illuminate\Support\Facades\Route::has('competitions.show'))->toBeTrue();
});

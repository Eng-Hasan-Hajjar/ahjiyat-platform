<?php

require_once __DIR__.'/CompetitiveTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\FriendChallengeResult;
use App\Services\Competitive\CompetitiveLifecycleService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e17Lifecycle(): array
{
    return app(CompetitiveLifecycleService::class)->run();
}

/** حدث يبدأ بعد ساعتين ويمتد 30 ساعة: يُسجَّل المشاركون قبل البدء ثم يتقدّم الزمن. */
function e17Upcoming(array $attrs = []): CompetitiveEvent
{
    return e17Event($attrs + ['starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(32)]);
}

test('54/55: competitive_event_started goes to registered participants only - not to non-participants, late registrants or finishers', function () {
    $event = e17Upcoming();
    [$registered, $outsider, $late] = [e16User(), e16User(), e16User()];
    e17Events()->register($registered, $event);

    e17Forward(3 * 3600_000); // بدأ
    e17Events()->register($late, $event); // سجّل بعد البدء: لا "بدأت"
    e17Lifecycle();

    $rows = e16Notes('competitive_event_started');
    expect($rows)->toHaveCount(1)->and($rows->first()->notifiable_id)->toBe($registered->id)
        ->and($rows->first()->idempotency_key)->toBe("competitive-event-started:{$event->id}:{$registered->id}")
        ->and($rows->first()->category)->toBe('competitive')->and($rows->first()->data['title'])->toBe("بدأت المنافسة: {$event->title}")
        ->and(app(NotificationUrlResolver::class)->resolve($rows->first()->data))->toBe("/competitions/{$event->slug}")
        ->and(e16Notes('competitive_event_started', $outsider))->toHaveCount(0)->and(e16Notes('competitive_event_started', $late))->toHaveCount(0);
});

test('56/57: ending-soon reaches only participants who have not finished, once; a finisher gets nothing', function () {
    $event = e17Event(['ends_at' => now()->addHours(5)]); // ضمن نافذة 24 ساعة
    [$waiting, $finished] = [e16User(), e16User()];
    e17Events()->register($waiting, $event);
    e17PlayEvent($finished, $event, E17_ANSWER, 10_000);

    e17Lifecycle();
    e17Lifecycle();

    $rows = e16Notes('competitive_event_ending_soon');
    expect($rows)->toHaveCount(1)->and($rows->first()->notifiable_id)->toBe($waiting->id)
        ->and($rows->first()->idempotency_key)->toBe("competitive-event-ending:{$event->id}:{$waiting->id}")
        ->and($rows->first()->data['body'])->toContain('24 ساعة')->and(e16Notes('competitive_event_ending_soon', $finished))->toHaveCount(0);
});

test('56b: an event far from its end sends no ending-soon reminder; the daily re-engagement budget caps reminders per user', function () {
    $far = e17Event(['ends_at' => now()->addDays(5)]);
    $user = e16User();
    e17Events()->register($user, $far);
    e17Lifecycle();
    expect(e16Notes('competitive_event_ending_soon'))->toHaveCount(0);

    // حدثان ينتهيان خلال النافذة لنفس المستخدم: الميزانية اليومية (1) تسمح بتذكير واحد فقط.
    $one = e17Event(['ends_at' => now()->addHours(4)]);
    $two = e17Event(['ends_at' => now()->addHours(6)]);
    e17Events()->register($user, $one);
    e17Events()->register($user, $two);
    $stats = e17Lifecycle();

    expect(e16Notes('competitive_event_ending_soon', $user))->toHaveCount(1)->and($stats['ending_soon']['over_budget'])->toBeGreaterThanOrEqual(1);
});

test('58: result_ready is sent once per participant with a result after finalization, leading to the event page', function () {
    $event = e17Event();
    [$winner, $second, $noShow] = [e16User(), e16User(), e16User()];
    e17PlayEvent($winner, $event, E17_ANSWER, 10_000);
    e17PlayEvent($second, $event, E17_ANSWER, 40_000);
    e17Events()->register($noShow, $event);

    e17Forward(40 * 3600_000); // انتهى
    $stats = e17Lifecycle();
    e17Lifecycle(); // تشغيل مكرر

    $rows = e16Notes('competitive_event_result_ready');
    expect($stats['finalized'])->toBe(1)->and($rows)->toHaveCount(2)->and(e16Notes('competitive_event_result_ready', $noShow))->toHaveCount(0)
        ->and(e16Notes('competitive_event_result_ready', $winner)->first()->data['body'])->toBe('ترتيبك النهائي: 1.')
        ->and(e16Notes('competitive_event_result_ready', $second)->first()->data['body'])->toBe('ترتيبك النهائي: 2.')
        ->and(e16Notes('competitive_event_result_ready', $winner)->first()->idempotency_key)->toBe("competitive-event-result:{$event->id}:{$winner->id}");

    $note = e16Notes('competitive_event_result_ready', $winner)->first();
    $this->actingAs($winner)->post(route('notifications.open', $note->id))->assertRedirect(route('competitions.show', $event));
});

test('59/60: with the competitive preference off no notification is created - the result and ranking stay correct', function () {
    $event = e17Event();
    $user = e16User();
    app(NotificationPreferenceService::class)->update($user, ['competitive_enabled' => false]);
    e17PlayEvent($user, $event, E17_ANSWER, 10_000);

    e17Forward(40 * 3600_000);
    e17Lifecycle();

    expect(DatabaseNotification::where('notifiable_id', $user->id)->whereIn('type_key', ['competitive_event_started', 'competitive_event_ending_soon', 'competitive_event_result_ready'])->count())->toBe(0)
        ->and(CompetitiveEventResult::sole()->final_rank)->toBe(1)->and($event->refresh()->status)->toBe('completed');
});

test('61: with global notifications off the competition works and nothing is created', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    $event = e17Upcoming();
    $user = e16User();
    e17Events()->register($user, $event);
    e17Forward(3 * 3600_000);
    e17Events()->start($user, $event);
    e17Forward(20_000);
    e17Events()->submit($user, $event, ['answer' => E17_ANSWER]);
    e17Forward(40 * 3600_000);
    e17Lifecycle();

    expect(DatabaseNotification::count())->toBe(0)->and(CompetitiveEventResult::sole()->final_rank)->toBe(1);
});

test('62: running the scheduler repeatedly never duplicates a competitive notification', function () {
    $event = e17Upcoming(['ends_at' => now()->addHours(10)]);
    $user = e16User();
    e17Events()->register($user, $event);
    e17Forward(3 * 3600_000);

    foreach (range(1, 4) as $i) {
        e17Lifecycle();
    }

    expect(e16Notes('competitive_event_started'))->toHaveCount(1)->and(e16Notes('competitive_event_ending_soon'))->toHaveCount(1);
    $this->artisan('competitive:process-lifecycle')->assertSuccessful();
    expect(e16Notes('competitive_event_started'))->toHaveCount(1);
});

test('63: the social preference governs friend-challenge notifications (and only those)', function () {
    [$a, $b] = e17Friends();
    app(NotificationPreferenceService::class)->update($b, ['social_enabled' => false]);
    app(NotificationPreferenceService::class)->update($a, ['competitive_enabled' => false]); // لا تأثير على التحديات

    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 20_000);

    expect(e16Notes('friend_challenge_received', $b))->toHaveCount(0)        // B أطفأ الاجتماعي
        ->and(e16Notes('friend_challenge_result_ready', $b))->toHaveCount(0)
        ->and(e16Notes('friend_challenge_accepted', $a))->toHaveCount(1)       // A لم يُطفئ الاجتماعي (طفأ التنافسي فقط)
        ->and(e16Notes('friend_challenge_result_ready', $a))->toHaveCount(1)
        ->and($challenge->refresh()->status)->toBe('completed')->and(FriendChallengeResult::count())->toBe(2); // اللعب سليم
});

test('E1/E7-E10: friend-challenge notifications are single, addressed to the right side, with semantic keys and safe routes', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenges()->create($a, $b, e17Puzzle(['title' => 'لغز الغروب']));

    e17Challenges()->accept($b, $challenge);
    e17Challenges()->accept($b, $challenge); // إعادة
    e17Forward(10_000);
    e17PlayChallenge($a, $challenge->refresh(), E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge->refresh(), 'خطأ', 20_000);
    event(new \App\Events\FriendChallengeCompleted($challenge->id)); // إعادة بث

    $received = e16Notes('friend_challenge_received', $b)->sole();
    $accepted = e16Notes('friend_challenge_accepted', $a)->sole();
    $resultA = e16Notes('friend_challenge_result_ready', $a)->sole();
    $resultB = e16Notes('friend_challenge_result_ready', $b)->sole();

    expect(e16Notes('friend_challenge_received', $a))->toHaveCount(0)->and(e16Notes('friend_challenge_accepted', $b))->toHaveCount(0)
        ->and($received->idempotency_key)->toBe("friend-challenge-received:{$challenge->id}")->and($accepted->idempotency_key)->toBe("friend-challenge-accepted:{$challenge->id}")
        ->and($resultA->idempotency_key)->toBe("friend-challenge-result:{$challenge->id}:{$a->id}")->and($resultB->idempotency_key)->toBe("friend-challenge-result:{$challenge->id}:{$b->id}")
        ->and($received->category)->toBe('social')->and($received->data['title'])->toBe("تحدّاك {$a->name} في أحجية")
        ->and($resultA->data['body'])->toContain('فزت')->and($resultB->data['body'])->toContain('خسرت')
        ->and(app(NotificationUrlResolver::class)->resolve($received->data))->toBe("/friends/challenges/{$challenge->public_id}");
});

test('decline, cancel and expiry send no notification; an invalidated challenge sends none either', function () {
    [$a, $b, $c] = [...e17Friends(), e16User()];
    e16Befriend($a, $c);
    $declined = e17Challenges()->create($a, $b, e17Puzzle());
    DatabaseNotification::query()->delete();

    e17Challenges()->decline($b, $declined);
    $cancelled = e17Challenges()->create($a, $c, e17Puzzle());
    DatabaseNotification::query()->where('type_key', 'friend_challenge_received')->delete();
    e17Challenges()->cancel($a, $cancelled);
    e17Forward(48 * 3600_000 + 1);
    e17Lifecycle();

    expect(DatabaseNotification::count())->toBe(0);

    // إشعار "استلمت تحدّيًا" لتحدٍّ لم يعد pending (أُلغي بحظر) لا يُنشأ حتى من حدث معاد.
    [$x, $y] = e17Friends();
    $ch = e17Challenges()->create($x, $y, e17Puzzle());
    DatabaseNotification::query()->delete();
    app(\App\Services\Social\BlockService::class)->block($y, $x);
    event(new \App\Events\FriendChallengeCreated($ch->id));
    expect(DatabaseNotification::count())->toBe(0);
});

test('64: a failing notification pipeline never rolls back a result, a challenge, a registration or a finalization', function () {
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));
    [$a, $b] = e17Friends();
    $event = e17Event();
    $user = e16User();

    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 20_000);
    e17PlayEvent($user, $event, E17_ANSWER, 10_000);
    e17Forward(40 * 3600_000);
    $stats = e17Lifecycle();

    expect($challenge->refresh()->status)->toBe('completed')->and(FriendChallengeResult::count())->toBe(2)
        ->and(CompetitiveEventResult::sole()->final_rank)->toBe(1)->and($event->refresh()->status)->toBe('completed')->and($stats['finalized'])->toBe(1)
        ->and(DatabaseNotification::count())->toBe(0);
});

test('opening any competitive notification grants nothing and changes no gameplay state', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);
    e17PlayChallenge($a, $challenge, E17_ANSWER, 10_000);
    e17PlayChallenge($b, $challenge, E17_ANSWER, 20_000);
    $before = e17Snapshot();

    foreach ([[$b, 'friend_challenge_received'], [$a, 'friend_challenge_accepted'], [$a, 'friend_challenge_result_ready']] as [$user, $type]) {
        $note = e16Notes($type, $user)->first();
        $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('friends.challenges.show', $challenge));
    }

    expect(e17Snapshot())->toBe($before);
});

test('the lifecycle command is registered hourly and the new types have real internal routes', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'competitive:process-lifecycle'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
    foreach (\App\Services\Notifications\NotificationType::cases() as $type) {
        foreach ($type->allowedRoutes() as $route) {
            expect(\Illuminate\Support\Facades\Route::has($route))->toBeTrue("{$type->value} -> {$route}");
        }
    }
});

test('the preferences page lists the competitive category as optional and enabled by default', function () {
    $user = e16User();
    $html = $this->actingAs($user)->get(route('notifications.preferences'))->assertOk()->getContent();

    expect($html)->toContain('المنافسات')->and($html)->toContain('name="competitive_enabled"');
});


test('the hourly run self-heals a drifted participant counter (e.g. after a user deletion cascade) and frees the seat, idempotently', function () {
    $event = e17Event(['max_participants' => 2]);
    [$u1, $u2, $late] = [e16User(), e16User(), e16User()];
    e17Events()->register($u1, $event);
    e17Events()->register($u2, $event);
    expect(fn () => e17Events()->register($late, $event))->toThrow(\App\Services\Competitive\CompetitiveException::class); // ممتلئ

    $u1->delete(); // يحذف صف مشاركته بالـcascade دون إنقاص العدّاد
    expect($event->refresh()->participants_count)->toBe(2);

    $first = e17Lifecycle();
    $second = e17Lifecycle();

    expect($first['counters_fixed'])->toBe(1)->and($second['counters_fixed'])->toBe(0)->and($event->refresh()->participants_count)->toBe(1)
        ->and(e17Events()->register($late, $event)->status)->toBe('registered')->and($event->refresh()->participants_count)->toBe(2);
});

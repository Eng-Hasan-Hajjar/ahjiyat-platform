<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use App\Events\TeamChallengeAccepted;
use App\Events\TeamChallengeCompleted;
use App\Events\TeamChallengeCreated;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationUrlResolver;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});
afterEach(fn () => Carbon::setTestNow());

function e20Notes(string $type, $user = null)
{
    return e16Notes($type, $user);
}

/** @return array{0: \App\Models\Team, 1: \App\Models\Team, 2: array, 3: array, 4: \App\Models\User, 5: \App\Models\User} فريقان بمشرف لكلٍّ ولاعب عادي. */
function e20Staffed(): array
{
    [$a, $b, $am, $bm] = e20Pair(3, 3);
    $adminA = e19Member($a, null, 'admin');
    $adminB = e19Member($b, null, 'admin');

    return [$a->refresh(), $b->refresh(), $am, $bm, $adminA, $adminB];
}

test('67/E6/E10: a received challenge notifies the owner and admins of the TARGET team once each - never a plain member, never the challenger team - with semantic keys and a safe link', function () {
    [$a, $b, $am, $bm, $adminA, $adminB] = e20Staffed();

    $challenge = e20Create($a, $b, [$am[0]]);

    expect(e20Notes('team_challenge_received', $b->owner))->toHaveCount(1)->and(e20Notes('team_challenge_received', $adminB))->toHaveCount(1)
        ->and(e20Notes('team_challenge_received', $bm[1]))->toHaveCount(0)->and(e20Notes('team_challenge_received', $a->owner))->toHaveCount(0)->and(e20Notes('team_challenge_received', $adminA))->toHaveCount(0)
        ->and(e16Notes('team_challenge_received'))->toHaveCount(2);

    $note = e20Notes('team_challenge_received', $b->owner)->sole();
    expect($note->idempotency_key)->toBe("team-challenge-received:{$challenge->id}:{$b->owner->id}")->and($note->category)->toBe('social')
        ->and($note->data['title'])->toBe("تحدّاكم فريق «{$a->name}» بمباراة")->and(app(NotificationUrlResolver::class)->resolve($note->data))->toBe("/teams/challenges/{$challenge->public_id}");

    foreach (range(1, 3) as $i) {
        event(new TeamChallengeCreated($challenge->id));
    }
    expect(e16Notes('team_challenge_received'))->toHaveCount(2);
});

test('68: an accepted challenge notifies the CHALLENGER team owner and admins once each - not the accepting side, not plain members', function () {
    [$a, $b, $am, $bm, $adminA, $adminB] = e20Staffed();
    $challenge = e20Create($a, $b, [$am[0]]);
    e20Challenges()->accept($adminB, $challenge, e20Ids([$bm[0]]));
    event(new TeamChallengeAccepted($challenge->id));                          // إعادة بث

    expect(e20Notes('team_challenge_accepted', $a->owner))->toHaveCount(1)->and(e20Notes('team_challenge_accepted', $adminA))->toHaveCount(1)->and(e20Notes('team_challenge_accepted', $am[1]))->toHaveCount(0)
        ->and(e20Notes('team_challenge_accepted', $b->owner))->toHaveCount(0)->and(e20Notes('team_challenge_accepted', $adminB))->toHaveCount(0)
        ->and(e20Notes('team_challenge_accepted', $a->owner)->sole()->idempotency_key)->toBe("team-challenge-accepted:{$challenge->id}:{$a->owner->id}");
});

test('69/E6/E7: the result notifies each roster player and each team leader exactly once - an owner who is also a player gets one - and a non-roster member gets nothing', function () {
    [$a, $b, $am, $bm, $adminA, $adminB] = e20Staffed();
    $challenge = e20Accepted($a, $b, [$a->owner, $am[1]], [$b->owner, $bm[1]]);
    e20Submit($a->owner, $challenge, E17_ANSWER, 5_000);
    e20Submit($am[1], $challenge, E17_ANSWER, 6_000);
    e20Submit($b->owner, $challenge, E17_ANSWER, 40_000);
    e20Submit($bm[1], $challenge, E17_ANSWER, 41_000);
    event(new TeamChallengeCompleted($challenge->id));                         // إعادة بث

    foreach ([$a->owner, $adminA, $am[1], $b->owner, $adminB, $bm[1]] as $u) {
        expect(e20Notes('team_challenge_result_ready', $u))->toHaveCount(1);
    }
    expect(e20Notes('team_challenge_result_ready', $am[2]))->toHaveCount(0)->and(e20Notes('team_challenge_result_ready', $bm[2]))->toHaveCount(0)->and(e16Notes('team_challenge_result_ready'))->toHaveCount(6);

    $win = e20Notes('team_challenge_result_ready', $a->owner)->sole();
    $loss = e20Notes('team_challenge_result_ready', $b->owner)->sole();
    expect($win->idempotency_key)->toBe("team-challenge-result:{$challenge->id}:{$a->owner->id}")->and($win->data['body'])->toBe('فاز فريقكم.')->and($loss->data['body'])->toBe('خسر فريقكم.')
        ->and($win->data['title'])->toBe("نتيجة مباراتكم ضد «{$b->name}» جاهزة");
});

test('a draw tells both sides it was a draw, and a replayed event for a non-completed challenge sends nothing', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $pending = e20Create($a, $b, [$am[0]]);
    event(new TeamChallengeAccepted($pending->id));                            // ما زال معلّقًا
    event(new TeamChallengeCompleted($pending->id));
    event(new TeamChallengeCreated(999999));
    expect(e16Notes('team_challenge_accepted'))->toHaveCount(0)->and(e16Notes('team_challenge_result_ready'))->toHaveCount(0);

    $drawn = e20Match(e19Team(), e19Team(), 12_000, 12_000);
    expect(e16Notes('team_challenge_result_ready')->every(fn ($n) => $n->data['body'] === 'انتهت المباراة بالتعادل.'))->toBeTrue()->and(e16Notes('team_challenge_result_ready'))->toHaveCount(2);
    expect($drawn->is_draw)->toBeTrue();
});

test('declining, cancelling and expiry send no notification at all', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $c1 = e20Create($a, $b, [$am[0]]);
    $c2 = e20Create($a, $b, [$am[0]]);
    $c3 = e20Create($a, $b, [$am[0]]);
    DatabaseNotification::query()->delete();

    e20Challenges()->decline($b->owner, $c1);
    e20Challenges()->cancel($a->owner, $c2);
    Carbon::setTestNow(now()->addHours(73));
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    expect(DatabaseNotification::count())->toBe(0)->and($c3->refresh()->status)->toBe('expired');
});

test('70/E5: a live championship notifies the owners and admins of active teams once when it starts - never plain members - and rerunning the lifecycle adds nothing', function () {
    [$a, $b] = [e19Team(), e19Team()];
    $adminA = e19Member($a, null, 'admin');
    $memberA = e19Member($a);
    $dead = e19Team();
    e19Teams()->deactivate($dead->owner, $dead);
    $admin = e20Admin([], 'administrator');
    $champ = e20Championship($admin, ['ends_at' => now()->addDays(2)]);
    e20Champs()->linkEvent($admin, $champ, e20Event([[$a, 1900], [$b, 1800]]));
    e20Champs()->publish($admin, $champ);

    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    expect(e20Notes('team_championship_started', $a->owner))->toHaveCount(1)->and(e20Notes('team_championship_started', $adminA))->toHaveCount(1)->and(e20Notes('team_championship_started', $b->owner))->toHaveCount(1)
        ->and(e20Notes('team_championship_started', $memberA))->toHaveCount(0)->and(e20Notes('team_championship_started', $dead->owner))->toHaveCount(0)
        ->and(e20Notes('team_championship_started', $a->owner)->sole()->idempotency_key)->toBe("team-championship-started:{$champ->id}:{$a->owner->id}")
        ->and(e20Notes('team_championship_started', $a->owner)->sole()->category)->toBe('competitive')->and($champ->refresh()->started_notified_at)->not->toBeNull()
        ->and(app(NotificationUrlResolver::class)->resolve(e20Notes('team_championship_started', $a->owner)->sole()->data))->toBe("/team-championships/{$champ->slug}");
});

test('71: the championship result notifies the owners and admins of the teams in the final standing once each - a team that never scored is not told', function () {
    [$a, $b, $outsider] = [e19Team(), e19Team(), e19Team()];
    $adminB = e19Member($b, null, 'admin');
    $memberB = e19Member($b);
    $champ = e20Completed([[$a, 1900], [$b, 1800]], 'بطولة الإشعار');

    foreach ([$a->owner, $b->owner, $adminB] as $u) {
        expect(e20Notes('team_championship_result_ready', $u))->toHaveCount(1);
    }
    expect(e20Notes('team_championship_result_ready', $memberB))->toHaveCount(0)->and(e20Notes('team_championship_result_ready', $outsider->owner))->toHaveCount(0)
        ->and(e20Notes('team_championship_result_ready', $a->owner)->sole()->idempotency_key)->toBe("team-championship-result:{$champ->id}:{$a->owner->id}");

    event(new \App\Events\TeamChampionshipFinalized($champ->id));              // إعادة بث
    expect(e16Notes('team_championship_result_ready'))->toHaveCount(3);
});

test('72: the social preference governs challenge notifications and the competitive preference governs championship ones - the match and the championship still work', function () {
    [$a, $b, $am, $bm] = e20Pair();
    app(NotificationPreferenceService::class)->update($b->owner, ['social_enabled' => false, 'competitive_enabled' => true]);
    app(NotificationPreferenceService::class)->update($a->owner, ['social_enabled' => true, 'competitive_enabled' => false]);

    $c = e20Create($a, $b, [$am[0]]);
    expect(e20Notes('team_challenge_received', $b->owner))->toHaveCount(0)->and($c->refresh()->status)->toBe('pending');
    e20Challenges()->accept($b->owner, $c, e20Ids([$bm[0]]));
    expect(e20Notes('team_challenge_accepted', $a->owner))->toHaveCount(1);                                       // a: social مفعّل

    $champ = e20Completed([[$a, 1900], [$b, 1800]]);
    expect(e20Notes('team_championship_result_ready', $a->owner))->toHaveCount(0)->and(e20Notes('team_championship_result_ready', $b->owner))->toHaveCount(1)       // a أوقف competitive
        ->and($champ->status)->toBe('completed')->and($champ->champion_team_id)->toBe($a->id);                                                                       // البطل لم يتأثر بالتفضيل
});

test('73: with global notifications off every team match and championship step works and nothing is created', function () {
    app(PlatformSettingsService::class)->set('notifications', 'notifications_enabled', false);
    [$a, $b] = [e19Team(), e19Team()];
    $c = e20Match($a, $b, 5_000, 40_000);
    $champ = e20Completed([[$a, 1900], [$b, 1800]]);

    expect($c->status)->toBe('completed')->and($champ->status)->toBe('completed')->and(DatabaseNotification::count())->toBe(0);
});

test('74/E12: a failing notification pipeline never rolls back a challenge, an acceptance, a result, a championship start or a championship finalization', function () {
    $this->app->bind(NotificationDispatcher::class, fn () => throw new RuntimeException('notification pipeline is down'));
    [$a, $b, $am, $bm] = e20Pair();

    $c = e20Create($a, $b, [$am[0]]);
    e20Challenges()->accept($b->owner, $c, e20Ids([$bm[0]]));
    e20Submit($am[0], $c, E17_ANSWER, 5_000);
    e20Submit($bm[0], $c, E17_ANSWER, 40_000);
    $champ = e20Completed([[$a, 1900], [$b, 1800]]);

    expect($c->refresh()->status)->toBe('completed')->and($c->winner_team_id)->toBe($a->id)->and($champ->status)->toBe('completed')->and($champ->champion_team_id)->toBe($a->id)->and(DatabaseNotification::count())->toBe(0);

    $live = e20Championship(e20Admin([], 'administrator'), ['ends_at' => now()->addDays(2)]);
    e20Champs()->linkEvent(e20Admin([], 'administrator'), $live, e20Event([[$a, 1900]]));
    e20Champs()->publish(e20Admin([], 'administrator'), $live);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    expect($live->refresh()->status)->toBe('published');
});

test('75: opening any team challenge or championship notification grants nothing and changes no match or championship state', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $c = e20Create($a, $b, [$am[0]]);
    $note = e20Notes('team_challenge_received', $b->owner)->sole();
    $before = [e17Snapshot(), \App\Models\TeamChallenge::where('status', 'pending')->count(), \App\Models\TeamChallengeParticipant::count()];

    $this->actingAs($b->owner)->post(route('notifications.open', $note->id))->assertRedirect(route('teams.challenges.show', $c));

    expect([e17Snapshot(), \App\Models\TeamChallenge::where('status', 'pending')->count(), \App\Models\TeamChallengeParticipant::count()])->toBe($before)->and($c->refresh()->status)->toBe('pending');
});

test('the five new types are registered with real routes under the existing categories - challenges social, championships competitive - and no new category or preference', function () {
    $social = ['TeamChallengeReceived', 'TeamChallengeAccepted', 'TeamChallengeResultReady'];
    $competitive = ['TeamChampionshipStarted', 'TeamChampionshipResultReady'];

    foreach (array_merge($social, $competitive) as $case) {
        $type = constant("App\\Services\\Notifications\\NotificationType::{$case}");
        expect($type->category())->toBe(in_array($case, $social, true) ? \App\Services\Notifications\NotificationCategory::Social : \App\Services\Notifications\NotificationCategory::Competitive);

        foreach ($type->allowedRoutes() as $route) {
            expect(\Illuminate\Support\Facades\Route::has($route))->toBeTrue();
        }
    }
    expect(count(\App\Services\Notifications\NotificationCategory::cases()))->toBe(8);
});

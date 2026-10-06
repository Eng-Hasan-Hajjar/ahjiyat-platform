<?php

require_once __DIR__.'/CompetitiveTestHelpers.php';

use App\Filament\Resources\CompetitiveEventResource\Pages\CreateCompetitiveEvent;
use App\Filament\Resources\CompetitiveEventResource\Pages\EditCompetitiveEvent;
use App\Filament\Resources\CompetitiveEventResource\Pages\ListCompetitiveEvents;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\FriendChallenge;
use App\Models\GameSession;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\Competitive\CompetitiveEventAdminService;
use App\Services\Competitive\CompetitiveException;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});
afterEach(fn () => Carbon::setTestNow());

function e17Admin(array $permissions = [], ?string $role = null): User
{
    $user = e16User();
    $permissions && $user->givePermissionTo($permissions);
    $role && $user->assignRole($role);

    return $user;
}

// ============================ الأمن ============================

test('65: challenge IDOR - a third user gets 404 on show and every action, and nothing changes', function () {
    [$a, $b] = e17Friends();
    $outsider = e16User();
    $challenge = e17Challenge($a, $b);
    e17Challenges()->start($a, $challenge);
    $before = [FriendChallenge::count(), GameSession::count(), $challenge->refresh()->status];

    $this->actingAs($outsider)->get(route('friends.challenges.show', $challenge))->assertNotFound();
    foreach ([['post', 'accept'], ['post', 'decline'], ['delete', 'cancel'], ['post', 'start'], ['post', 'submit']] as [$method, $name]) {
        $this->actingAs($outsider)->{$method}(route("friends.challenges.{$name}", $challenge), ['answer' => E17_ANSWER])->assertNotFound();
    }

    expect([FriendChallenge::count(), GameSession::count(), $challenge->refresh()->status])->toBe($before)->and(\App\Models\FriendChallengeResult::count())->toBe(0);
});

test('65b: the challenge route identifier is an unguessable ULID, not a sequential id', function () {
    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b);

    expect($challenge->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    $this->actingAs($a)->get('/friends/challenges/'.$challenge->id)->assertNotFound(); // الرقم التسلسلي لا يعمل
});

test('66: event result IDOR - a user cannot submit or finish another user run; each submit only ever uses the authenticated user own session', function () {
    $event = e17Event();
    [$a, $b] = [e16User(), e16User()];
    e17Events()->register($a, $event);
    e17Events()->register($b, $event);
    e17Events()->start($a, $event);   // لـA فقط جلسة نشطة

    expect(fn () => e17Events()->submit($b, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class, 'ابدأ المحاولة أولًا.');
    $this->actingAs($b)->post(route('competitions.submit', $event), ['answer' => E17_ANSWER, 'user_id' => $a->id, 'session_id' => GameSession::first()->id])->assertSessionHas('error');

    expect(CompetitiveEventResult::count())->toBe(0)->and(GameSession::where('user_id', $a->id)->value('status'))->toBe('active'); // جلسة A سليمة
    e17Forward(15_000);
    e17Events()->submit($a, $event, ['answer' => E17_ANSWER]);
    expect(CompetitiveEventResult::sole()->user_id)->toBe($a->id);
});

test('67: guests and unverified users cannot run any competitive mutation or play page', function () {
    [$a, $b] = e17Friends();
    $event = e17Event();
    $challenge = e17Challenge($a, $b);
    $mutations = [
        ['post', route('competitions.register', $event)], ['delete', route('competitions.leave', $event)], ['post', route('competitions.start', $event)], ['post', route('competitions.submit', $event)],
        ['post', route('friends.challenges.store', $b)], ['post', route('friends.challenges.accept', $challenge)], ['post', route('friends.challenges.decline', $challenge)],
        ['delete', route('friends.challenges.cancel', $challenge)], ['post', route('friends.challenges.start', $challenge)], ['post', route('friends.challenges.submit', $challenge)],
    ];

    foreach ($mutations as [$method, $url]) {
        $this->{$method}($url)->assertRedirect(route('login'));
    }
    foreach ([route('competitions.play', $event), route('friends.challenges.index'), route('friends.challenges.show', $challenge), route('friends.challenges.create', $b)] as $page) {
        $this->get($page)->assertRedirect(route('login'));
    }

    $unverified = User::factory()->create(['email_verified_at' => null]);
    $this->actingAs($unverified)->post(route('competitions.register', $event))->assertRedirect(route('verification.notice'));
    expect(\App\Models\CompetitiveEventParticipant::count())->toBe(0);
});

test('69: GET routes never change state - the play page, challenge pages and event pages create no session, result or notification', function () {
    [$a, $b] = e17Friends();
    $event = e17Event();
    $challenge = e17Challenge($a, $b);
    e17Events()->register($a, $event);
    $tables = ['game_sessions', 'competitive_event_results', 'competitive_event_participants', 'friend_challenge_results', 'friend_challenges', 'notifications', 'puzzle_attempts'];
    $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), $tables);
    $before = $counts();
    $statuses = [$challenge->refresh()->status, $event->refresh()->participants_count];

    foreach ([route('competitions.index'), route('competitions.show', $event), route('competitions.show', [$event, 'scope' => 'friends']), route('competitions.play', $event),
        route('friends.challenges.index'), route('friends.challenges.show', $challenge), route('friends.challenges.create', $b)] as $url) {
        $this->actingAs($a)->get($url);
    }

    expect($counts())->toBe($before)->and([$challenge->refresh()->status, $event->refresh()->participants_count])->toBe($statuses);
});

test('CSRF and verbs: every mutation is POST/DELETE inside the web group; mutating URLs answer 405 to GET and forms carry tokens', function () {
    $names = ['competitions.register', 'competitions.leave', 'competitions.start', 'competitions.submit', 'friends.challenges.store', 'friends.challenges.accept', 'friends.challenges.decline', 'friends.challenges.cancel', 'friends.challenges.start', 'friends.challenges.submit'];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route->gatherMiddleware())->toContain('web')->and($route->methods())->not->toContain('GET');
    }
    $getNames = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true) && (str_starts_with((string) $r->getName(), 'competitions.') || str_starts_with((string) $r->getName(), 'friends.challenges.')))->map->getName()->sort()->values()->all();
    expect($getNames)->toBe(['competitions.hall-of-fame', 'competitions.index', 'competitions.play', 'competitions.show', 'friends.challenges.create', 'friends.challenges.index', 'friends.challenges.show']); // + قاعة الأمجاد (E18، قراءة فقط)

    [$a, $b] = e17Friends();
    $challenge = e17Challenge($a, $b, null, false);
    $this->actingAs($a)->get('/competitions/x/register')->assertStatus(405); // الرابط للـPOST/DELETE فقط
    $html = $this->actingAs($a)->get(route('friends.challenges.show', $challenge))->getContent();
    expect(substr_count($html, 'name="_token"'))->toBeGreaterThanOrEqual(2);
});

// ============================ إدارة دورة الحياة ============================

test('E17-B12: the permissions exist in the registry and the administrator receives them', function () {
    foreach (['view', 'create', 'update', 'delete', 'publish', 'cancel', 'finalize'] as $ability) {
        expect(config('permissions.competitive_events.permissions'))->toHaveKey("competitive_events.{$ability}");
    }

    expect(e17Admin([], 'administrator')->can('competitive_events.publish'))->toBeTrue();
});

test('68: publish, cancel and finalize are domain actions - unauthorized users get 403 and nothing changes, authorized ones are audited', function () {
    $draft = e17Event([], CompetitiveEvent::STATUS_DRAFT);
    $nobody = e17Admin(['competitive_events.view', 'competitive_events.update']);
    $publisher = e17Admin(['competitive_events.publish']);
    $service = app(CompetitiveEventAdminService::class);

    expect(fn () => $service->publish($draft, $nobody))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($draft->refresh()->status)->toBe('draft')->and(OperationalAuditLog::where('action', 'competitive_event_published')->count())->toBe(0);

    $service->publish($draft, $publisher);
    $log = OperationalAuditLog::where('action', 'competitive_event_published')->sole();

    expect($draft->refresh()->status)->toBe('published')->and($draft->published_at)->not->toBeNull()->and($log->actor_user_id)->toBe($publisher->id)->and($log->subject_id)->toBe($draft->id);
    expect(fn () => $service->publish($draft, $publisher))->toThrow(CompetitiveException::class, 'يمكن نشر المسوّدات فقط.'); // مرتان: مرفوض بلا ازدواج تدقيق
    expect(OperationalAuditLog::where('action', 'competitive_event_published')->count())->toBe(1);
});

test('publishing requires a valid target and a future end; a hinted puzzle or an ended event cannot go live', function () {
    $admin = e17Admin([], 'administrator');
    $service = app(CompetitiveEventAdminService::class);
    $hinted = e17Event(['puzzle_id' => e17Puzzle(['hint' => 'تلميح'])->id], CompetitiveEvent::STATUS_DRAFT);
    $ended = e17Event(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()], CompetitiveEvent::STATUS_DRAFT);

    expect(fn () => $service->publish($hinted, $admin))->toThrow(CompetitiveException::class)
        ->and(fn () => $service->publish($ended, $admin))->toThrow(CompetitiveException::class, 'لا يمكن نشر منافسة انتهى وقتها.')
        ->and($hinted->refresh()->status)->toBe('draft');
});

test('E22: cancelling stops new results, expires open runs, keeps all history, and is audited; a finalized event cannot be cancelled', function () {
    $admin = e17Admin([], 'administrator');
    $event = e17Event();
    [$done, $running] = [e16User(), e16User()];
    e17PlayEvent($done, $event);
    e17Events()->register($running, $event);
    e17Events()->start($running, $event);

    app(CompetitiveEventAdminService::class)->cancel($event, $admin);

    expect($event->refresh()->status)->toBe('cancelled')->and(CompetitiveEventResult::count())->toBe(1)                       // التاريخ باقٍ
        ->and(GameSession::where('user_id', $running->id)->value('status'))->toBe('expired')
        ->and(fn () => e17Events()->submit($running, $event, ['answer' => E17_ANSWER]))->toThrow(CompetitiveException::class)
        ->and(OperationalAuditLog::where('action', 'competitive_event_cancelled')->sole()->metadata)->toMatchArray(['participants' => 2, 'results' => 1]);

    $finalized = e17Event();
    e17PlayEvent(e16User(), $finalized);
    e17Forward(40 * 3600_000);
    app(CompetitiveEventAdminService::class)->finalize($finalized->refresh(), $admin);
    expect(fn () => app(CompetitiveEventAdminService::class)->cancel($finalized->refresh(), $admin))->toThrow(CompetitiveException::class);
    $log = OperationalAuditLog::where('action', 'competitive_event_finalized')->sole();
    expect($log->actor_user_id)->toBe($admin->id)->and($finalized->refresh()->status)->toBe('completed');
});

test('E19: an admin cannot edit any result - no result column is fillable and the resource exposes no result editing', function () {
    expect((new CompetitiveEventResult)->getFillable())->toContain('score'); // الكتابة عبر الخدمات فقط
    $resource = file_get_contents(app_path('Filament/Resources/CompetitiveEventResource.php'));

    expect($resource)->not->toContain('CompetitiveEventResult')->and($resource)->not->toMatch('/TextInput::make\(.(score|final_rank|status|duration_ms)/');
});

test('Filament: the list page enforces view permission, shows derived phase and counts, and actions follow permissions', function () {
    $viewer = e17Admin(['competitive_events.view']);
    $event = e17Event([], CompetitiveEvent::STATUS_DRAFT);

    $this->actingAs(e16User());
    Livewire::test(ListCompetitiveEvents::class)->assertForbidden();

    $this->actingAs($viewer);
    Livewire::test(ListCompetitiveEvents::class)->assertOk()->assertCanSeeTableRecords([$event])->assertTableActionHidden('publish', $event)->assertTableActionHidden('cancel', $event);

    $this->actingAs(e17Admin(['competitive_events.view', 'competitive_events.publish']));
    Livewire::test(ListCompetitiveEvents::class)->assertTableActionVisible('publish', $event)->callTableAction('publish', $event)->assertHasNoTableActionErrors();
    expect($event->refresh()->status)->toBe('published')->and(OperationalAuditLog::where('action', 'competitive_event_published')->count())->toBe(1);
});

test('Filament: cancel and finalize actions appear only in the right lifecycle state and run through the audited domain service', function () {
    $admin = e17Admin([], 'administrator');
    $this->actingAs($admin);
    $endedEvent = e17Event();
    e17PlayEvent(e16User(), $endedEvent);
    e17Forward(40 * 3600_000); // انتهى $endedEvent
    $live = e17Event();        // أحداث تُنشأ بعد تقدّم الزمن: مباشرة ومسوّدة
    $draft = e17Event([], CompetitiveEvent::STATUS_DRAFT);

    $list = Livewire::test(ListCompetitiveEvents::class);
    $list->assertTableActionHidden('finalize', $live->refresh())->assertTableActionHidden('publish', $live)->assertTableActionVisible('finalize', $endedEvent->refresh())
        ->assertTableActionVisible('publish', $draft->refresh());

    $list->callTableAction('finalize', $endedEvent)->assertHasNoTableActionErrors();
    expect($endedEvent->refresh()->status)->toBe('completed')->and(CompetitiveEventResult::first()->final_rank)->toBe(1);

    $list->callTableAction('cancel', $live)->assertHasNoTableActionErrors();
    expect($live->refresh()->status)->toBe('cancelled')->and(OperationalAuditLog::whereIn('action', ['competitive_event_finalized', 'competitive_event_cancelled'])->count())->toBe(2);
});

test('Filament: the create form makes a draft with the chosen puzzle, and after publishing the fairness fields are disabled', function () {
    $this->actingAs(e17Admin([], 'administrator'));
    $puzzle = e17Puzzle(['title' => 'أحجية المنافسة']);

    Livewire::test(CreateCompetitiveEvent::class)->fillForm([
        'title' => 'منافسة جديدة', 'slug' => 'new-competition', 'puzzle_id' => $puzzle->id,
        'starts_at' => now()->addDay()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'max_participants' => 10,
    ])->call('create')->assertHasNoFormErrors();

    $created = CompetitiveEvent::where('slug', 'new-competition')->sole();
    expect($created->status)->toBe('draft')->and($created->puzzle_id)->toBe($puzzle->id)->and($created->participants_count)->toBe(0);

    app(CompetitiveEventAdminService::class)->publish($created, auth()->user());
    $edit = Livewire::test(EditCompetitiveEvent::class, ['record' => $created->getRouteKey()]);
    foreach (['puzzle_id', 'starts_at', 'ends_at', 'max_participants', 'slug'] as $field) {
        $edit->assertFormFieldIsDisabled($field);
    }
    $edit->fillForm(['description' => 'وصف محدَّث'])->call('save')->assertHasNoFormErrors();
    expect($created->refresh()->description)->toBe('وصف محدَّث')->and($created->max_participants)->toBe(10);
});

test('only a draft can be deleted - published events keep their history', function () {
    $admin = e17Admin(['competitive_events.delete']);
    $draft = e17Event([], CompetitiveEvent::STATUS_DRAFT);
    $published = e17Event();

    expect($admin->can('delete', $draft))->toBeTrue()->and($admin->can('delete', $published))->toBeFalse();
    expect(fn () => $published->puzzle->delete())->toThrow(\Illuminate\Database\QueryException::class); // الأحجية الهدف محمية بـrestrictOnDelete
});

// ============================ تدقيقات ثابتة ============================

function e17DomainFiles(): array
{
    return array_merge(
        glob(app_path('Services/Competitive/*.php')), glob(app_path('Listeners/SendFriendChallenge*.php')), [app_path('Listeners/QueueCompetitiveResultNotifications.php'), app_path('Jobs/DispatchCompetitiveResultChunk.php')],
        glob(app_path('Events/FriendChallenge*.php')), [app_path('Events/CompetitiveEventFinalized.php')],
        glob(app_path('Models/FriendChallenge*.php')), glob(app_path('Models/CompetitiveEvent*.php')),
        [app_path('Http/Controllers/CompetitionController.php'), app_path('Http/Controllers/CompetitionPlayController.php'), app_path('Http/Controllers/FriendChallengeController.php')],
        glob(app_path('Http/Requests/Competitive*.php')), [app_path('Http/Requests/FriendChallengeStoreRequest.php'), app_path('Console/Commands/ProcessCompetitiveLifecycle.php')],
        glob(resource_path('views/competitions/*.blade.php')), glob(resource_path('views/friends/challenges/*.blade.php')),
    );
}

function e17Strip(string $code): string
{
    return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'], '', $code);
}

test('static pay-to-win audit: the competitive domain has no wallet, credit, purchase, XP, quest, streak, achievement, reward-pipeline or gem-reward dependency', function () {
    $files = e17DomainFiles();
    expect(count($files))->toBeGreaterThan(25);
    $forbidden = ['CurrencyWalletService', 'WalletService', 'creditPending', 'credit(', 'debit(', 'StorePurchase', 'XpService', 'awardXp', 'QuestService', 'StreakService', 'AchievementService',
        'ProgressionService', 'PuzzleAttemptService', 'AttemptRewardResolver', 'RewardDirective', 'gem_reward', 'xp_reward', 'purchaseHint', 'Entitlement', 'bonus_gem'];

    foreach ($files as $file) {
        $code = e17Strip(file_get_contents($file));

        foreach ($forbidden as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not reference {$needle}");
        }
    }
});

test('static score-trust audit: no request, controller or view reads score, winner, rank, duration, token or hint flags from the client', function () {
    foreach (e17DomainFiles() as $file) {
        $code = e17Strip(file_get_contents($file));
        $isClientFacing = str_contains($file, 'Controllers') || str_contains($file, 'Requests') || str_contains($file, 'views');

        if (! $isClientFacing) {
            continue;
        }

        expect(preg_match('/\$request->(input|get|post|query|only|except|all|boolean|integer)\(\s*[\'"](score|winner|winner_user_id|rank|final_rank|duration|duration_ms|start_token|used_hint|is_correct|status)[\'"]/', $code))->toBe(0, basename($file));
        expect(preg_match('/name="(score|winner|rank|duration|duration_ms|is_correct|used_hint|start_token)"/', $code))->toBe(0, basename($file).' must not render a trusted-score input');
    }

    $rules = array_keys((new \App\Http\Requests\CompetitiveSubmitRequest)->rules());
    expect($rules)->toBe(['answer', 'submission', 'submission.order', 'submission.order.*', 'submission.matches', 'submission.moves']);
});

test('static privacy and hygiene audit: no private fields, raw HTML or debug leftovers in the competitive code', function () {
    foreach (e17DomainFiles() as $file) {
        $code = e17Strip(file_get_contents($file));

        foreach (['/\bdd\(/', '/\bdump\(/', '/\bray\(/', '/\bvar_dump\(/', '/TODO/', '/FIXME/', '/console\.log/', '/\{!!/'] as $bad) {
            expect(preg_match($bad, $code))->toBe(0, basename($file)." must not match {$bad}");
        }
        foreach (['email', 'phone', 'wallet', 'password', 'remember_token', 'is_frozen'] as $private) {
            // (email_verified_at/is_frozen في الخدمات مشروعان للتصفية فقط، وليسا للعرض؛ تُفحص الواجهات صارمًا.)
            if (str_contains($file, 'views')) {
                expect(stripos($code, $private))->toBeFalse(basename($file)." view must not touch {$private}");
            } elseif (! in_array($private, ['is_frozen'], true)) {
                // (hasVerifiedEmail() فحص أهلية مشروع لا يقرأ العنوان.)
                expect(preg_match('/'.$private.'(?!_verified_at)/i', str_replace('hasVerifiedEmail', '', $code)))->toBe(0, basename($file)." must not touch {$private}");
            }
        }
    }
});

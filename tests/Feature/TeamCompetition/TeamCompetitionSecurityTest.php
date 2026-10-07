<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\TeamChallengeResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

function e20State(): string
{
    return json_encode([
        DB::table('team_challenges')->orderBy('id')->get()->all(), DB::table('team_challenge_participants')->orderBy('id')->get()->all(),
        DB::table('team_challenge_results')->orderBy('id')->get()->all(), DB::table('game_sessions')->orderBy('id')->get()->all(),
    ]);
}

/** عالَم للهجوم: فريقا المهاجم أ/ب، وفريقان بعيدان ج/د بينهما تحدٍّ معلّق وآخر مقبول. */
function e20World(): array
{
    [$a, $b, $am, $bm] = e20Pair();
    [$c, $d, $cm, $dm] = e20Pair();
    $pending = e20Create($c, $d, [$cm[0]]);
    $accepted = e20Accepted($c, $d, [$cm[0], $cm[1]], [$dm[0], $dm[1]], e17Puzzle());

    return compact('a', 'b', 'am', 'bm', 'c', 'd', 'cm', 'dm', 'pending', 'accepted');
}

test('76: challenge IDOR - owners and members of unrelated teams can read, accept, decline, cancel or edit nothing of another pair of teams - 404 for all, and nothing changes', function () {
    ['a' => $a, 'am' => $am, 'pending' => $pending, 'accepted' => $accepted, 'd' => $d] = e20World();
    $before = e20State();

    foreach ([$a->owner, $am[1]] as $attacker) {
        foreach ([$pending, $accepted] as $challenge) {
            $this->actingAs($attacker)->get(route('teams.challenges.show', $challenge))->assertNotFound();
            $this->actingAs($attacker)->post(route('teams.challenges.accept', $challenge), ['roster' => e20Ids([$attacker])])->assertNotFound();
            $this->actingAs($attacker)->post(route('teams.challenges.decline', $challenge))->assertNotFound();
            $this->actingAs($attacker)->delete(route('teams.challenges.cancel', $challenge))->assertNotFound();
            $this->actingAs($attacker)->put(route('teams.challenges.roster', $challenge), ['roster' => e20Ids([$attacker])])->assertNotFound();
            $this->actingAs($attacker)->post(route('teams.challenges.start', $challenge))->assertNotFound();
            $this->actingAs($attacker)->post(route('teams.challenges.submit', $challenge), ['answer' => E17_ANSWER])->assertNotFound();
        }
    }

    expect(e20State())->toBe($before);
    expect($d->exists)->toBeTrue();
});

test('76b: a plain member of an involved team cannot accept, decline or cancel - only owners and admins act - and the refusal changes nothing', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Create($a, $b, [$am[0]]);
    $before = e20State();

    $this->actingAs($bm[1])->post(route('teams.challenges.accept', $challenge), ['roster' => e20Ids([$bm[1]])])->assertRedirect()->assertSessionHas('error');
    $this->actingAs($bm[1])->post(route('teams.challenges.decline', $challenge))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($am[1])->delete(route('teams.challenges.cancel', $challenge))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($a->owner)->post(route('teams.challenges.accept', $challenge), ['roster' => e20Ids([$am[0]])])->assertRedirect()->assertSessionHas('error');         // الفريق المتحدّي لا يقبل
    $this->actingAs($b->owner)->delete(route('teams.challenges.cancel', $challenge))->assertRedirect()->assertSessionHas('error');                                      // الخصم لا يلغي

    expect(e20State())->toBe($before)->and($challenge->refresh()->status)->toBe('pending');
});

test('77: roster IDOR - a non-roster player cannot start or submit, a roster player cannot play as another, the locked roster cannot be edited over HTTP, and the other team data is invisible', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $challenge = e20Accepted($a, $b, [$am[0]], [$bm[0]]);
    $outsiderTeamMember = $am[2];                                                   // عضو فريق أ لكن خارج الروستر
    $before = e20State();

    $this->actingAs($outsiderTeamMember)->post(route('teams.challenges.start', $challenge))->assertNotFound();
    $this->actingAs($outsiderTeamMember)->post(route('teams.challenges.submit', $challenge), ['answer' => E17_ANSWER])->assertNotFound();
    $this->actingAs($a->owner)->put(route('teams.challenges.roster', $challenge), ['roster' => e20Ids([$am[1]])])->assertRedirect()->assertSessionHas('error');         // مقفل
    expect(e20State())->toBe($before);

    // لاعب أ يرسل ببيانات لاعب ب: الهوية من المصادَقة، فلا أثر على مقعد ب.
    $this->actingAs($am[0])->post(route('teams.challenges.start', $challenge))->assertRedirect();
    e17Forward(8_000);
    $this->actingAs($am[0])->post(route('teams.challenges.submit', $challenge), ['answer' => E17_ANSWER, 'user_id' => $bm[0]->id, 'user' => $bm[0]->public_id, 'participant_id' => 2])->assertRedirect();
    expect(TeamChallengeParticipant::where('user_id', $am[0]->id)->value('completed_at'))->not->toBeNull()->and(TeamChallengeParticipant::where('user_id', $bm[0]->id)->value('completed_at'))->toBeNull();

    // مستخدم خارج الفريقين: لا يرى تحدّيًا جاريًا ولا روسترًا.
    $this->actingAs(e16User())->get(route('teams.challenges.show', $challenge))->assertNotFound();
    auth()->forgetGuards();
    $this->get(route('teams.challenges.show', $challenge))->assertNotFound();
});

test('76c: a pending challenge is invisible to everyone outside the two teams, while a completed one is public with the result and the contributing players but no answers or puzzle payload', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $pending = e20Create($a, $b, [$am[0]]);
    $done = e20Match(e19Team(null, ['name' => 'فريق علني']), e19Team(), 5_000, 40_000);

    $this->get(route('teams.challenges.show', $pending))->assertNotFound();
    $this->actingAs(e16User())->get(route('teams.challenges.show', $pending))->assertNotFound();
    $this->actingAs($b->owner)->get(route('teams.challenges.show', $pending))->assertOk();

    $html = $this->get(route('teams.challenges.show', $done))->assertOk()->assertSee('فاز فريق')->assertSee($done->puzzle->title)->getContent();
    expect($html)->not->toContain(E17_ANSWER)->and($html)->not->toMatch('/answer_raw|normalized_answer|\bhint\b/i');
});

test('79: guests can mutate nothing - every mutating team challenge route redirects to login and no state changes', function () {
    ['pending' => $pending, 'accepted' => $accepted, 'a' => $a] = e20World();
    $before = e20State();

    foreach ([
        ['post', route('teams.challenges.store'), ['opponent' => $a->slug]], ['post', route('teams.challenges.accept', $pending)], ['post', route('teams.challenges.decline', $pending)],
        ['delete', route('teams.challenges.cancel', $pending)], ['put', route('teams.challenges.roster', $pending)], ['post', route('teams.challenges.start', $accepted)], ['post', route('teams.challenges.submit', $accepted)],
    ] as $item) {
        [$verb, $url, $data] = array_pad($item, 3, []);
        $this->{$verb}($url, $data)->assertRedirect(route('login'));
    }
    foreach ([route('teams.challenges.index'), route('teams.challenges.create')] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    expect(e20State())->toBe($before);
});

test('80/84: GET never mutates and there is no raw management route - the mutating URLs answer 405 to GET, championships expose only two read routes, and the GET route set is exact', function () {
    ['accepted' => $accepted, 'pending' => $pending, 'c' => $c] = e20World();
    $before = e20State();

    foreach ([route('teams.challenges.accept', $pending), route('teams.challenges.decline', $pending), route('teams.challenges.start', $accepted), route('teams.challenges.submit', $accepted), route('teams.challenges.roster', $pending)] as $url) {
        $this->actingAs($c->owner)->get($url)->assertStatus(405);
    }
    foreach ([route('teams.challenges.index'), route('teams.challenges.show', $pending), route('team-championships.index'), route('teams.show', $c)] as $url) {
        $this->actingAs($c->owner)->get($url);
    }
    expect(e20State())->toBe($before);

    $names = fn (string $prefix, string $verb) => collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), $prefix) && in_array($verb, $r->methods(), true))->map->getName()->sort()->values()->all();
    expect($names('teams.challenges.', 'GET'))->toBe(['teams.challenges.create', 'teams.challenges.index', 'teams.challenges.show'])
        ->and($names('team-championships.', 'GET'))->toBe(['team-championships.index', 'team-championships.show']);

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
        expect($names('team-championships.', $verb))->toBe([]);                                           // بلا أي تعديل عام للبطولات
    }
});

test('81/82/83: a forged winner, score, rank, status, team or championship points in any request is ignored - the server derives every one of them', function () {
    [$a, $b, $am, $bm] = e20Pair();
    $puzzle = e17Puzzle();
    $c = e20Pair();

    $this->actingAs($a->owner)->post(route('teams.challenges.store'), [
        'opponent' => $b->slug, 'puzzle_id' => $puzzle->id, 'roster' => e20Ids([$am[0]]),
        'challenger_team_id' => $b->id, 'opponent_team_id' => $a->id, 'winner_team_id' => $a->id, 'status' => 'completed', 'is_draw' => true, 'score' => 9999, 'rank' => 1, 'active_key' => 'x',
        'expires_at' => now()->addYears(5)->toDateTimeString(), 'play_ends_at' => now()->addYears(5)->toDateTimeString(), 'championship_points' => 999, 'created_by_user_id' => $bm[0]->id,
    ])->assertRedirect();

    $challenge = TeamChallenge::firstOrFail();
    expect($challenge->challenger_team_id)->toBe($a->id)->and($challenge->opponent_team_id)->toBe($b->id)->and($challenge->status)->toBe('pending')->and($challenge->winner_team_id)->toBeNull()
        ->and($challenge->is_draw)->toBeFalse()->and($challenge->created_by_user_id)->toBe($a->owner_id)->and($challenge->expires_at->lessThan(now()->addDays(4)))->toBeTrue()->and($challenge->play_ends_at)->toBeNull();

    $this->actingAs($b->owner)->post(route('teams.challenges.accept', $challenge), ['roster' => e20Ids([$bm[0]]), 'winner_team_id' => $b->id, 'status' => 'completed', 'score' => 9999])->assertRedirect();
    $challenge->refresh();
    expect($challenge->status)->toBe('accepted')->and($challenge->winner_team_id)->toBeNull();

    foreach ([[$am[0], 30_000], [$bm[0], 5_000]] as [$u, $ms]) {
        $this->actingAs($u)->post(route('teams.challenges.start', $challenge));
        e17Forward($ms);
        $this->actingAs($u)->post(route('teams.challenges.submit', $challenge), ['answer' => E17_ANSWER, 'winner_team_id' => $a->id, 'score' => 9999, 'rank' => 1, 'team_score' => 99999, 'team_id' => $a->id, 'championship_points' => 999, 'duration_ms' => 1])->assertRedirect();
    }
    $challenge->refresh();

    expect($challenge->status)->toBe('completed')->and($challenge->winner_team_id)->toBe($b->id)                              // الأسرع فاز رغم تزوير المنافس
        ->and(TeamChallengeResult::where('team_id', $a->id)->value('score'))->toBeLessThan(2001)->and(TeamChallengeParticipant::where('user_id', $bm[0]->id)->value('duration_ms'))->toBeGreaterThan(1000);
    expect(count($c))->toBe(4);
});

test('CSRF: every mutating team challenge route sits in the web group and every form on the challenge pages carries a token', function () {
    foreach (collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'teams.challenges.') && ! in_array('GET', $r->methods(), true)) as $route) {
        expect($route->gatherMiddleware())->toContain('web');
    }

    [$a, $b, $am, $bm] = e20Pair();
    $pending = e20Create($a, $b, [$am[0]]);
    [$c, $d, $cm, $dm] = e20Pair();
    $running = e20Accepted($c, $d, [$cm[0]], [$dm[0]]);

    $pages = [[$b->owner, route('teams.challenges.show', $pending)], [$a->owner, route('teams.challenges.show', $pending)], [$cm[0], route('teams.challenges.show', $running)], [$a->owner, route('teams.challenges.create', ['opponent' => $b->slug])]];

    foreach ($pages as [$user, $url]) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        preg_match_all('#<form\b[^>]*method="POST"[^>]*>.*?</form>#is', $html, $forms);

        foreach ($forms[0] as $form) {
            if (! str_contains($form, route('logout'))) {
                expect($form)->toContain('name="_token"');
            }
        }
        expect($forms[0])->not->toBeEmpty();
    }
});

test('rate limits protect challenge creation - the limit answers 429 and the state stops changing - and a frozen account is stopped before any action', function () {
    [$a, $b, $am] = e20Pair();
    $puzzles = [e17Puzzle(), e17Puzzle(), e17Puzzle(), e17Puzzle(), e17Puzzle()];

    foreach (array_slice($puzzles, 0, 4) as $p) {
        $this->actingAs($a->owner)->post(route('teams.challenges.store'), ['opponent' => $b->slug, 'puzzle_id' => $p->id, 'roster' => e20Ids([$am[0]])]);
    }
    $this->actingAs($a->owner)->post(route('teams.challenges.store'), ['opponent' => $b->slug, 'puzzle_id' => $puzzles[4]->id, 'roster' => e20Ids([$am[0]])])->assertStatus(429);
    expect(TeamChallenge::count())->toBe(4);

    $am[1]->forceFill(['is_frozen' => true])->save();
    $res = $this->actingAs($am[1])->get(route('teams.challenges.index'));
    expect($res->status())->not->toBe(200);
});

function e20ProductionFiles(): array
{
    return array_merge(
        glob(app_path('Services/Teams/TeamChallenge*.php')), glob(app_path('Services/Teams/TeamChampionship*.php')),
        [app_path('Services/Teams/TeamCompetitionLifecycleService.php'), app_path('Services/Teams/TeamScoreCalculator.php'), app_path('Services/Teams/TeamGloryService.php')],
        glob(app_path('Models/TeamChallenge*.php')), glob(app_path('Models/TeamChampionship*.php')),
        glob(app_path('Http/Controllers/TeamChallenge*.php')), [app_path('Http/Controllers/TeamChampionshipController.php')], glob(app_path('Http/Requests/TeamChallenge*.php')),
        [app_path('Listeners/SendTeamChallengeNotifications.php'), app_path('Listeners/QueueTeamChampionshipResultNotifications.php'), app_path('Jobs/DispatchTeamChampionshipNotificationsChunk.php')],
        glob(app_path('Events/TeamChallenge*.php')), [app_path('Events/TeamChampionshipFinalized.php')], glob(app_path('Policies/TeamChallengePolicy.php')), glob(app_path('Policies/TeamChampionshipPolicy.php')),
        glob(app_path('Filament/Resources/TeamChallengeResource.php')), glob(app_path('Filament/Resources/TeamChallengeResource/*/*.php')),
        glob(app_path('Filament/Resources/TeamChampionshipResource.php')), glob(app_path('Filament/Resources/TeamChampionshipResource/*/*.php')),
        glob(resource_path('views/teams/challenges/*.blade.php')), glob(resource_path('views/team-championships/*.blade.php')), [resource_path('views/teams/_glory.blade.php'), resource_path('views/components/team-challenge-row.blade.php')],
    );
}

test('static history audit: no team challenge result, standing or glory query reads the current team memberships - results come from the locked roster and stored rankings', function () {
    foreach (['TeamChallengeFinalizer', 'TeamChallengePlayService', 'TeamChampionshipStandingsService', 'TeamChampionshipService', 'TeamGloryService', 'TeamScoreCalculator'] as $class) {
        $code = e19Code(app_path("Services/Teams/{$class}.php"));
        expect($code)->not->toContain('TeamMembership')->and($code)->not->toContain('team_memberships')->and($code)->not->toContain('membershipOf')->and($code)->not->toContain('teamIdFor');
    }
    expect(e19Code(app_path('Services/Teams/TeamChallengeFinalizer.php')))->toContain('team_challenge_results')->and(e19Code(app_path('Services/Teams/TeamChallengeFinalizer.php')))->toContain('TeamChallengeParticipant');
});

test('static economy audit: no E20 code touches a wallet, currency, XP, inventory, entitlement or E18 reward - team competition is recognition only', function () {
    $files = e20ProductionFiles();
    expect(count($files))->toBeGreaterThan(40);
    $forbidden = ['CurrencyWalletService', 'creditPending', 'creditAvailable', 'grantXp', 'XpService', 'InventoryService', 'EntitlementService', 'StorePurchase', 'CompetitiveRewardGrant', 'CompetitiveRewardDistributionService',
        'ProgressionRewardService', 'AchievementService', 'wallet', 'multiplier', 'pending_balance', 'total_xp', 'treasury', 'donat', 'entry_fee', 'wager'];

    foreach ($files as $file) {
        $code = e19Code($file);

        foreach ($forbidden as $needle) {
            expect(preg_match('/\b'.preg_quote($needle, '/').'/i', $code))->toBe(0, basename($file)." must not reference {$needle}");
        }
    }
    foreach (['team_challenges', 'team_challenge_participants', 'team_challenge_results', 'team_championships', 'team_championship_events', 'team_championship_results'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            expect($column)->not->toMatch('/(^|_)(currency|wallet|balance|xp|reward|prize|cash|price|fee|donation|treasury|multiplier|wager|stake|bet)(_|$)/i');
        }
    }
});

test('static client-trust audit: no E20 controller reads a winner, score, rank, team or points from the request - only the whitelisted validated fields reach the services', function () {
    foreach (array_merge(glob(app_path('Http/Controllers/TeamChallenge*.php')), [app_path('Http/Controllers/TeamChampionshipController.php')]) as $file) {
        $code = e19Code($file);
        expect(preg_match('/\$request->(input|get|post|query|only|all|integer|string)\(\s*[\'"](score|winner_team_id|winner|rank|team_score|championship_points|points|status|team_id|challenger_team_id|is_draw)[\'"]/', $code))->toBe(0, basename($file));
    }
    foreach (glob(app_path('Http/Requests/TeamChallenge*.php')) as $file) {
        expect(e19Code($file))->not->toMatch('/score|winner|rank|points|status|team_id|is_draw/');
    }
    expect(e19Code(app_path('Http/Controllers/TeamChallengePlayController.php')))->toContain('answerOnly()');
});

test('static privacy and hygiene audit: no private field or raw HTML in the E20 views, and no debug leftovers in any E20 file', function () {
    foreach (array_merge(glob(resource_path('views/teams/challenges/*.blade.php')), glob(resource_path('views/team-championships/*.blade.php')), [resource_path('views/teams/_glory.blade.php')]) as $view) {
        $code = e19Code($view);

        foreach (['->email', "'email'", '->phone', 'phone', 'wallet', 'password', 'is_frozen', 'idempotency', 'remember_token'] as $private) {
            expect(stripos($code, $private))->toBeFalse(basename($view)." must not touch {$private}");
        }
        expect($code)->not->toContain('{!!');
    }

    foreach (e20ProductionFiles() as $file) {
        $code = e19Code($file);

        foreach (['/\bdd\(/', '/\bdump\(/', '/\bray\(/', '/\bvar_dump\(/', '/TODO/', '/FIXME/', '/console\.log/'] as $bad) {
            expect(preg_match($bad, $code))->toBe(0, basename($file)." must not match {$bad}");
        }
    }
});

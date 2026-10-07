<?php

require_once __DIR__.'/DemoQaFacts.php';

use App\Models\User;
use Database\Seeders\DemoQaSeeder;
use Illuminate\Support\Facades\DB;

/** كل اختبار هنا يقرأ "حقائق" الديمو (تُبذر مرة واحدة لكل عملية). القيم المتوقعة مصمَّمة بالبذور نفسها، فأي انحراف = تغيّر غير مقصود بالسيناريو. */
test('1/2: the seeder runs on a prepared database and rerunning it twice changes no table - no duplicate friendships, teams, events, results, grants or notifications', function () {
    $f = demoQaFacts($this);

    expect(count($f['counts_first']))->toBeGreaterThan(70)->and($f['counts_third'])->toBe($f['counts_first'])
        ->and($f['friendships'])->toBe(['accepted' => 10, 'pending' => 2])->and(count($f['teams']))->toBe(4)->and($f['rewards']['duplicates'])->toBe(0)->and($f['duplicate_keys'])->toBe(0);
    expect(array_sum($f['counts_first']))->toBeGreaterThan(1500);
});

test('3/4: the main account exists with a usable shared password and a public profile - all demo accounts are .test addresses', function () {
    $f = demoQaFacts($this);

    expect($f['users']['yousef'])->toMatchArray(['name' => 'يوسف الحمدان', 'email' => 'yousef@ahjiyat.test', 'role' => 'user', 'is_frozen' => false, 'profile_visibility' => 'public', 'verified' => true, 'password_ok' => true])
        ->and($f['users']['count'])->toBe(26)->and($f['users']['demo_visible_public'])->toBeGreaterThan(15);
});

test('5/6/7/8/9/A1: Yousef has five accepted friends, an incoming request (Reem), an outgoing request (Khaled), a block he made (Lama), a block against him (Firas), no relation (Omar) and a user who refuses requests (Dana)', function () {
    $f = demoQaFacts($this);

    expect($f['friends'])->toBe(['jana', 'layan', 'noureddine', 'sara', 'yaser'])->and($f['relations'])->toMatchArray([
        'sara' => 'friends', 'noureddine' => 'friends', 'yaser' => 'friends', 'layan' => 'friends', 'jana' => 'friends',
        'reem' => 'incoming_pending', 'khaled' => 'outgoing_pending', 'lama' => 'blocked_by_me', 'firas' => 'unavailable', 'omar' => 'none', 'dana' => 'unavailable',
    ])->and($f['blocks'])->toBe(['yousef_blocked_lama' => true, 'firas_blocked_yousef' => true])->and($f['dana'])->toBe(['enabled' => false, 'can_send' => false]);
});

test('10/11/D1/D2/D3: Yousef owns Knights of Sham with an admin and members - Sara owns the rival team - roles are owner, admin and member', function () {
    $f = demoQaFacts($this);

    expect($f['teams']['فرسان الشام'])->toMatchArray(['owner' => 'yousef', 'members' => 5, 'real_count' => 5, 'active' => true])
        ->and($f['teams']['فرسان الشام']['roles'])->toEqual(['yousef' => 'owner', 'omar' => 'admin', 'layan' => 'member', 'jana' => 'member', 'yaser' => 'member'])
        ->and($f['teams']['صقور المعرفة']['owner'])->toBe('sara')->and($f['one_team_each'])->toBe(0);
    foreach ($f['teams'] as $team) {
        expect($team['members'])->toBe($team['real_count']);
    }
});

test('12/13/D4-D10: join policies cover open, request and invite-only - one team is private and one is near capacity - a pending join request and a pending invitation exist', function () {
    $f = demoQaFacts($this);
    $policy = fn ($name) => [$f['teams'][$name]['policy'], $f['teams'][$name]['visibility']];

    expect($policy('صقور المعرفة'))->toBe(['open', 'public'])->and($policy('فرسان الشام'))->toBe(['request', 'public'])->and($policy('عباقرة الشرق'))->toBe(['invite_only', 'public'])->and($policy('نجوم الأحجيات'))->toBe(['request', 'private'])
        ->and([$f['teams']['صقور المعرفة']['members'], $f['teams']['صقور المعرفة']['max']])->toBe([5, 6])
        ->and($f['pending_requests'])->toBe(['khaled→فرسان الشام'])->and($f['pending_invitations'])->toBe(['kenan←فرسان الشام'])->and($f['accepted_requests'])->toBe(6);
});

test('14/15/E1-E4: individual events exist in every state and the finalized cups have complete leaderboards - Yousef finished 5th, 7th, 2nd and 1st', function () {
    $f = demoQaFacts($this);

    expect($f['events']['demo-cup-1']['status'])->toBe('completed')->and($f['events']['demo-cup-4']['team_rankings'])->toBeTrue()
        ->and($f['events']['demo-live'])->toMatchArray(['status' => 'published', 'starts_past' => true, 'ends_future' => true, 'participants' => 5])
        ->and($f['events']['demo-upcoming'])->toMatchArray(['status' => 'published', 'starts_past' => false])->and($f['events']['demo-cancelled']['status'])->toBe('cancelled');

    expect($f['ranks']['demo-cup-1']['top3'])->toBe(['reem', 'shatha', 'sara'])->and($f['ranks']['demo-cup-2']['top3'])->toBe(['sara', 'malak', 'wisam'])
        ->and($f['ranks']['demo-cup-3']['top3'])->toBe(['reem', 'yousef', 'omar'])->and($f['ranks']['demo-cup-4']['top3'])->toBe(['yousef', 'sara', 'omar'])
        ->and([$f['ranks']['demo-cup-1']['yousef'], $f['ranks']['demo-cup-2']['yousef'], $f['ranks']['demo-cup-3']['yousef'], $f['ranks']['demo-cup-4']['yousef']])->toBe([5, 7, 2, 1]);

    foreach ($f['ranks'] as $cup) {
        expect($cup['ranks'])->toBe(range(1, $cup['count']))->and($cup['count'])->toBeGreaterThanOrEqual(11);
    }
});

test('16/E5: Yousef has every friend challenge state - a win, a loss, a real draw, an active one and an incoming pending one', function () {
    expect(demoQaFacts($this)['friend_challenges'])->toBe([['completed', 'yousef'], ['completed', 'yaser'], ['completed', 'draw'], ['accepted', null], ['pending', null]]);
});

test('17/E6/E7: the rewards of the reward cup were granted once per participant through the official distribution, and the competitive recognition is partly unlocked and partly in progress', function () {
    $f = demoQaFacts($this);

    expect($f['rewards'])->toMatchArray(['grants' => 14, 'granted' => 14, 'cup3_participants' => 14, 'duplicates' => 0, 'reem_rmd' => 150, 'yousef_rmd' => 60])
        ->and($f['comp_achievements']['competitive_first_win'])->toBe([1, true])->and($f['comp_achievements']['competitive_first_top3'])->toBe([2, true])
        ->and($f['comp_achievements']['competitive_three_wins'])->toBe([1, false])->and($f['comp_achievements']['competitive_five_completed'])->toBe([4, false]);
});

test('18/F1/F2: every finalized cup has a stored team standing of four teams that equals a fresh recomputation from the registration snapshots, and no snapshot is missing or disagrees with membership', function () {
    $f = demoQaFacts($this);

    expect($f['team_event_standings']['demo-cup-1']['order'])->toBe(['عباقرة الشرق', 'صقور المعرفة', 'فرسان الشام', 'نجوم الأحجيات'])
        ->and($f['team_event_standings']['demo-cup-2']['order'])->toBe(['صقور المعرفة', 'فرسان الشام', 'عباقرة الشرق', 'نجوم الأحجيات'])
        ->and($f['team_event_standings']['demo-cup-3']['order'])->toBe(['فرسان الشام', 'صقور المعرفة', 'عباقرة الشرق', 'نجوم الأحجيات'])
        ->and($f['team_event_standings']['demo-cup-4']['order'])->toBe(['فرسان الشام', 'صقور المعرفة', 'عباقرة الشرق', 'نجوم الأحجيات'])
        ->and(collect($f['team_event_standings'])->every(fn ($s) => $s['match']))->toBeTrue()->and($f['null_snapshots'])->toBe(0)->and($f['snapshot_mismatch'])->toBe(0);
});

test('19/20/F3/F4: team challenges cover a win, a loss, a draw, an active and a pending one - every accepted or completed match has a fully locked roster with role snapshots and no stranger on a team', function () {
    $c = demoQaFacts($this)['team_challenges'];

    expect($c)->toHaveCount(5)
        ->and($c[0])->toMatchArray(['pair' => 'فرسان الشام|نجوم الأحجيات', 'status' => 'completed', 'winner' => 'فرسان الشام', 'participants' => 5, 'locked' => 5, 'results' => 2])
        ->and($c[1])->toMatchArray(['pair' => 'فرسان الشام|صقور المعرفة', 'status' => 'completed', 'winner' => 'صقور المعرفة', 'locked' => 5, 'results' => 2])
        ->and($c[2])->toMatchArray(['pair' => 'فرسان الشام|عباقرة الشرق', 'status' => 'completed', 'winner' => null, 'draw' => true, 'locked' => 2, 'results' => 2])
        ->and($c[3])->toMatchArray(['status' => 'accepted', 'participants' => 5, 'locked' => 5, 'results' => 0, 'draw' => false])
        ->and($c[4])->toMatchArray(['pair' => 'صقور المعرفة|فرسان الشام', 'status' => 'pending', 'participants' => 2, 'locked' => 0, 'results' => 0]);

    foreach ($c as $challenge) {
        expect($challenge['bad_team'])->toBe(0);

        if ($challenge['status'] !== 'pending') {
            expect($challenge['roles_set'])->toBe($challenge['participants']);
        }
    }
});

test('21/22/F5-F8: two championships are completed with deterministic standings and different champions - one is live with a provisional standing and one is upcoming - Yousef team is champion of the autumn cup', function () {
    $c = demoQaFacts($this)['championships'];

    expect($c['demo-championship-spring'])->toMatchArray(['status' => 'completed', 'champion' => 'صقور المعرفة', 'events' => 2])
        ->and($c['demo-championship-spring']['standing'])->toBe([['صقور المعرفة', 17, 1], ['عباقرة الشرق', 15, 2], ['فرسان الشام', 12, 3], ['نجوم الأحجيات', 6, 4]])
        ->and($c['demo-championship-autumn'])->toMatchArray(['status' => 'completed', 'champion' => 'فرسان الشام'])
        ->and($c['demo-championship-autumn']['standing'])->toBe([['فرسان الشام', 20, 1], ['صقور المعرفة', 14, 2], ['عباقرة الشرق', 10, 3], ['نجوم الأحجيات', 6, 4]])
        ->and($c['demo-championship-winter'])->toMatchArray(['status' => 'published', 'phase' => 'live', 'champion' => null, 'events' => 2, 'standing' => []])
        ->and($c['demo-championship-spring-2'])->toMatchArray(['status' => 'published', 'phase' => 'upcoming', 'events' => 1]);
    expect(collect($c)->every(fn ($x) => $x['linked_ok']))->toBeTrue();
});

test('23/24/G: the notification center is lively but calm - five to twelve per main account with read and unread - no duplicate semantic key and only real registered types', function () {
    $f = demoQaFacts($this);

    foreach (['yousef', 'sara', 'reem'] as $key) {
        expect($f['notifications'][$key]['total'])->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(12)->and($f['notifications'][$key]['unread'])->toBeGreaterThan(0)->toBeLessThan($f['notifications'][$key]['total']);
    }
    expect($f['duplicate_keys'])->toBe(0)->and($f['notification_types'])->toContain('friend_request_received', 'team_invitation_received', 'team_join_request_received', 'competitive_event_result_ready', 'competitive_reward_granted', 'team_challenge_received',
        'team_challenge_result_ready', 'team_championship_result_ready', 'team_championship_started');
    $registered = array_map(fn ($c) => $c->value, \App\Services\Notifications\NotificationType::cases());
    expect(array_diff($f['notification_types'], $registered))->toBe([]);
});

test('27/B: the economy was built only through the official services - every wallet equals its transactions, every XP total equals its ledger, nothing is negative - with varied levels, an equipped store item and a seven day streak', function () {
    $f = demoQaFacts($this);

    expect($f['ledger'])->toMatchArray(['wallet_mismatch' => 0, 'xp_mismatch' => 0, 'negative' => 0])->and($f['ledger']['wallets'])->toBeGreaterThan(20)
        ->and($f['levels']['reem'][0])->toBeGreaterThan($f['levels']['yousef'][0])->and($f['levels']['yousef'][0])->toBeGreaterThan($f['levels']['sara'][0])->and($f['levels']['yousef'][0])->toBeBetween(7, 10)
        ->and(collect($f['levels'])->pluck(0)->unique()->count())->toBeGreaterThanOrEqual(6)->and($f['levels'])->not->toHaveKey('kenan')
        ->and($f['store'])->toMatchArray(['items' => 5, 'equipped' => 1, 'sara_equipped' => 1])->and($f['store']['owned'])->toBeGreaterThanOrEqual(3)->and($f['streak'])->toBe(7);
});

test('B6/C: daily quests are partly completed from real attempts, and the campaign is completed, in progress, early or untouched - all derived from the official services', function () {
    $f = demoQaFacts($this);

    expect($f['quests_today']['daily_solve_1'])->toBe([2, 1, true])->and($f['quests_today']['daily_solve_2'])->toBe([2, 2, true])->and($f['quests_today']['daily_solve_3'])->toBe([2, 3, false])
        ->and($f['campaign'])->toBe(['sara' => ['done' => 22, 'completed' => true], 'yousef' => ['done' => 6, 'completed' => false], 'reem' => ['done' => 3, 'completed' => false], 'kenan' => ['done' => 0, 'completed' => false]]);
});

test('25/H: the admin and analytics views are not empty - they are fed by the seeded scenarios - and the existing fraud flags and frozen or unverified users are reused', function () {
    $f = demoQaFacts($this);

    foreach ($f['analytics'] as $service => $hasData) {
        expect($hasData)->toBeTrue("{$service} must report data from the seeded scenarios");
    }

    $teams = $f['team_analytics'];
    expect(array_keys($f['analytics']))->toHaveCount(8)->and($teams['challenges'])->toMatchArray(['created' => 5, 'completed' => 3, 'accepted' => 4])->and($teams['championships']['completed'])->toBe(2)->and($teams['championships']['champions'])->not->toBe([])
        ->and($f['fraud_flags'])->toBe(3)->and($f['users']['tarek_frozen'])->toBeTrue()->and($f['users']['huda_frozen'])->toBeTrue()->and($f['users']['muath_unverified'])->toBeTrue()
        ->and($f['counts']['admin_audit'])->toBeGreaterThan(5)->and($f['counts']['failed_jobs'])->toBe(0)->and($f['counts']['jobs'])->toBe(0);
});

test('26: the seeder refuses production and every unknown environment without touching a table - and runs in local, testing and staging', function () {
    $before = demoQaCounts();

    foreach (['production', 'prod', 'development', 'qa'] as $env) {
        app()->detectEnvironment(fn () => $env);
        expect(fn () => (new DemoQaSeeder)->run())->toThrow(RuntimeException::class, 'local/testing/staging');
    }

    foreach (['local', 'testing', 'staging'] as $env) {
        app()->detectEnvironment(fn () => $env);
        DemoQaSeeder::assertSafeEnvironment();
    }

    app()->detectEnvironment(fn () => 'testing');
    expect(demoQaCounts())->toBe($before)->and(User::query()->count())->toBe($before['users']);
});

test('26b: the demo pack is opt-in - not wired into DatabaseSeeder, config, routes, bootstrap or composer scripts - so no deployment runs it', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('Demo');

    foreach ([base_path('composer.json'), base_path('package.json')] as $file) {
        expect(file_get_contents($file))->not->toContain('DemoQa');
    }

    foreach (['app', 'config', 'routes', 'bootstrap'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                expect(file_get_contents($file->getPathname()))->not->toContain('DemoQaSeeder');
            }
        }
    }
});

function demoQaSeederFiles(): array
{
    return array_merge(glob(database_path('seeders/Demo/*.php')), [database_path('seeders/DemoQaSeeder.php')]);
}

test('determinism: no randomness anywhere in the demo seeders - same users, teams, results and ranks every run', function () {
    expect(count(demoQaSeederFiles()))->toBe(12);

    foreach (demoQaSeederFiles() as $file) {
        $code = demoQaCode($file);

        foreach (['fake(', 'rand(', 'mt_rand', 'random_int', 'shuffle(', 'inRandomOrder', 'Str::random', 'Factory', 'factory(', 'array_rand', 'Str::uuid', 'Str::ulid'] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not use {$needle}");
        }
    }
});

test('28/27b: the demo seeders never mutate economy, XP or derived progress directly, never delete or reset anything, and leave no debug code', function () {
    foreach (demoQaSeederFiles() as $file) {
        $code = demoQaCode($file);

        foreach (['->increment(', '->decrement(', 'available_balance', 'pending_balance', 'lifetime_', 'total_xp', "DB::table('wallets')", 'Wallet::', 'campaign_completed', 'is_completed', 'campaign_progress'] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not touch {$needle}");
        }

        foreach (['truncate', 'migrate:fresh', 'db:wipe', '->delete(', 'forceDelete', 'DELETE FROM', 'Schema::drop', 'dropIfExists', '->forget('] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not use {$needle}");
        }

        foreach (['/\bdd\(/', '/\bdump\(/', '/\bray\(/', '/\bvar_dump\(/', '/TODO/', '/FIXME/'] as $bad) {
            expect(preg_match($bad, $code))->toBe(0, basename($file)." must not match {$bad}");
        }
    }

    // لا ترحيلات ولا ملفات منتج جديدة: الحزمة بيانات فقط.
    expect(glob(database_path('migrations/*qa*')))->toBe([])->and(glob(database_path('migrations/*demo*')))->toBe([]);
});


test('59/E21-M: the chat demo is idempotent - the chat tables keep exactly the same rows after the second and third run - and it created no notification, economy or queue residue', function () {
    $f = demoQaFacts($this);

    foreach (['chat_threads', 'chat_messages', 'chat_read_states', 'chat_message_reports', 'chat_mutes'] as $table) {
        expect($f['counts_third'][$table])->toBe($f['counts_first'][$table])->and($f['counts_first'][$table])->toBeGreaterThan(0);
    }
    expect($f['chat']['chat_tables'])->toMatchArray(['chat_threads' => 4, 'chat_messages' => 40, 'chat_message_reports' => 1, 'chat_mutes' => 1])->and($f['chat']['chat_notifications'])->toBe(0)
        ->and($f['counts']['failed_jobs'])->toBe(0)->and($f['counts']['jobs'])->toBe(0);
});

test('60/M1/M2/M7: Yousef has two direct chats at once - with Sara (eight messages) and with Layan (four) - from his main persona', function () {
    $f = demoQaFacts($this);

    expect(array_keys($f['chat']['direct']))->toBe(['سارة القيسي', 'ليان سلطان'])->and($f['chat']['direct']['سارة القيسي']['messages'])->toBe(8)->and($f['chat']['direct']['ليان سلطان']['messages'])->toBe(4)
        ->and($f['chat']['threads'])->toMatchArray(['direct' => 2, 'team' => 1, 'global' => 1])->and($f['chat']['yousef_send'])->toBeNull();      // يوسف يستطيع الإرسال فورًا بالعامة (ليس مكتومًا)
});

test('61/M3: the team chat demo has ten short messages from five different members and one edited message', function () {
    $team = demoQaFacts($this)['chat']['team'];

    expect($team['team_name'])->toBe('فرسان الشام')->and($team['messages'])->toBe(10)->and($team['senders'])->toBe(['jana', 'layan', 'omar', 'yaser', 'yousef'])->and($team['edited'])->toBe(1);
});

test('62/M4: the global chat demo has eighteen messages from eighteen different users, one tombstone, and the messages of the two users Yousef is blocked with are filtered from his view only', function () {
    $global = demoQaFacts($this)['chat']['global'];

    expect($global['messages'])->toBe(18)->and($global['senders'])->toBe(18)->and($global['deleted'])->toBe(1)->and($global['visible_to_yousef'])->toHaveCount(16)
        ->and($global['visible_to_yousef'])->not->toContain('lama')->not->toContain('firas')->and($global['visible_to_yousef'])->toContain('yousef');
});

test('63/M1: the chat demo has both read and unread - one unread direct message from Sara, none from Layan, unread team and global messages - and the total feeds the badge', function () {
    $chat = demoQaFacts($this)['chat'];

    expect($chat['direct']['سارة القيسي']['unread'])->toBe(1)->and($chat['direct']['ليان سلطان']['unread'])->toBe(0)->and($chat['team']['unread'])->toBe(4)->and($chat['global']['unread'])->toBeGreaterThan(0)
        ->and($chat['unread_total'])->toBe(1 + 4 + $chat['global']['unread']);
});

test('64/M5/M6: one pending report sits on the advertising global message by Khaled, Wisam is temporarily muted and Yousef is not', function () {
    $chat = demoQaFacts($this)['chat'];

    expect($chat['reports'])->toBe([['pending', 'spam', 'khaled', 'sara', 'global']])->and($chat['mutes'])->toBe(['wisam'])->and($chat['mutes'])->not->toContain('yousef');
});

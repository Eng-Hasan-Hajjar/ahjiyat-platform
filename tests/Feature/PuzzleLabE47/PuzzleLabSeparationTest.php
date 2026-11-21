<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\PuzzleLabExercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E47 - فصل التدريب عن التقدّم الرسمي: لا جواهر ولا XP ولا إنجازات ولا ترتيب ولا حملات ولا مهام ولا سلسلة نشاط ولا محاولة رسمية ولا إشعارات ولا سجل تدريب،
| وأن اللعب الرسمي لنوعَي memory/sequence بقي كما كان. القياس بعدّ صفوف كل الجداول (لا بقائمة أسماء أتوقعها)، فأي كتابة جديدة تُكتشف.
*/

/** جدول واحد فقط مسموح بتغيّره: تتبّع الأمان العام لكل طلب مصادَق (E24، مرة كل دقيقة لكل مستخدم/IP/جهاز) - وسيط عام لا علاقة له بالمختبر. */
const LAB_ALLOWED_GLOBAL_WRITES = ['device_sightings'];

function lab_changed_tables(array $before, array $after): array
{
    return array_values(array_diff(array_keys(array_filter($after, fn ($n, $t) => ($before[$t] ?? 0) !== $n, ARRAY_FILTER_USE_BOTH)), LAB_ALLOWED_GLOBAL_WRITES));
}

/**
 * أول صفحة مصادَقة لأي لاعب (من أي قسم بالموقع، لا المختبر وحده) تُنشئ له صف تقدّم صفريًا (total_xp=0، المستوى 1) وصف تتبّع الجهاز.
 * هذا سلوك قائم بالتخطيط العام قبل E47، فنسخّن به قبل القياس ثم نثبت أن قيم التقدّم نفسها لا تتغيّر.
 */
function lab_warm_up(User $user): void
{
    test()->actingAs($user)->get(route('puzzles.index'))->assertOk();
}

function lab_run_full_session(User $user, PuzzleLabExercise $sequence, PuzzleLabExercise $memory): void
{
    $t = test();

    $t->actingAs($user)->get(route('puzzle-lab.index'))->assertOk();
    $t->actingAs($user)->get(route('puzzle-lab.show', $sequence))->assertOk();
    $t->actingAs($user)->get(route('puzzle-lab.show', $memory))->assertOk();

    // تسلسل: خطأ ثم جولة جديدة ثم نجاح
    $s1 = lab_start($user, $sequence);
    $t->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $sequence), ['round' => $s1['round'], 'order' => array_reverse(lab_correct_tokens($sequence, $s1))])->assertOk();
    $s2 = lab_start($user, $sequence);
    expect($t->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $sequence), ['round' => $s2['round'], 'order' => lab_correct_tokens($sequence, $s2)])->json('correct'))->toBeTrue();

    // ذاكرة: تحقق ناقص (خطأ) ثم نجاح
    $m1 = lab_start($user, $memory);
    $pairs = lab_memory_pairs($user, $memory, $m1);
    $t->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $memory), ['round' => $m1['round'], 'matches' => [$pairs[0]]])->assertOk();
    expect($t->actingAs($user)->postJson(route('puzzle-lab.rounds.check', $memory), ['round' => $m1['round'], 'matches' => $pairs])->json('correct'))->toBeTrue();
}

test('SEP-01: a full lab session (open, wrong, retry, success, memory) writes nothing - wallet, attempts, XP, progress, missions, notifications are all untouched', function () {
    $user = lab_player();
    $sequence = lab_exercise('sequence');
    $memory = lab_exercise('memory');

    lab_warm_up($user);

    $walletBefore = $user->wallet()->first()->getAttributes();
    $userBefore = User::find($user->id)->getAttributes();
    $progressionBefore = DB::table('player_progressions')->where('user_id', $user->id)->first();
    $before = lab_table_counts();

    lab_run_full_session($user, $sequence, $memory);

    $after = lab_table_counts();

    expect(lab_changed_tables($before, $after))->toBe([])
        ->and($user->wallet()->first()->getAttributes())->toBe($walletBefore)
        ->and(User::find($user->id)->getAttributes())->toBe($userBefore)
        ->and(DB::table('player_progressions')->where('user_id', $user->id)->first())->toEqual($progressionBefore)
        ->and((int) $progressionBefore->total_xp)->toBe(0)
        ->and(PuzzleAttempt::count())->toBe(0);
});

test('SEP-02: the same holds for a draft previewed by an administrator (staff rounds on drafts are training too)', function () {
    $admin = lab_admin();
    $draft = lab_exercise('sequence', 'draft');
    lab_warm_up($admin);
    $before = lab_table_counts();
    $walletBefore = $admin->wallet()->first()->getAttributes();

    $this->actingAs($admin)->get(route('puzzle-lab.preview', $draft))->assertOk();
    $start = lab_start($admin, $draft);
    $this->actingAs($admin)->postJson(route('puzzle-lab.rounds.check', $draft), ['round' => $start['round'], 'order' => lab_correct_tokens($draft, $start)])->assertOk()->assertJson(['correct' => true]);

    expect(lab_changed_tables($before, lab_table_counts()))->toBe([])
        ->and($admin->wallet()->first()->getAttributes())->toBe($walletBefore);
});

test('SEP-03: no stored round - the lab owns exactly one table and it holds exercises only', function () {
    $user = lab_player();
    $exercise = lab_exercise('memory');
    $before = DB::table('puzzle_lab_exercises')->count();

    lab_run_full_session($user, lab_exercise('sequence'), $exercise);

    $labTables = collect(array_keys(lab_table_counts()))->filter(fn ($t) => str_contains($t, 'lab'))->values()->all();
    expect($labTables)->toBe(['puzzle_lab_exercises'])
        ->and(DB::table('puzzle_lab_exercises')->count())->toBe($before + 1) // +1: التمرين الذي أنشأه الاختبار نفسه أثناء الجلسة
        ->and(DB::table('puzzle_lab_exercises')->count())->toBe(2);
});

test('SEP-04: the lab cannot reach official, paid or unpublished puzzles - it only serves its own exercises by slug', function () {
    $user = lab_player();
    $official = Puzzle::factory()->create(['game_type' => 'memory', 'game_config' => ['faces' => ['أ', 'ب', 'ج']], 'is_active' => true]);
    $inactive = Puzzle::factory()->create(['game_type' => 'sequence', 'game_config' => ['items' => ['1', '2', '3']], 'is_active' => false]);
    lab_exercise('memory', 'published', ['slug' => 'real-exercise']);

    // أي رابط بمفتاح أحجية رسمية أو مسودة لا يفتح شيئًا (ولا يحلّ slug رقمي إلى أحجية).
    foreach ([$official->id, $inactive->id, 'puzzle-'.$official->id] as $key) {
        $this->actingAs($user)->get('/puzzle-lab/'.$key)->assertNotFound();
        $this->actingAs($user)->postJson('/puzzle-lab/'.$key.'/rounds')->assertNotFound();
    }

    // لا بيانات من جدول الأحجيات تصل صفحة المختبر.
    $html = $this->actingAs($user)->get(route('puzzle-lab.index'))->getContent();
    expect($html)->not->toContain((string) $official->prompt);
});

test('SEP-05: drafts and hidden exercises are confined to the admin side - players get 404 on the page, the preview and every round endpoint', function () {
    $player = lab_player();
    $admin = lab_admin();

    foreach (['draft', 'hidden'] as $status) {
        $exercise = lab_exercise('sequence', $status);

        $this->actingAs($player)->get(route('puzzle-lab.show', $exercise))->assertNotFound();
        $this->actingAs($player)->get(route('puzzle-lab.preview', $exercise))->assertNotFound();
        $this->actingAs($player)->postJson(route('puzzle-lab.rounds.start', $exercise))->assertNotFound();
        $this->actingAs($player)->postJson(route('puzzle-lab.rounds.flip', $exercise), ['round' => str_repeat('a', 24), 'card' => 0])->assertNotFound();
        $this->actingAs($player)->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => str_repeat('a', 24), 'order' => []])->assertNotFound();

        // لا تظهر بالقائمة حتى للمسؤول (القائمة للاعبين؛ المسودة بالمعاينة فقط).
        $this->actingAs($admin)->get(route('puzzle-lab.index'))->assertOk()->assertDontSee($exercise->title);
        $this->actingAs($admin)->get(route('puzzle-lab.show', $exercise))->assertNotFound();
    }
});

test('SEP-06: official play for memory and sequence is unchanged - attempts are recorded and gems still awarded through the official path', function () {
    $user = lab_player();

    $memory = Puzzle::factory()->create([
        'game_type' => 'memory', 'validation_type' => 'memory_match', 'score_mode' => 'flat', 'renderer' => 'games.memory',
        'game_config' => ['faces' => ['أ', 'ب', 'ج']], 'gem_reward' => 18,
    ]);
    $pairs = collect($memory->fresh()->game_config['cards'])->groupBy('face')->map(fn ($g) => $g->pluck('id')->all())->values()->all();

    $this->actingAs($user)->post(route('puzzles.attempt', $memory), ['submission' => ['matches' => $pairs]])->assertSessionHas('success');
    expect($user->wallet->fresh()->pending_balance)->toBe(18)->and(PuzzleAttempt::where('user_id', $user->id)->where('puzzle_id', $memory->id)->count())->toBe(1);

    $sequence = Puzzle::factory()->create([
        'game_type' => 'sequence', 'validation_type' => 'sequence_match', 'score_mode' => 'flat', 'renderer' => 'games.sequence',
        'game_config' => ['items' => ['أ', 'ب', 'ج']], 'gem_reward' => 7,
    ]);
    $page = $this->actingAs($user)->get(route('puzzles.show', $sequence))->assertOk();
    $items = collect(\App\GameEngine\Support\SequenceTokens::expectedOrder($sequence->fresh()))->all();

    $this->actingAs($user)->post(route('puzzles.attempt', $sequence), ['submission' => ['order' => $items]])->assertSessionHas('success');
    expect($user->wallet->fresh()->pending_balance)->toBe(25)->and(PuzzleAttempt::where('user_id', $user->id)->count())->toBe(2);
    $page->assertSee('نقل للأعلى');   // عارض التسلسل الرسمي لم يتغيّر
});

test('SEP-07: static guard - nothing in the lab code can reach wallets, attempts, progression, missions, campaigns, achievements, leaderboards or notifications', function () {
    $files = array_merge(
        glob(base_path('app/Services/PuzzleLab/*.php')),
        [base_path('app/Http/Controllers/PuzzleLabController.php'), base_path('app/Models/PuzzleLabExercise.php'), base_path('routes/puzzle_lab.php'), base_path('resources/js/games/renderers/lab.js')],
    );

    $forbidden = '/PuzzleAttemptService|GameSessionService|GemWalletService|WalletService|ProgressionService|EngagementService|StreakService|MissionCenter|MissionAssignment|CampaignProgress|AchievementService|Leaderboard|Notification|NotificationDispatcher|CurrencyTransaction|GemTransaction|XpTransaction|PuzzleAttempt|App\\\\Models\\\\Wallet|App\\\\Models\\\\Campaign|CompetitiveRewards|localStorage|sessionStorage|indexedDB/';

    foreach ($files as $file) {
        $source = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents($file)); // التعليقات لا تُحتسب
        expect(preg_match($forbidden, $source, $m) === 1 ? $file.' => '.$m[0] : null)->toBeNull();
    }

    // ولا يكتب المتحكّم/الخدمة في قاعدة البيانات أبدًا.
    foreach ([base_path('app/Services/PuzzleLab/PuzzleLabRounds.php'), base_path('app/Http/Controllers/PuzzleLabController.php')] as $file) {
        expect(file_get_contents($file))->not->toMatch('/->(save|create|update|delete|insert|increment|decrement)\(|DB::|::create\(|::insert\(|firstOrCreate|updateOrCreate/');
    }
});

test('SEP-08: every lab route sits behind the official sign-in rules, writes are POST-only with a named throttle, and no route name carries a reward word', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'puzzle-lab.'));

    expect($routes->count())->toBe(6);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('auth')->toContain('verified')->toContain('account.active');
        expect(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:puzzle-lab-')))->toBeTrue();
        expect($route->getName().' '.$route->uri())->not->toMatch('/reward|grant|claim|payout|prize/i');
        expect($route->methods())->each->not->toContain('PUT')->not->toContain('PATCH')->not->toContain('DELETE');
    }
});

test('SEP-09: the lab link lives on the puzzles browse page only - no permanent tab was added to the mobile bar or the main navigation', function () {
    $user = lab_player();
    $html = $this->actingAs($user)->get(route('puzzles.index'))->assertOk()->assertSee('مختبر الأحجيات')->getContent();

    // مرة واحدة فقط (بطاقة المدخل)؛ لا نسخة ثانية في شريط الجوال أو القائمة.
    expect(substr_count($html, 'href="'.route('puzzle-lab.index').'"'))->toBe(1);

    $urls = [];
    $menu = \App\Support\NavigationMenu::build($user, ['show_puzzles_link' => true]);
    array_walk_recursive($menu, function ($value, $key) use (&$urls) {
        if ($key === 'url') {
            $urls[] = (string) $value;
        }
    });
    expect($urls)->not->toBeEmpty()->and(collect($urls)->contains(fn ($u) => str_contains($u, 'puzzle-lab')))->toBeFalse();
});

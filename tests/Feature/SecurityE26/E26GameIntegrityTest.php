<?php

require_once __DIR__.'/_support.php';

use App\GameEngine\Support\SequenceTokens;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| E26 (O/P) - نزاهة اللعب: ما يُرسل للعميل لا يكشف الحل، الألغاز غير الفعّالة لا تُلعب ولا تدفع، سقف الكسب اليومي صلب، وبوابات API (الخاملة) لا تتجاوز شروط الويب.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e26_sequence_puzzle')) {
    function e26_sequence_puzzle(array $attrs = []): Puzzle
    {
        return Puzzle::factory()->create($attrs + [
            'game_type' => 'sequence', 'validation_type' => 'sequence_match', 'score_mode' => 'flat', 'renderer' => 'games.sequence',
            'game_config' => ['items' => ['أولًا', 'ثانيًا', 'ثالثًا', 'رابعًا']], 'gem_reward' => 6,
        ]);
    }

    function e26_solve($test, User $user, Puzzle $puzzle, array $order)
    {
        return $test->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['submission' => json_encode(['order' => $order])]);
    }
}

test('O1: sequence items are shown with opaque per-puzzle tokens - never the raw index - and the display order does not reveal the answer order', function () {
    $user = e26_user();
    $puzzle = e26_sequence_puzzle();
    $expected = SequenceTokens::expectedOrder($puzzle);

    expect($expected)->toHaveCount(4)->and(array_unique($expected))->toHaveCount(4);
    foreach ($expected as $token) {
        expect($token)->toMatch('/^[0-9a-f]{16}$/');
    }

    $html = $this->actingAs($user)->get(route('puzzles.show', $puzzle))->assertOk()->getContent();
    foreach ($expected as $token) {
        expect($html)->toContain($token);
    }
    // لا مصفوفة فهارس خام ولا ترتيب حل ظاهر.
    expect($html)->not->toContain('"order"')->not->toContain('solution_data')->not->toContain('[0,1,2,3]')->not->toContain('answer_normalized');

    // الرموز تتغير بتغيّر اللغز وبمفتاح التطبيق (لا يمكن حسابها من لغز آخر ولا دون المفتاح).
    $other = e26_sequence_puzzle();
    expect(SequenceTokens::expectedOrder($other))->not->toBe($expected);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(SequenceTokens::expectedOrder($puzzle))->not->toBe($expected);
});

test('O2: raw ascending indexes, reversed or partial tokens, duplicates and foreign tokens never solve a sequence puzzle - only the exact token order pays', function () {
    $user = e26_user();
    $puzzle = e26_sequence_puzzle(['max_attempts' => 50]);
    $expected = SequenceTokens::expectedOrder($puzzle);
    $foreign = SequenceTokens::expectedOrder(e26_sequence_puzzle());

    foreach ([[0, 1, 2, 3], ['0', '1', '2', '3'], array_reverse($expected), array_slice($expected, 0, 3), [$expected[0], $expected[0], $expected[1], $expected[2]], $foreign, [], 'abc'] as $wrong) {
        \Illuminate\Support\Facades\Cache::flush();
        e26_solve($this, $user, $puzzle, is_array($wrong) ? $wrong : [$wrong]);
    }
    expect(PuzzleAttempt::where('user_id', $user->id)->where('is_correct', true)->count())->toBe(0);
    expect((int) $user->wallet->refresh()->pending_balance)->toBe(0);

    \Illuminate\Support\Facades\Cache::flush();
    e26_solve($this, $user, $puzzle, $expected)->assertSessionHas('success');
    expect((int) $user->wallet->refresh()->pending_balance)->toBe(6);
});

test('O3: the API puzzle payload never contains the solution (answer, normalized answer, solution_data) and uses opaque tokens for sequence items', function () {
    e26_sanctum_table();
    $user = e26_user();
    $puzzle = e26_sequence_puzzle(['answer_raw' => 'سر-الحل']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/puzzles/'.$puzzle->getKey())->assertOk();
    $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

    foreach (['answer_raw', 'answer_normalized', 'solution_data', 'سر-الحل', '"order"'] as $needle) {
        expect($json)->not->toContain($needle);
    }
    foreach (SequenceTokens::expectedOrder($puzzle) as $token) {
        expect($json)->toContain($token);
    }
});

test('O4: inactive (draft) puzzles cannot be viewed, attempted, hinted or rewarded by players - web and API - while staff with admin access can preview', function () {
    e26_sanctum_table();
    $player = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'سر', 'gem_reward' => 9, 'is_active' => false]);

    $this->actingAs($player)->get(route('puzzles.show', $puzzle))->assertNotFound();
    $this->actingAs($player)->post(route('puzzles.attempt', $puzzle), ['answer' => 'سر'])->assertSessionHas('error');
    $this->actingAs($player)->post(route('puzzles.hint', $puzzle))->assertSessionHas('error');
    Sanctum::actingAs($player);
    $this->getJson('/api/puzzles/'.$puzzle->getKey())->assertNotFound();
    expect($this->postJson('/api/puzzles/'.$puzzle->getKey().'/attempt', ['answer' => 'سر'])->getStatusCode())->toBeIn([403, 404, 422]);

    expect(PuzzleAttempt::where('puzzle_id', $puzzle->id)->count())->toBe(0);
    expect((int) $player->wallet->refresh()->pending_balance)->toBe(0);

    $admin = e26_user();
    $admin->assignRole('administrator');
    $this->actingAs($admin)->get(route('puzzles.show', $puzzle))->assertOk();
});

test('O5: the daily earn cap is a hard ceiling across many different puzzles, and the cap check runs under the wallet row lock (closes the concurrent-attempt race)', function () {
    config(['gems.daily_earn_cap' => 20]);
    $user = e26_user();

    foreach (range(1, 6) as $i) {
        $puzzle = Puzzle::factory()->create(['answer_raw' => 'ج'.$i, 'gem_reward' => 8]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'ج'.$i]);
    }

    $earned = (int) $user->wallet->refresh()->pending_balance + (int) $user->wallet->available_balance;
    expect($earned)->toBe(20);   // 8 + 8 + 4 (جزئي) ثم 0

    $source = e26_php_code(base_path('app/Services/PuzzleAttemptService.php'));
    $lock = strpos($source, 'lockWallet(');
    $read = strpos($source, 'daily_earn_cap');
    expect($lock)->not->toBeFalse()->and($read)->not->toBeFalse()->and($lock)->toBeLessThan($read);
});

test('O6: the hint is never in the page, is refused (and not charged) without balance or without a stored hint, and costs exactly the configured amount once paid', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['hint' => 'تلميح-سري-مدفوع', 'answer_raw' => 'الحل-السري']);
    $cost = (int) config('gems.hint_cost');

    $page = $this->actingAs($user)->get(route('puzzles.show', $puzzle))->assertOk()->getContent();
    expect($page)->not->toContain('تلميح-سري-مدفوع')->not->toContain('الحل-السري');

    // بلا رصيد: يُرفض ولا يظهر التلميح.
    $this->actingAs($user)->post(route('puzzles.hint', $puzzle))->assertSessionHas('error')->assertSessionMissing('hint');
    expect(e26_wallet_state($user)[0])->toBe(0);

    // لغز بلا تلميح: يُرفض بلا خصم.
    $user->wallet->update(['available_balance' => 10]);
    $noHint = Puzzle::factory()->create(['hint' => null]);
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($user->fresh())->post(route('puzzles.hint', $noHint))->assertSessionHas('error');
    expect(e26_wallet_state($user)[0])->toBe(10);

    // بالرصيد: يُخصم المبلغ المضبوط بالإعدادات فقط (لا قيمة من العميل).
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($user->fresh())->post(route('puzzles.hint', $puzzle), ['cost' => 0, 'hint_cost' => 0])->assertSessionHas('hint', 'تلميح-سري-مدفوع');
    expect(e26_wallet_state($user)[0])->toBe(10 - $cost);
});

test('P1: API login is dormant-safe - it answers 503 (not a 500 with a stack trace) while the token table does not exist, and never issues a token', function () {
    $this->postJson('/api/auth/login', ['email' => 'x@example.test', 'password' => 'secret-pass', 'device_name' => 'e26'])
        ->assertStatus(503);
});

test('P2: API login refuses frozen and unverified accounts, answers wrong-password and unknown-email identically, expires tokens and caps live tokens', function () {
    e26_sanctum_table();
    $user = e26_user(['email' => 'api.user@example.test']);
    $payload = fn (string $email, string $password = 'password') => ['email' => $email, 'password' => $password, 'device_name' => 'e26-device'];

    // لا تعداد حسابات: نفس الرد للبريد المجهول وكلمة المرور الخاطئة.
    $wrong = $this->postJson('/api/auth/login', $payload($user->email, 'wrong'));
    $unknown = $this->postJson('/api/auth/login', $payload('nobody@example.test', 'wrong'));
    expect($wrong->getStatusCode())->toBe($unknown->getStatusCode())->and($wrong->json('errors.email'))->toBe($unknown->json('errors.email'));

    // مجمَّد / غير موثَّق: لا رمز.
    $frozen = e26_freeze(e26_user(['email' => 'api.frozen@example.test']));
    $this->postJson('/api/auth/login', $payload($frozen->email))->assertForbidden();
    $unverified = e26_user(['email' => 'api.unverified@example.test', 'email_verified_at' => null]);
    $this->postJson('/api/auth/login', $payload($unverified->email))->assertForbidden();
    expect($frozen->tokens()->count() + $unverified->tokens()->count())->toBe(0);

    // نجاح: رمز بتاريخ انتهاء.
    $ok = $this->postJson('/api/auth/login', $payload($user->email))->assertOk();
    expect($ok->json('token'))->toBeString()->and($ok->json('expires_in_minutes'))->toBe(43200);
    $token = $user->tokens()->latest('id')->first();
    expect($token->expires_at)->not->toBeNull()->and($token->expires_at->isFuture())->toBeTrue();

    // سقف الرموز الحيّة: 12 رمزًا قائمة ثم دخول جديد -> لا يزيد عن 10.
    foreach (range(1, 12) as $i) {
        $user->createToken('old-'.$i);
    }
    \Illuminate\Support\Facades\Cache::flush();
    $this->postJson('/api/auth/login', $payload($user->email))->assertOk();
    expect($user->tokens()->count())->toBeLessThanOrEqual(10);
});

test('P3: API login brute force is throttled (6th wrong attempt for the same account+IP gets 429)', function () {
    e26_sanctum_table();
    $user = e26_user(['email' => 'api.brute@example.test']);

    $statuses = e26_hammer($this, 'post', '/api/auth/login', 8, fn ($i) => ['email' => $user->email, 'password' => 'wrong-'.$i, 'device_name' => 'e26'], true);

    expect(e26_first_429($statuses))->toBe(6);
});

test('P4: a token belonging to a frozen or unverified user cannot read the wallet or earn gems; guests get 401; a good token works; logout revokes the token', function () {
    e26_sanctum_table();
    $this->getJson('/api/wallet')->assertUnauthorized();
    $this->postJson('/api/puzzles/999999/attempt', ['answer' => 'x'])->assertUnauthorized();

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'x', 'gem_reward' => 5]);
    $frozen = e26_freeze(e26_user());
    Sanctum::actingAs($frozen);
    $this->getJson('/api/wallet')->assertForbidden();
    $this->postJson('/api/puzzles/'.$puzzle->getKey().'/attempt', ['answer' => 'x'])->assertForbidden();
    expect(PuzzleAttempt::count())->toBe(0);

    $unverified = e26_user(['email_verified_at' => null]);
    Sanctum::actingAs($unverified);
    $this->getJson('/api/wallet')->assertForbidden();
    $this->postJson('/api/puzzles/'.$puzzle->getKey().'/attempt', ['answer' => 'x'])->assertForbidden();
    expect(PuzzleAttempt::count())->toBe(0);

    $good = e26_user();
    $plain = $good->createToken('real')->plainTextToken;
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/wallet')->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/auth/logout')->assertOk();
    expect($good->tokens()->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/wallet')->assertUnauthorized();
});

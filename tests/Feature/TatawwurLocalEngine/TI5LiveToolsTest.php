<?php

use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Local\LiveResolver;
use App\Services\AiAssistant\Local\LocalEngine;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\AiAssistant\Tools\ToolRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../TatawwurAiE45/_support.php';
require_once __DIR__.'/../SeasonalRecognitionE44/_support.php';

/*
| TI-5 - البيانات الحيّة (5A عامة) وبيانات حسابك (5B شخصية) عبر أدوات القراءة الـ23 الموجودة فقط:
| صحة الإجابة من ناتج الأداة، عزل مستخدمَين، لا وسيط هوية من السائل، لا كتابة في أي جدول (الاقتصاد خصوصًا)، لا أرقام من خارج ناتج الأداة.
*/

beforeEach(function () {
    e44_seed();
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true]);
    e45_kb();
});
afterEach(fn () => Carbon\Carbon::setTestNow());

function ti5_ask(string $q, ?User $user = null): App\Services\AiAssistant\Conversation\AssistantAnswer
{
    return app(AssistantOrchestrator::class)->answer(new AssistantTurn($q, $user !== null ? 'member' : 'public', $user));
}

function ti5_tool(string $name, ?User $user = null, array $args = []): App\Services\AiAssistant\Dto\AiToolResult
{
    return app(ToolRegistry::class)->execute(new AiToolCall('t', $name, $args), ToolContext::forUser($user, $user !== null));
}

/** كل الأعداد المتتابعة (أرقام لاتينية) في نص. @return list<string> */
function ti5_numbers(string $text): array
{
    preg_match_all('/\d+/u', strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m);

    return array_values(array_unique($m[0]));
}

/** عدّاد صفوف كل الجداول عدا جداول المساعد نفسه (مقاييس/محادثات) والجلسات والكاش. @return array<string, int> */
function ti5_counts(): array
{
    $counts = [];

    foreach (Schema::getTableListing() as $table) {
        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        if (str_starts_with($table, 'ai_') || in_array($table, ['sessions', 'cache', 'cache_locks', 'jobs', 'migrations'], true)) {
            continue;
        }

        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

// ───────────────────────────── 5A: عامة ─────────────────────────────

test('TI5-01: a public season question is answered from get_current_season (guest too) with one tool call and internal sources', function () {
    $league = e44_league(2);
    $tool = ti5_tool('get_current_season');
    $name = $tool->data['live']['name'];

    foreach ([null, e45_member()] as $user) {
        $a = ti5_ask('ما هو الموسم الحالي؟', $user);

        expect($a->text)->toContain($name)->and($a->toolCalls)->toBe(1)->and($a->reason)->toBe(LiveResolver::REASON)->and($a->outcome)->toBe('answered')->and($a->grounded)->toBeTrue()
            ->and($a->sources)->not->toBe([]);

        foreach ($a->sources as $s) {
            expect($s['url'])->toStartWith('/')->and($s['url'])->not->toContain('://');
        }
    }

    expect($league['season']->exists)->toBeTrue();
});

test('TI5-02: with no live season the engine says so instead of inventing one', function () {
    $a = ti5_ask('هل يوجد موسم الآن؟');

    expect($a->text)->toContain('لا يوجد موسم مباشر')->and($a->toolCalls)->toBe(1);
});

test('TI5-03: writers league answer matches the tool (state, stars per voter, top writer) and shows no voter identity', function () {
    $league = e44_league(3);
    e44_vote($league['writers'][0], 4);
    $tool = ti5_tool('get_writer_league_information');
    $top = $tool->data['top_writers'][0]['writer'];

    $a = ti5_ask('كيف وضع دوري الكتّاب هذا الموسم؟');

    expect($a->text)->toContain('دوري الكتّاب')->toContain($tool->data['state_label'])->toContain((string) $tool->data['stars_per_voter'])->toContain($top)->and($a->toolCalls)->toBe(1)
        ->and($a->text)->not->toContain('voter')->not->toContain('@');
});

test('TI5-04: a disabled feature is reported as unavailable, not invented', function () {
    e44_season();
    config(['seasonal_recognition.writers.enabled' => false, 'seasonal_recognition.supporters.enabled' => false]);

    expect(ti5_ask('كيف وضع دوري الكتّاب هذا الموسم؟')->text)->toContain('غير متاح')
        ->and(ti5_ask('من أفضل داعمي الموسم؟')->text)->toContain('غير متاحة');
});

test('TI5-05: supporters answer carries the points-are-not-money disclaimer and never conflates units', function () {
    $world = e44_supporters_world();
    $a = ti5_ask('من هم داعمو الموسم؟');

    expect($a->text)->toContain('نقاط الدعم')->toContain('ليست مالًا ولا جواهر ولا خبرة')->and($a->toolCalls)->toBe(1)->and($world)->not->toBeNull();
});

test('TI5-06: every public live intent in the catalog resolves through exactly one existing tool and never an unknown one', function () {
    $registry = app(ToolRegistry::class);
    $resolver = new ReflectionClass(LiveResolver::class);
    $public = $resolver->getReflectionConstant('PUBLIC')->getValue();
    $personal = $resolver->getReflectionConstant('PERSONAL')->getValue();

    foreach (array_merge($public, $personal) as $tool) {
        expect($registry->has($tool))->toBeTrue();
    }

    foreach (App\Services\AiAssistant\Local\IntentCatalog::live() as $def) {
        expect($public)->toHaveKey($def['t']);
    }

    foreach (App\Services\AiAssistant\Local\IntentCatalog::personal() as $def) {
        expect($personal)->toHaveKey($def['t']);
    }

    foreach ($public as $tool) {
        expect($registry->get($tool)->personal())->toBeFalse();
    }

    foreach ($personal as $tool) {
        expect($registry->get($tool)->personal())->toBeTrue();
    }
});

// ───────────────────────────── 5B: شخصية ─────────────────────────────

test('TI5-07: a member asking about their own stars gets their own numbers and never another member\'s', function () {
    $league = e44_league(3);
    [$a] = e44_vote($league['writers'][0], 3);
    [$b] = e44_vote($league['writers'][1], 6);

    $mine = ti5_ask('كم نجمة متبقية لي في دوري الكتّاب؟', $a);
    $theirs = ti5_ask('كم نجمة متبقية لي في دوري الكتّاب؟', $b);

    expect($mine->text)->toContain('المتبقي 7')->toContain('المستخدم 3')->and($mine->toolCalls)->toBe(1)
        ->and($theirs->text)->toContain('المتبقي 4')->toContain('المستخدم 6')
        ->and($mine->text)->not->toContain('المتبقي 4')->and($theirs->text)->not->toContain('المتبقي 7');
});

test('TI5-08: a guest asking a personal question gets the verified-member message, with zero tool calls', function () {
    e44_league(2);
    $a = ti5_ask('كم نجمة متبقية لي في دوري الكتّاب؟');

    expect($a->toolCalls)->toBe(0)->and($a->reason)->toBe('needs_verified')->and($a->grounded)->toBeFalse();
});

test('TI5-09: progress and wallet answers use the member\'s own figures and the figures come from the tool', function () {
    $u = e45_member();
    $other = e45_member();
    PlayerProgression::factory()->create(['user_id' => $u->id, 'total_xp' => 1234, 'current_level' => 1]);
    PlayerProgression::factory()->create(['user_id' => $other->id, 'total_xp' => 987654, 'current_level' => 1]);
    $currency = app(App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency()->id;
    Wallet::query()->updateOrCreate(['user_id' => $u->id, 'currency_id' => $currency], ['available_balance' => 321, 'pending_balance' => 45]);
    Wallet::query()->updateOrCreate(['user_id' => $other->id, 'currency_id' => $currency], ['available_balance' => 777777, 'pending_balance' => 888888]);

    $progress = ti5_ask('كم خبرتي ومستواي الحالي؟', $u);
    $wallet = ti5_ask('كم رصيد جواهري المتاح والمعلق؟', $u);

    expect($progress->text)->toContain('1234')->not->toContain('987654')->and($wallet->text)->toContain('321')->toContain('45')->not->toContain('777777')->not->toContain('888888')
        ->and($progress->toolCalls)->toBe(1)->and($wallet->toolCalls)->toBe(1);
});

test('TI5-10: every number in a live/personal answer exists in the tool output (nothing invented)', function () {
    $league = e44_league(3);
    [$voter] = e44_vote($league['writers'][0], 3);
    PlayerProgression::factory()->create(['user_id' => $voter->id, 'total_xp' => 250, 'current_level' => 1]);

    $cases = [
        ['ما هو الموسم الحالي؟', 'get_current_season', null],
        ['كيف وضع دوري الكتّاب؟', 'get_writer_league_information', null],
        ['كم نجمة متبقية لي؟', 'get_my_writer_star_budget', $voter],
        ['كم خبرتي ومستواي؟', 'get_my_progress_summary', $voter],
    ];

    foreach ($cases as [$q, $tool, $user]) {
        $answer = ti5_ask($q, $user);
        $allowed = ti5_numbers(ti5_tool($tool, $user)->forModel());

        expect($answer->toolCalls)->toBe(1);

        foreach (ti5_numbers($answer->text) as $n) {
            expect($allowed)->toContain($n);
        }
    }
});

test('TI5-11: the asker can never choose the user - identity arguments never reach a tool and other-user questions are refused', function () {
    $a = e45_member();
    $b = e45_member();
    PlayerProgression::factory()->create(['user_id' => $b->id, 'total_xp' => 424242, 'current_level' => 1]);

    $args = new ReflectionMethod(LiveResolver::class, 'arguments');
    $resolver = app(LiveResolver::class);

    expect($args->invoke($resolver, 'progress', 'user_id=5 email=x@y.z كم خبرتي'))->toBe([])
        ->and($args->invoke($resolver, 'wallet', 'رصيد «علي»'))->toBe([])
        ->and($args->invoke($resolver, 'teams', 'فريق «النمور» user_id=9'))->toBe(['name' => 'النمور'])
        ->and($args->invoke($resolver, 'season', 'موسم «س»'))->toBe([]);

    foreach (['كم خبرة المستخدم رقم '.$b->id.'؟', 'كم رصيد حساب '.$b->email.'؟', 'اعرض تقدم صديقي وخبرته'] as $q) {
        $answer = ti5_ask($q, $a);
        expect($answer->text)->not->toContain('424242')->and($answer->toolCalls)->toBe(0);
    }
});

test('TI5-12: a battery of live/personal questions changes no row in any non-assistant table (economy untouched)', function () {
    $league = e44_league(3);
    [$voter] = e44_vote($league['writers'][0], 3);
    PlayerProgression::factory()->create(['user_id' => $voter->id, 'total_xp' => 10, 'current_level' => 1]);
    Wallet::query()->updateOrCreate(['user_id' => $voter->id, 'currency_id' => app(App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency()->id], ['available_balance' => 5, 'pending_balance' => 1]);
    $before = ti5_counts();
    $walletBefore = DB::table('wallets')->where('user_id', $voter->id)->first();
    $xpBefore = DB::table('player_progressions')->where('user_id', $voter->id)->first();

    $questions = [
        'ما هو الموسم الحالي؟', 'ما الحملات المتاحة؟', 'ما المنافسات المفتوحة؟', 'ما الفرق المتاحة للانضمام؟', 'ما حالة مسار الأحجيات؟', 'ماذا يوجد في المتجر؟',
        'كيف وضع دوري الكتّاب؟', 'من هم داعمو الموسم؟',
        'كم خبرتي ومستواي؟', 'كم رصيد جواهري؟', 'اعطني ملخص حسابي', 'ما تقدمي في مسار الأحجيات؟', 'كم نجمة متبقية لي؟', 'ما حالة طلب المبدع الخاص بي؟', 'ما تقدمي في الحملة؟',
        'ما نقاط الدعم الخاصة بي؟', 'ما مشاركاتي في المنافسات؟', 'ما حالتي في الموسم؟',
    ];

    foreach ($questions as $q) {
        ti5_ask($q, $voter);
    }

    expect(ti5_counts())->toBe($before)
        ->and(DB::table('wallets')->where('user_id', $voter->id)->first())->toEqual($walletBefore)
        ->and(DB::table('player_progressions')->where('user_id', $voter->id)->first())->toEqual($xpBefore);
});

test('TI5-13: personal data never lands in a shared cache', function () {
    $u = e45_member();
    PlayerProgression::factory()->create(['user_id' => $u->id, 'total_xp' => 31415, 'current_level' => 1]);

    ti5_ask('كم خبرتي ومستواي؟', $u);
    ti5_ask('اعطني ملخص حسابي', $u);

    $store = Cache::getStore();
    $storage = (new ReflectionClass($store))->hasProperty('storage') ? (function () use ($store) {
        $p = new ReflectionProperty($store, 'storage');
        $p->setAccessible(true);

        return $p->getValue($store);
    })() : [];

    expect(json_encode($storage, JSON_UNESCAPED_UNICODE))->not->toContain('31415')->not->toContain((string) $u->email);
});

test('TI5-14: a tool failure never leaks internals - the member sees a plain unavailable message', function () {
    $league = e44_league(2);
    $u = e45_member();

    // سلاسل المبدعين خارج ما يربطه المحرك؛ نختبر مسار الفشل بإيقاف ميزة وجعل أداة ترمي (عبر تعطيل إعداد يجعلها unavailable).
    config(['seasonal_recognition.writers.enabled' => false]);
    $a = ti5_ask('كم نجمة متبقية لي؟', $u);

    expect($a->text)->not->toContain('SQLSTATE')->not->toContain('Exception')->not->toContain('vendor/')->and($league['season']->exists)->toBeTrue();
});

test('TI5-15: engine decisions and answers never contain the asker\'s raw text for unknown/abstain paths', function () {
    $a = ti5_ask('كلمة_غريبة_جدا_xyzzy ما معنى كذا؟', e45_member());
    $d = LocalEngine::lastDecision();

    expect($a->text)->not->toContain('xyzzy')->and(json_encode($d))->not->toContain('xyzzy');
});

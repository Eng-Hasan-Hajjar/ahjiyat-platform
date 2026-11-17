<?php

use App\Models\CurrencyTransaction;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Economy\Integrity\WalletAuditCase;
use App\Services\Economy\Integrity\WalletIntegrityAuditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../DatabaseE31/_support.php';

/*
| E46 - تزامن حقيقي على MySQL: عمليات PHP مستقلة (proc_open) لكل منها جلسة MySQL ومعاملة وقفل. تتخطى بوضوح على SQLite (e31_requires_mysql)
| ولا تُحتسب دليلًا إلا إن شُغِّلت فعلًا على MySQL:
|     php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/GemIntegrityE46/ConcurrencyMysqlTest.php
|
| العزل: تُنشأ قاعدة مؤقتة منفصلة اسمها <قاعدة الاختبار>_e46conc_test (تُرحَّل ثم تُحذف في النهاية) كي تُثبَّت البيانات (commit) وتراها العمليات الأخرى،
| دون لمس معاملة RefreshDatabase ولا بيانات بقية الاختبارات. يحتاج مستخدم الاختبار صلاحية CREATE/DROP DATABASE على هذا النمط (كما E31). لا تُلمس قاعدة العمل أبدًا.
|
| نتائج «FINDING» هنا تثبّت السلوك الحالي المعيب (لا إصلاح): انظر docs/gem-integrity-e46-findings.md.
*/

afterAll(function () {
    e46_conc_drop();
});

/** @var array<string, mixed>|null $GLOBALS['e46_conc'] */
function e46_conc_state(): ?array
{
    return $GLOBALS['e46_conc'] ?? null;
}

/** اسم القاعدة المؤقتة المشتق من قاعدة الاختبار، أو '' إن لم يكن آمنًا. */
function e46_conc_database(string $main): string
{
    $name = preg_replace('/(_test)?(_mysql|_mariadb)?$/i', '', $main).'_e46conc_test';

    return preg_match('/^[A-Za-z0-9_]{1,60}_e46conc_test$/', $name) === 1 && $name !== $main && TestDatabaseGuard::isAllowed('mysql', $name) ? $name : '';
}

/**
 * يجهّز القاعدة المؤقتة مرة واحدة لكل عملية اختبار (إنشاء + migrate) ويحوّل الاتصال الافتراضي إليها، ثم يُعيده e46_conc_leave().
 * البيانات المُنشأة بعدها تُثبَّت فعلًا (لا معاملة RefreshDatabase عليها).
 */
function e46_conc_enter(): array
{
    e31_requires_mysql();

    $original = config('database.default');
    $base = (array) config('database.connections.'.$original);
    $database = e46_conc_database((string) $base['database']);
    expect($database)->not->toBe('', 'تعذّر اشتقاق اسم آمن للقاعدة المؤقتة');

    // كل اختبار يبني تطبيقًا جديدًا (الإعدادات لا تبقى)، فنعيد تسجيل الاتصالين دائمًا؛ أما إنشاء القاعدة والترحيل فمرة واحدة لكل عملية.
    config([
        'database.connections.e46_conc_admin' => array_merge($base, ['database' => null, 'url' => null]),
        'database.connections.e46_conc' => array_merge($base, ['database' => $database, 'url' => null]),
    ]);
    DB::purge('e46_conc_admin');
    DB::purge('e46_conc');

    if (e46_conc_state() === null) {
        $admin = DB::connection('e46_conc_admin');
        $admin->statement("DROP DATABASE IF EXISTS `{$database}`");
        $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $GLOBALS['e46_conc'] = ['database' => $database, 'base' => $base, 'original' => $original];

        expect(Artisan::call('migrate', ['--database' => 'e46_conc', '--force' => true]))->toBe(0);
    }

    DB::setDefaultConnection('e46_conc');
    config(['database.default' => 'e46_conc']);
    Cache::flush();
    app()->forgetInstance(CurrencyRegistry::class);

    return e46_conc_state();
}

function e46_conc_leave(): void
{
    if ($state = e46_conc_state()) {
        DB::setDefaultConnection($state['original']);
        config(['database.default' => $state['original']]);
        app()->forgetInstance(CurrencyRegistry::class);
    }
}

function e46_conc_drop(): void
{
    $state = e46_conc_state();

    if ($state === null || $state['database'] === '' || ! TestDatabaseGuard::isAllowed('mysql', $state['database'])) {
        return;
    }

    $b = $state['base'];

    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s', $b['host'], $b['port']), $b['username'], $b['password'] ?? '');
        $pdo->exec('DROP DATABASE IF EXISTS `'.$state['database'].'`');
    } catch (Throwable) {
        // best-effort
    }

    unset($GLOBALS['e46_conc']);
}

/**
 * يشغّل عاملًا مستقلًا. start_at: لحظة بدء موحَّدة (بعد إقلاع كل العمال) فيبدأ الجميع الفعل نفسه في آنٍ واحد تقريبًا.
 *
 * @return array{proc: resource, pipes: array, args: array}
 */
function e46_spawn(array $args): array
{
    $state = e46_conc_state();
    $b = $state['base'];
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $b['host'], 'DB_PORT' => (string) $b['port'],
        'DB_DATABASE' => $state['database'], 'DB_USERNAME' => (string) $b['username'], 'DB_PASSWORD' => (string) ($b['password'] ?? ''),
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
    ]);
    $proc = proc_open(
        [PHP_BINARY, __DIR__.'/_concurrency_worker.php', json_encode($args)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env,
    );

    expect(is_resource($proc))->toBeTrue();

    return ['proc' => $proc, 'pipes' => $pipes, 'args' => $args];
}

/** ينتظر العامل وبرجع نتيجته المفكوكة (آخر سطر JSON). يفشل الاختبار برسالة واضحة إن لم يُنتج نتيجة. */
function e46_join(array $worker): array
{
    $out = stream_get_contents($worker['pipes'][1]);
    $err = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['proc']);

    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray('worker produced no JSON result. stdout='.mb_substr((string) $out, -400).' stderr='.mb_substr((string) $err, -400));

    return $decoded;
}

/** يشغّل عاملَين (أو أكثر) على لحظة بدء موحَّدة ويعيد نتائجهم. */
function e46_race(array $argsList, float $warmup = 5.0): array
{
    $startAt = microtime(true) + $warmup;
    $workers = array_map(fn ($a) => e46_spawn($a + ['start_at' => $startAt]), $argsList);

    return array_map('e46_join', $workers);
}

function e46_fresh_wallet_user(int $available = 0, int $pending = 0, $currency = null): User
{
    $user = User::factory()->create();
    $currency ??= e46_gem();

    if ($available > 0) {
        app(CurrencyWalletService::class)->creditAvailable($user, $currency, $available, 'e46_conc_fund');
    }

    if ($pending > 0) {
        app(CurrencyWalletService::class)->creditPending($user, $currency, $pending, 'e46_conc_fund_pending');
    }

    return $user;
}

// ───────────────────────────── الأقفال: ما يجب أن يبقى سليمًا تحت التزامن ─────────────────────────────

test('E46-100: two processes debiting the same wallet at the same instant - exactly one succeeds, the balance never goes negative, the audit matches', function () {
    e46_conc_enter();

    try {
        foreach (range(1, 3) as $round) {
            $user = e46_fresh_wallet_user(available: 100);
            $args = ['action' => 'debit', 'user_id' => $user->id, 'currency_id' => e46_gem()->id, 'amount' => 100];

            $results = e46_race([$args, $args]);
            $ok = count(array_filter($results, fn ($r) => $r['ok']));

            expect($ok)->toBe(1, "round {$round}: ".json_encode($results, JSON_UNESCAPED_UNICODE))
                ->and(e46_wallet($user, e46_gem())->available_balance)->toBe(0)
                ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_REDEEM)->count() + CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_SPEND)->count())->toBe(1);

            e46_age_everything();
            expect(e46_case($user, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
        }
    } finally {
        e46_conc_leave();
    }
});

test('E46-101: two processes releasing the same pending amount at the same instant release it once, and two concurrent scheduled jobs release the hold exactly once', function () {
    e46_conc_enter();

    try {
        $user = e46_fresh_wallet_user(pending: 50);
        $args = ['action' => 'release', 'user_id' => $user->id, 'currency_id' => e46_gem()->id, 'amount' => 50];
        $results = e46_race([$args, $args]);

        $wallet = e46_wallet($user, e46_gem());
        expect(array_sum(array_map(fn ($r) => (int) ($r['released'] ?? 0), $results)))->toBe(50)
            ->and($wallet->pending_balance)->toBe(0)->and($wallet->available_balance)->toBe(50)
            ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)->count())->toBe(1);

        // المهمة المجدولة مرتين معًا: الاستحقاق نفسه يُحسب في كل عملية قبل أن تحرّر أيٌّ منهما، والتحرير مقيَّد بالمعلّق الفعلي فيُنفَّذ مرة واحدة
        $second = e46_fresh_wallet_user(pending: 40);
        DB::table('currency_transactions')->where('user_id', $second->id)->update(['created_at' => now()->subDays((int) config('gems.pending_hold_days') + 3)]);
        $jobs = e46_race([['action' => 'release_job'], ['action' => 'release_job']]);

        $after = e46_wallet($second, e46_gem());
        expect(collect($jobs)->every(fn ($j) => $j['ok']))->toBeTrue(json_encode($jobs))
            ->and($after->pending_balance)->toBe(0)->and($after->available_balance)->toBe(40)
            ->and(CurrencyTransaction::where('user_id', $second->id)->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)->count())->toBe(1);

        e46_age_everything();
        expect(e46_case($user, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
            ->and(e46_case($second, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
    } finally {
        e46_conc_leave();
    }
});

// ───────────────────────────── FINDING-F1: السباق على حالة طلب الاستبدال ─────────────────────────────

test('E46-102 FINDING-F1e (current behaviour): two admins rejecting the same pending request at once BOTH pass the status check and the user is refunded twice', function () {
    e46_conc_enter();

    try {
        $request = e46_request(e46_eligible_user());
        $user = User::find($request->user_id);
        $admin = User::factory()->create();
        $dir = sys_get_temp_dir().'/e46-barrier-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $base = ['action' => 'race_redemption', 'op' => 'reject', 'request_id' => $request->id, 'admin_id' => $admin->id, 'barrier_dir' => $dir];
        $results = e46_race([$base + ['id' => 'a', 'other' => 'b'], $base + ['id' => 'b', 'other' => 'a']]);
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);

        $min = (int) config('gems.min_redemption');
        $refunds = CurrencyTransaction::where('user_id', $user->id)->where('reason', 'like', 'redemption_rejected:%')->count();

        expect(collect($results)->every(fn ($r) => $r['passed_check'] && $r['other_checked'] && $r['ok']))->toBeTrue(json_encode($results, JSON_UNESCAPED_UNICODE))
            ->and($refunds)->toBe(2)
            ->and(e46_wallet($user, e46_gem())->available_balance)->toBe(2 * $min)                       // ضعف ما خُصم
            ->and(app(\App\Services\Economy\Integrity\RedemptionCommitments::class)->summary()['footprints']['duplicate_refunds'])->toBe(1);

        e46_age_everything();
        expect(e46_case($user, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);              // المحفظة والدفتر متطابقان: لا يكشفه تدقيق الأرصدة
    } finally {
        e46_conc_leave();
    }
});

test('E46-103 FINDING-F1f (current behaviour): two admins marking the same approved request as fulfilled at once both pass the check and lifetime_redeemed is added twice', function () {
    e46_conc_enter();

    try {
        $request = e46_request(e46_eligible_user());
        $admin = User::factory()->create();
        app(\App\Services\RedemptionService::class)->approve($request, $admin);
        $dir = sys_get_temp_dir().'/e46-barrier-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $base = ['action' => 'race_redemption', 'op' => 'fulfill', 'request_id' => $request->id, 'admin_id' => $admin->id, 'barrier_dir' => $dir];
        $results = e46_race([$base + ['id' => 'a', 'other' => 'b'], $base + ['id' => 'b', 'other' => 'a']]);
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);

        expect(collect($results)->every(fn ($r) => $r['passed_check'] && $r['ok']))->toBeTrue(json_encode($results, JSON_UNESCAPED_UNICODE))
            ->and(e46_wallet(User::find($request->user_id), e46_gem())->lifetime_redeemed)->toBe(2 * (int) config('gems.min_redemption'));
    } finally {
        e46_conc_leave();
    }
});

// ───────────────────────────── التدقيق أثناء الحركة: لا إنذارات كاذبة ─────────────────────────────

test('E46-104: a write committed by another session in the middle of an audit batch is NOT seen by that batch (snapshot), so it can never become a false "proven difference"', function () {
    e46_conc_enter();

    try {
        $user = e46_fresh_wallet_user(pending: 30);
        e46_age_everything();
        $gem = e46_gem();

        // الحركة الخارجية: ربح معلَّق جديد يُثبَّت (محفظة + دفتر في معاملة واحدة) بجلسة مستقلة، في منتصف دفعة التدقيق (بين قراءة المحافظ وقراءة التجميعات)
        $injectWrite = function () use ($user, $gem) {
            config(['database.connections.e46_conc_writer' => config('database.connections.e46_conc')]);
            DB::purge('e46_conc_writer');
            $writer = DB::connection('e46_conc_writer');
            $writer->transaction(function () use ($writer, $user, $gem) {
                $writer->table('wallets')->where('user_id', $user->id)->where('currency_id', $gem->id)
                    ->update(['pending_balance' => DB::raw('pending_balance + 10'), 'lifetime_earned' => DB::raw('lifetime_earned + 10'), 'updated_at' => now()]);
                $writer->table('currency_transactions')->insert([
                    'user_id' => $user->id, 'currency_id' => $gem->id, 'amount' => 10, 'type' => 'earn_pending', 'reason' => 'e46_conc_mid_batch',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            DB::purge('e46_conc_writer');
        };

        $auditor = new class($injectWrite) extends WalletIntegrityAuditor
        {
            public bool $fired = false;

            public function __construct(private Closure $inject) {}

            protected function aggregates(array $userIds, array $currencyIds): array
            {
                if (! $this->fired) {
                    $this->fired = true;
                    ($this->inject)();
                }

                return parent::aggregates($userIds, $currencyIds);
            }
        };

        // 1) الإعداد الافتراضي للخادم
        $case = $auditor->auditPair($user->id, $gem->id, e46_scope($user->id, [$gem->id]));
        expect($auditor->fired)->toBeTrue()
            ->and($case->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
            ->and([$case->storedPending, $case->derivedPending])->toBe([30, 30]);          // لقطة قبل الكتابة: لا ترى منها شيئًا

        // 2) ضبط جلسة التدقيق على READ COMMITTED (كخادم بإعداد مختلف): الأمر الصريح REPEATABLE READ للمعاملة يحافظ على الضمان
        $second = e46_fresh_wallet_user(pending: 30);
        e46_age_everything();
        $injectSecond = function () use ($second, $gem) {
            config(['database.connections.e46_conc_writer' => config('database.connections.e46_conc')]);
            DB::purge('e46_conc_writer');
            $writer = DB::connection('e46_conc_writer');
            $writer->transaction(function () use ($writer, $second, $gem) {
                $writer->table('wallets')->where('user_id', $second->id)->where('currency_id', $gem->id)
                    ->update(['pending_balance' => DB::raw('pending_balance + 10'), 'lifetime_earned' => DB::raw('lifetime_earned + 10'), 'updated_at' => now()]);
                $writer->table('currency_transactions')->insert([
                    'user_id' => $second->id, 'currency_id' => $gem->id, 'amount' => 10, 'type' => 'earn_pending', 'reason' => 'e46_conc_mid_batch',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            DB::purge('e46_conc_writer');
        };
        $rc = new class($injectSecond) extends WalletIntegrityAuditor
        {
            public bool $fired = false;

            public function __construct(private Closure $inject) {}

            protected function aggregates(array $userIds, array $currencyIds): array
            {
                if (! $this->fired) {
                    $this->fired = true;
                    ($this->inject)();
                }

                return parent::aggregates($userIds, $currencyIds);
            }
        };

        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        // اسم المتغير transaction_isolation (MySQL 8 / MariaDB 11.1+) أو tx_isolation (أقدم)
        $isolation = fn () => (string) (DB::select("show session variables where Variable_name in ('transaction_isolation','tx_isolation')")[0]->Value ?? '');
        expect($isolation())->toBe('READ-COMMITTED');
        $case2 = $rc->auditPair($second->id, $gem->id, e46_scope($second->id, [$gem->id]));
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        expect($rc->fired)->toBeTrue()
            ->and($case2->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
            ->and([$case2->storedPending, $case2->derivedPending])->toBe([30, 30]);

        // 3) الشاهد (negative control): قراءة «ساذجة» بلا لقطة، بنفس الكتابة الخارجية في المنتصف، تُنتج فرقًا كاذبًا - فالاختبار قادر على كشف غياب اللقطة
        $third = e46_fresh_wallet_user(pending: 30);
        e46_age_everything();
        $wallet = DB::table('wallets')->where('user_id', $third->id)->where('currency_id', $gem->id)->first();
        config(['database.connections.e46_conc_writer' => config('database.connections.e46_conc')]);
        DB::purge('e46_conc_writer');
        $writer = DB::connection('e46_conc_writer');
        $writer->transaction(function () use ($writer, $third, $gem) {
            $writer->table('wallets')->where('user_id', $third->id)->update(['pending_balance' => DB::raw('pending_balance + 10'), 'lifetime_earned' => DB::raw('lifetime_earned + 10')]);
            $writer->table('currency_transactions')->insert(['user_id' => $third->id, 'currency_id' => $gem->id, 'amount' => 10, 'type' => 'earn_pending', 'reason' => 'e46_conc_mid_batch', 'created_at' => now(), 'updated_at' => now()]);
        });
        DB::purge('e46_conc_writer');
        $naiveDerived = (int) DB::table('currency_transactions')->where('user_id', $third->id)->where('type', 'earn_pending')->sum('amount')
            - (int) DB::table('currency_transactions')->where('user_id', $third->id)->where('type', 'release_available')->sum('amount');

        expect((int) $wallet->pending_balance)->toBe(30)->and($naiveDerived)->toBe(40);        // 30 ≠ 40 ⇒ إنذار كاذب لو لم تكن هناك لقطة
    } finally {
        e46_conc_leave();
    }
});

test('E46-105: while real services churn the same wallets in another process, repeated audits with ZERO grace never report a proven difference', function () {
    e46_conc_enter();

    try {
        $gem = e46_gem();
        $users = array_map(fn () => e46_fresh_wallet_user(available: 200, pending: 100), range(1, 6));
        e46_age_everything();
        $ledgerBefore = DB::table('currency_transactions')->count();

        $duration = 6.0;
        $startAt = microtime(true) + 5.0;
        $workers = array_map(fn ($seed) => e46_spawn([
            'action' => 'churn', 'user_ids' => array_map(fn ($u) => $u->id, $users), 'currency_id' => $gem->id,
            'duration' => $duration, 'seed' => $seed, 'start_at' => $startAt,
        ]), [11, 22]);

        $scans = 0;
        $proven = [];
        $undetermined = 0;
        $ledgerDuring = $ledgerBefore;
        $userIds = array_map(fn ($u) => $u->id, $users);

        while (microtime(true) < $startAt + $duration + 0.5) {
            $summary = app(WalletIntegrityAuditor::class)->audit(e46_scope(null, [$gem->id], chunk: 3, grace: 0));
            $scans++;
            $proven[] = $summary->provenDifferences;
            $undetermined += $summary->undetermined;

            if (microtime(true) > $startAt + 0.5) {
                $ledgerDuring = max($ledgerDuring, DB::table('currency_transactions')->count());
            }
        }

        $results = array_map('e46_join', $workers);
        $ops = array_sum(array_map(fn ($r) => (int) ($r['ops'] ?? 0), $results));

        // بعد توقف الحركة: كل شيء مطابق تمامًا
        sleep(1);
        $final = app(WalletIntegrityAuditor::class)->audit(e46_scope(null, [$gem->id], chunk: 3, grace: 0));

        expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results))
            ->and($ops)->toBeGreaterThan(50)                                   // كانت هناك حركة حقيقية
            ->and($ledgerDuring)->toBeGreaterThan($ledgerBefore)                // والدفتر تغيّر فعلًا أثناء المسح
            ->and($scans)->toBeGreaterThan(2)
            ->and(array_sum($proven))->toBe(0, 'false alarms during churn: scans with a proven difference = '.count(array_filter($proven)).' of '.count($proven))
            ->and($final->provenDifferences)->toBe(0)->and($final->undetermined)->toBe(0)->and($final->matched)->toBe($final->scanned);

        fwrite(STDERR, sprintf("\n[E46-105] scans=%d ops=%d ledger %d→%d undetermined_during=%d final_scanned=%d\n", $scans, $ops, $ledgerBefore, $ledgerDuring, $undetermined, $final->scanned));
    } finally {
        e46_conc_leave();
    }
});

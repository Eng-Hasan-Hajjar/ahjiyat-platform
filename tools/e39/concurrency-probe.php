<?php

/*
|--------------------------------------------------------------------------
| E39 - مسبار التزامن الحقيقي على MySQL (عمليتا PHP منفصلتان تتسابقان فعلًا)
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، وقفل الصفوف (lockForUpdate) لا أثر له على SQLite، فلا سباق حقيقي بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليتين تبدآن في اللحظة نفسها:
|   J1  طلب دفعة مزدوج لمبدع واحد             ← طلب واحد فقط، حجز واحد، بلا إنفاق مزدوج
|   J2  إقفال مزدوج للفترة نفسها              ← مجموعة تخصيصات واحدة وقيد واحد لكل مبدع، المجمّع لا يُوزَّع مرتين
|   J3  صرف مزدوج (بالمرجع نفسه وبمرجعين) وصرف دفعتين بمرجع واحد ← صرف واحد وقيد واحد، والمرجع الخارجي فريد
|   J4  اعتماد ↔ رفض لدفعة واحدة              ← نتيجة واحدة متسقة مع الدفتر (فكّ الحجز مرة واحدة على الأكثر)
|   J5  إلغاء المبدع ↔ اعتماد المسؤول         ← أحدهما يفوز؛ لا إلغاء لدفعة معتمدة ولا اعتماد لملغاة
|   J6  استثناء مُحقَن وسط الطلب والإقفال      ← تراجع كامل: لا دفعة ولا قيد ولا تخصيص، ثم ينجح التنفيذ النظيف لاحقًا
|   J7  تفعيل التسييل ↔ إقفال فترة لمبدع واحد ← بلا deadlock (ترتيب الأقفال حساب ثم ملف)
|   R   المصالحة المالية بعد السباقات = لا فروق (اللقطة = الدفتر، المحجوز = الدفعات المفتوحة، المدفوع = المصروفة، التخصيصات = القيود)
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/creator-finance-operations-runbook.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e39/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e39/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 5)
*/

use App\Models\CreatorEarningAccount;
use App\Models\CreatorPayout;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorRevenuePeriod;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorFinanceReconciler;
use App\Services\CreatorFinance\CreatorLedgerService;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorFinance\CreatorPayoutService;
use App\Services\CreatorFinance\CreatorRevenuePeriodService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env (جداول الكاش تُحذف أثناء migrate:fresh)، وتُورَّث للعمليات العاملة. حدود التأهل مخفَّضة لتبسيط البيانات.
foreach (['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'CREATOR_MONETIZATION_MIN_UNITS' => '1', 'CREATOR_MONETIZATION_MIN_PLAYERS' => '1', 'CREATOR_MONETIZATION_FINALIZE_DELAY_HOURS' => '1'] as $envKey => $envValue) {
    putenv("{$envKey}={$envValue}");
    $_ENV[$envKey] = $_SERVER[$envKey] = $envValue;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $args[$key] = $value;
    }
}

// ───────────────────────────── عامل (عملية السباق) ─────────────────────────────
if (isset($args['worker'])) {
    exit(probe_worker($args));
}

// ───────────────────────────── الحواجز ─────────────────────────────
function probe_guard(): ?string
{
    if (app()->environment('production')) {
        return 'مرفوض: APP_ENV=production.';
    }

    $connection = (string) config('database.default');
    $driver = (string) config("database.connections.{$connection}.driver");
    $database = (string) config("database.connections.{$connection}.database");

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        return "مرفوض: هذا المسبار لـMySQL/MariaDB فقط (السائق الحالي: {$driver}). اضبط DB_CONNECTION=mysql.";
    }

    if (preg_match('/(^|_)(test|testing)(_(mysql|mariadb))?$/', $database) !== 1 || preg_match('/^[A-Za-z0-9_]{1,50}$/', $database) !== 1) {
        return 'مرفوض: اسم القاعدة لا ينتهي بـ _test أو _testing أو _test_mysql. هذا السكربت يمسح كل جداول القاعدة (migrate:fresh) فلا يُوجَّه لقاعدة العمل.';
    }

    return null;
}

/**
 * يطلق عمليات PHP منفصلة (واحدة لكل مواصفة) تنتظر اللحظة نفسها ثم تنفّذ معًا.
 *
 * @param  list<array{0: string, 1: list<string>}>  $specs  [kind, extra args]
 * @return list<array<string, mixed>>
 */
function probe_race(array $specs, float $leadSeconds = 3.0): array
{
    $at = microtime(true) + $leadSeconds;
    $procs = [];

    foreach ($specs as $i => [$kind, $extra]) {
        $cmd = array_merge([PHP_BINARY, __FILE__, "--worker={$kind}", "--at={$at}"], $extra);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null);
        $procs[$i] = [$process, $pipes];
    }

    $out = [];

    foreach ($procs as $i => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $line = '';
        foreach (explode("\n", (string) $stdout) as $candidate) {
            if (str_starts_with(trim($candidate), '{')) {
                $line = trim($candidate);
            }
        }
        $decoded = $line !== '' ? json_decode($line, true) : null;
        $out[$i] = is_array($decoded) ? $decoded : ['outcome' => 'worker_crash', 'detail' => substr(trim((string) $stderr), 0, 160)];
    }

    return $out;
}

function probe_worker(array $args): int
{
    $kind = (string) $args['worker'];
    $at = (float) ($args['at'] ?? 0);
    $result = ['outcome' => 'unknown'];

    try {
        $actor = User::query()->findOrFail((int) ($args['actor'] ?? 0));

        while (microtime(true) < $at) {
            usleep(200);
        }

        $started = microtime(true);

        if (in_array($kind, ['request-fail', 'finalize-fail'], true)) {
            // يحقن استثناءً في آخر خطوة داخل المعاملة (التدقيق) فيجب أن يتراجع كل ما قبله.
            $failOn = $kind === 'request-fail' ? 'creator_payout_requested' : 'creator_revenue_period_finalized';
            app()->bind(\App\Services\OperationalAuditService::class, fn () => new class($failOn) extends \App\Services\OperationalAuditService
            {
                public function __construct(protected string $failOn) {}

                public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
                {
                    if ($action === $this->failOn) {
                        throw new RuntimeException('probe-injected-failure');
                    }

                    parent::log($action, $subject, $metadata, $actor);
                }
            });
        }

        $payouts = fn () => app(CreatorPayoutService::class);
        $payout = isset($args['payout']) ? CreatorPayout::query()->findOrFail((int) $args['payout']) : null;

        if ($kind === 'request' || $kind === 'request-fail') {
            $after = $payouts()->request($actor, (string) ($args['amount'] ?? '100.00'), 'bank_transfer');
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'cancel') {
            $result = ['outcome' => 'ok', 'status' => $payouts()->cancel($actor, $payout)->status];
        } elseif ($kind === 'approve') {
            $result = ['outcome' => 'ok', 'status' => $payouts()->approve($payout, $actor)->status];
        } elseif ($kind === 'reject') {
            $result = ['outcome' => 'ok', 'status' => $payouts()->reject($payout, $actor, 'رفض من مسبار التزامن')->status];
        } elseif ($kind === 'settle') {
            $result = ['outcome' => 'ok', 'status' => $payouts()->settle($payout, $actor, (string) $args['ref'])->status];
        } elseif ($kind === 'finalize' || $kind === 'finalize-fail') {
            $period = CreatorRevenuePeriod::query()->findOrFail((int) $args['period']);
            $result = ['outcome' => 'ok', 'status' => app(CreatorRevenuePeriodService::class)->finalize($period, $actor)->status];
        } elseif ($kind === 'enable') {
            $profile = CreatorProfile::query()->findOrFail((int) $args['profile']);
            $result = ['outcome' => 'ok', 'status' => app(CreatorMonetizationService::class)->enable($profile, $actor)->monetization_status];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof CreatorFinanceException;
        // لا نطبع رسائل SQL (قد تحمل قيم): اسم الفهرس المخالَف فقط لأخطاء 1062، ورسالة الاستثناء للاستثناءات الدومينية (نصوص عربية آمنة).
        $detail = '';
        if ($code === 1062 && preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $detail = $m[1];
        } elseif ($domain) {
            $detail = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120);
        }
        $result = ['outcome' => $domain ? 'refused' : 'exception', 'class' => class_basename($e), 'mysql' => $code, 'detail' => $detail];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE)."\n";

    return 0;
}

// ───────────────────────────── المسبار الرئيسي ─────────────────────────────
if (($error = probe_guard()) !== null) {
    fwrite(STDERR, $error."\n");
    exit(2);
}

$rounds = max(2, min(20, (int) ($args['rounds'] ?? 5)));
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ').$name.($detail !== '' ? '  - '.$detail : '')."\n";
};

echo '== E39 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh)...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);

$verified = function (User $u): User {
    $u->forceFill(['email_verified_at' => now()])->save();

    return $u->fresh();
};
$admin = fn (string $tag) => (function () use ($verified, $tag) {
    $u = $verified(User::factory()->create(['email' => "probe-{$tag}@finance-concurrency.test"]));
    $u->syncRoles(['administrator']);

    return $u->fresh();
})();

$finance = $admin('finance');
$finance2 = $admin('finance2');
$reviewer = $verified(User::factory()->create(['email' => 'probe-reviewer@finance-concurrency.test']));
$reviewer->syncRoles(['moderator']);

$category = PuzzleCategory::query()->create(['name' => 'مسبار', 'slug' => 'probe', 'sort_order' => 1, 'is_active' => true]);
$applications = app(CreatorApplicationService::class);
$puzzles = app(CreatorPuzzleService::class);
$review = app(CreatorReviewService::class);
$monetization = app(CreatorMonetizationService::class);
$ledger = app(CreatorLedgerService::class);
$periods = app(CreatorRevenuePeriodService::class);
$payoutSvc = app(CreatorPayoutService::class);

/** @return array{0: User, 1: CreatorProfile, 2: CreatorEarningAccount, 3: CreatorPuzzle} */
$makeCreator = function (string $tag) use ($verified, $applications, $puzzles, $review, $reviewer, $category, $monetization, $finance): array {
    $user = $verified(User::factory()->create());
    $profile = $applications->apply($user, 'مبدع مسبار التزامن '.$tag.' — نبذة كافية الطول.');
    $applications->approve($profile, $reviewer, 'مرحبًا بك مبدعًا.');
    $profile = $profile->fresh();
    $draft = $puzzles->createDraft($user, ['kind' => 'text', 'difficulty' => 'easy', 'title' => 'لغز مسبار '.$tag, 'puzzle_category_id' => $category->id, 'prompt' => 'ما الشيء الذي يكتب ولا يقرأ؟', 'hint' => 'تجده على المكتب', 'answer' => 'القلم']);
    $review->approve($puzzles->submit($user, $draft->work->public_id), $reviewer, 'معتمدة.');
    $work = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->firstOrFail();
    $account = $monetization->enable($profile, $finance);

    return [$user, $profile, $account, $work];
};

/** يمنح الحساب رصيدًا متاحًا بتعديل إداري رسمي. */
$fund = fn (CreatorEarningAccount $account, string $amount) => $ledger->adjust($account->fresh(), $finance, 'credit', $amount, 'goodwill', 'تمويل مسبار التزامن', 'fund-'.$account->id.'-'.bin2hex(random_bytes(4)));

[$userA, $profileA, $accountA, $workA] = $makeCreator('A');
[$userB, $profileB, $accountB, $workB] = $makeCreator('B');

$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r) && ($r['mysql'] ?? 0) !== 1062)->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
$okCount = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'ok')->count();
$entries = fn (string $type, int $accountId) => DB::table('creator_earning_entries')->where('type', $type)->where('creator_earning_account_id', $accountId)->count();

// ── J1: طلب دفعة مزدوج ──
echo "\nJ1: طلب دفعة مزدوج متزامن لمبدع واحد (عمليتان لكل جولة)\n";
$fund($accountA, '10000.00');
$fund($accountB, '10000.00');
$j1 = [];
$j1Created = 0;

for ($i = 0; $i < $rounds; $i++) {
    $before = CreatorPayout::query()->where('creator_earning_account_id', $accountA->id)->count();
    $j1 = array_merge($j1, probe_race([['request', ["--actor={$userA->id}", '--amount=100.00']], ['request', ["--actor={$userA->id}", '--amount=100.00']]]));
    $after = CreatorPayout::query()->where('creator_earning_account_id', $accountA->id)->count();
    $j1Created += $after - $before;
    $open = CreatorPayout::query()->where('creator_earning_account_id', $accountA->id)->whereIn('status', CreatorPayout::OPEN_STATUSES)->first();

    if ($open !== null) {
        $payoutSvc->reject($open, $finance, 'تنظيف بين جولات المسبار');
    }
}

$reserves = $entries('payout_reserve', $accountA->id);
$releases = $entries('payout_release', $accountA->id);
$check('J1 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j1) === 0 && $lockErrors($j1) === 0, 'crash='.$crashes($j1).' lock-errors='.$lockErrors($j1));
$check('J1 no unexpected exception class (loser is a clean domain refusal)', $unexpected($j1) === 0, 'unexpected='.$unexpected($j1).' clean-refusals='.$refusals($j1));
$check('J1 exactly one request wins every race', $j1Created === $rounds && $okCount($j1) === $rounds, "created={$j1Created}/{$rounds} ok-results={$okCount($j1)}");
$check('J1 exactly one reservation entry per created request (no double spend)', $reserves === $rounds, "reserve-entries={$reserves}/{$rounds}");
$check('J1 the loser left no payout, no entry and no audit row', DB::table('operational_audit_logs')->where('action', 'creator_payout_requested')->count() === $rounds, 'audit='.DB::table('operational_audit_logs')->where('action', 'creator_payout_requested')->count());
$check('J1 reserved is back to zero after the clean-up rejections', (int) $accountA->fresh()->reserved_minor === 0 && $releases === $rounds, 'reserved='.$accountA->fresh()->reserved_minor." releases={$releases}");

// ── J2: إقفال مزدوج ──
echo "\nJ2: إقفال مزدوج متزامن للفترة نفسها (مبدعان مؤهَّلان)\n";
$base = now()->subDays(120)->startOfDay();
$players = [];

for ($p = 0; $p < 4; $p++) {
    $players[] = $verified(User::factory()->create())->id;
}

$mkPeriod = function (int $index, int $day) use ($base, $periods, $finance, $players, $workA, $workB): CreatorRevenuePeriod {
    $start = $base->copy()->addDays($day * 2);
    $end = $start->copy()->addDay();

    foreach ($players as $playerId) {
        foreach ([$workA, $workB] as $work) {
            DB::table('community_puzzle_attempts')->insert([
                'user_id' => $playerId, 'creator_puzzle_id' => $work->id, 'puzzle_id' => $work->published_puzzle_id, 'attempt_number' => 1 + $index, 'is_correct' => 1, 'created_at' => $start->copy()->addHours(2),
            ]);
        }
    }

    $period = $periods->create($finance, ['label' => 'مسبار '.$index, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'), 'pool' => '100.00']);

    return $periods->submitForReview($period, $finance);
};

$j2 = [];
$periodIds = [];

for ($i = 0; $i < $rounds; $i++) {
    $period = $mkPeriod($i, $i);
    $periodIds[] = $period->id;
    $j2 = array_merge($j2, probe_race([['finalize', ["--actor={$finance->id}", "--period={$period->id}"]], ['finalize', ["--actor={$finance2->id}", "--period={$period->id}"]]]));
}

$allocRows = DB::table('creator_revenue_allocations')->whereIn('creator_revenue_period_id', $periodIds)->count();
$shareEntries = DB::table('creator_earning_entries')->where('type', 'revenue_share')->whereIn('creator_revenue_period_id', $periodIds);
$finalizedPeriods = CreatorRevenuePeriod::query()->whereIn('id', $periodIds)->where('status', 'finalized')->count();
$auditFinalized = DB::table('operational_audit_logs')->where('action', 'creator_revenue_period_finalized')->count();
$distinctAllocPerPeriod = DB::table('creator_revenue_allocations')->whereIn('creator_revenue_period_id', $periodIds)->selectRaw('creator_revenue_period_id, COUNT(DISTINCT creator_profile_id) as n, COUNT(*) as m')->groupBy('creator_revenue_period_id')->get();

$check('J2 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j2) === 0 && $lockErrors($j2) === 0, 'crash='.$crashes($j2).' lock-errors='.$lockErrors($j2));
$check('J2 no unexpected exception class (the second closer is idempotent or a clean refusal)', $unexpected($j2) === 0, 'unexpected='.$unexpected($j2).' clean-refusals='.$refusals($j2));
$check('J2 every raced period ends finalized exactly once', $finalizedPeriods === $rounds && $auditFinalized === $rounds, "finalized={$finalizedPeriods}/{$rounds} audit={$auditFinalized}");
$check('J2 exactly two allocations per period (one per creator, no duplicate set)', $allocRows === 2 * $rounds && $distinctAllocPerPeriod->every(fn ($r) => (int) $r->n === 2 && (int) $r->m === 2), "allocations={$allocRows}/".(2 * $rounds));
$check('J2 exactly one revenue entry per allocation and the pool is distributed once (100.00 per period)', (clone $shareEntries)->count() === 2 * $rounds && (int) (clone $shareEntries)->sum('available_delta_minor') === 10000 * $rounds, 'entries='.(clone $shareEntries)->count().' sum='.(int) (clone $shareEntries)->sum('available_delta_minor'));
$check('J2 the closer that lost the race did not fail (both workers returned a result)', $okCount($j2) >= $rounds, "ok-results={$okCount($j2)}/".count($j2));

// ── J3: صرف مزدوج ──
echo "\nJ3: صرف مزدوج (المرجع نفسه، مرجعان، ودفعتان بمرجع واحد)\n";
$j3 = [];
$sameRef = [];
$diffRef = [];

for ($i = 0; $i < $rounds; $i++) {
    $payout = $payoutSvc->request($userA->fresh(), '50.00', 'bank_transfer');
    $payoutSvc->approve($payout, $finance);
    $sameRef[] = $payout->id;
    $j3 = array_merge($j3, probe_race([['settle', ["--actor={$finance->id}", "--payout={$payout->id}", "--ref=SAME-{$i}-REF"]], ['settle', ["--actor={$finance2->id}", "--payout={$payout->id}", "--ref=SAME-{$i}-REF"]]]));
}

for ($i = 0; $i < $rounds; $i++) {
    $payout = $payoutSvc->request($userA->fresh(), '50.00', 'bank_transfer');
    $payoutSvc->approve($payout, $finance);
    $diffRef[] = $payout->id;
    $j3 = array_merge($j3, probe_race([['settle', ["--actor={$finance->id}", "--payout={$payout->id}", "--ref=DIFF-{$i}-ONE"]], ['settle', ["--actor={$finance2->id}", "--payout={$payout->id}", "--ref=DIFF-{$i}-TWO"]]]));
}

$crossRounds = max(2, intdiv($rounds, 2));
$crossIds = [];
$crossLeft = [];
$cross = [];

for ($i = 0; $i < $crossRounds; $i++) {
    $pa = $payoutSvc->request($userA->fresh(), '50.00', 'bank_transfer');
    $pb = $payoutSvc->request($userB->fresh(), '50.00', 'bank_transfer');
    $payoutSvc->approve($pa, $finance);
    $payoutSvc->approve($pb, $finance);
    $crossIds[] = [$pa->id, $pb->id];
    $cross = array_merge($cross, probe_race([['settle', ["--actor={$finance->id}", "--payout={$pa->id}", "--ref=CROSS-{$i}-REF"]], ['settle', ["--actor={$finance2->id}", "--payout={$pb->id}", "--ref=CROSS-{$i}-REF"]]]));

    // الخاسر يبقى معتمَدًا (طلب مفتوح): نرفضه الآن كي لا يمنع طلب الجولة التالية (طلب واحد مفتوح لكل مبدع).
    foreach (CreatorPayout::query()->whereIn('id', [$pa->id, $pb->id])->where('status', 'approved')->get() as $left) {
        $crossLeft[] = $left->id;
        $payoutSvc->reject($left, $finance, 'تنظيف بعد سباق المرجع');
    }
}

$paidSame = CreatorPayout::query()->whereIn('id', $sameRef)->where('status', 'paid')->count();
$paidDiff = CreatorPayout::query()->whereIn('id', $diffRef)->where('status', 'paid')->count();
$settleEntries = DB::table('creator_earning_entries')->where('type', 'payout_settle')->whereIn('creator_payout_id', array_merge($sameRef, $diffRef))->count();
$crossPaid = 0;

foreach ($crossIds as [$x, $y]) {
    $crossPaid += CreatorPayout::query()->whereIn('id', [$x, $y])->where('status', 'paid')->count();
}

$crossApproved = count($crossLeft);

$all3 = array_merge($j3, $cross);
$check('J3 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($all3) === 0 && $lockErrors($all3) === 0, 'crash='.$crashes($all3).' lock-errors='.$lockErrors($all3));
$check('J3 no unexpected exception class (duplicate-reference loser, 1062, or a clean refusal)', $unexpected($all3) === 0, 'unexpected='.$unexpected($all3).' clean-refusals='.$refusals($all3));
$check('J3 same reference twice: every payout paid once and the second call is idempotent', $paidSame === $rounds, "paid={$paidSame}/{$rounds}");
$check('J3 two different references: exactly one wins, the other is refused', $paidDiff === $rounds, "paid={$paidDiff}/{$rounds}");
$check('J3 exactly one settlement entry per paid payout (no double paid_lifetime)', $settleEntries === 2 * $rounds, "settle-entries={$settleEntries}/".(2 * $rounds));
$check('J3 one reference can never settle two payouts (one wins, the other stays approved)', $crossPaid === $crossRounds && $crossApproved === $crossRounds, "paid={$crossPaid}/{$crossRounds} left-approved={$crossApproved}/{$crossRounds}");
$check('J3 external references stay globally unique', DB::table('creator_payouts')->whereNotNull('external_reference')->count() === DB::table('creator_payouts')->whereNotNull('external_reference')->distinct()->count('external_reference'));

// ── J4: اعتماد ↔ رفض ──
echo "\nJ4: اعتماد ورفض متزامنان لطلب واحد\n";
$j4 = [];
$j4Ids = [];

for ($i = 0; $i < $rounds; $i++) {
    $payout = $payoutSvc->request($userB->fresh(), '40.00', 'bank_transfer');
    $j4Ids[] = $payout->id;
    $j4 = array_merge($j4, probe_race([['approve', ["--actor={$finance->id}", "--payout={$payout->id}"]], ['reject', ["--actor={$finance2->id}", "--payout={$payout->id}"]]]));
    $final = CreatorPayout::query()->find($payout->id);

    if ($final->status === 'approved') {
        $payoutSvc->reject($final, $finance, 'تنظيف بين جولات المسبار');
    }
}

$consistent = true;

foreach ($j4Ids as $id) {
    $row = CreatorPayout::query()->find($id);
    $reserve = DB::table('creator_earning_entries')->where('creator_payout_id', $id)->where('type', 'payout_reserve')->count();
    $release = DB::table('creator_earning_entries')->where('creator_payout_id', $id)->where('type', 'payout_release')->count();
    $consistent = $consistent && $row->status === 'rejected' && $reserve === 1 && $release === 1;
}

$check('J4 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j4) === 0 && $lockErrors($j4) === 0, 'crash='.$crashes($j4).' lock-errors='.$lockErrors($j4));
$check('J4 no unexpected exception class', $unexpected($j4) === 0, 'unexpected='.$unexpected($j4).' clean-refusals='.$refusals($j4));
$check('J4 every request ends rejected with exactly one reserve and one release entry (money returned once)', $consistent);

// ── J5: إلغاء ↔ اعتماد ──
echo "\nJ5: إلغاء المبدع واعتماد المسؤول لطلب واحد\n";
$j5 = [];
$j5Ids = [];

for ($i = 0; $i < $rounds; $i++) {
    $payout = $payoutSvc->request($userB->fresh(), '40.00', 'bank_transfer');
    $j5Ids[] = $payout->id;
    $j5 = array_merge($j5, probe_race([['cancel', ["--actor={$userB->id}", "--payout={$payout->id}"]], ['approve', ["--actor={$finance->id}", "--payout={$payout->id}"]]]));
    $final = CreatorPayout::query()->find($payout->id);

    if ($final->status === 'approved') {
        $payoutSvc->reject($final, $finance, 'تنظيف بين جولات المسبار');
    }
}

$j5Bad = 0;
$j5Cancelled = 0;

foreach ($j5Ids as $id) {
    $row = CreatorPayout::query()->find($id);
    $release = DB::table('creator_earning_entries')->where('creator_payout_id', $id)->where('type', 'payout_release')->count();
    $j5Cancelled += $row->status === 'cancelled' ? 1 : 0;
    $j5Bad += ($release === 1 && in_array($row->status, ['cancelled', 'rejected'], true)) ? 0 : 1;
}

$check('J5 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j5) === 0 && $lockErrors($j5) === 0, 'crash='.$crashes($j5).' lock-errors='.$lockErrors($j5));
$check('J5 no unexpected exception class', $unexpected($j5) === 0, 'unexpected='.$unexpected($j5).' clean-refusals='.$refusals($j5));
$check('J5 every request ends cancelled or rejected with exactly one release (never both an approved and a cancelled outcome)', $j5Bad === 0, "inconsistent={$j5Bad} cancelled-won={$j5Cancelled}/{$rounds}");

// ── J6: استثناء مُحقَن ──
echo "\nJ6: استثناء مُحقَن في آخر خطوة داخل معاملة الطلب ومعاملة الإقفال (تراجع كامل)\n";
$injectRounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $injectRounds; $i++) {
    $snap = [CreatorPayout::query()->count(), DB::table('creator_earning_entries')->count(), (int) $accountB->fresh()->available_minor, (int) $accountB->fresh()->reserved_minor];
    $res = probe_race([['request-fail', ["--actor={$userB->id}", '--amount=30.00']]], 1.0);
    $after = [CreatorPayout::query()->count(), DB::table('creator_earning_entries')->count(), (int) $accountB->fresh()->available_minor, (int) $accountB->fresh()->reserved_minor];
    $check("J6 injected request failure #{$i}: worker failed as injected", ($res[0]['class'] ?? '') === 'RuntimeException' && ($res[0]['outcome'] ?? '') === 'exception', 'worker='.json_encode($res[0], JSON_UNESCAPED_UNICODE));
    $check("J6 injected request failure #{$i}: no payout, no entry, balances untouched", $snap === $after, json_encode($after));

    $clean = $payoutSvc->request($userB->fresh(), '30.00', 'bank_transfer');
    $check("J6 injected request failure #{$i}: a clean request afterwards succeeds once", $clean->status === 'requested');
    $payoutSvc->reject($clean, $finance, 'تنظيف بعد حقن الاستثناء');
}

for ($i = 0; $i < $injectRounds; $i++) {
    $period = $mkPeriod(100 + $i, 20 + $i);
    $snap = [DB::table('creator_revenue_allocations')->count(), DB::table('creator_earning_entries')->count(), (int) DB::table('creator_earning_accounts')->sum('available_minor')];
    $res = probe_race([['finalize-fail', ["--actor={$finance->id}", "--period={$period->id}"]]], 1.0);
    $after = [DB::table('creator_revenue_allocations')->count(), DB::table('creator_earning_entries')->count(), (int) DB::table('creator_earning_accounts')->sum('available_minor')];
    $check("J6 injected finalize failure #{$i}: worker failed as injected", ($res[0]['class'] ?? '') === 'RuntimeException' && ($res[0]['outcome'] ?? '') === 'exception', 'worker='.json_encode($res[0], JSON_UNESCAPED_UNICODE));
    $check("J6 injected finalize failure #{$i}: still in review, no allocation, no entry, no balance change", $period->fresh()->status === 'review' && $snap === $after, 'status='.$period->fresh()->status.' '.json_encode($after));
    $done = $periods->finalize($period->fresh(), $finance);
    $check("J6 injected finalize failure #{$i}: a clean finalize afterwards succeeds once", $done->status === 'finalized' && DB::table('creator_revenue_allocations')->where('creator_revenue_period_id', $period->id)->count() === 2);
}

// ── J7: تفعيل التسييل ↔ إقفال ──
echo "\nJ7: تفعيل التسييل (مبدع مفعَّل أصلًا) وإقفال فترة متزامنان - ترتيب الأقفال حساب ثم ملف\n";
$j7 = [];

for ($i = 0; $i < $rounds; $i++) {
    $period = $mkPeriod(200 + $i, 30 + $i);
    $j7 = array_merge($j7, probe_race([['finalize', ["--actor={$finance->id}", "--period={$period->id}"]], ['enable', ["--actor={$finance2->id}", "--profile={$profileA->id}"]], ['enable', ["--actor={$finance2->id}", "--profile={$profileB->id}"]]]));
    $period = $period->fresh();
    $check("J7 round #{$i}: the period is finalized with two allocations", $period->status === 'finalized' && DB::table('creator_revenue_allocations')->where('creator_revenue_period_id', $period->id)->count() === 2);
}

$check('J7 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j7) === 0 && $lockErrors($j7) === 0, 'crash='.$crashes($j7).' lock-errors='.$lockErrors($j7));
$check('J7 no unexpected exception class', $unexpected($j7) === 0, 'unexpected='.$unexpected($j7).' clean-refusals='.$refusals($j7));
$check('J7 both accounts are still enabled and there is still exactly one account per creator', DB::table('creator_earning_accounts')->count() === 2 && CreatorEarningAccount::query()->where('monetization_status', 'enabled')->count() === 2);

// ── R: المصالحة المالية ──
echo "\nR: المصالحة المالية بعد كل السباقات\n";
$report = app(CreatorFinanceReconciler::class)->run(500);
$check('R reconciliation reports no mismatch (snapshots = ledger, reserved = open payouts, paid = paid payouts, allocations = entries)', $report['findings'] === [], 'findings='.count($report['findings']).($report['findings'] === [] ? '' : ' first='.json_encode($report['findings'][0], JSON_UNESCAPED_UNICODE)));
$check('R no negative balance anywhere', DB::table('creator_earning_accounts')->where(fn ($q) => $q->where('available_minor', '<', 0)->orWhere('reserved_minor', '<', 0)->orWhere('paid_lifetime_minor', '<', 0))->count() === 0);
$check('R the ledger conserves money: available + reserved + paid = credits (revenue + adjustments) - debits', (function () {
    $in = (int) DB::table('creator_earning_entries')->whereIn('type', ['revenue_share', 'adjustment_credit'])->sum('available_delta_minor');
    $out = -1 * (int) DB::table('creator_earning_entries')->where('type', 'adjustment_debit')->sum('available_delta_minor');
    $held = (int) DB::table('creator_earning_accounts')->selectRaw('SUM(available_minor + reserved_minor + paid_lifetime_minor) as t')->value('t');

    return $held === $in - $out;
})());

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

<?php

/*
|--------------------------------------------------------------------------
| E32 closure - مسبار التزامن الحقيقي على MySQL (عمليتا PHP منفصلتان تتسابقان فعلًا)
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، فلا تُنتج سباقًا حقيقيًا بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليتين تبدآن في اللحظة نفسها:
|   J1  تحقق مزدوج من الطلب نفسه             ← مساهمة واحدة فقط وأثر واحد (تدقيق/إشعار/تمويل)
|   J2  استرداد مزدوج (دفع+دفع، ودفع+E30)    ← استرداد واحد، بلا deadlock، والصافي صحيح
|   J3  منح مكافأة بالمفتاح المصدر نفسه       ← منحة واحدة وحركة محفظة واحدة
|   J4  طلب بانتظار/مرسَل لا يموّل البرنامج     ← التمويل = المحقَّق فقط
|   J5  استثناء مُحقَن وسط التجسيد (مساهمة+تدقيق) ← تراجع كامل: لا مساهمة ولا داعم ولا أثر، ثم ينجح التحقق لاحقًا
|   R   المطابقة المالية بعد السباقات = لا فروق
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/mysql-production-e31.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e32/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e32/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 6)
*/

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipRewardBonus;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SponsorshipRewardService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env (جداول الكاش تُحذف أثناء migrate:fresh)، وتُورَّث للعمليات العاملة.
foreach (['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'] as $envKey => $envValue) {
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

        if ($kind === 'verify') {
            $payment = SponsorshipPayment::query()->findOrFail((int) $args['payment']);
            $after = app(SponsorshipPaymentService::class)->verify($actor, $payment);
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'verify-fail') {
            // يحقن استثناءً بعد إدراج المساهمة وتحديث الطلب وقبل نهاية المعاملة (آخر خطوة: تدقيق contribution_materialized).
            app()->bind(\App\Services\OperationalAuditService::class, fn () => new class extends \App\Services\OperationalAuditService
            {
                public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
                {
                    if ($action === 'contribution_materialized') {
                        throw new RuntimeException('probe-injected-failure');
                    }

                    parent::log($action, $subject, $metadata, $actor);
                }
            });
            $payment = SponsorshipPayment::query()->findOrFail((int) $args['payment']);
            $after = app(SponsorshipPaymentService::class)->verify($actor, $payment);
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'refund-payment') {
            $payment = SponsorshipPayment::query()->findOrFail((int) $args['payment']);
            $after = app(SponsorshipPaymentService::class)->refund($actor, $payment, 'مسبار التزامن');
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'refund-contribution') {
            $contribution = SponsorshipContribution::query()->findOrFail((int) $args['contribution']);
            $after = app(ContributionService::class)->refund($actor, $contribution, 'مسبار التزامن (E30)');
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'reward') {
            $user = User::query()->findOrFail((int) $args['user']);
            $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
            $method = new ReflectionMethod(SponsorshipRewardService::class, 'credit');
            $granted = $method->invoke(app(SponsorshipRewardService::class), $user, $currency, (int) $args['program'], (string) $args['key'], null, null, 100, 500, 5, 5, 1000);
            $result = ['outcome' => 'ok', 'granted' => (int) $granted];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof \App\Services\Sponsorship\SponsorshipException || $e instanceof \App\Services\Payments\PaymentException;
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

$rounds = max(2, min(20, (int) ($args['rounds'] ?? 6)));
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ').$name.($detail !== '' ? '  - '.$detail : '')."\n";
};

echo "== E32 concurrency probe (MySQL: ".DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh)...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);

$finance = User::factory()->create(['email' => 'probe-finance@concurrency.test']);
$finance->forceFill(['email_verified_at' => now()])->save();
$finance->syncRoles(['administrator']);

$programs = app(SponsorshipProgramService::class);
$program = $programs->activate($finance, $programs->create($finance, ['title' => 'برنامج مسبار التزامن', 'target_type' => 'platform', 'description' => 'اختبار تزامن مؤقت']));
$payments = app(SponsorshipPaymentService::class);

$newSubmitted = function (string $amount) use ($payments, $program): SponsorshipPayment {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    $payment = $payments->createRequest($user, $program, $amount, 'USD', false, null, 'manual', 'probe-'.$user->id);

    return $payments->markTransferred($user, $payment, 'مسبار');
};

// ── J4: غير المحقَّق لا يموّل ──
$awaiting = (function () use ($payments, $program) {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();

    return $payments->createRequest($user, $program, '77', 'USD', false, null, 'manual', 'probe-await-'.$user->id);
})();
$submittedOnly = $newSubmitted('88');
$totals = app(SupportTotals::class);
$check('J4 awaiting + submitted requests do not fund the program', $totals->programFunding($program->id) === 0, 'funding='.$totals->programFunding($program->id));

$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r) && ($r['mysql'] ?? 0) !== 1062)->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
$duplicateKeys = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['mysql'] ?? 0) === 1062)->map(fn ($r) => $r['detail'] ?? '')->countBy()->all(), JSON_UNESCAPED_UNICODE);

// ── J1: تحقق مزدوج ──
echo "\nJ1: تحقق مزدوج متزامن (عمليتان لكل طلب)\n";
$verifyIds = [];
$j1 = [];

for ($i = 0; $i < $rounds; $i++) {
    $payment = $newSubmitted('10');
    $verifyIds[] = $payment->id;
    $j1 = array_merge($j1, probe_race([['verify', ["--actor={$finance->id}", "--payment={$payment->id}"]], ['verify', ["--actor={$finance->id}", "--payment={$payment->id}"]]]));
}

$verifiedCount = SponsorshipPayment::query()->whereIn('id', $verifyIds)->where('status', 'verified')->count();
$linkedDistinct = SponsorshipPayment::query()->whereIn('id', $verifyIds)->whereNotNull('sponsorship_contribution_id')->distinct()->count('sponsorship_contribution_id');
$totalContribs = SponsorshipContribution::query()->where('sponsorship_program_id', $program->id)->count();
$auditVerified = DB::table('operational_audit_logs')->where('action', 'payment_verified')->count();
$winners = collect($j1)->filter(fn ($r) => ($r['outcome'] ?? '') === 'ok')->count();

$check('J1 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j1) === 0 && $lockErrors($j1) === 0, 'crash='.$crashes($j1).' lock-errors='.$lockErrors($j1));
$check('J1 no unexpected exception class (only clean refusals / idempotent results)', $unexpected($j1) === 0, 'unexpected='.$unexpected($j1).' clean-refusals='.$refusals($j1));
$check('J1 every raced payment is verified exactly once', $verifiedCount === $rounds, "verified={$verifiedCount}/{$rounds}");
$check('J1 exactly one contribution per payment (no duplicate, no orphan)', $linkedDistinct === $rounds && $totalContribs === $rounds, "contributions={$totalContribs} linked-distinct={$linkedDistinct} expected={$rounds}");
$check('J1 exactly one payment_verified audit row per payment', $auditVerified === $rounds, "audit={$auditVerified}/{$rounds}");
$check('J1 at least one winner per race', $winners >= $rounds, "ok-results={$winners}/".count($j1));
$verifiedMoney = (int) SponsorshipPayment::query()->where('sponsorship_program_id', $program->id)->where('status', 'verified')->sum('accounting_amount_minor');
$check('J1 funding equals verified payments money exactly (awaiting/submitted excluded)', $totals->programFunding($program->id) === $verifiedMoney && $verifiedMoney === $rounds * 1000, 'funding='.$totals->programFunding($program->id)." verified-payments={$verifiedMoney}");

// ── J2: استرداد مزدوج ──
echo "\nJ2: استرداد مزدوج متزامن (دفع+دفع، ثم دفع+E30 مباشر)\n";
$samePayments = [];
$mixedPayments = [];
$j2 = [];
$j2rounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $j2rounds; $i++) {
    $payment = $newSubmitted('10');
    $payments->verify($finance, $payment->fresh());
    $samePayments[] = $payment->id;
    $j2 = array_merge($j2, probe_race([['refund-payment', ["--actor={$finance->id}", "--payment={$payment->id}"]], ['refund-payment', ["--actor={$finance->id}", "--payment={$payment->id}"]]]));
}

for ($i = 0; $i < $j2rounds; $i++) {
    $payment = $newSubmitted('10');
    $payments->verify($finance, $payment->fresh());
    $mixedPayments[] = $payment->id;
    $contribution = (int) SponsorshipPayment::query()->whereKey($payment->id)->value('sponsorship_contribution_id');
    $j2 = array_merge($j2, probe_race([['refund-payment', ["--actor={$finance->id}", "--payment={$payment->id}"]], ['refund-contribution', ["--actor={$finance->id}", "--contribution={$contribution}"]]]));
}

$raced = array_merge($samePayments, $mixedPayments);
$refundedPayments = SponsorshipPayment::query()->whereIn('id', $raced)->where('status', 'refunded')->count();
$refundedContribs = SponsorshipContribution::query()->whereIn('id', SponsorshipPayment::query()->whereIn('id', $raced)->pluck('sponsorship_contribution_id'))->where('status', 'refunded')->count();
$auditRefunds = DB::table('operational_audit_logs')->where('action', 'payment_refunded')->count();

$check('J2 no worker crash, no deadlock (1213) / lock timeout (1205) in refund races', $crashes($j2) === 0 && $lockErrors($j2) === 0, 'crash='.$crashes($j2).' lock-errors='.$lockErrors($j2));
$check('J2 no unexpected exception class (domain refusals of the loser are accepted and listed)', $unexpected($j2) === 0, 'unexpected='.$unexpected($j2).' clean-refusals='.$refusals($j2));
$check('J2 every double-refunded payment ends refunded', $refundedPayments === count($raced), "refunded-payments={$refundedPayments}/".count($raced));
$check('J2 every matching contribution ends refunded exactly once', $refundedContribs === count($raced), "refunded-contributions={$refundedContribs}/".count($raced));
$check('J2 exactly one payment_refunded audit row per refunded payment', $auditRefunds === count($raced), "audit={$auditRefunds}/".count($raced));
$verifiedMoney = (int) SponsorshipPayment::query()->where('sponsorship_program_id', $program->id)->where('status', 'verified')->sum('accounting_amount_minor');
$check('J2 funding after refunds equals still-verified payments money exactly (no double deduction)', $totals->programFunding($program->id) === $verifiedMoney, 'funding='.$totals->programFunding($program->id)." verified-payments={$verifiedMoney}");

// ── J5: استثناء مُحقَن وسط التجسيد ──
echo "\nJ5: استثناء مُحقَن أثناء تجسيد المساهمة (يجب ألا تبقى حالة مالية نصف منشأة)\n";
$injected = [];
$fundingBefore = $totals->programFunding($program->id);
$contribBefore = SponsorshipContribution::query()->count();
$profilesBefore = DB::table('supporter_profiles')->count();
$auditBefore = DB::table('operational_audit_logs')->where('action', 'payment_verified')->count();
$bonusBefore = SponsorshipRewardBonus::query()->count();
$txBefore = DB::table('currency_transactions')->count();

for ($i = 0; $i < max(2, intdiv($rounds, 2)); $i++) {
    $payment = $newSubmitted('10');
    $profilesWithPayer = DB::table('supporter_profiles')->count();
    $res = probe_race([['verify-fail', ["--actor={$finance->id}", "--payment={$payment->id}"]]], 1.0);
    $injected[] = $payment->id;
    $row = SponsorshipPayment::query()->find($payment->id);
    $stateOk = $row->status === 'submitted' && $row->sponsorship_contribution_id === null && $row->verified_at === null && $row->accounting_amount_minor === null;
    $check("J5 injected failure #{$i}: worker failed as injected (domain-neutral RuntimeException)", ($res[0]['class'] ?? '') === 'RuntimeException' && ($res[0]['outcome'] ?? '') === 'exception', 'worker='.json_encode($res[0], JSON_UNESCAPED_UNICODE));
    $check("J5 injected failure #{$i}: payment untouched (still submitted, no contribution link, no verified_at, no accounting amounts)", $stateOk, 'status='.$row->status);
    $check("J5 injected failure #{$i}: no supporter profile was created by the rolled-back attempt", DB::table('supporter_profiles')->count() === $profilesWithPayer, 'profiles='.DB::table('supporter_profiles')->count()." expected={$profilesWithPayer}");

    // بعد التراجع يجب أن ينجح التحقق العادي (لا قفل عالق ولا حالة يتيمة)
    $after = $payments->verify($finance, $payment->fresh());
    $check("J5 injected failure #{$i}: a clean verify afterwards succeeds exactly once", $after->status === 'verified' && SponsorshipContribution::query()->where('reference', $payment->reference)->count() === 1);
}

$verifiedAfterInjection = count($injected);
$check('J5 no orphan contributions: every contribution has exactly one payment', DB::table('sponsorship_contributions as c')->leftJoin('sponsorship_payments as p', 'p.sponsorship_contribution_id', '=', 'c.id')->whereNull('p.id')->where('c.provider', 'manual')->count() === 0, 'orphans='.DB::table('sponsorship_contributions as c')->leftJoin('sponsorship_payments as p', 'p.sponsorship_contribution_id', '=', 'c.id')->whereNull('p.id')->where('c.provider', 'manual')->count());
$check('J5 contributions grew only by the clean verifies (no leak from failed attempts)', SponsorshipContribution::query()->count() === $contribBefore + $verifiedAfterInjection, 'contributions='.SponsorshipContribution::query()->count().' expected='.($contribBefore + $verifiedAfterInjection));
$check('J5 payment_verified audit rows grew only by the clean verifies', DB::table('operational_audit_logs')->where('action', 'payment_verified')->count() === $auditBefore + $verifiedAfterInjection);
$check('J5 funding grew by exactly the clean verifies money', $totals->programFunding($program->id) === $fundingBefore + $verifiedAfterInjection * 1000, 'funding='.$totals->programFunding($program->id).' expected='.($fundingBefore + $verifiedAfterInjection * 1000));
$check('J5 no reward bonus or wallet transaction was created by failed attempts', SponsorshipRewardBonus::query()->count() === $bonusBefore && DB::table('currency_transactions')->count() === $txBefore, 'bonus='.SponsorshipRewardBonus::query()->count()."/{$bonusBefore} tx=".DB::table('currency_transactions')->count()."/{$txBefore}");

// ── J3: مكافأة بالمفتاح المصدر نفسه ──
echo "\nJ3: منح مكافأة بالمفتاح المصدر نفسه (عمليتان، مستخدم جديد بلا محفظة في كل جولة)\n";
$j3 = [];
$players = [];

for ($i = 0; $i < $rounds; $i++) {
    $player = User::factory()->create();
    $player->forceFill(['email_verified_at' => now()])->save();
    $players[] = $player->id;
    $key = 'probe:'.$i;
    $one = ["--actor={$finance->id}", "--user={$player->id}", "--program={$program->id}", "--key={$key}"];
    $j3 = array_merge($j3, probe_race([['reward', $one], ['reward', $one]]));
}

$bonusRows = SponsorshipRewardBonus::query()->where('source_key', 'like', 'sponsor-bonus:probe:%')->count();
$distinctKeys = SponsorshipRewardBonus::query()->where('source_key', 'like', 'sponsor-bonus:probe:%')->distinct()->count('source_key');
$txRows = DB::table('currency_transactions')->whereIn('user_id', $players)->where('idempotency_key', 'like', 'sponsor-bonus:probe:%')->count();
$walletRows = DB::table('wallets')->whereIn('user_id', $players)->count();
$pendingSum = (int) DB::table('wallets')->whereIn('user_id', $players)->sum('pending_balance');
$grantedSum = collect($j3)->sum(fn ($r) => (int) ($r['granted'] ?? 0));
$lostRace1062 = collect($j3)->filter(fn ($r) => ($r['mysql'] ?? 0) === 1062)->count();

$check('J3 no worker crash and no deadlock / lock timeout', $crashes($j3) === 0 && $lockErrors($j3) === 0, 'crash='.$crashes($j3).' lock-errors='.$lockErrors($j3));
$check('J3 no unexpected exception class (duplicate-key loser, 1062, is the only accepted loser)', $unexpected($j3) === 0, 'unexpected='.$unexpected($j3)." loser-1062={$lostRace1062} keys=".$duplicateKeys($j3));
$check('J3 one bonus row per source_key (UNIQUE holds under a real race)', $bonusRows === $rounds && $distinctKeys === $rounds, "rows={$bonusRows}/{$rounds}");
$check('J3 exactly one wallet transaction per source_key', $txRows === $rounds, "transactions={$txRows}/{$rounds}");
$check('J3 exactly one wallet per player (wallet-creation race safe)', $walletRows === $rounds, "wallets={$walletRows}/{$rounds}");
$check('J3 pending balance equals one bonus per key (no double credit)', $pendingSum === $rounds * 5, "pending-sum={$pendingSum} expected=".($rounds * 5)." returned-sum={$grantedSum}");

// ── R: المطابقة المالية ──
echo "\nR: المطابقة المالية بعد كل السباقات\n";
$findings = app(PaymentReconciliationService::class)->run();
$check('R reconciliation reports no mismatch', $findings === [], 'findings='.count($findings).($findings === [] ? '' : ' first='.json_encode($findings[0], JSON_UNESCAPED_UNICODE)));

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

<?php

/*
|--------------------------------------------------------------------------
| E40 - مسبار التزامن الحقيقي على MySQL (عمليات PHP منفصلة تتسابق فعلًا) لمسار الأحجيات
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، وقفل الصفوف (lockForUpdate) لا أثر له على SQLite، فلا سباق حقيقي بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليات تبدأ في اللحظة نفسها:
|   J1  طلب مميز مزدوج للاعب نفسه                    ← صف طلب مفتوح واحد (open_key)، لا طلبان
|   J2  تحقق مزدوج لطلب مقدَّم من مسؤولَين            ← فتح مميز مرة واحدة، حدث واحد، تدقيق واحد، إشعار واحد
|   J3  تحقق ↔ رفض لطلب واحد                          ← نتيجة واحدة متسقة (محقَّق ⇔ مميز مفتوح)
|   J4  مطالبة مزدوجة بالمكافأة نفسها                  ← مطالبة واحدة وحركة مخزون واحدة، الخاسر رفض نظيف
|   J5  «استلام الكل» ↔ «استلام الكل» ↔ مطالبة فردية  ← كل مكافأة تُستلم مرة واحدة بالضبط، المخزون = المطالبات
|   J6  استعادة النقاط المزدوجة                        ← صف دفتر واحد لكل مصدر، pass_points = مجموع الدفتر
|   J7  استرداد ↔ مطالبات مميزة                        ← لا مطالبة مميزة بعد الاسترداد، refund_kept_items = المطالبات الفعلية، بلا deadlock
|   J8  استثناء مُحقَن بعد منح المخزون داخل المطالبة   ← تراجع كامل (لا مطالبة ولا حركة مخزون) ثم تنجح مطالبة نظيفة لاحقًا
|   J9  مزامنة الدورة المزدوجة عند لحظة البدء          ← تفعيل المسار مرة واحدة، لا انتهاك لقيد «مسار فعّال واحد»
|   R   مصالحة المسار بعد السباقات = لا فروق (ahjiyat-pass:reconcile)
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/ahjiyat-pass-operations-runbook.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e40/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e40/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 4)
*/

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Models\DigitalPurchase;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Models\UserQuestProgress;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassClaimService;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPointService;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\AhjiyatPass\PassProgressService;
use App\Services\AhjiyatPass\PassReconciler;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env (جداول الكاش تُحذف أثناء migrate:fresh)، وتُورَّث للعمليات العاملة.
foreach (['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'AHJIYAT_PASS_ENABLED' => 'true'] as $envKey => $envValue) {
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
        $actor = isset($args['actor']) ? User::query()->find((int) $args['actor']) : null;
        $pass = isset($args['pass']) ? AhjiyatPass::query()->find((int) $args['pass']) : null;
        $order = isset($args['order']) ? DigitalPurchase::query()->find((int) $args['order']) : null;

        while (microtime(true) < $at) {
            usleep(200);
        }

        $started = microtime(true);

        if ($kind === 'claim-fail') {
            // يحقن استثناءً بعد منح المخزون داخل معاملة المطالبة نفسها: يجب أن يتراجع المنح والمطالبة معًا.
            app()->bind(\App\Services\Store\InventoryService::class, fn ($app) => new class($app->make(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)) extends \App\Services\Store\InventoryService
            {
                public function grant(User $user, StoreItem $item, int $quantity, string $reason, ?\Illuminate\Database\Eloquent\Model $reference = null): \App\Models\InventoryTransaction
                {
                    parent::grant($user, $item, $quantity, $reason, $reference);

                    throw new RuntimeException('probe-injected-failure');
                }
            });
        }

        if ($kind === 'request') {
            $o = app(PassPremiumService::class)->request($actor, $pass);
            $result = ['outcome' => 'ok', 'id' => $o->id, 'status' => $o->status];
        } elseif ($kind === 'verify') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->verify($actor, $order, 'تحقق من مسبار التزامن')->status];
        } elseif ($kind === 'reject') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->reject($actor, $order, 'رفض من مسبار التزامن')->status];
        } elseif ($kind === 'refund') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->refund($actor, $order, 'error', 'استرداد من مسبار التزامن', true)->status];
        } elseif ($kind === 'claim' || $kind === 'claim-fail') {
            $claim = app(PassClaimService::class)->claim($actor, $pass, (int) $args['reward']);
            $result = ['outcome' => 'ok', 'claimed' => 1, 'id' => $claim->id];
        } elseif ($kind === 'collect') {
            $report = app(PassClaimService::class)->collectAll($actor, $pass);
            $result = ['outcome' => 'ok', 'claimed' => count($report['claimed']), 'failed' => count($report['failed']), 'reasons' => collect($report['failed'])->pluck('reason')->countBy()->all()];
        } elseif ($kind === 'heal') {
            $result = ['outcome' => 'ok', 'granted' => app(PassPointService::class)->healFor($actor, $pass)];
        } elseif ($kind === 'sync') {
            $result = ['outcome' => 'ok'] + app(PassLifecycleService::class)->sync();
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof PassException;
        // لا نطبع رسائل SQL (قد تحمل قيم): اسم الفهرس المخالَف فقط لأخطاء 1062، ورمز السبب للاستثناءات الدومينية.
        $detail = '';
        if ($code === 1062 && preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $detail = $m[1];
        } elseif ($domain) {
            $detail = $e->reason;
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

$rounds = max(2, min(20, (int) ($args['rounds'] ?? 4)));
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ').$name.($detail !== '' ? '  - '.$detail : '')."\n";
};

echo '== E40 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh)...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);

$verified = function (User $u): User {
    $u->forceFill(['email_verified_at' => now()])->save();

    return $u->fresh();
};
$player = fn () => $verified(User::factory()->create());
$admin = function (string $tag) use ($verified): User {
    $u = $verified(User::factory()->create(['email' => "probe-{$tag}@pass-concurrency.test"]));
    $u->syncRoles(['administrator']);

    return $u->fresh();
};

$finance = $admin('finance');
$finance2 = $admin('finance2');
$builder = app(PassBuilderService::class);
$lifecycle = app(PassLifecycleService::class);
$premium = app(PassPremiumService::class);
$progressSvc = app(PassProgressService::class);
$points = app(PassPointService::class);

/** @return array{pass: AhjiyatPass, quest: QuestDefinition, rewards: array<int, array{free: AhjiyatPassTierReward, premium: ?AhjiyatPassTierReward}>} */
$buildPass = function (string $key, Carbon $starts, int $days, bool $withPremium) use ($builder, $lifecycle, $finance): array {
    $pass = $builder->createDraft($finance, array_filter([
        'internal_key' => $key, 'name' => 'مسار المسبار '.$key,
        'starts_at' => $starts->format('Y-m-d H:i:s'), 'ends_at' => $starts->copy()->addDays($days)->format('Y-m-d H:i:s'), 'claim_grace_days' => 5,
        'premium_enabled' => $withPremium, 'premium_price_minor' => $withPremium ? 500 : null, 'currency' => $withPremium ? 'USD' : null,
    ], fn ($v) => $v !== null));
    $builder->generateTiers($finance, $pass, 6, 100, 0, 0);
    $rewards = [];

    foreach (AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->id)->orderBy('tier_number')->get() as $tier) {
        $rewards[$tier->tier_number] = [
            'free' => $builder->setTierReward($finance, $tier, 'free', StoreItem::factory()->cosmeticTitle('لقب '.$key.$tier->tier_number)->create()),
            'premium' => $withPremium ? $builder->setTierReward($finance, $tier, 'premium', StoreItem::factory()->cosmeticTitle('لقب مميز '.$key.$tier->tier_number)->create()) : null,
        ];
    }

    $quest = QuestDefinition::factory()->daily(1)->create();
    $builder->attachMission($finance, $pass, $quest, 'easy', ['points' => 100, 'max_completions' => 10, 'sort_order' => 0]);
    $lifecycle->schedule($finance, $pass->fresh());

    return ['pass' => $pass->fresh(), 'quest' => $quest, 'rewards' => $rewards];
};

/** إكمالات مصدر (UserQuestProgress) داخل نافذة المسار، ثم نقاط عبر الخدمة الرسمية في العملية الرئيسية. */
$completions = function (User $user, QuestDefinition $quest, int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $at = now()->subDays(1 + $i)->setTime(10, 0);
        UserQuestProgress::query()->create([
            'user_id' => $user->id, 'quest_definition_id' => $quest->id, 'period_type' => $quest->period_type, 'period_key' => 'daily:'.$at->format('Y-m-d'),
            'period_start' => $at->copy()->startOfDay(), 'period_end' => $at->copy()->endOfDay(), 'current_value' => (int) $quest->target_value, 'target_value_snapshot' => (int) $quest->target_value, 'completed_at' => $at,
        ]);
    }
};

$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r))->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
$okCount = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'ok')->count();
$cleanRaces = function (string $label, array $rs) use ($check, $crashes, $lockErrors, $unexpected, $refusals): void {
    $check("{$label} no worker crash, no deadlock (1213) / lock timeout (1205)", $crashes($rs) === 0 && $lockErrors($rs) === 0, 'crash='.$crashes($rs).' lock-errors='.$lockErrors($rs));
    $check("{$label} no unexpected exception (the loser is a clean domain refusal or idempotent)", $unexpected($rs) === 0, 'unexpected='.$unexpected($rs).' clean-refusals='.$refusals($rs));
};

// ── J9 أولًا: مسار يبدأ بعد ثوانٍ، ومزامنتان متزامنتان عند لحظة البدء ──
echo "\nJ9: مزامنتان متزامنتان لمسار مجدول عند لحظة بدئه\n";
$j9 = [];
$j9Active = 0;
$j9Activated = 0;

for ($i = 0; $i < max(2, intdiv($rounds, 2)); $i++) {
    $first = $buildPass('probe-sync-'.$i, now()->addSeconds(12), 10, false);
    sleep(14);
    $rs = probe_race([['sync', []], ['sync', []]], 1.0);
    $j9 = array_merge($j9, $rs);
    $j9Activated += (int) collect($rs)->sum('activated');
    $j9Active += AhjiyatPass::query()->where('id', $first['pass']->id)->where('status', 'active')->whereNotNull('live_slot')->count();
    $lifecycle->end($finance, $first['pass']->fresh(), 'تنظيف بعد سباق المزامنة');
}

$cleanRaces('J9', $j9);
$check('J9 each raced pass becomes active exactly once and holds the single live slot', $j9Active === max(2, intdiv($rounds, 2)), 'active='.$j9Active);
$check('J9 the activation is counted once across both sync workers', $j9Activated === max(2, intdiv($rounds, 2)), 'activated='.$j9Activated);
$check('J9 never more than one live pass', DB::table('ahjiyat_passes')->whereNotNull('live_slot')->count() === 0, 'live='.DB::table('ahjiyat_passes')->whereNotNull('live_slot')->count());

// ── المسار الرئيسي للسباقات ──
$main = $buildPass('probe-main', now()->subDays(10), 30, true);
$pass = $main['pass'];
$rewards = $main['rewards'];
$quest = $main['quest'];
$free1 = (int) $rewards[1]['free']->id;

/** لاعب بطلب مقدَّم جاهز للمراجعة. @return array{0: User, 1: DigitalPurchase} */
$submitted = function () use ($player, $premium, $pass): array {
    $user = $player();
    $order = $premium->submit($user, $premium->request($user, $pass), 'TRX-'.random_int(10000000, 99999999), null);

    return [$user, $order];
};

// ── J1: طلب مزدوج ──
echo "\nJ1: طلب مميز مزدوج متزامن للاعب نفسه\n";
$j1 = [];
$j1Rows = 0;

for ($i = 0; $i < $rounds; $i++) {
    $user = $player();
    $j1 = array_merge($j1, probe_race([['request', ["--actor={$user->id}", "--pass={$pass->id}"]], ['request', ["--actor={$user->id}", "--pass={$pass->id}"]]]));
    $j1Rows += DB::table('digital_purchases')->where('user_id', $user->id)->where('product_id', $pass->id)->count() === 1 ? 1 : 0;
}

$cleanRaces('J1', $j1);
$check('J1 exactly one open order per player in every race', $j1Rows === $rounds, "single-order rounds={$j1Rows}/{$rounds}");
$check('J1 the database never holds two open orders for one player', DB::table('digital_purchases')->whereNotNull('open_key')->count() === DB::table('digital_purchases')->whereNotNull('open_key')->distinct()->count('open_key'));

// ── J2: تحقق مزدوج ──
echo "\nJ2: تحقق مزدوج متزامن لطلب مقدَّم (مسؤولان)\n";
$j2 = [];
$j2Orders = [];

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order] = $submitted();
    $j2Orders[] = [$user->id, $order->id];
    $j2 = array_merge($j2, probe_race([['verify', ["--actor={$finance->id}", "--order={$order->id}"]], ['verify', ["--actor={$finance2->id}", "--order={$order->id}"]]]));
}

$verifiedOrders = DigitalPurchase::query()->whereIn('id', array_column($j2Orders, 1))->where('status', 'verified')->count();
$verifiedEvents = DB::table('digital_purchase_events')->whereIn('digital_purchase_id', array_column($j2Orders, 1))->where('event', 'verified')->count();
$unlocked = UserPassProgress::query()->whereIn('user_id', array_column($j2Orders, 0))->where('ahjiyat_pass_id', $pass->id)->whereNotNull('premium_unlocked_at')->whereIn('premium_purchase_id', array_column($j2Orders, 1))->count();
$notes = DB::table('notifications')->where('type_key', 'pass_premium_unlocked')->whereIn('notifiable_id', array_column($j2Orders, 0))->count();
$audits = DB::table('operational_audit_logs')->where('action', 'digital_purchase_verified')->count();

$cleanRaces('J2', $j2);
$check('J2 every raced order is verified exactly once (one winner, one clean refusal)', $verifiedOrders === $rounds && $okCount($j2) === $rounds, "verified={$verifiedOrders}/{$rounds} ok-results={$okCount($j2)}");
$check('J2 exactly one verified event, one unlock link, one notification and one audit row per order', $verifiedEvents === $rounds && $unlocked === $rounds && $notes === $rounds && $audits === $rounds, "events={$verifiedEvents} unlocked={$unlocked} notifications={$notes} audits={$audits} (expected {$rounds} each)");

// ── J3: تحقق ↔ رفض ──
echo "\nJ3: تحقق ورفض متزامنان لطلب واحد\n";
$j3 = [];
$j3Ids = [];

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order] = $submitted();
    $j3Ids[] = [$user->id, $order->id];
    $j3 = array_merge($j3, probe_race([['verify', ["--actor={$finance->id}", "--order={$order->id}"]], ['reject', ["--actor={$finance2->id}", "--order={$order->id}"]]]));
}

$consistent = 0;
$oneTerminal = 0;

foreach ($j3Ids as [$uid, $oid]) {
    $status = DigitalPurchase::query()->find($oid)->status;
    $owns = UserPassProgress::query()->where('user_id', $uid)->where('ahjiyat_pass_id', $pass->id)->whereNotNull('premium_unlocked_at')->exists();
    $consistent += (($status === 'verified' && $owns) || ($status === 'rejected' && ! $owns)) ? 1 : 0;
    $oneTerminal += DB::table('digital_purchase_events')->where('digital_purchase_id', $oid)->whereIn('event', ['verified', 'rejected'])->count() === 1 ? 1 : 0;
}

$cleanRaces('J3', $j3);
$check('J3 one outcome per order, always consistent with premium ownership (verified ⇔ unlocked)', $consistent === $rounds, "consistent={$consistent}/{$rounds}");
$check('J3 exactly one terminal event per order and exactly one winner', $oneTerminal === $rounds && $okCount($j3) === $rounds, "single-terminal={$oneTerminal}/{$rounds} ok-results={$okCount($j3)}");

// ── J4: مطالبة مزدوجة ──
echo "\nJ4: مطالبة مزدوجة بالمكافأة نفسها (ثلاث عمليات)\n";
$j4 = [];
$j4Users = [];

for ($i = 0; $i < $rounds; $i++) {
    $user = $player();
    $progressSvc->ensure($user, $pass);
    $j4Users[] = $user->id;
    $j4 = array_merge($j4, probe_race([['claim', ["--actor={$user->id}", "--pass={$pass->id}", "--reward={$free1}"]], ['claim', ["--actor={$user->id}", "--pass={$pass->id}", "--reward={$free1}"]], ['claim', ["--actor={$user->id}", "--pass={$pass->id}", "--reward={$free1}"]]]));
}

$claims = DB::table('user_pass_reward_claims')->whereIn('user_id', $j4Users)->count();
$inventory = DB::table('inventory_transactions')->whereIn('user_id', $j4Users)->count();
$quantity = (int) DB::table('user_inventory_items')->whereIn('user_id', $j4Users)->sum('quantity');
$refused = collect($j4)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused' && ($r['detail'] ?? '') === 'already_claimed')->count();

$cleanRaces('J4', $j4);
$check('J4 exactly one claim, one inventory transaction and one unit per player', $claims === $rounds && $inventory === $rounds && $quantity === $rounds, "claims={$claims} inventory={$inventory} quantity={$quantity} (expected {$rounds} each)");
$check('J4 exactly one winner per race; the other two are clean "already claimed" refusals', $okCount($j4) === $rounds && $refused === 2 * $rounds, "ok={$okCount($j4)} already_claimed={$refused}");

// ── J5: استلام الكل المتزامن ──
echo "\nJ5: «استلام الكل» مرتان ومطالبة فردية بالتزامن\n";
$j5 = [];
$j5Users = [];
$j5Expected = 0;

for ($i = 0; $i < $rounds; $i++) {
    $user = $player();
    $completions($user, $quest, 3);
    $points->healFor($user->fresh(), $pass->fresh());
    $tier = (int) UserPassProgress::query()->where('user_id', $user->id)->value('highest_tier');
    $j5Expected += $tier;
    $j5Users[] = $user->id;
    $j5 = array_merge($j5, probe_race([['collect', ["--actor={$user->id}", "--pass={$pass->id}"]], ['collect', ["--actor={$user->id}", "--pass={$pass->id}"]], ['claim', ["--actor={$user->id}", "--pass={$pass->id}", '--reward='.(int) $rewards[2]['free']->id]]]));
}

$j5Claims = DB::table('user_pass_reward_claims')->whereIn('user_id', $j5Users)->count();
$j5Distinct = DB::table('user_pass_reward_claims')->whereIn('user_id', $j5Users)->distinct()->count(DB::raw("CONCAT(user_id, '-', ahjiyat_pass_tier_reward_id)"));
$j5Inventory = DB::table('inventory_transactions')->whereIn('user_id', $j5Users)->count();
$j5Reported = (int) collect($j5)->sum('claimed');

$cleanRaces('J5', $j5);
$check('J5 every claimable reward is claimed exactly once (free tiers reached = claims)', $j5Claims === $j5Expected && $j5Distinct === $j5Claims, "claims={$j5Claims} expected={$j5Expected} distinct={$j5Distinct}");
$check('J5 inventory transactions equal claims (no partial or double grant)', $j5Inventory === $j5Claims, "inventory={$j5Inventory} claims={$j5Claims}");
$check('J5 the workers\' reported successes add up to the claims (no lost update, no phantom)', $j5Reported === $j5Claims, "reported={$j5Reported} claims={$j5Claims}");

// ── J6: استعادة النقاط المزدوجة ──
echo "\nJ6: استعادة نقاط متزامنة (ثلاث عمليات) لإكمالات بلا دفتر\n";
$j6 = [];
$j6Users = [];

for ($i = 0; $i < $rounds; $i++) {
    $user = $player();
    $progressSvc->ensure($user, $pass);
    $completions($user, $quest, 4);
    $j6Users[] = $user->id;
    $j6 = array_merge($j6, probe_race([['heal', ["--actor={$user->id}", "--pass={$pass->id}"]], ['heal', ["--actor={$user->id}", "--pass={$pass->id}"]], ['heal', ["--actor={$user->id}", "--pass={$pass->id}"]]]));
}

$j6Grants = DB::table('pass_point_grants')->whereIn('user_id', $j6Users)->count();
$j6Sum = (int) DB::table('pass_point_grants')->whereIn('user_id', $j6Users)->sum('points');
$j6Points = (int) DB::table('user_pass_progress')->whereIn('user_id', $j6Users)->sum('pass_points');
$j6Granted = (int) collect($j6)->sum('granted');

$cleanRaces('J6', $j6);
$check('J6 exactly one ledger row per completion (4 per player), no duplicate source', $j6Grants === 4 * $rounds && DB::table('pass_point_grants')->whereIn('user_id', $j6Users)->distinct()->count('source_key') === $j6Grants, "grants={$j6Grants}/".(4 * $rounds));
$check('J6 pass_points equals the ledger sum exactly (400 per player)', $j6Points === $j6Sum && $j6Sum === 400 * $rounds, "points={$j6Points} ledger={$j6Sum}");
$check('J6 the workers\' reported grants add up to the ledger (no lost update)', $j6Granted === $j6Grants, "reported={$j6Granted} ledger={$j6Grants}");

// ── J7: استرداد ↔ مطالبات مميزة ──
echo "\nJ7: استرداد شراء محقَّق ومطالبات مميزة ومجانية بالتزامن\n";
$j7 = [];
$j7Orders = [];

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order] = $submitted();
    $premium->verify($finance, $order, 'تحقق تحضيري');
    $completions($user, $quest, 3);
    $points->healFor($user->fresh(), $pass->fresh());
    $j7Orders[] = [$user->id, $order->id];
    $j7 = array_merge($j7, probe_race([
        ['refund', ["--actor={$finance->id}", "--order={$order->id}"]],
        ['claim', ["--actor={$user->id}", "--pass={$pass->id}", '--reward='.(int) $rewards[1]['premium']->id]],
        ['claim', ["--actor={$user->id}", "--pass={$pass->id}", '--reward='.(int) $rewards[2]['premium']->id]],
        ['collect', ["--actor={$user->id}", "--pass={$pass->id}"]],
    ]));
}

$j7Consistent = 0;
$j7NoLate = 0;

foreach ($j7Orders as [$uid, $oid]) {
    $o = DigitalPurchase::query()->find($oid);
    $premiumClaims = DB::table('user_pass_reward_claims')->where('user_id', $uid)->where('track', 'premium');
    $j7Consistent += ($o->status === 'refunded' && (int) $o->refund_kept_items === (clone $premiumClaims)->count()) ? 1 : 0;
    $j7NoLate += (clone $premiumClaims)->where('claimed_at', '>', $o->refunded_at)->count() === 0 ? 1 : 0;
}

$cleanRaces('J7', $j7);
$check('J7 every order ends refunded and refund_kept_items equals the premium claims that really exist', $j7Consistent === $rounds, "consistent={$j7Consistent}/{$rounds}");
$check('J7 no premium reward is claimed after the refund moment', $j7NoLate === $rounds, "clean={$j7NoLate}/{$rounds}");
$check('J7 premium ownership is gone after every refund', UserPassProgress::query()->whereIn('user_id', array_column($j7Orders, 0))->where('ahjiyat_pass_id', $pass->id)->whereNotNull('premium_unlocked_at')->count() === 0);

// ── J8: استثناء مُحقَن ──
echo "\nJ8: استثناء مُحقَن بعد منح المخزون داخل معاملة المطالبة\n";
$injectRounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $injectRounds; $i++) {
    $user = $player();
    $progressSvc->ensure($user, $pass);
    $snap = [DB::table('user_pass_reward_claims')->where('user_id', $user->id)->count(), DB::table('inventory_transactions')->where('user_id', $user->id)->count(), (int) DB::table('user_inventory_items')->where('user_id', $user->id)->sum('quantity')];
    $res = probe_race([['claim-fail', ["--actor={$user->id}", "--pass={$pass->id}", "--reward={$free1}"]]], 1.0);
    $after = [DB::table('user_pass_reward_claims')->where('user_id', $user->id)->count(), DB::table('inventory_transactions')->where('user_id', $user->id)->count(), (int) DB::table('user_inventory_items')->where('user_id', $user->id)->sum('quantity')];
    $check("J8 injected failure #{$i}: the worker failed as injected", ($res[0]['class'] ?? '') === 'RuntimeException' && ($res[0]['outcome'] ?? '') === 'exception', 'worker='.json_encode($res[0], JSON_UNESCAPED_UNICODE));
    $check("J8 injected failure #{$i}: no claim, no inventory transaction, no stock change", $snap === $after, json_encode($after));

    $clean = app(PassClaimService::class)->claim($user->fresh(), $pass->fresh(), $free1);
    $check("J8 injected failure #{$i}: a clean claim afterwards succeeds exactly once", $clean->exists && DB::table('user_pass_reward_claims')->where('user_id', $user->id)->count() === 1 && DB::table('inventory_transactions')->where('user_id', $user->id)->count() === 1);
}

// ── R: المصالحة ──
echo "\nR: المصالحة بعد كل السباقات\n";
$report = app(PassReconciler::class)->run();
$serious = collect($report['findings'])->whereIn('severity', ['critical', 'warning']);
$check('R reconciliation reports no critical or warning drift (points = ledger, tier = thresholds, premium = verified purchase, claims linked)', $serious->isEmpty(), 'findings='.$serious->count().($serious->isEmpty() ? '' : ' first='.json_encode($serious->first(), JSON_UNESCAPED_UNICODE)));
$check('R the Artisan reconcile command exits clean', Artisan::call('ahjiyat-pass:reconcile') === 0);
$check('R one claim per (player, reward) and one ledger row per source across the whole run', DB::table('user_pass_reward_claims')->count() === DB::table('user_pass_reward_claims')->distinct()->count(DB::raw("CONCAT(user_id, '-', ahjiyat_pass_tier_reward_id)")) && DB::table('pass_point_grants')->count() === DB::table('pass_point_grants')->distinct()->count('source_key'));
$check('R no negative or impossible counters', DB::table('user_pass_progress')->where('highest_tier', '<', 0)->count() === 0 && DB::table('user_inventory_items')->where('quantity', '<', 0)->count() === 0);

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

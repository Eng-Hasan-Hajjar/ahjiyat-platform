<?php

/*
|--------------------------------------------------------------------------
| E41 - مسبار التزامن الحقيقي على MySQL (عمليات PHP منفصلة تتسابق فعلًا) للتجارة الاستهلاكية والدفع المستضاف
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، وقفل الصفوف (lockForUpdate) لا أثر له على SQLite، فلا سباق حقيقي بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليات تبدأ في اللحظة نفسها (المزوّد التجريبي sandbox فقط؛ لا شبكة ولا مزوّد حقيقي):
|   P1  بدء checkout مزدوج لطلب واحد                          ← جلسة مزوّد واحدة وبلا استبدال (الجيل الأول)، الجميع يرون الجلسة نفسها
|   P2  حدث «مدفوع» نفسه يصل 4 مرات معًا                      ← تطبيق واحد + 3 duplicate، فتح مميز واحد، حدث واحد، إشعار واحد، صف سجل واحد
|   P3  أحداث مدفوع/فاشل/ملغى متزامنة بترتيب عشوائي             ← الحالة تتقدّم صعودًا فقط: النتيجة محقَّق وprovider_status=paid دائمًا
|   P4  تأكيد ↔ تأكيد ↔ تجاهل ↔ استرداد لدفعة معلَّمة            ← قرار واحد فقط يستقر؛ لا استرداد عند المزوّد إلا إن كان القرار النهائي «استرداد»
|   P5  استرداد ↔ استرداد ↔ حدث refunded من المزوّد            ← استرداد واحد، سحب المميز مرة واحدة، حدث/إشعار واحد
|   P6  حدث مدفوع ↔ انتهاء الصلاحية                            ← إما محقَّق، أو منتهٍ + علم دفعة متأخرة؛ لا ضياع دفعة ولا حالة معلّقة
|   P7  استثناء مُحقَن عند إدراج سجل الحدث                      ← تراجع كامل (لا أثر ولا صف سجل ولا مميز)، ثم تسليم نظيف ينجح مرة واحدة
|   R   مصالحة التجارة بعد السباقات = لا فروق (commerce:reconcile) وسلامة العدّادات
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - كاش الملفات المشترك بين العمليات (حالة المحاكاة) في مجلد مؤقت خاص بالمسبار فقط؛ لا يمسّ كاش التطبيق.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط. لا مزوّد حقيقي ولا اتصال شبكة.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/consumer-commerce-operations-runbook.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e41/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e41/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 4)
*/

use App\Models\AhjiyatPassTier;
use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Models\QuestDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserPassProgress;
use App\Services\AhjiyatPass\PassBuilderService;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceWebhookException;
use App\Services\Commerce\CommerceWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env، وتُورَّث للعمليات العاملة. الكاش ملفات (يُشارك بين العمليات: حالة المحاكاة) في مجلد خاص بالمسبار.
$probeCacheDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'e41-probe-cache';

foreach ([
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'AHJIYAT_PASS_ENABLED' => 'true',
    'COMMERCE_ENABLED' => 'true', 'COMMERCE_DEFAULT_PROVIDER' => 'sandbox', 'COMMERCE_FALLBACK_PROVIDER' => 'manual', 'COMMERCE_SANDBOX_ENABLED' => 'true',
    'COMMERCE_SANDBOX_ACCEPT_WEBHOOKS' => 'true', 'COMMERCE_WEBHOOKS_ENABLED' => 'true', 'COMMERCE_NOTIFICATIONS_ENABLED' => 'true',
] as $envKey => $envValue) {
    putenv("{$envKey}={$envValue}");
    $_ENV[$envKey] = $_SERVER[$envKey] = $envValue;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['cache.default' => 'file', 'cache.stores.file.path' => $probeCacheDir]);
app('cache')->forgetDriver('file');

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
        $order = isset($args['order']) ? DigitalPurchase::query()->find((int) $args['order']) : null;
        $payload = isset($args['payload']) ? (string) base64_decode((string) $args['payload'], true) : '';
        $headers = isset($args['headers']) ? (array) json_decode((string) base64_decode((string) $args['headers'], true), true) : [];

        while (microtime(true) < $at) {
            usleep(200);
        }

        // تأخير اختياري بعد الحاجز (ميلي ثانية) لتنويع التقاطعات الزمنية بين العمليات في السباق نفسه.
        if (isset($args['delay'])) {
            usleep(max(0, min(500, (int) $args['delay'])) * 1000);
        }

        $started = microtime(true);

        if ($kind === 'webhook-fail') {
            // يحقن استثناءً عند إدراج سجل الحدث (آخر خطوة داخل معاملة الأثر): يجب أن يتراجع الأثر كاملًا.
            CommerceProviderEvent::creating(function (): void {
                throw new RuntimeException('probe-injected-failure');
            });
        }

        if ($kind === 'checkout') {
            $begun = app(CommerceCheckoutService::class)->start($actor, $order);
            $result = ['outcome' => 'ok', 'session' => $begun['session_id'], 'reused' => (bool) $begun['reused']];
        } elseif ($kind === 'webhook' || $kind === 'webhook-fail') {
            $r = app(CommerceWebhookService::class)->handle('sandbox', $payload, $headers);
            $result = ['outcome' => 'ok', 'result' => $r['result'], 'detail' => $r['detail'] ?? null];
        } elseif ($kind === 'verify') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->verify($actor, $order, 'تأكيد من مسبار التزامن')->status, 'action' => 'verify'];
        } elseif ($kind === 'dismiss') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->dismissReview($actor, $order, 'تجاهل من مسبار التزامن')->status, 'action' => 'dismiss'];
        } elseif ($kind === 'refund-unmatched') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->refundUnmatched($actor, $order, 'استرداد من مسبار التزامن')->status, 'action' => 'refund-unmatched'];
        } elseif ($kind === 'refund') {
            $result = ['outcome' => 'ok', 'status' => app(PassPremiumService::class)->refund($actor, $order, 'user_request', 'استرداد من مسبار التزامن', true)->status, 'action' => 'refund'];
        } elseif ($kind === 'expire') {
            $result = ['outcome' => 'ok', 'expired' => app(PassPremiumService::class)->expireStale(), 'action' => 'expire'];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof PassException || $e instanceof CommerceException || $e instanceof CommerceWebhookException;
        // لا نطبع رسائل SQL (قد تحمل قيم): اسم الفهرس المخالَف فقط لأخطاء 1062، ورمز السبب للاستثناءات الدومينية.
        $detail = '';
        if ($code === 1062 && preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $detail = $m[1];
        } elseif ($domain) {
            $detail = (string) $e->reason;
        } elseif ($e instanceof RuntimeException && $e->getMessage() === 'probe-injected-failure') {
            $detail = 'injected';
        }
        $result = ['outcome' => $domain ? 'refused' : 'exception', 'class' => class_basename($e), 'mysql' => $code, 'detail' => $detail, 'action' => $kind];
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

echo '== E41 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh) وكاش المسبار...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);
Cache::flush(); // مجلد كاش المسبار الخاص فقط (ليس كاش التطبيق).

$verified = function (User $u): User {
    $u->forceFill(['email_verified_at' => now()])->save();

    return $u->fresh();
};
$player = fn () => $verified(User::factory()->create());
$admin = function (string $tag) use ($verified): User {
    $u = $verified(User::factory()->create(['email' => "probe-{$tag}@commerce-concurrency.test"]));
    $u->syncRoles(['administrator']);

    return $u->fresh();
};

$finance = $admin('finance');
$finance2 = $admin('finance2');
$premium = app(PassPremiumService::class);
$checkout = app(CommerceCheckoutService::class);
$sandbox = app(CommercePaymentProviderRegistry::class)->get('sandbox');

$builder = app(PassBuilderService::class);
$pass = $builder->createDraft($finance, [
    'internal_key' => 'probe-commerce', 'name' => 'مسار مسبار التجارة',
    'starts_at' => now()->subDays(10)->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(20)->format('Y-m-d H:i:s'), 'claim_grace_days' => 5,
    'premium_enabled' => true, 'premium_price_minor' => 500, 'currency' => 'USD',
]);
$builder->generateTiers($finance, $pass, 4, 100, 0, 0);

foreach (AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->id)->orderBy('tier_number')->get() as $tier) {
    $builder->setTierReward($finance, $tier, 'free', StoreItem::factory()->cosmeticTitle('لقب مجاني '.$tier->tier_number)->create());
    $builder->setTierReward($finance, $tier, 'premium', StoreItem::factory()->cosmeticTitle('لقب مميز '.$tier->tier_number)->create());
}

$builder->attachMission($finance, $pass, QuestDefinition::factory()->daily(1)->create(), 'easy', ['points' => 100, 'max_completions' => 10, 'sort_order' => 0]);
app(PassLifecycleService::class)->schedule($finance, $pass->fresh());
$pass = $pass->fresh();

/** طلب مستضاف جاهز (checkout بدأ). @return array{0: User, 1: DigitalPurchase, 2: string} */
$hosted = function () use ($player, $premium, $checkout, $pass): array {
    $user = $player();
    $order = $premium->request($user, $pass);
    $started = $checkout->start($user, $order);

    return [$user, $order->fresh(), $started['session_id']];
};
/** طلب مفتوح بلا checkout (لسباق بدء الدفع). */
$open = function () use ($player, $premium, $pass): array {
    $user = $player();

    return [$user, $premium->request($user, $pass)];
};
$enc = fn (array $ev): array => ['--payload='.base64_encode($ev['payload']), '--headers='.base64_encode((string) json_encode($ev['headers']))];
$notes = fn (User $u, string $type): int => DatabaseNotification::query()->where('notifiable_id', $u->id)->get()->filter(fn ($n) => ($n->data['type'] ?? null) === $type)->count();
$ledger = fn (DigitalPurchase $o): int => CommerceProviderEvent::query()->where('digital_purchase_id', $o->id)->count();
$events = fn (DigitalPurchase $o, string $event): int => DB::table('digital_purchase_events')->where('digital_purchase_id', $o->id)->where('event', $event)->count();
$unlocked = fn (User $u): bool => UserPassProgress::query()->where('user_id', $u->id)->where('ahjiyat_pass_id', $pass->id)->whereNotNull('premium_unlocked_at')->exists();

$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r))->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
$cleanRaces = function (string $label, array $rs) use ($check, $crashes, $lockErrors, $unexpected, $refusals): void {
    $check("{$label} no worker crash, no deadlock (1213) / lock timeout (1205)", $crashes($rs) === 0 && $lockErrors($rs) === 0, 'crash='.$crashes($rs).' lock-errors='.$lockErrors($rs));
    $check("{$label} no unexpected exception (the loser is a clean domain refusal or idempotent)", $unexpected($rs) === 0, 'unexpected='.$unexpected($rs).' clean-refusals='.$refusals($rs));
};

// ── P1: بدء checkout مزدوج ──
echo "\nP1: بدء دفع مستضاف متزامن لطلب واحد\n";
$p1 = [];
$p1Single = 0;
$p1Consistent = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order] = $open();
    $rs = probe_race(array_fill(0, 4, ['checkout', ["--actor={$user->id}", "--order={$order->id}"]]));
    $p1 = array_merge($p1, $rs);
    $fresh = $order->fresh();
    $sessions = collect($rs)->where('outcome', 'ok')->pluck('session')->unique()->values();
    $p1Single += ($sessions->count() === 1 && $fresh->provider_session_id === $sessions->first() && (int) $fresh->checkout_generation === 0) ? 1 : 0;
    $p1Consistent += (DigitalPurchase::query()->where('provider', 'sandbox')->where('provider_session_id', $fresh->provider_session_id)->count() === 1 && $fresh->status === 'awaiting_payment') ? 1 : 0;
}

$cleanRaces('P1', $p1);
$check('P1 every order ends with exactly one provider session and still the first generation (0), and all winners saw that session', $p1Single === $rounds, "single={$p1Single}/{$rounds}");
$check('P1 the session reference is unique across purchases and the order stays awaiting payment', $p1Consistent === $rounds, "consistent={$p1Consistent}/{$rounds}");

// ── P2: الحدث نفسه 4 مرات ──
echo "\nP2: حدث «مدفوع» نفسه يصل من أربع عمليات معًا\n";
$p2 = [];
$p2Ok = 0;
$p2Rows = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'p2-'.$i);
    $rs = probe_race(array_fill(0, 4, ['webhook', $enc($ev)]));
    $p2 = array_merge($p2, $rs);
    $results = collect($rs)->pluck('result')->countBy();
    $fresh = $order->fresh();
    $p2Rows += $ledger($fresh) === 1 ? 1 : 0;
    $p2Ok += ((int) ($results['applied'] ?? 0) === 1 && (int) ($results['duplicate'] ?? 0) === 3 && $fresh->status === 'verified' && $events($fresh, 'verified') === 1 && $unlocked($user) && $notes($user, 'pass_premium_unlocked') === 1) ? 1 : 0;
}

$cleanRaces('P2', $p2);
$check('P2 per round: one applied + three duplicates, one verification event, premium unlocked once, one notification', $p2Ok === $rounds, "ok={$p2Ok}/{$rounds}");
$check('P2 the ledger holds exactly one row per delivered event id (unique(provider, provider_event_id))', $p2Rows === $rounds, "rows={$p2Rows}/{$rounds}");

// ── P3: مدفوع/فاشل/ملغى متزامنة ──
echo "\nP3: أحداث مدفوع وفاشل وملغى تصل معًا\n";
$p3 = [];
$p3Ok = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order, $session] = $hosted();
    $paid = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'p3p-'.$i);
    $failed = $sandbox->signedEvent($session, 'payment.failed', [], 'p3f-'.$i);
    $cancelled = $sandbox->signedEvent($session, 'payment.cancelled', [], 'p3c-'.$i);
    $specs = [['webhook', $enc($failed)], ['webhook', $enc($paid)], ['webhook', $enc($cancelled)]];
    shuffle($specs);
    $rs = probe_race($specs);
    $p3 = array_merge($p3, $rs);
    $fresh = $order->fresh();
    $p3Ok += ($fresh->status === 'verified' && $fresh->provider_status === 'paid' && $ledger($fresh) === 3 && $unlocked($user) && $notes($user, 'pass_premium_unlocked') === 1) ? 1 : 0;
}

$cleanRaces('P3', $p3);
$check('P3 whatever the arrival order the purchase ends verified with provider_status=paid (status only moves forward) and all three events are on the ledger', $p3Ok === $rounds, "ok={$p3Ok}/{$rounds}");

// ── P4: قرارات المراجعة المتزامنة ──
echo "\nP4: تأكيد ↔ تأكيد ↔ تجاهل ↔ استرداد لدفعة معلَّمة\n";
$p4 = [];
$p4One = 0;
$p4Consistent = 0;
$p4NoStrayRefund = 0;
$resolutions = [];

for ($i = 0; $i < $rounds * 2; $i++) {
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, ['amount_minor' => 499], 'p4-'.$i);
    $first = app(CommerceWebhookService::class)->handle('sandbox', $ev['payload'], $ev['headers']);

    if ($first['result'] !== 'needs_review') {
        $check("P4 round {$i} setup: the mismatched payment raised a review flag", false, json_encode($first));

        continue;
    }

    $specs = [['verify', ["--actor={$finance->id}", "--order={$order->id}"]], ['verify', ["--actor={$finance2->id}", "--order={$order->id}"]], ['dismiss', ["--actor={$finance->id}", "--order={$order->id}"]], ['refund-unmatched', ["--actor={$finance2->id}", "--order={$order->id}"]]];
    shuffle($specs);
    $rs = probe_race($specs);
    $p4 = array_merge($p4, $rs);
    $fresh = $order->fresh();
    $resolution = $fresh->review_resolution;
    $resolutions[$resolution ?? 'none'] = ($resolutions[$resolution ?? 'none'] ?? 0) + 1;
    $refundRecord = Cache::has('commerce:sandbox:refund:refund:'.$fresh->public_id);
    $okActions = collect($rs)->where('outcome', 'ok')->pluck('action')->all();

    $p4One += ($fresh->review_resolved_at !== null && in_array($resolution, ['verified', 'dismissed', 'refunded'], true)) ? 1 : 0;
    $p4Consistent += match ($resolution) {
        'verified' => $fresh->status === 'verified' && $unlocked($user) && $fresh->provider_refund_reference === null,
        'dismissed' => $fresh->status === 'awaiting_payment' && ! $unlocked($user) && $fresh->provider_refund_reference === null,
        'refunded' => $fresh->status === 'awaiting_payment' && ! $unlocked($user) && $fresh->provider_refund_reference !== null && $fresh->provider_status === 'refunded',
        default => false,
    } ? 1 : 0;
    // لا استرداد عند المزوّد إلا إن كان القرار النهائي «استرداد»؛ وإن كان «استرداد» فالمنفِّذ الوحيد ناجح واحد.
    $p4NoStrayRefund += ($refundRecord === ($resolution === 'refunded') && count($okActions) >= 1) ? 1 : 0;
}

$p4Total = $rounds * 2;
$cleanRaces('P4', $p4);
$check('P4 exactly one resolution settles per flagged payment (verified | dismissed | refunded)', $p4One === $p4Total, "settled={$p4One}/{$p4Total} outcomes=".json_encode($resolutions));
$check('P4 the settled state is coherent: verified ⇒ unlocked and no refund; dismissed ⇒ locked and no refund; refunded ⇒ locked and a provider refund reference', $p4Consistent === $p4Total, "coherent={$p4Consistent}/{$p4Total}");
$check('P4 a refund exists at the provider only when the final resolution is "refunded" (no refunded-but-unlocked payment)', $p4NoStrayRefund === $p4Total, "ok={$p4NoStrayRefund}/{$p4Total}");

// ── P5: استرداد متزامن ──
echo "\nP5: استرداد ↔ استرداد ↔ حدث refunded من المزوّد\n";
$p5 = [];
$p5Ok = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'p5p-'.$i);
    app(CommerceWebhookService::class)->handle('sandbox', $ev['payload'], $ev['headers']);
    $refundedEvent = $sandbox->signedEvent($session, 'payment.refunded', [], 'p5r-'.$i);
    $specs = [['refund', ["--actor={$finance->id}", "--order={$order->id}"]], ['refund', ["--actor={$finance2->id}", "--order={$order->id}"]], ['webhook', $enc($refundedEvent)]];
    shuffle($specs);
    $rs = probe_race($specs);
    $p5 = array_merge($p5, $rs);
    $fresh = $order->fresh();
    $p5Ok += ($fresh->status === 'refunded' && ! $unlocked($user) && $events($fresh, 'refunded') === 1 && $notes($user, 'commerce_refunded') === 1 && $fresh->provider_status === 'refunded') ? 1 : 0;
}

$cleanRaces('P5', $p5);
$check('P5 per round: refunded once, premium withdrawn, one refund event, one refund notification', $p5Ok === $rounds, "ok={$p5Ok}/{$rounds}");

// ── P6: مدفوع ↔ انتهاء ──
echo "\nP6: حدث مدفوع ↔ انتهاء صلاحية الطلب\n";
$p6 = [];
$p6Ok = 0;
$p6Outcomes = [];

for ($i = 0; $i < $rounds * 2; $i++) {
    [$user, $order, $session] = $hosted();
    DB::table('digital_purchases')->where('id', $order->id)->update(['expires_at' => now()->subMinute()]);
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'p6-'.$i);
    $delay = [0, 15, 40, 90][$i % 4]; // نُبطئ الانتهاء أحيانًا ليربح الحدث المدفوع في بعض الجولات (الذراعان معًا مغطّاة).
    $specs = [['webhook', $enc($ev)], ['expire', ["--actor={$finance->id}", "--delay={$delay}"]]];
    shuffle($specs);
    $rs = probe_race($specs);
    $p6 = array_merge($p6, $rs);
    $fresh = $order->fresh();
    $p6Outcomes[$fresh->status] = ($p6Outcomes[$fresh->status] ?? 0) + 1;
    $p6Ok += (($fresh->status === 'verified' && ! $fresh->needsReview() && $unlocked($user))
        || ($fresh->status === 'expired' && $fresh->review_flag === 'late_paid_expired' && $fresh->review_resolved_at === null && $fresh->provider_status === 'paid' && ! $unlocked($user))) ? 1 : 0;
}

$cleanRaces('P6', $p6);
$check('P6 no payment is lost: either verified and unlocked, or expired with a late-payment review flag awaiting a human (never stuck awaiting)', $p6Ok === $rounds * 2, 'ok='.$p6Ok.'/'.($rounds * 2).' outcomes='.json_encode($p6Outcomes));

// ── P7: استثناء مُحقَن ──
echo "\nP7: استثناء مُحقَن عند إدراج سجل الحدث داخل معاملة الأثر\n";
$p7Rolled = 0;
$p7Clean = 0;
$p7Injected = 0;
$injectRounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $injectRounds; $i++) {
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'p7-'.$i);
    $res = probe_race([['webhook-fail', $enc($ev)]], 1.0);
    $fresh = $order->fresh();
    $p7Injected += (($res[0]['outcome'] ?? '') === 'exception' && ($res[0]['detail'] ?? '') === 'injected') ? 1 : 0;
    $p7Rolled += ($fresh->status === 'awaiting_payment' && $fresh->provider_payment_reference === null && $ledger($fresh) === 0 && ! $unlocked($user) && $notes($user, 'pass_premium_unlocked') === 0 && $events($fresh, 'verified') === 0) ? 1 : 0;

    $clean = app(CommerceWebhookService::class)->handle('sandbox', $ev['payload'], $ev['headers']);
    $again = $order->fresh();
    $p7Clean += ($clean['result'] === 'applied' && $again->status === 'verified' && $ledger($again) === 1 && $unlocked($user) && $notes($user, 'pass_premium_unlocked') === 1) ? 1 : 0;
}

$check('P7 the worker failed as injected', $p7Injected === $injectRounds, "injected={$p7Injected}/{$injectRounds}");
$check('P7 the failed delivery left no trace: still awaiting, no ledger row, no verification event, no unlock, no notification', $p7Rolled === $injectRounds, "rolled-back={$p7Rolled}/{$injectRounds}");
$check('P7 re-delivery of the same event afterwards applies exactly once', $p7Clean === $injectRounds, "applied-once={$p7Clean}/{$injectRounds}");

// ── R: المصالحة ──
echo "\nR: المصالحة بعد كل السباقات\n";
$exit = Artisan::call('commerce:reconcile', ['--json' => true, '--limit' => 5000]);
$report = json_decode(Artisan::output(), true) ?: [];
$serious = collect($report['findings'] ?? [])->whereIn('severity', ['critical', 'warning']);
$check('R commerce reconciliation reports no critical or warning drift', $exit === 0 && $serious->isEmpty(), 'exit='.$exit.' findings='.$serious->count().($serious->isEmpty() ? '' : ' first='.json_encode($serious->first(), JSON_UNESCAPED_UNICODE)));
$check('R one ledger row per provider event id and one purchase per provider session', CommerceProviderEvent::query()->count() === CommerceProviderEvent::query()->distinct()->count('provider_event_id')
    && DigitalPurchase::query()->whereNotNull('provider_session_id')->count() === DigitalPurchase::query()->whereNotNull('provider_session_id')->distinct()->count('provider_session_id'));
$check('R premium ownership matches verified purchases exactly (no unlock without a verified purchase, no verified purchase without unlock)',
    UserPassProgress::query()->where('ahjiyat_pass_id', $pass->id)->whereNotNull('premium_unlocked_at')->count() === DigitalPurchase::query()->where('status', 'verified')->count());
$check('R no purchase is verified and refunded at once, and no refunded purchase keeps premium', DB::table('digital_purchases')->where('status', 'refunded')->whereIn('user_id', UserPassProgress::query()->whereNotNull('premium_unlocked_at')->pluck('user_id'))->count() === 0);

Cache::flush();

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

<?php

/*
|--------------------------------------------------------------------------
| E43 - مسبار التزامن الحقيقي على MySQL (عمليات PHP منفصلة تتسابق فعلًا) لسوق المبدعين المميز
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، وقفل الصفوف (lockForUpdate) لا أثر له على SQLite، فلا سباق حقيقي بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليات تبدأ في اللحظة نفسها (المزوّد التجريبي sandbox فقط؛ لا شبكة ولا مزوّد حقيقي):
|   Q1  تحقق مزدوج للشراء نفسه (الحدث نفسه ×4، وأربعة أحداث مدفوعة مختلفة)   ← وصول واحد، بيع واحد، قيد دائن واحد، إشعار واحد لكل طرف
|   Q2  دفعة متأخرة على طلب منتهٍ (يحقّقها المراجع) ↔ دفع طلب جديد للسلسلة نفسها ← وصول واحد فقط لـ(مستخدم، سلسلة)، بيع واحد، والخاسر محجوب للمراجعة لا مفقود
|   Q3  حدث مدفوع ↔ حدث refunded ↔ تكرار                                    ← حالة متسقة: محقَّق(وصول+بيع) | مسترد(وصول مسحوب+بيع معكوس) | معلَّق بلا وصول ولا بيع
|   Q4  استرداد إداري ×2 ↔ حدث refunded من المزوّد                          ← استرداد واحد، قيد عكس واحد، سحب الوصول مرة واحدة، إشعاران فقط
|   Q5  عكس الحصة ↔ خصم إداري متزامن يستهلك المتاح                           ← لا رصيد سالب أبدًا؛ إما عُكست الحصة أو عُلّم البيع needs_finance_review (لا الاثنان معًا)
|   Q6  حدث مدفوع ↔ إيقاف العرض (مبدع/إدارة)                                ← إما بيع قبل الإيقاف أو حجب للمراجعة؛ ولا بيع بعد الإيقاف
|   Q7  تفعيل ↔ إيقاف العرض                                                ← عرض حيّ واحد، first_activated_at يُكتب مرة واحدة
|   Q8  استثناء مُحقَن داخل معاملة التحقق (بعد منح الوصول)                    ← تراجع كامل (لا وصول ولا بيع ولا قيد ولا إشعار)، ثم تسليم نظيف يطبَّق مرة واحدة
|   R   مصالحة السوق المميز ومصالحة التجارة بعد السباقات = لا فروق، وفحوص سلامة نهائية
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - كاش الملفات المشترك بين العمليات (حالة المحاكاة) في مجلد مؤقت خاص بالمسبار فقط؛ لا يمسّ كاش التطبيق.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط. لا مزوّد حقيقي ولا اتصال شبكة.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/creator-premium-operations-runbook.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e43/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e43/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 4)
*/

use App\Models\CommerceProviderEvent;
use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\CommerceProductRegistry;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceWebhookService;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorLedgerService;
use App\Services\CreatorFinance\CreatorMoney;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorPremium\CreatorDirectSaleService;
use App\Services\CreatorPremium\CreatorPremiumNotifier;
use App\Services\CreatorPremium\CreatorPremiumPolicy;
use App\Services\CreatorPremium\CreatorPremiumRefundService;
use App\Services\CreatorPremium\CreatorSeriesAccessProductHandler;
use App\Services\CreatorPremium\CreatorSeriesOfferService;
use App\Services\CreatorPremium\CreatorSeriesPurchaseService;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\OperationalAuditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env، وتُورَّث للعمليات العاملة. الكاش ملفات (يُشارك بين العمليات: حالة المحاكاة) في مجلد خاص بالمسبار.
$probeCacheDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'e43-probe-cache';

foreach ([
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'CREATOR_PREMIUM_ENABLED' => 'true', 'CREATOR_MONETIZATION_ENABLED' => 'true',
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
        $purchase = isset($args['purchase']) ? DigitalPurchase::query()->find((int) $args['purchase']) : null;
        $offerId = isset($args['offer']) ? (string) $args['offer'] : null;
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
            // يستبدل معالج المنتج بنسخة تُلقي استثناءً بعد منح الوصول مباشرة (داخل الـsavepoint نفسها) فيجب أن يتراجع كل شيء.
            $failing = new class(app(CreatorLedgerService::class), app(CreatorPremiumPolicy::class), app(OperationalAuditService::class)) extends CreatorDirectSaleService
            {
                public function record(DigitalPurchase $purchase, CreatorSeries $series, CreatorSeriesSaleOffer $offer, CreatorEarningAccount $lockedAccount): \App\Models\CreatorDirectSale
                {
                    throw new RuntimeException('probe-injected-failure');
                }
            };
            app(CommerceProductRegistry::class)->register(new CreatorSeriesAccessProductHandler($failing, app(CreatorPremiumPolicy::class), app(CreatorSeriesVisibilityService::class), app(CreatorPremiumNotifier::class)));
        }

        if ($kind === 'webhook' || $kind === 'webhook-fail') {
            $r = app(CommerceWebhookService::class)->handle('sandbox', $payload, $headers);
            $result = ['outcome' => 'ok', 'result' => $r['result'], 'detail' => $r['detail'] ?? null];
        } elseif ($kind === 'refund') {
            $result = ['outcome' => 'ok', 'status' => app(CreatorPremiumRefundService::class)->refund($actor, $purchase->public_id, 'user_request', 'استرداد من مسبار التزامن', true)->status, 'action' => 'refund'];
        } elseif ($kind === 'verify-reviewed') {
            $result = ['outcome' => 'ok', 'status' => app(CreatorPremiumRefundService::class)->verifyReviewed($actor, $purchase->public_id, 'تحقق من مسبار التزامن')->status, 'action' => 'verify-reviewed'];
        } elseif ($kind === 'debit') {
            $entry = app(CreatorLedgerService::class)->adjust(CreatorEarningAccount::query()->findOrFail((int) $args['account']), $actor, 'debit', (string) $args['amount'], 'correction', 'خصم متزامن من مسبار التزامن', 'probe-'.bin2hex(random_bytes(6)));
            $result = ['outcome' => 'ok', 'entry' => $entry->getKey(), 'action' => 'debit'];
        } elseif ($kind === 'offer-pause') {
            $result = ['outcome' => 'ok', 'status' => app(CreatorSeriesOfferService::class)->pause($actor, (string) $offerId)->status, 'action' => 'offer-pause'];
        } elseif ($kind === 'offer-pause-admin') {
            $result = ['outcome' => 'ok', 'status' => app(CreatorSeriesOfferService::class)->pauseByAdmin($actor, (string) $offerId, 'إيقاف إداري من مسبار التزامن')->status, 'action' => 'offer-pause-admin'];
        } elseif ($kind === 'offer-activate') {
            $result = ['outcome' => 'ok', 'status' => app(CreatorSeriesOfferService::class)->activate($actor, (string) $offerId)->status, 'action' => 'offer-activate'];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof CreatorException || $e instanceof CommerceException || $e instanceof CreatorFinanceException;
        // لا نطبع رسائل SQL (قد تحمل قيم): اسم الفهرس المخالَف فقط لأخطاء 1062، ورمز السبب للاستثناءات الدومينية.
        $detail = '';
        if ($code === 1062 && preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $detail = $m[1];
        } elseif ($domain) {
            $detail = property_exists($e, 'reason') ? (string) $e->reason : 'domain';
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

echo '== E43 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
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
    $u = $verified(User::factory()->create(['email' => "probe-{$tag}@premium-concurrency.test"]));
    $u->syncRoles(['administrator']);

    return $u->fresh();
};

$finance = $admin('finance');
$finance2 = $admin('finance2');
$reviewer = $verified(User::factory()->create(['email' => 'probe-reviewer@premium-concurrency.test']));
$reviewer->syncRoles(['moderator']);

// ── مشهد المبدع: ملف معتمَد، ثلاثة أعمال منشورة، تسييل مفعَّل، سلسلة منشورة، عرض نشط بسعر 5.00 USD ──
$category = PuzzleCategory::query()->create(['name' => 'مسبار', 'slug' => 'probe', 'sort_order' => 1, 'is_active' => true]);
$applications = app(CreatorApplicationService::class);
$puzzles = app(CreatorPuzzleService::class);
$reviewPuzzle = app(CreatorReviewService::class);
$ledger = app(CreatorLedgerService::class);
$offers = app(CreatorSeriesOfferService::class);
$purchases = app(CreatorSeriesPurchaseService::class);
$refunds = app(CreatorPremiumRefundService::class);
$checkout = app(CommerceCheckoutService::class);
$sandbox = app(CommercePaymentProviderRegistry::class)->get('sandbox');

$creator = $verified(User::factory()->create(['email' => 'probe-creator@premium-concurrency.test']));
$profile = $applications->apply($creator, 'مبدع مسبار سوق مميز — نبذة كافية الطول.');
$applications->approve($profile, $reviewer, 'مرحبًا بك مبدعًا.');
$profile = $profile->fresh();
$account = app(CreatorMonetizationService::class)->enable($profile, $finance);

$workIds = [];

for ($i = 1; $i <= 3; $i++) {
    $draft = $puzzles->createDraft($creator, ['kind' => 'text', 'difficulty' => 'easy', 'title' => 'لغز مسبار '.$i, 'puzzle_category_id' => $category->id, 'prompt' => 'ما الشيء الذي يكتب ولا يقرأ؟', 'hint' => 'تجده على المكتب', 'answer' => 'القلم']);
    $reviewPuzzle->approve($puzzles->submit($creator, $draft->work->public_id), $reviewer, 'معتمدة.');
    $workIds[] = $draft->work->public_id;
}

$seriesService = app(CreatorSeriesService::class);
$revision = $seriesService->createDraft($creator, ['title' => 'سلسلة مسبار مميزة', 'description' => 'سلسلة لاختبار التزامن.', 'items' => $workIds]);
$revision = $seriesService->submit($creator, $revision->series()->value('public_id'));
app(CreatorSeriesReviewService::class)->approve($revision, $reviewer);
$series = $revision->series()->first();
$offer = $offers->createDraft($creator, $series->public_id, '5.00');
$offer = $offers->activate($creator, $offer->public_id);
$firstActivatedAt = (string) DB::table('creator_series_sale_offers')->where('id', $offer->id)->value('first_activated_at');
$seriesId = (int) $series->id;

// ── مساعدات ──
$offerRow = fn () => DB::table('creator_series_sale_offers')->where('id', $offer->id)->first();
/** يعيد العرض نشطًا بين الجولات (بقرار الإدارة إن كان موقوفًا). */
$resetOffer = function () use ($offerRow, $offers, $finance, $offer): void {
    if (($offerRow()->status ?? '') !== 'active') {
        $offers->resumeByAdmin($finance, $offer->public_id, 'استئناف بين الجولات من المسبار');
    }
};
$avail = fn (): int => (int) CreatorEarningAccount::query()->find($account->id)->available_minor;
/** يفرّغ المتاح بتعديل إداري رسمي (بين الجولات) ليبدأ سباق العكس من رصيد معروف. */
$drain = function () use ($avail, $ledger, $account, $finance): void {
    $a = $avail();

    if ($a > 0) {
        $ledger->adjust($account->fresh(), $finance, 'debit', CreatorMoney::toMajorText($a, 'USD'), 'correction', 'تفريغ بين الجولات في مسبار التزامن', 'drain-'.bin2hex(random_bytes(5)));
    }
};
/** طلب مستضاف جاهز (checkout بدأ). @return array{0: User, 1: DigitalPurchase, 2: string} */
$hosted = function () use ($player, $purchases, $checkout, $series): array {
    $user = $player();
    $order = $purchases->request($user, $series->fresh());
    $started = $checkout->start($user, $order);

    return [$user, $order->fresh(), $started['session_id']];
};
$enc = fn (array $ev): array => ['--payload='.base64_encode($ev['payload']), '--headers='.base64_encode((string) json_encode($ev['headers']))];
$notes = fn (User $u, string $type): int => DatabaseNotification::query()->where('notifiable_id', $u->id)->get()->filter(fn ($n) => ($n->data['type'] ?? null) === $type)->count();
$accessRows = fn (User $u) => DB::table('creator_series_accesses')->where('user_id', $u->id)->where('creator_series_id', $seriesId)->get();
$saleOf = fn (DigitalPurchase $p) => DB::table('creator_direct_sales')->where('digital_purchase_id', $p->id)->first();
$entryCount = fn (DigitalPurchase $p, string $suffix): int => DB::table('creator_earning_entries')->where('source_key', 'direct_sale:'.$p->id.':'.$suffix)->count();
$ledgerEvents = fn (DigitalPurchase $o): int => CommerceProviderEvent::query()->where('digital_purchase_id', $o->id)->count();
$creatorNotes = fn (string $type): int => $notes($creator, $type);

$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r))->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
$cleanRaces = function (string $label, array $rs) use ($check, $crashes, $lockErrors, $unexpected, $refusals): void {
    $check("{$label} no worker crash, no deadlock (1213) / lock timeout (1205)", $crashes($rs) === 0 && $lockErrors($rs) === 0, 'crash='.$crashes($rs).' lock-errors='.$lockErrors($rs));
    $check("{$label} no unexpected exception (the loser is a clean domain refusal or idempotent)", $unexpected($rs) === 0, 'unexpected='.$unexpected($rs).' clean-refusals='.$refusals($rs));
};

/**
 * اتساق حالة شراء مع جداول E43: محقَّق ⇒ وصول فعّال مصدره هذا الشراء + بيع credited + قيد دائن واحد؛
 * مسترد ⇒ الوصول مسحوب + البيع refunded بقيد عكس واحد؛ غير ذلك ⇒ لا وصول ولا بيع ولا قيد لهذا الشراء.
 */
$coherent = function (User $user, DigitalPurchase $p) use ($accessRows, $saleOf, $entryCount): bool {
    $p = $p->fresh();
    $sale = $saleOf($p);
    $mine = $accessRows($user)->where('source_digital_purchase_id', $p->id);
    $credit = $entryCount($p, 'credit');
    $reversal = $entryCount($p, 'reversal');

    return match ($p->status) {
        'verified' => $mine->count() === 1 && $mine->first()->revoked_at === null && $sale !== null && $sale->status === 'credited' && $credit === 1 && $reversal === 0,
        'refunded' => ($sale === null) === false && $credit === 1 && ($mine->isEmpty() || $mine->first()->revoked_at !== null) && (($sale->status === 'refunded' && $reversal === 1) || ($sale->status === 'needs_finance_review' && $reversal === 0)),
        default => $mine->isEmpty() && $sale === null && $credit === 0 && $reversal === 0,
    };
};

// ── Q1: تحقق مزدوج للشراء نفسه ──
echo "\nQ1: التحقق من الشراء نفسه من أربع عمليات معًا (الحدث نفسه ×4 / أربعة أحداث مختلفة)\n";
$q1 = [];
$q1Ok = 0;
$q1Entries = 0;
$q1Notes = 0;
$q1Applied = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$user, $order, $session] = $hosted();
    $before = $avail();
    $creatorBefore = $creatorNotes('creator_premium_sale');
    $first = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'q1a-'.$i);

    if ($i % 2 === 0) {
        $specs = array_fill(0, 4, ['webhook', $enc($first)]);
    } else {
        $specs = [['webhook', $enc($first)]];

        for ($k = 1; $k < 4; $k++) {
            $specs[] = ['webhook', $enc($sandbox->signedEvent($session, 'payment.paid', [], "q1b-{$i}-{$k}"))];
        }
    }

    $rs = probe_race($specs);
    $q1 = array_merge($q1, $rs);
    $fresh = $order->fresh();
    $results = collect($rs)->pluck('result')->countBy();
    $q1Applied += (int) ($results['applied'] ?? 0) === 1 ? 1 : 0;
    $q1Ok += ($fresh->status === 'verified' && $accessRows($user)->count() === 1 && $saleOf($fresh) !== null && $coherent($user, $fresh)) ? 1 : 0;
    $q1Entries += ($entryCount($fresh, 'credit') === 1 && $avail() === $before + 350) ? 1 : 0;
    $q1Notes += ($notes($user, 'premium_series_access_granted') === 1 && $creatorNotes('creator_premium_sale') === $creatorBefore + 1) ? 1 : 0;
}

$cleanRaces('Q1', $q1);
$check('Q1 per round exactly one delivery applied the verification (the rest were duplicates/ignored)', $q1Applied === $rounds, "applied-once={$q1Applied}/{$rounds}");
$check('Q1 per round: purchase verified, one access, one sale snapshot, state coherent across purchase/access/sale/ledger', $q1Ok === $rounds, "ok={$q1Ok}/{$rounds}");
$check('Q1 per round: exactly one direct_sale_credit entry and the creator available grew by exactly the 3.50 share', $q1Entries === $rounds, "ok={$q1Entries}/{$rounds}");
$check('Q1 per round: one access notification for the buyer and one sale notification for the creator', $q1Notes === $rounds, "ok={$q1Notes}/{$rounds}");

// ── Q2: دفعة متأخرة معلَّمة ↔ دفع طلب جديد ──
echo "\nQ2: تحقق مراجِع لدفعة متأخرة ↔ دفع طلب جديد للمشتري نفسه والسلسلة نفسها\n";
$q2 = [];
$q2Ok = 0;
$q2One = 0;

for ($i = 0; $i < $rounds * 2; $i++) {
    $user = $player();
    $stale = $purchases->request($user, $series->fresh());
    $staleStarted = $checkout->start($user, $stale);
    DB::table('digital_purchases')->where('id', $stale->id)->update(['expires_at' => now()->subMinute()]);
    $new = $purchases->request($user, $series->fresh()); // يُنهي القديم ويُنشئ بديلًا
    $newStarted = $checkout->start($user, $new);
    $late = $sandbox->simulate($staleStarted['session_id'], CommerceProviderState::Paid, [], 'q2s-'.$i);
    $lateResult = app(CommerceWebhookService::class)->handle('sandbox', $late['payload'], $late['headers']);

    if (! $stale->fresh()->needsReview()) {
        $check("Q2 round {$i} setup: the late payment on the expired order raised a review flag", false, json_encode($lateResult));

        continue;
    }

    $newEvent = $sandbox->simulate($newStarted['session_id'], CommerceProviderState::Paid, [], 'q2n-'.$i);
    $delay = [0, 20, 60, 120][$i % 4];
    $specs = [['verify-reviewed', ["--actor={$finance->id}", "--purchase={$stale->id}", "--delay={$delay}"]], ['webhook', $enc($newEvent)]];
    shuffle($specs);
    $rs = probe_race($specs);
    $q2 = array_merge($q2, $rs);
    $staleNow = $stale->fresh();
    $newNow = $new->fresh();
    $verifiedCount = collect([$staleNow, $newNow])->where('status', 'verified')->count();
    $rows = $accessRows($user);
    $winner = $staleNow->status === 'verified' ? $staleNow : $newNow;

    $q2One += ($rows->count() === 1 && $rows->first()->revoked_at === null && $verifiedCount === 1 && (int) $rows->first()->source_digital_purchase_id === (int) $winner->id) ? 1 : 0;
    $loser = $winner->id === $staleNow->id ? $newNow : $staleNow;
    $q2Ok += ($coherent($user, $staleNow) && $coherent($user, $newNow)
        && DB::table('creator_direct_sales')->whereIn('digital_purchase_id', [$staleNow->id, $newNow->id])->count() === 1
        && ($loser->status !== 'verified' && ($loser->needsReview() || $loser->review_resolved_at === null))) ? 1 : 0;
}

$q2Total = $rounds * 2;
$cleanRaces('Q2', $q2);
$check('Q2 exactly one of the two purchases verifies and the single access row points at that purchase (no double ownership)', $q2One === $q2Total, "ok={$q2One}/{$q2Total}");
$check('Q2 one sale only; the loser is never silently granted or lost (still flagged for a human) and each purchase is coherent', $q2Ok === $q2Total, "ok={$q2Ok}/{$q2Total}");

// ── Q3: مدفوع ↔ refunded ↔ تكرار ──
echo "\nQ3: حدث مدفوع ↔ حدث refunded ↔ مدفوع مكرر\n";
$q3 = [];
$q3Ok = 0;
$q3Outcomes = [];
$q3Bad = [];
$q3Stale = [];

for ($i = 0; $i < $rounds * 2; $i++) {
    [$user, $order, $session] = $hosted();
    $paid = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'q3p-'.$i);
    $refunded = $sandbox->signedEvent($session, 'payment.refunded', [], 'q3r-'.$i);
    $dup = $sandbox->signedEvent($session, 'payment.paid', [], 'q3d-'.$i);
    $specs = [['webhook', $enc($paid)], ['webhook', $enc($refunded)], ['webhook', $enc($dup)]];
    shuffle($specs);
    $rs = probe_race($specs);
    $q3 = array_merge($q3, $rs);
    $fresh = $order->fresh();
    $q3Outcomes[$fresh->status] = ($q3Outcomes[$fresh->status] ?? 0) + 1;
    // ترتيب وصول معكوس (refunded قبل paid) نتيجته في نواة E41: «محقَّق» وprovider_status=refunded (تثبيت الحالة صعودًا فقط). مقبول لـE43 ما دام الوصول والبيع متسقين، وتلتقطه مصالحة E41
    // بالرمز refunded_provider_local_verified للمراجعة البشرية (انظر docs/creator-premium-operations-runbook.md)؛ نسجّله لنسمح به في R لهذه الطلبات تحديدًا لا لغيرها.
    if ($fresh->status === 'verified' && ($fresh->provider_status ?? null) === 'refunded') {
        $q3Stale[] = (int) $fresh->id;
    }

    $q3Good = $coherent($user, $fresh) && $accessRows($user)->count() <= 1 && $entryCount($fresh, 'credit') <= 1 && $entryCount($fresh, 'reversal') <= 1;
    $q3Ok += $q3Good ? 1 : 0;

    if (! $q3Good) {
        $sale = $saleOf($fresh);
        $q3Bad[] = $fresh->status.'/provider='.($fresh->provider_status ?? '-').'/access='.$accessRows($user)->map(fn ($r) => $r->revoked_at === null ? 'active' : 'revoked')->implode(',').'/sale='.($sale?->status ?? '-').'/credit='.$entryCount($fresh, 'credit').'/reversal='.$entryCount($fresh, 'reversal').'/results='.collect($rs)->pluck('result')->implode(',');
    }
}

$cleanRaces('Q3', $q3);
$check('Q3 whatever the arrival order the state is coherent: verified(access+sale) | refunded(access revoked + sale reversed) | held(no access, no sale); at most one credit and one reversal', $q3Ok === $rounds * 2, 'ok='.$q3Ok.'/'.($rounds * 2).' outcomes='.json_encode($q3Outcomes).($q3Bad === [] ? '' : ' incoherent='.json_encode(array_slice($q3Bad, 0, 3), JSON_UNESCAPED_UNICODE)));

// ── Q4: استرداد متزامن ──
echo "\nQ4: استرداد إداري ×2 ↔ حدث refunded من المزوّد\n";
$q4 = [];
$q4Ok = 0;
$q4Notes = 0;

for ($i = 0; $i < $rounds; $i++) {
    $drain();
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'q4p-'.$i);
    app(CommerceWebhookService::class)->handle('sandbox', $ev['payload'], $ev['headers']);
    $creatorBefore = $creatorNotes('creator_premium_sale_adjusted');
    $refundedEvent = $sandbox->signedEvent($session, 'payment.refunded', [], 'q4r-'.$i);
    $specs = [['refund', ["--actor={$finance->id}", "--purchase={$order->id}"]], ['refund', ["--actor={$finance2->id}", "--purchase={$order->id}"]], ['webhook', $enc($refundedEvent)]];
    shuffle($specs);
    $rs = probe_race($specs);
    $q4 = array_merge($q4, $rs);
    $fresh = $order->fresh();
    $sale = $saleOf($fresh);
    $access = $accessRows($user)->first();

    $q4Ok += ($fresh->status === 'refunded' && $sale?->status === 'refunded' && $entryCount($fresh, 'credit') === 1 && $entryCount($fresh, 'reversal') === 1 && $access !== null && $access->revoked_at !== null && $avail() === 0) ? 1 : 0;
    $q4Notes += ($notes($user, 'commerce_refunded') === 1 && $creatorNotes('creator_premium_sale_adjusted') === $creatorBefore + 1) ? 1 : 0;
}

$cleanRaces('Q4', $q4);
$check('Q4 per round: refunded once, access revoked (row kept), one credit + exactly one reversal entry, creator available back to zero', $q4Ok === $rounds, "ok={$q4Ok}/{$rounds}");
$check('Q4 per round: one buyer refund notification and one creator adjustment notification', $q4Notes === $rounds, "ok={$q4Notes}/{$rounds}");

// ── Q5: عكس الحصة ↔ خصم متزامن يستهلك المتاح ──
echo "\nQ5: عكس حصة المبدع ↔ خصم إداري متزامن لنفس الرصيد (المتاح = الحصة 3.50 فقط)\n";
$q5 = [];
$q5Ok = 0;
$q5NotNegative = 0;
$q5Outcomes = [];

for ($i = 0; $i < $rounds * 2; $i++) {
    $drain();
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'q5p-'.$i);
    app(CommerceWebhookService::class)->handle('sandbox', $ev['payload'], $ev['headers']);

    if ($avail() !== 350) {
        $check("Q5 round {$i} setup: the creator available equals the 3.50 share", false, 'available='.$avail());

        continue;
    }

    $delay = [0, 15, 45, 100][$i % 4];
    $specs = [['refund', ["--actor={$finance->id}", "--purchase={$order->id}"]], ['debit', ["--actor={$finance2->id}", "--account={$account->id}", '--amount=3.50', "--delay={$delay}"]]];
    shuffle($specs);
    $rs = probe_race($specs);
    $q5 = array_merge($q5, $rs);
    $fresh = $order->fresh();
    $sale = $saleOf($fresh);
    $debitOk = collect($rs)->where('action', 'debit')->where('outcome', 'ok')->count();
    $reversed = $entryCount($fresh, 'reversal');
    $balances = $ledger->ledgerBalances($account->id);
    $state = $sale?->status.'|debit='.$debitOk.'|reversal='.$reversed;
    $q5Outcomes[$state] = ($q5Outcomes[$state] ?? 0) + 1;

    // الحصة 3.50 تُستهلك مرة واحدة فقط: إما عكس (والخصم رُفض) أو خصم (والعكس عُلّم للمراجعة). ولا رصيد سالب.
    $q5NotNegative += ($avail() >= 0 && $balances['available'] === $avail()) ? 1 : 0;
    $q5Ok += ($fresh->status === 'refunded'
        && (($sale?->status === 'refunded' && $reversed === 1 && $debitOk === 0) || ($sale?->status === 'needs_finance_review' && $reversed === 0 && $debitOk === 1))) ? 1 : 0;
}

$q5Total = $rounds * 2;
$cleanRaces('Q5', $q5);
$check('Q5 the 3.50 is consumed exactly once: refunded+reversed (debit refused) OR debited (reversal flagged needs_finance_review) - never both, never neither', $q5Ok === $q5Total, "ok={$q5Ok}/{$q5Total} outcomes=".json_encode($q5Outcomes));
$check('Q5 no negative balance ever and the ledger sum equals the account snapshot after every race', $q5NotNegative === $q5Total, "ok={$q5NotNegative}/{$q5Total}");

// ── Q6: مدفوع ↔ إيقاف العرض ──
echo "\nQ6: حدث مدفوع ↔ إيقاف العرض (مبدع وإدارة)\n";
$q6 = [];
$q6Ok = 0;
$q6NoLateSale = 0;
$q6Outcomes = [];

for ($i = 0; $i < $rounds * 2; $i++) {
    $resetOffer();
    [$user, $order, $session] = $hosted();
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'q6-'.$i);
    $delay = [0, 15, 45, 100][$i % 4];
    $specs = [['webhook', $enc($ev)], ['offer-pause', ["--actor={$creator->id}", "--offer={$offer->public_id}", "--delay={$delay}"]], ['offer-pause-admin', ["--actor={$finance->id}", "--offer={$offer->public_id}"]]];
    shuffle($specs);
    $rs = probe_race($specs);
    $q6 = array_merge($q6, $rs);
    $fresh = $order->fresh();
    $row = $offerRow();
    $sale = $saleOf($fresh);
    $q6Outcomes[$fresh->status.'/'.($fresh->review_flag ?? '-')] = ($q6Outcomes[$fresh->status.'/'.($fresh->review_flag ?? '-')] ?? 0) + 1;

    // إما بيع كامل قبل الإيقاف، أو حجب للمراجعة بلا وصول ولا بيع (المال لا يضيع ولا يُفتح صامتًا).
    $q6Ok += (($fresh->status === 'verified' && $coherent($user, $fresh))
        || ($fresh->status === 'awaiting_payment' && $fresh->review_flag === 'fulfillment_blocked' && $coherent($user, $fresh))) ? 1 : 0;
    $q6NoLateSale += ($sale === null || $row->paused_at === null || strtotime((string) $sale->sold_at) <= strtotime((string) $row->paused_at)) ? 1 : 0;
}

$q6Total = $rounds * 2;
$cleanRaces('Q6', $q6);
$check('Q6 the payment either sold completely before the pause or was held for review - never a half sale, never a silent grant', $q6Ok === $q6Total, "ok={$q6Ok}/{$q6Total} outcomes=".json_encode($q6Outcomes));
$check('Q6 no sale was recorded after the offer was paused', $q6NoLateSale === $q6Total, "ok={$q6NoLateSale}/{$q6Total}");

// ── Q7: تفعيل ↔ إيقاف ──
echo "\nQ7: تفعيل العرض ↔ إيقافه معًا\n";
$q7 = [];
$q7Ok = 0;
$q7Once = 0;

for ($i = 0; $i < $rounds * 2; $i++) {
    $resetOffer();
    $offers->pause($creator, $offer->public_id);
    $specs = [['offer-activate', ["--actor={$creator->id}", "--offer={$offer->public_id}"]], ['offer-pause', ["--actor={$creator->id}", "--offer={$offer->public_id}"]], ['offer-activate', ["--actor={$creator->id}", "--offer={$offer->public_id}", '--delay=20']]];
    shuffle($specs);
    $rs = probe_race($specs);
    $q7 = array_merge($q7, $rs);
    $row = $offerRow();
    $live = DB::table('creator_series_sale_offers')->where('creator_series_id', $seriesId)->whereNotNull('live_series_id')->count();

    $q7Ok += (in_array($row->status, ['active', 'paused'], true) && $live === 1 && (int) $row->live_series_id === $seriesId) ? 1 : 0;
    $q7Once += ((string) $row->first_activated_at === $firstActivatedAt && $row->retired_at === null) ? 1 : 0;
}

$q7Total = $rounds * 2;
$cleanRaces('Q7', $q7);
$check('Q7 the offer always ends active or paused with exactly one live row for the series', $q7Ok === $q7Total, "ok={$q7Ok}/{$q7Total}");
$check('Q7 first_activated_at (the fund-exclusion / grandfathering anchor) is written once and never moves', $q7Once === $q7Total, "ok={$q7Once}/{$q7Total}");
$resetOffer();

// ── Q8: استثناء مُحقَن داخل معاملة التحقق ──
echo "\nQ8: استثناء مُحقَن بعد منح الوصول داخل معاملة التحقق\n";
$q8Injected = 0;
$q8Rolled = 0;
$q8Clean = 0;
$injectRounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $injectRounds; $i++) {
    [$user, $order, $session] = $hosted();
    $before = [$avail(), DB::table('creator_earning_entries')->count()];
    $ev = $sandbox->simulate($session, CommerceProviderState::Paid, [], 'q8-'.$i);
    $res = probe_race([['webhook-fail', $enc($ev)]], 1.0);
    $fresh = $order->fresh();
    $q8Injected += (($res[0]['outcome'] ?? '') === 'exception' && ($res[0]['detail'] ?? '') === 'injected') ? 1 : 0;
    $q8Rolled += ($fresh->status === 'awaiting_payment' && $accessRows($user)->isEmpty() && $saleOf($fresh) === null && $entryCount($fresh, 'credit') === 0 && $ledgerEvents($fresh) === 0
        && $avail() === $before[0] && DB::table('creator_earning_entries')->count() === $before[1] && $notes($user, 'premium_series_access_granted') === 0) ? 1 : 0;

    $clean = app(CommerceWebhookService::class)->handle('sandbox', $ev['payload'], $ev['headers']);
    $again = $order->fresh();
    $q8Clean += ($clean['result'] === 'applied' && $again->status === 'verified' && $coherent($user, $again) && $avail() === $before[0] + 350 && $notes($user, 'premium_series_access_granted') === 1) ? 1 : 0;
}

$check('Q8 the worker failed as injected', $q8Injected === $injectRounds, "injected={$q8Injected}/{$injectRounds}");
$check('Q8 the failed verification left no trace: still awaiting, no access, no sale, no ledger entry, no balance change, no provider-event row, no notification', $q8Rolled === $injectRounds, "rolled-back={$q8Rolled}/{$injectRounds}");
$check('Q8 re-delivery of the same event afterwards applies exactly once (access + sale + 3.50 credit + notification)', $q8Clean === $injectRounds, "applied-once={$q8Clean}/{$injectRounds}");

// ── R: المصالحة والسلامة النهائية ──
echo "\nR: المصالحة بعد كل السباقات\n";
// الأمر نفسه يخرج 1 عند أي علم مراجعة بشرية قائم (وهذا مقصود: السباقات تترك خاسرين معلَّمين للمراجعة وبيعًا معلَّقًا مماليًا). لذلك نشغّل المصالحة نفسها
// بسقف نتائج مرفوع ونطلب: لا انحراف من أي نوع آخر، وطابور المراجعة البشرية يطابق القاعدة تمامًا (لا ضياع ولا فتح صامت).
config(['creator_premium.reconcile.max_findings' => 100000]);
$premium = app(\App\Services\CreatorPremium\CreatorPremiumReconciler::class)->run(5000);
$reviewQueue = ['purchase_in_review', 'sale_needs_finance_review'];
$drift = collect($premium['findings'])->reject(fn ($f) => in_array($f['code'], $reviewQueue, true));
$check('R premium reconciliation reports no drift other than the human-review queue', $drift->isEmpty() && ! $premium['truncated'], 'drift='.$drift->count().($drift->isEmpty() ? '' : ' first='.json_encode($drift->first(), JSON_UNESCAPED_UNICODE)));
$queuedPurchases = collect($premium['findings'])->where('code', 'purchase_in_review')->count();
$queuedSales = collect($premium['findings'])->where('code', 'sale_needs_finance_review')->count();
$check('R the review queue equals the database exactly (flagged purchases and needs_finance_review sales - nothing lost, nothing granted silently)',
    $queuedPurchases === DB::table('digital_purchases')->where('product_type', 'creator_series_access')->whereNotNull('review_flag')->whereNull('review_resolved_at')->count()
    && $queuedSales === DB::table('creator_direct_sales')->where('status', 'needs_finance_review')->count(), "purchases={$queuedPurchases} sales={$queuedSales}");
$exit = Artisan::call('creator-premium:reconcile', ['--limit' => 5000]);
$check('R the reconcile command agrees with the service (exit 0 only when the queue is empty)', ($exit === 0) === ($premium['remaining'] === 0), 'exit='.$exit.' remaining='.$premium['remaining']);

$exitCommerce = Artisan::call('commerce:reconcile', ['--json' => true, '--limit' => 5000]);
$report = json_decode(Artisan::output(), true) ?: [];
$knownStale = collect($q3Stale)->map(fn ($id) => 'purchase#'.$id)->all();
$serious = collect($report['findings'] ?? [])->whereIn('severity', ['critical', 'warning'])
    ->reject(fn ($f) => ($f['code'] ?? '') === 'refunded_provider_local_verified' && in_array($f['subject'] ?? '', $knownStale, true))->values();
$check('R commerce reconciliation reports no critical or warning drift (except the known E41 reversed-arrival-order case flagged in Q3)', $serious->isEmpty(), 'exit='.$exitCommerce.' findings='.$serious->count().' known-stale='.count($knownStale).($serious->isEmpty() ? '' : ' first='.json_encode($serious->first(), JSON_UNESCAPED_UNICODE)));

$check('R one access per (user, series) and one access per source purchase', DB::table('creator_series_accesses')->count() === DB::table('creator_series_accesses')->distinct()->count(DB::raw('CONCAT(user_id, "-", creator_series_id)'))
    && DB::table('creator_series_accesses')->count() === DB::table('creator_series_accesses')->distinct()->count('source_digital_purchase_id'));
$check('R one sale per purchase, one credit entry per sale, and no credit entry without a sale', DB::table('creator_direct_sales')->count() === DB::table('creator_direct_sales')->distinct()->count('digital_purchase_id')
    && DB::table('creator_earning_entries')->where('type', 'direct_sale_credit')->count() === DB::table('creator_direct_sales')->count());
$check('R every refunded sale has exactly one reversal entry; no reversal entry exists without a refunded sale', DB::table('creator_earning_entries')->where('type', 'direct_sale_reversal')->count() === DB::table('creator_direct_sales')->where('status', 'refunded')->count()
    && DB::table('creator_direct_sales')->where('status', 'refunded')->whereNull('reversal_entry_id')->count() === 0);
$check('R no negative balance anywhere and the ledger equals the account snapshot', DB::table('creator_earning_accounts')->where(fn ($q) => $q->where('available_minor', '<', 0)->orWhere('reserved_minor', '<', 0)->orWhere('paid_lifetime_minor', '<', 0))->count() === 0
    && $ledger->ledgerBalances($account->id)['available'] === $avail());
$check('R no refunded purchase keeps an active access, and no verified purchase lacks one', DB::table('digital_purchases as d')->join('creator_series_accesses as a', 'a.source_digital_purchase_id', '=', 'd.id')->where('d.status', 'refunded')->whereNull('a.revoked_at')->count() === 0
    && DB::table('digital_purchases as d')->leftJoin('creator_series_accesses as a', 'a.source_digital_purchase_id', '=', 'd.id')->where('d.product_type', 'creator_series_access')->where('d.status', 'verified')->whereNull('a.id')->count() === 0);
$check('R the creator earning account is still exactly one and the offer is still the single live row', DB::table('creator_earning_accounts')->count() === 1 && DB::table('creator_series_sale_offers')->whereNotNull('live_series_id')->count() === 1);

Cache::flush();

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

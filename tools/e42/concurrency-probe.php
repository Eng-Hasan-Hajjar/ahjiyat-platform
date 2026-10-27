<?php

/*
|--------------------------------------------------------------------------
| E42 - مسبار التزامن الحقيقي على MySQL (عمليات PHP منفصلة تتسابق فعلًا) لتطوّر الموسم وتنسيقه
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، وقفل الصفوف (lockForUpdate) والـUPDATE الشرطي الذري لا أثر لهما على SQLite
| فلا سباق حقيقي بين اتصالين. هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليات تبدأ في اللحظة نفسها (لا شبكة ولا مزوّد خارجي):
|   P1  بدء الموسم (syncLiveState) من 4 عمليات معًا                ← انتقال واحد فقط (went_live_at مرة واحدة)، وإشعار «بدأ الموسم» واحد لكل مشارك
|   P2  إضافة مراحل متداخلة / متجاورة من 4 عمليات                   ← المتداخلة: واحدة فقط تنجح والبقية phase_overlap؛ المتجاورة (نصف مفتوحة): تنجح كلها بلا تداخل نهائي
|   P3  حدّ المراحل وحدّ المحتوى وحدّ الإعلانات تحت سباق               ← لا يتجاوز العدد الحدّ أبدًا (يفوز بالضبط «الحدّ − الموجود»)
|   P4  ربط الكيان نفسه بالموسم من 4 عمليات                          ← رابط واحد فقط (القيد الفريد + الفحص المقفول)، والبقية already_linked نظيفة
|   P5  تشغيل المعالج seasons:process من 4 عمليات معًا                ← مرحلة واحدة تبدأ (إشعار واحد لكل مشارك)، الأولى تُحجَز بصمت، لا تكرار
|   P6  إعلانات بإشعار صريح تُعالَج من 4 عمليات                       ← كل إعلان يُحجَز ويُرسَل مرة واحدة فقط، إشعار واحد لكل مشارك لكل إعلان
|   P7  استثناء مُحقَن بعد حفظ المرحلة داخل المعاملة                   ← تراجع كامل (لا مرحلة ولا سجل تدقيق)، ثم إعادة المحاولة النظيفة تنجح مرة واحدة
|   R   سلامة نهائية: لا تداخل في أي موسم، لا مرحلة خارج النافذة، لا رابط مكرر، لا إشعار مكرر بمفتاح دلالي، تكافؤ مرئية الموسم SQL↔PHP
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - كاش الملفات المشترك بين العمليات (رمز إصدار كاش الموسم) في مجلد مؤقت خاص بالمسبار فقط؛ لا يمسّ كاش التطبيق.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أسباب فقط. لا اتصال شبكة.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/season-operations-runbook.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e42/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e42/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 4)
*/

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\OperationalAuditLog;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonContentItem;
use App\Models\SeasonPhase;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use App\Services\Seasons\SeasonProcessor;
use App\Services\SeasonLifecycleService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env، وتُورَّث للعمليات العاملة. الكاش ملفات (يُشارك بين العمليات) في مجلد خاص بالمسبار.
$probeCacheDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'e42-probe-cache';

foreach ([
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
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

/** تنسيق التاريخ كما تتوقعه الخدمة (بتوقيت التطبيق). */
function probe_fmt(int $timestamp): string
{
    return Carbon::createFromTimestamp($timestamp, (string) config('app.timezone'))->format('Y-m-d H:i:s');
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
        $season = isset($args['season']) ? Season::query()->find((int) $args['season']) : null;

        // حدود الإعدادات تُمرَّر بالمعاملات (لا env() في config): الحدّ الأدنى يجعل سباق الحدّ قابلًا للإعادة بعدد صغير من الصفوف.
        if (isset($args['phase-limit'])) {
            config(['seasons.limits.phases_per_season' => (int) $args['phase-limit']]);
        }

        if (isset($args['content-limit'])) {
            config(['seasons.limits.extra_campaigns' => (int) $args['content-limit']]);
        }

        if (isset($args['announcement-limit'])) {
            config(['seasons.limits.announcements_per_season' => (int) $args['announcement-limit']]);
        }

        while (microtime(true) < $at) {
            usleep(200);
        }

        if (isset($args['delay'])) {
            usleep(max(0, min(500, (int) $args['delay'])) * 1000);
        }

        $started = microtime(true);

        if ($kind === 'phase-fail') {
            // يحقن استثناءً بعد حفظ المرحلة داخل معاملة الباني (قبل سجل التدقيق): يجب أن يتراجع كل شيء.
            SeasonPhase::created(function (): void {
                throw new RuntimeException('probe-injected-failure');
            });
        }

        if ($kind === 'sync') {
            $result = ['outcome' => 'ok', 'transitioned' => app(SeasonLifecycleService::class)->syncLiveState($season)];
        } elseif ($kind === 'phase' || $kind === 'phase-fail') {
            $phase = app(SeasonBuilderService::class)->addPhase($actor, $season, [
                'name' => 'مرحلة '.$args['key'], 'internal_key' => (string) $args['key'],
                'starts_at' => probe_fmt((int) $args['from']), 'ends_at' => probe_fmt((int) $args['to']),
            ]);
            $result = ['outcome' => 'ok', 'phase' => $phase->public_id];
        } elseif ($kind === 'content') {
            $item = app(SeasonBuilderService::class)->addContent($actor, $season, SeasonContentItem::TYPE_CAMPAIGN, (int) $args['campaign']);
            $result = ['outcome' => 'ok', 'item' => $item->id];
        } elseif ($kind === 'announce') {
            $row = app(SeasonBuilderService::class)->createAnnouncement($actor, $season, ['title' => 'إعلان '.$args['key'], 'body' => 'نص إعلان المسبار']);
            $result = ['outcome' => 'ok', 'announcement' => $row->public_id];
        } elseif ($kind === 'process') {
            $result = ['outcome' => 'ok', 'stats' => app(SeasonProcessor::class)->run()];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof SeasonException;
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

echo '== E42 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh) وكاش المسبار...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);
Cache::flush(); // مجلد كاش المسبار الخاص فقط (ليس كاش التطبيق).

$verified = function (User $u): User {
    $u->forceFill(['email_verified_at' => now()])->save();

    return $u->fresh();
};
$admin = $verified(User::factory()->create(['email' => 'probe-admin@season-concurrency.test']));
$admin->syncRoles(['administrator']);
$admin = $admin->fresh();

$serial = 0;
$now = time();

/** حملة بمرحلة ← بوابة ← خطوة (لازمة لمعرفة «المشاركين»). @return array{0: Campaign, 1: CampaignStep} */
$campaignWithStep = function () use (&$serial): array {
    $serial++;
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => 'حملة مسبار '.$serial]);
    $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id]);
    $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);
    $step = CampaignStep::factory()->create(['campaign_gate_id' => $gate->id]);

    return [$campaign, $step];
};

/** موسم منشور بنافذة صريحة: بدأ قبل 10 أيام وينتهي بعد 20 يومًا. @return array{0: Season, 1: CampaignStep} */
$liveSeason = function (bool $resetLive = false) use (&$serial, $campaignWithStep, $now): array {
    [$campaign, $step] = $campaignWithStep();
    $serial++;
    $season = (new Season)->forceFill([
        'campaign_id' => $campaign->id, 'code' => 'PR'.$serial, 'slug' => 'probe-season-'.$serial, 'is_published' => true, 'is_featured' => false,
        'starts_at' => probe_fmt($now - 10 * 86400), 'ends_at' => probe_fmt($now + 20 * 86400),
    ]);
    $season->save();

    if ($resetLive) {
        DB::table('seasons')->where('id', $season->id)->update(['went_live_at' => null]); // سباق «اللحظة الأولى» يحتاج موسمًا مباشرًا لم يُعلَّم بعد.
    }

    return [$season->fresh(), $step];
};

$participants = function (CampaignStep $step, int $count) use ($verified): array {
    $users = [];

    for ($i = 0; $i < $count; $i++) {
        $user = $verified(User::factory()->create());
        UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now(), 'completed_at' => now()]);
        $users[] = $user;
    }

    return $users;
};

$notes = fn (User $u, string $type): int => DB::table('notifications')->where('notifiable_id', $u->id)->where('type_key', $type)->count();
$builder = app(SeasonBuilderService::class);
$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r))->count();
$refusals = fn (array $rs): array => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => (string) ($r['detail'] ?? ''))->countBy()->all();
$oks = fn (array $rs): int => collect($rs)->where('outcome', 'ok')->count();
$cleanRaces = function (string $label, array $rs) use ($check, $crashes, $lockErrors, $unexpected, $refusals): void {
    $check("{$label} no worker crash, no deadlock (1213) / lock timeout (1205)", $crashes($rs) === 0 && $lockErrors($rs) === 0, 'crash='.$crashes($rs).' lock-errors='.$lockErrors($rs));
    $check("{$label} no unexpected exception (the loser is a clean domain refusal)", $unexpected($rs) === 0, 'unexpected='.$unexpected($rs).' clean-refusals='.json_encode($refusals($rs), JSON_UNESCAPED_UNICODE));
};

$actorArg = "--actor={$admin->id}";

// ── P1: بدء الموسم من 4 عمليات ──
echo "\nP1: syncLiveState من أربع عمليات في اللحظة نفسها\n";
$p1 = [];
$p1Once = 0;
$p1Notes = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$season, $step] = $liveSeason(true);
    $users = $participants($step, 3);
    $rs = probe_race(array_fill(0, 4, ['sync', ["--season={$season->id}"]]));
    $p1 = array_merge($p1, $rs);
    $transitions = collect($rs)->where('outcome', 'ok')->where('transitioned', true)->count();
    $p1Once += ($transitions === 1 && Season::query()->whereKey($season->id)->whereNotNull('went_live_at')->exists()) ? 1 : 0;
    $p1Notes += collect($users)->every(fn (User $u) => $notes($u, 'season_started') === 1) ? 1 : 0;
}

$cleanRaces('P1', $p1);
$check('P1 per round exactly one process performed the transition and went_live_at is set', $p1Once === $rounds, "once={$p1Once}/{$rounds}");
$check('P1 every participant received exactly one "season started" notification', $p1Notes === $rounds, "ok={$p1Notes}/{$rounds}");

// ── P2: مراحل متداخلة ثم متجاورة ──
echo "\nP2: إضافة مراحل متداخلة / متجاورة من أربع عمليات\n";
$p2 = [];
$p2Overlap = 0;
$p2OverlapRefused = 0;
$p2Adjacent = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$season] = $liveSeason();
    $base = $now + 86400;
    $specs = [];

    foreach (range(0, 3) as $w) {
        $specs[] = ['phase', [$actorArg, "--season={$season->id}", "--key=ov{$w}", '--from='.$base, '--to='.($base + 86400)]];
    }

    $rs = probe_race($specs);
    $p2 = array_merge($p2, $rs);
    $p2Overlap += ($oks($rs) === 1 && SeasonPhase::query()->where('season_id', $season->id)->count() === 1) ? 1 : 0;
    $p2OverlapRefused += (($refusals($rs)['phase_overlap'] ?? 0) === 3) ? 1 : 0;

    [$adjSeason] = $liveSeason();
    $specs = [];

    foreach (range(0, 3) as $w) {
        $specs[] = ['phase', [$actorArg, "--season={$adjSeason->id}", "--key=adj{$w}", '--from='.($base + $w * 86400), '--to='.($base + ($w + 1) * 86400)]];
    }

    $rs = probe_race($specs);
    $p2 = array_merge($p2, $rs);
    $ordered = SeasonPhase::query()->where('season_id', $adjSeason->id)->orderBy('starts_at')->get();
    $contiguous = $ordered->count() === 4 && $ordered->every(fn (SeasonPhase $p, int $k) => $k === 0 || $ordered[$k - 1]->ends_at->lessThanOrEqualTo($p->starts_at));
    $p2Adjacent += ($oks($rs) === 4 && $contiguous) ? 1 : 0;
}

$cleanRaces('P2', $p2);
$check('P2 overlapping windows: exactly one phase is stored per round', $p2Overlap === $rounds, "one={$p2Overlap}/{$rounds}");
$check('P2 overlapping windows: the three losers are clean phase_overlap refusals', $p2OverlapRefused === $rounds, "refused={$p2OverlapRefused}/{$rounds}");
$check('P2 adjacent half-open windows: all four succeed and the stored phases never overlap', $p2Adjacent === $rounds, "all={$p2Adjacent}/{$rounds}");

// ── P3: حدود المراحل والمحتوى والإعلانات ──
echo "\nP3: الحدود تحت السباق (المراحل / المحتوى / الإعلانات)\n";
$p3 = [];
$p3Phase = 0;
$p3PhaseRefused = 0;
$p3Content = 0;
$p3Announce = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$season] = $liveSeason();
    $builder->addPhase($admin, $season, ['name' => 'سابقة', 'starts_at' => probe_fmt($now - 5 * 86400), 'ends_at' => probe_fmt($now - 4 * 86400)]);
    $specs = [];

    foreach (range(0, 3) as $w) {
        $from = $now + 86400 + $w * 86400;
        $specs[] = ['phase', [$actorArg, "--season={$season->id}", "--key=lim{$w}", '--from='.$from, '--to='.($from + 86400), '--phase-limit=3']];
    }

    $rs = probe_race($specs);
    $p3 = array_merge($p3, $rs);
    $p3Phase += ($oks($rs) === 2 && SeasonPhase::query()->where('season_id', $season->id)->count() === 3) ? 1 : 0;
    $p3PhaseRefused += (($refusals($rs)['phase_limit'] ?? 0) === 2) ? 1 : 0;

    [$contentSeason] = $liveSeason();
    $targets = array_map(fn () => $campaignWithStep()[0], range(0, 3));
    $rs = probe_race(array_map(fn (Campaign $c) => ['content', [$actorArg, "--season={$contentSeason->id}", "--campaign={$c->id}", '--content-limit=2']], $targets));
    $p3 = array_merge($p3, $rs);
    $p3Content += ($oks($rs) === 2 && SeasonContentItem::query()->where('season_id', $contentSeason->id)->count() === 2 && ($refusals($rs)['content_limit'] ?? 0) === 2) ? 1 : 0;

    [$noteSeason] = $liveSeason();
    $rs = probe_race(array_map(fn (int $w) => ['announce', [$actorArg, "--season={$noteSeason->id}", "--key=n{$w}", '--announcement-limit=2']], range(0, 3)));
    $p3 = array_merge($p3, $rs);
    $p3Announce += ($oks($rs) === 2 && SeasonAnnouncement::query()->where('season_id', $noteSeason->id)->count() === 2 && ($refusals($rs)['announcement_limit'] ?? 0) === 2) ? 1 : 0;
}

$cleanRaces('P3', $p3);
$check('P3 phase limit 3 with one existing phase: exactly two of four racers win, the stored count never exceeds the limit', $p3Phase === $rounds && $p3PhaseRefused === $rounds, "stored-3={$p3Phase}/{$rounds} refused-2={$p3PhaseRefused}/{$rounds}");
$check('P3 extra-campaign limit 2: exactly two of four racers win and two links are stored', $p3Content === $rounds, "ok={$p3Content}/{$rounds}");
$check('P3 announcement limit 2: exactly two of four racers win and two rows are stored', $p3Announce === $rounds, "ok={$p3Announce}/{$rounds}");

// ── P4: الربط المكرر ──
echo "\nP4: ربط الحملة نفسها بالموسم من أربع عمليات\n";
$p4 = [];
$p4One = 0;
$p4Refused = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$season] = $liveSeason();
    [$extra] = $campaignWithStep();
    $rs = probe_race(array_fill(0, 4, ['content', [$actorArg, "--season={$season->id}", "--campaign={$extra->id}"]]));
    $p4 = array_merge($p4, $rs);
    $p4One += ($oks($rs) === 1 && SeasonContentItem::query()->where('season_id', $season->id)->where('campaign_id', $extra->id)->count() === 1) ? 1 : 0;
    $p4Refused += (($refusals($rs)['already_linked'] ?? 0) === 3) ? 1 : 0;
}

$cleanRaces('P4', $p4);
$check('P4 exactly one link is stored per round (unique (season, campaign) holds under contention)', $p4One === $rounds, "one={$p4One}/{$rounds}");
$check('P4 the three losers are clean already_linked refusals (never a raw 1062 / 500)', $p4Refused === $rounds, "refused={$p4Refused}/{$rounds}");

// ── P5: تشغيل المعالج المتزامن ──
echo "\nP5: seasons:process من أربع عمليات معًا (بدء مرحلة)\n";
app(SeasonProcessor::class)->run(); // تصريف ما تبقى من جولات سابقة حتى لا يلوّث العدّادات.
$p5 = [];
$p5Once = 0;
$p5Notes = 0;
$p5Silent = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$season, $step] = $liveSeason();
    $users = $participants($step, 3);
    $first = $builder->addPhase($admin, $season, ['name' => 'الأولى', 'starts_at' => probe_fmt($now - 5 * 86400), 'ends_at' => probe_fmt($now - 2 * 3600)]);
    $second = $builder->addPhase($admin, $season, ['name' => 'الثانية', 'starts_at' => probe_fmt($now - 3600), 'ends_at' => probe_fmt($now + 3 * 86400)]);
    $rs = probe_race(array_fill(0, 4, ['process', []]));
    $p5 = array_merge($p5, $rs);
    $started = collect($rs)->where('outcome', 'ok')->sum(fn ($r) => (int) ($r['stats']['phases_started'] ?? 0));
    $p5Once += ($started === 1 && SeasonPhase::query()->whereKey($second->id)->whereNotNull('went_live_at')->exists()) ? 1 : 0;
    $p5Silent += (SeasonPhase::query()->whereKey($first->id)->whereNotNull('went_live_at')->exists() && collect($users)->every(fn (User $u) => DB::table('notifications')->where('notifiable_id', $u->id)->where('type_key', 'season_phase_started')->where('idempotency_key', "season-phase:{$first->id}:{$u->id}")->count() === 0)) ? 1 : 0;
    $p5Notes += collect($users)->every(fn (User $u) => $notes($u, 'season_phase_started') === 1) ? 1 : 0;
}

$cleanRaces('P5', $p5);
$check('P5 per round exactly one process started the current phase (atomic claim) and it is marked started', $p5Once === $rounds, "once={$p5Once}/{$rounds}");
$check('P5 the first phase is claimed silently (no phase notification - the season-start notice covers it)', $p5Silent === $rounds, "silent={$p5Silent}/{$rounds}");
$check('P5 every participant received exactly one "phase started" notification', $p5Notes === $rounds, "ok={$p5Notes}/{$rounds}");

// ── P6: إعلانات بإشعار صريح ──
echo "\nP6: إعلانات بإشعار صريح تُعالَج من أربع عمليات\n";
$p6 = [];
$p6Sent = 0;
$p6Notes = 0;
$p6Claimed = 0;

for ($i = 0; $i < $rounds; $i++) {
    [$season, $step] = $liveSeason();
    $users = $participants($step, 3);
    $rows = [];

    foreach (range(0, 2) as $n) {
        $rows[] = $builder->createAnnouncement($admin, $season, ['title' => "خبر {$n}", 'body' => 'نص', 'published_at' => probe_fmt($now - 300 - $n), 'notify_audience' => 'participants']);
    }

    $rs = probe_race(array_fill(0, 4, ['process', []]));
    $p6 = array_merge($p6, $rs);
    $sent = collect($rs)->where('outcome', 'ok')->sum(fn ($r) => (int) ($r['stats']['announcements_sent'] ?? 0));
    $p6Sent += $sent === 3 ? 1 : 0;
    $p6Claimed += SeasonAnnouncement::query()->where('season_id', $season->id)->whereNotNull('notified_at')->count() === 3 ? 1 : 0;
    $p6Notes += collect($users)->every(fn (User $u) => $notes($u, 'season_announcement_published') === 3) ? 1 : 0;
}

$cleanRaces('P6', $p6);
$check('P6 across the four processes each announcement was sent exactly once (3 per round)', $p6Sent === $rounds, "sent-3={$p6Sent}/{$rounds}");
$check('P6 every announcement is marked notified once', $p6Claimed === $rounds, "claimed={$p6Claimed}/{$rounds}");
$check('P6 every participant received exactly one notification per announcement (3), never a duplicate', $p6Notes === $rounds, "ok={$p6Notes}/{$rounds}");

// ── P7: استثناء مُحقَن ──
echo "\nP7: استثناء مُحقَن بعد حفظ المرحلة داخل المعاملة\n";
$p7Injected = 0;
$p7Rolled = 0;
$p7Clean = 0;
$injectRounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $injectRounds; $i++) {
    [$season] = $liveSeason();
    $auditBefore = OperationalAuditLog::query()->count();
    $from = $now + 86400;
    $res = probe_race([['phase-fail', [$actorArg, "--season={$season->id}", '--key=inject', '--from='.$from, '--to='.($from + 86400)]]], 1.0);
    $p7Injected += (($res[0]['outcome'] ?? '') === 'exception' && ($res[0]['detail'] ?? '') === 'injected') ? 1 : 0;
    $p7Rolled += (SeasonPhase::query()->where('season_id', $season->id)->count() === 0 && OperationalAuditLog::query()->count() === $auditBefore) ? 1 : 0;

    $retry = probe_race([['phase', [$actorArg, "--season={$season->id}", '--key=inject', '--from='.$from, '--to='.($from + 86400)]]], 1.0);
    $p7Clean += (($retry[0]['outcome'] ?? '') === 'ok' && SeasonPhase::query()->where('season_id', $season->id)->count() === 1) ? 1 : 0;
}

$check('P7 the worker failed as injected', $p7Injected === $injectRounds, "injected={$p7Injected}/{$injectRounds}");
$check('P7 the failed write left no trace: no phase row and no audit entry', $p7Rolled === $injectRounds, "rolled-back={$p7Rolled}/{$injectRounds}");
$check('P7 a clean retry of the same phase afterwards succeeds exactly once', $p7Clean === $injectRounds, "applied-once={$p7Clean}/{$injectRounds}");

// ── R: سلامة نهائية ──
echo "\nR: سلامة نهائية بعد كل السباقات\n";
$overlaps = 0;
$outside = 0;
$tooMany = 0;
$lifecycle = app(SeasonLifecycleService::class);

foreach (Season::query()->with('campaign')->get() as $s) {
    $phases = SeasonPhase::query()->where('season_id', $s->id)->orderBy('starts_at')->orderBy('sort_order')->orderBy('id')->get();
    $tooMany += $phases->count() > 12 ? 1 : 0;

    foreach ($phases as $k => $p) {
        $overlaps += ($k > 0 && $phases[$k - 1]->ends_at->greaterThan($p->starts_at)) ? 1 : 0;
        $outside += ($s->starts_at !== null && $p->starts_at->lessThan($s->starts_at)) || ($s->ends_at !== null && $p->ends_at->greaterThan($s->ends_at)) ? 1 : 0;
    }
}

$check('R no season has overlapping phases, none lies outside its season window, none exceeds the global phase limit', $overlaps === 0 && $outside === 0 && $tooMany === 0, "overlaps={$overlaps} outside={$outside} over-limit={$tooMany}");

$dupLinks = DB::table('season_content_items')->whereNotNull('campaign_id')->groupBy('season_id', 'campaign_id')->havingRaw('count(*) > 1')->get()->count();
$dupNotes = DB::table('notifications')->groupBy('notifiable_type', 'notifiable_id', 'idempotency_key')->havingRaw('count(*) > 1')->get()->count();
$check('R one link per (season, campaign) and one notification per (user, semantic idempotency key)', $dupLinks === 0 && $dupNotes === 0, "dup-links={$dupLinks} dup-notifications={$dupNotes}");

$pending = SeasonAnnouncement::query()->whereNotNull('notify_audience')->whereNull('notified_at')->whereNotNull('published_at')->where('published_at', '<=', now())->count();
$unclaimed = SeasonPhase::query()->whereNull('went_live_at')->where('starts_at', '<=', now()->subMinute())->whereHas('season', fn ($q) => $q->publiclyVisible())->count();
app(SeasonProcessor::class)->run();
$stillPending = SeasonAnnouncement::query()->whereNotNull('notify_audience')->whereNull('notified_at')->whereNotNull('published_at')->where('published_at', '<=', now())->count();
$check('R a final sequential processor run leaves no announcement un-notified and no due phase unclaimed (idempotent, nothing left behind by the races)', $stillPending === 0, "before-final-run: announcements={$pending} phases={$unclaimed}; after: announcements={$stillPending}");

$sqlVisible = Season::query()->publiclyVisible()->count();
$phpVisible = Season::query()->with('campaign')->get()->filter(fn (Season $s) => $lifecycle->isPubliclyVisible($s))->count();
$check('R season visibility is identical in SQL and PHP on real MySQL', $sqlVisible === $phpVisible && $sqlVisible > 0, "sql={$sqlVisible} php={$phpVisible}");

$unstarted = Season::query()->where('is_published', true)->whereNull('went_live_at')->count();
$check('R every published live season ended up with went_live_at set (the hook, the sync command or the race winner)', $unstarted === 0, "unstarted={$unstarted}");

Cache::flush();

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

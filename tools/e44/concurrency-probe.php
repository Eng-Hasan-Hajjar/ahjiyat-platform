<?php

/*
|--------------------------------------------------------------------------
| E44 - مسبار التزامن الحقيقي على MySQL (عمليات PHP منفصلة تتسابق فعلًا) لدوري كتّاب الموسم وتقدير الداعمين
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، وقفل الصفوف (lockForUpdate) لا أثر له على SQLite، فلا سباق حقيقي بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليات تبدأ في اللحظة نفسها (لا شبكة ولا مزوّد دفع ولا بث؛ خدمات E44 الرسمية فقط):
|   W1  ناخب واحد يوزّع 6 نجوم على كاتب مختلف من أربع عمليات (إحداها مكررة)   ← ميزانية الـ10 لا تُتجاوز أبدًا: كاتب واحد يفوز بـ6 والباقي budget_exceeded
|   W2  إعادة توزيع الناخب نفسه على الكاتب نفسه بقيم مختلفة معًا               ← صف توزيع واحد، المصروف = قيمة الصف، مجاميع الكاتب تطابق الدفتر
|   W3  ستة ناخبين مختلفين على كاتب واحد معًا                                  ← النجوم والمصوّتون يساوون المجموع بلا فقدان تحديث
|   W4  ناخبان كلٌّ يوزّع على كاتبين بترتيبين متعاكسين                          ← لا جمود (1213) ولا انتظار قفل (1205)، وكل ميزانية صُرفت كاملة
|   W5  سحب كاتب من القائمة ↔ أربعة ناخبين يوزّعون له                           ← إما نجوم قبل السحب أو entry_inactive؛ المجاميع تطابق الدفتر والميزانيات متّسقة
|   W6  ثلاث عمليات إنهاء لدوري مغلق معًا                                       ← إنهاء واحد فقط، مراتب متصلة، جائزة مالية واحدة (التزام) لا مكرر، إشعار واحد لكل كاتب
|   S1  منح المصدر نفسه من أربع عمليات                                          ← قيد واحد ونقاط تُحتسب مرة واحدة
|   S2  أربعة مصادر مختلفة لمستخدم واحد معًا                                    ← مجموع صحيح بلا فقدان تحديث
|   S3  عكس المصدر نفسه من أربع عمليات                                          ← قيد عكس واحد والمجاميع تعود
|   S4  منح ↔ عكس ↔ منح لمستخدم جديد                                            ← مجاميع كل صف إحصاء = مجموع الدفتر
|   S5  إنهاء ترتيب الداعمين (×2) ↔ منح لمستخدمين جدد ولقدامى ↔ عكس متأخر       ← إنهاء واحد، لا صف إحصاء يتيم بلا ختم، لقطة متصلة، المنح بعد الإنهاء مرفوض
|   R   المطابقة للدوري والداعمين بعد كل السباقات = لا فروق + فحوص سلامة نهائية (دفتر ↔ مجاميع)
|
| ملاحظة: الزمن الحقيقي لا يُقدَّم داخل عمليات منفصلة، لذا «مرور الوقت» (إغلاق نافذة التصويت) يُحاكى بكتابة مباشرة واحدة لعمود الإغلاق في قاعدة الاختبار المؤقتة؛
|         وكذلك إدراج محاولات اللعب (دليل أهلية التصويت). غير ذلك كله يمرّ بالخدمات الرسمية.
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - كاش الملفات المشترك بين العمليات في مجلد مؤقت خاص بالمسبار فقط؛ لا يمسّ كاش التطبيق.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط. لا اتصال شبكة.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/seasonal-recognition-operations-runbook.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e44/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e44/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 4)
*/

use App\Models\Campaign;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeries;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Models\SeasonSupportPointEntry;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Creators\CreatorReviewService;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\SeasonalRecognition\SeasonalRecognitionException;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonSupportPointService;
use App\Services\SeasonalRecognition\SeasonSupporterFinalizer;
use App\Services\SeasonalRecognition\SeasonSupporterReconciler;
use App\Services\SeasonalRecognition\SeasonWriterFinalizer;
use App\Services\SeasonalRecognition\SeasonWriterReconciler;
use App\Services\SeasonalRecognition\SeasonWriterRosterService;
use App\Services\SeasonalRecognition\SeasonWriterVotingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env، وتُورَّث للعمليات العاملة. الكاش ملفات (يُشارك بين العمليات) في مجلد خاص بالمسبار.
$probeCacheDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'e44-probe-cache';

foreach ([
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'SEASON_WRITERS_ENABLED' => 'true', 'SEASON_SUPPORTERS_ENABLED' => 'true',
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

        while (microtime(true) < $at) {
            usleep(200);
        }

        // تأخير اختياري بعد الحاجز (ميلي ثانية) لتنويع التقاطعات الزمنية بين العمليات في السباق نفسه.
        if (isset($args['delay'])) {
            usleep(max(0, min(500, (int) $args['delay'])) * 1000);
        }

        $started = microtime(true);

        if ($kind === 'vote') {
            $entry = SeasonWriterEntry::query()->findOrFail((int) $args['entry']);
            $r = app(SeasonWriterVotingService::class)->allocate($actor, $entry, (int) $args['stars']);
            $result = ['outcome' => 'ok', 'stars' => $r['stars'], 'spent' => $r['spent'], 'delta' => $r['delta']];
        } elseif ($kind === 'remove') {
            $entry = SeasonWriterEntry::query()->findOrFail((int) $args['entry']);
            $result = ['outcome' => 'ok', 'status' => app(SeasonWriterRosterService::class)->removeWriter($entry, $actor)];
        } elseif ($kind === 'finalize-writers') {
            $r = app(SeasonWriterFinalizer::class)->finalize(Season::query()->findOrFail((int) $args['season']), $actor);
            $result = ['outcome' => 'ok', 'finalized' => $r['finalized'], 'already' => $r['already']];
        } elseif ($kind === 'finalize-supporters') {
            $r = app(SeasonSupporterFinalizer::class)->finalize(Season::query()->findOrFail((int) $args['season']), $actor);
            $result = ['outcome' => 'ok', 'finalized' => $r['finalized'], 'already' => $r['already']];
        } elseif ($kind === 'grant') {
            $entry = app(SeasonSupportPointService::class)->grant((int) $args['season'], (int) $args['user'], SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, (int) $args['source'], (int) $args['points'], ((string) ($args['public'] ?? '1')) === '1');
            $result = ['outcome' => 'ok', 'granted' => $entry !== null];
        } elseif ($kind === 'reverse') {
            $entry = app(SeasonSupportPointService::class)->reverse(SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, (int) $args['source']);
            $result = ['outcome' => 'ok', 'reversed' => $entry !== null];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof SeasonalRecognitionException;
        // لا نطبع رسائل SQL (قد تحمل قيم): اسم الفهرس المخالَف فقط لأخطاء 1062، ورمز السبب للاستثناءات الدومينية.
        $detail = '';
        if ($code === 1062 && preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $detail = $m[1];
        } elseif ($domain) {
            $detail = (string) $e->reason;
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
$finRounds = max(2, intdiv($rounds, 2));
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ').$name.($detail !== '' ? '  - '.$detail : '')."\n";
};

echo '== E44 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh) وكاش المسبار...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);
Cache::flush(); // مجلد كاش المسبار الخاص فقط (ليس كاش التطبيق).

$verified = function (User $u): User {
    $u->forceFill(['email_verified_at' => now()])->save();

    return $u->fresh();
};
$admin = $verified(User::factory()->create(['email' => 'probe-admin@seasonal-concurrency.test']));
$admin->syncRoles(['administrator']);
$admin = $admin->fresh();
$reviewer = $verified(User::factory()->create(['email' => 'probe-reviewer@seasonal-concurrency.test']));
$reviewer->syncRoles(['moderator']);

$settings = app(SeasonRecognitionSettingsService::class);
$roster = app(SeasonWriterRosterService::class);
$points = app(SeasonSupportPointService::class);
$voting = app(SeasonWriterVotingService::class);
$writerRec = app(SeasonWriterReconciler::class);
$supporterRec = app(SeasonSupporterReconciler::class);

// ── مساعدات سباق ──
$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r))->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
/** @param list<string> $allowed أسباب الرفض المقبولة لهذا السباق (أي رفض آخر = مخالفة) */
$cleanRaces = function (string $label, array $rs, array $allowed = []) use ($check, $crashes, $lockErrors, $unexpected, $refusals): void {
    $check("{$label} no worker crash, no deadlock (1213) / lock timeout (1205)", $crashes($rs) === 0 && $lockErrors($rs) === 0, 'crash='.$crashes($rs).' lock-errors='.$lockErrors($rs));
    $check("{$label} no unexpected exception (the loser is a clean domain refusal or idempotent)", $unexpected($rs) === 0, 'unexpected='.$unexpected($rs).' refusals='.$refusals($rs));
    $stray = collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused' && ! in_array($r['detail'] ?? '', $allowed, true))->count();
    $check("{$label} every refusal is an expected reason (".implode('|', $allowed ?: ['none']).')', $stray === 0, 'unexpected-refusals='.$stray);
};

// ── مشاهد الكتّاب ──
$category = PuzzleCategory::query()->create(['name' => 'مسبار', 'slug' => 'probe', 'sort_order' => 1, 'is_active' => true]);
$applications = app(CreatorApplicationService::class);
$puzzles = app(CreatorPuzzleService::class);
$reviewPuzzle = app(CreatorReviewService::class);
$seriesService = app(CreatorSeriesService::class);
$reviewSeries = app(CreatorSeriesReviewService::class);
$creatorSerial = 0;
$seasonSerial = 0;

/** مبدع معتمَد بعملين منشورين وسلسلة منشورة. @return array{user: User, profile: CreatorProfile, works: list<CreatorPuzzle>, series: CreatorSeries} */
$makeCreator = function () use (&$creatorSerial, $verified, $applications, $reviewer, $puzzles, $reviewPuzzle, $category, $seriesService, $reviewSeries): array {
    $creatorSerial++;
    $user = $verified(User::factory()->create(['email' => "probe-creator-{$creatorSerial}@seasonal-concurrency.test", 'name' => "كاتب المسبار {$creatorSerial}"]));
    $profile = $applications->apply($user, 'مبدع مسبار دوري الكتّاب — نبذة كافية الطول.');
    $applications->approve($profile, $reviewer, 'مرحبًا بك مبدعًا.');
    $profile = $profile->fresh();

    $works = [];
    $ids = [];

    for ($i = 1; $i <= 2; $i++) {
        $draft = $puzzles->createDraft($user, ['kind' => 'text', 'difficulty' => 'easy', 'title' => "لغز مسبار {$creatorSerial}-{$i}", 'puzzle_category_id' => $category->id, 'prompt' => 'ما الشيء الذي يكتب ولا يقرأ؟', 'hint' => 'تجده على المكتب', 'answer' => 'القلم']);
        $reviewPuzzle->approve($puzzles->submit($user, $draft->work->public_id), $reviewer, 'معتمدة.');
        $ids[] = $draft->work->public_id;
        $works[] = CreatorPuzzle::query()->where('public_id', $draft->work->public_id)->firstOrFail();
    }

    $revision = $seriesService->createDraft($user, ['title' => "سلسلة مسبار {$creatorSerial}", 'description' => 'سلسلة لاختبار التزامن.', 'items' => $ids]);
    $revision = $seriesService->submit($user, $revision->series()->value('public_id'));
    $reviewSeries->approve($revision, $reviewer);

    return ['user' => $user, 'profile' => $profile, 'works' => $works, 'series' => $revision->series()->firstOrFail()];
};

/** موسم منشور بنافذة صريحة نسبية للآن + صف إعدادات. */
$makeSeason = function (string $tag, string $starts, string $ends) use (&$seasonSerial, $settings): Season {
    $seasonSerial++;
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => "حملة مسبار {$seasonSerial}"]);
    $season = Season::factory()->create([
        'campaign_id' => $campaign->id, 'code' => 'PE'.$seasonSerial, 'slug' => "e44-probe-{$tag}-{$seasonSerial}", 'is_published' => true,
        'starts_at' => now()->modify($starts), 'ends_at' => now()->modify($ends),
    ]);
    $settings->ensure($season);

    return $season->fresh();
};

/**
 * يُفعّل الدوري ويضيف الكتّاب ويربط سلاسلهم ثم يفتح نافذة التصويت (الفتح بعد الربط بثانية كي تأتي محاولات اللعب لاحقًا لوقت الربط).
 *
 * @param  list<array{user: User, profile: CreatorProfile, works: list<CreatorPuzzle>, series: CreatorSeries}>  $creators
 * @return list<array{entry: SeasonWriterEntry, creator: array}>
 */
$openLeague = function (Season $season, array $creators, array $extra = []) use ($settings, $roster, $admin): array {
    $settings->saveWriterSettings($season, $extra + ['enabled' => true, 'capacity' => 40, 'eliminated_count' => 0], $admin);
    $out = [];

    foreach ($creators as $creator) {
        $entry = $roster->addWriter($season, $creator['profile'], $admin);
        $roster->linkSeries($entry, $creator['series'], $admin);
        $out[] = ['entry' => $entry->fresh(), 'creator' => $creator];
    }

    sleep(1);
    $settings->saveWriterSettings($season, ['opens_at' => now()->subMinutes(5), 'closes_at' => now()->addDays(20)], $admin);

    return $out;
};

/** ناخب موثَّق بدليل لعب (محاولة حقيقية في قاعدة الاختبار) على أول عمل لكل كاتب في $league. @param list<array{entry: SeasonWriterEntry, creator: array}> $league */
$makeVoter = function (array $league) use ($verified): User {
    $voter = $verified(User::factory()->create());

    foreach ($league as $row) {
        $work = $row['creator']['works'][0];
        DB::table('community_puzzle_attempts')->insert([
            'user_id' => $voter->id, 'creator_puzzle_id' => $work->id, 'puzzle_id' => $work->published_puzzle_id,
            'attempt_number' => 1, 'is_correct' => 1, 'created_at' => now(),
        ]);
    }

    return $voter;
};

$ledgerSum = fn (int $entryId): int => (int) DB::table('season_writer_star_allocations')->where('writer_entry_id', $entryId)->sum('stars');
$spentOf = fn (Season $s, User $v): int => (int) DB::table('season_writer_vote_budgets')->where('season_id', $s->id)->where('user_id', $v->id)->value('spent_stars');
$allocRows = fn (Season $s, User $v) => DB::table('season_writer_star_allocations')->where('season_id', $s->id)->where('voter_user_id', $v->id)->get();
$vote = fn (User $v, SeasonWriterEntry $e, int $stars, int $delay = 0): array => ['vote', ["--actor={$v->id}", "--entry={$e->id}", "--stars={$stars}", "--delay={$delay}"]];

// ── المشهد V: دوري مفتوح بأربعة كتّاب + ضحايا السحب (W5) ──
echo "\nتجهيز دوري التصويت المفتوح (كتّاب بمحتوى منشور وسلاسل مربوطة)...\n";
$pool = [];

for ($i = 0; $i < 4; $i++) {
    $pool[] = $makeCreator();
}

$victims = [];

for ($i = 0; $i < $rounds; $i++) {
    $victims[] = $makeCreator();
}

$seasonV = $makeSeason('vote', '-10 days', '+30 days');
$leagueAll = $openLeague($seasonV, array_merge($pool, $victims));
$mainLeague = array_slice($leagueAll, 0, 4);
$victimLeague = array_slice($leagueAll, 4);
[$wa, $wb, $wc, $wd] = array_map(fn ($row) => $row['entry'], $mainLeague);

// ── W1 ──
echo "\nW1: ناخب واحد يطلب 6 نجوم لثلاثة كتّاب مختلفين (ومكرر لأحدهم) معًا — الميزانية 10\n";
$w1 = [];
$w1Ok = 0;
$w1One = 0;

for ($i = 0; $i < $rounds; $i++) {
    $voter = $makeVoter($mainLeague);
    $specs = [$vote($voter, $wa, 6), $vote($voter, $wb, 6), $vote($voter, $wc, 6), $vote($voter, $wa, 6, 5)];
    shuffle($specs);
    $rs = probe_race($specs);
    $w1 = array_merge($w1, $rs);
    $rows = $allocRows($seasonV, $voter);
    $w1Ok += ($spentOf($seasonV, $voter) === 6 && (int) $rows->sum('stars') === 6) ? 1 : 0;
    $w1One += ($rows->count() === 1 && (int) $rows->first()->stars === 6) ? 1 : 0;
}

$cleanRaces('W1', $w1, ['budget_exceeded']);
$check('W1 the 10-star budget is never exceeded: exactly 6 stars were spent (one winner), never 12', $w1Ok === $rounds, "ok={$w1Ok}/{$rounds}");
$check('W1 exactly one allocation row of 6 stars survives per voter (the duplicate request is idempotent, the other writers were refused)', $w1One === $rounds, "ok={$w1One}/{$rounds}");

// ── W2 ──
echo "\nW2: الناخب نفسه يعيد توزيع النجوم على الكاتب نفسه بقيم مختلفة (3/7/10/1) معًا\n";
$w2 = [];
$w2Ok = 0;
$w2Agg = 0;

for ($i = 0; $i < $rounds; $i++) {
    $voter = $makeVoter($mainLeague);
    $specs = [$vote($voter, $wb, 3), $vote($voter, $wb, 7, 3), $vote($voter, $wb, 10, 6), $vote($voter, $wb, 1, 9)];
    shuffle($specs);
    $rs = probe_race($specs);
    $w2 = array_merge($w2, $rs);
    $rows = $allocRows($seasonV, $voter);
    $final = (int) ($rows->first()->stars ?? -1);
    $w2Ok += ($rows->count() === 1 && in_array($final, [3, 7, 10, 1], true) && $spentOf($seasonV, $voter) === $final) ? 1 : 0;
    $w2Agg += (collect($rs)->where('outcome', 'ok')->count() === 4) ? 1 : 0;
}

$cleanRaces('W2', $w2);
$check('W2 one allocation row; the budget spent equals that row (no drift between rows and the budget under parallel re-allocation)', $w2Ok === $rounds, "ok={$w2Ok}/{$rounds}");
$check('W2 every re-allocation succeeded (a final value within the budget is never refused)', $w2Agg === $rounds, "ok={$w2Agg}/{$rounds}");

// ── W3 ──
echo "\nW3: ستة ناخبين مختلفين يمنحون الكاتب نفسه 5 نجوم معًا\n";
$w3 = [];
$w3Ok = 0;
$w3Counts = 0;

for ($i = 0; $i < $rounds; $i++) {
    $before = [(int) DB::table('season_writer_entries')->where('id', $wc->id)->value('stars_total'), (int) DB::table('season_writer_entries')->where('id', $wc->id)->value('voters_count')];
    $voters = [];
    $specs = [];

    for ($k = 0; $k < 6; $k++) {
        $v = $makeVoter($mainLeague);
        $voters[] = $v;
        $specs[] = $vote($v, $wc, 5, $k * 2);
    }

    $rs = probe_race($specs);
    $w3 = array_merge($w3, $rs);
    $row = DB::table('season_writer_entries')->where('id', $wc->id)->first();
    $w3Ok += ((int) $row->stars_total === $before[0] + 30 && (int) $row->voters_count === $before[1] + 6) ? 1 : 0;
    $w3Counts += ((int) $row->stars_total === $ledgerSum($wc->id)) ? 1 : 0;
}

$cleanRaces('W3', $w3);
$check('W3 six parallel voters add exactly 30 stars and 6 voters (no lost update on the writer aggregate)', $w3Ok === $rounds, "ok={$w3Ok}/{$rounds}");
$check('W3 the stored aggregate equals the allocation ledger sum after every round', $w3Counts === $rounds, "ok={$w3Counts}/{$rounds}");

// ── W4 ──
echo "\nW4: ناخبان كلٌّ يوزّع على كاتبين بترتيبين متعاكسين معًا (اختبار جمود)\n";
$w4 = [];
$w4Ok = 0;

for ($i = 0; $i < $finRounds * 2; $i++) {
    $x = $makeVoter($mainLeague);
    $y = $makeVoter($mainLeague);
    $specs = [$vote($x, $wa, 5), $vote($x, $wd, 5, 4), $vote($y, $wd, 5), $vote($y, $wa, 5, 4)];
    shuffle($specs);
    $rs = probe_race($specs);
    $w4 = array_merge($w4, $rs);
    $w4Ok += ($spentOf($seasonV, $x) === 10 && $spentOf($seasonV, $y) === 10 && $allocRows($seasonV, $x)->count() === 2 && $allocRows($seasonV, $y)->count() === 2) ? 1 : 0;
}

$w4Total = $finRounds * 2;
$cleanRaces('W4', $w4);
$check('W4 both voters spent their whole budget across the two writers (nothing refused, nothing lost)', $w4Ok === $w4Total, "ok={$w4Ok}/{$w4Total}");

// ── W5 ──
echo "\nW5: سحب كاتب من القائمة ↔ أربعة ناخبين يوزّعون له\n";
$w5 = [];
$w5Ok = 0;
$w5Withdrawn = 0;
$w5Budgets = 0;

foreach ($victimLeague as $i => $row) {
    $victim = $row['entry'];
    $voters = [];
    $specs = [['remove', ["--actor={$admin->id}", "--entry={$victim->id}"]]];

    for ($k = 0; $k < 4; $k++) {
        $v = $makeVoter($mainLeague);
        $voters[] = $v;
        // الناخب يحتاج دليل لعب لسلسلة الضحية أيضًا.
        $work = $row['creator']['works'][0];
        DB::table('community_puzzle_attempts')->insert(['user_id' => $v->id, 'creator_puzzle_id' => $work->id, 'puzzle_id' => $work->published_puzzle_id, 'attempt_number' => 1, 'is_correct' => 1, 'created_at' => now()]);
        $specs[] = $vote($v, $victim, 3, $k * 3);
    }

    shuffle($specs);
    $rs = probe_race($specs);
    $w5 = array_merge($w5, $rs);
    $fresh = DB::table('season_writer_entries')->where('id', $victim->id)->first();
    $w5Withdrawn += ($fresh->status === 'withdrawn') ? 1 : 0;
    $w5Ok += ((int) $fresh->stars_total === $ledgerSum($victim->id) && (int) $fresh->voters_count === DB::table('season_writer_star_allocations')->where('writer_entry_id', $victim->id)->count()) ? 1 : 0;
    $w5Budgets += collect($voters)->every(fn (User $v) => $spentOf($seasonV, $v) === (int) $allocRows($seasonV, $v)->sum('stars')) ? 1 : 0;
}

$w5Total = count($victimLeague);
$cleanRaces('W5', $w5, ['entry_inactive', 'roster_locked']);
$check('W5 the writer ends withdrawn (the withdrawal always wins eventually, never lost)', $w5Withdrawn === $w5Total, "ok={$w5Withdrawn}/{$w5Total}");
$check('W5 the writer aggregate equals the allocation ledger (stars and voters) whichever side won the race', $w5Ok === $w5Total, "ok={$w5Ok}/{$w5Total}");
$check('W5 every voter budget equals the sum of that voter allocations', $w5Budgets === $w5Total, "ok={$w5Budgets}/{$w5Total}");

// ── W6: إنهاء الدوري المغلق من ثلاث عمليات ──
echo "\nW6: ثلاث عمليات إنهاء لدوري مغلق معًا (جائزة مالية للمركز الأول)\n";
$w6 = [];
$w6Once = 0;
$w6Rows = 0;
$w6Awards = 0;
$w6Notes = 0;
$w6Clean = 0;
$noteCount = fn (string $type): int => (int) DB::table('notifications')->where('type_key', $type)->count();

for ($f = 0; $f < $finRounds; $f++) {
    $seasonF = $makeSeason('final', '-10 days', '+30 days');
    $league = $openLeague($seasonF, $pool, ['reward_plan' => [['placement' => 'first_place', 'kind' => 'monetary', 'label' => 'جائزة المركز الأول من المسبار', 'amount_minor' => 5000, 'currency' => 'USD']]]);

    // الأصوات بالخدمة الرسمية: الأول 8 نجوم (ناخبان)، الثاني والثالث 5 لكلٍّ (تعادل يحسمه الترتيب الحتمي)، الرابع بلا نجوم.
    foreach ([[0, 4], [0, 4], [1, 5], [2, 5]] as [$idx, $stars]) {
        $v = $makeVoter($league);
        $voting->allocate($v, $league[$idx]['entry']->fresh(), $stars);
    }

    // «مرور الوقت»: إغلاق النافذة بكتابة مباشرة واحدة (انظر ملاحظة الرأس).
    DB::table('season_recognition_settings')->where('season_id', $seasonF->id)->update(['writers_voting_closes_at' => now()->subMinute()]);

    $notesBefore = $noteCount('writer_final_standing');
    $rs = probe_race([['finalize-writers', ["--actor={$admin->id}", "--season={$seasonF->id}"]], ['finalize-writers', ["--actor={$admin->id}", "--season={$seasonF->id}", '--delay=4']], ['finalize-writers', ["--actor={$admin->id}", "--season={$seasonF->id}", '--delay=9']]]);
    $w6 = array_merge($w6, $rs);

    $finalized = collect($rs)->where('finalized', true)->count();
    $already = collect($rs)->where('already', true)->count();
    $w6Once += ($finalized === 1 && $already === 2) ? 1 : 0;

    $ranks = DB::table('season_writer_entries')->where('season_id', $seasonF->id)->orderBy('final_rank')->pluck('final_rank')->map(fn ($r) => (int) $r)->all();
    $first = DB::table('season_writer_entries')->where('season_id', $seasonF->id)->where('final_rank', 1)->first();
    $w6Rows += ($ranks === [1, 2, 3, 4] && $first !== null && $first->outcome === 'first_place' && (int) $first->final_stars === 8 && DB::table('season_writer_entries')->where('season_id', $seasonF->id)->whereNull('finalized_at')->count() === 0) ? 1 : 0;
    $awards = DB::table('season_writer_awards')->where('season_id', $seasonF->id)->get();
    $w6Awards += ($awards->count() === 1 && $awards->first()->kind === 'monetary' && $awards->first()->status === 'pending_finance' && (int) $awards->first()->amount_minor === 5000 && (int) $awards->first()->writer_entry_id === (int) $first->id) ? 1 : 0;
    $w6Notes += ($noteCount('writer_final_standing') - $notesBefore === 4) ? 1 : 0;
    $w6Clean += ($writerRec->inspect($seasonF) === []) ? 1 : 0;
}

$cleanRaces('W6', $w6, ['finalized']);
$check('W6 exactly one finalizer wins; the others see the ranking already final (no double finalization)', $w6Once === $finRounds, "ok={$w6Once}/{$finRounds}");
$check('W6 final ranks are contiguous 1..4, rank 1 = the 8-star writer, every entry stamped with its final snapshot', $w6Rows === $finRounds, "ok={$w6Rows}/{$finRounds}");
$check('W6 exactly one monetary award obligation (pending finance, 5000 minor units = 50.00 USD) for the winner — never duplicated, nothing paid automatically', $w6Awards === $finRounds, "ok={$w6Awards}/{$finRounds}");
$check('W6 exactly one final-standing notification per ranked writer', $w6Notes === $finRounds, "ok={$w6Notes}/{$finRounds}");
$check('W6 writers reconciliation is clean after finalization', $w6Clean === $finRounds, "ok={$w6Clean}/{$finRounds}");

// ── مشهد الداعمين ──
echo "\nتجهيز مواسم الداعمين...\n";
$seasonS = $makeSeason('support', '-10 days', '+30 days');
$settings->setSupportersEnabled($seasonS, true, $admin);
$supporters = [];

for ($i = 0; $i < 12; $i++) {
    $supporters[] = $verified(User::factory()->create(['name' => 'داعم المسبار '.($i + 1)]));
}

$sourceSerial = 1000;
$nextSource = function () use (&$sourceSerial): int {
    return ++$sourceSerial;
};
$statOf = fn (int $seasonId, int $userId) => DB::table('season_supporter_stats')->where('season_id', $seasonId)->where('user_id', $userId)->first();
$entriesOf = fn (int $seasonId, int $userId) => DB::table('season_support_point_entries')->where('season_id', $seasonId)->where('user_id', $userId)->get();
$grantSpec = fn (int $seasonId, User $u, int $source, int $pts, bool $public = true, int $delay = 0): array => ['grant', ["--season={$seasonId}", "--user={$u->id}", "--source={$source}", "--points={$pts}", '--public='.($public ? '1' : '0'), "--delay={$delay}"]];
$reverseSpec = fn (int $source, int $delay = 0): array => ['reverse', ["--source={$source}", "--delay={$delay}"]];

// ── S1 ──
echo "\nS1: منح المصدر نفسه من أربع عمليات معًا\n";
$s1 = [];
$s1Ok = 0;
$s1Won = 0;

for ($i = 0; $i < $rounds; $i++) {
    $user = $supporters[$i % 12];
    $before = (int) ($statOf($seasonS->id, $user->id)->points_total ?? 0);
    $source = $nextSource();
    $rs = probe_race(array_map(fn ($d) => $grantSpec($seasonS->id, $user, $source, 40, true, $d), [0, 2, 5, 9]));
    $s1 = array_merge($s1, $rs);
    $s1Won += (collect($rs)->where('granted', true)->count() === 1) ? 1 : 0;
    $s1Ok += (DB::table('season_support_point_entries')->where('source_key', SeasonSupportPointEntry::grantKey(SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $source))->count() === 1
        && (int) $statOf($seasonS->id, $user->id)->points_total === $before + 40) ? 1 : 0;
}

$cleanRaces('S1', $s1);
$check('S1 exactly one of the four requests wrote the grant (the rest were idempotent no-ops)', $s1Won === $rounds, "ok={$s1Won}/{$rounds}");
$check('S1 one ledger entry and the user total grew by exactly the points once', $s1Ok === $rounds, "ok={$s1Ok}/{$rounds}");

// ── S2 ──
echo "\nS2: أربعة مصادر مختلفة لمستخدم واحد معًا\n";
$s2 = [];
$s2Ok = 0;

for ($i = 0; $i < $rounds; $i++) {
    $user = $supporters[($i + 4) % 12];
    $before = $statOf($seasonS->id, $user->id);
    $b = [(int) ($before->points_total ?? 0), (int) ($before->public_points_total ?? 0)];
    $specs = [$grantSpec($seasonS->id, $user, $nextSource(), 10), $grantSpec($seasonS->id, $user, $nextSource(), 20, false, 3), $grantSpec($seasonS->id, $user, $nextSource(), 30, true, 6), $grantSpec($seasonS->id, $user, $nextSource(), 40, true, 9)];
    $rs = probe_race($specs);
    $s2 = array_merge($s2, $rs);
    $after = $statOf($seasonS->id, $user->id);
    $s2Ok += ((int) $after->points_total === $b[0] + 100 && (int) $after->public_points_total === $b[1] + 80 && collect($rs)->where('granted', true)->count() === 4) ? 1 : 0;
}

$cleanRaces('S2', $s2);
$check('S2 four parallel grants add exactly 100 points (80 public) — no lost update on the stat row', $s2Ok === $rounds, "ok={$s2Ok}/{$rounds}");

// ── S3 ──
echo "\nS3: عكس المصدر نفسه من أربع عمليات معًا\n";
$s3 = [];
$s3Ok = 0;
$s3One = 0;

for ($i = 0; $i < $rounds; $i++) {
    $user = $supporters[($i + 8) % 12];
    $source = $nextSource();
    $points->grant($seasonS->id, $user->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $source, 70, true);
    $before = (int) $statOf($seasonS->id, $user->id)->points_total;
    $rs = probe_race(array_map(fn ($d) => $reverseSpec($source, $d), [0, 2, 5, 9]));
    $s3 = array_merge($s3, $rs);
    $s3One += (collect($rs)->where('reversed', true)->count() === 1) ? 1 : 0;
    $s3Ok += (DB::table('season_support_point_entries')->where('source_key', SeasonSupportPointEntry::reversalKey(SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $source))->count() === 1
        && (int) $statOf($seasonS->id, $user->id)->points_total === $before - 70) ? 1 : 0;
}

$cleanRaces('S3', $s3);
$check('S3 exactly one reversal request wrote the negative entry', $s3One === $rounds, "ok={$s3One}/{$rounds}");
$check('S3 one reversal entry and the user total dropped by exactly the original 70 points once', $s3Ok === $rounds, "ok={$s3Ok}/{$rounds}");

// ── S4 ──
echo "\nS4: عكس مصدر ↔ منح مصدر آخر للمستخدم نفسه ↔ منح لمستخدم جديد\n";
$s4 = [];
$s4Ok = 0;

for ($i = 0; $i < $rounds; $i++) {
    $user = $supporters[$i % 12];
    $fresh = $verified(User::factory()->create());
    $old = $nextSource();
    $points->grant($seasonS->id, $user->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $old, 25, true);
    $specs = [$reverseSpec($old), $grantSpec($seasonS->id, $user, $nextSource(), 15, true, 3), $grantSpec($seasonS->id, $fresh, $nextSource(), 50, true, 6), $grantSpec($seasonS->id, $user, $nextSource(), 5, false, 9)];
    shuffle($specs);
    $rs = probe_race($specs);
    $s4 = array_merge($s4, $rs);
    $s4Ok += (collect($rs)->where('granted', true)->count() === 3 && collect($rs)->where('reversed', true)->count() === 1) ? 1 : 0;
}

$cleanRaces('S4', $s4);
$check('S4 every grant and the reversal took effect exactly once', $s4Ok === $rounds, "ok={$s4Ok}/{$rounds}");

// ── S5 ──
echo "\nS5: إنهاء ترتيب الداعمين (×2) ↔ منح لمستخدمين جدد ولقدامى ↔ عكس متأخر\n";
$s5 = [];
$s5Once = 0;
$s5NoStray = 0;
$s5Snapshot = 0;
$s5Ledger = 0;
$s5Clean = 0;
$s5Notes = 0;
$s5Diag = '';

for ($f = 0; $f < $finRounds; $f++) {
    $seasonE = $makeSeason('ended', '-40 days', '-1 days');
    $settings->setSupportersEnabled($seasonE, true, $admin);
    $old = [$supporters[($f * 3) % 12], $supporters[($f * 3 + 1) % 12], $supporters[($f * 3 + 2) % 12]];

    // قبل الإنهاء: ثلاثة داعمين قدامى بنقاط مختلفة (الأول بمنحين، أحدهما سيُعكس لاحقًا).
    $reversible = $nextSource();
    $points->grant($seasonE->id, $old[0]->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $reversible, 300, true);
    $points->grant($seasonE->id, $old[0]->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $nextSource(), 100, true);
    $points->grant($seasonE->id, $old[1]->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $nextSource(), 250, true);
    $points->grant($seasonE->id, $old[2]->id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, $nextSource(), 90, false); // نقاط غير علنية: خارج الترتيب العام

    $newUsers = [$verified(User::factory()->create()), $verified(User::factory()->create()), $verified(User::factory()->create())];
    $notesBefore = $noteCount('supporter_final_rank');

    $specs = [
        ['finalize-supporters', ["--actor={$admin->id}", "--season={$seasonE->id}"]],
        ['finalize-supporters', ["--actor={$admin->id}", "--season={$seasonE->id}", '--delay=2']],
        $grantSpec($seasonE->id, $newUsers[0], $nextSource(), 500, true, 0),
        $grantSpec($seasonE->id, $newUsers[1], $nextSource(), 400, true, 3),
        $grantSpec($seasonE->id, $newUsers[2], $nextSource(), 20, true, 6),
        $grantSpec($seasonE->id, $old[1], $nextSource(), 60, true, 1),
        $grantSpec($seasonE->id, $old[2], $nextSource(), 30, true, 5),
        $reverseSpec($reversible, 4),
    ];
    shuffle($specs);
    $rs = probe_race($specs, 4.0);
    $s5 = array_merge($s5, $rs);

    $finalizers = collect($rs)->filter(fn ($r) => array_key_exists('finalized', $r));
    $s5Once += ($finalizers->where('finalized', true)->count() === 1 && DB::table('season_recognition_settings')->where('season_id', $seasonE->id)->whereNotNull('supporters_finalized_at')->count() === 1) ? 1 : 0;

    // لا صف إحصاء يتيم: كل صف إما مختوم بالإنهاء أو لا قيود له إطلاقًا (المنح المرفوض بعد الإنهاء لا يترك أثرًا).
    $unstamped = DB::table('season_supporter_stats')->where('season_id', $seasonE->id)->whereNull('finalized_at')->get();
    $s5NoStray += ($unstamped->every(fn ($r) => $entriesOf($seasonE->id, (int) $r->user_id)->isEmpty() && (int) $r->points_total === 0)) ? 1 : 0;

    // اللقطة: المراتب متصلة من 1، ومرتبة تنازليًا بالنقاط النهائية، ولا يحمل رتبة إلا من له نقاط علنية.
    $ranked = DB::table('season_supporter_stats')->where('season_id', $seasonE->id)->whereNotNull('final_rank')->orderBy('final_rank')->get();
    $contiguous = $ranked->pluck('final_rank')->map(fn ($r) => (int) $r)->values()->all() === range(1, $ranked->count());
    $descending = $ranked->pluck('final_points')->map(fn ($p) => (int) $p)->values()->all() === $ranked->pluck('final_points')->map(fn ($p) => (int) $p)->sortDesc()->values()->all();
    $positive = $ranked->every(fn ($r) => (int) $r->final_points > 0);
    $stamped = DB::table('season_supporter_stats')->where('season_id', $seasonE->id)->whereNotNull('finalized_at')->get();
    $finalMatches = $stamped->every(function ($r) use ($old): bool {
        // الداعم الأول وحده قد يختلف فيه الحيّ عن النهائي (العكس المتأخر يمسّ الحيّ فقط)؛ البقية يساوي نهائيها علنيّها الحيّ إلا ما منُح قبل الإنهاء ثم زاد لاحقًا (مرفوض بعد الإنهاء).
        if ((int) $r->user_id === (int) $old[0]->id) {
            return in_array((int) $r->final_points, [400, 100], true) && (int) $r->public_points_total === 100;
        }

        return (int) $r->final_points === (int) $r->public_points_total;
    });
    $s5Snapshot += ($ranked->count() > 0 && $contiguous && $descending && $positive && $finalMatches) ? 1 : 0;

    // الدفتر هو السلطة: مجاميع كل صف = مجموع قيوده (الحيّ)، وعدد القيود = المنح الناجح + عكس واحد + قيود ما قبل الإنهاء.
    $mismatch = DB::table('season_supporter_stats as s')
        ->leftJoin(DB::raw('(select user_id, sum(points_delta) as p, sum(case when is_public = 1 then points_delta else 0 end) as pp from season_support_point_entries where season_id = '.(int) $seasonE->id.' group by user_id) e'), 'e.user_id', '=', 's.user_id')
        ->where('s.season_id', $seasonE->id)
        ->whereRaw('(s.points_total <> coalesce(e.p, 0) or s.public_points_total <> coalesce(e.pp, 0))')->count();
    $grantedRacing = collect($rs)->where('granted', true)->count();
    $reversedOnce = collect($rs)->where('reversed', true)->count();
    $entryTotal = DB::table('season_support_point_entries')->where('season_id', $seasonE->id)->count();
    $s5Ledger += ($mismatch === 0 && $entryTotal === 4 + $grantedRacing + $reversedOnce) ? 1 : 0;
    $s5Diag = "last: stat-vs-ledger-mismatch={$mismatch} entries={$entryTotal} expected=".(4 + $grantedRacing + $reversedOnce)." (granted-racing={$grantedRacing} reversed={$reversedOnce})";

    $rankedCount = $ranked->count();
    $s5Notes += ($noteCount('supporter_final_rank') - $notesBefore === $rankedCount) ? 1 : 0;
    $s5Clean += ($supporterRec->inspect($seasonE) === []) ? 1 : 0;
}

$cleanRaces('S5', $s5, ['finalized']);
$check('S5 exactly one finalizer wrote the snapshot (the second saw it already final)', $s5Once === $finRounds, "ok={$s5Once}/{$finRounds}");
$check('S5 no orphan stat row: a grant refused after finalization leaves neither an entry nor an unstamped stat row (the race that previously left a snapshot gap)', $s5NoStray === $finRounds, "ok={$s5NoStray}/{$finRounds}");
$check('S5 the final snapshot is contiguous from 1, ordered by final points, positive-only, and equals the live public total (except the late-reversed supporter)', $s5Snapshot === $finRounds, "ok={$s5Snapshot}/{$finRounds}");
$check('S5 every stat row equals its ledger sum and the entry count = pre-finalization grants + successful racing grants + one reversal', $s5Ledger === $finRounds, "ok={$s5Ledger}/{$finRounds} ".($s5Ledger === $finRounds ? '' : $s5Diag));
$check('S5 exactly one final-rank notification per ranked supporter', $s5Notes === $finRounds, "ok={$s5Notes}/{$finRounds}");
$check('S5 supporters reconciliation is clean after the race', $s5Clean === $finRounds, "ok={$s5Clean}/{$finRounds}");

// ── R: المطابقة والسلامة النهائية ──
echo "\nR: المطابقة بعد كل السباقات\n";
$writerFindings = $writerRec->inspect($seasonV, 200);
$check('R writers reconciliation of the voting season reports no drift (aggregates = ledger, budgets = allocations)', $writerFindings === [], $writerFindings === [] ? '' : 'first='.json_encode($writerFindings[0], JSON_UNESCAPED_UNICODE));
$supporterFindings = $supporterRec->inspect($seasonS, 200);
$check('R supporters reconciliation of the live season reports no drift (stats = ledger, reversals match originals)', $supporterFindings === [], $supporterFindings === [] ? '' : 'first='.json_encode($supporterFindings[0], JSON_UNESCAPED_UNICODE));

$check('R no voter ever spent more than the 10-star budget and no allocation is outside 1..10', DB::table('season_writer_vote_budgets')->whereColumn('spent_stars', '>', 'total_stars')->count() === 0
    && DB::table('season_writer_star_allocations')->where(fn ($q) => $q->where('stars', '<', 1)->orWhere('stars', '>', 10))->count() === 0);
$check('R no negative support-point total anywhere and ledger source keys are unique', DB::table('season_supporter_stats')->where(fn ($q) => $q->where('points_total', '<', 0)->orWhere('public_points_total', '<', 0))->count() === 0
    && DB::table('season_support_point_entries')->count() === DB::table('season_support_point_entries')->distinct()->count('source_key'));
$check('R at most one reversal per grant and every reversal mirrors its original (same user, season, negated points)', DB::table('season_support_point_entries as r')->join('season_support_point_entries as g', 'g.id', '=', 'r.reverses_entry_id')
    ->whereRaw('(r.user_id <> g.user_id or r.season_id <> g.season_id or r.points_delta <> -g.points_delta)')->count() === 0
    && DB::table('season_support_point_entries')->whereNotNull('reverses_entry_id')->count() === DB::table('season_support_point_entries')->whereNotNull('reverses_entry_id')->distinct()->count('reverses_entry_id'));

Cache::flush();

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);

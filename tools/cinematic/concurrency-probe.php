<?php

/*
|--------------------------------------------------------------------------
| CIN-1 - مسبار التزامن الحقيقي على MySQL للسلاسل السينمائية (عمليات PHP منفصلة تتسابق فعلًا)
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة واحدة في عملية واحدة، وقفل الصفوف (lockForUpdate) والفرادة لا يُختبران بين اتصالين حقيقيين على SQLite.
| هذا السكربت يلتزم بالبيانات فعلًا ويطلق عمليات تبدأ في اللحظة نفسها (لا شبكة ولا مزوّد ولا دفع):
|   Q1  إكمال المشهد نفسه ×N (مشهد سرد)             ← صف حالة واحد مكتمل، وقت إكمال واحد، كل العمليات تنتهي دون انهيار (إتمام متكرر ساكن)
|   Q2  إجابة صحيحة للتفاعل نفسه ×N                   ← إكمال واحد فقط، لا صفوف حالة مكررة
|   Q3  بدء التقدّم نفسه ×N (المستخدم نفسه)           ← صف تقدّم واحد لـ(مستخدم، قصة، نسخة) وكل العمليات تعيد المعرّف نفسه
|   Q4  بدء N مستخدمين مختلفين معًا                    ← صف لكل مستخدم، بلا تداخل
|   Q5  نشر النسخة نفسها ×N                           ← نسخة منشورة واحدة للقصة (live_slot)، عملية واحدة ناجحة، السابقة مستبدلة
|   R   فحوص سلامة نهائية: لا تقدّم يتيم، لا مشهد مكتمل مرتين، لا اقتصاد (جواهر/خبرة/محافظ) تغيّر
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - كاش الملفات المشترك بين العمليات في مجلد مؤقت خاص بالمسبار فقط.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط.
|
| التشغيل (من جذر المشروع، PowerShell):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/cinematic/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/cinematic/concurrency-probe.php --rounds=8 # عدد العمليات المتسابقة (الافتراضي 6)
*/

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStory;
use App\Models\User;
use App\Services\Cinematic\CinematicAuthoringService;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicPlayService;
use App\Services\Cinematic\CinematicPublishingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$probeCacheDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'cin-probe-cache';

foreach (['CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'CINEMATIC_STORIES_ENABLED' => 'true'] as $envKey => $envValue) {
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

if (($error = probe_guard()) !== null) {
    fwrite(STDERR, $error."\n");
    exit(2);
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
        $user = isset($args['user']) ? User::query()->findOrFail((int) $args['user']) : null;
        $story = isset($args['story']) ? CinematicStory::query()->findOrFail((int) $args['story']) : null;
        $play = app(CinematicPlayService::class);

        while (microtime(true) < $at) {
            usleep(200);
        }

        if (isset($args['delay'])) {
            usleep(max(0, min(500, (int) $args['delay'])) * 1000);
        }

        switch ($kind) {
            case 'begin':
                $progress = $play->begin($user, $story);
                $result = ['outcome' => 'ok', 'progress' => $progress->id];
                break;

            case 'finish':
                $progress = $play->progressFor($user, $story);
                $scene = CinematicScene::query()->where('revision_id', $progress->revision_id)->where('stable_key', (string) $args['scene'])->firstOrFail();
                $play->finish($progress, $scene, 'read');
                $result = ['outcome' => 'ok'];
                break;

            case 'submit':
                $progress = $play->progressFor($user, $story);
                $scene = CinematicScene::query()->where('revision_id', $progress->revision_id)->where('stable_key', (string) $args['scene'])->firstOrFail();
                $state = $play->submit($progress, $scene, ['item' => (string) $args['item']]);
                $result = ['outcome' => 'ok', 'correct' => (bool) ($state['result']['correct'] ?? false)];
                break;

            case 'publish':
                $revision = CinematicRevision::query()->findOrFail((int) $args['revision']);
                app(CinematicPublishingService::class)->publish($revision, null);
                $result = ['outcome' => 'ok'];
                break;

            default:
                $result = ['outcome' => 'bad_worker'];
        }
    } catch (CinematicException $e) {
        $result = ['outcome' => 'domain_error', 'reason' => $e->reason];
    } catch (Throwable $e) {
        $result = ['outcome' => 'crash', 'type' => get_class($e), 'detail' => substr($e->getMessage(), 0, 160)];
    }

    echo json_encode($result)."\n";

    return 0;
}

if (isset($args['worker'])) {
    exit(probe_worker($args));
}

$rounds = max(2, min(20, (int) ($args['rounds'] ?? 6)));
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ').$name.($detail !== '' ? '  - '.$detail : '')."\n";
};

echo '== CIN-1 concurrency probe (MySQL: '.DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh) وكاش المسبار...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Cache::flush();

$verified = function (User $u): User {
    $u->forceFill(['email_verified_at' => now()])->save();

    return $u->fresh();
};
$player = fn () => $verified(User::factory()->create());
$admin = $verified(User::factory()->create());

$economy = function (): array {
    $out = [];

    foreach (['gem_transactions', 'xp_transactions', 'puzzle_attempts'] as $table) {
        $out[$table] = Illuminate\Support\Facades\Schema::hasTable($table) ? DB::table($table)->count() : -1;
    }

    // المحافظ تُنشأ مع المستخدمين (لا نقارن عددها)؛ المهم ألا يتغيّر مجموع الرصيد.
    $out['wallet_balance_sum'] = Illuminate\Support\Facades\Schema::hasTable('wallets') ? (int) DB::table('wallets')->sum('available_balance') : -1;

    return $out;
};
$economyBefore = $economy();

// ── مشهد: قصة بثلاثة مشاهد (سرد، اختيار، سرد) منشورة، ونسخة ثانية قيد المراجعة لسباق النشر ──
$authoring = app(CinematicAuthoringService::class);
$publishing = app(CinematicPublishingService::class);
$story = $authoring->createStory(['title' => 'مسبار التزامن', 'slug' => 'probe-story'], $admin);
$rev1 = $story->revisions()->firstOrFail();
$stage = $authoring->addStage($rev1, ['title' => 'مرحلة', 'stable_key' => 'st_one']);
$chapter = $authoring->addChapter($stage, ['title' => 'فصل', 'stable_key' => 'ch_one']);
$authoring->addScene($chapter, ['type' => 'narrative', 'title' => 'سرد أ', 'stable_key' => 'beat_a', 'config' => ['lines' => [['speaker' => '', 'text' => 'نص أ']]]]);
$quiz = $authoring->addScene($chapter, ['type' => 'interaction', 'title' => 'اختيار', 'stable_key' => 'quiz_it', 'config' => ['interaction' => ['kind' => 'choose_item', 'prompt' => 'اختر', 'items' => [['id' => 'aa', 'label' => 'أ'], ['id' => 'bb', 'label' => 'ب']]]]]);
$errors = $authoring->setInteractionSolution($quiz, ['correct' => 'bb']);
$authoring->addScene($chapter, ['type' => 'narrative', 'title' => 'سرد ج', 'stable_key' => 'beat_c', 'config' => ['lines' => [['speaker' => '', 'text' => 'نص ج']]]]);

if ($errors !== []) {
    fwrite(STDERR, 'solution setup failed: '.implode(' | ', $errors)."\n");
    exit(2);
}

$publishing->submitForReview($rev1->fresh());
$live = $publishing->publish($rev1->fresh(), $admin);
$storyId = (string) $story->id;

// ── Q3: بدء التقدّم نفسه معًا ──
echo "-- Q3: بدء التقدّم نفسه ×{$rounds}\n";
$racer = $player();
$res = probe_race(array_map(fn () => ['begin', ["--user={$racer->id}", "--story={$storyId}"]], range(1, $rounds)));
$ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r['progress'] ?? null, $res))));
$check('Q3 كل العمليات نجحت', count(array_filter($res, fn ($r) => ($r['outcome'] ?? '') === 'ok')) === $rounds, json_encode(array_count_values(array_column($res, 'outcome'))));
$check('Q3 صف تقدّم واحد فقط للمستخدم/القصة/النسخة', CinematicProgress::query()->where('user_id', $racer->id)->where('story_id', $story->id)->where('revision_id', $live->id)->count() === 1);
$check('Q3 كل العمليات أعادت المعرّف نفسه', count($ids) === 1, 'ids='.implode(',', $ids));

// ── Q1: إكمال المشهد نفسه معًا ──
echo "-- Q1: إكمال مشهد السرد نفسه ×{$rounds}\n";
$res = probe_race(array_map(fn () => ['finish', ["--user={$racer->id}", "--story={$storyId}", '--scene=beat_a']], range(1, $rounds)));
$sceneA = CinematicScene::query()->where('revision_id', $live->id)->where('stable_key', 'beat_a')->firstOrFail();
$rows = CinematicSceneState::query()->where('scene_id', $sceneA->id)->get();
$check('Q1 لا انهيار في أي عملية', count(array_filter($res, fn ($r) => in_array($r['outcome'] ?? '', ['ok', 'domain_error'], true))) === $rounds, json_encode(array_count_values(array_column($res, 'outcome'))));
$check('Q1 صف حالة واحد للمشهد', $rows->count() === 1);
$check('Q1 المشهد مكتمل مرة واحدة بوقت إكمال واحد', $rows->count() === 1 && $rows->first()->completed_at !== null);

// ── Q2: إجابة صحيحة للتفاعل نفسه معًا ──
echo "-- Q2: إجابة صحيحة للتفاعل نفسه ×{$rounds}\n";
$res = probe_race(array_map(fn () => ['submit', ["--user={$racer->id}", "--story={$storyId}", '--scene=quiz_it', '--item=bb']], range(1, $rounds)));
$sceneQ = CinematicScene::query()->where('revision_id', $live->id)->where('stable_key', 'quiz_it')->firstOrFail();
$rows = CinematicSceneState::query()->where('scene_id', $sceneQ->id)->get();
$check('Q2 لا انهيار في أي عملية', count(array_filter($res, fn ($r) => in_array($r['outcome'] ?? '', ['ok', 'domain_error'], true))) === $rounds, json_encode(array_count_values(array_column($res, 'outcome'))));
$check('Q2 صف حالة واحد للتفاعل', $rows->count() === 1);
$check('Q2 مكتمل مرة واحدة', $rows->count() === 1 && $rows->first()->completed_at !== null && (int) $rows->first()->attempts_count >= 1);

// ── Q4: مستخدمون مختلفون معًا ──
echo "-- Q4: بدء {$rounds} مستخدمين مختلفين معًا\n";
$users = array_map(fn () => $player(), range(1, $rounds));
$res = probe_race(array_map(fn (User $u) => ['begin', ["--user={$u->id}", "--story={$storyId}"]], $users));
$check('Q4 كل العمليات نجحت', count(array_filter($res, fn ($r) => ($r['outcome'] ?? '') === 'ok')) === $rounds, json_encode(array_count_values(array_column($res, 'outcome'))));
$check('Q4 صف تقدّم لكل مستخدم (بلا تداخل)', CinematicProgress::query()->whereIn('user_id', array_map(fn (User $u) => $u->id, $users))->count() === $rounds
    && CinematicProgress::query()->whereIn('user_id', array_map(fn (User $u) => $u->id, $users))->distinct()->count('user_id') === $rounds);

// ── Q5: نشر النسخة نفسها معًا ──
echo "-- Q5: نشر النسخة الجديدة نفسها ×{$rounds}\n";
$rev2 = $authoring->createDraft($story->fresh(), $admin, $live->fresh());
$publishing->submitForReview($rev2->fresh());
$res = probe_race(array_map(fn () => ['publish', ["--revision={$rev2->id}"]], range(1, $rounds)));
$okCount = count(array_filter($res, fn ($r) => ($r['outcome'] ?? '') === 'ok'));
$crashes = count(array_filter($res, fn ($r) => ! in_array($r['outcome'] ?? '', ['ok', 'domain_error'], true)));
$published = CinematicRevision::query()->where('story_id', $story->id)->where('status', CinematicRevision::STATUS_PUBLISHED)->get();
$check('Q5 لا انهيار (ناجحة أو رفض مجال)', $crashes === 0, json_encode(array_count_values(array_column($res, 'outcome'))));
$check('Q5 نسخة منشورة واحدة للقصة', $published->count() === 1 && $published->first()->id === $rev2->id);
$check('Q5 عملية واحدة على الأقل نجحت والسابقة مستبدلة', $okCount >= 1 && CinematicRevision::query()->whereKey($live->id)->value('status') === CinematicRevision::STATUS_SUPERSEDED);
$check('Q5 لا نسختان بـ live_slot', CinematicRevision::query()->where('story_id', $story->id)->whereNotNull('live_slot')->count() === 1);

// ── R: سلامة نهائية ──
echo "-- R: فحوص سلامة نهائية\n";
$dupes = DB::table('cinematic_scene_states')->select('progress_id', 'scene_id')->groupBy('progress_id', 'scene_id')->havingRaw('count(*) > 1')->count();
$orphans = DB::table('cinematic_progress')->leftJoin('cinematic_revisions', 'cinematic_revisions.id', '=', 'cinematic_progress.revision_id')->whereNull('cinematic_revisions.id')->count();
$check('R لا صفوف حالة مكررة', $dupes === 0);
$check('R لا تقدّم يتيم', $orphans === 0);
$check('R لا اقتصاد تغيّر (جواهر/خبرة/محافظ/محاولات)', $economy() === $economyBefore);

$failed = count(array_filter($checks, fn ($c) => ! $c[1]));
echo "\n== النتيجة: ".(count($checks) - $failed).'/'.count($checks).' فحصًا ناجحًا'.($failed === 0 ? '' : " ({$failed} فشل)")." ==\n";

exit($failed === 0 ? 0 : 1);

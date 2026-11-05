<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| CIN-1 - حدود السلاسل السينمائية في القاعدة نفسها. سائق-محايد: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (خطوة mysql-parity في CI).
| تثبت: نسخة منشورة واحدة لكل قصة (live_slot) مع تعايش NULL، فرادة رقم النسخة، تقدّم واحد لكل (مستخدم، قصة، نسخة)، حالة واحدة لكل (تقدّم، مشهد)،
| حل واحد لكل مشهد، ملف واحد لكل (دور، بصمة)، مفتاح مشهد ثابت واحد في النسخة، سياسة المفاتيح الأجنبية (RESTRICT للتاريخ، CASCADE للبنية والتقدّم الشخصي، NULL للوسائط)،
| النص العربي والرموز التعبيرية، أعمدة JSON، لا float/decimal، والحل مشفَّر في القاعدة. السباق الحقيقي بين العمليات في tools/cinematic/concurrency-probe.php.
|--------------------------------------------------------------------------
*/

beforeEach(fn () => cin_seed());

function cindb_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/** يستنسخ صفًّا موجودًا بتعديلات (لقياس القيود لا الخدمات): يزيل id ويجدد public_id إن وُجد. */
function cindb_clone(string $table, int $id, array $over = []): int
{
    $row = (array) DB::table($table)->where('id', $id)->first();
    unset($row['id']);

    if (array_key_exists('public_id', $row)) {
        $row['public_id'] = (string) Str::ulid();
    }

    return (int) DB::table($table)->insertGetId($over + $row);
}

function cindb_driver(): string
{
    return DB::connection()->getDriverName();
}

/** @return array{story: \App\Models\Cinematic\CinematicStory, revision: \App\Models\Cinematic\CinematicRevision, scene: CinematicScene, progress: CinematicProgress, state: CinematicSceneState, user: \App\Models\User} */
function cindb_world(): array
{
    $w = cin_story();
    $scene = cin_narrative($w['chapter'], 'beat_one');
    $live = cin_publish($w['revision']);
    [$progress, $user] = cin_begin($w['story']);
    cin_play()->finish($progress, $scene->fresh(), 'read');

    return ['story' => $w['story']->fresh(), 'revision' => $live->fresh(), 'scene' => $scene->fresh(), 'progress' => $progress->fresh(), 'state' => CinematicSceneState::query()->where('progress_id', $progress->id)->firstOrFail(), 'user' => $user];
}

test('CINDB-01: only one published revision per story can exist (live_slot), several NULL slots coexist, and a revision number is unique per story', function () {
    $w = cindb_world();
    $live = $w['revision'];

    expect($live->live_slot)->toBe(1);

    // نسخة منشورة ثانية للقصة نفسها مرفوضة، لكن عدة نسخ بلا live_slot (مسودات/مستبدلة) مسموحة.
    expect(cindb_violates(fn () => cindb_clone('cinematic_revisions', $live->id, ['revision_number' => 50, 'live_slot' => 1])))->toBeTrue();
    expect(cindb_violates(fn () => cindb_clone('cinematic_revisions', $live->id, ['revision_number' => 51, 'live_slot' => null, 'status' => 'draft'])))->toBeFalse();
    expect(cindb_violates(fn () => cindb_clone('cinematic_revisions', $live->id, ['revision_number' => 52, 'live_slot' => null, 'status' => 'superseded'])))->toBeFalse();

    // رقم النسخة فريد ضمن القصة.
    expect(cindb_violates(fn () => cindb_clone('cinematic_revisions', $live->id, ['live_slot' => null, 'status' => 'draft'])))->toBeTrue();

    // قصة أخرى لها نسختها المنشورة الخاصة بها (القيد لكل قصة لا عام).
    $other = cin_story();
    cin_narrative($other['chapter'], 'beat_one');
    expect(cin_publish($other['revision'])->live_slot)->toBe(1);
});

test('CINDB-02: duplicate progress, scene state, solution, media file, or scene key is rejected by the database itself', function () {
    $w = cindb_world();
    $media = cin_video_asset();
    $sc = cin_interaction(cin_story()['chapter'], 'dup_quiz', ...cin_choose('b'));

    expect(cindb_violates(fn () => cindb_clone('cinematic_progress', $w['progress']->id)))->toBeTrue()
        ->and(cindb_violates(fn () => cindb_clone('cinematic_scene_states', $w['state']->id)))->toBeTrue()
        ->and(cindb_violates(fn () => DB::table('cinematic_scene_solutions')->insert(['scene_id' => $sc->id, 'payload' => 'x', 'created_at' => now(), 'updated_at' => now()])))->toBeTrue()
        ->and(cindb_violates(fn () => cindb_clone('cinematic_media_assets', $media->id)))->toBeTrue()
        ->and(cindb_violates(fn () => cindb_clone('cinematic_scenes', $w['scene']->id)))->toBeTrue();

    // نفس الملف بدور مختلف مسموح (القيد على الدور والبصمة معًا).
    expect(cindb_violates(fn () => cindb_clone('cinematic_media_assets', $media->id, ['role' => 'audio'])))->toBeFalse();
});

test('CINDB-03: foreign-key policy - history is RESTRICTed, structure and personal progress cascade, media links null out', function () {
    $w = cindb_world();
    $media = cin_video_asset();
    $film = cin_video_scene(cin_story()['chapter'], 'film_fk', [], $media);

    // التاريخ لا يُحذف تتابعيًا: قصة بنسخ، نسخة بتقدّم، مشهد بحالة لاعب.
    expect(cindb_violates(fn () => DB::table('cinematic_stories')->where('id', $w['story']->id)->delete()))->toBeTrue()
        ->and(cindb_violates(fn () => DB::table('cinematic_revisions')->where('id', $w['revision']->id)->delete()))->toBeTrue()
        ->and(cindb_violates(fn () => DB::table('cinematic_scenes')->where('id', $w['scene']->id)->delete()))->toBeTrue();

    // حذف ملف وسيط يفك الربط ولا يحذف المشهد.
    DB::table('cinematic_media_assets')->where('id', $media->id)->delete();
    expect(DB::table('cinematic_scenes')->where('id', $film->id)->value('video_asset_id'))->toBeNull()->and(DB::table('cinematic_scenes')->where('id', $film->id)->exists())->toBeTrue();

    // حذف حساب لاعب يحذف تقدّمه وحالات مشاهده فقط، ويُبقي القصة والمشاهد.
    DB::table('users')->where('id', $w['user']->id)->delete();
    expect(DB::table('cinematic_progress')->where('id', $w['progress']->id)->exists())->toBeFalse()
        ->and(DB::table('cinematic_scene_states')->where('id', $w['state']->id)->exists())->toBeFalse()
        ->and(DB::table('cinematic_scenes')->where('id', $w['scene']->id)->exists())->toBeTrue();

    // مسودة بلا تقدّم تُحذف ببنيتها كاملة (مراحل/فصول/مشاهد/حلول).
    $draft = cin_story();
    cin_interaction($draft['chapter'], 'draft_quiz', ...cin_choose('b'));
    $revisionId = $draft['revision']->id;
    DB::table('cinematic_revisions')->where('id', $revisionId)->delete();
    expect(DB::table('cinematic_stages')->where('revision_id', $revisionId)->count())->toBe(0)
        ->and(DB::table('cinematic_scenes')->where('revision_id', $revisionId)->count())->toBe(0);
});

test('CINDB-04: Arabic text and emoji round-trip intact through string, text and JSON columns, and no column is float, double or decimal', function () {
    $w = cin_story(['title' => 'مكتبة 📚 الأسرار']);
    $text = str_repeat('نص عربي طويل ', 40).'🔑✨';
    $scene = cin_narrative($w['chapter'], 'arabic_beat', ['title' => 'مشهد «الباب» 🚪', 'config' => ['lines' => [['speaker' => 'آدم', 'text' => $text]]]]);

    $fresh = CinematicScene::query()->findOrFail($scene->id);
    expect($fresh->title)->toBe('مشهد «الباب» 🚪')->and($fresh->config['lines'][0]['text'])->toBe(mb_substr($text, 0, 600))
        ->and($w['story']->fresh()->title)->toBe('مكتبة 📚 الأسرار');

    foreach (Schema::getTableListing() as $table) {
        $table = Str::afterLast($table, '.');

        if (! str_starts_with($table, 'cinematic_')) {
            continue;
        }

        foreach (Schema::getColumns($table) as $column) {
            expect(strtolower((string) $column['type_name']))->not->toBeIn(['float', 'double', 'decimal', 'real', 'numeric'], "{$table}.{$column['name']}");
        }
    }
});

test('CINDB-05: the solution is stored encrypted - the raw database value never contains the plaintext answer or the code', function () {
    $w = cin_story();
    $scene = cin_interaction($w['chapter'], 'secret_code', ['kind' => 'reveal_code', 'prompt' => 'الرمز'], ['code' => 'ZX-9981-PLAIN']);
    $raw = (string) DB::table('cinematic_scene_solutions')->where('scene_id', $scene->id)->value('payload');

    expect($raw)->not->toBe('')->and($raw)->not->toContain('ZX-9981-PLAIN')->and($raw)->not->toContain('reveal')
        ->and(\App\Models\Cinematic\CinematicSceneSolution::query()->where('scene_id', $scene->id)->firstOrFail()->payload['code'])->toBe('ZX-9981-PLAIN');
});

test('CINDB-06: on MySQL the foreign-key delete rules and column types are exactly as designed (information_schema)', function () {
    if (cindb_driver() !== 'mysql') {
        expect(true)->toBeTrue();   // القواعد نفسها اختُبرت سلوكيًا في CINDB-03 على أي سائق؛ هذا الفحص يخص MySQL فقط.

        return;
    }

    $db = (string) DB::connection()->getDatabaseName();
    $rules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $db)->where('TABLE_NAME', 'like', 'cinematic\\_%')
        ->get(['TABLE_NAME', 'CONSTRAINT_NAME', 'DELETE_RULE'])->keyBy(fn ($r) => $r->TABLE_NAME.'.'.$r->CONSTRAINT_NAME);

    expect($rules)->not->toBeEmpty();

    $byColumn = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', 'like', 'cinematic\\_%')->whereNotNull('REFERENCED_TABLE_NAME')
        ->get(['TABLE_NAME', 'COLUMN_NAME', 'CONSTRAINT_NAME', 'REFERENCED_TABLE_NAME'])
        ->mapWithKeys(fn ($r) => [$r->TABLE_NAME.'.'.$r->COLUMN_NAME => $rules[$r->TABLE_NAME.'.'.$r->CONSTRAINT_NAME]->DELETE_RULE]);

    expect($byColumn['cinematic_stories.creator_series_id'])->toBe('RESTRICT')
        ->and($byColumn['cinematic_revisions.story_id'])->toBe('RESTRICT')
        ->and($byColumn['cinematic_progress.revision_id'])->toBe('RESTRICT')
        ->and($byColumn['cinematic_progress.story_id'])->toBe('RESTRICT')
        ->and($byColumn['cinematic_progress.user_id'])->toBe('CASCADE')
        ->and($byColumn['cinematic_scene_states.progress_id'])->toBe('CASCADE')
        ->and($byColumn['cinematic_scene_states.scene_id'])->toBe('RESTRICT')
        ->and($byColumn['cinematic_scenes.video_asset_id'])->toBe('SET NULL')
        ->and($byColumn['cinematic_scenes.revision_id'])->toBe('CASCADE');

    $types = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', 'like', 'cinematic\\_%')
        ->get(['TABLE_NAME', 'COLUMN_NAME', 'DATA_TYPE'])->mapWithKeys(fn ($r) => [$r->TABLE_NAME.'.'.$r->COLUMN_NAME => strtolower($r->DATA_TYPE)]);

    expect($types['cinematic_scenes.config'])->toBe('json')->and($types['cinematic_scenes.unlock_rule'])->toBe('json')->and($types['cinematic_media_assets.meta'])->toBe('json')
        ->and($types['cinematic_scene_solutions.payload'])->toBe('text');

    $charsets = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', 'like', 'cinematic\\_%')->whereNotNull('CHARACTER_SET_NAME')->pluck('CHARACTER_SET_NAME')->unique()->values()->all();
    expect($charsets)->toBe(['utf8mb4']);
});

test('CINDB-07: the real-race probe exists, is MySQL-only with a hard test-database guard, and is wired into CI after the existing probes', function () {
    $probe = (string) File::get(base_path('tools/cinematic/concurrency-probe.php'));
    $ci = (string) File::get(base_path('.github/workflows/ci.yml'));

    expect($probe)->toContain('_test')->toContain('migrate:fresh')->toContain('APP_ENV=production')->and(preg_match('/phpunit|pest\s/', $probe))->toBe(0)
        ->and($ci)->toContain('tools/cinematic/concurrency-probe.php')->toContain('tests/Feature/CinematicStories/CinMysqlSchemaTest.php')
        ->and(substr_count($ci, 'tools/cinematic/concurrency-probe.php'))->toBe(1)
        ->and(strpos($ci, 'tools/cinematic/concurrency-probe.php'))->toBeGreaterThan(strpos($ci, 'tools/e43/concurrency-probe.php'));
});

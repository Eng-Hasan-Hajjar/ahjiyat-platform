<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\CinematicStructure;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| CIN-1-FIX - ملف المحتوى المراجَع الجديد (database/demo/cinematic/library-mystery-stage-1-plm-revised.json).
| اختبارات جديدة فقط: لا تمس اختبارات DEMO الحالية التي تثبّت ملف demo الأصلي (19 مشهدًا، 10 فيديوهات، pos_3).
|  - ملف demo الأصلي لم يتغيّر بايتًا بايت (بصمة خط الأساس a78a5d3).
|  - الملف المراجَع يطابق الأصل في كل شيء عدا قائمة بيضاء صريحة (مدد/ملاحظات إنتاج/intro_lines/حل pos_5).
|  - يُبنى في قاعدة الاختبار المعزولة وحدها عبر خدمات التأليف الرسمية (لا قاعدة إنتاج، ولا أداة demo على قاعدة موجودة).
|  - لا نشر ولا وسائط ولا اقتصاد؛ الحلول المرجعية تُكمل المرحلة عبر HTTP بوسائط مؤقتة داخل الاختبار فقط.
*/

beforeEach(fn () => cin_seed());

const CIN_REV_ORIGINAL_SHA256 = 'eaab1039b7f3563f0dd8f4ffde82d248d289f8302dc43c1211d4de7be9ef65e0';

/** @return array<string, mixed> */
function cin_rev_def(): array
{
    return json_decode((string) file_get_contents(database_path('demo/cinematic/library-mystery-stage-1-plm-revised.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function cin_rev_original(): array
{
    return json_decode((string) file_get_contents(database_path('demo/cinematic/library-mystery-stage-1.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, array<string, mixed>> المشاهد مفهرسة بـstable_key */
function cin_rev_scenes(array $def): array
{
    $out = [];

    foreach ($def['chapters'] as $chapter) {
        foreach ($chapter['scenes'] as $scene) {
            $out[$scene['stable_key']] = $scene;
        }
    }

    return $out;
}

/** @return array<string, mixed> مسارات منقّطة -> قيم ورقية (المصفوفة الفارغة ورقة). */
function cin_rev_flat(mixed $value, string $path = ''): array
{
    if (is_array($value) && $value !== []) {
        $out = [];

        foreach ($value as $key => $item) {
            $out += cin_rev_flat($item, $path === '' ? (string) $key : $path.'.'.$key);
        }

        return $out;
    }

    return [$path => $value];
}

/** يبني التعريف في قاعدة الاختبار فقط عبر خدمات التأليف الرسمية (مرآة لمنطق المثبّت دون استعماله). @return array{story: CinematicStory, revision: CinematicRevision} */
function cin_rev_install(array $def): array
{
    return DB::transaction(function () use ($def) {
        $story = cin_authoring()->createStory((array) $def['story'], null);
        $revision = $story->revisions()->orderBy('id')->firstOrFail();
        $stage = cin_authoring()->addStage($revision, (array) $def['stage']);

        foreach ($def['chapters'] as $chapterDef) {
            $chapter = cin_authoring()->addChapter($stage, ['stable_key' => $chapterDef['stable_key'], 'title' => $chapterDef['title'], 'summary' => $chapterDef['summary'] ?? null]);

            foreach ($chapterDef['scenes'] as $sceneDef) {
                $scene = cin_authoring()->addScene($chapter, [
                    'stable_key' => $sceneDef['stable_key'],
                    'type' => $sceneDef['type'],
                    'title' => $sceneDef['title'],
                    'duration_hint_seconds' => $sceneDef['duration_hint_seconds'] ?? null,
                    'awaiting_media' => (bool) ($sceneDef['awaiting_media'] ?? false),
                    'unlock_rule' => $sceneDef['unlock_rule'] ?? [],
                    'config' => $sceneDef['config'] ?? [],
                ]);

                if (is_array($sceneDef['solution'] ?? null)) {
                    $errors = cin_authoring()->setInteractionSolution($scene, $sceneDef['solution']);

                    if ($errors !== []) {
                        throw new RuntimeException("solution {$sceneDef['stable_key']}: ".implode(' | ', $errors));
                    }
                }
            }
        }

        return ['story' => $story, 'revision' => $revision->fresh()];
    });
}

/** @return array<string, mixed> إجابة مرجعية مشتقة من الحل في التعريف. */
function cin_rev_answer(array $scene): array
{
    $s = $scene['solution'];

    return match ($scene['config']['interaction']['kind']) {
        'hotspot' => ['area' => $s['correct'][0]],
        'container' => ['area' => $s['correct']],
        'sequence' => ['order' => $s['order']],
        'drag_drop' => ['placements' => $s['map']],
        'choose_item' => ['item' => $s['correct']],
        'compare' => ['option' => $s['correct']],
        'flashlight' => ['points' => array_map(fn ($t) => ['x' => $t['x'] + $t['w'] / 2, 'y' => $t['y'] + $t['h'] / 2], array_slice($s['targets'], 0, (int) ($s['required'] ?? count($s['targets']))))],
    };
}

test('REV-01: the original demo file is untouched byte for byte (the baseline fingerprint) and the installer still reads only that file', function () {
    $path = database_path('demo/cinematic/library-mystery-stage-1.json');

    expect(hash_file('sha256', $path))->toBe(CIN_REV_ORIGINAL_SHA256)
        ->and(Stage1DemoInstaller::definitionPath())->toBe($path)
        ->and(cin_rev_original()['chapters'])->not->toBeEmpty();

    // لا كود ولا إعداد يقرأ الملف المراجَع: هو مرجع محتوى فقط.
    foreach ([app_path(), config_path(), base_path('routes'), database_path('seeders'), database_path('factories'), database_path('migrations')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                expect(str_contains((string) file_get_contents($file->getPathname()), 'plm-revised'))->toBeFalse($file->getPathname());
            }
        }
    }
});

test('REV-02: the revised file differs from the original ONLY inside an explicit allow-list - same scenes, same order, same unlock rules, same click areas, same solutions except the approved pos_5 target', function () {
    $old = cin_rev_flat(cin_rev_original());
    $new = cin_rev_flat(cin_rev_def());

    $allowed = [
        '/^revision(\.|$)/',
        '/^chapters\.\d+\.scenes\.\d+\.duration_hint_seconds$/',
        '/^chapters\.\d+\.scenes\.\d+\.provisional(\.\d+)?$/',
        '/^chapters\.\d+\.scenes\.\d+\.production(\.(planning_duration|poster_required|plm_split|plm_note|symbol|transition|plm_visual_note))?$/',
        '/^chapters\.\d+\.scenes\.\d+\.config\.intro_lines\.0\.(speaker|text)$/',
        '/^chapters\.\d+\.scenes\.\d+\.solution\.map\.moved_book$/',
    ];

    $changed = array_unique(array_merge(
        array_keys(array_diff_key($new, $old)),
        array_keys(array_diff_key($old, $new)),
        array_keys(array_filter($new, fn ($v, $k) => array_key_exists($k, $old) && $old[$k] !== $v, ARRAY_FILTER_USE_BOTH)),
    ));

    expect($changed)->not->toBeEmpty();

    foreach ($changed as $path) {
        $ok = collect($allowed)->contains(fn ($re) => preg_match($re, $path) === 1);
        expect($ok)->toBeTrue("unexpected change at {$path}");
    }

    $oldScenes = cin_rev_scenes(cin_rev_original());
    $newScenes = cin_rev_scenes(cin_rev_def());

    expect(array_keys($newScenes))->toBe(array_keys($oldScenes))->and($newScenes)->toHaveCount(19);

    foreach ($newScenes as $key => $scene) {
        expect($scene['unlock_rule'])->toBe($oldScenes[$key]['unlock_rule'], $key)->and($scene['type'])->toBe($oldScenes[$key]['type'], $key)
            ->and($scene['config']['interaction'] ?? null)->toBe($oldScenes[$key]['config']['interaction'] ?? null, $key);

        if ($key !== 'riddle_2_move') {
            expect($scene['solution'])->toBe($oldScenes[$key]['solution'], "solution {$key}");
        }
    }
});

test('REV-03: the approved content decisions are present - V03 as two scenes, hourglass book 5 with a temporary pos_5 target, T02-T04 in intro_lines, V06 transition corrected, 214 s planning durations', function () {
    $def = cin_rev_def();
    $s = cin_rev_scenes($def);

    // V03: مشهدان منفصلان 10 + 40، والثاني يتطلب الأول.
    expect($s['arrival_enter_video']['duration_hint_seconds'])->toBe(10)->and($s['library_video']['duration_hint_seconds'])->toBe(40)
        ->and($s['library_video']['unlock_rule'])->toBe(['requires' => ['arrival_enter_video']])
        ->and($s['arrival_enter_video']['production']['plm_split'])->toContain('V03a')->and($s['library_video']['production']['plm_split'])->toContain('V03b');

    // الكتاب 5: الهدف المؤقت pos_5 (لا pos_3)، والهدف موجود بين المواضع السبعة، والتبعية معلنة مؤقتة تحتاج ياسمين.
    $move = $s['riddle_2_move'];
    expect($move['solution'])->toBe(['map' => ['moved_book' => 'pos_5']])->and(collect($move['config']['interaction']['targets'])->pluck('id')->all())->toContain('pos_5')
        ->and(collect($move['config']['interaction']['targets']))->toHaveCount(7)->and(collect($move['config']['interaction']['items'])->pluck('label')->all())->toBe(['الكتاب الذي تغيّر موضعه']);
    $prov = implode(' ', $move['provisional']);
    expect($prov)->toContain('الساعة الرملية')->toContain('pos_5')->toContain('ياسمين')->and($prov)->not->toContain('pos_3')->and(json_encode($move['solution']))->not->toContain('pos_3');

    // T02-T04 حرفيًا من نصوص الأصل، في intro_lines فقط للمشاهد الثلاثة.
    $expected = [
        'riddle_1_lamp' => ['الصفحة', 'ليست كل الصفحات الفارغة خالية مما كتب فيها'],
        'riddle_3_order' => ['العبارة المكتوبة', 'احتمال أن يكون المطلوب ترتيب العلامات ليس مظاهرها'],
        'riddle_4_keys' => ['العبارة المكتوبة', 'ثلاثة مفاتيح أمامك، لكن واحدًا فقط يقودك إلى الحقيقة'],
    ];

    foreach ($s as $key => $scene) {
        if (isset($expected[$key])) {
            expect($scene['config']['intro_lines'])->toBe([['speaker' => $expected[$key][0], 'text' => $expected[$key][1]]]);
        } else {
            expect($scene['config'])->not->toHaveKey('intro_lines');
        }
    }

    // انتقال V06 وفق الخطة: درج مفتوح شقًّا مظلمًا، والمفاتيح تظهر في صورة I06.
    $transition = $s['riddle_4_video']['production']['transition'];
    expect($transition)->toContain('شقًّا مظلمًا')->toContain('I06')->and($transition)->not->toContain('ينتهي بالمفاتيح الثلاثة ظاهرة');

    // المدد التخطيطية المؤقتة (المجموع 214).
    $plan = ['letter_video' => 20, 'arrival_video' => 25, 'arrival_enter_video' => 10, 'library_video' => 40, 'riddle_1_video' => 8, 'riddle_2_video' => 16, 'riddle_3_video' => 14, 'riddle_4_video' => 14, 'riddle_5_video' => 22, 'finale_video' => 45];
    expect(collect($plan)->sum())->toBe(214);

    foreach ($plan as $key => $secs) {
        expect($s[$key]['duration_hint_seconds'])->toBe($secs, $key)->and($s[$key]['production']['planning_duration'])->toContain('مؤقتة')->and($s[$key]['production']['poster_required'])->toContain('ملصق');
    }

    expect($def['revision']['planning_duration_seconds'])->toBe(['total' => 214, 'status' => 'provisional_planning_only', 'per_scene' => $plan]);
});

test('REV-04: click coordinates are NOT moved on a guess - every interactive area keeps its original numbers, the hidden drawer is documented as player-right with final numbers deferred, and no hint leaks into player-visible text', function () {
    $old = cin_rev_scenes(cin_rev_original());
    $new = cin_rev_scenes(cin_rev_def());

    foreach (['arrival_door', 'library_explore', 'riddle_5_drawer', 'riddle_1_lamp'] as $key) {
        expect($new[$key]['config']['interaction'])->toBe($old[$key]['config']['interaction'], $key);
    }

    expect($new['riddle_1_lamp']['solution'])->toBe($old['riddle_1_lamp']['solution']);

    $drawer = $new['riddle_5_drawer'];
    $areas = collect($drawer['config']['interaction']['areas'])->keyBy('id');
    expect([$areas['under_table']['x'], $areas['under_table']['y'], $areas['under_table']['w'], $areas['under_table']['h']])->toBe([38, 76, 24, 16])
        ->and($areas['under_table']['label'])->toBe('أسفل الطاولة');

    $doc = implode(' ', $drawer['provisional']).' '.$drawer['production']['plm_note'];
    expect($doc)->toContain('يمين اللاعب')->toContain('مؤقتة')->toContain('مستقل عن درج المفاتيح')->toContain('صور الوسائط');

    $deferred = cin_rev_def()['revision']['deferred_coordinates'];
    expect(collect($deferred)->pluck('status')->unique()->all())->toBe(['pending_final_art'])->and(collect($deferred)->pluck('area')->all())->toContain('under_table')->toContain('key_drawer');

    // لا تلميح في نص اللاعب المرئي: «يمين/الدرج المخفي/الساعة الرملية» لا تظهر في config العام لأي مشهد تفاعل.
    $public = json_encode(collect($new)->map(fn ($s) => $s['config']['interaction'] ?? null)->filter()->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toContain('الساعة الرملية')->not->toContain('يمين اللاعب')->not->toContain('"moved_book":"pos_5"');
});

test('REV-05: the revised content is an UNPUBLISHED draft - it states what blocks publishing, keeps the solutions warning, and holds no media, URLs or markup in public text', function () {
    $def = cin_rev_def();
    $all = cin_rev_scenes($def);

    expect($def['revision']['status'])->toBe('reviewed_unpublished_draft')->and($def['revision']['baseline_sha256'])->toBe(CIN_REV_ORIGINAL_SHA256)->and($def['revision']['baseline_commit'])->toBe('a78a5d352c2b6581ba476655fe564a5051029286')
        ->and(implode(' ', $def['revision']['publish_blocked_until']))->toContain('ياسمين')->and($def['warning'])->toContain('الحلول المرجعية')->and($def['story']['access_mode'])->toBe('free')
        ->and(count($def['revision']['approved_decisions']))->toBe(9);

    foreach ($all as $key => $scene) {
        if ($scene['type'] === 'video') {
            expect($scene['awaiting_media'])->toBeTrue($key);
        }
    }

    $public = json_encode(collect($all)->map(fn ($s) => $s['config'])->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toMatch('/<[a-z!\/]/i')->and($public)->not->toContain('http');

    // الملف في مجلد demo الخاص وليس في public.
    expect(str_starts_with(realpath(database_path('demo/cinematic/library-mystery-stage-1-plm-revised.json')), realpath(public_path())))->toBeFalse();
});

test('REV-06: the revised file builds in the isolated test database through the official authoring services - 19 scenes in the same order, drafts only, every video awaiting media, nothing published, no economy touched', function () {
    $before = cin_economy_snapshot();
    $r = cin_rev_install(cin_rev_def());
    $scenes = CinematicStructure::scenes($r['revision']);

    expect($r['revision']->status)->toBe(CinematicRevision::STATUS_DRAFT)->and($scenes)->toHaveCount(19)
        ->and($scenes->pluck('stable_key')->all())->toBe(array_keys(cin_rev_scenes(cin_rev_original())))
        ->and($scenes->where('type', 'video'))->toHaveCount(10)->and(CinematicStory::query()->count())->toBe(1);

    foreach ($scenes->where('type', 'video') as $video) {
        expect($video->awaiting_media)->toBeTrue()->and($video->video_asset_id)->toBeNull()->and($video->poster_asset_id)->toBeNull();
    }

    $readiness = app(CinematicReadinessChecker::class)->check($r['revision']);
    expect($readiness['ok'])->toBeFalse()->and(collect($readiness['blockers'])->pluck('code')->unique()->all())->toBe(['video_missing']);

    cin_publishing()->submitForReview($r['revision']->fresh());
    expect(cin_reason(fn () => cin_publishing()->publish($r['revision']->fresh())))->not->toBeNull()
        ->and(CinematicRevision::query()->where('status', CinematicRevision::STATUS_PUBLISHED)->count())->toBe(0)
        ->and(cin_economy_snapshot())->toBe($before);

    auth()->logout();
    $this->get('/cinematic/unwritten-page')->assertNotFound();
});

test('REV-07: with temporary test media the revised stage publishes and its reference solutions complete all 19 scenes over HTTP - intro_lines reach the player, pos_5 is accepted and pos_3 is refused, no response leaks a solution', function () {
    $def = cin_rev_def();
    $r = cin_rev_install($def);
    $asset = cin_video_asset();

    foreach (CinematicStructure::scenes($r['revision'])->where('type', 'video') as $scene) {
        cin_authoring()->replaceMedia($scene, 'video', $asset);
    }

    $live = cin_publish($r['revision']->fresh());
    expect($live->status)->toBe(CinematicRevision::STATUS_PUBLISHED);

    $user = cin_user();
    $slug = $r['story']->slug;
    $before = cin_economy_snapshot();
    $seen = [];
    $answers = cin_rev_scenes($def);
    $byKey = CinematicStructure::scenes($live)->keyBy('stable_key');
    $intro = [];

    $this->actingAs($user)->get("/cinematic/{$slug}/play")->assertOk();

    foreach ($answers as $key => $scene) {
        $id = $byKey[$key]->public_id;
        $started = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/start")->assertOk();
        $seen[] = $started->getContent();

        if (in_array($key, ['riddle_1_lamp', 'riddle_3_order', 'riddle_4_keys'], true)) {
            $intro[$key] = $started->json('scene.intro_lines');
        }

        if (in_array($scene['type'], ['video', 'transition'], true)) {
            $res = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/finish", ['mode' => 'read'])->assertOk();
        } else {
            if ($key === 'riddle_2_move') {
                $old = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/submit", ['submission' => ['placements' => ['moved_book' => 'pos_3']]])->assertOk();
                expect($old->json('result.correct'))->toBeFalse();
            }

            $wrong = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/submit", ['submission' => ['area' => 'nowhere', 'item' => 'nothing', 'order' => ['x'], 'placements' => ['x' => 'y'], 'points' => [['x' => 0.1, 'y' => 0.1]]]])->assertOk();
            expect($wrong->json('result.correct'))->toBeFalse($key);
            $seen[] = $wrong->getContent();

            $res = $this->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$id}/submit", ['submission' => cin_rev_answer($scene)])->assertOk();
            expect($res->json('result.correct'))->toBeTrue($key);
        }

        $seen[] = $res->getContent();
    }

    expect($intro['riddle_1_lamp'][0]['text'])->toBe('ليست كل الصفحات الفارغة خالية مما كتب فيها')->and($intro['riddle_3_order'][0]['speaker'])->toBe('العبارة المكتوبة')
        ->and($intro['riddle_4_keys'][0]['text'])->toBe('ثلاثة مفاتيح أمامك، لكن واحدًا فقط يقودك إلى الحقيقة');

    $progress = CinematicProgress::query()->where('user_id', $user->id)->firstOrFail();
    expect($progress->status)->toBe('completed')->and(CinematicSceneState::query()->where('progress_id', $progress->id)->whereNotNull('completed_at')->count())->toBe(19);

    $joined = implode("\n", $seen);
    expect($joined)->not->toContain('"correct":"')->not->toContain('"correct":[')->not->toContain('"order":[')->not->toContain('"map"')->not->toContain('mark_first')->not->toContain('requires_scenes')->not->toContain('"solution"')
        ->and($joined)->not->toContain('"moved_book":"pos_5"')->not->toContain('moved_book":"pos_')->not->toContain('الساعة الرملية')->and(cin_economy_snapshot())->toBe($before);
});

test('REV-08: the staff preview of the revised draft shows intro_lines and writes nothing; the flag stays closed by default', function () {
    $r = cin_rev_install(cin_rev_def());
    $staff = cin_admin();
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];

    $page = $this->actingAs($staff)->get('/cinematic/preview/'.$r['revision']->public_id)->assertOk();
    preg_match('/data-config="([^"]*)"/', $page->getContent(), $m);
    $cfg = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true);
    $scene = collect($cfg['scenes'])->firstWhere('key', 'riddle_3_order');

    expect($scene['intro_lines'][0]['text'])->toBe('احتمال أن يكون المطلوب ترتيب العلامات ليس مظاهرها')->and(json_encode($cfg))->not->toContain('"correct"')
        ->and([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);

    config(['cinematic_stories.enabled' => false]);
    $this->actingAs($staff)->get('/cinematic/preview/'.$r['revision']->public_id)->assertNotFound();
});

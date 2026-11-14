<?php

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\CinematicStructure;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use App\Services\Cinematic\Demo\Stage2ContentInstaller;

require_once __DIR__.'/_support.php';

/*
| CIN-2 - محتوى المرحلة الثانية («الصورة المفقودة») ومُثبّته وتشغيله من نهاية المرحلة الأولى حتى نهاية الثانية.
|  - CIN2-01: القالب: سبعة فصول بأسمائها، مفاتيح لا تتعارض مع المرحلة 1، كل مشهد تفاعلي بحل صالح، كل فيديو «بانتظار الإنتاج»، لا اسم قاعة ولا حل في عناوين المراحل/الفصول/المشاهد.
|  - CIN2-02: المُثبّت: معاينة بلا كتابة، تطبيق يضيف مرحلة فقط ولا يلمس مشاهد المرحلة 1، لا تكرار عند الإعادة، ويُبلَّغ بكل ما فيه نقص.
|  - CIN2-03: حدود المُثبّت: الإنتاج، لا مسودة، حالة جزئية، مسودة جديدة من منشورة، نهاية المرحلة الأولى مفقودة.
|  - CIN2-04: الجاهزية: النص المؤقت [[…]] والفيديوهات والصور تمنع النشر، والاعتماد التحريري تحذير، ولا شيء يُخضَّر بتعطيل الفحص.
|  - CIN2-05: اللعب الكامل عبر HTTP: المرحلة 2 تبدأ بعد 1، الإلزامية لا تُتخطى، الأخطاء تُرفض مع تلميحات، الأدلة الأربعة بترتيبها، الخزانة لا تنفتح قبل شروطها، النهاية بلا تسريب.
|  - CIN2-06: لا تسريب: لا رمز ولا إجابة ولا نص كشف ولا اسم القاعة قبل أوانها، ولا هوية للشخص الثامن، والحالة تُستأنف بالسجل نفسه.
|  - CIN2-07: لا أثر على الاقتصاد ولا على المرحلة الأولى؛ المعاينة لا تحفظ شيئًا.
|  - CIN2-08: المحرّر الإداري: الحفظ لا يُضيّع الحل ولا بطاقة الدليل، وغير المخوَّل لا يرى الحل.
|  - CIN2-09: عقود القوالب: الشرائح الجديدة بلا HTML خام، وعقد صورة المشهد القديم سليم، والعلم مغلق افتراضيًا.
*/

beforeEach(fn () => cin_seed());

function cin2_staff(array $permissions): App\Models\User
{
    $user = e16User();
    $user->givePermissionTo(array_merge(['admin.access'], $permissions));

    return $user;
}

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} [المرحلة1، المرحلة2] */
function cin2_install(): array
{
    $one = app(Stage1DemoInstaller::class)->install();
    $two = app(Stage2ContentInstaller::class)->install();

    return [$one, $two];
}

/** @return list<array<string, mixed>> */
function cin2_scenes(): array
{
    $out = [];

    foreach (Stage2ContentInstaller::definition()['chapters'] as $chapter) {
        foreach ($chapter['scenes'] as $scene) {
            $out[] = $scene;
        }
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function cin2_stage1_scenes(): array
{
    $out = [];

    foreach (Stage1DemoInstaller::definition()['chapters'] as $chapter) {
        foreach ($chapter['scenes'] as $scene) {
            $out[] = $scene;
        }
    }

    return $out;
}

/** إجابة مرجعية من الحل المخزَّن في القالب. @param array<string, mixed> $scene @return array<string, mixed> */
function cin2_answer(array $scene): array
{
    $public = $scene['config']['interaction'];
    $s = $scene['solution'];

    return match ($public['kind']) {
        'hotspot' => ['area' => $s['correct'][0]],
        'container' => ['area' => $s['correct']],
        'sequence' => ['order' => $s['order']],
        'drag_drop' => ['placements' => $s['map']],
        'choose_item' => ['item' => $s['correct']],
        'compare' => ['option' => $s['correct']],
        'flashlight' => ['points' => array_map(fn ($t) => ['x' => $t['x'] + $t['w'] / 2, 'y' => $t['y'] + $t['h'] / 2], array_slice($s['targets'], 0, (int) ($s['required'] ?? count($s['targets']))))],
        'answer' => ['answer' => (string) $s['accepted'][0]],
        'inspect' => ['inspected' => $s['required']],
        'multi_lock' => ['values' => collect($public['locks'])->mapWithKeys(function ($lock) use ($s) {
            $code = $s['codes'][$lock['id']];
            $parts = [];
            $rest = $code;

            foreach ($lock['segments'] as $seg) {
                if ($seg['type'] === 'digits') {
                    $parts[] = mb_substr($rest, 0, $seg['length']);
                    $rest = mb_substr($rest, $seg['length']);
                } else {
                    $parts[] = mb_substr($rest, 0, 1);
                    $rest = mb_substr($rest, 1);
                }
            }

            return [$lock['id'] => $parts];
        })->all()],
    };
}

/** بصمة المرحلة الأولى: مشاهدها وحلولها كما هي. */
function cin2_snapshot(CinematicRevision $revision): string
{
    $stage = CinematicStage::query()->where('revision_id', $revision->id)->where('stable_key', 'stage_1')->firstOrFail();
    $scenes = CinematicScene::query()->where('stage_id', $stage->id)->orderBy('id')->get();
    $chapters = CinematicChapter::query()->where('stage_id', $stage->id)->orderBy('id')->get();
    $solutions = CinematicSceneSolution::query()->whereIn('scene_id', $scenes->pluck('id'))->orderBy('scene_id')->get();

    return md5($stage->toJson().$chapters->toJson().$scenes->toJson().$solutions->toJson());
}

function cin2_counts(): array
{
    return [CinematicStage::query()->count(), CinematicChapter::query()->count(), CinematicScene::query()->count(), CinematicSceneSolution::query()->count()];
}

/** يستبدل كل نص مؤقت [[…]] بنص تجريبي (داخل الاختبار فقط) ويربط وسائط مؤقتة لتصبح المرحلة قابلة للنشر. */
function cin2_make_publishable(CinematicRevision $revision): void
{
    $video = cin_video_asset();
    $poster = cin_poster_asset();

    foreach (CinematicStructure::scenes($revision) as $scene) {
        $json = json_encode($scene->config, JSON_UNESCAPED_UNICODE);

        if (str_contains((string) $json, '[[')) {
            cin_authoring()->updateScene($scene, ['config' => json_decode((string) preg_replace('/\[\[[^\]]*\]\]/u', 'نص تجريبي داخل الاختبار فقط', (string) $json), true)]);
        }

        if ($scene->type === 'video') {
            cin_authoring()->replaceMedia($scene->fresh(), 'video', $video);
        } elseif (in_array($scene->type, ['interaction', 'exploration'], true)) {
            cin_authoring()->replaceMedia($scene->fresh(), 'poster', $poster);
        }
    }
}

test('CIN2-01: the template follows the brief - seven chapters with the agreed names, collision-free keys, valid solutions, every video awaiting production, and nothing that gives an answer away in a title', function () {
    $def = Stage2ContentInstaller::definition();
    $scenes = cin2_scenes();

    expect($def['format'])->toBe('cinematic-stage-addon/1')->and($def['target_story_slug'])->toBe('unwritten-page')->and($def['stage']['stable_key'])->toBe('stage_2')
        ->and(collect($def['chapters'])->pluck('title')->all())->toBe([
            'الفصل الأول: الصورة التي لا تتذكر', 'الفصل الثاني: الوقت الذي لا يتطابق', 'الفصل الثالث: أثر على الزجاج', 'الفصل الرابع: فهرس لا يذكر صاحبه',
            'الفصل الخامس: الخزانة ذات القفلين', 'الفصل السادس: ما تكشفه النسخة الأصلية', 'الفصل السابع: المشهد الختامي',
        ])->and(count($scenes))->toBe(18);

    // مفاتيح فريدة، بصيغة المشروع، وبادئة المرحلة، ولا تصادم المرحلة الأولى.
    $keys = collect($scenes)->pluck('stable_key');
    $stage1 = collect(cin2_stage1_scenes())->pluck('stable_key');
    expect($keys->duplicates()->all())->toBe([])->and($keys->intersect($stage1)->all())->toBe([])
        ->and($keys->every(fn ($k) => preg_match('/\Astage2_[a-z0-9_]{2,56}\z/', $k) === 1))->toBeTrue()
        ->and(collect($def['chapters'])->pluck('stable_key')->intersect(collect(Stage1DemoInstaller::definition()['chapters'])->pluck('stable_key'))->all())->toBe([]);

    // المستخدم يبدأ المرحلة 2 بعد نهاية المرحلة 1 مباشرة.
    expect($scenes[0]['unlock_rule']['requires'])->toBe(['stage_1_end'])->and($def['requires_existing'])->toBe(['stage_key' => 'stage_1', 'scene_key' => 'stage_1_end']);

    $engine = new App\Services\Cinematic\Interactions\InteractionEngine;
    $all = $keys->all();

    foreach ($scenes as $scene) {
        expect($scene)->toHaveKeys(['stable_key', 'type', 'title', 'provisional', 'production', 'unlock_rule'])->and($scene['config']['editorial'])->toBe('proposal');

        foreach ($scene['unlock_rule']['requires'] as $r) {
            expect(in_array($r, $all, true) || $r === 'stage_1_end')->toBeTrue("{$scene['stable_key']} → {$r}");
        }

        if ($scene['type'] === 'video') {
            expect($scene['awaiting_media'])->toBeTrue()->and($scene['config']['transcript'])->not->toBeEmpty()->and($scene['production']['assets'])->toHaveKeys(['video', 'sfx'])
                ->and($scene['production']['start_frame'])->not->toBeEmpty()->and($scene['production']['end_frame'])->not->toBeEmpty()->and($scene['production']['on_end'])->not->toBeEmpty();
        }

        if (in_array($scene['type'], ['interaction', 'exploration'], true)) {
            $public = $scene['config']['interaction'];
            expect($scene['solution'])->not->toBeNull()->and($engine->validate($public['kind'], $public, $scene['solution']))->toBe([], $scene['stable_key'])
                ->and($scene['production'])->toHaveKeys(['assets', 'on_success'])->and($scene['provisional'])->toBeArray();
        }
    }

    // الأنواع المستعملة: الجديدة والموجودة معًا.
    $kinds = collect($scenes)->filter(fn ($s) => isset($s['config']['interaction']))->map(fn ($s) => $s['config']['interaction']['kind'])->unique()->sort()->values()->all();
    expect($kinds)->toBe(['answer', 'choose_item', 'compare', 'drag_drop', 'hotspot', 'inspect', 'multi_lock']);

    // لا عنوان (مرحلة/فصل/مشهد) يحمل اسم القاعة أو رمزًا أو إجابة.
    $titles = json_encode([$def['stage']['title'], $def['stage']['summary'], collect($def['chapters'])->pluck('title'), collect($scenes)->pluck('title')], JSON_UNESCAPED_UNICODE);
    expect($titles)->not->toContain('المخطوطات')->not->toContain('ب04')->not->toContain('04')->not->toContain('20 ')->not->toContain('الشخص الثامن هو');

    // اسم القاعة لا يرد إلا في: مشهد اختيار القرينة (حلًّا ودليلًا) وبطاقة الانتقال الختامية.
    foreach ($scenes as $scene) {
        $blob = json_encode([$scene['config'], $scene['solution']], JSON_UNESCAPED_UNICODE);

        if (str_contains($blob, 'المخطوطات')) {
            expect($scene['stable_key'])->toBeIn(['stage2_hall_clue_choice', 'stage2_end']);
        }
    }

    // الحلول المرجعية كما في الطلب: 20 دقيقة، ب04، الكرسي، النسخة الأصلية، اسم القاعة.
    $by = collect($scenes)->keyBy('stable_key');
    expect($by['stage2_time_difference']['solution']['accepted'])->toBe(['20'])->and($by['stage2_cabinet_locks']['solution']['codes'])->toBe(['lock_time' => '20', 'lock_drawer' => 'ب04'])
        ->and($by['stage2_glass_compare']['solution']['correct'])->toBe('chair')->and($by['stage2_original_choice']['solution']['correct'])->toBe('original_copy')
        ->and($by['stage2_hall_clue_choice']['solution']['correct'])->toBe('hall_name')
        ->and(collect($by['stage2_time_difference']['config']['interaction']['facts'])->pluck('value')->all())->toBe(['11:40', '11:20']);

    // القرائن الأربع بترتيبها، كل واحدة في مشهدها الصحيح.
    $clues = collect($scenes)->filter(fn ($s) => isset($s['config']['evidence']['clue']))->mapWithKeys(fn ($s) => [$s['config']['evidence']['clue'] => $s['stable_key']])->sortKeys()->all();
    expect($clues)->toBe([1 => 'stage2_time_difference', 2 => 'stage2_glass_trace', 3 => 'stage2_cabinet_locate', 4 => 'stage2_hall_clue_choice']);

    // الخزانة لا تُفتح قبل القرائن الثلاث، والقرينة الرابعة بعد فحص النسخة الأصلية.
    expect($by['stage2_cabinet_locks']['solution']['requires_scenes'])->toContain('stage2_time_difference', 'stage2_glass_trace', 'stage2_cabinet_locate')
        ->and($by['stage2_hall_clue_choice']['solution']['requires_scenes'])->toBe(['stage2_original_inspect'])
        ->and($by['stage2_original_inspect']['solution']['requires_scenes'])->toBe(['stage2_cabinet_locks']);

    // النص المؤقت معلَن: كل ما ينتظر وثيقة ياسمين موسوم [[…]] وفي قائمة pending.
    $placeholders = collect($scenes)->filter(fn ($s) => str_contains(json_encode($s['config'], JSON_UNESCAPED_UNICODE), '[['))->pluck('stable_key')->all();
    expect($placeholders)->toBe(['stage2_photo_intro_video', 'stage2_photo_inspect', 'stage2_time_result_video', 'stage2_cabinet_open_video', 'stage2_projector_video', 'stage2_finale_video'])
        ->and($def['pending_from_pdf'])->not->toBeEmpty()->and($def['warning'])->toContain('الحلول');

    // لا HTML ولا روابط في النصوص العامة.
    $public = json_encode(collect($scenes)->pluck('config')->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toMatch('/<[a-z!\/]/i')->and($public)->not->toContain('http');
});

test('CIN2-02: the installer previews without writing, then adds exactly one stage to the existing draft, leaves every stage-1 row untouched, and never duplicates on a retry', function () {
    $one = app(Stage1DemoInstaller::class)->install();
    $rev = $one['revision'];
    $snapshot = cin2_snapshot($rev);
    $before = cin2_counts();
    $economy = cin_economy_snapshot();

    // معاينة: لا كتابة.
    $plan = app(Stage2ContentInstaller::class)->plan();
    expect($plan['status'])->toBe('ready')->and($plan['problems'])->toBe([])->and($plan['counts'])->toBe(['stages' => 1, 'chapters' => 7, 'scenes' => 18]);
    $this->artisan('cinematic:install-stage2')->assertExitCode(0);
    expect(cin2_counts())->toBe($before);

    // تطبيق.
    $this->artisan('cinematic:install-stage2', ['--apply' => true])->assertExitCode(0);
    $after = cin2_counts();
    expect($after[0])->toBe($before[0] + 1)->and($after[1])->toBe($before[1] + 7)->and($after[2])->toBe($before[2] + 18)->and($after[3])->toBe($before[3] + 12);
    expect(cin2_snapshot($rev->fresh()))->toBe($snapshot);

    $stage2 = CinematicStage::query()->where('revision_id', $rev->id)->where('stable_key', 'stage_2')->firstOrFail();
    $stage1 = CinematicStage::query()->where('revision_id', $rev->id)->where('stable_key', 'stage_1')->firstOrFail();
    expect($stage2->position)->toBeGreaterThan($stage1->position)->and($stage2->title)->toBe('المرحلة الثانية: الصورة المفقودة')
        ->and(CinematicStory::query()->count())->toBe(1)->and(CinematicRevision::query()->count())->toBe(1);

    // ترتيب اللعب: المرحلة 2 بعد نهاية المرحلة 1 مباشرة، والانتقال الختامي هو الأخير.
    $order = CinematicStructure::scenes($rev->fresh())->pluck('stable_key')->values();
    expect($order->get($order->search('stage_1_end') + 1))->toBe('stage2_photo_intro_video')->and($order->last())->toBe('stage2_end')->and($order->count())->toBe(37);

    // إعادة التشغيل: لا شيء يتغير ولا تكرار.
    $again = app(Stage2ContentInstaller::class)->install();
    expect($again['status'])->toBe('already_applied')->and($again['created'])->toBeFalse()->and(cin2_counts())->toBe($after);
    $this->artisan('cinematic:install-stage2', ['--apply' => true])->assertExitCode(0);
    expect(cin2_counts())->toBe($after)->and(cin2_snapshot($rev->fresh()))->toBe($snapshot)->and(app(Stage2ContentInstaller::class)->plan()['status'])->toBe('already_applied');

    // لا اقتصاد ولا نشر ولا وسائط.
    expect(cin_economy_snapshot())->toBe($economy)->and($rev->fresh()->status)->not->toBe(CinematicRevision::STATUS_PUBLISHED)
        ->and(CinematicStructure::scenes($rev->fresh())->filter(fn ($s) => $s->video_asset_id !== null || $s->poster_asset_id !== null)->count())->toBe(0);
});

test('CIN2-03: the installer refuses safely - production without confirmation, no draft, a half-applied stage, missing end of stage one, an unknown story - and creates a draft only when told to', function () {
    // قصة غير موجودة.
    expect(app(Stage2ContentInstaller::class)->plan()['status'])->toBe('blocked')->and(cin_reason(fn () => app(Stage2ContentInstaller::class)->install()))->toBe('stage2_story_missing');

    $one = app(Stage1DemoInstaller::class)->install();
    $rev = $one['revision'];

    // الإنتاج يتطلب تأكيدًا صريحًا، ولا كتابة قبله.
    $before = cin2_counts();
    $this->app['env'] = 'production';
    expect(cin_reason(fn () => app(Stage2ContentInstaller::class)->install()))->toBe('stage2_production_confirm')->and(cin2_counts())->toBe($before);
    $this->artisan('cinematic:install-stage2', ['--apply' => true])->assertExitCode(1);
    expect(cin2_counts())->toBe($before);
    $this->app['env'] = 'testing';

    // نهاية المرحلة الأولى مفقودة: يتوقف قبل أي كتابة.
    $end = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage_1_end')->firstOrFail();
    $end->forceFill(['stable_key' => 'renamed_end'])->save();
    expect(app(Stage2ContentInstaller::class)->plan()['status'])->toBe('blocked')->and(cin_reason(fn () => app(Stage2ContentInstaller::class)->install()))->toBe('stage2_blocked')->and(cin2_counts())->toBe($before);
    $end->forceFill(['stable_key' => 'stage_1_end'])->save();

    // حالة جزئية (مرحلة بالمفتاح نفسه لكن بمشاهد مختلفة): لا يلمسها.
    $stub = cin_authoring()->addStage($rev->fresh(), ['stable_key' => 'stage_2', 'title' => 'يدوية']);
    $chapter = cin_authoring()->addChapter($stub, ['stable_key' => 'ch_manual', 'title' => 'فصل يدوي']);
    cin_narrative($chapter, 'manual_scene');
    $half = cin2_counts();
    expect(app(Stage2ContentInstaller::class)->plan()['status'])->toBe('blocked')->and(cin_reason(fn () => app(Stage2ContentInstaller::class)->install()))->toBe('stage2_blocked')->and(cin2_counts())->toBe($half);
    cin_authoring()->removeStage($stub->fresh());

    // بلا مسودة مفتوحة: يرفض إلا مع الطلب الصريح، وعندها يُنشأ draft جديد والمنشورة لا تُمس.
    foreach (CinematicStructure::scenes($rev->fresh())->where('type', 'video') as $video) {
        cin_authoring()->replaceMedia($video, 'video', cin_video_asset());
    }

    $live = cin_publish($rev->fresh());
    expect($live->status)->toBe(CinematicRevision::STATUS_PUBLISHED);
    $published = cin2_snapshot($live);
    $counts = cin2_counts();

    expect(app(Stage2ContentInstaller::class)->plan()['will_create_draft'])->toBeTrue()->and(cin_reason(fn () => app(Stage2ContentInstaller::class)->install()))->toBe('stage2_no_draft')->and(cin2_counts())->toBe($counts);

    $r = app(Stage2ContentInstaller::class)->install(null, true);
    expect($r['status'])->toBe('applied')->and($r['revision']->id)->not->toBe($live->id)->and($r['revision']->status)->toBe(CinematicRevision::STATUS_DRAFT)
        ->and(CinematicStage::query()->where('revision_id', $live->id)->count())->toBe(1)->and(CinematicStage::query()->where('revision_id', $r['revision']->id)->count())->toBe(2)
        ->and(cin2_snapshot($live->fresh()))->toBe($published)->and($live->fresh()->status)->toBe(CinematicRevision::STATUS_PUBLISHED);
});

test('CIN2-04: readiness blocks publishing while text is a placeholder, a video is missing or an inspect scene has no image; editorial approval is a warning; and nothing turns green by switching a check off', function () {
    [$one, $two] = cin2_install();
    $rev = $two['revision'];
    $r = app(CinematicReadinessChecker::class)->check($rev);

    expect($r['ok'])->toBeFalse();
    $codes = collect($r['blockers'])->groupBy('code')->map->count();
    $placeholderScenes = collect($r['blockers'])->where('code', 'placeholder_text')->pluck('scene')->sort()->values()->all();
    expect($placeholderScenes)->toBe(['stage2_cabinet_open_video', 'stage2_finale_video', 'stage2_photo_inspect', 'stage2_photo_intro_video', 'stage2_projector_video', 'stage2_time_result_video'])
        ->and($codes->has('video_missing') || collect($r['blockers'])->contains(fn ($b) => str_contains($b['message'], 'فيديو')))->toBeTrue()
        ->and(collect($r['warnings'])->where('code', 'editorial_pending')->count())->toBe(18)
        ->and(collect($r['blockers'])->pluck('code')->contains('stage_transition_missing'))->toBeFalse();

    // inspect بلا صورة = مانع، وجوابه/قفله بلا صورة = تحذير فقط.
    $sceneImage = collect($r['blockers'])->where('code', 'scene_image_missing')->pluck('scene')->sort()->values()->all();
    expect($sceneImage)->toBe(['stage2_original_inspect', 'stage2_photo_inspect', 'stage2_register_view']);

    // مرحلة 2 بلا بطاقة انتقال في آخرها = تحذير (آلية CIN-1 للانتقال بين المراحل).
    $noEnd = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage2_end')->firstOrFail();
    cin_authoring()->removeScene($noEnd);
    expect(collect(app(CinematicReadinessChecker::class)->check($rev->fresh())['warnings'])->pluck('code'))->toContain('stage_transition_missing');

    // فحص الجاهزية نفسه لا يقبل تعطيلًا: بعد تثبيت المحتوى لا مفتاح تهيئة يتجاوز مانع النص المؤقت.
    expect(config('cinematic_stories'))->not->toHaveKey('readiness_skip')->and(config('cinematic_stories'))->not->toHaveKey('allow_placeholders');
});

test('CIN2-05: the whole run over HTTP - stage 2 starts right after stage 1, mandatory scenes cannot be skipped, wrong answers are refused with progressive hints, the four clues arrive in order, the cabinet stays shut until its conditions are met, and the end is reached', function () {
    [$one, $two] = cin2_install();
    $rev = $two['revision'];
    cin2_make_publishable($rev->fresh());
    $live = cin_publish($rev->fresh());
    expect($live->status)->toBe(CinematicRevision::STATUS_PUBLISHED);

    $slug = $one['story']->slug;
    $user = cin_user();
    $economy = cin_economy_snapshot();
    $byKey = CinematicStructure::scenes($live)->keyBy('stable_key');
    $defs = collect(cin2_scenes())->keyBy('stable_key');
    $bodies = [];

    // المرحلة الأولى بالخدمة مباشرة (إثبات الاستمرارية)، ثم المرحلة الثانية عبر HTTP.
    $progress = cin_play()->begin($user, $one['story']);

    foreach (cin2_stage1_scenes() as $def) {
        $scene = $byKey[$def['stable_key']];
        cin_play()->start($progress, $scene);

        if (in_array($def['type'], ['video', 'transition'], true)) {
            cin_play()->finish($progress, $scene, 'read');
        } else {
            expect(cin_play()->submit($progress, $scene, cin2_answer($def))['result']['correct'] ?? null)->toBeTrue($def['stable_key']);
        }
    }

    $post = function (string $key, string $action, array $body = []) use ($user, $slug, $byKey, &$bodies) {
        $res = test()->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$byKey[$key]->public_id}/{$action}", $body);
        $bodies[] = $res->getContent();

        return $res;
    };
    $evidence = fn ($res) => collect($res->json('evidence'))->pluck('key')->all();

    // 1) أول مشهد في المرحلة 2 متاح، وما بعده مقفل، ولا سجل أدلة بعد.
    $state = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $bodies[] = $state->getContent();
    $status = collect($state->json('state.scenes'))->pluck('status', 'key');
    expect($status['stage_1_end'])->toBe('completed')->and($status['stage2_photo_intro_video'])->toBe('available')->and($status['stage2_photo_inspect'])->toBe('locked')->and($status['stage2_cabinet_locks'])->toBe('locked')
        ->and($state->json('evidence'))->toBe([]);

    // 2) ما لم يُفتح بعد لا يُبدأ ولا يُجاب (الخزانة ثم الفحص ثم الإلزامية).
    $post('stage2_cabinet_locks', 'start')->assertStatus(403);
    $post('stage2_cabinet_locks', 'submit', ['submission' => cin2_answer($defs['stage2_cabinet_locks'])])->assertStatus(403);
    $post('stage2_time_difference', 'submit', ['submission' => ['answer' => '20']])->assertStatus(403);
    $post('stage2_photo_inspect', 'start')->assertStatus(403);

    // 3) الفيديو الأول: لا تخطي (غير قابل للتخطي) ثم نشاهد.
    $post('stage2_photo_intro_video', 'start')->assertOk();
    $post('stage2_photo_intro_video', 'finish', ['mode' => 'skipped'])->assertStatus(403)->assertJsonPath('reason', 'skip_denied');
    $post('stage2_photo_intro_video', 'finish', ['mode' => 'read'])->assertOk();

    // 4) فحص الصورة: ناقص ⇒ خطأ؛ كامل ⇒ نجاح مع بطاقة دليل بلا هوية للشخص الثامن.
    $post('stage2_photo_inspect', 'start')->assertOk();
    $post('stage2_photo_inspect', 'finish', ['mode' => 'read'])->assertStatus(422);
    $partial = $post('stage2_photo_inspect', 'submit', ['submission' => ['inspected' => ['group_seven', 'person_8']]])->assertOk();
    expect($partial->json('result.correct'))->toBeFalse()->and($evidence($partial))->toBe([]);
    $ok = $post('stage2_photo_inspect', 'submit', ['submission' => cin2_answer($defs['stage2_photo_inspect'])])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($evidence($ok))->toBe(['stage2_photo_inspect']);

    // 5) ساعة الحائط (خطأ ثم صح).
    $post('stage2_clock_notice', 'start')->assertOk();
    expect($post('stage2_clock_notice', 'submit', ['submission' => ['area' => 'table_lamp']])->json('result.correct'))->toBeFalse();
    $ok = $post('stage2_clock_notice', 'submit', ['submission' => ['area' => 'wall_clock']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($evidence($ok))->toBe(['stage2_photo_inspect', 'stage2_clock_notice']);

    // 6) الفصل الثاني: إجابة الفارق. أخطاء بتلميحات متتابعة ثم ٢٠ بالأرقام العربية.
    $post('stage2_time_difference', 'start')->assertOk();
    $hints = [];

    foreach (['19', '200', 'عشرون', '2 0'] as $wrong) {
        $res = $post('stage2_time_difference', 'submit', ['submission' => ['answer' => $wrong]])->assertOk();
        expect($res->json('result.correct'))->toBeFalse($wrong)->and($res->json('result.reveal'))->toBeNull();
        $hints[] = $res->json('result.hint');
    }

    expect($hints)->toBe(['قارن بين الوقت المكتوب خلف الصورة والوقت الذي تشير إليه الساعة.', 'الفارق يُحسب بالدقائق بين الوقتين.', 'ابدأ من الوقت الأصغر وعُدّ حتى تصل إلى الأكبر.', 'ابدأ من الوقت الأصغر وعُدّ حتى تصل إلى الأكبر.']);

    $ok = $post('stage2_time_difference', 'submit', ['submission' => ['answer' => '٢٠ دقيقة']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($ok->json('result.reveal'))->toContain('درج رفيع')->and($evidence($ok))->toContain('stage2_time_difference');
    $clue1 = collect($ok->json('evidence'))->firstWhere('key', 'stage2_time_difference');
    expect($clue1['clue'])->toBe(1)->and($clue1['summary'])->toContain('20');

    // المحاولات المرفوضة لم تُنشئ تقدّمًا إضافيًا ولا حالة زمنية.
    $p = CinematicProgress::query()->where('user_id', $user->id)->firstOrFail();
    $state = CinematicSceneState::query()->where('progress_id', $p->id)->where('scene_id', $byKey['stage2_time_difference']->id)->firstOrFail();
    expect($state->attempts_count)->toBe(5)->and($state->completed_at)->not->toBeNull();

    $post('stage2_time_result_video', 'start')->assertOk();
    $post('stage2_time_result_video', 'finish', ['mode' => 'read'])->assertOk();

    // 7) أثر على الزجاج: كرسي ← موضع قديم ← آثار (المرحلة الثانية من اثنتين: تُظهر القرينة الثانية).
    $post('stage2_glass_compare', 'start')->assertOk();
    expect($post('stage2_glass_compare', 'submit', ['submission' => ['option' => 'lamp']])->json('result.correct'))->toBeFalse();
    expect($post('stage2_glass_compare', 'submit', ['submission' => ['option' => 'chair']])->json('result.correct'))->toBeTrue();
    $post('stage2_glass_old_position', 'start')->assertOk();
    expect($post('stage2_glass_old_position', 'submit', ['submission' => ['area' => 'window_pane']])->json('result.correct'))->toBeFalse();
    expect($post('stage2_glass_old_position', 'submit', ['submission' => ['area' => 'old_chair_spot']])->json('result.correct'))->toBeTrue();
    $post('stage2_glass_trace', 'start')->assertOk();
    expect($post('stage2_glass_trace', 'submit', ['submission' => ['placements' => ['trace_first' => 'pos_lower', 'trace_second' => 'pos_upper']]])->json('result.correct'))->toBeFalse();
    $ok = $post('stage2_glass_trace', 'submit', ['submission' => cin2_answer($defs['stage2_glass_trace'])])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($ok->json('result.reveal'))->toBe('درج ب / ملف 04');
    expect(collect($ok->json('evidence'))->pluck('clue')->filter()->values()->all())->toBe([1, 2]);

    // 8) الفهرس ← النسخة الأصلية ← مخطط المكتبة (القرينة الثالثة) بلا ذكر من عدّل.
    $post('stage2_register_view', 'start')->assertOk();
    expect($post('stage2_register_view', 'submit', ['submission' => ['inspected' => ['row_file_04']]])->json('result.correct'))->toBeFalse();
    $ok = $post('stage2_register_view', 'submit', ['submission' => cin2_answer($defs['stage2_register_view'])])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue();
    $post('stage2_original_choice', 'start')->assertOk();
    expect($post('stage2_original_choice', 'submit', ['submission' => ['item' => 'modified_copy']])->json('result.correct'))->toBeFalse();
    expect($post('stage2_original_choice', 'submit', ['submission' => ['item' => 'original_copy']])->json('result.correct'))->toBeTrue();
    $post('stage2_cabinet_locate', 'start')->assertOk();
    expect($post('stage2_cabinet_locate', 'submit', ['submission' => ['area' => 'reading_table']])->json('result.correct'))->toBeFalse();
    $ok = $post('stage2_cabinet_locate', 'submit', ['submission' => ['area' => 'archive_cabinet']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and(collect($ok->json('evidence'))->pluck('clue')->filter()->values()->all())->toBe([1, 2, 3]);
    $marker = collect($ok->json('evidence'))->firstWhere('key', 'stage2_cabinet_locate')['marker'];
    expect($marker['label'])->toBe('خزانة الأرشيف');

    // 9) الخزانة: قفل صحيح وآخر خاطئ ⇒ لا فتح وكل قفل يُحكم عليه وحده؛ ثم الرمزان الصحيحان.
    $post('stage2_cabinet_locks', 'start')->assertOk();
    $half = $post('stage2_cabinet_locks', 'submit', ['submission' => ['values' => ['lock_time' => ['20'], 'lock_drawer' => ['أ', '04']]]])->assertOk();
    expect($half->json('result.correct'))->toBeFalse()->and($half->json('result.detail'))->toBe(['lock_time' => true, 'lock_drawer' => false])->and($half->json('result.hint'))->toBe('حرف الدرج ثم رقم الملف كما ظهرا لك.')
        ->and($half->json('result.reveal'))->toBeNull();
    $still = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect(collect($still->json('state.scenes'))->pluck('status', 'key')['stage2_cabinet_locks'])->not->toBe('completed')->and(collect($still->json('state.scenes'))->pluck('status', 'key')['stage2_photo_intro_video'])->toBe('completed');
    $ok = $post('stage2_cabinet_locks', 'submit', ['submission' => cin2_answer($defs['stage2_cabinet_locks'])])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($ok->json('result.reveal'))->toContain('ظرف قديم');

    $post('stage2_cabinet_open_video', 'start')->assertOk();
    $post('stage2_cabinet_open_video', 'finish', ['mode' => 'read'])->assertOk();
    $post('stage2_projector_video', 'start')->assertOk();
    $post('stage2_projector_video', 'finish', ['mode' => 'read'])->assertOk();

    // 10) النسخة الأصلية ← القرينة الرابعة: اسم القاعة يظهر الآن فقط.
    $joined = implode("\n", $bodies);
    expect($joined)->not->toContain('المخطوطات');
    $post('stage2_original_inspect', 'start')->assertOk();
    $ok = $post('stage2_original_inspect', 'submit', ['submission' => cin2_answer($defs['stage2_original_inspect'])])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue();
    $post('stage2_hall_clue_choice', 'start')->assertOk();
    expect($post('stage2_hall_clue_choice', 'submit', ['submission' => ['item' => 'eighth_face']])->json('result.correct'))->toBeFalse();
    expect(implode("\n", $bodies))->not->toContain('المخطوطات');
    $ok = $post('stage2_hall_clue_choice', 'submit', ['submission' => ['item' => 'hall_name']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($ok->json('result.reveal'))->toBe('اللافتة تقول: قاعة المخطوطات.')
        ->and(collect($ok->json('evidence'))->pluck('clue')->filter()->values()->all())->toBe([1, 2, 3, 4]);

    // 11) النهاية: فيديو ثم بطاقة الانتقال إلى المرحلة الثالثة، وبعدها اكتمال السلسلة (لا محتوى للمرحلة 3).
    $post('stage2_finale_video', 'start')->assertOk();
    $post('stage2_finale_video', 'finish', ['mode' => 'read'])->assertOk();
    $end = $post('stage2_end', 'start')->assertOk();
    expect($end->json('scene.transition.title'))->toBe('المرحلة الثالثة')->and($end->json('scene.transition.next_stage_title'))->toBe('قاعة المخطوطات');
    $fin = $post('stage2_end', 'finish', ['mode' => 'read'])->assertOk();
    expect($fin->json('state.progress.status'))->toBe('completed')->and($fin->json('state.progress.percent'))->toBe(100);

    // كل ما سبق بلا اقتصاد وبلا سر في أي استجابة.
    expect(cin_economy_snapshot())->toBe($economy);
    $joined = implode("\n", $bodies);
    expect($joined)->not->toContain('ب04')->not->toContain('"accepted"')->not->toContain('"codes"')->not->toContain('lock_hints')->not->toContain('"solution"')->not->toContain('requires_scenes')->not->toContain('"required":[')
        ->and($joined)->not->toMatch('/الشخص الثامن (هو|اسمه|يدعى|اسمها)/u');

    // التقدّم: كل مشاهد المرحلة 2 مكتملة (18) إضافة إلى المرحلة 1 (19).
    expect(CinematicSceneState::query()->where('progress_id', $p->id)->whereNotNull('completed_at')->count())->toBe(37);
});

test('CIN2-06: nothing is given away early - a scene payload never carries a solution, a hint, a reveal text or a later clue, the evidence log only holds finished scenes, and resuming restores exactly the same log', function () {
    [$one, $two] = cin2_install();
    cin2_make_publishable($two['revision']->fresh());
    $live = cin_publish($two['revision']->fresh());
    $slug = $one['story']->slug;
    $user = cin_user();
    $byKey = CinematicStructure::scenes($live)->keyBy('stable_key');
    $progress = cin_play()->begin($user, $one['story']);

    // كل مشاهد المرحلة 1 بالخدمة.
    foreach (cin2_stage1_scenes() as $def) {
        $scene = $byKey[$def['stable_key']];
        cin_play()->start($progress, $scene);
        in_array($def['type'], ['video', 'transition'], true) ? cin_play()->finish($progress, $scene, 'read') : cin_play()->submit($progress, $scene, cin2_answer($def));
    }

    // مشهد مقفل لا حمولة له: الخزانة وما بعدها ترفض البدء ولا تحمل شيئًا من الحل.
    $startPayloads = [];

    foreach (['stage2_photo_inspect', 'stage2_cabinet_locks', 'stage2_hall_clue_choice', 'stage2_end'] as $locked) {
        $res = test()->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$byKey[$locked]->public_id}/start")->assertStatus(403);
        expect($res->getContent())->not->toContain('prompt')->not->toContain('accepted')->not->toContain('"interaction"');
    }

    // احفظ حمولة كل مشهد تفاعلي عند فتحه بإتمام ما قبله بالخدمة، ثم افحص التسريب في حمولته فقط (لا في حلول المشاهد اللاحقة).
    $order = collect(cin2_scenes());

    foreach ($order as $i => $def) {
        $scene = $byKey[$def['stable_key']];
        $p = $progress->fresh();

        $res = test()->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$scene->public_id}/start")->assertOk();
        $json = $res->getContent();
        $startPayloads[$def['stable_key']] = $json;

        if (isset($def['config']['interaction'])) {
            foreach (['"accepted"', '"codes"', '"lock_hints"', '"hints"', '"reveal"', '"required":[', 'requires_scenes', '"solution"', '"units"', '"correct":"', '"correct":['] as $needle) {
                expect($json)->not->toContain($needle, "{$def['stable_key']} leaks {$needle}");
            }

            // نص النجاح/الخطأ والتلميحات لا تُرسل مع المشهد، بل مع نتيجة الإرسال فقط.
            foreach (['success_message', 'wrong_message'] as $field) {
                $text = $def['config']['interaction'][$field] ?? null;
                expect($text === null || ! str_contains($json, $text))->toBeTrue("{$def['stable_key']} sends {$field}");
            }
        }

        // قبل الإتمام: سجل الأدلة لا يحمل هذا المشهد ولا ما بعده.
        $log = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->json('evidence');
        $future = $order->slice($i)->pluck('stable_key')->all();
        expect(collect($log)->pluck('key')->intersect($future)->all())->toBe([], $def['stable_key']);

        in_array($def['type'], ['video', 'transition'], true) ? cin_play()->finish($p, $scene, 'read') : cin_play()->submit($p, $scene, cin2_answer($def));
    }

    // الأدلة المتراكمة بالترتيب، ولا يظهر اسم القاعة قبل مشهد القرينة الرابعة في أي حمولة بدء سابقة له.
    $before = collect($startPayloads)->only($order->pluck('stable_key')->take($order->pluck('stable_key')->search('stage2_hall_clue_choice'))->all())->implode("\n");
    expect($before)->not->toContain('المخطوطات');

    // الاستئناف: حالة جديدة (جلسة جديدة) تعيد السجل نفسه بالترتيب نفسه.
    $final = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $keys = collect($final->json('evidence'))->pluck('key')->all();
    expect($keys)->toBe(collect(cin2_scenes())->filter(fn ($s) => ! empty($s['config']['evidence']))->pluck('stable_key')->all());
    $again = test()->actingAs($user)->get("/cinematic/{$slug}/play")->assertOk();
    expect(html_entity_decode($again->getContent()))->toContain('stage2_cabinet_locate');
});

test('CIN2-07: stage 2 touches nothing of the economy or stage 1, and the staff preview of the new kinds judges on the server while persisting no progress', function () {
    [$one, $two] = cin2_install();
    $rev = $two['revision']->fresh();
    cin2_make_publishable($rev);
    $admin = cin_admin();
    $player = cin_user();
    $economy = cin_economy_snapshot();
    $progressRows = CinematicProgress::query()->count();

    $page = $this->actingAs($admin)->get("/cinematic/preview/{$rev->public_id}")->assertOk();
    $config = html_entity_decode($page->getContent());
    expect($config)->toContain('evidence_by_scene')->and($config)->not->toContain('"accepted"')->not->toContain('"codes"')->not->toContain('lock_hints')->not->toContain('ب04');

    $by = CinematicStructure::scenes($rev)->keyBy('stable_key');
    $post = fn (string $key, array $submission, array $extra = []) => $this->actingAs($admin)->postJson("/cinematic/preview/{$rev->public_id}/scenes/{$by[$key]->public_id}/submit", ['submission' => $submission] + $extra);

    $r = $post('stage2_time_difference', ['answer' => '٢٠'])->assertOk();
    expect($r->json('result.correct'))->toBeTrue()->and($r->json('result.reveal'))->toContain('درج رفيع');
    $r = $post('stage2_time_difference', ['answer' => '7'], ['attempt' => 2])->assertOk();
    expect($r->json('result.correct'))->toBeFalse()->and($r->json('result.hint'))->toBe('الفارق يُحسب بالدقائق بين الوقتين.');
    expect($post('stage2_cabinet_locks', ['values' => ['lock_time' => ['20'], 'lock_drawer' => ['ب', '04']]])->json('result.correct'))->toBeTrue();
    expect($post('stage2_photo_inspect', ['inspected' => ['person_8']])->json('result.correct'))->toBeFalse();

    expect(CinematicProgress::query()->count())->toBe($progressRows)->and(CinematicSceneState::query()->count())->toBe(0)->and(cin_economy_snapshot())->toBe($economy);

    // لاعب عادي لا يصل إلى المعاينة الإشرافية.
    $this->actingAs($player)->get("/cinematic/preview/{$rev->public_id}")->assertForbidden();
});

test('CIN2-08: saving a stage-2 scene from the admin editor keeps its solution, evidence card and editorial flag, and a viewer without manage never sees them', function () {
    [$one, $two] = cin2_install();
    $rev = $two['revision']->fresh();
    $story = $one['story'];
    $scene = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage2_time_difference')->firstOrFail();
    $lock = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage2_cabinet_locks')->firstOrFail();
    $inspect = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage2_photo_inspect')->firstOrFail();

    $author = cin2_staff(['cinematic-stories.view', 'cinematic-stories.manage']);
    $this->actingAs($author);

    foreach ([$scene, $lock, $inspect] as $s) {
        $payloadBefore = CinematicSceneSolution::query()->where('scene_id', $s->id)->firstOrFail()->payload;
        $configBefore = $s->fresh()->config;

        Livewire\Livewire::withQueryParams(['story' => $story->public_id])->test(App\Filament\Pages\CinematicBuilderPage::class)->call('editScene', $s->public_id)->call('saveScene')->assertHasNoErrors();

        $after = $s->fresh();
        expect(CinematicSceneSolution::query()->where('scene_id', $s->id)->firstOrFail()->payload)->toEqual($payloadBefore, $s->stable_key)
            ->and($after->config['evidence'] ?? null)->toEqual($configBefore['evidence'] ?? null)->and($after->config['editorial'] ?? null)->toBe('proposal')
            ->and($after->config['interaction'])->toEqual($configBefore['interaction'], $s->stable_key);
    }

    // الحقول الجديدة معروضة للمسؤول (الجواب المقبول، الرموز، الأقفال) ومخفية لمن لا يملك manage.
    $component = Livewire\Livewire::withQueryParams(['story' => $story->public_id])->test(App\Filament\Pages\CinematicBuilderPage::class)->call('editScene', $lock->public_id);
    expect($component->get('sf.ix.codes'))->toContain('lock_drawer=ب04')->and($component->get('sf.ix.locks'))->toContain('lock_drawer|القفل الثاني|L(أ,ب,ج)+D2')->and($component->get('sf.kind'))->toBe('multi_lock');
    $time = Livewire\Livewire::withQueryParams(['story' => $story->public_id])->test(App\Filament\Pages\CinematicBuilderPage::class)->call('editScene', $scene->public_id);
    expect($time->get('sf.ix.accepted'))->toBe('20')->and($time->get('sf.ev.clue'))->toBe('1');

    $viewer = cin2_staff(['cinematic-stories.view']);
    $this->actingAs($viewer);

    foreach ([$scene, $lock, $inspect] as $s) {
        $c = Livewire\Livewire::withQueryParams(['story' => $story->public_id])->test(App\Filament\Pages\CinematicBuilderPage::class)->call('editScene', $s->public_id);
        expect($c->get('sf.ix.accepted'))->toBe('')->and($c->get('sf.ix.codes'))->toBe('')->and($c->get('sf.ix.hints'))->toBe('')->and($c->get('sf.ix.reveal'))->toBe('')->and($c->get('sf.ix.inspect_required'))->toBe('')->and($c->get('sf.ev.summary'))->toBe('');
        // القيمتان 11:40 و11:20 حقائق عامة تُعرض للاعب في السؤال نفسه؛ المحجوب هو الرمز ونص الكشف والجواب المقبول.
        $c->assertDontSee('ب04')->assertDontSee('درج رفيع')->assertDontSee('ظرف قديم');
    }

    // التحرير من النموذج: تغيير الجواب المقبول يمرّ بالتحقق نفسه؛ وقيمة غير عددية تُرفض بلا فقد للجواب القديم.
    $this->actingAs($author);
    Livewire\Livewire::withQueryParams(['story' => $story->public_id])->test(App\Filament\Pages\CinematicBuilderPage::class)->call('editScene', $scene->public_id)->set('sf.ix.accepted', 'abc')->call('saveScene');
    expect(CinematicSceneSolution::query()->where('scene_id', $scene->id)->firstOrFail()->payload['accepted'])->toBe(['20']);
});

test('CIN2-09: the new player templates render nothing as raw HTML, keep the old scene-image contract intact, are wired into the immersive player without a rebuild, and the feature flag still ships closed', function () {
    $dir = resource_path('views/cinematic/partials');
    $blade = (string) file_get_contents(resource_path('views/cinematic/play.blade.php'));

    foreach (['cin2-stage', 'cin2-paper', 'cin2-evidence', 'cin2-scripts', 'cin2-styles'] as $name) {
        $src = (string) file_get_contents("{$dir}/{$name}.blade.php");
        expect($src)->not->toContain('x-html')->not->toContain('{!!')->not->toContain('innerHTML')->not->toContain('insertAdjacentHTML')->not->toContain('document.write')->not->toContain('eval(')->not->toContain('http://')->not->toContain('https://');
        expect(view("cinematic.partials.{$name}")->render())->toBeString();
    }

    // التضمين في المشغّل الغامر القائم (لا إعادة بناء)، وعقد صورة المشهد القديم كما هو.
    expect($blade)->toContain("@include('cinematic.partials.cin2-stage')")->toContain("@include('cinematic.partials.cin2-paper')")->toContain("@include('cinematic.partials.cin2-evidence')")
        ->toContain("@include('cinematic.partials.immersive-scripts')")->toContain("@include('cinematic.partials.immersive-styles')")
        ->and(substr_count($blade, 'data-cinematic-scene-image'))->toBe(1)->and(substr_count($blade, '<img :src="scene.image"'))->toBe(4)
        ->and($blade)->toContain("['sequence', 'drag_drop', 'choose_item', 'compare'].indexOf(kind) !== -1");

    // الحقول الأساسية للواجهة الجديدة موجودة للاختبار الآلي ولقارئات الشاشة.
    $paper = (string) file_get_contents("{$dir}/cin2-paper.blade.php");
    $stage = (string) file_get_contents("{$dir}/cin2-stage.blade.php");
    $ev = (string) file_get_contents("{$dir}/cin2-evidence.blade.php");
    expect($paper)->toContain('data-cin2-answer-input')->toContain('inputmode')->toContain('data-cin2-locks')->toContain('data-cin2-digits')->toContain('data-cin2-letter')->toContain('aria-live')
        ->and($stage)->toContain('data-cin2-inspect-stage')->toContain('data-cin2-flip')->toContain('data-cin2-zoom-in')->toContain('aria-label')->toContain('role="application"')
        ->and($ev)->toContain('role="dialog"')->toContain('aria-modal="true"')->toContain('x-on:keydown.escape.window');

    // لا مكتبات خارجية ولا طلبات شبكة جديدة من القوالب الجديدة.
    foreach (['cin2-scripts', 'cin2-stage', 'cin2-paper', 'cin2-evidence'] as $name) {
        expect((string) file_get_contents("{$dir}/{$name}.blade.php"))->not->toContain('<script src')->not->toContain('fetch(')->not->toContain('XMLHttpRequest');
    }

    // جوال أفقي ضيّق (≤720px): تخطيط جانبي للأنواع الجديدة فقط (لا لأنواع CIN-1) حتى لا تُضغط الصورة تحت الورقة السفلية.
    $css = (string) file_get_contents("{$dir}/cin2-styles.blade.php");
    expect($css)->toContain('@media (orientation: landscape) and (max-width: 720px) and (max-height: 520px)')
        ->and($css)->toContain('[data-kind="inspect"]')->toContain('[data-kind="answer"]')->toContain('[data-kind="multi_lock"]')
        ->and($css)->not->toContain('[data-kind="hotspot"]')->not->toContain('[data-kind="compare"]')->not->toContain('[data-kind="drag_drop"]')->not->toContain('[data-kind="choose_item"]');

    // العلم مغلق افتراضيًا في الملف المسلَّم. نقرأ نص الملف لا قيمة env الحيّة، حتى لا يتأثر الاختبار بملف .env المحلي الذي فتح العلم للمعاينة.
    $shippedConfig = (string) file_get_contents(base_path('config/cinematic_stories.php'));
    expect(preg_match("/'enabled'\\s*=>\\s*\\(bool\\)\\s*env\\('CINEMATIC_STORIES_ENABLED',\\s*false\\)/", $shippedConfig))->toBe(1);
});

test('CIN2-10: the visual calibrator now also covers inspect areas (same text field, no new save path), shows only to editors who may see the image, and never to a viewer', function () {
    [$one, $two] = cin2_install();
    $rev = $two['revision']->fresh();
    $story = $one['story'];
    $scene = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage2_photo_inspect')->firstOrFail();
    cin_authoring()->replaceMedia($scene, 'poster', cin_poster_asset());
    $url = App\Filament\Pages\CinematicBuilderPage::getUrl(['story' => $story->public_id, 'scene' => $scene->public_id]);

    $author = cin2_staff(['cinematic-stories.view', 'cinematic-stories.manage', 'cinematic-stories.preview']);
    $html = $this->actingAs($author)->get($url)->assertOk()->getContent();
    expect($html)->toContain('data-calibrator="areas"')->toContain('data-cal-stage')->toContain('معايرة مناطق الفحص فوق الصورة')->toContain('id="cin-src-inspect"')
        ->and(substr_count($html, 'id="cin-src-inspect"'))->toBe(1)->and($html)->toContain("source: 'cin-src-inspect'")->and($html)->toMatch('/group_seven\|[^|]*\|6\|24\|66\|46/');

    $this->flushSession();
    $textOnly = cin2_staff(['cinematic-stories.view', 'cinematic-stories.manage']);
    $noImage = $this->actingAs($textOnly)->get($url)->assertOk()->getContent();
    expect($noImage)->toContain('data-calibrator-noaccess')->not->toContain('data-cal-stage')->not->toContain('signature=');

    $this->flushSession();
    $viewer = cin2_staff(['cinematic-stories.view']);
    $viewerHtml = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
    expect($viewerHtml)->not->toMatch('/group_seven\|[^|]*\|6\|24\|66\|46/')->not->toContain('data-cal-stage')->not->toContain('wire:click="saveScene"');

    // التحرير: حفظ المشهد بعد المعايرة يمرّ بنفس مسار الحفظ (النص)، ولا يُضيّع الحل ولا قيمة required_count.
    $this->flushSession();
    $this->actingAs($author);
    $before = CinematicSceneSolution::query()->where('scene_id', $scene->id)->firstOrFail()->payload;
    Livewire\Livewire::withQueryParams(['story' => $story->public_id])->test(App\Filament\Pages\CinematicBuilderPage::class)->call('editScene', $scene->public_id)
        ->set('sf.ix.areas', "group_seven|سبعة أشخاص|7|25|65|45\nperson_8|الشخص الثامن|76|24|15|46\ncut_edge|الحافة المقصوصة|92|4|7|92")->call('saveScene')->assertHasNoErrors();
    $fresh = $scene->fresh();
    expect(collect($fresh->config['interaction']['areas'])->firstWhere('id', 'group_seven'))->toMatchArray(['x' => 7, 'y' => 25, 'w' => 65, 'h' => 45])
        ->and(CinematicSceneSolution::query()->where('scene_id', $scene->id)->firstOrFail()->payload)->toEqual($before)->and($fresh->config['interaction']['required_count'])->toBe(4);
});
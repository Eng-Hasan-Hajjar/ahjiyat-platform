<?php

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\CinematicStructure;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use App\Services\Cinematic\Demo\Stage2ContentInstaller;
use App\Services\Cinematic\Demo\StageAddonInstaller;
use App\Services\Cinematic\Interactions\InteractionEngine;

require_once __DIR__.'/_plm34_support.php';

/*
| PLM-3 - المرحلة الثالثة «الساعة المتوقفة» داخل نظام القصص السينمائية الموجود (بلا محرّك جديد).
|  - PLM3-01: القالب: 7 فصول و15 مشهدًا، مفاتيح بلا تصادم، حلول صالحة، الرقعة 8×8 والحصان (5,5) والآثار الثلاثة، القفل 1145، لا اختيار باب ولا لغز رابع.
|  - PLM3-02: المثبّت: معاينة بلا كتابة، تطبيق يضيف مرحلة فقط ولا يلمس المرحلتين 1 و2، لا تكرار، يتوقف عند التعارض أو غياب المرحلة 2.
|  - PLM3-03: الوصلة بين الثانية والثالثة: المفتاح المعلّق يمنع اللعب والنشر، والمعاينة الإشرافية تعمل، والربط عند التثبيت فقط ولا يلمس مشهدًا موجودًا.
|  - PLM3-04: اللعب الكامل عبر HTTP: التتابع، النقلة (3,4) فقط، القفل 1145/١١٤٥ لا 3618، اللوحة ب فقط، الأدلة بعد النجاح فقط، النهاية عند الأبواب المغلقة.
|  - PLM3-05: الاستئناف: التقدّم والأدلة يعودان كما هما، والمحاولات الخاطئة لا تُتلف ما أُنجز.
*/

beforeEach(fn () => cin_seed());

test('PLM3-01: the stage-3 template follows the document - seven chapters, fifteen scenes, collision-free keys, valid solutions, the 8x8 board with the knight at (5,5) and three displayed traces, the 1145 lock, no door choice and nothing from stage 4 or 5', function () {
    $def = StageAddonInstaller::definition(3);
    $scenes = plm34_scenes(3);
    $by = collect($scenes)->keyBy('stable_key');

    expect($def['format'])->toBe('cinematic-stage-addon/1')->and($def['target_story_slug'])->toBe('unwritten-page')->and($def['stage']['stable_key'])->toBe('stage_3')->and($def['stage']['title'])->toBe('المرحلة الثالثة: الساعة المتوقفة')
        ->and($def['requires_existing'])->toBe(['stage_key' => 'stage_2'])->and(count($def['chapters']))->toBe(7)->and(count($scenes))->toBe(15)
        ->and(collect($def['chapters'])->pluck('stable_key')->all())->toBe(['ch3_corridor', 'ch3_knight', 'ch3_cabinet', 'ch3_clock', 'ch3_recording', 'ch3_plates', 'ch3_doors']);

    // مفاتيح فريدة وبادئة المرحلة، ولا تصادم مع المرحلتين 1 و2 (مشاهد وفصولًا).
    $keys = collect($scenes)->pluck('stable_key');
    $others = collect(plm34_stage1_scenes())->merge(plm34_stage2_scenes())->pluck('stable_key');
    $otherChapters = collect(Stage1DemoInstaller::definition()['chapters'])->merge(Stage2ContentInstaller::definition()['chapters'])->pluck('stable_key');
    expect($keys->duplicates()->all())->toBe([])->and($keys->intersect($others)->all())->toBe([])->and(collect($def['chapters'])->pluck('stable_key')->intersect($otherChapters)->all())->toBe([])
        ->and($keys->every(fn ($k) => preg_match('/\Astage3_[a-z0-9_]{2,56}\z/', $k) === 1))->toBeTrue();

    // الوصلة: أول مشهد في الثالثة معلّق على مفتاح معرَّف، ولا شيء غيره يعتمد على مفتاح غير موجود.
    expect($def['pending_links'])->toHaveCount(1)->and($def['pending_links'][0]['key'])->toBe('seam_pending_stage2_end_approval')->and($def['pending_links'][0]['gates'])->toBe(['stage3_corridor_video'])
        ->and($scenes[0]['unlock_rule']['requires'])->toBe(['seam_pending_stage2_end_approval']);

    $engine = new InteractionEngine;
    $all = $keys->all();

    foreach ($scenes as $scene) {
        expect($scene)->toHaveKeys(['stable_key', 'type', 'title', 'provisional', 'production', 'unlock_rule'])->and($scene['production'])->toHaveKeys(['link_action']);

        foreach ($scene['unlock_rule']['requires'] as $r) {
            expect(in_array($r, $all, true) || $r === 'seam_pending_stage2_end_approval')->toBeTrue("{$scene['stable_key']} → {$r}");
        }

        if ($scene['type'] === 'video') {
            expect($scene['awaiting_media'])->toBeTrue()->and($scene['config']['transcript'])->not->toBeEmpty();
        }

        if ($scene['type'] === 'interaction') {
            $public = $scene['config']['interaction'];
            expect($engine->validate($public['kind'], $public, $scene['solution']))->toBe([], $scene['stable_key']);
        }
    }

    // الرقعة: 8×8، الحصان في الصف 5 والعمود 5، والآثار المعروضة (3,4) و(3,5) و(4,4)، والمقبول (3,4) فقط.
    $knight = $by['stage3_knight_puzzle'];
    $pub = $knight['config']['interaction'];
    expect($pub['kind'])->toBe('board_move')->and($pub['board'])->toBe(['rows' => 8, 'cols' => 8])->and([$pub['piece']['row'], $pub['piece']['col']])->toBe([5, 5])
        ->and(collect($pub['targets'])->map(fn ($t) => [$t['row'], $t['col']])->all())->toBe([[3, 4], [3, 5], [4, 4]])->and($knight['solution']['correct'])->toBe('trace_a')
        ->and($knight['config']['interaction']['instructions'])->toContain('الصف ١ في أسفل الرقعة والعمود ١ على يسارها')
        ->and($knight['config']['evidence']['title'])->toBe('ورقة 11:45 وطبعة النقلة')->and($knight['config']['evidence']['summary'])->toContain('11:45');

    // القفل: أربع خانات (ساعة ثم دقيقة) والرمز 1145، وفتحه بعد النقلة.
    expect($by['stage3_time_lock']['solution']['codes'])->toBe(['cabinet_lock' => '1145'])->and($by['stage3_time_lock']['solution']['requires_scenes'])->toContain('stage3_knight_puzzle')
        ->and(collect($by['stage3_time_lock']['config']['interaction']['locks'][0]['segments'])->sum('length'))->toBe(4);

    // الساعة: الفحص يتطلب العقربين والظهر، وعبارة الظهر من الوثيقة.
    $clock = $by['stage3_clock_inspect'];
    expect($clock['solution']['required'])->toBe(['minute_hand', 'hour_hand', 'back'])->and($clock['config']['interaction']['back']['text'])->toBe('ليست الساعة ما تبحث عنه، بل ما حدث عندما توقفت.');

    // اللوحات: ب فقط، ولكل لوحة أثرها المنقوش في تسميتها (للمقارنة بما نُفِّذ لا بما يمكن تنفيذه).
    $plates = $by['stage3_plates_choice'];
    expect($plates['solution']['correct'])->toBe('plate_b')->and(collect($plates['config']['interaction']['items'])->pluck('id')->all())->toBe(['plate_a', 'plate_b', 'plate_c'])
        ->and($plates['config']['interaction']['items'][1]['label'])->toContain('الصف ٣ والعمود ٤')->and($plates['config']['interaction']['items'][0]['label'])->toContain('الصف ٣ والعمود ٦')
        ->and($plates['config']['interaction']['items'][2]['label'])->toContain('خط مستقيم');

    // لا اختيار باب في الثالثة: لا سحب وإفلات ولا هدف باسم باب، وتنتهي عند الأبواب المغلقة ببطاقة انتقال إلى الرابعة.
    $blob = json_encode(array_map(fn ($s) => [$s['config'], $s['solution']], $scenes), JSON_UNESCAPED_UNICODE);
    expect(collect($scenes)->pluck('config.interaction.kind')->filter()->unique()->sort()->values()->all())->toBe(['board_move', 'choose_item', 'inspect', 'multi_lock'])
        ->and($blob)->not->toContain('door_left')->not->toContain('door_middle')->not->toContain('door_right')
        ->and($by['stage3_end']['type'])->toBe('transition')->and($by['stage3_end']['config']['title'])->toBe('المرحلة الرابعة')->and($by['stage3_end']['config']['next_stage_title'])->toBe('الأبواب الثلاثة')
        ->and($keys->last())->toBe('stage3_end')->and($keys->filter(fn ($k) => str_starts_with($k, 'stage4_') || str_starts_with($k, 'stage5_'))->all())->toBe([]);

    // لا نص مؤقت ولا «بانتظار» بديلًا عن نص الوثيقة، ولا HTML أو روابط في النصوص العامة.
    $public = json_encode(collect($scenes)->pluck('config')->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toContain('[[')->not->toContain('بانتظار')->not->toMatch('/<[a-z!\/]/i')->not->toContain('http');

    // العناوين لا تحمل حلًّا.
    $titles = json_encode([$def['stage']['title'], $def['stage']['summary'], collect($def['chapters'])->pluck('title'), collect($scenes)->pluck('title')], JSON_UNESCAPED_UNICODE);
    expect($titles)->not->toContain('1145')->not->toContain('11:45')->not->toContain('plate_b')->not->toContain('اللوحة ب');

    // المرجع الإنتاجي: كل مشهد يعلن خانة الوسيط وطريقة الربط، ومشاهد الإضافة للتسليم موسومة بذلك.
    expect(collect($scenes)->where('production.link_action', 'scene_added_for_handoff')->pluck('stable_key')->all())->toBe(['stage3_holder_video', 'stage3_latch_video'])
        ->and(collect($scenes)->pluck('production.link_action')->unique()->sort()->values()->all())->toBe(['link_to_existing_scene', 'none', 'scene_added_for_handoff']);
});

test('PLM3-02: the installer previews without writing, then adds exactly one stage, leaves every stage-1 and stage-2 row untouched, never duplicates, and stops on a conflict or a missing stage 2', function () {
    $one = app(Stage1DemoInstaller::class)->install();
    $rev = $one['revision'];

    // بلا المرحلة الثانية: يتوقف قبل أي كتابة.
    $plan = app(StageAddonInstaller::class)->plan(3);
    $empty = plm34_counts();
    expect($plan['status'])->toBe('blocked')->and(implode(' ', $plan['problems']))->toContain('stage_2')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(3)))->toBe('addon_blocked')->and(plm34_counts())->toBe($empty);
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true])->assertExitCode(1);
    expect(plm34_counts())->toBe($empty);

    app(Stage2ContentInstaller::class)->install();
    $snap1 = plm34_snapshot($rev->fresh(), 'stage_1');
    $snap2 = plm34_snapshot($rev->fresh(), 'stage_2');
    $before = plm34_counts();
    $economy = cin_economy_snapshot();

    // معاينة: لا كتابة، والحالة جاهزة، والربط المعلّق مذكور.
    $plan = app(StageAddonInstaller::class)->plan(3);
    expect($plan['status'])->toBe('ready')->and($plan['problems'])->toBe([])->and($plan['counts'])->toBe(['stages' => 1, 'chapters' => 7, 'scenes' => 15])->and($plan['pending'][0]['resolved'])->toBeFalse();
    $this->artisan('cinematic:install-stage', ['stage' => 3])->assertExitCode(0);
    expect(plm34_counts())->toBe($before);

    // تطبيق.
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true])->assertExitCode(0);
    $after = plm34_counts();
    expect($after[0])->toBe($before[0] + 1)->and($after[1])->toBe($before[1] + 7)->and($after[2])->toBe($before[2] + 15)->and($after[3])->toBe($before[3] + 6)
        ->and(plm34_snapshot($rev->fresh(), 'stage_1'))->toBe($snap1)->and(plm34_snapshot($rev->fresh(), 'stage_2'))->toBe($snap2);

    $s2 = CinematicStage::query()->where('revision_id', $rev->id)->where('stable_key', 'stage_2')->firstOrFail();
    $s3 = CinematicStage::query()->where('revision_id', $rev->id)->where('stable_key', 'stage_3')->firstOrFail();
    expect($s3->position)->toBeGreaterThan($s2->position)->and(CinematicStory::query()->count())->toBe(1)->and(CinematicRevision::query()->count())->toBe(1);

    // الترتيب: الثالثة بعد نهاية الثانية مباشرة وآخر مشهد هو stage3_end، والعدد 37 + 15 = 52.
    $order = CinematicStructure::scenes($rev->fresh())->pluck('stable_key')->values();
    expect($order->get($order->search('stage2_end') + 1))->toBe('stage3_corridor_video')->and($order->last())->toBe('stage3_end')->and($order->count())->toBe(52);

    // إعادة التشغيل: لا شيء يتغير ولا تكرار، والمعاينة تقول already_applied.
    $again = app(StageAddonInstaller::class)->install(3);
    expect($again['status'])->toBe('already_applied')->and($again['created'])->toBeFalse()->and(plm34_counts())->toBe($after);
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true])->assertExitCode(0);
    expect(plm34_counts())->toBe($after)->and(app(StageAddonInstaller::class)->plan(3)['status'])->toBe('already_applied');

    // لا اقتصاد ولا نشر ولا وسائط مربوطة.
    expect(cin_economy_snapshot())->toBe($economy)->and($rev->fresh()->status)->not->toBe(CinematicRevision::STATUS_PUBLISHED)
        ->and(CinematicStructure::scenes($rev->fresh())->filter(fn ($s) => $s->video_asset_id !== null || $s->poster_asset_id !== null)->count())->toBe(0);
});

test('PLM3-02b: the installer refuses safely - unknown stage, production without confirmation, a half-applied stage 3, a key clash outside the stage, a bad --link - and creates a draft only when told to', function () {
    expect(cin_reason(fn () => StageAddonInstaller::definition(9)))->toBe('addon_stage_unknown');
    $this->artisan('cinematic:install-stage', ['stage' => 9])->assertExitCode(1);

    // قصة غير موجودة.
    expect(app(StageAddonInstaller::class)->plan(3)['status'])->toBe('blocked')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(3)))->toBe('addon_story_missing');

    $ctx = plm34_install(2);
    $rev = $ctx['revision'];
    $before = plm34_counts();

    // الإنتاج يتطلب تأكيدًا صريحًا، ولا كتابة قبله.
    $this->app['env'] = 'production';
    expect(cin_reason(fn () => app(StageAddonInstaller::class)->install(3)))->toBe('addon_production_confirm')->and(plm34_counts())->toBe($before);
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true])->assertExitCode(1);
    expect(plm34_counts())->toBe($before);
    $this->app['env'] = 'testing';

    // --link: صيغة خاطئة، مفتاح معلّق غير معرَّف، مشهد غير موجود، مشهد من الإضافة نفسها: كلها تُرفض قبل أي كتابة.
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true, '--link' => ['no_equals']])->assertExitCode(1);
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true, '--link' => ['=stage2_end']])->assertExitCode(1);
    foreach ([['unknown_pending' => 'stage2_end'], [PLM34_LINK_END => 'no_such_scene'], [PLM34_LINK_END => 'stage3_end'], [PLM34_LINK_END => 'Bad Key!']] as $links) {
        expect(app(StageAddonInstaller::class)->plan(3, null, $links)['status'])->toBe('blocked')
            ->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(3, null, false, false, $links)))->toBeIn(['addon_definition_invalid', 'addon_blocked'])->and(plm34_counts())->toBe($before);
    }

    // مرحلة 3 جزئية (بالمفتاح نفسه لكن بمشاهد مختلفة): لا يلمسها.
    $stub = cin_authoring()->addStage($rev->fresh(), ['stable_key' => 'stage_3', 'title' => 'يدوية']);
    $chapter = cin_authoring()->addChapter($stub, ['stable_key' => 'ch_manual', 'title' => 'فصل يدوي']);
    cin_narrative($chapter, 'manual_scene');
    $half = plm34_counts();
    expect(app(StageAddonInstaller::class)->plan(3)['status'])->toBe('blocked')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(3)))->toBe('addon_blocked')->and(plm34_counts())->toBe($half);
    cin_authoring()->removeStage($stub->fresh());

    // مفتاح مشهد من القالب موجود خارج المرحلة: يتوقف.
    $clash = cin_authoring()->addStage($rev->fresh(), ['stable_key' => 'stage_x', 'title' => 'دخيلة']);
    $cch = cin_authoring()->addChapter($clash, ['stable_key' => 'ch_x', 'title' => 'فصل']);
    cin_narrative($cch, 'stage3_end');
    $withClash = plm34_counts();
    expect(app(StageAddonInstaller::class)->plan(3)['status'])->toBe('blocked')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(3)))->toBe('addon_blocked')->and(plm34_counts())->toBe($withClash);
    cin_authoring()->removeStage($clash->fresh());

    // بلا مسودة مفتوحة: يرفض إلا مع الطلب الصريح، وعندها تُنشأ مسودة جديدة والمنشورة لا تُمس.
    $rev = $ctx['revision']->fresh();
    plm34_make_publishable($rev);
    $live = cin_publish($rev->fresh());
    $published = plm34_snapshot($live, 'stage_2');
    $counts = plm34_counts();
    expect(app(StageAddonInstaller::class)->plan(3)['will_create_draft'])->toBeTrue()->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(3)))->toBe('addon_no_draft')->and(plm34_counts())->toBe($counts);

    $r = app(StageAddonInstaller::class)->install(3, null, true);
    expect($r['status'])->toBe('applied')->and($r['revision']->id)->not->toBe($live->id)->and($r['revision']->status)->toBe(CinematicRevision::STATUS_DRAFT)
        ->and(CinematicStage::query()->where('revision_id', $live->id)->count())->toBe(2)->and(CinematicStage::query()->where('revision_id', $r['revision']->id)->count())->toBe(3)
        ->and(plm34_snapshot($live->fresh(), 'stage_2'))->toBe($published)->and($live->fresh()->status)->toBe(CinematicRevision::STATUS_PUBLISHED);
});

test('PLM3-03: the seam to stage 2 - an unresolved entry key blocks play and publishing but not the staff preview, linking happens only at install time and changes nothing that already exists, and stage 2 itself is never modified', function () {
    $ctx = plm34_install(3, []);   // بلا ربط: المفتاح المعلّق يبقى كما هو.
    $rev = $ctx['revision'];
    $first = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage3_corridor_video')->firstOrFail();
    expect($first->unlock_rule)->toBe(['requires' => ['seam_pending_stage2_end_approval']]);

    // الجاهزية: مانع واضح على المشهد المعلّق، ولا نشر.
    $r = app(CinematicReadinessChecker::class)->check($rev);
    $gate = collect($r['blockers'])->where('scene', 'stage3_corridor_video')->pluck('code')->all();
    expect($r['ok'])->toBeFalse()->and($gate)->toContain('unlock_rule_invalid');

    plm34_make_publishable($rev->fresh());
    $stillBlocked = app(CinematicReadinessChecker::class)->check($rev->fresh());
    expect($stillBlocked['ok'])->toBeFalse()->and(collect($stillBlocked['blockers'])->pluck('code')->unique()->all())->toBe(['unlock_rule_invalid']);
    expect(cin_reason(fn () => cin_publish($rev->fresh())))->not->toBeNull()->and($rev->fresh()->status)->not->toBe(CinematicRevision::STATUS_PUBLISHED);

    // المعاينة الإشرافية تعمل على كل المشاهد (المقفل أيضًا) وتحكم الخادم بلا حفظ.
    $staff = cin_admin();
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];
    $knight = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage3_knight_puzzle')->firstOrFail();
    $ok = $this->actingAs($staff)->postJson('/cinematic/preview/'.$rev->public_id.'/scenes/'.$knight->public_id.'/submit', ['submission' => ['row' => 3, 'col' => 4]])->assertOk();
    $no = $this->actingAs($staff)->postJson('/cinematic/preview/'.$rev->public_id.'/scenes/'.$knight->public_id.'/submit', ['submission' => ['row' => 3, 'col' => 5]])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($no->json('result.correct'))->toBeFalse()
        ->and([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);
});

test('PLM3-03b: linking at install time rewrites only the new scene condition, leaves stage 2 byte-identical, and a different linked scene is honoured', function () {
    $one = app(Stage1DemoInstaller::class)->install();
    app(Stage2ContentInstaller::class)->install();
    $rev = $one['revision'];
    $snap2 = plm34_snapshot($rev->fresh(), 'stage_2');

    app(StageAddonInstaller::class)->install(3, null, false, false, [PLM34_LINK_END => 'stage2_finale_video']);
    $first = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage3_corridor_video')->firstOrFail();
    expect($first->unlock_rule)->toBe(['requires' => ['stage2_finale_video']])->and(plm34_snapshot($rev->fresh(), 'stage_2'))->toBe($snap2);

    // لا يبقى أي مفتاح معلّق في المراحل الثالثة بعد الربط، والجاهزية لا تشكو من شرط غير معروف في الثالثة.
    $r = app(CinematicReadinessChecker::class)->check($rev->fresh());
    expect(collect($r['blockers'])->where('code', 'unlock_rule_invalid')->all())->toBe([]);

    // إعادة التثبيت مع ربط مختلف لا تُعدّل مشهدًا موجودًا (لا تعديل صامت لشروط مشاهد موجودة).
    $plan = app(StageAddonInstaller::class)->plan(3, null, [PLM34_LINK_END => 'stage2_end']);
    expect($plan['status'])->toBe('already_applied')->and(implode(' ', $plan['notes']))->toContain('لا يُعاد الربط');
    app(StageAddonInstaller::class)->install(3, null, false, false, [PLM34_LINK_END => 'stage2_end']);
    expect($first->fresh()->unlock_rule)->toBe(['requires' => ['stage2_finale_video']]);
});

test('PLM3-04: the whole of stage 3 over HTTP - sequence, the knight move (3,4) only, the 1145/١١٤٥ lock and not 3618, plate B only, evidence only after success, and the stage ends at the closed doors', function () {
    $ctx = plm34_ready(3, array_merge(plm34_stage1_scenes(), plm34_stage2_scenes()));
    ['slug' => $slug, 'user' => $user, 'byKey' => $byKey] = $ctx;
    $defs = collect(plm34_scenes(3))->keyBy('stable_key');
    $economy = cin_economy_snapshot();
    $bodies = [];
    $phase = [];

    $post = function (string $key, string $action, array $body = []) use ($user, $slug, $byKey, &$bodies) {
        $res = test()->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$byKey[$key]->public_id}/{$action}", $body);
        $bodies[] = $res->getContent();

        return $res;
    };
    // أدلة المرحلة الثالثة فقط (سجل الأدلة يحمل أدلة الثانية أيضًا).
    $evidence = fn ($res) => collect($res->json('evidence'))->pluck('key')->filter(fn ($k) => str_starts_with($k, 'stage3_'))->values()->all();
    $watch = function (string $key) use ($post) {
        $post($key, 'start')->assertOk();
        $post($key, 'finish', ['mode' => 'read'])->assertOk();
    };

    // 1) أول مشهد في الثالثة متاح (بعد نهاية الثانية)، وما بعده مقفل، وما لم يُفتح لا يُبدأ ولا يُجاب.
    $state = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $bodies[] = $state->getContent();
    $status = collect($state->json('state.scenes'))->pluck('status', 'key');
    expect($status['stage2_end'])->toBe('completed')->and($status['stage3_corridor_video'])->toBe('available')->and($status['stage3_corridor_inspect'])->toBe('locked')->and($status['stage3_knight_puzzle'])->toBe('locked')
        ->and($status['stage3_time_lock'])->toBe('locked')->and($status['stage3_plates_choice'])->toBe('locked');
    $post('stage3_knight_puzzle', 'start')->assertStatus(403);
    $post('stage3_knight_puzzle', 'submit', ['submission' => ['row' => 3, 'col' => 4]])->assertStatus(403);
    $post('stage3_time_lock', 'submit', ['submission' => plm34_answer($defs['stage3_time_lock'])])->assertStatus(403);
    $post('stage3_plates_choice', 'submit', ['submission' => ['item' => 'plate_b']])->assertStatus(403);

    // 2) الممر: فيديو ثم فحص إلزامي (ناقص ⇒ خطأ، كامل ⇒ نجاح).
    $watch('stage3_corridor_video');
    $post('stage3_corridor_inspect', 'start')->assertOk();
    $post('stage3_corridor_inspect', 'finish', ['mode' => 'read'])->assertStatus(422);
    expect($post('stage3_corridor_inspect', 'submit', ['submission' => ['inspected' => ['door_phrase']]])->json('result.correct'))->toBeFalse();
    expect($post('stage3_corridor_inspect', 'submit', ['submission' => plm34_answer($defs['stage3_corridor_inspect'])])->json('result.correct'))->toBeTrue();

    // 3) الرقعة: ما قبل النجاح لا يحمل 11:45 ولا 1145 في أي استجابة.
    $watch('stage3_board_intro_video');
    $post('stage3_knight_puzzle', 'start')->assertOk();
    $hints = [];

    foreach ([[3, 5], [4, 4], [3, 6], [6, 3], [5, 5], [9, 9]] as [$r, $c]) {
        $res = $post('stage3_knight_puzzle', 'submit', ['submission' => ['row' => $r, 'col' => $c]])->assertOk();
        expect($res->json('result.correct'))->toBeFalse("{$r},{$c}")->and($res->json('result.reveal'))->toBeNull()->and($evidence($res))->toBe([]);
        $hints[] = $res->json('result.hint');
    }

    expect($hints[0])->toBe('انظر إلى اختلاف المسافات بين الموضع الحالي والآثار الثلاثة.')->and($hints[1])->toBe('نقلة الحصان الواحدة: مربعان في اتجاه ومربع واحد عموديًا عليه.');
    $joined = implode("\n", $bodies);
    expect($joined)->not->toContain('11:45')->and($joined)->not->toContain('1145')->and($joined)->not->toContain('١١٤٥')->and($joined)->not->toContain('"correct":"')->and($joined)->not->toContain('"correct":[');
    // الخزانة ما زالت مقفلة بعد محاولات خاطئة.
    $post('stage3_cabinet_video', 'start')->assertStatus(403);

    $ok = $post('stage3_knight_puzzle', 'submit', ['submission' => ['row' => '٣', 'col' => '٤']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($ok->json('result.reveal'))->toContain('11:45')->and($evidence($ok))->toBe(['stage3_knight_puzzle']);
    $paper = collect($ok->json('evidence'))->firstWhere('key', 'stage3_knight_puzzle');
    expect($paper['title'])->toBe('ورقة 11:45 وطبعة النقلة')->and($paper['summary'])->toContain('من الصف ٥ والعمود ٥ إلى الصف ٣ والعمود ٤');

    // 4) الخزانة: 3618 مرفوض، 1145 بالإنجليزية والعربية مقبول؛ كل قفل يُحكم وحده والمشهد لا يكتمل بالخطأ.
    $watch('stage3_cabinet_video');
    $post('stage3_time_lock', 'start')->assertOk();

    foreach ([['36', '18'], ['11', '54'], ['11', ''], ['45', '11'], ['11', '45', '45']] as $wrong) {
        $res = $post('stage3_time_lock', 'submit', ['submission' => ['values' => ['cabinet_lock' => $wrong]]])->assertOk();
        expect($res->json('result.correct'))->toBeFalse(json_encode($wrong))->and($res->json('result.reveal'))->toBeNull();
    }

    $still = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect(collect($still->json('state.scenes'))->pluck('status', 'key')['stage3_time_lock'])->not->toBe('completed');
    $ok = $post('stage3_time_lock', 'submit', ['submission' => ['values' => ['cabinet_lock' => ['١١', '٤٥']]]])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue();
    // الإجابة بالأرقام اللاتينية تُقبل أيضًا إن أُعيد المشهد (إعادة اللعب مسموحة).
    $post('stage3_time_lock', 'start')->assertOk();
    expect($post('stage3_time_lock', 'submit', ['submission' => ['values' => ['cabinet_lock' => ['11', '45']]]])->json('result.correct'))->toBeTrue();

    // 5) الساعة: فحص العقربين والظهر ثم الحامل والجهاز.
    $watch('stage3_clock_video');
    $post('stage3_clock_inspect', 'start')->assertOk();
    expect($post('stage3_clock_inspect', 'submit', ['submission' => ['inspected' => ['minute_hand', 'hour_hand']]])->json('result.correct'))->toBeFalse();
    $ok = $post('stage3_clock_inspect', 'submit', ['submission' => plm34_answer($defs['stage3_clock_inspect'])])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($evidence($ok))->toContain('stage3_clock_inspect');
    $watch('stage3_holder_video');
    $post('stage3_recorder_lever', 'start')->assertOk();
    expect($post('stage3_recorder_lever', 'submit', ['submission' => ['inspected' => ['horn']]])->json('result.correct'))->toBeFalse();
    expect($post('stage3_recorder_lever', 'submit', ['submission' => ['inspected' => ['lever']]])->json('result.correct'))->toBeTrue();

    // 6) التسجيل: يُشغَّل ويُقرأ نصًّا، ونصه في سجل الأدلة بعد المشاهدة.
    $post('stage3_recording_video', 'start')->assertOk();
    $post('stage3_recording_video', 'finish', ['mode' => 'read'])->assertOk();

    // 7) اللوحات: أ وج مرفوضتان، ب مقبولة؛ ولا شيء من لوحة ب في الأدلة قبل النجاح.
    expect(collect(test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->json('evidence'))->pluck('key')->all())->not->toContain('stage3_plates_choice');
    $post('stage3_plates_choice', 'start')->assertOk();

    foreach (['plate_a', 'plate_c', 'plate_x', ''] as $wrong) {
        $res = $post('stage3_plates_choice', 'submit', ['submission' => ['item' => $wrong]])->assertOk();
        expect($res->json('result.correct'))->toBeFalse($wrong)->and($res->json('result.reveal'))->toBeNull()->and($evidence($res))->not->toContain('stage3_plates_choice');
    }

    $post('stage3_latch_video', 'start')->assertStatus(403);
    $ok = $post('stage3_plates_choice', 'submit', ['submission' => ['item' => 'plate_b']])->assertOk();
    expect($ok->json('result.correct'))->toBeTrue()->and($evidence($ok))->toContain('stage3_plates_choice')->and($ok->json('result.reveal'))->toContain('مزلاج');

    // 8) المزلاج والأبواب المغلقة ثم بطاقة الانتقال إلى الرابعة؛ الثالثة لا تختار بابًا.
    $watch('stage3_latch_video');
    $watch('stage3_doors_video');
    $end = $post('stage3_end', 'start')->assertOk();
    expect($end->json('scene.transition.title'))->toBe('المرحلة الرابعة')->and($end->json('scene.transition.next_stage_title'))->toBe('الأبواب الثلاثة');
    $fin = $post('stage3_end', 'finish', ['mode' => 'read'])->assertOk();
    expect($fin->json('state.progress.status'))->toBe('completed')->and($fin->json('state.progress.percent'))->toBe(100);

    // لا اقتصاد، ولا حل أو سر في أي استجابة، ولا اسم باب.
    expect(cin_economy_snapshot())->toBe($economy);
    $joined = implode("\n", $bodies);
    expect($joined)->not->toContain('"accepted"')->and($joined)->not->toContain('"codes"')->and($joined)->not->toContain('lock_hints')->and($joined)->not->toContain('"solution"')->and($joined)->not->toContain('requires_scenes')
        ->and($joined)->not->toContain('door_left')->and($joined)->not->toContain('door_middle')->and($joined)->not->toContain('door_right')->and($joined)->not->toContain('plate_b_correct');

    // اكتملت 37 + 15 مشهدًا، وكل مشاهد الثالثة مكتملة.
    $p = CinematicProgress::query()->where('user_id', $user->id)->firstOrFail();
    expect(CinematicSceneState::query()->where('progress_id', $p->id)->whereNotNull('completed_at')->count())->toBe(52);
});

test('PLM3-05: resuming restores exactly the same progress and evidence log, evidence holds finished scenes only, and wrong attempts never undo what was done', function () {
    $ctx = plm34_ready(3, array_merge(plm34_stage1_scenes(), plm34_stage2_scenes()));
    ['slug' => $slug, 'user' => $user, 'byKey' => $byKey, 'story' => $story] = $ctx;
    $defs = collect(plm34_scenes(3))->keyBy('stable_key');

    // نلعب حتى بعد النقلة مباشرة بالخدمة.
    $progress = $ctx['progress'];

    foreach (['stage3_corridor_video', 'stage3_corridor_inspect', 'stage3_board_intro_video', 'stage3_knight_puzzle'] as $key) {
        plm34_play_scene($progress, $byKey[$key], $defs[$key]);
    }

    $before = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $evBefore = collect($before->json('evidence'))->pluck('key')->all();
    expect($evBefore)->toContain('stage3_knight_puzzle')->and($evBefore)->not->toContain('stage3_plates_choice')->and($evBefore)->not->toContain('stage3_clock_inspect');

    // خطأ لاحق (قفل خاطئ ثم لوحة خاطئة) لا يمحو شيئًا مما أُنجز.
    plm34_play_scene($progress, $byKey['stage3_cabinet_video'], $defs['stage3_cabinet_video']);
    cin_play()->start($progress, $byKey['stage3_time_lock']);
    expect(cin_play()->submit($progress, $byKey['stage3_time_lock'], ['values' => ['cabinet_lock' => ['36', '18']]])['result']['correct'])->toBeFalse();

    // «العودة»: بدء تقدّم جديد لنفس اللاعب يعيد التقدّم نفسه لا تقدّمًا جديدًا (الاستئناف).
    $resumed = cin_play()->begin($user, $story);
    expect($resumed->id)->toBe($progress->id);
    $after = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect(collect($after->json('evidence'))->pluck('key')->all())->toBe($evBefore)
        ->and(collect($after->json('state.scenes'))->pluck('status', 'key')->only(['stage3_corridor_video', 'stage3_corridor_inspect', 'stage3_board_intro_video', 'stage3_knight_puzzle', 'stage3_cabinet_video', 'stage3_time_lock', 'stage3_clock_video'])->all())
        ->toBe(['stage3_corridor_video' => 'completed', 'stage3_corridor_inspect' => 'completed', 'stage3_board_intro_video' => 'completed', 'stage3_knight_puzzle' => 'completed', 'stage3_cabinet_video' => 'completed', 'stage3_time_lock' => 'available', 'stage3_clock_video' => 'locked'])
        ->and($after->json('state.current'))->toBe($byKey['stage3_time_lock']->public_id);

    // وبعد تقدّمه بقية الطريق تبقى النقلة مكتملة بدليلها (إعادة النقلة بإجابة خاطئة لا تُسقط الاكتمال).
    cin_play()->start($progress, $byKey['stage3_knight_puzzle']);
    cin_play()->submit($progress, $byKey['stage3_knight_puzzle'], ['row' => 3, 'col' => 5]);
    $afterRetry = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect(collect($afterRetry->json('state.scenes'))->pluck('status', 'key')['stage3_knight_puzzle'])->toBe('completed')->and(collect($afterRetry->json('evidence'))->pluck('key')->all())->toContain('stage3_knight_puzzle');
});

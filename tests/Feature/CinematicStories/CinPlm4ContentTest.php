<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStage;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\CinematicStructure;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use App\Services\Cinematic\Demo\Stage2ContentInstaller;
use App\Services\Cinematic\Demo\StageAddonInstaller;
use App\Services\Cinematic\Interactions\InteractionEngine;

require_once __DIR__.'/_plm34_support.php';

/*
| PLM-4 - المرحلة الرابعة «الأبواب الثلاثة» داخل نظام القصص السينمائية الموجود، وتنتهي عند الساعة الحجرية بلا لغز من الخامسة.
|  - PLM4-01: القالب: 6 فصول و15 مشهدًا، مفاتيح بلا تصادم، لغز الباب بوضع اللوحة ب، ثلاثة أسئلة استنتاج (استنتاج + مصدره)، لا لغز خامسة.
|  - PLM4-02: المثبّت: يتطلب الثالثة ونهايتها، لا يلمس المراحل 1–3، لا تكرار، يتوقف عند التعارض.
|  - PLM4-03: الوصلة: بطاقة الملف ب 214 معلّقة، تمنع النشر وتبقي المنشور بلا مرحلة رابعة، ولا تمنع المعاينة الإشرافية.
|  - PLM4-04: اللعب الكامل عبر HTTP: التتابع 3→4، اختيار الباب وحده لا يفتح، اللوحة ب على الباب الأيسر فقط، الاستنتاج مع مصدره، الإجابات المسنودة تبقى، النهاية عند الساعة الحجرية.
|  - PLM4-05: الاستئناف والأدلة: ما أُثبت يبقى ولا يُمحى بخطأ لاحق أو بالخروج، والأدلة لا تظهر قبل أوانها.
*/

beforeEach(fn () => cin_seed());

test('PLM4-01: the stage-4 template follows the document - six chapters, fifteen scenes, collision-free keys, the door puzzle needs plate B placed, three inference questions pair a conclusion with its source, and nothing of stage 5', function () {
    $def = StageAddonInstaller::definition(4);
    $scenes = plm34_scenes(4);
    $by = collect($scenes)->keyBy('stable_key');

    expect($def['format'])->toBe('cinematic-stage-addon/1')->and($def['target_story_slug'])->toBe('unwritten-page')->and($def['stage']['stable_key'])->toBe('stage_4')->and($def['stage']['title'])->toBe('المرحلة الرابعة: الأبواب الثلاثة')
        ->and($def['requires_existing'])->toBe(['stage_key' => 'stage_3', 'scene_key' => 'stage3_end'])->and(count($def['chapters']))->toBe(6)->and(count($scenes))->toBe(15)
        ->and(collect($def['chapters'])->pluck('stable_key')->all())->toBe(['ch4_doors', 'ch4_match', 'ch4_room', 'ch4_evidence', 'ch4_question', 'ch4_stone']);

    $keys = collect($scenes)->pluck('stable_key');
    $others = collect(plm34_stage1_scenes())->merge(plm34_stage2_scenes())->merge(plm34_scenes(3))->pluck('stable_key');
    $otherChapters = collect(Stage1DemoInstaller::definition()['chapters'])->merge(Stage2ContentInstaller::definition()['chapters'])->merge(StageAddonInstaller::definition(3)['chapters'])->pluck('stable_key');
    expect($keys->duplicates()->all())->toBe([])->and($keys->intersect($others)->all())->toBe([])->and(collect($def['chapters'])->pluck('stable_key')->intersect($otherChapters)->all())->toBe([])
        ->and($keys->every(fn ($k) => preg_match('/\Astage4_[a-z0-9_]{2,56}\z/', $k) === 1))->toBeTrue()
        ->and($scenes[0]['unlock_rule']['requires'])->toBe(['stage3_end']);

    // الوصلة: بطاقة الملف ب 214 معلّقة وتعلّق مشهد الشخص الإضافي بشرط الفتح (ومعه كل ما بعده بالتتابع)؛ شرط الحل لا يحمل المفتاح المعلّق كي تعمل المعاينة الإشرافية.
    expect($def['pending_links'])->toHaveCount(1)->and($def['pending_links'][0]['key'])->toBe('seam_pending_stage2_photo_card')->and($def['pending_links'][0]['gates'])->toBe(['stage4_photo_inspect'])
        ->and($by['stage4_photo_inspect']['unlock_rule']['requires'])->toBe(['stage4_room_video', 'seam_pending_stage2_photo_card'])->and($by['stage4_photo_inspect']['solution']['requires_scenes'])->toBe(['stage4_room_video']);

    $engine = new InteractionEngine;
    $all = $keys->all();

    foreach ($scenes as $scene) {
        expect($scene)->toHaveKeys(['stable_key', 'type', 'title', 'provisional', 'production', 'unlock_rule'])->and($scene['production'])->toHaveKeys(['link_action']);

        foreach ($scene['unlock_rule']['requires'] as $r) {
            expect(in_array($r, $all, true) || in_array($r, ['stage3_end', 'seam_pending_stage2_photo_card'], true))->toBeTrue("{$scene['stable_key']} → {$r}");
        }

        if ($scene['type'] === 'video') {
            expect($scene['awaiting_media'])->toBeTrue()->and($scene['config']['transcript'])->not->toBeEmpty();
        }

        if ($scene['type'] === 'interaction') {
            $public = $scene['config']['interaction'];
            expect($engine->validate($public['kind'], $public, $scene['solution']))->toBe([], $scene['stable_key']);
        }
    }

    // علامات الأبواب الثلاثة والآثار: الأيسر حصان (5,5)→(3,4) يسارًا، الأوسط ساعة بلا عقارب بالأثر نفسه، الأيمن كتاب مفتوح (3,6) يمينًا؛ كلها إلزامية.
    $inspect = $by['stage4_doors_inspect'];
    $notes = collect($inspect['config']['interaction']['areas'])->pluck('note', 'id');
    expect($inspect['solution']['required'])->toBe(['left_mark', 'left_plate', 'mid_mark', 'mid_plate', 'right_mark', 'right_plate'])->and($notes['left_mark'])->toBe('حصان.')->and($notes['mid_mark'])->toBe('ساعة بلا عقارب.')->and($notes['right_mark'])->toBe('كتاب مفتوح.')
        ->and($notes['left_plate'])->toBe($notes['mid_plate'])->and($notes['left_plate'])->toContain('الصف ٣ والعمود ٤')->and($notes['left_plate'])->toContain('نحو اليسار')
        ->and($notes['right_plate'])->toContain('الصف ٣ والعمود ٦')->and($notes['right_plate'])->toContain('نحو اليمين');

    // لغز الباب: وضع اللوحة ب على موضع مطابقة الباب (لا اختيار اسم باب)، والصحيح الأيسر، ويدعم السحب أو الاختيار-ثم-التأكيد بالتحقق الخادمي نفسه.
    $match = $by['stage4_door_match'];
    $pub = $match['config']['interaction'];
    expect($pub['kind'])->toBe('drag_drop')->and($pub['items'])->toHaveCount(1)->and($pub['items'][0]['id'])->toBe('plate_b')->and(collect($pub['targets'])->pluck('id')->all())->toBe(['door_left', 'door_middle', 'door_right'])
        ->and($match['solution']['map'])->toBe(['plate_b' => 'door_left'])->and($match['solution']['hints'])->toBe(['راجع نص التسجيل.', 'قد يتفق الأثر وتختلف العلامة.'])
        ->and($pub['wrong_message'])->toBe('المزلاج لا يتحرك، وتبقى اللوحة بيد آدم.')->and($pub['instructions'])->toContain('لا يكفي الضغط على اسم الباب دون وضع اللوحة');

    // الاستنتاج: خمسة مصادر في ثلاث مجموعات، وأسئلة ثلاثة تقبل زوجًا واحدًا بالنصوص نفسها من الوثيقة.
    $q = fn (string $k) => $by[$k];
    expect(collect($q('stage4_inference_q1')['config']['interaction']['sources'])->pluck('id')->all())->toBe(['src_message', 'src_photo', 'src_card', 'src_paper', 'src_clock'])
        ->and(collect($q('stage4_inference_q1')['config']['interaction']['sources'])->pluck('group')->unique()->values()->all())->toBe(['الرسالة', 'الصورة وملفها', 'الورقة والساعة'])
        ->and($q('stage4_inference_q1')['solution']['conclusion'])->toBe('opt_b')->and($q('stage4_inference_q1')['solution']['sources'])->toBe(['src_message'])
        ->and($q('stage4_inference_q2')['solution']['conclusion'])->toBe('opt_a')->and($q('stage4_inference_q2')['solution']['sources'])->toBe(['src_photo', 'src_card'])
        ->and($q('stage4_inference_q3')['solution']['conclusion'])->toBe('opt_b')->and($q('stage4_inference_q3')['solution']['sources'])->toBe(['src_paper', 'src_clock'])
        ->and($q('stage4_inference_q1')['config']['interaction']['wrong_message'])->toBe('أي جزء من دليلك يثبت هذه الإضافة؟')
        ->and($q('stage4_inference_q1')['config']['interaction']['options'][1]['label'])->toBe('وعدتني بكتاب يحمل اسمي، ولم تقل إنه أحد الكتب السبعة.')
        ->and($q('stage4_inference_q2')['config']['interaction']['options'][0]['label'])->toBe('يوجد شخص إضافي عند المدخل، لكن الدليل المتاح لا يحدد هويته أو ما فعله.')
        ->and($q('stage4_inference_q3')['config']['interaction']['options'][1]['label'])->toBe('الورقة والساعة المتوقفة تحملان القراءة نفسها، وعلاقتها بالصورة لم تثبت.')
        ->and(collect(['q1', 'q2', 'q3'])->map(fn ($n) => $q("stage4_inference_{$n}")['config']['evidence']['title'])->all())->toBe(['وعد الرسالة', 'شخص إضافي مجهول', 'قراءة وقت غير مؤرخة']);

    // التتابع: الأسئلة الثلاثة بترتيبها بعد غرفة الأدلة، وما بعدها لا يُفتح قبلها.
    expect($by['stage4_inference_q1']['unlock_rule']['requires'])->toBe(['stage4_evidence_room'])->and($by['stage4_inference_q2']['unlock_rule']['requires'])->toBe(['stage4_inference_q1'])->and($by['stage4_inference_q3']['unlock_rule']['requires'])->toBe(['stage4_inference_q2'])
        ->and($by['stage4_inference_result']['unlock_rule']['requires'])->toBe(['stage4_inference_q3'])->and($by['stage4_question_video']['unlock_rule']['requires'])->toBe(['stage4_inference_result']);

    // النتيجة فهم جديد لا فتح مادي: لا قفل ولا حاوية ولا ألواح، ونص النتيجة يقول ذلك.
    expect(collect($scenes)->pluck('config.interaction.kind')->filter()->unique()->sort()->values()->all())->toBe(['drag_drop', 'inference', 'inspect'])
        ->and(json_encode($by['stage4_inference_result']['config'], JSON_UNESCAPED_UNICODE))->toContain('لا ينفتح شيء هنا بسبب استنتاج');

    // تنتهي الرابعة عند الساعة الحجرية بالمصباح في الحامل: لا لغز من الخامسة (فحص القضيب والظل فقط) وبطاقة الانتقال إلى «ساعة الظل».
    expect($keys->last())->toBe('stage4_end')->and($keys->filter(fn ($k) => str_starts_with($k, 'stage5_'))->all())->toBe([])->and($by['stage4_end']['type'])->toBe('transition')
        ->and($by['stage4_end']['config']['title'])->toBe('المرحلة الخامسة')->and($by['stage4_end']['config']['next_stage_title'])->toBe('ساعة الظل')
        ->and($by['stage4_stone_inspect']['solution']['required'])->toBe(['rod', 'shadow'])->and(collect($by['stage4_stone_inspect']['config']['interaction']['areas'])->pluck('id')->all())->toBe(['rod', 'shadow'])
        ->and($by['stage4_lamp_video']['production']['link_action'])->toBe('scene_added_for_handoff');

    // لا نص مؤقت ولا «بانتظار» ولا HTML ولا روابط؛ والعناوين والملخصات لا تحمل حلًّا (الملخص يظهر للاعب في صفحة السلسلة).
    $public = json_encode(collect($scenes)->pluck('config')->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toContain('[[')->not->toContain('بانتظار')->not->toMatch('/<[a-z!\/]/i')->not->toContain('http');
    $titles = json_encode([$def['stage']['title'], $def['stage']['summary'], collect($def['chapters'])->map(fn ($c) => [$c['title'], $c['summary']]), collect($scenes)->pluck('title')], JSON_UNESCAPED_UNICODE);
    expect($titles)->not->toContain('الأيسر')->not->toContain('plate_b')->not->toContain('اللوحة ب')->not->toContain('11:45')->not->toContain('door_left');
});

test('PLM4-02: the installer needs stage 3 and its end, adds exactly one stage without touching stages 1-3, never duplicates, and stops on a conflict', function () {
    $ctx = plm34_install(2);
    $rev = $ctx['revision'];

    // بلا الثالثة: يتوقف قبل أي كتابة (الترتيب: لا رابعة قبل ثالثة).
    $before = plm34_counts();
    $plan = app(StageAddonInstaller::class)->plan(4);
    expect($plan['status'])->toBe('blocked')->and(implode(' ', $plan['problems']))->toContain('stage_3')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(4)))->toBe('addon_blocked')->and(plm34_counts())->toBe($before);
    $this->artisan('cinematic:install-stage', ['stage' => 4, '--apply' => true])->assertExitCode(1);
    expect(plm34_counts())->toBe($before);

    app(StageAddonInstaller::class)->install(3, null, false, false, [PLM34_LINK_END => 'stage2_end']);
    $before = plm34_counts();
    $economy = cin_economy_snapshot();

    // نهاية الثالثة مفقودة: يتوقف. (التعديل هنا من الاختبار نفسه، فتؤخذ بصمات المراحل بعد إعادته: البصمة تشمل updated_at، ولو أُخذت قبله لاختلفت كلما عبرت ثانية الساعة بين الخطوتين.)
    $end = CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage3_end')->firstOrFail();
    $end->forceFill(['stable_key' => 'renamed_end'])->save();
    expect(app(StageAddonInstaller::class)->plan(4)['status'])->toBe('blocked')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(4)))->toBe('addon_blocked')->and(plm34_counts())->toBe($before);
    $end->forceFill(['stable_key' => 'stage3_end'])->save();
    $snaps = array_map(fn ($k) => plm34_snapshot($rev->fresh(), $k), ['stage_1', 'stage_2', 'stage_3']);

    // معاينة بلا كتابة.
    $plan = app(StageAddonInstaller::class)->plan(4);
    expect($plan['status'])->toBe('ready')->and($plan['problems'])->toBe([])->and($plan['counts'])->toBe(['stages' => 1, 'chapters' => 6, 'scenes' => 15])->and($plan['pending'][0]['key'])->toBe('seam_pending_stage2_photo_card')->and($plan['pending'][0]['resolved'])->toBeFalse();
    $this->artisan('cinematic:install-stage', ['stage' => 4])->assertExitCode(0);
    expect(plm34_counts())->toBe($before);

    // تطبيق: مرحلة وستة فصول وخمسة عشر مشهدًا وسبعة حلول، دون لمس 1–3.
    $this->artisan('cinematic:install-stage', ['stage' => 4, '--apply' => true])->assertExitCode(0);
    $after = plm34_counts();
    expect($after[0])->toBe($before[0] + 1)->and($after[1])->toBe($before[1] + 6)->and($after[2])->toBe($before[2] + 15)->and($after[3])->toBe($before[3] + 7)
        ->and(array_map(fn ($k) => plm34_snapshot($rev->fresh(), $k), ['stage_1', 'stage_2', 'stage_3']))->toBe($snaps);

    $order = CinematicStructure::scenes($rev->fresh())->pluck('stable_key')->values();
    expect($order->get($order->search('stage3_end') + 1))->toBe('stage4_doors_video')->and($order->last())->toBe('stage4_end')->and($order->count())->toBe(67)
        ->and(CinematicStage::query()->where('revision_id', $rev->id)->orderBy('position')->pluck('stable_key')->all())->toBe(['stage_1', 'stage_2', 'stage_3', 'stage_4']);

    // إعادة التشغيل: لا تكرار.
    $again = app(StageAddonInstaller::class)->install(4);
    expect($again['status'])->toBe('already_applied')->and(plm34_counts())->toBe($after)->and(app(StageAddonInstaller::class)->plan(4)['status'])->toBe('already_applied')->and(app(StageAddonInstaller::class)->plan(3)['status'])->toBe('already_applied');
    $this->artisan('cinematic:install-stage', ['stage' => 4, '--apply' => true])->assertExitCode(0);
    $this->artisan('cinematic:install-stage', ['stage' => 3, '--apply' => true])->assertExitCode(0);
    expect(plm34_counts())->toBe($after)->and(cin_economy_snapshot())->toBe($economy)->and($rev->fresh()->status)->not->toBe(CinematicRevision::STATUS_PUBLISHED);

    // مرحلة رابعة جزئية: يرفض ولا يلمسها.
    $stage4 = CinematicStage::query()->where('revision_id', $rev->id)->where('stable_key', 'stage_4')->firstOrFail();
    CinematicScene::query()->where('revision_id', $rev->id)->where('stable_key', 'stage4_lamp_video')->firstOrFail()->forceFill(['stable_key' => 'stage4_lamp_renamed'])->save();
    expect(app(StageAddonInstaller::class)->plan(4)['status'])->toBe('blocked')->and(cin_reason(fn () => app(StageAddonInstaller::class)->install(4)))->toBe('addon_blocked')->and(plm34_counts())->toBe($after)
        ->and($stage4->fresh())->not->toBeNull();
});

test('PLM4-03: the pending photo-card key blocks publishing and keeps the published run without a stage 4, yet the staff preview judges every scene; linking to a real scene opens the way', function () {
    // نسخة منشورة بالمراحل 1–3 (محاكاة الوصلة الأولى)، ثم مسودة جديدة تُضاف إليها الرابعة بلا ربط البطاقة.
    $ctx = plm34_install(3);
    plm34_make_publishable($ctx['revision']);
    $live = cin_publish($ctx['revision']->fresh());
    $liveSnap = [plm34_snapshot($live, 'stage_2'), plm34_snapshot($live, 'stage_3')];

    $r = app(StageAddonInstaller::class)->install(4, null, true);
    $draft = $r['revision'];
    expect($draft->id)->not->toBe($live->id)->and($draft->status)->toBe(CinematicRevision::STATUS_DRAFT);

    $photo = CinematicScene::query()->where('revision_id', $draft->id)->where('stable_key', 'stage4_photo_inspect')->firstOrFail();
    expect($photo->unlock_rule['requires'])->toContain('seam_pending_stage2_photo_card');

    // مانع الجاهزية: مفتاح شرط الفتح المعلّق، ولا يُضعَّف الفحص.
    plm34_make_publishable($draft->fresh());
    $check = app(CinematicReadinessChecker::class)->check($draft->fresh());
    $codes = collect($check['blockers'])->where('scene', 'stage4_photo_inspect')->pluck('code')->all();
    expect($check['ok'])->toBeFalse()->and($codes)->toBe(['unlock_rule_invalid'])
        ->and(collect($check['blockers'])->where('scene', '!=', 'stage4_photo_inspect')->pluck('code')->unique()->all())->toBe([]);
    expect(cin_reason(fn () => cin_publish($draft->fresh())))->not->toBeNull()->and($draft->fresh()->status)->not->toBe(CinematicRevision::STATUS_PUBLISHED);

    // المنشور كما هو: بلا مرحلة رابعة، وبلا أي تغيير في الثانية والثالثة.
    $liveNow = CinematicRevision::query()->where('story_id', $ctx['story']->id)->where('status', CinematicRevision::STATUS_PUBLISHED)->firstOrFail();
    expect($liveNow->id)->toBe($live->id)->and(CinematicScene::query()->where('revision_id', $live->id)->where('stable_key', 'like', 'stage4_%')->count())->toBe(0)
        ->and([plm34_snapshot($live->fresh(), 'stage_2'), plm34_snapshot($live->fresh(), 'stage_3')])->toBe($liveSnap);

    // المعاينة الإشرافية: كل المشاهد تُحكم على الخادم ولو كان شرطها معلّقًا، ولا شيء يُحفظ.
    $staff = cin_admin();
    $before = [CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()];
    $submit = fn (string $key, array $body) => $this->actingAs($staff)->postJson('/cinematic/preview/'.$draft->public_id.'/scenes/'.CinematicScene::query()->where('revision_id', $draft->id)->where('stable_key', $key)->firstOrFail()->public_id.'/submit', ['submission' => $body])->assertOk();
    expect($submit('stage4_photo_inspect', ['inspected' => ['extra_person', 'file_card']])->json('result.correct'))->toBeTrue()
        ->and($submit('stage4_door_match', ['placements' => ['plate_b' => 'door_left']])->json('result.correct'))->toBeTrue()
        ->and($submit('stage4_door_match', ['placements' => ['plate_b' => 'door_middle']])->json('result.correct'))->toBeFalse()
        ->and($submit('stage4_inference_q1', ['conclusion' => 'opt_b', 'sources' => ['src_message']])->json('result.correct'))->toBeTrue()
        ->and($submit('stage4_inference_q1', ['conclusion' => 'opt_b', 'sources' => ['src_photo']])->json('result.correct'))->toBeFalse()
        ->and([CinematicProgress::query()->count(), CinematicSceneState::query()->count(), cin_economy_snapshot()])->toBe($before);

    // بعد ربط حقيقي عند التثبيت (مسودة جديدة بلا رابعة): لا مفاتيح مجهولة وتُنشر.
    CinematicScene::query()->where('revision_id', $draft->id)->where('stable_key', 'like', 'stage4_%')->get()->each(fn ($s) => cin_authoring()->removeScene($s->fresh()));
    $stage4 = CinematicStage::query()->where('revision_id', $draft->id)->where('stable_key', 'stage_4')->first();
    $stage4 && CinematicScene::query()->where('stage_id', $stage4->id)->count() === 0 && cin_authoring()->removeStage($stage4->fresh());
    expect(CinematicStage::query()->where('revision_id', $draft->id)->where('stable_key', 'stage_4')->exists())->toBeFalse();

    app(StageAddonInstaller::class)->install(4, null, false, false, [PLM34_LINK_CARD => 'stage2_photo_inspect']);
    $photo = CinematicScene::query()->where('revision_id', $draft->id)->where('stable_key', 'stage4_photo_inspect')->firstOrFail();
    $solution = $photo->solution()->first();
    expect($photo->unlock_rule)->toBe(['requires' => ['stage4_room_video', 'stage2_photo_inspect']])
        ->and(collect(app(CinematicReadinessChecker::class)->check($draft->fresh())['blockers'])->pluck('code')->all())->not->toContain('unlock_rule_invalid')->and($solution)->not->toBeNull();
});

test('PLM4-04: the whole of stage 4 over HTTP - 3 to 4 sequencing, a door name alone opens nothing, plate B on the left door only, each inference needs its own source, answers stay saved, and the run stops at the stone clock', function () {
    $before = array_merge(plm34_stage1_scenes(), plm34_stage2_scenes(), array_slice(plm34_scenes(3), 0, -1));   // حتى stage3_doors_video بلا بطاقة الانتقال
    $ctx = plm34_ready(4, $before);
    ['slug' => $slug, 'user' => $user, 'byKey' => $byKey, 'progress' => $progress] = $ctx;
    $defs = collect(plm34_scenes(4))->keyBy('stable_key');
    $economy = cin_economy_snapshot();
    $bodies = [];

    $post = function (string $key, string $action, array $body = []) use ($user, $slug, $byKey, &$bodies) {
        $res = test()->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$byKey[$key]->public_id}/{$action}", $body);
        $bodies[] = $res->getContent();

        return $res;
    };
    $evidence = fn ($res) => collect($res->json('evidence'))->pluck('key')->filter(fn ($k) => str_starts_with($k, 'stage4_'))->values()->all();
    $watch = function (string $key) use ($post) {
        $post($key, 'start')->assertOk();
        $post($key, 'finish', ['mode' => 'read'])->assertOk();
    };
    $submit = fn (string $key, array $body) => $post($key, 'submit', ['submission' => $body])->assertOk();

    // 1) التتابع 3→4: الأولى في الرابعة مقفلة حتى تُكتمل بطاقة نهاية الثالثة.
    $state = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $status = collect($state->json('state.scenes'))->pluck('status', 'key');
    expect($status['stage3_doors_video'])->toBe('completed')->and($status['stage3_end'])->toBe('available')->and($status['stage4_doors_video'])->toBe('locked');
    $post('stage4_doors_video', 'start')->assertStatus(403);
    $post('stage3_end', 'start')->assertOk();
    $post('stage3_end', 'finish', ['mode' => 'read'])->assertOk();
    $status = collect(test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->json('state.scenes'))->pluck('status', 'key');
    expect($status['stage4_doors_video'])->toBe('available')->and($status['stage4_doors_inspect'])->toBe('locked')->and($status['stage4_door_match'])->toBe('locked');

    // 2) ما لم يُفتح لا يُجاب: لغز الباب والاستنتاج ومشهد الشخص الإضافي.
    $post('stage4_door_match', 'submit', ['submission' => ['placements' => ['plate_b' => 'door_left']]])->assertStatus(403);
    $post('stage4_inference_q1', 'submit', ['submission' => plm34_answer($defs['stage4_inference_q1'])])->assertStatus(403);
    $post('stage4_photo_inspect', 'start')->assertStatus(403);

    // 3) فحص العلامات: ناقص ⇒ خطأ (لا يُتخطى)، كامل ⇒ نجاح.
    $watch('stage4_doors_video');
    $post('stage4_doors_inspect', 'start')->assertOk();
    $post('stage4_doors_inspect', 'finish', ['mode' => 'read'])->assertStatus(422);
    expect($submit('stage4_doors_inspect', ['inspected' => ['left_mark', 'mid_mark', 'right_mark']])->json('result.correct'))->toBeFalse();
    expect($submit('stage4_doors_inspect', plm34_answer($defs['stage4_doors_inspect']))->json('result.correct'))->toBeTrue();

    // 4) لغز الباب: اسم الباب وحده لا يفتح، ولا باب بلا لوحة، ولا لوحة على باب خطأ (الأوسط يشارك الأثر والأيمن يخالف الاتجاه).
    $post('stage4_door_match', 'start')->assertOk();
    $hints = [];

    foreach ([
        ['door' => 'door_left'], ['target' => 'door_left'], ['item' => 'door_left'], ['option' => 'door_left'], ['area' => 'door_left'], ['placements' => []], ['placements' => ['plate_b' => '']],
        ['placements' => ['door_left' => 'plate_b']], ['placements' => ['plate_a' => 'door_left']], ['placements' => ['plate_b' => 'door_middle']], ['placements' => ['plate_b' => 'door_right']],
        ['placements' => ['plate_b' => ['door_left']]], ['placements' => ['plate_b' => 'door_left', 'plate_c' => 'door_right']],
    ] as $wrong) {
        $res = $submit('stage4_door_match', $wrong);
        expect($res->json('result.correct'))->toBeFalse(json_encode($wrong))->and($res->json('result.reveal'))->toBeNull()->and($evidence($res))->toBe([]);
        $hints[] = $res->json('result.hint');
    }

    expect($hints[0])->toBe('راجع نص التسجيل.')->and($hints[1])->toBe('قد يتفق الأثر وتختلف العلامة.');
    $still = collect(test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->json('state.scenes'))->pluck('status', 'key');
    expect($still['stage4_door_match'])->not->toBe('completed')->and($still['stage4_room_video'])->toBe('locked');
    $post('stage4_room_video', 'start')->assertStatus(403);

    $ok = $submit('stage4_door_match', ['placements' => ['plate_b' => 'door_left']]);
    expect($ok->json('result.correct'))->toBeTrue()->and($ok->json('result.reveal'))->toContain('هذا هو الأثر نفسه');

    // 5) الغرفة: الصورة وبطاقتها، ثم غرفة الأدلة.
    $watch('stage4_room_video');
    $post('stage4_photo_inspect', 'start')->assertOk();
    expect($submit('stage4_photo_inspect', ['inspected' => ['extra_person']])->json('result.correct'))->toBeFalse();
    expect($submit('stage4_photo_inspect', ['inspected' => ['extra_person', 'file_card']])->json('result.correct'))->toBeTrue();
    $watch('stage4_evidence_room');

    // 6) الاستنتاج 1: الاستنتاج الصحيح بلا مصدره أو بمصدر خطأ ⇒ خطأ، ثم الزوج.
    $post('stage4_inference_q1', 'start')->assertOk();
    $firstHints = [];

    foreach ([['conclusion' => 'opt_b', 'sources' => []], ['conclusion' => 'opt_b', 'sources' => ['src_photo']], ['conclusion' => 'opt_b', 'sources' => ['src_message', 'src_photo']], ['conclusion' => 'opt_a', 'sources' => ['src_message']],
        ['conclusion' => 'opt_b', 'sources' => ['src_message', 'src_photo', 'src_card', 'src_paper', 'src_clock']], ['conclusion' => 'opt_b'], ['sources' => ['src_message']]] as $wrong) {
        $res = $submit('stage4_inference_q1', $wrong);
        expect($res->json('result.correct'))->toBeFalse(json_encode($wrong))->and($res->json('result.reveal'))->toBeNull()->and($evidence($res))->toBe([]);
        $firstHints[] = $res->json('result.hint');
    }

    expect($firstHints[0])->toBe('اقرأ ما يقوله المصدر حرفيًا.')->and($firstHints[1])->toBe('الرسالة لا تذكر السبعة.');
    $q1 = $submit('stage4_inference_q1', ['conclusion' => 'opt_b', 'sources' => ['src_message']]);
    expect($q1->json('result.correct'))->toBeTrue()->and($evidence($q1))->toBe(['stage4_inference_q1']);
    expect(collect($q1->json('evidence'))->firstWhere('key', 'stage4_inference_q1')['title'])->toBe('وعد الرسالة');

    // 7) الاستنتاج 2: أخطاء متعددة ثم خروج وعودة؛ إجابة الأول المسنودة تبقى ولا تُمحى.
    $post('stage4_inference_q2', 'start')->assertOk();

    foreach ([['conclusion' => 'opt_b', 'sources' => ['src_photo']], ['conclusion' => 'opt_a', 'sources' => ['src_photo']], ['conclusion' => 'opt_a', 'sources' => ['src_card']], ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_card']]] as $wrong) {
        expect($submit('stage4_inference_q2', $wrong)->json('result.correct'))->toBeFalse(json_encode($wrong));
    }

    $afterErrors = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $st = collect($afterErrors->json('state.scenes'))->pluck('status', 'key');
    expect($st['stage4_inference_q1'])->toBe('completed')->and($st['stage4_inference_q2'])->not->toBe('completed')->and($st['stage4_inference_q3'])->toBe('locked')
        ->and(collect($afterErrors->json('evidence'))->pluck('key')->all())->toContain('stage4_inference_q1')->and(collect($afterErrors->json('evidence'))->pluck('key')->all())->not->toContain('stage4_inference_q2');
    $post('stage4_inference_q3', 'start')->assertStatus(403);

    // «خروج»: بدء المشهد الأول من جديد (إعادة) ثم الخروج دون إجابة لا يمحو اكتماله ولا دليله.
    $post('stage4_inference_q1', 'start')->assertOk();
    $afterLeave = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect(collect($afterLeave->json('state.scenes'))->pluck('status', 'key')['stage4_inference_q1'])->toBe('completed')->and(collect($afterLeave->json('evidence'))->pluck('key')->all())->toContain('stage4_inference_q1');

    $q2 = $submit('stage4_inference_q2', ['conclusion' => 'opt_a', 'sources' => ['src_card', 'src_photo']]);
    expect($q2->json('result.correct'))->toBeTrue()->and($evidence($q2))->toBe(['stage4_inference_q1', 'stage4_inference_q2']);

    // 8) الاستنتاج 3.
    $post('stage4_inference_q3', 'start')->assertOk();
    expect($submit('stage4_inference_q3', ['conclusion' => 'opt_a', 'sources' => ['src_paper', 'src_clock']])->json('result.correct'))->toBeFalse();
    expect($submit('stage4_inference_q3', ['conclusion' => 'opt_b', 'sources' => ['src_photo', 'src_clock']])->json('result.correct'))->toBeFalse();
    $q3 = $submit('stage4_inference_q3', ['conclusion' => 'opt_b', 'sources' => ['src_paper', 'src_clock']]);
    expect($q3->json('result.correct'))->toBeTrue()->and($evidence($q3))->toBe(['stage4_inference_q1', 'stage4_inference_q2', 'stage4_inference_q3'])
        ->and(collect($q3->json('evidence'))->whereIn('key', ['stage4_inference_q1', 'stage4_inference_q2', 'stage4_inference_q3'])->pluck('clue')->filter()->all())->toBe([]);

    // 9) النتيجة (سرد): فهم لا فتح مادي، ثم العبارة والساعة الحجرية.
    $watch('stage4_inference_result');
    $watch('stage4_question_video');
    $watch('stage4_stone_video');
    $post('stage4_stone_inspect', 'start')->assertOk();
    expect($submit('stage4_stone_inspect', ['inspected' => ['rod']])->json('result.correct'))->toBeFalse();
    expect($submit('stage4_stone_inspect', ['inspected' => ['rod', 'shadow']])->json('result.correct'))->toBeTrue();
    $watch('stage4_lamp_video');

    // 10) النهاية: بطاقة الانتقال إلى الخامسة ثم اكتمال السلسلة؛ لا لغز من الخامسة ولا مشهد بعدها.
    $end = $post('stage4_end', 'start')->assertOk();
    expect($end->json('scene.transition.title'))->toBe('المرحلة الخامسة')->and($end->json('scene.transition.next_stage_title'))->toBe('ساعة الظل');
    $fin = $post('stage4_end', 'finish', ['mode' => 'read'])->assertOk();
    expect($fin->json('state.progress.status'))->toBe('completed')->and($fin->json('state.progress.percent'))->toBe(100)
        ->and(collect($fin->json('state.scenes'))->pluck('key')->filter(fn ($k) => str_starts_with($k, 'stage5_'))->all())->toBe([]);

    // لا اقتصاد، ولا حل أو مصدر صحيح في أي استجابة قبل أوانه، ولا تغيير في قفل مادي.
    expect(cin_economy_snapshot())->toBe($economy);
    $joined = implode("\n", $bodies);
    expect($joined)->not->toContain('"accepted"')->and($joined)->not->toContain('"codes"')->and($joined)->not->toContain('lock_hints')->and($joined)->not->toContain('"solution"')->and($joined)->not->toContain('requires_scenes')
        ->and($joined)->not->toContain('"conclusion"')->and($joined)->not->toContain('"map"')->and($joined)->not->toContain('"correct":"')->and($joined)->not->toContain('"correct":[');

    // اكتملت المراحل 1–4 كلها: 37 + 15 + 15.
    $p = CinematicProgress::query()->where('user_id', $user->id)->firstOrFail();
    expect(CinematicSceneState::query()->where('progress_id', $p->id)->whereNotNull('completed_at')->count())->toBe(67);
});

test('PLM4-05: nothing is shown early - the evidence log holds only finished inference scenes, the room and question payloads carry no source answer, and resuming restores the same log', function () {
    $before = array_merge(plm34_stage1_scenes(), plm34_stage2_scenes(), plm34_scenes(3));
    $ctx = plm34_ready(4, $before);
    ['slug' => $slug, 'user' => $user, 'byKey' => $byKey, 'progress' => $progress, 'story' => $story] = $ctx;
    $defs = collect(plm34_scenes(4))->keyBy('stable_key');

    foreach (['stage4_doors_video', 'stage4_doors_inspect', 'stage4_door_match', 'stage4_room_video', 'stage4_photo_inspect', 'stage4_evidence_room'] as $key) {
        plm34_play_scene($progress, $byKey[$key], $defs[$key]);
    }

    // أمام الأسئلة ولم يُجب شيء: لا دليل استنتاج في السجل، ولا إجابة في حمولة المشهد.
    $res = test()->actingAs($user)->postJson("/cinematic/{$slug}/scenes/{$byKey['stage4_inference_q1']->public_id}/start")->assertOk();
    $payload = $res->getContent();
    expect(collect($res->json('evidence'))->pluck('key')->filter(fn ($k) => str_starts_with($k, 'stage4_inference'))->all())->toBe([])
        ->and($payload)->not->toContain('"conclusion"')->and($payload)->not->toContain('اقرأ ما يقوله المصدر حرفيًا')->and($payload)->not->toContain('الجواب: ب مع الرسالة')->and($payload)->not->toContain('وعد الرسالة')
        ->and($res->json('scene.interaction.sources'))->toHaveCount(5)->and($res->json('scene.interaction.options'))->toHaveCount(2);

    // حفظ ثم استئناف: السجل نفسه بالضبط، والمشهد الحالي هو الثاني.
    plm34_play_scene($progress, $byKey['stage4_inference_q1'], $defs['stage4_inference_q1']);
    $a = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    $resumed = cin_play()->begin($user, $story);
    $b = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect($resumed->id)->toBe($progress->id)->and(collect($b->json('evidence'))->pluck('key')->all())->toBe(collect($a->json('evidence'))->pluck('key')->all())
        ->and(collect($b->json('evidence'))->pluck('key')->all())->toContain('stage4_inference_q1')->and(collect($b->json('evidence'))->pluck('key')->all())->not->toContain('stage4_inference_q2')
        ->and($b->json('state.current'))->toBe($byKey['stage4_inference_q2']->public_id);

    // إعادة لعب اللوحة بإجابة خاطئة لا تُسقط اكتمالها (لا محو بخطأ لاحق).
    cin_play()->start($progress, $byKey['stage4_door_match']);
    cin_play()->submit($progress, $byKey['stage4_door_match'], ['placements' => ['plate_b' => 'door_right']]);
    $c = test()->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect(collect($c->json('state.scenes'))->pluck('status', 'key')['stage4_door_match'])->toBe('completed')->and($c->json('state.current'))->toBe($byKey['stage4_inference_q2']->public_id);
});

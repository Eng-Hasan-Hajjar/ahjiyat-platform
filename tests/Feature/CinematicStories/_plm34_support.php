<?php

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicStage;
use App\Services\Cinematic\CinematicStructure;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use App\Services\Cinematic\Demo\Stage2ContentInstaller;
use App\Services\Cinematic\Demo\StageAddonInstaller;

require_once __DIR__.'/_support.php';

/*
| أدوات مشتركة لاختبارات PLM-3/4 (المرحلتان الثالثة والرابعة). تُحمَّل بـ require_once وكل دالة محروسة بـ function_exists.
| الربط الافتراضي في الاختبارات:
|   seam_pending_stage2_end_approval => stage2_end         (اعتماد نهاية الثانية كنقطة دخول للثالثة: يُحاكى هنا بمشهد حقيقي)
|   seam_pending_stage2_photo_card   => stage2_photo_inspect (بطاقة الملف ب 214: تُحاكى بمشهد فحص الصورة الحقيقي في الثانية)
*/

const PLM34_LINK_END = 'seam_pending_stage2_end_approval';
const PLM34_LINK_CARD = 'seam_pending_stage2_photo_card';

if (! function_exists('plm34_scenes')) {
    /** مشاهد قالب مرحلة (3 أو 4) مسطَّحة بترتيبها. @return list<array<string, mixed>> */
    function plm34_scenes(int $stage): array
    {
        $out = [];

        foreach (StageAddonInstaller::definition($stage)['chapters'] as $chapter) {
            foreach ($chapter['scenes'] as $scene) {
                $out[] = $scene;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    function plm34_scenes_of(array $definition): array
    {
        $out = [];

        foreach ($definition['chapters'] as $chapter) {
            foreach ($chapter['scenes'] as $scene) {
                $out[] = $scene;
            }
        }

        return $out;
    }

    function plm34_stage1_scenes(): array
    {
        return plm34_scenes_of(Stage1DemoInstaller::definition());
    }

    function plm34_stage2_scenes(): array
    {
        return plm34_scenes_of(Stage2ContentInstaller::definition());
    }

    /** إجابة مرجعية من الحل المخزَّن في القالب (كل الأنواع المستعملة في المراحل 1–4). @param array<string, mixed> $scene @return array<string, mixed> */
    function plm34_answer(array $scene): array
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
            'reveal_code' => ['code' => $s['code']],
            'flashlight' => ['points' => array_map(fn ($t) => ['x' => $t['x'] + $t['w'] / 2, 'y' => $t['y'] + $t['h'] / 2], array_slice($s['targets'], 0, (int) ($s['required'] ?? count($s['targets']))))],
            'answer' => ['answer' => (string) $s['accepted'][0]],
            'inspect' => ['inspected' => $s['required']],
            'board_move' => (function () use ($public, $s) {
                $t = collect($public['targets'])->firstWhere('id', $s['correct']);

                return ['row' => $t['row'], 'col' => $t['col']];
            })(),
            'inference' => ['conclusion' => $s['conclusion'], 'sources' => $s['sources']],
            'multi_lock' => ['values' => collect($public['locks'])->mapWithKeys(function ($lock) use ($s) {
                $rest = $s['codes'][$lock['id']];
                $parts = [];

                foreach ($lock['segments'] as $seg) {
                    $take = $seg['type'] === 'digits' ? $seg['length'] : 1;
                    $parts[] = mb_substr($rest, 0, $take);
                    $rest = mb_substr($rest, $take);
                }

                return [$lock['id'] => $parts];
            })->all()],
        };
    }

    /** يلعب مشهدًا بالخدمة مباشرة (إتمام صحيح بالإجابة المرجعية أو بالقراءة/المشاهدة). */
    function plm34_play_scene(App\Models\Cinematic\CinematicProgress $progress, CinematicScene $scene, array $def): void
    {
        cin_play()->start($progress, $scene);

        if (in_array($def['type'], ['video', 'transition', 'narrative'], true)) {
            cin_play()->finish($progress, $scene, 'read');

            return;
        }

        $r = cin_play()->submit($progress, $scene, plm34_answer($def));

        if (($r['result']['correct'] ?? null) !== true) {
            throw new RuntimeException('plm34_play_scene: '.$def['stable_key'].' لم يُقبل بالإجابة المرجعية: '.json_encode($r['result'] ?? $r, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * يثبّت المراحل حتى $upto (1..4) في قصة واحدة. الربط المعلّق (نهاية الثانية / بطاقة الملف) يُحاكى بمشهدين حقيقيين من الثانية ما لم يُمرَّر $links.
     *
     * @param  array<string, string>|null  $links
     * @return array<string, mixed>
     */
    function plm34_install(int $upto = 4, ?array $links = null): array
    {
        $links ??= [PLM34_LINK_END => 'stage2_end', PLM34_LINK_CARD => 'stage2_photo_inspect'];
        $one = app(Stage1DemoInstaller::class)->install();
        $two = app(Stage2ContentInstaller::class)->install();
        $three = $upto >= 3 ? app(StageAddonInstaller::class)->install(3, null, false, false, array_intersect_key($links, [PLM34_LINK_END => 1])) : null;
        $four = $upto >= 4 ? app(StageAddonInstaller::class)->install(4, null, false, false, array_intersect_key($links, [PLM34_LINK_CARD => 1])) : null;

        return ['one' => $one, 'two' => $two, 'three' => $three, 'four' => $four, 'story' => $one['story'], 'revision' => ($four ?? $three ?? $two)['revision']->fresh()];
    }

    /** يجعل المسودة قابلة للنشر داخل الاختبار فقط: يستبدل النص المؤقت [[…]] في الثانية ويربط وسائط تجريبية. */
    function plm34_make_publishable(CinematicRevision $revision): void
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

    /** بصمة مرحلة بعينها: مراحلها وفصولها ومشاهدها وحلولها كما هي (لإثبات أن تثبيت لاحق لا يلمسها). */
    function plm34_snapshot(CinematicRevision $revision, string $stageKey): string
    {
        $stage = CinematicStage::query()->where('revision_id', $revision->id)->where('stable_key', $stageKey)->firstOrFail();
        $scenes = CinematicScene::query()->where('stage_id', $stage->id)->orderBy('id')->get();
        $chapters = CinematicChapter::query()->where('stage_id', $stage->id)->orderBy('id')->get();
        $solutions = CinematicSceneSolution::query()->whereIn('scene_id', $scenes->pluck('id'))->orderBy('scene_id')->get();

        return md5($stage->toJson().$chapters->toJson().$scenes->toJson().$solutions->toJson());
    }

    /** @return list<int> أعداد الصفوف: مراحل، فصول، مشاهد، حلول. */
    function plm34_counts(): array
    {
        return [CinematicStage::query()->count(), CinematicChapter::query()->count(), CinematicScene::query()->count(), CinematicSceneSolution::query()->count()];
    }

    /**
     * ينشر النسخة بعد المحاكاة، ويلعب المراحل السابقة لـ$from بالخدمة، ويعيد سياق اللعب عبر HTTP.
     *
     * @param  list<array<string, mixed>>  $before  مشاهد (تعريفات) تُلعب مسبقًا بالخدمة بترتيبها
     * @return array<string, mixed>
     */
    function plm34_ready(int $upto, array $before): array
    {
        $ctx = plm34_install($upto);
        plm34_make_publishable($ctx['revision']);
        $live = cin_publish($ctx['revision']->fresh());
        $user = cin_user();
        $byKey = CinematicStructure::scenes($live)->keyBy('stable_key');
        $progress = cin_play()->begin($user, $ctx['story']);

        foreach ($before as $def) {
            plm34_play_scene($progress, $byKey[$def['stable_key']], $def);
        }

        return $ctx + ['live' => $live, 'user' => $user, 'byKey' => $byKey, 'progress' => $progress, 'slug' => $ctx['story']->slug];
    }
}

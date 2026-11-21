<?php

namespace App\Services\Cinematic\Demo;

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicAuthoringService;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\Interactions\InteractionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * PLM-3/4 - مثبّت عام لإضافات المراحل (cinematic-stage-addon/1) على مسودة القصة الموجودة: المرحلة الثالثة «الساعة المتوقفة» والرابعة «الأبواب الثلاثة».
 * مستقل عن Stage2ContentInstaller (الذي لا يُمسّ) ويتبع العقد نفسه والحدود نفسها:
 *
 *  - المعاينة (plan) هي الافتراضي: لا تكتب شيئًا، وتُبلغ بما سيُضاف وبأي تعارض، وبمفاتيح «الربط المعلّق» غير المحلولة.
 *  - التطبيق (install) يضيف مرحلة جديدة **فقط** إلى مسودة مفتوحة، في معاملة واحدة: لا يعدّل ولا يحذف أي مرحلة/فصل/مشهد موجود، ولا يربط وسائط، ولا ينشر،
 *    ولا يمس الاقتصاد (جواهر/خبرة) ولا تقدّم اللاعبين.
 *  - بلا تكرار: إن كانت المرحلة موجودة بمشاهدها نفسها تمامًا يعود already_applied بلا كتابة؛ وإن وُجدت جزئية/مختلفة أو تعارضت مفاتيح مع مشاهد أخرى يتوقف قبل أي كتابة.
 *  - الترتيب: يشترط القالب مرحلة سابقة (requires_existing.stage_key) ومشهدًا سابقًا اختياريًا (scene_key) فلا تُثبَّت الرابعة قبل الثالثة.
 *  - «الربط المعلّق» (pending_links): مفاتيح شروط فتح غير موجودة عمدًا (مثل نهاية الثانية المنقّحة التي لم تُعتمد بعد). تبقى المشاهد المعتمدة عليها مقفلة وغير قابلة للنشر
 *    (حواجز الجاهزية القائمة) إلى أن يُربط المفتاح بمشهد حقيقي، إما عند التثبيت بـ links (pending=real) أو لاحقًا من محرّر المشهد. لا يُمنح اللاعب دليل لم يكتشفه.
 *  - بيئة production تتطلب تأكيدًا صريحًا (allowProduction).
 */
final class StageAddonInstaller
{
    public const FORMAT = 'cinematic-stage-addon/1';

    /** رقم المرحلة ⇒ ملف القالب */
    private const FILES = [
        3 => 'library-mystery-stage-3.json',
        4 => 'library-mystery-stage-4.json',
    ];

    public function __construct(
        protected CinematicAuthoringService $authoring,
        protected CinematicReadinessChecker $readiness,
        protected InteractionEngine $engine,
    ) {}

    /** @return list<int> */
    public static function stages(): array
    {
        return array_keys(self::FILES);
    }

    public static function definitionPath(int $stage): string
    {
        $file = self::FILES[$stage] ?? throw CinematicException::make('addon_stage_unknown', "لا قالب للمرحلة {$stage}. المتاح: ".implode(', ', array_keys(self::FILES)), 422);

        return database_path('demo'.DIRECTORY_SEPARATOR.'cinematic'.DIRECTORY_SEPARATOR.$file);
    }

    /** @return array<string, mixed> */
    public static function definition(int $stage, ?string $path = null): array
    {
        $path ??= self::definitionPath($stage);

        if (! File::isFile($path)) {
            throw CinematicException::make('addon_definition_missing', "ملف قالب المرحلة {$stage} غير موجود.", 500);
        }

        $definition = File::json($path);

        if (! is_array($definition) || ($definition['format'] ?? null) !== self::FORMAT || ! is_array($definition['chapters'] ?? null) || ! is_array($definition['stage'] ?? null)) {
            throw CinematicException::make('addon_definition_invalid', "ملف قالب المرحلة {$stage} غير صالح.", 500);
        }

        return $definition;
    }

    /**
     * معاينة بلا كتابة.
     *
     * @param  array<string, string>  $links  pending_key => real_scene_key
     * @return array<string, mixed>
     */
    public function plan(int $stage, ?string $slug = null, array $links = [], ?array $definition = null): array
    {
        $definition ??= self::definition($stage);
        $problems = $this->validateDefinition($definition, $links);
        $story = $this->story($definition, $slug);
        $report = [
            'mode' => 'plan', 'stage' => $stage, 'slug' => $story?->slug, 'problems' => $problems, 'status' => 'ready', 'counts' => $this->counts($definition),
            'target_revision' => null, 'will_create_draft' => false, 'notes' => [], 'pending' => $this->pendingReport($definition, $links),
        ];

        if ($story === null) {
            $report['problems'][] = 'القصة المستهدفة غير موجودة. أنشئ القصة أولًا (أو ثبّت المرحلة الأولى).';
            $report['status'] = 'blocked';

            return $report;
        }

        $revision = $this->editableRevision($story);
        $report['will_create_draft'] = $revision === null;
        $inspect = $revision ?? CinematicRevision::query()->where('story_id', $story->id)->where('status', CinematicRevision::STATUS_PUBLISHED)->first()
            ?? CinematicRevision::query()->where('story_id', $story->id)->orderByDesc('revision_number')->first();
        $report['target_revision'] = $revision?->public_id;

        if ($inspect === null) {
            $report['problems'][] = 'لا توجد نسخة للقصة.';
            $report['status'] = 'blocked';

            return $report;
        }

        $state = $this->state($inspect, $definition, $links);
        $report['status'] = $state['status'];
        $report['problems'] = array_merge($report['problems'], $state['problems']);

        if ($report['problems'] !== [] && $report['status'] === 'ready') {
            $report['status'] = 'blocked';
        }

        if ($revision === null && $report['status'] === 'ready') {
            $report['notes'][] = 'لا توجد مسودة مفتوحة: عند التطبيق مع createDraft ستُنشأ مسودة جديدة من النسخة الحالية ثم تُضاف إليها المرحلة.';
        }

        if ($state['status'] === 'already_applied' && $links !== []) {
            $report['notes'][] = 'المرحلة مضافة مسبقًا: لا يُعاد الربط من هنا (لا تعديل لمشاهد موجودة). غيّر شرط فتح المشهد المعني من محرّر المشهد.';
        }

        return $report;
    }

    /**
     * @param  array<string, string>  $links  pending_key => real_scene_key
     * @return array{status: string, created: bool, story: CinematicStory, revision: CinematicRevision, counts: array<string, int>, readiness: array<string, mixed>, pending: list<array<string, mixed>>}
     */
    public function install(int $stage, ?string $slug = null, bool $createDraft = false, bool $allowProduction = false, array $links = [], ?array $definition = null): array
    {
        if (app()->environment('production') && ! $allowProduction) {
            throw CinematicException::make('addon_production_confirm', 'بيئة الإنتاج تتطلب تأكيدًا صريحًا (--yes-production).', 403);
        }

        $definition ??= self::definition($stage);
        $problems = $this->validateDefinition($definition, $links);

        if ($problems !== []) {
            throw CinematicException::make('addon_definition_invalid', "قالب المرحلة {$stage} غير صالح: ".implode(' | ', $problems), 422);
        }

        $story = $this->story($definition, $slug) ?? throw CinematicException::make('addon_story_missing', 'القصة المستهدفة غير موجودة.', 404);

        return DB::transaction(function () use ($definition, $story, $createDraft, $links) {
            CinematicStory::query()->whereKey($story->id)->lockForUpdate()->first();

            $revision = $this->editableRevision($story);

            if ($revision === null) {
                if (! $createDraft) {
                    throw CinematicException::make('addon_no_draft', 'لا توجد مسودة مفتوحة للقصة. أنشئ مسودة جديدة من لوحة الإدارة أو أعد التشغيل مع --create-draft.', 409);
                }

                $revision = $this->authoring->createDraft($story, null);
            }

            $state = $this->state($revision, $definition, $links);
            $pending = $this->pendingReport($definition, $links);

            if ($state['status'] === 'already_applied') {
                return ['status' => 'already_applied', 'created' => false, 'story' => $story, 'revision' => $revision, 'counts' => $this->counts($definition), 'readiness' => $this->readiness->check($revision), 'pending' => $pending];
            }

            if ($state['status'] !== 'ready') {
                throw CinematicException::make('addon_blocked', 'تعذّر التطبيق دون كتابة: '.implode(' | ', $state['problems']), 409);
            }

            $stage = $this->authoring->addStage($revision, (array) $definition['stage']);

            foreach ($definition['chapters'] as $chapterDef) {
                $chapter = $this->authoring->addChapter($stage, ['stable_key' => $chapterDef['stable_key'], 'title' => $chapterDef['title'], 'summary' => $chapterDef['summary'] ?? null]);

                foreach ($chapterDef['scenes'] as $sceneDef) {
                    $scene = $this->authoring->addScene($chapter, [
                        'stable_key' => $sceneDef['stable_key'], 'type' => $sceneDef['type'], 'title' => $sceneDef['title'],
                        'duration_hint_seconds' => $sceneDef['duration_hint_seconds'] ?? null, 'awaiting_media' => (bool) ($sceneDef['awaiting_media'] ?? false),
                        'unlock_rule' => $this->linked((array) ($sceneDef['unlock_rule'] ?? []), 'requires', $links), 'config' => $sceneDef['config'] ?? [],
                    ]);

                    if (is_array($sceneDef['solution'] ?? null)) {
                        $errors = $this->authoring->setInteractionSolution($scene, $this->linked((array) $sceneDef['solution'], 'requires_scenes', $links));

                        if ($errors !== []) {
                            throw CinematicException::make('addon_solution_invalid', "حل المشهد {$sceneDef['stable_key']} غير صالح: ".implode(' | ', $errors), 422);
                        }
                    }
                }
            }

            $revision = $revision->fresh();

            return ['status' => 'applied', 'created' => true, 'story' => $story, 'revision' => $revision, 'counts' => $this->counts($definition), 'readiness' => $this->readiness->check($revision), 'pending' => $pending];
        });
    }

    /** يستبدل مفاتيح الربط المعلّق المحلولة داخل قائمة شروط (unlock_rule.requires أو solution.requires_scenes) بالمفاتيح الحقيقية. */
    private function linked(array $bag, string $field, array $links): array
    {
        if ($links === [] || ! is_array($bag[$field] ?? null)) {
            return $bag;
        }

        $bag[$field] = array_values(array_unique(array_map(fn ($k) => is_string($k) && isset($links[$k]) ? $links[$k] : $k, $bag[$field])));

        return $bag;
    }

    /** @return array<string, int> */
    protected function counts(array $definition): array
    {
        $scenes = 0;

        foreach ($definition['chapters'] as $c) {
            $scenes += count($c['scenes'] ?? []);
        }

        return ['stages' => 1, 'chapters' => count($definition['chapters']), 'scenes' => $scenes];
    }

    /**
     * @param  array<string, string>  $links
     * @return list<array<string, mixed>>
     */
    protected function pendingReport(array $definition, array $links): array
    {
        $out = [];

        foreach ((array) ($definition['pending_links'] ?? []) as $p) {
            $key = (string) ($p['key'] ?? '');
            $out[] = ['key' => $key, 'meaning' => (string) ($p['meaning'] ?? ''), 'gates' => array_values((array) ($p['gates'] ?? [])), 'linked_to' => $links[$key] ?? null, 'resolved' => isset($links[$key])];
        }

        return $out;
    }

    protected function story(array $definition, ?string $slug): ?CinematicStory
    {
        $slug ??= (string) ($definition['target_story_slug'] ?? '');

        return $slug === '' ? null : CinematicStory::query()->where('slug', $slug)->first();
    }

    protected function editableRevision(CinematicStory $story): ?CinematicRevision
    {
        return CinematicRevision::query()->where('story_id', $story->id)->whereIn('status', CinematicRevision::EDITABLE)->orderByDesc('id')->first();
    }

    /**
     * حالة النسخة بالنسبة للقالب: ready | already_applied | blocked (مع أسباب). لا كتابة.
     *
     * @param  array<string, string>  $links
     * @return array{status: string, problems: list<string>}
     */
    protected function state(CinematicRevision $revision, array $definition, array $links = []): array
    {
        $req = (array) ($definition['requires_existing'] ?? []);
        $problems = [];
        $stageKey = (string) $definition['stage']['stable_key'];
        $existing = CinematicStage::query()->where('revision_id', $revision->id)->where('stable_key', $stageKey)->first();

        $sceneKeys = [];
        $chapterKeys = [];

        foreach ($definition['chapters'] as $c) {
            $chapterKeys[] = (string) $c['stable_key'];

            foreach ($c['scenes'] as $s) {
                $sceneKeys[] = (string) $s['stable_key'];
            }
        }

        if ($existing !== null) {
            $have = CinematicScene::query()->where('revision_id', $revision->id)->where('stage_id', $existing->id)->pluck('stable_key')->all();

            if (array_diff($sceneKeys, $have) === [] && array_diff($have, $sceneKeys) === []) {
                return ['status' => 'already_applied', 'problems' => []];
            }

            return ['status' => 'blocked', 'problems' => ["المرحلة {$stageKey} موجودة لكن مشاهدها لا تطابق القالب (جزئية أو مُعدَّلة). لن يُغيَّر شيء؛ عالجها يدويًا من لوحة الإدارة."]];
        }

        if (isset($req['stage_key']) && ! CinematicStage::query()->where('revision_id', $revision->id)->where('stable_key', $req['stage_key'])->exists()) {
            $problems[] = "المرحلة المطلوبة {$req['stage_key']} غير موجودة في النسخة (الترتيب: لا تُثبَّت هذه المرحلة قبلها).";
        }

        if (isset($req['scene_key']) && ! CinematicScene::query()->where('revision_id', $revision->id)->where('stable_key', $req['scene_key'])->exists()) {
            $problems[] = "المشهد المطلوب {$req['scene_key']} (نهاية المرحلة السابقة) غير موجود في النسخة.";
        }

        $clash = CinematicScene::query()->where('revision_id', $revision->id)->whereIn('stable_key', $sceneKeys)->pluck('stable_key')->all();

        if ($clash !== []) {
            $problems[] = 'مفاتيح مشاهد موجودة مسبقًا خارج المرحلة '.$stageKey.': '.implode(', ', $clash);
        }

        $clashChapters = CinematicChapter::query()->where('revision_id', $revision->id)->whereIn('stable_key', $chapterKeys)->pluck('stable_key')->all();

        if ($clashChapters !== []) {
            $problems[] = 'مفاتيح فصول موجودة مسبقًا: '.implode(', ', $clashChapters);
        }

        foreach ($links as $pendingKey => $real) {
            if (! CinematicScene::query()->where('revision_id', $revision->id)->where('stable_key', $real)->exists()) {
                $problems[] = "الربط {$pendingKey} ⇒ {$real}: المشهد {$real} غير موجود في النسخة، فلا يُربط بمفتاح وهمي.";
            }
        }

        return ['status' => $problems === [] ? 'ready' : 'blocked', 'problems' => $problems];
    }

    /**
     * فحص بنية القالب وحلوله قبل أي كتابة (المحرّك نفسه الذي يستعمله الحفظ الفعلي).
     *
     * @param  array<string, string>  $links
     * @return list<string>
     */
    public function validateDefinition(array $definition, array $links = []): array
    {
        $problems = [];
        $seen = [];
        $all = [];
        $pendingKeys = array_map(fn ($p) => (string) ($p['key'] ?? ''), (array) ($definition['pending_links'] ?? []));

        foreach ($definition['chapters'] as $c) {
            foreach ($c['scenes'] as $s) {
                $all[] = (string) $s['stable_key'];
            }
        }

        foreach ($links as $pendingKey => $real) {
            if (! in_array($pendingKey, $pendingKeys, true)) {
                $problems[] = "الربط {$pendingKey}: ليس مفتاح ربط معلّق معرَّفًا في القالب (".implode(', ', $pendingKeys).').';
            }

            if (preg_match('/\A[a-z0-9_]{2,64}\z/', (string) $real) !== 1 || in_array($real, $all, true)) {
                $problems[] = "الربط {$pendingKey} ⇒ {$real}: يجب أن يشير إلى مشهد موجود في مرحلة سابقة بمفتاح صالح (لا إلى مشهد من هذه الإضافة).";
            }
        }

        foreach ($definition['chapters'] as $c) {
            foreach ($c['scenes'] as $s) {
                $key = (string) ($s['stable_key'] ?? '');

                if (preg_match('/\A[a-z0-9_]{2,64}\z/', $key) !== 1 || isset($seen[$key])) {
                    $problems[] = "مفتاح مشهد غير صالح أو مكرر: {$key}";
                }

                $seen[$key] = true;

                if (is_array($s['solution'] ?? null)) {
                    $public = (array) ($s['config']['interaction'] ?? []);
                    $errors = $this->engine->validate((string) ($public['kind'] ?? ''), $public, $s['solution']);

                    foreach ($errors as $e) {
                        $problems[] = "{$key}: {$e}";
                    }
                }
            }
        }

        // كل requires يشير إلى مشهد في القالب أو إلى مشهد المرحلة السابقة المصرَّح به أو إلى مفتاح ربط معلّق معرَّف (محلول أو لا).
        $external = array_merge([(string) ($definition['requires_existing']['scene_key'] ?? '')], $pendingKeys);

        foreach ($definition['chapters'] as $c) {
            foreach ($c['scenes'] as $s) {
                $refs = array_merge((array) ($s['unlock_rule']['requires'] ?? []), (array) ($s['solution']['requires_scenes'] ?? []));

                foreach ($refs as $r) {
                    if (! in_array($r, $all, true) && ! in_array($r, $external, true)) {
                        $problems[] = "{$s['stable_key']}: يشير إلى مشهد غير معروف {$r}";
                    }
                }
            }
        }

        return $problems;
    }
}

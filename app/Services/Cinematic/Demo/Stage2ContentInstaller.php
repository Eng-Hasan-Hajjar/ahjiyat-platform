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
 * CIN-2 - إضافة محتوى المرحلة الثانية («الصورة المفقودة») كمرحلة جديدة إلى القصة الموجودة (unwritten-page)، من قالب JSON مستقل.
 *
 * حدود صارمة (مصمَّمة لتُشغَّل على بيانات حقيقية بأمان):
 *  - معاينة (plan) هي الافتراضي: لا تكتب شيئًا في قاعدة البيانات، وتُبلغ بما سيُنشأ وبأي تعارض.
 *  - التطبيق (install) يضيف مرحلة جديدة فقط إلى **مسودة** مفتوحة: لا يعدّل ولا يحذف أي مرحلة/فصل/مشهد موجود (المرحلة الأولى كما هي)،
 *    ولا ينشر، ولا يربط أي وسيط، ولا يمس الاقتصاد (جواهر/خبرة) ولا تقدّم اللاعبين.
 *  - مرة واحدة: إن كانت المرحلة stage_2 موجودة بكامل مشاهدها لا يُعدَّل شيء (already_applied). وإن وُجدت جزئيًا أو تعارضت مفاتيح مع مراحل أخرى يتوقف قبل أي كتابة.
 *  - كل الكتابة داخل معاملة واحدة: أي خطأ (حل غير صالح مثلًا) يُرجع كل شيء.
 *  - لا مسودة مفتوحة ⇒ يرفض مع تعليمات، إلا بخيار createDraft الصريح (ينشئ مسودة من الأحدث/المنشورة بحسب سلوك لوحة الإدارة نفسه).
 *  - في بيئة production يلزم تأكيد صريح (allowProduction).
 */
final class Stage2ContentInstaller
{
    public const FORMAT = 'cinematic-stage-addon/1';

    public function __construct(
        protected CinematicAuthoringService $authoring,
        protected CinematicReadinessChecker $readiness,
        protected InteractionEngine $engine,
    ) {}

    public static function definitionPath(): string
    {
        return database_path('demo'.DIRECTORY_SEPARATOR.'cinematic'.DIRECTORY_SEPARATOR.'library-mystery-stage-2.json');
    }

    /** @return array<string, mixed> */
    public static function definition(?string $path = null): array
    {
        $path ??= self::definitionPath();

        if (! File::isFile($path)) {
            throw CinematicException::make('stage2_definition_missing', 'ملف قالب المرحلة الثانية غير موجود.', 500);
        }

        $definition = File::json($path);

        if (! is_array($definition) || ($definition['format'] ?? null) !== self::FORMAT || ! is_array($definition['chapters'] ?? null) || ! is_array($definition['stage'] ?? null)) {
            throw CinematicException::make('stage2_definition_invalid', 'ملف قالب المرحلة الثانية غير صالح.', 500);
        }

        return $definition;
    }

    /**
     * معاينة بلا كتابة: تتحقق من القالب ومن حالة القصة وتُبلغ بما سيحدث.
     *
     * @return array<string, mixed>
     */
    public function plan(?string $slug = null, ?array $definition = null): array
    {
        $definition ??= self::definition();
        $problems = $this->validateDefinition($definition);
        $story = $this->story($definition, $slug);
        $report = ['mode' => 'plan', 'slug' => $story?->slug, 'problems' => $problems, 'status' => 'ready', 'counts' => $this->counts($definition), 'target_revision' => null, 'will_create_draft' => false];

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

        $state = $this->state($inspect, $definition);
        $report['status'] = $state['status'];
        $report['problems'] = array_merge($report['problems'], $state['problems']);

        if ($report['problems'] !== [] && $report['status'] === 'ready') {
            $report['status'] = 'blocked';
        }

        if ($revision === null && $report['status'] === 'ready') {
            $report['notes'][] = 'لا توجد مسودة مفتوحة: عند التطبيق مع createDraft ستُنشأ مسودة جديدة من النسخة الحالية، ثم تُضاف إليها المرحلة الثانية.';
        }

        return $report;
    }

    /**
     * @return array{status: string, created: bool, story: CinematicStory, revision: CinematicRevision, counts: array<string, int>, readiness: array<string, mixed>}
     */
    public function install(?string $slug = null, bool $createDraft = false, bool $allowProduction = false, ?array $definition = null): array
    {
        if (app()->environment('production') && ! $allowProduction) {
            throw CinematicException::make('stage2_production_confirm', 'بيئة الإنتاج تتطلب تأكيدًا صريحًا (--yes-production).', 403);
        }

        $definition ??= self::definition();
        $problems = $this->validateDefinition($definition);

        if ($problems !== []) {
            throw CinematicException::make('stage2_definition_invalid', 'قالب المرحلة الثانية غير صالح: '.implode(' | ', $problems), 422);
        }

        $story = $this->story($definition, $slug) ?? throw CinematicException::make('stage2_story_missing', 'القصة المستهدفة غير موجودة.', 404);

        return DB::transaction(function () use ($definition, $story, $createDraft) {
            CinematicStory::query()->whereKey($story->id)->lockForUpdate()->first();

            $revision = $this->editableRevision($story);

            if ($revision === null) {
                if (! $createDraft) {
                    throw CinematicException::make('stage2_no_draft', 'لا توجد مسودة مفتوحة للقصة. أنشئ مسودة جديدة من لوحة الإدارة أو أعد التشغيل مع --create-draft.', 409);
                }

                $revision = $this->authoring->createDraft($story, null);
            }

            $state = $this->state($revision, $definition);

            if ($state['status'] === 'already_applied') {
                return ['status' => 'already_applied', 'created' => false, 'story' => $story, 'revision' => $revision, 'counts' => $this->counts($definition), 'readiness' => $this->readiness->check($revision)];
            }

            if ($state['status'] !== 'ready') {
                throw CinematicException::make('stage2_blocked', 'تعذّر التطبيق دون كتابة: '.implode(' | ', $state['problems']), 409);
            }

            $stage = $this->authoring->addStage($revision, (array) $definition['stage']);

            foreach ($definition['chapters'] as $chapterDef) {
                $chapter = $this->authoring->addChapter($stage, ['stable_key' => $chapterDef['stable_key'], 'title' => $chapterDef['title'], 'summary' => $chapterDef['summary'] ?? null]);

                foreach ($chapterDef['scenes'] as $sceneDef) {
                    $scene = $this->authoring->addScene($chapter, [
                        'stable_key' => $sceneDef['stable_key'], 'type' => $sceneDef['type'], 'title' => $sceneDef['title'],
                        'duration_hint_seconds' => $sceneDef['duration_hint_seconds'] ?? null, 'awaiting_media' => (bool) ($sceneDef['awaiting_media'] ?? false),
                        'unlock_rule' => $sceneDef['unlock_rule'] ?? [], 'config' => $sceneDef['config'] ?? [],
                    ]);

                    if (is_array($sceneDef['solution'] ?? null)) {
                        $errors = $this->authoring->setInteractionSolution($scene, $sceneDef['solution']);

                        if ($errors !== []) {
                            throw CinematicException::make('stage2_solution_invalid', "حل المشهد {$sceneDef['stable_key']} غير صالح: ".implode(' | ', $errors), 422);
                        }
                    }
                }
            }

            $revision = $revision->fresh();

            return ['status' => 'applied', 'created' => true, 'story' => $story, 'revision' => $revision, 'counts' => $this->counts($definition), 'readiness' => $this->readiness->check($revision)];
        });
    }

    /** @return array<string, mixed> */
    protected function counts(array $definition): array
    {
        $scenes = 0;

        foreach ($definition['chapters'] as $c) {
            $scenes += count($c['scenes'] ?? []);
        }

        return ['stages' => 1, 'chapters' => count($definition['chapters']), 'scenes' => $scenes];
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
     * @return array{status: string, problems: list<string>}
     */
    protected function state(CinematicRevision $revision, array $definition): array
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
            $problems[] = "المرحلة المطلوبة {$req['stage_key']} غير موجودة في النسخة.";
        }

        if (isset($req['scene_key']) && ! CinematicScene::query()->where('revision_id', $revision->id)->where('stable_key', $req['scene_key'])->exists()) {
            $problems[] = "المشهد المطلوب {$req['scene_key']} (نهاية المرحلة الأولى) غير موجود في النسخة.";
        }

        $clash = CinematicScene::query()->where('revision_id', $revision->id)->whereIn('stable_key', $sceneKeys)->pluck('stable_key')->all();

        if ($clash !== []) {
            $problems[] = 'مفاتيح مشاهد موجودة مسبقًا خارج المرحلة الثانية: '.implode(', ', $clash);
        }

        $clashChapters = CinematicChapter::query()->where('revision_id', $revision->id)->whereIn('stable_key', $chapterKeys)->pluck('stable_key')->all();

        if ($clashChapters !== []) {
            $problems[] = 'مفاتيح فصول موجودة مسبقًا: '.implode(', ', $clashChapters);
        }

        return ['status' => $problems === [] ? 'ready' : 'blocked', 'problems' => $problems];
    }

    /**
     * فحص بنية القالب وحلوله قبل أي كتابة (المحرّك نفسه الذي يستعمله الحفظ الفعلي).
     *
     * @return list<string>
     */
    public function validateDefinition(array $definition): array
    {
        $problems = [];
        $seen = [];
        $all = [];

        foreach ($definition['chapters'] as $c) {
            foreach ($c['scenes'] as $s) {
                $all[] = (string) $s['stable_key'];
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

        // كل requires يشير إلى مشهد في القالب أو إلى نهاية المرحلة الأولى.
        $external = [(string) ($definition['requires_existing']['scene_key'] ?? '')];

        foreach ($definition['chapters'] as $c) {
            foreach ($c['scenes'] as $s) {
                foreach ((array) ($s['unlock_rule']['requires'] ?? []) as $r) {
                    if (! in_array($r, $all, true) && ! in_array($r, $external, true)) {
                        $problems[] = "{$s['stable_key']}: يشير إلى مشهد غير معروف {$r}";
                    }
                }
            }
        }

        return $problems;
    }
}

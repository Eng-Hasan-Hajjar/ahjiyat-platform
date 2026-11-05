<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Cinematic\Interactions\InteractionEngine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CIN-1 - التأليف: القصة والنسخ والمراحل والفصول والمشاهد والحلول. كل الكتابة هنا ($guarded=['*'] على الموديلات).
 *
 *  - المحتوى يُعدَّل **فقط** في نسخة مسودة (أو قيد المراجعة فتعود مسودة). المنشورة/المستبدلة/المسحوبة مقفلة منطقيًا.
 *    الاستثناء الوحيد المقصود: replaceMedia (تبديل ملف فيديو/ملصق/ترجمة/صوت دون المساس بمنطق المشهد، فلا يتأثر تقدّم أحد).
 *  - الحل خادمي: يُحفظ مشفّرًا في cinematic_scene_solutions ولا يدخل إعداد المشهد العام أبدًا.
 *  - لا لمس لأي جدول اقتصادي أو creator_series_*.
 */
class CinematicAuthoringService
{
    /** @var list<string> */
    public const RESERVED_SLUGS = ['media', 'cover', 'preview', 'play', 'state', 'upgrade', 'scenes', 'admin', 'api'];

    public function __construct(
        protected InteractionEngine $engine,
        protected SceneConfigNormalizer $normalizer,
        protected CinematicPuzzleBridge $puzzles,
    ) {}

    // ───────────────────────── القصة والنسخ ─────────────────────────

    /** @param array<string, mixed> $data */
    public function createStory(array $data, ?User $by = null): CinematicStory
    {
        $title = $this->title($data['title'] ?? '', 'عنوان القصة');
        $access = (string) ($data['access_mode'] ?? CinematicStory::ACCESS_FREE);
        $kind = (string) ($data['label_kind'] ?? CinematicStory::KIND_LONG);

        if (! in_array($access, [CinematicStory::ACCESS_FREE, CinematicStory::ACCESS_PREMIUM], true) || ! in_array($kind, [CinematicStory::KIND_SHORT, CinematicStory::KIND_LONG], true)) {
            throw CinematicException::make('story_invalid', 'قيمة نوع القصة أو نمط الوصول غير صالحة.');
        }

        $seriesId = $this->seriesId($access, $data['creator_series_id'] ?? null);

        return DB::transaction(function () use ($data, $title, $access, $kind, $seriesId, $by) {
            $story = new CinematicStory;
            $story->forceFill([
                'slug' => $this->uniqueSlug((string) ($data['slug'] ?? ''), $title),
                'title' => $title,
                'summary' => $this->nullableText($data['summary'] ?? null, 1000),
                'label_kind' => $kind,
                'access_mode' => $access,
                'creator_series_id' => $seriesId,
                'created_by' => $by?->id,
            ])->save();

            $this->newRevision($story, $by, null);

            return $story;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateStory(CinematicStory $story, array $data): CinematicStory
    {
        $access = (string) ($data['access_mode'] ?? $story->access_mode);
        $kind = (string) ($data['label_kind'] ?? $story->label_kind);

        if (! in_array($access, [CinematicStory::ACCESS_FREE, CinematicStory::ACCESS_PREMIUM], true) || ! in_array($kind, [CinematicStory::KIND_SHORT, CinematicStory::KIND_LONG], true)) {
            throw CinematicException::make('story_invalid', 'قيمة نوع القصة أو نمط الوصول غير صالحة.');
        }

        $story->forceFill([
            'title' => array_key_exists('title', $data) ? $this->title($data['title'], 'عنوان القصة') : $story->title,
            'summary' => array_key_exists('summary', $data) ? $this->nullableText($data['summary'], 1000) : $story->summary,
            'label_kind' => $kind,
            'access_mode' => $access,
            'creator_series_id' => $this->seriesId($access, array_key_exists('creator_series_id', $data) ? $data['creator_series_id'] : $story->creator_series_id),
        ])->save();

        return $story;
    }

    /** غلاف القصة التسويقي: ملصق جاهز فقط (أو إزالته). لا يمس أي نسخة ولا تقدّم. */
    public function setCover(CinematicStory $story, ?CinematicMediaAsset $asset): CinematicStory
    {
        if ($asset !== null && ($asset->role !== CinematicMediaAsset::ROLE_POSTER || ! $asset->isReady())) {
            throw CinematicException::make('media_not_ready', 'الغلاف يجب أن يكون ملصقًا جاهزًا.');
        }

        $story->forceFill(['cover_asset_id' => $asset?->id])->save();

        return $story;
    }

    public function setArchived(CinematicStory $story, bool $archived): CinematicStory
    {
        $story->forceFill(['archived_at' => $archived ? now() : null])->save();

        return $story;
    }

    /** ينشئ مسودة جديدة بنسخ بنية المصدر (الافتراضي: المنشورة ثم الأحدث). لا يوجد أكثر من مسودة واحدة مفتوحة للقصة. */
    public function createDraft(CinematicStory $story, ?User $by = null, ?CinematicRevision $source = null): CinematicRevision
    {
        return DB::transaction(function () use ($story, $by, $source) {
            CinematicStory::query()->whereKey($story->id)->lockForUpdate()->first();

            $open = CinematicRevision::query()->where('story_id', $story->id)->whereIn('status', CinematicRevision::EDITABLE)->first();

            if ($open) {
                throw CinematicException::make('draft_exists', 'توجد مسودة مفتوحة لهذه القصة بالفعل؛ أكمل العمل عليها أو احذفها.', 409);
            }

            $source ??= CinematicRevision::query()->where('story_id', $story->id)->where('status', CinematicRevision::STATUS_PUBLISHED)->first()
                ?? CinematicRevision::query()->where('story_id', $story->id)->orderByDesc('revision_number')->first();

            return $this->newRevision($story, $by, $source);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateRevision(CinematicRevision $revision, array $data): CinematicRevision
    {
        $this->assertEditable($revision);

        $revision->forceFill([
            'title' => array_key_exists('title', $data) ? $this->title($data['title'], 'عنوان النسخة') : $revision->title,
            'summary' => array_key_exists('summary', $data) ? $this->nullableText($data['summary'], 1000) : $revision->summary,
            'notes' => array_key_exists('notes', $data) ? $this->nullableText($data['notes'], 2000) : $revision->notes,
        ])->save();

        $this->reopen($revision);

        return $revision;
    }

    /** حذف مسودة لم يبدأها أحد (المسودات لا تحمل تقدّم لاعبين بطبيعتها). */
    public function deleteDraft(CinematicRevision $revision): void
    {
        if ($revision->status !== CinematicRevision::STATUS_DRAFT) {
            throw CinematicException::make('revision_locked', 'يمكن حذف المسودات فقط.', 409);
        }

        if (CinematicProgress::query()->where('revision_id', $revision->id)->exists()) {
            throw CinematicException::make('revision_in_use', 'عليها تقدّم لاعبين ولا يمكن حذفها.', 409);
        }

        $revision->delete();
    }

    // ───────────────────────── المراحل ─────────────────────────

    /** @param array<string, mixed> $data */
    public function addStage(CinematicRevision $revision, array $data): CinematicStage
    {
        $this->assertEditable($revision);

        if (CinematicStage::query()->where('revision_id', $revision->id)->count() >= (int) config('cinematic_stories.limits.max_stages')) {
            throw CinematicException::make('limit_stages', 'بلغت الحد الأقصى لعدد المراحل المسموح به.');
        }

        $stage = new CinematicStage;
        $stage->forceFill([
            'revision_id' => $revision->id,
            'stable_key' => $this->key($data['stable_key'] ?? null, 'st'),
            'position' => $this->nextPosition(CinematicStage::query()->where('revision_id', $revision->id)),
            'title' => $this->title($data['title'] ?? '', 'عنوان المرحلة'),
            'summary' => $this->nullableText($data['summary'] ?? null, 1000),
            'is_optional' => (bool) ($data['is_optional'] ?? false),
        ]);

        $this->saveUnique($stage);
        $this->reopen($revision);

        return $stage;
    }

    /** @param array<string, mixed> $data */
    public function updateStage(CinematicStage $stage, array $data): CinematicStage
    {
        $revision = $this->revisionOf($stage->revision_id);
        $this->assertEditable($revision);

        $stage->forceFill([
            'title' => array_key_exists('title', $data) ? $this->title($data['title'], 'عنوان المرحلة') : $stage->title,
            'summary' => array_key_exists('summary', $data) ? $this->nullableText($data['summary'], 1000) : $stage->summary,
            'is_optional' => array_key_exists('is_optional', $data) ? (bool) $data['is_optional'] : $stage->is_optional,
        ])->save();

        $this->reopen($revision);

        return $stage;
    }

    public function removeStage(CinematicStage $stage): void
    {
        $revision = $this->revisionOf($stage->revision_id);
        $this->assertEditable($revision);
        $stage->delete();
        $this->normalize(CinematicStage::query()->where('revision_id', $revision->id)->orderBy('position')->orderBy('id')->get());
        $this->reopen($revision);
    }

    public function moveStage(CinematicStage $stage, string $direction): void
    {
        $revision = $this->revisionOf($stage->revision_id);
        $this->assertEditable($revision);
        $this->move(CinematicStage::query()->where('revision_id', $revision->id), $stage, $direction);
        $this->reopen($revision);
    }

    // ───────────────────────── الفصول ─────────────────────────

    /** @param array<string, mixed> $data */
    public function addChapter(CinematicStage $stage, array $data): CinematicChapter
    {
        $revision = $this->revisionOf($stage->revision_id);
        $this->assertEditable($revision);

        if (CinematicChapter::query()->where('stage_id', $stage->id)->count() >= (int) config('cinematic_stories.limits.max_chapters_per_stage')) {
            throw CinematicException::make('limit_chapters', 'بلغت الحد الأقصى لعدد الفصول في المرحلة.');
        }

        $chapter = new CinematicChapter;
        $chapter->forceFill([
            'revision_id' => $revision->id,
            'stage_id' => $stage->id,
            'stable_key' => $this->key($data['stable_key'] ?? null, 'ch'),
            'position' => $this->nextPosition(CinematicChapter::query()->where('stage_id', $stage->id)),
            'title' => $this->title($data['title'] ?? '', 'عنوان الفصل'),
            'summary' => $this->nullableText($data['summary'] ?? null, 1000),
            'is_optional' => (bool) ($data['is_optional'] ?? false),
        ]);

        $this->saveUnique($chapter);
        $this->reopen($revision);

        return $chapter;
    }

    /** @param array<string, mixed> $data */
    public function updateChapter(CinematicChapter $chapter, array $data): CinematicChapter
    {
        $revision = $this->revisionOf($chapter->revision_id);
        $this->assertEditable($revision);

        $chapter->forceFill([
            'title' => array_key_exists('title', $data) ? $this->title($data['title'], 'عنوان الفصل') : $chapter->title,
            'summary' => array_key_exists('summary', $data) ? $this->nullableText($data['summary'], 1000) : $chapter->summary,
            'is_optional' => array_key_exists('is_optional', $data) ? (bool) $data['is_optional'] : $chapter->is_optional,
        ])->save();

        $this->reopen($revision);

        return $chapter;
    }

    public function removeChapter(CinematicChapter $chapter): void
    {
        $revision = $this->revisionOf($chapter->revision_id);
        $this->assertEditable($revision);
        $stageId = $chapter->stage_id;
        $chapter->delete();
        $this->normalize(CinematicChapter::query()->where('stage_id', $stageId)->orderBy('position')->orderBy('id')->get());
        $this->reopen($revision);
    }

    public function moveChapter(CinematicChapter $chapter, string $direction): void
    {
        $revision = $this->revisionOf($chapter->revision_id);
        $this->assertEditable($revision);
        $this->move(CinematicChapter::query()->where('stage_id', $chapter->stage_id), $chapter, $direction);
        $this->reopen($revision);
    }

    // ───────────────────────── المشاهد ─────────────────────────

    /** @param array<string, mixed> $data */
    public function addScene(CinematicChapter $chapter, array $data): CinematicScene
    {
        $revision = $this->revisionOf($chapter->revision_id);
        $this->assertEditable($revision);

        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, CinematicScene::TYPES, true)) {
            throw CinematicException::make('scene_type', 'نوع المشهد غير معروف.');
        }

        if (CinematicScene::query()->where('chapter_id', $chapter->id)->count() >= (int) config('cinematic_stories.limits.max_scenes_per_chapter')
            || CinematicScene::query()->where('revision_id', $revision->id)->count() >= (int) config('cinematic_stories.limits.max_scenes_per_revision')) {
            throw CinematicException::make('limit_scenes', 'بلغت الحد الأقصى لعدد المشاهد.');
        }

        return DB::transaction(function () use ($chapter, $revision, $data, $type) {
            $scene = new CinematicScene;
            $scene->forceFill([
                'revision_id' => $revision->id,
                'stage_id' => $chapter->stage_id,
                'chapter_id' => $chapter->id,
                'stable_key' => $this->key($data['stable_key'] ?? null, 'sc'),
                'position' => $this->nextPosition(CinematicScene::query()->where('chapter_id', $chapter->id)),
                'type' => $type,
                'title' => $this->title($data['title'] ?? '', 'عنوان المشهد'),
                'is_optional' => (bool) ($data['is_optional'] ?? false),
                'is_skippable' => $type === CinematicScene::TYPE_VIDEO ? (bool) ($data['is_skippable'] ?? false) : false,
                'allow_replay' => (bool) ($data['allow_replay'] ?? true),
                'awaiting_media' => (bool) ($data['awaiting_media'] ?? ($type === CinematicScene::TYPE_VIDEO)),
                'duration_hint_seconds' => $this->nullableInt($data['duration_hint_seconds'] ?? null, 1, 3600),
                'unlock_rule' => $this->normalizer->unlockRule($data['unlock_rule'] ?? null),
                'config' => $this->normalizer->normalize($type, (array) ($data['config'] ?? [])),
                'puzzle_id' => null,
                'logic_hash' => str_repeat('0', 64),
            ]);

            $this->saveUnique($scene);

            if ($type === CinematicScene::TYPE_PUZZLE && ! empty($data['puzzle_id'])) {
                $this->linkPuzzle($scene, (int) $data['puzzle_id']);
            }

            $this->refreshLogicHash($scene);
            $this->reopen($revision);

            return $scene;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateScene(CinematicScene $scene, array $data): CinematicScene
    {
        $revision = $this->revisionOf($scene->revision_id);
        $this->assertEditable($revision);

        return DB::transaction(function () use ($scene, $data, $revision) {
            $scene->forceFill([
                'title' => array_key_exists('title', $data) ? $this->title($data['title'], 'عنوان المشهد') : $scene->title,
                'is_optional' => array_key_exists('is_optional', $data) ? (bool) $data['is_optional'] : $scene->is_optional,
                'is_skippable' => $scene->type === CinematicScene::TYPE_VIDEO && array_key_exists('is_skippable', $data) ? (bool) $data['is_skippable'] : $scene->is_skippable,
                'allow_replay' => array_key_exists('allow_replay', $data) ? (bool) $data['allow_replay'] : $scene->allow_replay,
                'awaiting_media' => array_key_exists('awaiting_media', $data) ? (bool) $data['awaiting_media'] : $scene->awaiting_media,
                'duration_hint_seconds' => array_key_exists('duration_hint_seconds', $data) ? $this->nullableInt($data['duration_hint_seconds'], 1, 3600) : $scene->duration_hint_seconds,
                'unlock_rule' => array_key_exists('unlock_rule', $data) ? $this->normalizer->unlockRule($data['unlock_rule']) : $scene->unlock_rule,
                'config' => array_key_exists('config', $data) ? $this->normalizer->normalize($scene->type, (array) $data['config']) : $scene->config,
            ])->save();

            if ($scene->type === CinematicScene::TYPE_PUZZLE && array_key_exists('puzzle_id', $data)) {
                $data['puzzle_id'] ? $this->linkPuzzle($scene, (int) $data['puzzle_id']) : $scene->forceFill(['puzzle_id' => null])->save();
            }

            $this->refreshLogicHash($scene);
            $this->reopen($revision);

            return $scene;
        });
    }

    public function removeScene(CinematicScene $scene): void
    {
        $revision = $this->revisionOf($scene->revision_id);
        $this->assertEditable($revision);
        $chapterId = $scene->chapter_id;
        $scene->delete();
        $this->normalize(CinematicScene::query()->where('chapter_id', $chapterId)->orderBy('position')->orderBy('id')->get());
        $this->reopen($revision);
    }

    public function moveScene(CinematicScene $scene, string $direction): void
    {
        $revision = $this->revisionOf($scene->revision_id);
        $this->assertEditable($revision);
        $this->move(CinematicScene::query()->where('chapter_id', $scene->chapter_id), $scene, $direction);
        $this->reopen($revision);
    }

    /**
     * يحفظ حل المشهد (مشفّر) بعد التحقق من اكتماله مع التعريف العام. لا يدخل الحل إعدادًا عامًا ولا يُعاد في أي واجهة.
     *
     * @param  array<string, mixed>  $solution
     * @return list<string> أخطاء التحقق (فارغة = حُفظ)
     */
    public function setInteractionSolution(CinematicScene $scene, array $solution): array
    {
        $revision = $this->revisionOf($scene->revision_id);
        $this->assertEditable($revision);

        if (! in_array($scene->type, [CinematicScene::TYPE_INTERACTION, CinematicScene::TYPE_EXPLORATION], true)) {
            throw CinematicException::make('scene_type', 'هذا النوع من المشاهد لا يحمل حلًّا تفاعليًا.');
        }

        $public = (array) ($scene->config['interaction'] ?? []);
        $kind = (string) ($public['kind'] ?? '');
        $errors = $this->engine->validate($kind, $public, $solution);

        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($scene, $solution, $revision) {
            $row = CinematicSceneSolution::query()->where('scene_id', $scene->id)->first() ?? new CinematicSceneSolution;
            $row->forceFill(['scene_id' => $scene->id, 'payload' => $solution])->save();
            $this->refreshLogicHash($scene);
            $this->reopen($revision);
        });

        return [];
    }

    /**
     * تبديل ملف وسيط لمشهد. مسموح على المسودة والمنشورة (لا يمس منطق المشهد ولا تقدّم اللاعبين)، ومرفوض على المستبدلة/المسحوبة.
     * الملف الجديد يجب أن يكون جاهزًا ومن الدور نفسه. null = إزالة الربط.
     */
    public function replaceMedia(CinematicScene $scene, string $role, ?CinematicMediaAsset $asset): CinematicScene
    {
        $revision = $this->revisionOf($scene->revision_id);

        if (! in_array($revision->status, [...CinematicRevision::EDITABLE, CinematicRevision::STATUS_PUBLISHED], true)) {
            throw CinematicException::make('revision_locked', 'لا يمكن تبديل الوسائط في نسخة مستبدلة أو مسحوبة.', 409);
        }

        $column = ['video' => 'video_asset_id', 'poster' => 'poster_asset_id', 'subtitle' => 'subtitle_asset_id', 'audio' => 'audio_asset_id'][$role] ?? null;

        if ($column === null) {
            throw CinematicException::make('role_invalid', 'نوع الوسيط غير معروف.');
        }

        if ($role === 'video' && $scene->type !== CinematicScene::TYPE_VIDEO) {
            throw CinematicException::make('scene_type', 'الفيديو يُربط بمشاهد الفيديو فقط.');
        }

        if ($asset !== null && ($asset->role !== $role || ! $asset->isReady())) {
            throw CinematicException::make('media_not_ready', 'الملف المختار غير جاهز أو ليس من النوع المطلوب.');
        }

        $scene->forceFill([
            $column => $asset?->id,
            'awaiting_media' => $role === 'video' ? $asset === null : $scene->awaiting_media,
        ])->save();

        $this->reopen($revision);

        return $scene;
    }

    // ───────────────────────── داخلي ─────────────────────────

    public function assertEditable(CinematicRevision $revision): void
    {
        if (! $revision->isEditable()) {
            throw CinematicException::make('revision_locked', 'هذه النسخة مقفلة؛ أنشئ مسودة جديدة منها للتعديل.', 409);
        }
    }

    /** أي تعديل على نسخة «قيد المراجعة» يعيدها مسودة (يجب إعادة الإرسال للمراجعة). */
    protected function reopen(CinematicRevision $revision): void
    {
        $changes = ['readiness' => null];

        if ($revision->status === CinematicRevision::STATUS_IN_REVIEW) {
            $changes += ['status' => CinematicRevision::STATUS_DRAFT, 'submitted_at' => null];
        }

        $revision->forceFill($changes)->save();
    }

    protected function revisionOf(int $id): CinematicRevision
    {
        return CinematicRevision::query()->findOrFail($id);
    }

    protected function newRevision(CinematicStory $story, ?User $by, ?CinematicRevision $source): CinematicRevision
    {
        $next = (int) CinematicRevision::query()->where('story_id', $story->id)->max('revision_number') + 1;

        $revision = new CinematicRevision;
        $revision->forceFill([
            'story_id' => $story->id,
            'revision_number' => $next,
            'status' => CinematicRevision::STATUS_DRAFT,
            'title' => $source?->title ?? $story->title,
            'summary' => $source?->summary ?? $story->summary,
            'created_by' => $by?->id,
        ])->save();

        if ($source !== null) {
            $this->cloneStructure($source, $revision);
        }

        return $revision;
    }

    protected function cloneStructure(CinematicRevision $from, CinematicRevision $to): void
    {
        $stageMap = [];
        $chapterMap = [];

        foreach (CinematicStage::query()->where('revision_id', $from->id)->orderBy('position')->orderBy('id')->get() as $stage) {
            $copy = new CinematicStage;
            $copy->forceFill(['revision_id' => $to->id, 'stable_key' => $stage->stable_key, 'position' => $stage->position, 'title' => $stage->title, 'summary' => $stage->summary, 'is_optional' => $stage->is_optional])->save();
            $stageMap[$stage->id] = $copy->id;
        }

        foreach (CinematicChapter::query()->where('revision_id', $from->id)->orderBy('position')->orderBy('id')->get() as $chapter) {
            $copy = new CinematicChapter;
            $copy->forceFill(['revision_id' => $to->id, 'stage_id' => $stageMap[$chapter->stage_id], 'stable_key' => $chapter->stable_key, 'position' => $chapter->position, 'title' => $chapter->title, 'summary' => $chapter->summary, 'is_optional' => $chapter->is_optional])->save();
            $chapterMap[$chapter->id] = $copy->id;
        }

        foreach (CinematicScene::query()->where('revision_id', $from->id)->orderBy('position')->orderBy('id')->get() as $scene) {
            $copy = new CinematicScene;
            $copy->forceFill([
                'revision_id' => $to->id,
                'stage_id' => $stageMap[$scene->stage_id],
                'chapter_id' => $chapterMap[$scene->chapter_id],
                'stable_key' => $scene->stable_key,
                'position' => $scene->position,
                'type' => $scene->type,
                'title' => $scene->title,
                'is_optional' => $scene->is_optional,
                'is_skippable' => $scene->is_skippable,
                'allow_replay' => $scene->allow_replay,
                'awaiting_media' => $scene->awaiting_media,
                'duration_hint_seconds' => $scene->duration_hint_seconds,
                'unlock_rule' => $scene->unlock_rule,
                'config' => $scene->config,
                'logic_hash' => $scene->logic_hash,
                'puzzle_id' => $scene->puzzle_id,
                'video_asset_id' => $scene->video_asset_id,
                'poster_asset_id' => $scene->poster_asset_id,
                'subtitle_asset_id' => $scene->subtitle_asset_id,
                'audio_asset_id' => $scene->audio_asset_id,
            ])->save();

            $solution = CinematicSceneSolution::query()->where('scene_id', $scene->id)->first();

            if ($solution) {
                $row = new CinematicSceneSolution;
                $row->forceFill(['scene_id' => $copy->id, 'payload' => $solution->payload])->save();
            }
        }
    }

    protected function linkPuzzle(CinematicScene $scene, int $puzzleId): void
    {
        $puzzle = Puzzle::query()->find($puzzleId);
        $reason = $this->puzzles->ineligibleReason($puzzle);

        if ($reason !== null) {
            throw CinematicException::make('puzzle_ineligible', $reason);
        }

        $scene->forceFill(['puzzle_id' => $puzzle->id])->save();
    }

    /** بصمة منطق المشهد: النوع + الأحجية + التعريف التفاعلي + الحل + شروط الفتح. تتغير فقط بتغيّر المنطق (لا بالنصوص ولا الوسائط). */
    public function refreshLogicHash(CinematicScene $scene): void
    {
        $solution = CinematicSceneSolution::query()->where('scene_id', $scene->id)->first();

        $hash = hash('sha256', (string) json_encode([
            'type' => $scene->type,
            'puzzle' => $scene->puzzle_id,
            'interaction' => $scene->config['interaction'] ?? null,
            'solution' => $solution?->payload,
            'unlock' => $scene->unlock_rule['requires'] ?? [],
            'optional' => (bool) $scene->is_optional,
        ], JSON_UNESCAPED_UNICODE));

        if ($scene->logic_hash !== $hash) {
            $scene->forceFill(['logic_hash' => $hash])->save();
        }
    }

    protected function saveUnique(\Illuminate\Database\Eloquent\Model $model): void
    {
        try {
            $model->save();
        } catch (QueryException $e) {
            throw CinematicException::make('key_taken', 'المفتاح الثابت مستخدم من قبل في هذه النسخة.', 409);
        }
    }

    protected function nextPosition(\Illuminate\Database\Eloquent\Builder $query): int
    {
        return (int) $query->max('position') + 1;
    }

    /** @param Collection<int, \Illuminate\Database\Eloquent\Model> $items */
    protected function normalize(Collection $items): void
    {
        foreach ($items->values() as $i => $item) {
            if ((int) $item->position !== $i + 1) {
                $item->forceFill(['position' => $i + 1])->save();
            }
        }
    }

    protected function move(\Illuminate\Database\Eloquent\Builder $siblings, \Illuminate\Database\Eloquent\Model $item, string $direction): void
    {
        DB::transaction(function () use ($siblings, $item, $direction) {
            $ordered = (clone $siblings)->orderBy('position')->orderBy('id')->get()->values();
            $this->normalize($ordered);
            $ordered = (clone $siblings)->orderBy('position')->orderBy('id')->get()->values();
            $index = $ordered->search(fn ($row) => $row->id === $item->id);

            if ($index === false) {
                return;
            }

            $swap = $direction === 'up' ? $index - 1 : $index + 1;

            if (! isset($ordered[$swap])) {
                return;
            }

            [$a, $b] = [$ordered[$index], $ordered[$swap]];
            [$pa, $pb] = [$a->position, $b->position];
            $a->forceFill(['position' => $pb])->save();
            $b->forceFill(['position' => $pa])->save();
        });
    }

    protected function key(mixed $given, string $prefix): string
    {
        if (is_string($given) && trim($given) !== '') {
            $key = trim($given);

            if (preg_match('/\A[a-z0-9_]{2,64}\z/', $key) !== 1) {
                throw CinematicException::make('key_invalid', 'المفتاح الثابت: حروف إنجليزية صغيرة وأرقام وشرطة سفلية فقط (2–64).');
            }

            return $key;
        }

        return $prefix.'_'.Str::lower(Str::random(8));
    }

    protected function title(mixed $value, string $label): string
    {
        $text = mb_substr(trim(strip_tags((string) $value)), 0, 160);

        if ($text === '') {
            throw CinematicException::make('title_required', "{$label} مطلوب.");
        }

        return $text;
    }

    protected function nullableText(mixed $value, int $max): ?string
    {
        $text = mb_substr(trim(strip_tags((string) $value)), 0, $max);

        return $text === '' ? null : $text;
    }

    protected function nullableInt(mixed $value, int $min, int $max): ?int
    {
        return is_numeric($value) && $value !== '' ? max($min, min($max, (int) $value)) : null;
    }

    protected function seriesId(string $access, mixed $seriesId): ?int
    {
        if ($access !== CinematicStory::ACCESS_PREMIUM) {
            return null;
        }

        if (empty($seriesId) || ! \App\Models\CreatorSeries::query()->whereKey((int) $seriesId)->exists()) {
            throw CinematicException::make('series_required', 'القصة المميزة تتطلب ربطًا بسلسلة مبدع قائمة (ملكية الوصول تُقرأ منها).');
        }

        return (int) $seriesId;
    }

    protected function uniqueSlug(string $given, string $title): string
    {
        $base = Str::slug($given !== '' ? $given : $title);
        $base = $base === '' ? 'story-'.Str::lower(Str::random(6)) : mb_substr($base, 0, 100);
        $slug = $base;
        $n = 2;

        // كلمات محجوزة: تتطابق مع مقاطع حرفية في مسارات /cinematic فلا يجوز أن تصير عنوان قصة.
        while (in_array($slug, self::RESERVED_SLUGS, true) || mb_strlen($slug) < 3 || CinematicStory::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}

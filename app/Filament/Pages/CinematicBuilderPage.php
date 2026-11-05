<?php

namespace App\Filament\Pages;

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Models\Puzzle;
use App\Services\Cinematic\Admin\SceneFormCodec;
use App\Services\Cinematic\CinematicAuthoringService;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicPublishingService;
use App\Services\Cinematic\CinematicPuzzleBridge;
use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\Interactions\InteractionEngine;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * CIN-1 - منشئ السلسلة السينمائية: القصة، النسخ (مسودة/مراجعة/منشورة)، المراحل والفصول والمشاهد، محرّر المشهد حسب نوعه، تعريف التفاعل وحلّه،
 * اختيار الوسائط من المكتبة، تقرير الجاهزية والوسائط الناقصة، معاينة إشرافية، ثم إرسال للمراجعة/نشر/سحب.
 * عرض وتفويض فقط: كل القواعد (الأقفال، الحدود، تفرّد المفاتيح، التحقق، النشر) داخل الخدمات. الصلاحيات: view/manage/media/publish/preview.
 * الحل (يُعرض لأصحاب manage فقط هنا) لا يخرج لأي صفحة لاعب أبدًا.
 */
class CinematicBuilderPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $title = 'منشئ السلسلة السينمائية';

    protected static ?string $slug = 'cinematic-builder';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.cinematic-builder';

    #[Url(as: 'story')]
    public ?string $storyId = null;

    #[Url(as: 'revision')]
    public ?string $revisionId = null;

    /** @var array<string, mixed> */
    public array $storyForm = [];

    /** @var array<string, mixed> */
    public array $revForm = [];

    public string $newStage = '';

    /** @var array<string, string> */
    public array $newChapter = [];

    /** @var array<string, array<string, string>> */
    public array $newScene = [];

    public ?string $editingScene = null;

    /** @var array<string, mixed> */
    public array $sf = [];

    public string $cover = '';

    public static function canAccess(): bool
    {
        return (bool) config('cinematic_stories.enabled') && (auth()->user()?->can('cinematic-stories.view') ?? false);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->story() === null, 404);
        $story = $this->story();
        $this->storyForm = ['title' => $story->title, 'summary' => (string) $story->summary, 'label_kind' => $story->label_kind, 'access_mode' => $story->access_mode, 'creator_series_id' => (string) ($story->creator_series_id ?? '')];
        $this->cover = (string) $story->cover?->public_id;
        $this->loadRevisionForm();
    }

    public function story(): ?CinematicStory
    {
        return $this->storyId === null ? null : CinematicStory::query()->where('public_id', $this->storyId)->first();
    }

    public function revision(): CinematicRevision
    {
        $story = $this->story();
        $query = CinematicRevision::query()->where('story_id', $story->id);

        if ($this->revisionId !== null && ($picked = (clone $query)->where('public_id', $this->revisionId)->first())) {
            return $picked;
        }

        return (clone $query)->whereIn('status', CinematicRevision::EDITABLE)->first()
            ?? (clone $query)->where('status', CinematicRevision::STATUS_PUBLISHED)->first()
            ?? $query->orderByDesc('revision_number')->firstOrFail();
    }

    protected function loadRevisionForm(): void
    {
        $r = $this->revision();
        $this->revForm = ['title' => $r->title, 'summary' => (string) $r->summary, 'notes' => (string) $r->notes];
    }

    public function can(string $permission): bool
    {
        return auth()->user()?->can('cinematic-stories.'.$permission) ?? false;
    }

    /** ينفّذ إجراءً مفوَّضًا ويحوّل استثناءات المجال إلى إشعار عربي. */
    protected function run(string $permission, callable $action, ?string $success = null): bool
    {
        abort_unless($this->can($permission), 403);

        try {
            $action();
        } catch (CinematicException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }

        if ($success !== null) {
            Notification::make()->success()->title($success)->send();
        }

        return true;
    }

    public function selectRevision(string $publicId): void
    {
        $this->revisionId = $publicId;
        $this->editingScene = null;
        $this->loadRevisionForm();
    }

    // ───────────────────────── القصة والنسخ ─────────────────────────

    public function saveStory(CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($authoring) {
            $story = $this->story();
            $authoring->updateStory($story, $this->storyForm);
            $cover = $this->cover === '' ? null : CinematicMediaAsset::query()->where('public_id', $this->cover)->first();
            $authoring->setCover($story, $cover);
        }, 'حُفظت بيانات القصة.');
    }

    public function saveRevision(CinematicAuthoringService $authoring): void
    {
        $this->run('manage', fn () => $authoring->updateRevision($this->revision(), $this->revForm), 'حُفظت بيانات النسخة.');
    }

    public function newDraft(CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($authoring) {
            $draft = $authoring->createDraft($this->story(), auth()->user());
            $this->revisionId = $draft->public_id;
            $this->loadRevisionForm();
        }, 'أُنشئت مسودة جديدة منسوخة من النسخة المنشورة (المنشورة لم تُمس).');
    }

    public function deleteDraft(CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($authoring) {
            $authoring->deleteDraft($this->revision());
            $this->revisionId = null;
            $this->editingScene = null;
            $this->loadRevisionForm();
        }, 'حُذفت المسودة.');
    }

    public function submitReview(CinematicPublishingService $publishing): void
    {
        $this->run('publish', fn () => $publishing->submitForReview($this->revision()), 'أُرسلت النسخة للمراجعة.');
    }

    public function requestChanges(CinematicPublishingService $publishing): void
    {
        $this->run('publish', fn () => $publishing->requestChanges($this->revision()), 'أُعيدت النسخة إلى مسودة للتعديل.');
    }

    public function publish(CinematicPublishingService $publishing): void
    {
        $this->run('publish', fn () => $publishing->publish($this->revision(), auth()->user()), 'نُشرت النسخة. اللاعبون الجدد يبدؤون عليها، ومن يلعب نسخة سابقة يكمل عليها حتى ينتقل بنفسه.');
    }

    public function unpublish(CinematicPublishingService $publishing): void
    {
        $this->run('publish', fn () => $publishing->unpublish($this->revision()), 'سُحبت القصة من النشر (لم يُحذف شيء، وتقدّم اللاعبين محفوظ).');
    }

    // ───────────────────────── البنية ─────────────────────────

    public function addStage(CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($authoring) {
            $authoring->addStage($this->revision(), ['title' => $this->newStage]);
            $this->newStage = '';
        }, 'أُضيفت المرحلة.');
    }

    public function moveStage(string $id, string $direction, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', fn () => $authoring->moveStage($this->stageOf($id), $direction));
    }

    public function removeStage(string $id, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', fn () => $authoring->removeStage($this->stageOf($id)), 'حُذفت المرحلة بمحتواها من المسودة.');
    }

    public function toggleStageOptional(string $id, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($id, $authoring) {
            $stage = $this->stageOf($id);
            $authoring->updateStage($stage, ['is_optional' => ! $stage->is_optional]);
        });
    }

    public function addChapter(string $stageId, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($stageId, $authoring) {
            $authoring->addChapter($this->stageOf($stageId), ['title' => $this->newChapter[$stageId] ?? '']);
            $this->newChapter[$stageId] = '';
        }, 'أُضيف الفصل.');
    }

    public function moveChapter(string $id, string $direction, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', fn () => $authoring->moveChapter($this->chapterOf($id), $direction));
    }

    public function removeChapter(string $id, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', fn () => $authoring->removeChapter($this->chapterOf($id)), 'حُذف الفصل بمشاهده من المسودة.');
    }

    public function toggleChapterOptional(string $id, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($id, $authoring) {
            $chapter = $this->chapterOf($id);
            $authoring->updateChapter($chapter, ['is_optional' => ! $chapter->is_optional]);
        });
    }

    public function addScene(string $chapterId, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($chapterId, $authoring) {
            $form = $this->newScene[$chapterId] ?? [];
            $scene = $authoring->addScene($this->chapterOf($chapterId), ['type' => $form['type'] ?? 'narrative', 'title' => $form['title'] ?? '', 'stable_key' => $form['key'] ?? null]);
            $this->newScene[$chapterId] = ['type' => 'narrative', 'title' => '', 'key' => ''];
            $this->editScene($scene->public_id);
        }, 'أُضيف المشهد.');
    }

    public function moveScene(string $id, string $direction, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', fn () => $authoring->moveScene($this->sceneOf($id), $direction));
    }

    public function removeScene(string $id, CinematicAuthoringService $authoring): void
    {
        $this->run('manage', function () use ($id, $authoring) {
            $authoring->removeScene($this->sceneOf($id));
            $this->editingScene = null;
        }, 'حُذف المشهد من المسودة.');
    }

    // ───────────────────────── محرّر المشهد ─────────────────────────

    public function editScene(string $id): void
    {
        $scene = $this->sceneOf($id);
        $config = (array) $scene->config;
        $solution = CinematicSceneSolution::query()->where('scene_id', $scene->id)->first();
        $public = (array) ($config['interaction'] ?? []);
        $this->editingScene = $scene->public_id;
        $this->sf = [
            'type' => $scene->type, 'key' => $scene->stable_key, 'title' => $scene->title, 'description' => (string) ($config['description'] ?? ''),
            'optional' => (bool) $scene->is_optional, 'skippable' => (bool) $scene->is_skippable, 'replay' => (bool) $scene->allow_replay, 'awaiting' => (bool) $scene->awaiting_media,
            'duration' => (string) ($scene->duration_hint_seconds ?? ''), 'requires' => implode(',', (array) ($scene->unlock_rule['requires'] ?? [])),
            'lines' => SceneFormCodec::linesToText((array) ($config['lines'] ?? [])), 'transcript' => SceneFormCodec::linesToText((array) ($config['transcript'] ?? [])),
            'intro' => SceneFormCodec::linesToText((array) ($config['intro_lines'] ?? [])),
            't_title' => (string) ($config['title'] ?? ''), 't_sub' => (string) ($config['subtitle'] ?? ''), 't_next' => (string) ($config['next_stage_title'] ?? ''),
            'puzzle_id' => (string) ($scene->puzzle_id ?? ''), 'max_attempts' => (string) ($config['max_attempts'] ?? ''),
            'wrong_message' => (string) ($config['wrong_message'] ?? ''), 'success_message' => (string) ($config['success_message'] ?? ''),
            'kind' => (string) ($public['kind'] ?? 'choose_item'),
            'ix' => $this->can('manage') && $public !== [] ? SceneFormCodec::encodeInteraction($public, $solution ? (array) $solution->payload : []) : SceneFormCodec::encodeInteraction(['kind' => 'choose_item'], []),
            'media' => ['video' => (string) $scene->video?->public_id, 'poster' => (string) $scene->poster?->public_id, 'subtitle' => (string) $scene->subtitle?->public_id, 'audio' => (string) $scene->audio?->public_id],
        ];
    }

    public function closeScene(): void
    {
        $this->editingScene = null;
    }

    public function saveScene(CinematicAuthoringService $authoring, InteractionEngine $engine): void
    {
        $this->run('manage', function () use ($authoring) {
            $scene = $this->sceneOf((string) $this->editingScene);
            $sf = $this->sf;
            $config = ['description' => $sf['description'] ?? ''];
            $type = $scene->type;
            $solution = null;

            if ($type === CinematicScene::TYPE_VIDEO) {
                $config['transcript'] = SceneFormCodec::textToLines((string) ($sf['transcript'] ?? ''));
            } elseif ($type === CinematicScene::TYPE_NARRATIVE) {
                $config['lines'] = SceneFormCodec::textToLines((string) ($sf['lines'] ?? ''));
            } elseif ($type === CinematicScene::TYPE_TRANSITION) {
                $config += ['title' => $sf['t_title'] ?? '', 'subtitle' => $sf['t_sub'] ?? '', 'next_stage_title' => $sf['t_next'] ?? ''];
            } else {
                $config['intro_lines'] = SceneFormCodec::textToLines((string) ($sf['intro'] ?? ''));
                $config['wrong_message'] = $sf['wrong_message'] ?? '';
                $config['success_message'] = $sf['success_message'] ?? '';
                $config['max_attempts'] = $sf['max_attempts'] ?? '';

                if ($type !== CinematicScene::TYPE_PUZZLE) {
                    [$public, $solution] = SceneFormCodec::decodeInteraction((string) ($sf['kind'] ?? ''), (array) ($sf['ix'] ?? []));
                    $config['interaction'] = $public;
                }
            }

            $data = [
                'title' => $sf['title'] ?? '', 'is_optional' => (bool) ($sf['optional'] ?? false), 'is_skippable' => (bool) ($sf['skippable'] ?? false), 'allow_replay' => (bool) ($sf['replay'] ?? false),
                'awaiting_media' => (bool) ($sf['awaiting'] ?? false), 'duration_hint_seconds' => $sf['duration'] ?? null,
                'unlock_rule' => ['requires' => SceneFormCodec::csv((string) ($sf['requires'] ?? ''))], 'config' => $config,
            ];

            if ($type === CinematicScene::TYPE_PUZZLE) {
                $data['puzzle_id'] = ($sf['puzzle_id'] ?? '') === '' ? null : (int) $sf['puzzle_id'];
            }

            $authoring->updateScene($scene, $data);

            if ($solution !== null) {
                $errors = $authoring->setInteractionSolution($scene->fresh(), $solution);

                if ($errors !== []) {
                    Notification::make()->warning()->title('حُفظ المشهد لكن تعريف التفاعل غير مكتمل')->body(implode(' — ', $errors))->persistent()->send();
                }
            }
        }, 'حُفظ المشهد.');
    }

    /** يربط/يبدّل وسائط المشهد من المكتبة (صلاحية media). مسموح حتى على نسخة منشورة دون المساس بمنطقها. */
    public function saveMedia(CinematicAuthoringService $authoring): void
    {
        $this->run('media', function () use ($authoring) {
            $scene = $this->sceneOf((string) $this->editingScene);

            foreach (['video', 'poster', 'subtitle', 'audio'] as $role) {
                if ($role === 'video' && $scene->type !== CinematicScene::TYPE_VIDEO) {
                    continue;
                }

                $picked = (string) ($this->sf['media'][$role] ?? '');
                $current = (string) ($scene->{$role}?->public_id);

                if ($picked === $current) {
                    continue;
                }

                $authoring->replaceMedia($scene, $role, $picked === '' ? null : CinematicMediaAsset::query()->where('public_id', $picked)->firstOrFail());
            }
        }, 'حُفظت وسائط المشهد.');
    }

    // ───────────────────────── داخلي ─────────────────────────

    protected function stageOf(string $publicId): CinematicStage
    {
        return CinematicStage::query()->where('public_id', $publicId)->where('revision_id', $this->revision()->id)->firstOrFail();
    }

    protected function chapterOf(string $publicId): CinematicChapter
    {
        return CinematicChapter::query()->where('public_id', $publicId)->where('revision_id', $this->revision()->id)->firstOrFail();
    }

    protected function sceneOf(string $publicId): CinematicScene
    {
        return CinematicScene::query()->where('public_id', $publicId)->where('revision_id', $this->revision()->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $story = $this->story();
        $revision = $this->revision();
        $tree = CinematicStage::query()->where('revision_id', $revision->id)->orderBy('position')
            ->with(['chapters' => fn ($q) => $q->orderBy('position'), 'chapters.scenes' => fn ($q) => $q->orderBy('position')->with(['video', 'poster'])])->get();
        $report = app(CinematicReadinessChecker::class)->check($revision);
        $bridge = app(CinematicPuzzleBridge::class);
        $assets = fn (string $role) => CinematicMediaAsset::query()->where('role', $role)->where('status', CinematicMediaAsset::STATUS_READY)->orderByDesc('id')->limit(200)->get(['public_id', 'original_name', 'duration_ms']);
        $puzzles = $this->editingScene && in_array($this->sf['type'] ?? '', ['puzzle'], true)
            ? Puzzle::query()->where('is_active', true)->orderBy('title')->limit(300)->get()->filter(fn (Puzzle $p) => $bridge->ineligibleReason($p) === null)->values()
            : collect();

        return [
            'story' => $story,
            'revision' => $revision,
            'revisions' => CinematicRevision::query()->where('story_id', $story->id)->orderByDesc('revision_number')->get(),
            'editable' => $revision->isEditable(),
            'tree' => $tree,
            'report' => $report,
            'assets' => ['video' => $assets('video'), 'poster' => $assets('poster'), 'subtitle' => $assets('subtitle'), 'audio' => $assets('audio')],
            'puzzles' => $puzzles,
            'kinds' => ['hotspot' => 'نقطة ساخنة', 'container' => 'باب/درج بشرط', 'sequence' => 'ترتيب', 'drag_drop' => 'سحب وإفلات', 'choose_item' => 'اختيار عنصر', 'compare' => 'مقارنة', 'reveal_code' => 'كشف رمز بشرط', 'flashlight' => 'مصباح'],
            'types' => ['video' => 'فيديو', 'narrative' => 'سرد', 'puzzle' => 'أحجية', 'interaction' => 'تفاعل', 'exploration' => 'استكشاف', 'transition' => 'انتقال'],
            'statusLabels' => ['draft' => 'مسودة', 'in_review' => 'قيد المراجعة', 'published' => 'منشورة', 'superseded' => 'مستبدلة', 'unpublished' => 'مسحوبة'],
            'previewUrl' => $this->can('preview') ? route('cinematic.preview', $revision->public_id) : null,
            'publicUrl' => $story->liveRevision ? route('cinematic.show', $story->slug) : null,
            'listUrl' => CinematicStoriesPage::getUrl(),
            'mediaUrl' => CinematicMediaLibraryPage::getUrl(),
        ];
    }
}

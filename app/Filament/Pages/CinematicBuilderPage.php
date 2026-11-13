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
use App\Services\Cinematic\Media\CinematicMediaLinks;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
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

    /** المشهد المفتوح في المحرّر. مربوط بالعنوان (?scene=) فيمكن فتح المحرّر مباشرة من المعاينة/المكتبة وتبقى الصفحة صالحة للتحديث. */
    #[Url(as: 'scene', history: false)]
    public ?string $editingScene = null;

    /** @var array<string, mixed> */
    public array $sf = [];

    public string $cover = '';

    public static function canAccess(): bool
    {
        return (bool) config('cinematic_stories.enabled') && (auth()->user()?->can('cinematic-stories.view') ?? false);
    }

    /** مساحة عرض كاملة: المنشئ يضم قائمة المشاهد ولوحة المحرّر جنبًا إلى جنب. */
    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->story() === null, 404);
        $story = $this->story();
        $this->storyForm = ['title' => $story->title, 'summary' => (string) $story->summary, 'label_kind' => $story->label_kind, 'access_mode' => $story->access_mode, 'creator_series_id' => (string) ($story->creator_series_id ?? '')];
        $this->cover = (string) $story->cover?->public_id;
        $this->loadRevisionForm();

        // فتح المحرّر مباشرة عند وجود ?scene= (رابط من المعاينة أو المكتبة). معرّف لا يخص هذه النسخة يُهمل بصمت بدل خطأ 404.
        if ($this->editingScene !== null && $this->editingScene !== '') {
            $exists = CinematicScene::query()->where('public_id', $this->editingScene)->where('revision_id', $this->revision()->id)->exists();

            if ($exists) {
                $this->loadSceneForm($this->editingScene);
            } else {
                $this->editingScene = null;
            }
        } else {
            $this->editingScene = null;
        }
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
        $this->sf = [];
        $this->resetValidation();
        $this->loadRevisionForm();
        $this->dispatch('cin-scene-closed');
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
            $this->sf = [];
            $this->loadRevisionForm();
            $this->dispatch('cin-scene-closed');
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

            if ($this->editingScene === $id) {
                $this->editingScene = null;
                $this->sf = [];
                $this->dispatch('cin-scene-closed');
            }
        }, 'حُذف المشهد من المسودة.');
    }

    // ───────────────────────── محرّر المشهد ─────────────────────────

    /** يفتح محرّر المشهد المطلوب. يبني النموذج من قاعدة البيانات دائمًا (لا يرث شيئًا من مشهد سابق) ثم يبلّغ الواجهة لتنقل التركيز إليه. */
    public function editScene(string $id): void
    {
        $this->loadSceneForm($id);
        $this->resetValidation();
        $this->dispatch('cin-scene-opened', id: $id);
    }

    protected function loadSceneForm(string $id): void
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
            'media' => $this->mediaForm($scene),
        ];
    }

    /** @return array<string, string> */
    protected function mediaForm(CinematicScene $scene): array
    {
        $scene->unsetRelations();

        return ['video' => (string) $scene->video?->public_id, 'poster' => (string) $scene->poster?->public_id, 'subtitle' => (string) $scene->subtitle?->public_id, 'audio' => (string) $scene->audio?->public_id];
    }

    public function closeScene(): void
    {
        $this->editingScene = null;
        $this->sf = [];
        $this->resetValidation();
        $this->dispatch('cin-scene-closed');
    }

    public function saveScene(CinematicAuthoringService $authoring, InteractionEngine $engine): void
    {
        $clean = true;

        $saved = $this->run('manage', function () use ($authoring, &$clean) {
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
                // فيديو مربوط فعلًا = ليس «بانتظار الإنتاج» (يمنع علم قديم في النموذج من إعادة المشهد إلى حالة الانتظار بعد ربط الوسائط).
                'awaiting_media' => $scene->video_asset_id !== null ? false : (bool) ($sf['awaiting'] ?? false), 'duration_hint_seconds' => $sf['duration'] ?? null,
                'unlock_rule' => ['requires' => SceneFormCodec::csv((string) ($sf['requires'] ?? ''))], 'config' => $config,
            ];

            if ($type === CinematicScene::TYPE_PUZZLE) {
                $data['puzzle_id'] = ($sf['puzzle_id'] ?? '') === '' ? null : (int) $sf['puzzle_id'];
            }

            $authoring->updateScene($scene, $data);

            if ($solution !== null) {
                $errors = $authoring->setInteractionSolution($scene->fresh(), $solution);

                if ($errors !== []) {
                    $clean = false;
                    Notification::make()->warning()->title('حُفظ المشهد لكن تعريف التفاعل غير مكتمل')->body(implode(' — ', $errors))->persistent()->send();
                }
            }
        }, 'حُفظ المشهد.');

        if ($saved) {
            // بعد حفظ سليم نعرض ما حُفظ فعلًا (بعد التطبيع)؛ وإن كان الحل ناقصًا نُبقي ما كتبه المسؤول كي لا يضيع.
            if ($clean) {
                $this->loadSceneForm((string) $this->editingScene);
            }

            $this->dispatch('cin-scene-saved', id: $this->editingScene, kind: 'scene');
        }
    }

    /** يربط/يبدّل وسائط المشهد من المكتبة (صلاحية media). مسموح حتى على نسخة منشورة دون المساس بمنطقها. لا ينسخ أي ملف: الربط مرجع إلى ملف المكتبة فقط. */
    public function saveMedia(CinematicAuthoringService $authoring): void
    {
        $changed = [];

        $ok = $this->run('media', function () use ($authoring, &$changed) {
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
                $changed[] = $role;
            }
        });

        if (! $ok) {
            return;
        }

        // نقرأ الحالة المحفوظة فعلًا ونحدّث الحقول المتأثرة (لا نعيد بناء النموذج كله كي لا نمحو تعديلات نص غير محفوظة).
        $scene = $this->sceneOf((string) $this->editingScene);
        $this->sf['media'] = $this->mediaForm($scene);
        $this->sf['awaiting'] = (bool) $scene->awaiting_media;

        $notification = Notification::make()->success();

        if ($changed === []) {
            $notification->title('لا تغيير في الوسائط: الاختيار الحالي هو المحفوظ أصلًا.');
        } else {
            $ready = $scene->type === CinematicScene::TYPE_VIDEO && $scene->video !== null && $scene->video->isReady();
            $notification->title($ready ? 'حُفظت وسائط المشهد — فيديو جاهز' : 'حُفظت وسائط المشهد')
                ->body('الربط مرجع إلى ملف المكتبة؛ لم يُنسخ أي ملف ولم يتغير منطق المشهد.');

            if ($this->can('preview')) {
                $notification->actions([
                    NotificationAction::make('preview')->label('معاينة هذا المشهد')->button()->url($this->sceneUrl($scene), shouldOpenInNewTab: true),
                ]);
            }
        }

        $notification->send();
        $this->dispatch('cin-scene-saved', id: $this->editingScene, kind: 'media');
    }

    /** رابط المعاينة الإشرافية لمشهد بعينه (بلا تقدّم محفوظ). $asPlayer = «معاينة كما يراها اللاعب» (?mode=player): الشاشة الغامرة بلا أدوات، وهي معاينة معزولة أيضًا. */
    protected function sceneUrl(CinematicScene $scene, bool $asPlayer = false): string
    {
        return route('cinematic.preview', array_filter(['revision' => $this->revision()->public_id, 'scene' => $scene->public_id, 'mode' => $asPlayer ? 'player' : null]));
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

    /** رابط موقَّع قصير العمر لمعاينة ملف من المكتبة داخل لوحة الإدارة (لا مسار تخزين مباشر أبدًا). */
    public function libraryUrl(?CinematicMediaAsset $asset): ?string
    {
        $user = auth()->user();

        if ($asset === null || $user === null || ! $asset->isReady() || ! ($this->can('media') || $this->can('preview'))) {
            return null;
        }

        return app(CinematicMediaLinks::class)->url($asset, CinematicMediaLinks::SCOPE_LIBRARY, CinematicMediaLinks::LIBRARY_ID, $user, true);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $story = $this->story();
        $revision = $this->revision();
        $tree = CinematicStage::query()->where('revision_id', $revision->id)->orderBy('position')
            ->with(['chapters' => fn ($q) => $q->orderBy('position'), 'chapters.scenes' => fn ($q) => $q->orderBy('position')->with(['video', 'poster', 'subtitle', 'audio'])])->get();
        $report = app(CinematicReadinessChecker::class)->check($revision);
        $bridge = app(CinematicPuzzleBridge::class);
        $columns = ['id', 'public_id', 'role', 'status', 'original_name', 'mime', 'size_bytes', 'duration_ms', 'width', 'height'];

        // قوائم الاختيار: الملفات الجاهزة + الملف المربوط حاليًا بالمشهد المفتوح (حتى لو سُحب) كي لا يختفي اختيار محفوظ من القائمة.
        $linkedNow = [];

        foreach ($tree as $stage) {
            foreach ($stage->chapters as $chapter) {
                foreach ($chapter->scenes as $scene) {
                    foreach (['video', 'poster', 'subtitle', 'audio'] as $role) {
                        if ($scene->{$role} !== null && $scene->public_id === $this->editingScene) {
                            $linkedNow[$role] = $scene->{$role}->id;
                        }
                    }
                }
            }
        }

        $assets = function (string $role) use ($columns, $linkedNow) {
            $query = CinematicMediaAsset::query()->where('role', $role)
                ->where(fn ($q) => $q->where('status', CinematicMediaAsset::STATUS_READY)->when(isset($linkedNow[$role]), fn ($qq) => $qq->orWhere('id', $linkedNow[$role])));

            return $query->orderByDesc('id')->limit(200)->get($columns);
        };

        $puzzles = $this->editingScene && in_array($this->sf['type'] ?? '', ['puzzle'], true)
            ? Puzzle::query()->where('is_active', true)->orderBy('title')->limit(300)->get()->filter(fn (Puzzle $p) => $bridge->ineligibleReason($p) === null)->values()
            : collect();

        // استعمال الوسائط داخل هذه النسخة: asset id => قائمة مشاهد. يكشف ربط فيديو واحد بمشهدين (يُعرض الفيديو مرتين) قبل الحفظ.
        $usage = [];
        $flat = [];
        $counts = ['scenes' => 0, 'video' => 0, 'video_ready' => 0, 'video_missing' => 0, 'interaction' => 0, 'interaction_no_image' => 0];

        foreach ($tree as $stage) {
            foreach ($stage->chapters as $chapter) {
                foreach ($chapter->scenes as $scene) {
                    $counts['scenes']++;
                    $flat[] = ['id' => $scene->public_id, 'key' => $scene->stable_key, 'title' => $scene->title, 'type' => $scene->type, 'stage' => $stage->title, 'chapter' => $chapter->title];

                    foreach (['video', 'poster', 'subtitle', 'audio'] as $role) {
                        if ($scene->{$role.'_asset_id'} !== null) {
                            $usage[$scene->{$role.'_asset_id'}][] = ['id' => $scene->public_id, 'title' => $scene->title, 'key' => $scene->stable_key];
                        }
                    }

                    if ($scene->type === CinematicScene::TYPE_VIDEO) {
                        $counts['video']++;
                        $scene->video !== null && $scene->video->isReady() ? $counts['video_ready']++ : $counts['video_missing']++;
                    } elseif (in_array($scene->type, [CinematicScene::TYPE_INTERACTION, CinematicScene::TYPE_EXPLORATION], true)) {
                        $counts['interaction']++;
                        $scene->poster === null || ! $scene->poster->isReady() ? $counts['interaction_no_image']++ : null;
                    }
                }
            }
        }

        $position = $this->editingScene ? array_search($this->editingScene, array_column($flat, 'id'), true) : false;
        $current = $position === false ? null : $flat[$position];
        $editing = null;
        $sceneModel = $this->editingScene ? CinematicScene::query()->where('public_id', $this->editingScene)->where('revision_id', $revision->id)->with(['video', 'poster', 'subtitle', 'audio'])->first() : null;

        if ($sceneModel !== null) {
            $pickedPoster = (string) ($this->sf['media']['poster'] ?? '');
            $posterAsset = $pickedPoster === '' ? null : CinematicMediaAsset::query()->where('public_id', $pickedPoster)->first();

            $editing = [
                'model' => $sceneModel,
                'stage' => $current['stage'] ?? '',
                'chapter' => $current['chapter'] ?? '',
                'prev' => $position > 0 ? $flat[$position - 1] : null,
                'next' => $position !== false && $position < count($flat) - 1 ? $flat[$position + 1] : null,
                'index' => $position === false ? 0 : $position + 1,
                'previewUrl' => $this->can('preview') ? $this->sceneUrl($sceneModel) : null,
                'playerPreviewUrl' => $this->can('preview') ? $this->sceneUrl($sceneModel, true) : null,
                'blockers' => array_values(array_filter($report['blockers'], fn ($b) => ($b['scene'] ?? null) === $sceneModel->stable_key)),
                'warnings' => array_values(array_filter($report['warnings'], fn ($b) => ($b['scene'] ?? null) === $sceneModel->stable_key)),
                // صورة المشهد المختارة (حتى قبل الحفظ) لأداة المعايرة البصرية: رابط موقَّع، وأبعادها الحقيقية من فحص الرفع.
                'imageUrl' => $this->libraryUrl($posterAsset),
                'imageWidth' => $posterAsset?->width,
                'imageHeight' => $posterAsset?->height,
                'uploadUrl' => $this->can('media') ? CinematicMediaLibraryPage::getUrl(['role' => ($this->sf['type'] ?? '') === 'video' ? 'video' : 'poster', 'story' => $story->public_id, 'revision' => $revision->public_id, 'scene' => $sceneModel->public_id]) : null,
            ];
        }

        return [
            'story' => $story,
            'revision' => $revision,
            'revisions' => CinematicRevision::query()->where('story_id', $story->id)->orderByDesc('revision_number')->get(),
            'editable' => $revision->isEditable(),
            'tree' => $tree,
            'report' => $report,
            'counts' => $counts,
            'flat' => $flat,
            'usage' => $usage,
            'editing' => $editing,
            'assets' => ['video' => $assets('video'), 'poster' => $assets('poster'), 'subtitle' => $assets('subtitle'), 'audio' => $assets('audio')],
            'puzzles' => $puzzles,
            'kinds' => ['hotspot' => 'نقطة ساخنة', 'container' => 'باب/درج بشرط', 'sequence' => 'ترتيب', 'drag_drop' => 'سحب وإفلات', 'choose_item' => 'اختيار عنصر', 'compare' => 'مقارنة', 'reveal_code' => 'كشف رمز بشرط', 'flashlight' => 'مصباح'],
            'types' => ['video' => 'فيديو', 'narrative' => 'سرد', 'puzzle' => 'أحجية', 'interaction' => 'تفاعل', 'exploration' => 'استكشاف', 'transition' => 'انتقال'],
            'statusLabels' => ['draft' => 'مسودة', 'in_review' => 'قيد المراجعة', 'published' => 'منشورة', 'superseded' => 'مستبدلة', 'unpublished' => 'مسحوبة'],
            'previewUrl' => $this->can('preview') ? route('cinematic.preview', $revision->public_id) : null,
            'playerPreviewUrl' => $this->can('preview') ? route('cinematic.preview', ['revision' => $revision->public_id, 'mode' => 'player']) : null,
            'publicUrl' => $story->liveRevision ? route('cinematic.show', $story->slug) : null,
            'listUrl' => CinematicStoriesPage::getUrl(),
            'mediaUrl' => CinematicMediaLibraryPage::getUrl(),
        ];
    }
}

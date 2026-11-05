<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicSceneState;
use App\Models\Cinematic\CinematicStory;
use App\Models\User;
use App\Services\Cinematic\Interactions\InteractionEngine;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CIN-1 - محرك اللعب: **كل قرار تقدّم يُتخذ هنا على الخادم**. لا يثق بأي ادعاء من العميل («شاهدت»، «حللت»، «أنا في المشهد كذا»).
 *
 *  - الفتح خطي: المشهد متاح فقط إذا اكتملت كل المشاهد **الإلزامية** السابقة (والاختياري لا يحجب) وتحققت شروط requires.
 *  - الإتمام مرة واحدة: unique(progress_id, scene_id) + completed_at لا يُكتب إلا من NULL، ومعاملة بقفل صف التقدّم ⇒ لا إتمام مزدوج ولو تكرر الطلب.
 *  - الفيديو: «شاهدته» ليست إثباتًا أمنيًا ولا تمنح شيئًا. المسارات: watched (بعد مرور نسبة من المدة على ساعة الخادم)، read (النص البديل)، skipped (إن سُمح).
 *  - الأحجية/التفاعل لا يُتمّان إلا بإجابة صحيحة يتحقق منها الخادم (حل التفاعل مشفّر خادميًا؛ الأحجية عبر validator محرك الأحجيات).
 *  - لا اقتصاد: لا جواهر ولا خبرة ولا محفظة من أي مسار هنا (الاختبارات تثبت أن الجداول الاقتصادية لا تتغير).
 *  - التقدّم مثبَّت على النسخة؛ الانتقال لنسخة أحدث صريح ويحمل فقط إتمامات المشاهد المتطابقة (stable_key + logic_hash).
 */
class CinematicPlayService
{
    public function __construct(
        protected CinematicAccess $access,
        protected InteractionEngine $engine,
        protected CinematicPuzzleBridge $puzzles,
    ) {}

    // ───────────────────────── التقدّم ─────────────────────────

    public function progressFor(User $user, CinematicStory $story): ?CinematicProgress
    {
        $rows = CinematicProgress::query()->where('user_id', $user->id)->where('story_id', $story->id)->orderByDesc('started_at')->orderByDesc('id')->get();
        $live = $this->access->liveRevision($story);

        return ($live ? $rows->firstWhere('revision_id', $live->id) : null)
            ?? $rows->firstWhere('status', CinematicProgress::STATUS_IN_PROGRESS)
            ?? $rows->first();
    }

    /** يبدأ أو يستأنف. (التفويض بالوصول مسؤولية المنادي عبر CinematicAccess::decide.) */
    public function begin(User $user, CinematicStory $story): CinematicProgress
    {
        $live = $this->access->liveRevision($story);

        if ($live === null) {
            throw CinematicException::make('unavailable', 'هذه السلسلة غير متاحة حاليًا.', 404);
        }

        if ($existing = $this->progressFor($user, $story)) {
            return $existing;
        }

        try {
            $progress = new CinematicProgress;
            $progress->forceFill(['user_id' => $user->id, 'story_id' => $story->id, 'revision_id' => $live->id, 'status' => CinematicProgress::STATUS_IN_PROGRESS, 'started_at' => now(), 'last_activity_at' => now()])->save();
        } catch (QueryException) {
            // سباق: طلب متزامن أنشأه أولًا (unique(user_id, story_id, revision_id)).
            return $this->progressFor($user, $story) ?? throw CinematicException::make('conflict', 'تعذّر بدء التقدّم، حاول مجددًا.', 409);
        }

        return $progress;
    }

    /**
     * انتقال صريح إلى النسخة المنشورة الحالية مع حمل الإتمامات المتطابقة فقط (نفس stable_key ونفس logic_hash). التقدّم القديم لا يُحذف ولا يُعدَّل.
     */
    public function upgrade(User $user, CinematicStory $story): CinematicProgress
    {
        $live = $this->access->liveRevision($story);

        if ($live === null) {
            throw CinematicException::make('unavailable', 'هذه السلسلة غير متاحة حاليًا.', 404);
        }

        $current = $this->progressFor($user, $story);

        if ($current === null) {
            return $this->begin($user, $story);
        }

        if ($current->revision_id === $live->id) {
            return $current;
        }

        return DB::transaction(function () use ($user, $story, $live, $current) {
            $old = CinematicProgress::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

            $already = CinematicProgress::query()->where('user_id', $user->id)->where('story_id', $story->id)->where('revision_id', $live->id)->first();

            if ($already) {
                return $already;
            }

            $new = new CinematicProgress;
            $new->forceFill(['user_id' => $user->id, 'story_id' => $story->id, 'revision_id' => $live->id, 'status' => CinematicProgress::STATUS_IN_PROGRESS, 'migrated_from_id' => $old->id, 'started_at' => now(), 'last_activity_at' => now()])->save();

            $newByKey = CinematicScene::query()->where('revision_id', $live->id)->get()->keyBy('stable_key');
            $oldScenes = CinematicScene::query()->where('revision_id', $old->revision_id)->get()->keyBy('id');

            foreach (CinematicSceneState::query()->where('progress_id', $old->id)->whereNotNull('completed_at')->get() as $state) {
                $oldScene = $oldScenes->get($state->scene_id);
                $target = $oldScene ? $newByKey->get($oldScene->stable_key) : null;

                if ($target === null || $target->logic_hash !== $oldScene->logic_hash || $target->type !== $oldScene->type) {
                    continue;
                }

                $row = new CinematicSceneState;
                $row->forceFill(['progress_id' => $new->id, 'scene_id' => $target->id, 'started_at' => $state->started_at, 'completed_at' => $state->completed_at, 'completion_mode' => CinematicSceneState::MODE_CARRIED])->save();
            }

            $this->refreshCompletion($new);

            return $new;
        });
    }

    // ───────────────────────── الحالة ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function state(CinematicProgress $progress): array
    {
        [$scenes, $statuses, $states] = $this->resolve($progress);
        $story = $progress->story ?? CinematicStory::query()->findOrFail($progress->story_id);
        $live = $this->access->liveRevision($story);
        $revisionNumber = (int) CinematicRevision::query()->whereKey($progress->revision_id)->value('revision_number');

        return $this->format($scenes, $statuses, $states, [
            'id' => $progress->public_id,
            'status' => $progress->status,
            'revision_number' => $revisionNumber,
            'completed_at' => $progress->completed_at?->toIso8601String(),
        ], $live !== null && $live->id !== $progress->revision_id);
    }

    /**
     * حالة «بلا تقدّم محفوظ» (قراءة فقط، لا تكتب شيئًا): المشاهد الأولى متاحة والباقي مقفل. تُستعمل قبل أول بدء فعلي (GET لا يكتب).
     *
     * @return array<string, mixed>
     */
    public function freshState(CinematicRevision $revision): array
    {
        [$scenes, $statuses, $states] = $this->resolveRevision($revision, null);

        return $this->format($scenes, $statuses, $states, null, false);
    }

    /** حالة المعاينة الإشرافية: كل المشاهد متاحة (بلا تقدّم ولا كتابة). @return array<string, mixed> */
    public function previewState(CinematicRevision $revision): array
    {
        [$scenes, , $states] = $this->resolveRevision($revision, null);
        $statuses = $scenes->mapWithKeys(fn (CinematicScene $s) => [$s->id => 'available'])->all();

        return $this->format($scenes, $statuses, $states, null, false);
    }

    /**
     * @param  Collection<int, CinematicScene>  $scenes
     * @param  array<int, string>  $statuses
     * @param  Collection<int, CinematicSceneState>  $states
     * @param  array<string, mixed>|null  $progress
     * @return array<string, mixed>
     */
    protected function format(Collection $scenes, array $statuses, Collection $states, ?array $progress, bool $upgradeAvailable): array
    {
        $required = $scenes->reject(fn (CinematicScene $s) => $this->optional($s));
        $doneRequired = $required->filter(fn (CinematicScene $s) => $statuses[$s->id] === 'completed')->count();
        $current = $scenes->first(fn (CinematicScene $s) => $statuses[$s->id] === 'available' && ! $this->optional($s))
            ?? $scenes->first(fn (CinematicScene $s) => $statuses[$s->id] === 'available');

        return [
            'progress' => $progress === null ? null : $progress + [
                'percent' => $required->count() === 0 ? 0 : (int) floor($doneRequired * 100 / $required->count()),
                'completed_scenes' => $doneRequired,
                'total_scenes' => $required->count(),
            ],
            'total_scenes' => $required->count(),
            'scenes' => $scenes->map(fn (CinematicScene $s) => [
                'id' => $s->public_id,
                'key' => $s->stable_key,
                'title' => $s->title,
                'type' => $s->type,
                'status' => $statuses[$s->id],
                'optional' => $this->optional($s),
                'skippable' => (bool) $s->is_skippable,
                'allow_replay' => (bool) $s->allow_replay,
                'stage' => ['id' => $s->stage->public_id, 'title' => $s->stage->title, 'position' => (int) $s->stage->position],
                'chapter' => ['id' => $s->chapter->public_id, 'title' => $s->chapter->title, 'position' => (int) $s->chapter->position],
                'attempts' => (int) ($states->get($s->id)?->attempts_count ?? 0),
            ])->values()->all(),
            'current' => $current?->public_id,
            'upgrade_available' => $upgradeAvailable,
        ];
    }

    // ───────────────────────── الإجراءات ─────────────────────────

    /** بدء مشهد (أو إعادة تشغيله إن كان مكتملًا ويسمح بالإعادة). @return array<string, mixed> */
    public function start(CinematicProgress $progress, CinematicScene $scene): array
    {
        return $this->locked($progress, $scene, function (CinematicProgress $p, CinematicScene $s, string $status) {
            if ($status === 'locked') {
                throw CinematicException::make('scene_locked', 'هذا المشهد مقفل بعد؛ أكمل ما قبله أولًا.', 403);
            }

            $state = $this->stateRow($p, $s);

            if ($status === 'completed') {
                if (! $s->allow_replay) {
                    throw CinematicException::make('replay_denied', 'لا تُسمح إعادة هذا المشهد.', 403);
                }

                $state->forceFill(['replay_count' => $state->replay_count + 1])->save();
            } elseif ($state->started_at === null) {
                $state->forceFill(['started_at' => now()])->save();
            }

            $p->forceFill(['current_scene_id' => $s->id, 'last_activity_at' => now()])->save();
        });
    }

    /** إنهاء مشهد سرد/فيديو/انتقال. mode: watched | read | skipped. @return array<string, mixed> */
    public function finish(CinematicProgress $progress, CinematicScene $scene, string $mode): array
    {
        return $this->locked($progress, $scene, function (CinematicProgress $p, CinematicScene $s, string $status) use ($mode) {
            if ($status === 'locked') {
                throw CinematicException::make('scene_locked', 'هذا المشهد مقفل بعد؛ أكمل ما قبله أولًا.', 403);
            }

            if ($status === 'completed') {
                return; // idempotent: لا إتمام مزدوج
            }

            $allowed = match ($s->type) {
                CinematicScene::TYPE_VIDEO => ['watched', 'read', 'skipped'],
                CinematicScene::TYPE_NARRATIVE, CinematicScene::TYPE_TRANSITION => ['read'],
                default => [],
            };

            if (! in_array($mode, $allowed, true)) {
                throw CinematicException::make('mode_not_allowed', 'هذا النوع من المشاهد لا يُنهى بهذه الطريقة.', 422);
            }

            $state = $this->stateRow($p, $s);

            if ($mode === 'skipped' && ! $s->is_skippable) {
                throw CinematicException::make('skip_denied', 'لا يُسمح بتخطي هذا المشهد.', 403);
            }

            if ($mode === 'read' && $s->type === CinematicScene::TYPE_VIDEO && ($s->config['transcript'] ?? []) === []) {
                throw CinematicException::make('no_text_alternative', 'لا يوجد نص بديل لهذا الفيديو.', 422);
            }

            if ($mode === 'watched') {
                $this->assertWatchedLongEnough($s, $state);
            }

            $this->complete($p, $s, $state, $mode === 'skipped' ? CinematicSceneState::MODE_SKIPPED : ($mode === 'read' ? CinematicSceneState::MODE_READ : CinematicSceneState::MODE_WATCHED));
        });
    }

    /**
     * إرسال إجابة لمشهد أحجية/تفاعل/استكشاف. التحقق بالكامل على الخادم.
     *
     * @param  array<string, mixed>  $submission
     * @return array<string, mixed> state + 'result'
     */
    public function submit(CinematicProgress $progress, CinematicScene $scene, array $submission): array
    {
        $result = [];

        $state = $this->locked($progress, $scene, function (CinematicProgress $p, CinematicScene $s, string $status) use ($submission, &$result) {
            if (! in_array($s->type, [CinematicScene::TYPE_PUZZLE, CinematicScene::TYPE_INTERACTION, CinematicScene::TYPE_EXPLORATION], true)) {
                throw CinematicException::make('mode_not_allowed', 'هذا المشهد لا يقبل إجابات.', 422);
            }

            if ($status === 'locked') {
                throw CinematicException::make('scene_locked', 'هذا المشهد مقفل بعد؛ أكمل ما قبله أولًا.', 403);
            }

            $state = $this->stateRow($p, $s);
            $completed = $status === 'completed';
            $max = $this->maxAttempts($s);

            if (! $completed && $max !== null && $state->attempts_count >= $max) {
                throw CinematicException::make('attempts_exhausted', 'استنفدت عدد المحاولات المسموح بها لهذا المشهد.', 403);
            }

            [$correct, $locked, $message, $reveal] = $this->evaluate($p, $s, $submission);

            if (! $completed) {
                $state->forceFill(['attempts_count' => $state->attempts_count + 1, 'last_attempt_at' => now(), 'started_at' => $state->started_at ?? now()])->save();

                if ($correct) {
                    $this->complete($p, $s, $state, $s->type === CinematicScene::TYPE_PUZZLE ? CinematicSceneState::MODE_SOLVED : CinematicSceneState::MODE_INTERACTED);
                }
            }

            $result = [
                'correct' => $correct,
                'locked' => $locked,
                'message' => $message,
                'reveal' => $reveal,
                'attempts_left' => $max === null ? null : max(0, $max - $state->attempts_count),
            ];
        });

        return $state + ['result' => $result];
    }

    // ───────────────────────── داخلي ─────────────────────────

    /**
     * ينفّذ إجراءً داخل معاملة بقفل صف التقدّم، بعد التحقق من أن المشهد من نسخة هذا التقدّم، ثم يُرجع الحالة الجديدة.
     *
     * @param  Closure(CinematicProgress, CinematicScene, string): mixed  $action
     * @return array<string, mixed>
     */
    protected function locked(CinematicProgress $progress, CinematicScene $scene, Closure $action): array
    {
        DB::transaction(function () use ($progress, $scene, $action) {
            $p = CinematicProgress::query()->whereKey($progress->id)->lockForUpdate()->firstOrFail();

            if ($scene->revision_id !== $p->revision_id) {
                throw CinematicException::make('scene_not_found', 'المشهد غير موجود.', 404);
            }

            $revision = CinematicRevision::query()->findOrFail($p->revision_id);

            if (! in_array($revision->status, [CinematicRevision::STATUS_PUBLISHED, CinematicRevision::STATUS_SUPERSEDED], true)) {
                throw CinematicException::make('unavailable', 'هذه السلسلة غير متاحة حاليًا.', 404);
            }

            [, $statuses] = $this->resolve($p);
            $action($p, $scene, $statuses[$scene->id] ?? 'locked');
        });

        return $this->state(CinematicProgress::query()->findOrFail($progress->id));
    }

    /**
     * @return array{0: Collection<int, CinematicScene>, 1: array<int, string>, 2: Collection<int, CinematicSceneState>}
     */
    protected function resolve(CinematicProgress $progress): array
    {
        return $this->resolveRevision(CinematicRevision::query()->findOrFail($progress->revision_id), $progress->id);
    }

    /**
     * @return array{0: Collection<int, CinematicScene>, 1: array<int, string>, 2: Collection<int, CinematicSceneState>}
     */
    protected function resolveRevision(CinematicRevision $revision, ?int $progressId): array
    {
        $scenes = CinematicStructure::scenes($revision);
        $states = $progressId === null ? collect() : CinematicSceneState::query()->where('progress_id', $progressId)->get()->keyBy('scene_id');
        $completedKeys = [];
        $statuses = [];
        $blocked = false;

        foreach ($scenes as $scene) {
            $done = $states->get($scene->id)?->completed_at !== null;

            if ($done) {
                $statuses[$scene->id] = 'completed';
                $completedKeys[] = $scene->stable_key;

                continue;
            }

            $requiresOk = array_diff((array) ($scene->unlock_rule['requires'] ?? []), $completedKeys) === [];
            $statuses[$scene->id] = (! $blocked && $requiresOk) ? 'available' : 'locked';

            if (! $this->optional($scene)) {
                $blocked = true;
            }
        }

        return [$scenes, $statuses, $states];
    }

    protected function optional(CinematicScene $scene): bool
    {
        return $scene->is_optional || $scene->chapter->is_optional || $scene->stage->is_optional;
    }

    protected function stateRow(CinematicProgress $progress, CinematicScene $scene): CinematicSceneState
    {
        $row = CinematicSceneState::query()->where('progress_id', $progress->id)->where('scene_id', $scene->id)->first();

        if ($row) {
            return $row;
        }

        $row = new CinematicSceneState;
        $row->forceFill(['progress_id' => $progress->id, 'scene_id' => $scene->id])->save();

        return $row;
    }

    protected function complete(CinematicProgress $progress, CinematicScene $scene, CinematicSceneState $state, string $mode): void
    {
        // الكتابة الشرطية: لا يُكتب الإتمام إلا إن كان NULL (حماية ثانية فوق قفل الصف).
        $updated = CinematicSceneState::query()->whereKey($state->id)->whereNull('completed_at')->update([
            'completed_at' => now(),
            'completion_mode' => $mode,
            'started_at' => $state->started_at ?? now(),
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            return;
        }

        $progress->forceFill(['current_scene_id' => $scene->id, 'last_activity_at' => now()])->save();
        $this->refreshCompletion($progress);
    }

    /** يكتمل التقدّم عند إتمام كل المشاهد الإلزامية في نسخته؛ completed_at يُكتب مرة واحدة فقط. */
    protected function refreshCompletion(CinematicProgress $progress): void
    {
        [$scenes, $statuses] = $this->resolve($progress);
        $required = $scenes->reject(fn (CinematicScene $s) => $this->optional($s));

        if ($required->isNotEmpty() && $required->every(fn (CinematicScene $s) => $statuses[$s->id] === 'completed')) {
            CinematicProgress::query()->whereKey($progress->id)->whereNull('completed_at')->update(['status' => CinematicProgress::STATUS_COMPLETED, 'completed_at' => now(), 'updated_at' => now()]);
            $progress->refresh();
        }
    }

    protected function assertWatchedLongEnough(CinematicScene $scene, CinematicSceneState $state): void
    {
        $seconds = $scene->video?->duration_ms ? $scene->video->duration_ms / 1000 : (float) ($scene->duration_hint_seconds ?? 0);

        if ($scene->video === null || ! $scene->video->isReady()) {
            throw CinematicException::make('no_media', 'الفيديو غير متاح حاليًا؛ يمكنك متابعة النص البديل.', 422);
        }

        $needed = min($seconds * (float) config('cinematic_stories.play.min_watch_ratio'), (float) config('cinematic_stories.play.min_watch_cap_seconds'));
        $elapsed = $state->started_at ? max(0, now()->diffInSeconds($state->started_at, true)) : 0;

        if ($elapsed + 1 < $needed) {
            throw CinematicException::make('too_early', 'لم تُشاهد المشهد بما يكفي بعد.', 422);
        }
    }

    protected function maxAttempts(CinematicScene $scene): ?int
    {
        $configured = $scene->config['max_attempts'] ?? null;

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        if ($scene->type !== CinematicScene::TYPE_PUZZLE || ! $scene->puzzle) {
            return null;
        }

        return (int) $scene->puzzle->max_attempts;
    }

    /**
     * @param  array<string, mixed>  $submission
     * @return array{0: bool, 1: bool, 2: ?string, 3: ?string}
     */
    protected function evaluate(CinematicProgress $progress, CinematicScene $scene, array $submission): array
    {
        $completedKeys = CinematicSceneState::query()
            ->where('cinematic_scene_states.progress_id', $progress->id)->whereNotNull('completed_at')
            ->join('cinematic_scenes', 'cinematic_scenes.id', '=', 'cinematic_scene_states.scene_id')
            ->pluck('cinematic_scenes.stable_key')->all();

        return $this->evaluateWith($scene, $submission, $completedKeys);
    }

    /**
     * تقييم نقي لإجابة (بلا كتابة أي حالة): يخدم اللعب الحقيقي والمعاينة الإشرافية معًا.
     *
     * @param  array<string, mixed>  $submission
     * @param  list<string>  $completedKeys
     * @return array{0: bool, 1: bool, 2: ?string, 3: ?string} [صحيح؟، مقفل بشرط؟، رسالة، رمز مكشوف]
     */
    public function evaluateWith(CinematicScene $scene, array $submission, array $completedKeys): array
    {
        if ($scene->type === CinematicScene::TYPE_PUZZLE) {
            $puzzle = $scene->puzzle;

            if ($this->puzzles->ineligibleReason($puzzle) !== null) {
                throw CinematicException::make('puzzle_unavailable', 'الأحجية غير متاحة حاليًا.', 422);
            }

            $answer = $submission['answer'] ?? '';
            $correct = is_scalar($answer) && $this->puzzles->check($puzzle, (string) $answer);

            return [$correct, false, $correct ? ($scene->config['success_message'] ?? null) : ($scene->config['wrong_message'] ?? 'ليست الإجابة الصحيحة، حاول مرة أخرى.'), null];
        }

        if (! in_array($scene->type, [CinematicScene::TYPE_INTERACTION, CinematicScene::TYPE_EXPLORATION], true)) {
            throw CinematicException::make('mode_not_allowed', 'هذا المشهد لا يقبل إجابات.', 422);
        }

        $public = (array) ($scene->config['interaction'] ?? []);
        $solution = CinematicSceneSolution::query()->where('scene_id', $scene->id)->first();

        if ($solution === null || ! isset($public['kind'])) {
            throw CinematicException::make('scene_unready', 'هذا المشهد غير جاهز.', 422);
        }

        $r = $this->engine->evaluate((string) $public['kind'], $public, (array) $solution->payload, $submission, $completedKeys);

        return [$r['correct'], $r['locked'], $r['message'], $r['reveal']];
    }
}

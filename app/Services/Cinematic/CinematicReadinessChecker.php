<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicSceneSolution;
use App\Models\Cinematic\CinematicStage;
use App\Services\Cinematic\Interactions\InteractionEngine;
use App\Services\Cinematic\Media\CinematicMediaService;

/**
 * CIN-1 - فحص جاهزية النسخة للنشر («مراجعة الوسائط الناقصة» وغيرها). blockers تمنع النشر؛ warnings تنبيهات لا تمنعه (ملصق/ترجمة/نص بديل).
 * لا يُنشر شيء إن كانت وسائط مطلوبة أو تفاعلات غير جاهزة: هذا هو الحاجز الذي يختبره CIN بعدم النشر الناقص.
 */
class CinematicReadinessChecker
{
    public function __construct(
        protected InteractionEngine $engine,
        protected CinematicPuzzleBridge $puzzles,
        protected CinematicMediaService $media,
    ) {}

    /**
     * @return array{ok: bool, blockers: list<array<string, mixed>>, warnings: list<array<string, mixed>>, counts: array<string, int>, checked_at: string}
     */
    public function check(CinematicRevision $revision): array
    {
        $blockers = [];
        $warnings = [];
        $add = function (string $severity, string $code, string $message, ?string $scene = null) use (&$blockers, &$warnings) {
            $row = ['code' => $code, 'message' => $message, 'scene' => $scene];
            $severity === 'blocker' ? $blockers[] = $row : $warnings[] = $row;
        };

        $story = $revision->story;
        $limits = (array) config('cinematic_stories.limits');
        $stages = CinematicStage::query()->where('revision_id', $revision->id)->orderBy('position')->get();
        $chapters = CinematicChapter::query()->where('revision_id', $revision->id)->get()->groupBy('stage_id');
        $scenes = CinematicStructure::scenes($revision);
        $scenesByChapter = $scenes->groupBy('chapter_id');
        $solutions = CinematicSceneSolution::query()->whereIn('scene_id', $scenes->pluck('id'))->get()->keyBy('scene_id');

        if ($story->isPremium() && ($story->creator_series_id === null || $story->series === null)) {
            $add('blocker', 'premium_series_missing', 'القصة مميزة بلا سلسلة مرتبطة؛ ملكية الوصول تُقرأ من السلسلة.');
        }

        if ($stages->isEmpty()) {
            $add('blocker', 'no_stages', 'لا توجد أي مرحلة.');
        }

        if ($stages->count() > (int) $limits['max_stages']) {
            $add('blocker', 'limit_stages', 'عدد المراحل يتجاوز الحد المضبوط.');
        }

        if ($scenes->count() > (int) $limits['max_scenes_per_revision']) {
            $add('blocker', 'limit_scenes', 'عدد المشاهد يتجاوز الحد المضبوط.');
        }

        foreach ($stages as $stage) {
            $stageChapters = $chapters->get($stage->id, collect());

            if ($stageChapters->isEmpty()) {
                $add('blocker', 'stage_empty', "المرحلة «{$stage->title}» بلا فصول.");
            }

            foreach ($stageChapters as $chapter) {
                if ($scenesByChapter->get($chapter->id, collect())->isEmpty()) {
                    $add('blocker', 'chapter_empty', "الفصل «{$chapter->title}» بلا مشاهد.");
                }
            }
        }

        if ($scenes->isNotEmpty() && $scenes->every(fn (CinematicScene $s) => $s->is_optional)) {
            $add('blocker', 'no_required_scene', 'كل المشاهد اختيارية؛ يلزم مشهد واحد إلزامي على الأقل.');
        }

        $seenKeys = [];

        foreach ($scenes as $scene) {
            $key = $scene->stable_key;

            foreach ((array) ($scene->unlock_rule['requires'] ?? []) as $required) {
                if (! in_array($required, $seenKeys, true)) {
                    $add('blocker', 'unlock_rule_invalid', "شرط الفتح في «{$scene->title}» يشير إلى مشهد غير موجود أو متأخر عنه ({$required}).", $key);
                }
            }

            $seenKeys[] = $key;

            match ($scene->type) {
                CinematicScene::TYPE_VIDEO => $this->checkVideo($scene, $add),
                CinematicScene::TYPE_NARRATIVE => $this->checkNarrative($scene, $add),
                CinematicScene::TYPE_TRANSITION => $this->checkTransition($scene, $add),
                CinematicScene::TYPE_PUZZLE => $this->checkPuzzle($scene, $add),
                default => $this->checkInteraction($scene, $solutions->get($scene->id), $add),
            };
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'counts' => ['stages' => $stages->count(), 'chapters' => $chapters->flatten()->count(), 'scenes' => $scenes->count()],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    protected function checkVideo(CinematicScene $scene, \Closure $add): void
    {
        $video = $scene->video;

        if ($video === null) {
            $add('blocker', 'video_missing', $scene->awaiting_media
                ? "مشهد «{$scene->title}» ينتظر إنتاج الفيديو."
                : "مشهد «{$scene->title}» بلا ملف فيديو.", $scene->stable_key);
        } elseif (! $video->isReady()) {
            $add('blocker', 'video_retired', "فيديو مشهد «{$scene->title}» مسحوب من المكتبة.", $scene->stable_key);
        } elseif ($this->media->absolutePath($video) === null) {
            $add('blocker', 'video_file_missing', "ملف فيديو «{$scene->title}» غير موجود على المخزن.", $scene->stable_key);
        }

        foreach (['poster' => 'ملصق', 'subtitle' => 'ترجمة', 'audio' => 'صوت'] as $role => $label) {
            $asset = $scene->{$role};

            if ($asset !== null && ! $asset->isReady()) {
                $add('blocker', "{$role}_retired", "{$label} مشهد «{$scene->title}» مسحوب من المكتبة.", $scene->stable_key);
            }
        }

        if ($scene->poster === null) {
            $add('warning', 'poster_missing', "مشهد «{$scene->title}» بلا ملصق (صورة مصغّرة).", $scene->stable_key);
        }

        if ($scene->subtitle === null) {
            $add('warning', 'subtitle_missing', "مشهد «{$scene->title}» بلا ترجمة WebVTT.", $scene->stable_key);
        }

        if (($scene->config['transcript'] ?? []) === []) {
            $add('warning', 'transcript_missing', "مشهد «{$scene->title}» بلا نص/حوار بديل للفيديو (إتاحة).", $scene->stable_key);
        }
    }

    protected function checkNarrative(CinematicScene $scene, \Closure $add): void
    {
        if (($scene->config['lines'] ?? []) === []) {
            $add('blocker', 'narrative_empty', "المشهد السردي «{$scene->title}» بلا نص.", $scene->stable_key);
        }
    }

    protected function checkTransition(CinematicScene $scene, \Closure $add): void
    {
        if (trim((string) ($scene->config['title'] ?? '')) === '') {
            $add('blocker', 'transition_empty', "شاشة الانتقال «{$scene->title}» بلا عنوان.", $scene->stable_key);
        }
    }

    protected function checkPuzzle(CinematicScene $scene, \Closure $add): void
    {
        $reason = $this->puzzles->ineligibleReason($scene->puzzle);

        if ($reason !== null) {
            $add('blocker', 'puzzle_invalid', "مشهد الأحجية «{$scene->title}»: {$reason}", $scene->stable_key);
        }
    }

    protected function checkInteraction(CinematicScene $scene, ?CinematicSceneSolution $solution, \Closure $add): void
    {
        $public = (array) ($scene->config['interaction'] ?? []);
        $kind = (string) ($public['kind'] ?? '');

        if ($kind === '') {
            $add('blocker', 'interaction_missing', "مشهد «{$scene->title}» بلا تعريف تفاعل.", $scene->stable_key);

            return;
        }

        if ($solution === null) {
            $add('blocker', 'solution_missing', "مشهد «{$scene->title}» بلا حل محفوظ.", $scene->stable_key);

            return;
        }

        foreach ($this->engine->validate($kind, $public, (array) $solution->payload) as $error) {
            $add('blocker', 'interaction_invalid', "مشهد «{$scene->title}»: {$error}", $scene->stable_key);
        }

        foreach ((array) ($solution->payload['requires_scenes'] ?? []) as $required) {
            if (! CinematicScene::query()->where('revision_id', $scene->revision_id)->where('stable_key', $required)->exists()) {
                $add('blocker', 'interaction_requires_unknown', "مشهد «{$scene->title}» يشترط مشهدًا غير موجود ({$required}).", $scene->stable_key);
            }
        }
    }
}

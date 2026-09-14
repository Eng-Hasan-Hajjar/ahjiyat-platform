<?php

namespace App\Services;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\CampaignStep;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CampaignPuzzleService
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected GameTypeRegistry $games,
        protected PuzzleAttemptService $attempts,
        protected GameSessionService $sessions,
        protected QualificationService $qualification,
    ) {}

    /**
     * @param  array<string, mixed>  $submission
     * @return array{attempt: \App\Models\PuzzleAttempt, correct: bool, gems_awarded: int, attempts_left: int}
     */
    public function attempt(
        User $user,
        CampaignStep $step,
        string $submittedAnswer,
        bool $usedHint,
        array $submission,
    ): array {
        $puzzle = $this->assertPuzzleStepReady($user, $step);

        if ($this->games->definitionFor($puzzle->game_type) instanceof GameSessionHandler) {
            throw new \RuntimeException('هذه الأحجية تتطلب بدء جلسة تفاعلية أولاً، لا إرسال إجابة مباشرة.');
        }

        // Reward Policy (inherit/override/none) تُحسم بالكامل داخل
        // PuzzleAttemptService عبر AttemptRewardResolver (C5) - يستخرجها من
        // نفس Context هذه تلقائياً. لا شيء يُمرَّر أو يُحسب هون إطلاقاً.
        $result = $this->attempts->attempt(
            $user,
            $puzzle,
            $submittedAnswer,
            $usedHint,
            $submission,
            AttemptContext::campaignStep($step->id),
        );

        if ($result['correct']) {
            $this->qualification->afterStepCompletion($user, $step);
        }

        return $result;
    }

    public function startSession(User $user, CampaignStep $step): GameSession
    {
        $puzzle = $this->assertPuzzleStepReady($user, $step);

        if (! $this->games->definitionFor($puzzle->game_type) instanceof GameSessionHandler) {
            throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
        }

        return $this->sessions->start($user, $puzzle, AttemptContext::campaignStep($step->id));
    }

    protected function assertPuzzleStepReady(User $user, CampaignStep $step): Puzzle
    {
        if ($step->kind !== CampaignStep::KIND_PUZZLE) {
            throw new \RuntimeException('هذه الخطوة ليست من نوع أحجية.');
        }

        $puzzle = $step->puzzle;

        if ($puzzle === null || ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

        if (! $this->progress->isStepUnlocked($user, $step)) {
            throw new AuthorizationException('هذه الخطوة مقفلة حالياً أو الحملة غير متاحة.');
        }

        return $puzzle;
    }
}
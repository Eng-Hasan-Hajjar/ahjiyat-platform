<?php

namespace App\Services;

use App\GameEngine\GameResult;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PuzzleAttemptService
{
    public function __construct(
        protected GemWalletService $wallet,
        protected FraudDetectionService $fraud,
        protected GameTypeRegistry $games,
    ) {}

    /**
     * @param  array<string, mixed>  $submission
     * @return array{attempt: PuzzleAttempt, correct: bool, gems_awarded: int, attempts_left: int}
     */
    public function attempt(
        User $user,
        Puzzle $puzzle,
        string $submittedAnswer,
        bool $usedHint = false,
        array $submission = [],
        ?AttemptContext $context = null,
    ): array {
        $context ??= AttemptContext::none();

        return DB::transaction(function () use ($user, $puzzle, $submittedAnswer, $usedHint, $submission, $context) {
            $previousAttempts = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->lockForUpdate()
                ->count();

            if ($previousAttempts >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            if ($user->hasSolvedPuzzle($puzzle, $context)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $payload = $submission !== [] ? $submission : ['answer' => $submittedAnswer];

            // مُلزم دائماً من المستخدم المُصادَق عليه فعلياً - أي قيمة مشابهة
            // يحاول العميل إرسالها ضمن submission تُستبدل هون بلا شروط. يُضاف
            // فقط لنسخة الـValidator - submission_snapshot المخزَّنة تبقى نسخة
            // نظيفة مطابقة تماماً لما أُرسل فعلياً (بدون بيانات داخلية مُحقنة).
            $validatorPayload = $payload + ['_context' => ['user_id' => $user->id]];

            $gameResult = $this->games->validatorFor($puzzle)->check($puzzle, $validatorPayload);
            $isCorrect = $gameResult->correct;

            $puzzleAttempt = PuzzleAttempt::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                'attempt_number' => $previousAttempts + 1,
                'is_correct' => $isCorrect,
                'used_hint' => $usedHint,
                'submission_snapshot' => $payload,
                ...$context->toAttributes(),
            ]);

            $gemsAwarded = 0;

            if ($isCorrect) {
                $gemsAwarded = $this->awardGemsForSolve($user, $puzzle);
                $this->updateChallengeScoresIfAny($user, $puzzle);
            }

            return [
                'attempt' => $puzzleAttempt,
                'correct' => $isCorrect,
                'gems_awarded' => $gemsAwarded,
                'attempts_left' => max(0, $puzzle->max_attempts - $puzzleAttempt->attempt_number),
            ];
        });
    }

    protected function awardGemsForSolve(User $user, Puzzle $puzzle): int
    {
        $dailyCap = config('gems.daily_earn_cap');
        $alreadyEarnedToday = $this->wallet->dailyEarnedToday($user);

        $rawReward = $this->games->scorerFor($puzzle)->calculate($puzzle, new GameResult(correct: true));
        $reward = min($rawReward, max(0, $dailyCap - $alreadyEarnedToday));

        if ($reward <= 0) {
            return 0;
        }

        $this->wallet->credit($user, $reward, "solved_puzzle:{$puzzle->id}", $puzzle);
        $this->fraud->evaluateAfterEarn($user);

        return $reward;
    }

    public function purchaseHint(User $user, Puzzle $puzzle): string
    {
        if (blank($puzzle->hint)) {
            throw new \RuntimeException('لا يوجد تلميح متاح لهذه الأحجية.');
        }

        $this->wallet->debitAvailable($user, (int) config('gems.hint_cost'), "hint:{$puzzle->id}", $puzzle);

        return $puzzle->hint;
    }

    protected function updateChallengeScoresIfAny(User $user, Puzzle $puzzle): void
    {
        $openChallenges = $puzzle->challenges()
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get();

        foreach ($openChallenges as $challenge) {
            $participant = $challenge->participants()->where('user_id', $user->id)->first();

            if ($participant) {
                $participant->increment('score');
            }
        }
    }
}
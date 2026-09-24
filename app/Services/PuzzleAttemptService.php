<?php

namespace App\Services;

use App\GameEngine\GameResult;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Facades\DB;

class PuzzleAttemptService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected CurrencyRegistry $currencies,
        protected FraudDetectionService $fraud,
        protected GameTypeRegistry $games,
        protected AttemptRewardResolver $rewards,
    ) {}

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
                $gemsAwarded = $this->awardGemsForSolve($user, $puzzle, $context);
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

    protected function awardGemsForSolve(User $user, Puzzle $puzzle, AttemptContext $context): int
    {
        $directive = $this->rewards->resolve($puzzle, $context);

        $currency = $directive->currency
            ?? $puzzle->rewardCurrency
            ?? $this->currencies->defaultEarnedCurrency();

        $isDefaultCurrency = $currency->is($this->currencies->defaultEarnedCurrency());

        $rawReward = $directive->useDefault
            ? $this->games->scorerFor($puzzle)->calculate($puzzle, new GameResult(correct: true))
            : $directive->amount;

        if ($isDefaultCurrency) {
            $dailyCap = config('gems.daily_earn_cap');
            $alreadyEarnedToday = $this->wallets->dailyEarnedToday($user, $currency);
            $reward = min($rawReward, max(0, $dailyCap - $alreadyEarnedToday));
        } else {
            $reward = $rawReward;
        }

        if ($reward <= 0) {
            return 0;
        }

        $this->wallets->creditPending($user, $currency, $reward, "solved_puzzle:{$puzzle->id}", $puzzle);

        $this->fraud->evaluateAfterEarn($user);

        return $reward;
    }

    public function purchaseHint(User $user, Puzzle $puzzle): string
    {
        if (blank($puzzle->hint)) {
            throw new \RuntimeException('لا يوجد تلميح متاح لهذه الأحجية.');
        }

        $this->wallets->debitAvailable(
            $user,
            $this->currencies->defaultEarnedCurrency(),
            (int) config('gems.hint_cost'),
            "hint:{$puzzle->id}",
            $puzzle,
        );

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
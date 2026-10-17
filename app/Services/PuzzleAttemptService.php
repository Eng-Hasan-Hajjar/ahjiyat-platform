<?php

namespace App\Services;

use App\GameEngine\GameResult;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\Currency;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use App\Services\Database\TransactionPolicy;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Progression\GameplayProgressionService;
use App\Services\Sponsorship\SponsorshipRewardService;
use Illuminate\Support\Facades\DB;

class PuzzleAttemptService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected CurrencyRegistry $currencies,
        protected FraudDetectionService $fraud,
        protected GameTypeRegistry $games,
        protected AttemptRewardResolver $rewards,
        protected GameplayProgressionService $progression,
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

        // E28: أحجيات المجتمع بلا اقتصاد/تقدّم بالبناء: لعبها يمرّ حصرًا بـCommunityPlayService (جدول لعب منفصل). هذه الخدمة تمنح جواهر وXP وتغذّي المهام والسلسلة،
        // فنرفض أي أحجية مجتمع هنا مهما كان السياق (حملة/جلسة/تنافس) كحاجز أخير لا يعتمد على فلترة المنادي.
        if ($puzzle->isCommunity()) {
            throw new \RuntimeException('هذه الأحجية غير متاحة عبر هذا المسار.');
        }

        // E26: المسار العام المباشر (بلا سياق) كان يقبل محاولات ومكافآت على أحجية معطَّلة/مسودة/مسحوبة لمن يخمّن معرّفها. السياقات (حملة/جلسة/تنافسي) تفحص
        // التفعيل بخدماتها (CampaignPuzzleService/GameSessionService/CompetitiveEligibility) فلا نغيّر سلوك جلسة جارية بقرار إداري لاحق.
        if (! $context->isPresent() && ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

        $result = DB::transaction(function () use ($user, $puzzle, $submittedAnswer, $usedHint, $submission, $context) {
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
            $sponsorBonus = 0;

            if ($isCorrect) {
                ['base' => $gemsAwarded, 'bonus' => $sponsorBonus] = $this->awardGemsForSolve($user, $puzzle, $context, (int) $puzzleAttempt->id);
                $this->updateChallengeScoresIfAny($user, $puzzle);
            }

            return [
                'attempt' => $puzzleAttempt,
                'correct' => $isCorrect,
                'gems_awarded' => $gemsAwarded,
                // E30: مكافأة الرعاية الإضافية (معاملة منفصلة، غالبًا 0). المكافأة الأساسية أعلاه لا تتغير أبدًا.
                'sponsor_bonus' => $sponsorBonus,
                'attempts_left' => max(0, $puzzle->max_attempts - $puzzleAttempt->attempt_number),
            ];
        }, TransactionPolicy::DEADLOCK_ATTEMPTS); // E31 (P): قفل نطاق فارغ ثم إدراج ← deadlock محتمل على MySQL عند نقر مزدوج؛ المعاملة كلها كتابات DB فإعادتها آمنة.

        if ($result['correct']) {
            $this->progression->afterPuzzleSolved($user, $result['attempt'], $puzzle, $context);
        }

        return $result;
    }

    /** @return array{base: int, bonus: int} */
    protected function awardGemsForSolve(User $user, Puzzle $puzzle, AttemptContext $context, int $attemptId = 0): array
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
            // E26: تسلسل قراءة السقف + الإيداع بقفل صف المحفظة (نحن داخل معاملة attempt()): طلبات متزامنة لأحجيات مختلفة كانت تتجاوز السقف اليومي معًا.
            $this->wallets->lockWallet($user, $currency);

            $dailyCap = config('gems.daily_earn_cap');
            $alreadyEarnedToday = $this->wallets->dailyEarnedToday($user, $currency);
            $reward = min($rawReward, max(0, $dailyCap - $alreadyEarnedToday));
        } else {
            $reward = $rawReward;
        }

        if ($reward <= 0) {
            return ['base' => 0, 'bonus' => 0];
        }

        $this->wallets->creditPending($user, $currency, $reward, "solved_puzzle:{$puzzle->id}", $puzzle);

        // E30: مكافأة الرعاية (إن وُجد برنامج تعزيز نشط منطبق) عبر البوابة المركزية الوحيدة: معاملة منفصلة، رسمي فقط، ضمن سقف اليوم، وفشلها معزول (لا يمسّ الأساس).
        $bonus = app(SponsorshipRewardService::class)->applyToSolve($user, $puzzle, $context, $currency, $reward, $attemptId);

        $this->fraud->evaluateAfterEarn($user);

        return ['base' => $reward, 'bonus' => $bonus];
    }

    public function purchaseHint(User $user, Puzzle $puzzle): string
    {
        // E26: لا تلميحات (ولا خصم جواهر) لأحجية غير مفعّلة. E28: ولا لأحجية مجتمع (تلميحها مجاني عبر CommunityPlayService).
        if ($puzzle->isCommunity() || ! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
        }

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
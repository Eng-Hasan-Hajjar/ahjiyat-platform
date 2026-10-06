<?php

namespace App\Services\Competitive;

use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\GameEngine\Support\TimedAttemptToken;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * اللعب التنافسي (تحدٍّ أو حدث) بمصدر حقيقة سيرفري كامل. جلسة = صف game_sessions بسياق تنافسي يربط (مستخدم، هدف، أحجية) بالسيرفر.
 *
 * عزل مطلق عن الاقتصاد: لا صفوف بـpuzzle_attempts ولا استدعاء لـPuzzleAttemptService (يمنح جواهر ويحدّث Challenge القديم ويستدعي التقدّم)
 * ولا لأي محفظة/XP/مهام/سلسلة. المتحقِّق نفسه (GameTypeRegistry) هو مصدر الصحة؛ والمدة بطوابع السيرفر (started_ms ثم الاستلام).
 *
 * محاولة واحدة: جلسة واحدة فقط لكل (مستخدم، سياق): الأقدم id هي الصالحة، وانتهاؤها نهائي. لا تُقبل قيمة من العميل غير الإجابة نفسها:
 * أي score/winner/rank/start_token/duration مُرسَل يُتجاهل (يُبنى payload المُتحقِّق من حالة السيرفر).
 */
class CompetitiveRunService
{
    public function __construct(
        protected GameTypeRegistry $games,
        protected CompetitiveScoringService $scoring,
        protected CompetitiveEligibility $eligibility,
    ) {}

    public function start(User $user, Puzzle $puzzle, AttemptContext $context, bool $requireActivePuzzle = true): GameSession
    {
        if (! $context->isCompetitive()) {
            throw new CompetitiveException('سياق غير تنافسي.');
        }

        if (($reason = $this->eligibility->reasonIfIneligible($puzzle, $requireActivePuzzle)) !== null) {
            throw new CompetitiveException($reason);
        }

        return DB::transaction(function () use ($user, $puzzle, $context) {
            $existing = $this->sessionsFor($user, $context)->lockForUpdate()->orderBy('id')->first();

            if ($existing !== null) {
                if ($existing->status === GameSession::STATUS_ACTIVE) {
                    return $existing; // استئناف نفس المحاولة (البدء الأصلي لا يتجدّد)
                }

                throw new CompetitiveException('أنهيت محاولتك الوحيدة هنا.');
            }

            $created = GameSession::create([
                'user_id' => $user->getKey(),
                'puzzle_id' => $puzzle->getKey(),
                ...$context->toAttributes(),
                'status' => GameSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => null,
                'server_state' => [
                    'competitive' => true,
                    'started_ms' => (int) now()->getPreciseTimestamp(3),
                    'start_token' => TimedAttemptToken::issue($puzzle, $user->getKey()),
                ],
            ]);

            // سباق بدءين متزامنين: يبقى الأقدم id وحده صالحًا.
            $first = $this->sessionsFor($user, $context)->orderBy('id')->first();

            if ($first->getKey() !== $created->getKey()) {
                $created->delete();

                return $first;
            }

            return $created;
        });
    }

    /** الجلسة النشطة الصالحة لهذا المستخدم بهذا السياق (أو null). */
    public function activeSession(User $user, AttemptContext $context): ?GameSession
    {
        $first = $this->sessionsFor($user, $context)->orderBy('id')->first();

        return $first !== null && $first->status === GameSession::STATUS_ACTIVE ? $first : null;
    }

    /**
     * ينهي الجلسة ويحسب الناتج. يُستدعى داخل معاملة المستدعي (الذي يكتب النتيجة بقيد UNIQUE في المعاملة نفسها).
     *
     * @param  array<string, mixed>  $clientInput  الإجابة فقط: answer وsubmission (غيرهما يُتجاهل)
     */
    public function finish(User $user, GameSession $session, Puzzle $puzzle, AttemptContext $context, array $clientInput): CompetitiveOutcome
    {
        $locked = GameSession::query()->lockForUpdate()->find($session->getKey());
        $first = $locked === null ? null : $this->sessionsFor($user, $context)->orderBy('id')->first();

        // ملكية الجلسة مضمونة بالبناء: sessionsFor() مقيَّد بالمستخدم، فجلسة غيره لا يمكن أن تكون "الأقدم" لهذا المستخدم.
        if ($locked === null || $first?->getKey() !== $locked->getKey()
            || ! $locked->isCompetitive() || $locked->puzzle_id !== $puzzle->getKey()
            || $locked->context_type !== $context->type || $locked->context_id !== $context->id
            || $locked->status !== GameSession::STATUS_ACTIVE) {
            throw new CompetitiveException('لا توجد محاولة نشطة صالحة لك هنا.');
        }

        $state = (array) $locked->server_state;
        $nowMs = (int) now()->getPreciseTimestamp(3);
        $durationMs = max(0, $nowMs - (int) ($state['started_ms'] ?? $nowMs));
        $limit = $puzzle->time_limit_seconds;
        $timedOut = $limit !== null && $durationMs > $limit * 1000;

        $correct = ! $timedOut && $this->games->validatorFor($puzzle)->check($puzzle, $this->payload($user, $clientInput, $state))->correct;
        $score = $this->scoring->score($correct, $durationMs, $this->scoring->capMs($puzzle));

        $locked->update([
            'status' => GameSession::STATUS_COMPLETED,
            'completed_at' => now(),
            'server_state' => $state + ['finished_ms' => $nowMs, 'correct' => $correct, 'duration_ms' => $durationMs],
        ]);

        return new CompetitiveOutcome($correct, $durationMs, $score, $timedOut, $locked);
    }

    /** يُغلق جلسة نشطة بلا نتيجة (مهلة انتهت). */
    public function expire(GameSession $session): void
    {
        if ($session->isCompetitive() && $session->status === GameSession::STATUS_ACTIVE) {
            $session->update(['status' => GameSession::STATUS_EXPIRED, 'completed_at' => now()]);
        }
    }

    protected function sessionsFor(User $user, AttemptContext $context)
    {
        return GameSession::query()->where('user_id', $user->getKey())
            ->where('context_type', $context->type)->where('context_id', $context->id);
    }

    /** @return array<string, mixed> payload المُتحقِّق: إجابة العميل + مفاتيح السيرفر (الأخيرة تغلب دائمًا) */
    protected function payload(User $user, array $clientInput, array $state): array
    {
        $submission = $clientInput['submission'] ?? [];
        $submission = is_array($submission) ? $submission : [];
        unset($submission['start_token'], $submission['_context']);

        $base = $submission !== [] ? $submission : ['answer' => (string) ($clientInput['answer'] ?? '')];

        return $base + [
            'start_token' => (string) ($state['start_token'] ?? ''),
            '_context' => ['user_id' => $user->getKey()],
        ];
    }
}

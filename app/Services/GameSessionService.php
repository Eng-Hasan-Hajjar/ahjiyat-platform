<?php

namespace App\Services;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * الأورشستريتور الوحيد لدورة حياة أي جلسة لعبة Stateful - عام تمامًا، لا
 * يعرف شيئًا عن spot_difference بالاسم (يفوّض التفاعل الفعلي لِـ
 * GameSessionHandler الخاص بنوع اللعبة). كل عملية حسم (Finalize) تمر
 * بمعاملة واحدة مع قفل صف، وتُنتج محاولة PuzzleAttempt واحدة بالضبط عبر
 * PuzzleAttemptService الموجود فعلياً - لا تكرار لمنطق المكافأة هون إطلاقاً.
 */
class GameSessionService
{
    public function __construct(
        protected GameTypeRegistry $games,
        protected PuzzleAttemptService $attempts,
    ) {}

    public function start(User $user, Puzzle $puzzle): GameSession
    {
        $definition = $this->games->definitionFor($puzzle->game_type);

        if (! $definition instanceof GameSessionHandler) {
            throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
        }

        if (! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير مُفعَّلة حالياً.');
        }

        return DB::transaction(function () use ($user, $puzzle, $definition) {
            $existing = GameSession::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('status', GameSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $existing->isExpired()) {
                    return $existing; // جلسة نشطة صالحة فعلاً - نعيد استخدامها بدل التكرار
                }

                $this->closeExpiredSession($existing);
            }

            if ($user->hasSolvedPuzzle($puzzle)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $attemptsUsed = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->count();

            if ($attemptsUsed >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            return GameSession::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                'context_type' => null,
                'context_id' => null,
                'status' => GameSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => $puzzle->time_limit_seconds
                    ? now()->addSeconds($puzzle->time_limit_seconds)
                    : null,
                'server_state' => $definition->initialServerState($puzzle),
            ]);
        });
    }

    /**
     * @return array{hit: bool, found: int, required: int, completed: bool, correct: ?bool, gems_awarded: ?int, session_status: string}
     */
    public function reveal(GameSession $session, float $x, float $y): array
    {
        return DB::transaction(function () use ($session, $x, $y) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                return $this->staleResponse($locked);
            }

            if ($locked->isExpired()) {
                $this->finalize($locked);

                return $this->staleResponse($locked->fresh());
            }

            $puzzle = $locked->puzzle;
            $definition = $this->games->definitionFor($puzzle->game_type);

            if (! $definition instanceof GameSessionHandler) {
                throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
            }

            $outcome = $definition->reveal($puzzle, $locked, $x, $y);

            $locked->update(['server_state' => ['found_indices' => $outcome['found_indices']]]);

            $response = [
                'hit' => $outcome['hit'],
                'found' => $outcome['found_count'],
                'required' => $outcome['required_count'],
                'completed' => false,
                'correct' => null,
                'gems_awarded' => null,
                'session_status' => $locked->status,
            ];

            if ($outcome['all_found']) {
                $finalized = $this->finalize($locked->fresh());
                $response['completed'] = true;
                $response['correct'] = $finalized['correct'];
                $response['gems_awarded'] = $finalized['gems_awarded'];
                $response['session_status'] = GameSession::STATUS_COMPLETED;
            }

            return $response;
        });
    }

    /**
     * الحسم النهائي - Idempotent بالكامل. يُستدعى إما تلقائياً من reveal()
     * عند اكتمال كل الفروق، أو عند اكتشاف انتهاء الوقت. لا يمنح مكافأة
     * مرتين مهما استُدعي، بفضل قفل الصف + فحص الحالة قبل أي تعديل.
     *
     * @return array{correct: bool, gems_awarded: int, already_finalized: bool}
     */
    public function finalize(GameSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                // مُنهاة مسبقاً (نجاحاً أو انتهاء وقت) - لا نكرر أي شيء
                $existingAttempt = PuzzleAttempt::where('game_session_id', $locked->id)->first();

                return [
                    'correct' => (bool) ($existingAttempt?->is_correct),
                    'gems_awarded' => 0,
                    'already_finalized' => true,
                ];
            }

            $newStatus = $locked->isExpired() ? GameSession::STATUS_EXPIRED : GameSession::STATUS_COMPLETED;

            $locked->update([
                'status' => $newStatus,
                'completed_at' => now(),
            ]);

            try {
                $result = $this->attempts->attempt(
                    $locked->user,
                    $locked->puzzle,
                    '',
                    false,
                    ['server_state' => $locked->server_state],
                    AttemptContext::none(),
                );
            } catch (\RuntimeException $e) {
                // استُنفدت المحاولات أصلاً أو سبق حلّها - الجلسة تبقى مُغلقة بلا مكافأة
                return ['correct' => false, 'gems_awarded' => 0, 'already_finalized' => false];
            }

            PuzzleAttempt::whereKey($result['attempt']->id)->update(['game_session_id' => $locked->id]);

            return [
                'correct' => $result['correct'],
                'gems_awarded' => $result['gems_awarded'],
                'already_finalized' => false,
            ];
        });
    }

    protected function closeExpiredSession(GameSession $session): void
    {
        $this->finalize($session);
    }

    /** @return array{hit: bool, found: int, required: int, completed: bool, correct: ?bool, gems_awarded: ?int, session_status: string} */
    protected function staleResponse(GameSession $session): array
    {
        return [
            'hit' => false,
            'found' => count((array) ($session->server_state['found_indices'] ?? [])),
            'required' => 0,
            'completed' => in_array($session->status, [GameSession::STATUS_COMPLETED, GameSession::STATUS_EXPIRED], true),
            'correct' => null,
            'gems_awarded' => null,
            'session_status' => $session->status,
        ];
    }
}
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

class GameSessionService
{
    public function __construct(
        protected GameTypeRegistry $games,
        protected PuzzleAttemptService $attempts,
    ) {}

    public function start(User $user, Puzzle $puzzle, ?AttemptContext $context = null): GameSession
    {
        $context ??= AttemptContext::none();

        $definition = $this->games->definitionFor($puzzle->game_type);

        if (! $definition instanceof GameSessionHandler) {
            throw new \RuntimeException('نوع هذه الأحجية لا يدعم الجلسات التفاعلية.');
        }

        if (! $puzzle->is_active) {
            throw new \RuntimeException('هذه الأحجية غير مُفعَّلة حالياً.');
        }

        return DB::transaction(function () use ($user, $puzzle, $definition, $context) {
            $existing = GameSession::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->where('status', GameSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $existing->isExpired()) {
                    return $existing;
                }

                $this->finalize($existing);
            }

            if ($user->hasSolvedPuzzle($puzzle, $context)) {
                throw new \RuntimeException('سبق أن حللت هذه الأحجية.');
            }

            $attemptsUsed = PuzzleAttempt::where('user_id', $user->id)
                ->where('puzzle_id', $puzzle->id)
                ->where('context_type', $context->type)
                ->where('context_id', $context->id)
                ->count();

            if ($attemptsUsed >= $puzzle->max_attempts) {
                throw new \RuntimeException('استنفدت عدد المحاولات المسموح بها لهذه الأحجية.');
            }

            return GameSession::create([
                'user_id' => $user->id,
                'puzzle_id' => $puzzle->id,
                ...$context->toAttributes(),
                'status' => GameSession::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => $puzzle->time_limit_seconds
                    ? now()->addSeconds($puzzle->time_limit_seconds)
                    : null,
                'server_state' => $definition->initialServerState($puzzle),
            ]);
        });
    }

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
                $response['session_status'] = $locked->fresh()->status;
            }

            return $response;
        });
    }

    public function finalize(GameSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $locked = GameSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== GameSession::STATUS_ACTIVE) {
                $existingAttempt = PuzzleAttempt::where('game_session_id', $locked->id)->first();

                return [
                    'correct' => (bool) ($existingAttempt?->is_correct),
                    'gems_awarded' => 0,
                    'already_finalized' => true,
                ];
            }

            $puzzle = $locked->puzzle;
            $definition = $this->games->definitionFor($puzzle->game_type);

            $genuinelySolved = ! $locked->isExpired()
                && $definition instanceof GameSessionHandler
                && $definition->isServerStateComplete($puzzle, (array) $locked->server_state);

            $locked->update([
                'status' => $genuinelySolved ? GameSession::STATUS_COMPLETED : GameSession::STATUS_EXPIRED,
                'completed_at' => now(),
            ]);

            $context = $locked->context_type
                ? AttemptContext::for($locked->context_type, $locked->context_id)
                : AttemptContext::none();

            try {
                $result = $this->attempts->attempt(
                    $locked->user,
                    $puzzle,
                    '',
                    false,
                    ['server_state' => $locked->server_state],
                    $context,
                );
            } catch (\RuntimeException $e) {
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
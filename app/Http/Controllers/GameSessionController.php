<?php

namespace App\Http\Controllers;

use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\RevealPuzzleSessionRequest;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Services\GameSessionService;
use Illuminate\Support\Facades\Auth;

class GameSessionController extends Controller
{
    public function __construct(
        protected GameSessionService $sessions,
        protected GameTypeRegistry $games,
    ) {}

    public function store(Puzzle $puzzle)
    {
        try {
            $session = $this->sessions->start(Auth::user(), $puzzle);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = $this->games->definitionFor($puzzle->game_type)->publicPayload($puzzle);

        return response()->json([
            'session_id' => $session->id,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'found' => count($session->server_state['found_indices'] ?? []),
            'required' => $payload['required_differences'] ?? 0,
        ]);
    }

    public function reveal(RevealPuzzleSessionRequest $request, GameSession $session)
    {
        abort_unless(Auth::user()->can('reveal', $session), 403);

        $result = $this->sessions->reveal(
            $session,
            (float) $request->input('x'),
            (float) $request->input('y')
        );

        return response()->json($result);
    }
}
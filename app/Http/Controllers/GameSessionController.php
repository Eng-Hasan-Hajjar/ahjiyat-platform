<?php

namespace App\Http\Controllers;

use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\AttemptContext;
use App\Http\Requests\RevealPuzzleSessionRequest;
use App\Models\CampaignStep;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Services\GameSessionService;
use App\Services\QualificationService;
use Illuminate\Support\Facades\Auth;

class GameSessionController extends Controller
{
    public function __construct(
        protected GameSessionService $sessions,
        protected GameTypeRegistry $games,
        protected QualificationService $qualification,
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

        // Hook C6: GameSessionService/GameSessionHandler يبقيان عامَّين تماماً،
        // لا يعرفان شيئاً عن Campaign أو Qualification. هذا الـController
        // العام هو الوحيد الذي يعرف Context، فيتحقق - فقط عند إكمال ناجح
        // ضمن سياق campaign_step - ثم يفوّض بالكامل لـQualificationService
        // (الذي بدوره Idempotent بذاته - آمن الاستدعاء بلا أي شرط إضافي هون).
        if ($result['completed'] && $result['correct'] && $session->context_type === AttemptContext::TYPE_CAMPAIGN_STEP) {
            $step = CampaignStep::find($session->context_id);

            if ($step !== null) {
                $this->qualification->afterStepCompletion(Auth::user(), $step);
            }
        }

        return response()->json($result);
    }
}
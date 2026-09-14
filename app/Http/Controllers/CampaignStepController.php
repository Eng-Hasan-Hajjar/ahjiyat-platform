<?php

namespace App\Http\Controllers;

use App\GameEngine\Contracts\GameSessionHandler;
use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\PuzzleAttempt;
use App\Services\CampaignNarrativeService;
use App\Services\CampaignProgressService;
use App\Services\CampaignPuzzleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Thin عمداً على طول الخط: كل Method يتحقق فقط من علاقة campaign↔step
 * (IDOR) ثم يفوّض بالكامل لخدمة النوع المناسبة. لا Validation، لا حساب
 * مكافأة، لا تلاعب بحالة Session هون - فقط علاقة/تفويض/استجابة.
 *
 * show()          -> C8، صفحة عرض الخطوة (GET) - يشتق الحالة فقط، لا يكتب شيئاً.
 * complete()      -> C3، narrative فقط.
 * attempt()       -> C4، Puzzle Stateless (نفس UX/أخطاء PuzzleController).
 * startSession()  -> C4، Puzzle Stateful (نفس UX/أخطاء GameSessionController).
 * لا reveal() هون إطلاقاً - المسار العام /game-sessions/{session}/reveal
 * يبقى الوحيد، لأن الـSession نفسها تحمل Context بعد B.1.
 */
class CampaignStepController extends Controller
{
    public function __construct(
        protected CampaignNarrativeService $narrative,
        protected CampaignPuzzleService $puzzle,
        protected CampaignProgressService $progress,
        protected GameTypeRegistry $games,
    ) {}

    public function show(Campaign $campaign, CampaignStep $step)
    {
        $this->assertStepBelongsToCampaign($campaign, $step);

        $user = Auth::user();

        // Server-side أولاً ودائماً (C8.11) - لا اعتماد على UI مخفي. خطوة
        // مقفلة لا تكشف حتى وجود محتواها (سردي مستقبلي أو غيره).
        if (! $this->progress->isStepUnlocked($user, $step)) {
            return view('campaigns.steps.locked', compact('campaign', 'step'));
        }

        if ($step->kind === CampaignStep::KIND_NARRATIVE) {
            $completed = $this->progress->isStepCompleted($user, $step);

            return view('campaigns.steps.narrative', compact('campaign', 'step', 'completed'));
        }

        $puzzle = $step->puzzle;
        $renderer = $this->games->rendererFor($puzzle);
        $usesGameSession = $this->games->definitionFor($puzzle->game_type) instanceof GameSessionHandler;
        $completed = $this->progress->isStepCompleted($user, $step);
        $attemptsUsed = PuzzleAttempt::where('user_id', $user->id)
            ->where('puzzle_id', $puzzle->id)
            ->where('context_type', 'campaign_step')
            ->where('context_id', $step->id)
            ->count();

        return view('campaigns.steps.puzzle', compact(
            'campaign', 'step', 'puzzle', 'renderer', 'usesGameSession', 'completed', 'attemptsUsed'
        ));
    }

    public function complete(Campaign $campaign, CampaignStep $step): RedirectResponse
    {
        $this->assertStepBelongsToCampaign($campaign, $step);

        try {
            $this->narrative->complete(Auth::user(), $step);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        // بعد الإكمال: الخطوة التالية المتاحة، أو صفحة الحملة إن لم توجد
        // (C8.6) - مُشتقّة الآن، لا next_step_id مخزَّن بأي مكان.
        $next = $this->progress->currentStepFor(Auth::user(), $campaign->fresh());

        $redirect = $next
            ? redirect()->route('campaigns.steps.show', [$campaign, $next])
            : redirect()->route('campaigns.show', $campaign);

        return $redirect->with('success', 'تم إكمال هذه الخطوة.');
    }

    public function attempt(SolvePuzzleRequest $request, Campaign $campaign, CampaignStep $step): RedirectResponse
    {
        $this->assertStepBelongsToCampaign($campaign, $step);

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->puzzle->attempt(
                Auth::user(),
                $step,
                (string) $request->input('answer', ''),
                $request->boolean('used_hint'),
                $submission,
            );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['correct']) {
            return back()->with('success', "إجابة صحيحة! حصلت على {$result['gems_awarded']} جوهرة معلقة.");
        }

        $message = $result['attempts_left'] > 0
            ? "إجابة غير صحيحة. تبقى لك {$result['attempts_left']} محاولة."
            : 'إجابة غير صحيحة. لقد استنفدت محاولاتك لهذه الأحجية.';

        return back()->with('error', $message);
    }

    public function startSession(Campaign $campaign, CampaignStep $step): JsonResponse
    {
        $this->assertStepBelongsToCampaign($campaign, $step);

        try {
            $session = $this->puzzle->startSession(Auth::user(), $step);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = $this->games->definitionFor($step->puzzle->game_type)->publicPayload($step->puzzle);

        return response()->json([
            'session_id' => $session->id,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'found' => count($session->server_state['found_indices'] ?? []),
            'required' => $payload['required_differences'] ?? 0,
        ]);
    }

    protected function assertStepBelongsToCampaign(Campaign $campaign, CampaignStep $step): void
    {
        abort_unless($step->gate->stage->campaign_id === $campaign->id, 404);
    }
}
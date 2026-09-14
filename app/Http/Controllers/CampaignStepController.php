<?php

namespace App\Http\Controllers;

use App\GameEngine\GameTypeRegistry;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Services\CampaignNarrativeService;
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
 * complete()      -> C3، narrative فقط، بلا تغيير.
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
        protected GameTypeRegistry $games,
    ) {}

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

        return back()->with('success', 'تم إكمال هذه الخطوة.');
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
            // مقفلة/الحملة غير متاحة - حالة لا يفترض أن يصلها مستخدم يتّبع
            // واجهة تحترم Unlock فعلياً، فنعاملها كـC3 بالضبط: Abort صريح.
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            // بقية أخطاء النطاق (نوع خاطئ، أحجية غير مفعَّلة، سبق حلّها،
            // استُنفدت المحاولات...) تُعامَل بنفس UX الأحجية المستقلة تماماً -
            // Redirect + رسالة، لا Abort قاسٍ لكل هذه الحالات.
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

        // نفس سطر GameSessionController::store() حرفياً - لا نسخ منطق
        // publicPayload، فقط استدعاء نفس الـDefinition الموجودة أصلاً.
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
        // IDOR: لا نثق بمعرّف step وحده - يجب أن تنتمي فعلياً لنفس campaign
        // بالـURL عبر step→gate→stage→campaign_id، وليس عبر أي حقل يرسله العميل.
        abort_unless($step->gate->stage->campaign_id === $campaign->id, 404);
    }
}
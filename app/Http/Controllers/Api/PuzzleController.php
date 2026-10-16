<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SolvePuzzleRequest;
use App\GameEngine\GameTypeRegistry;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Services\PuzzleAttemptService;
use Illuminate\Http\Request;

class PuzzleController extends Controller
{
    public function __construct(protected PuzzleAttemptService $attempts) {}

    public function categories()
    {
        return PuzzleCategory::where('is_active', true)->orderBy('sort_order')->get();
    }

    public function index(Request $request)
    {
        return Puzzle::query()
            ->where('is_active', true)
            ->when($request->category_id, fn ($q) => $q->where('puzzle_category_id', $request->category_id))
            ->select(['id', 'puzzle_category_id', 'title', 'type', 'game_type', 'difficulty', 'gem_reward'])
            ->paginate(20);
    }

    public function show(Puzzle $puzzle, GameTypeRegistry $games)
    {
        // E26: (1) غير المفعّلة 404 كالقائمة. (2) game_config الخام كان يسرّب ترتيب الإجابة الصحيح للترتيب (عناصر بالترتيب الصحيح) وأزواج بطاقات الذاكرة؛
        // نُعيد حمولة النوع العامة فقط (publicPayload: عناصر مخلوطة برموز معتمة، بلا solution_data/hotspots) ونحتفظ بالمفتاح game_config للتوافق.
        abort_unless($puzzle->is_active && ! $puzzle->isCommunity(), 404);

        return array_merge($puzzle->only([
            'id', 'title', 'type', 'difficulty', 'prompt', 'choices',
            'image_path', 'max_attempts', 'time_limit_seconds', 'gem_reward',
            'game_type', 'renderer',
        ]), [
            'game_config' => $this->publicGamePayload($puzzle, $games),
        ]);
    }

    /** حمولة النوع العامة؛ نوع غير مسجَّل (بيانات قديمة) = لا حمولة بدل خطأ 500. */
    protected function publicGamePayload(Puzzle $puzzle, GameTypeRegistry $games): ?array
    {
        try {
            return $games->definitionFor($puzzle->game_type)->publicPayload($puzzle);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function attempt(SolvePuzzleRequest $request, Puzzle $puzzle)
    {
        abort_if($puzzle->isCommunity(), 404);

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->attempts->attempt(
                $request->user(),
                $puzzle,
                (string) $request->input('answer', ''),
                $request->boolean('used_hint'),
                $submission
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
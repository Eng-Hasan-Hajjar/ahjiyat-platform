<?php

namespace App\Http\Controllers;

use App\Models\PuzzleLabExercise;
use App\Services\PuzzleLab\PuzzleLabRounds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * E47 - «مختبر الأحجيات»: صفحات التدريب وجولاته.
 *
 * هذا المتحكّم لا يستدعي أي خدمة جواهر/XP/محاولات/تقدّم، ولا يكتب في قاعدة البيانات إطلاقًا (قراءة التمارين فقط).
 * الوصول: كل المسارات خلف auth+verified+account.active (انظر routes/puzzle_lab.php). التمرين غير المنشور 404 للاعب؛ المعاينة بصلاحية puzzles.view.
 */
class PuzzleLabController extends Controller
{
    public function __construct(protected PuzzleLabRounds $rounds) {}

    public function index(): View
    {
        $exercises = PuzzleLabExercise::query()->published()->ordered()->get()
            ->filter(fn (PuzzleLabExercise $exercise) => $exercise->isPlayable())
            ->values();

        $sections = collect(PuzzleLabExercise::TYPES)->map(fn (string $label, string $type) => [
            'type' => $type,
            'label' => $label,
            'blurb' => PuzzleLabExercise::TYPE_BLURBS[$type] ?? '',
            'exercises' => $exercises->where('game_type', $type)->values(),
        ])->values();

        return view('puzzle-lab.index', ['sections' => $sections, 'total' => $exercises->count()]);
    }

    public function show(PuzzleLabExercise $exercise): View
    {
        abort_unless($exercise->isPlayable(), 404);

        return view('puzzle-lab.show', ['exercise' => $exercise, 'preview' => false, 'problems' => []]);
    }

    /** معاينة إدارية لأي حالة (مسودة/مخفي/منشور). اللاعب بلا الصلاحية يرى 404 فلا يُكشف وجود المسودة. */
    public function preview(Request $request, PuzzleLabExercise $exercise): View
    {
        abort_unless($request->user()?->can('preview', $exercise), 404);

        return view('puzzle-lab.show', ['exercise' => $exercise, 'preview' => true, 'problems' => $exercise->contentProblems()]);
    }

    public function start(Request $request, PuzzleLabExercise $exercise): JsonResponse
    {
        $this->authorizeRound($request, $exercise);

        return $this->json($this->rounds->start($exercise, $request->user()));
    }

    public function flip(Request $request, PuzzleLabExercise $exercise): JsonResponse
    {
        $this->authorizeRound($request, $exercise);

        $face = $this->rounds->flip($exercise, $request->user(), $request->input('round'), $request->input('card'));

        return $face === null ? $this->invalid() : $this->json($face);
    }

    public function check(Request $request, PuzzleLabExercise $exercise): JsonResponse
    {
        $this->authorizeRound($request, $exercise);

        $result = $this->rounds->check($exercise, $request->user(), $request->input('round'), $request->only(['matches', 'order']));

        return $result === null ? $this->invalid() : $this->json($result);
    }

    /**
     * جولة اللاعب: تمرين منشور وصالح فقط. الطاقم (puzzles.view) يجرّب المسودات بالمعاينة نفسها؛ ومحتوى غير مكتمل لا تُبنى له جولة حتى للطاقم.
     */
    protected function authorizeRound(Request $request, PuzzleLabExercise $exercise): void
    {
        if ($exercise->isPlayable()) {
            return;
        }

        abort_unless($request->user()?->can('preview', $exercise), 404);
        abort_if($exercise->contentProblems() !== [], 422, 'محتوى التمرين غير مكتمل.');
    }

    protected function invalid(): JsonResponse
    {
        return $this->json(['message' => 'انتهت هذه الجولة أو لم تعد صالحة. ابدأ جولة جديدة.'], 422);
    }

    /** لا تُخزَّن ردود الجولات في أي ذاكرة وسيطة (تحوي أوجه بطاقات/رموز خاصة بالجولة). */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}

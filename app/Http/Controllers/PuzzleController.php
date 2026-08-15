<?php

namespace App\Http\Controllers;

use App\Http\Requests\SolvePuzzleRequest;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Services\PuzzleAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PuzzleController extends Controller
{
    public function __construct(protected PuzzleAttemptService $attempts) {}

    public function index(PuzzleCategory $category = null)
    {
        $query = Puzzle::query()->where('is_active', true)->with('category');

        if ($category) {
            $query->where('puzzle_category_id', $category->id);
        }

        $puzzles = $query->latest()->paginate(12);
        $categories = PuzzleCategory::where('is_active', true)->orderBy('sort_order')->get();

        return view('puzzles.index', compact('puzzles', 'categories', 'category'));
    }

    public function show(Puzzle $puzzle)
    {
        $user = Auth::user();

        $attemptsUsed = $user
            ? $puzzle->attempts()->where('user_id', $user->id)->count()
            : 0;

        $alreadySolved = $user && $user->hasSolvedPuzzle($puzzle);

        return view('puzzles.show', compact('puzzle', 'attemptsUsed', 'alreadySolved'));
    }

    public function attempt(SolvePuzzleRequest $request, Puzzle $puzzle): RedirectResponse
    {
        try {
            $result = $this->attempts->attempt(
                Auth::user(),
                $puzzle,
                $request->string('answer'),
                $request->boolean('used_hint')
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['correct']) {
            return redirect()
                ->route('puzzles.show', $puzzle)
                ->with('success', "إجابة صحيحة! حصلت على {$result['gems_awarded']} جوهرة معلقة.");
        }

        $message = $result['attempts_left'] > 0
            ? "إجابة غير صحيحة. تبقى لك {$result['attempts_left']} محاولة."
            : 'إجابة غير صحيحة. لقد استنفدت محاولاتك لهذه الأحجية.';

        return back()->with('error', $message);
    }

    public function hint(Puzzle $puzzle): RedirectResponse
    {
        try {
            $hint = $this->attempts->purchaseHint(Auth::user(), $puzzle);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('hint', $hint);
    }
}

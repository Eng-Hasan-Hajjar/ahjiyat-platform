<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SolvePuzzleRequest;
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
            ->select(['id', 'puzzle_category_id', 'title', 'type', 'difficulty', 'gem_reward'])
            ->paginate(20);
    }

    public function show(Puzzle $puzzle)
    {
        return $puzzle->only([
            'id', 'title', 'type', 'difficulty', 'prompt', 'choices',
            'image_path', 'max_attempts', 'time_limit_seconds', 'gem_reward',
        ]);
    }

    public function attempt(SolvePuzzleRequest $request, Puzzle $puzzle)
    {
        try {
            $result = $this->attempts->attempt(
                $request->user(),
                $puzzle,
                $request->string('answer'),
                $request->boolean('used_hint')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;

class HomeController extends Controller
{
    public function index()
    {
        $dailyPuzzle = Puzzle::query()
            ->where('is_daily_puzzle', true)
            ->whereDate('daily_puzzle_date', today())
            ->where('is_active', true)
            ->first();

        $categories = PuzzleCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->withCount('puzzles')
            ->get();

        return view('home', compact('dailyPuzzle', 'categories'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index()
    {
        $topUsers = User::query()
            ->select('users.id', 'users.name')
            ->selectRaw('COUNT(puzzle_attempts.id) as solved_count')
            ->join('puzzle_attempts', 'puzzle_attempts.user_id', '=', 'users.id')
            ->where('puzzle_attempts.is_correct', true)
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('solved_count')
            ->limit(50)
            ->get();

        return view('leaderboard.index', compact('topUsers'));
    }
}

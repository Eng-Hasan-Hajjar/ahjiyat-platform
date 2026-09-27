<?php

namespace App\Http\Controllers;

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index()
    {
        $topUsers = User::query()
            ->select('users.id', 'users.name', 'users.public_id', 'users.profile_visibility')
            ->selectRaw('COUNT(puzzle_attempts.id) as solved_count')
            ->join('puzzle_attempts', 'puzzle_attempts.user_id', '=', 'users.id')
            ->where('puzzle_attempts.is_correct', true)
            ->groupBy('users.id', 'users.name', 'users.public_id', 'users.profile_visibility')
            ->orderByDesc('solved_count')
            ->limit(50)
            ->get();

        $loadoutsByUser = UserCosmeticLoadout::whereIn('user_id', $topUsers->pluck('id'))
            ->with('storeItem')
            ->get()
            ->groupBy('user_id');

        $viewer = Auth::user();

        $topUsers->each(function (User $user) use ($loadoutsByUser, $viewer) {
            $rows = ($loadoutsByUser->get($user->id) ?? collect())->keyBy('slot');

            $user->identityAvatar = $rows->get(StoreItem::SLOT_AVATAR)?->storeItem;
            $user->identityFrame = $rows->get(StoreItem::SLOT_FRAME)?->storeItem;
            $user->identityTitle = $rows->get(StoreItem::SLOT_TITLE)?->storeItem;

            $user->profileLinkable = $user->profileViewableBy($viewer);
        });

        return view('leaderboard.index', compact('topUsers'));
    }
}
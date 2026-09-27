<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\PlayerIdentity\PlayerProfileService;
use Illuminate\Support\Facades\Auth;

class PlayerProfileController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected PlayerProfileService $stats,
    ) {}

    public function show(User $user)
    {
        abort_unless($user->profileViewableBy(Auth::user()), 404);

        $loadout = $this->loadouts->loadoutFor($user);
        $stats = $this->stats->safeStatsFor($user);

        return view('players.show', [
            'player' => $user,
            'loadout' => $loadout,
            'stats' => $stats,
            'isOwner' => Auth::check() && Auth::id() === $user->id,
        ]);
    }
}
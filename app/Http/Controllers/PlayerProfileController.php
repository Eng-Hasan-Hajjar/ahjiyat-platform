<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\PlayerIdentity\PlayerProfileService;
use App\Services\Social\FriendshipService;
use Illuminate\Support\Facades\Auth;

class PlayerProfileController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected PlayerProfileService $stats,
        protected FriendshipService $friendships,
    ) {}

    public function show(User $user)
    {
        abort_unless($user->profileViewableBy(Auth::user()), 404);

        $loadout = $this->loadouts->loadoutFor($user);
        $stats = $this->stats->safeStatsFor($user);

        $viewer = Auth::user();
        $isOwner = $viewer !== null && $viewer->id === $user->id;

        return view('players.show', [
            'player' => $user,
            'loadout' => $loadout,
            'stats' => $stats,
            'isOwner' => $isOwner,
            // E16: حالة العلاقة تُعرض للمسجَّل الموثَّق فقط (وليست لصاحب الملف)؛ وعدد الأصدقاء رقم مجمَّع بلا هويات.
            'friendRelation' => ($viewer !== null && ! $isOwner && $viewer->hasVerifiedEmail()) ? $this->friendships->relationBetween($viewer, $user) : null,
            'friendsCount' => $this->friendships->friendsCount($user),
        ]);
    }
}
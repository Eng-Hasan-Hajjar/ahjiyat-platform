<?php

namespace App\Http\Controllers;

use App\Models\StoreItem;
use App\Models\User;
use App\Services\LeaderboardService;
use App\Models\UserCosmeticLoadout;
use App\Services\Social\FriendshipService;
use Illuminate\Support\Facades\Auth;

class LeaderboardController extends Controller
{
    public function index(FriendshipService $friendships, LeaderboardService $leaderboard)
    {
        $viewer = Auth::user();

        // E16: scope=friends = أنا + أصدقائي المقبولون فقط (لا pending ولا محظور ولا مُزال). مجرد تصفية: لا تغيير بالخوارزمية ولا النقاط.
        $scope = ($viewer !== null && request()->query('scope') === 'friends') ? 'friends' : 'global';
        $friendScopeIds = $scope === 'friends' ? [...$friendships->friendIds($viewer), $viewer->id] : null;

        // E24: الاستعلام الثقيل (O(كل المحاولات)) انتقل إلى LeaderboardService مع Top-N مخزَّن لفترة قصيرة؛ المعيار والعدد 50 كما كانا.
        $topUsers = $leaderboard->topSolvers($friendScopeIds, 50);

        $loadoutsByUser = UserCosmeticLoadout::whereIn('user_id', $topUsers->pluck('id'))
            ->with('storeItem')
            ->get()
            ->groupBy('user_id');

        $topUsers->each(function (User $user) use ($loadoutsByUser, $viewer) {
            $rows = ($loadoutsByUser->get($user->id) ?? collect())->keyBy('slot');

            $user->identityAvatar = $rows->get(StoreItem::SLOT_AVATAR)?->storeItem;
            $user->identityFrame = $rows->get(StoreItem::SLOT_FRAME)?->storeItem;
            $user->identityTitle = $rows->get(StoreItem::SLOT_TITLE)?->storeItem;

            $user->profileLinkable = $user->profileViewableBy($viewer);
        });

        return view('leaderboard.index', compact('topUsers', 'scope'));
    }
}
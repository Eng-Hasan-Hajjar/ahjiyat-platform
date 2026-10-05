<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserBlock;
use App\Services\Social\FriendshipService;
use App\Services\Social\PlayerCardLoader;
use Illuminate\Http\Request;

/** صفحة الأصدقاء (الأصدقاء، الطلبات الواردة والصادرة، المحظورون، إعداد استقبال الطلبات). رفيع: كل المنطق بالخدمات. قائمة الأصدقاء خاصة بصاحبها. */
class FriendsController extends Controller
{
    public function index(Request $request, FriendshipService $friendships, PlayerCardLoader $cards)
    {
        $user = $request->user();

        $friends = $friendships->friendsQuery($user)->paginate((int) config('friends.friends_per_page', 20));
        $incoming = $friendships->incoming($user);
        $outgoing = $friendships->outgoing($user);
        $blocked = User::query()->select('id', 'name', 'public_id', 'profile_visibility')
            ->whereIn('id', UserBlock::query()->where('blocker_id', $user->id)->select('blocked_id'))->orderBy('name')->get();

        $cards->attach($friends->getCollection()->concat($incoming->pluck('requester'))->concat($outgoing->pluck('addressee'))->concat($blocked), $user);

        return view('friends.index', [
            'friends' => $friends,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'blocked' => $blocked,
            'requestsEnabled' => $user->friend_requests_enabled !== false,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate(['friend_requests_enabled' => ['required', 'boolean']]);

        // لا Mass Assignment: حقل واحد مُتحقَّق منه، والمستخدم هو المصادَق فقط.
        $request->user()->forceFill(['friend_requests_enabled' => $request->boolean('friend_requests_enabled')])->save();

        return back()->with('success', 'تم حفظ إعداد طلبات الصداقة.');
    }
}

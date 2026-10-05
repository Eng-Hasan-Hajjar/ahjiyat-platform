<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Social\BlockService;
use Illuminate\Http\Request;

/** حظر/إلغاء حظر. الحاظر هو المستخدم المصادَق دائمًا؛ الهدف من المسار بـpublic_id. لا إخبار للمحظور. */
class FriendBlockController extends Controller
{
    public function store(Request $request, User $user, BlockService $blocks)
    {
        $result = $blocks->block($request->user(), $user);

        return back()->with($result->isSuccess() ? 'success' : 'error', $result->message());
    }

    public function destroy(Request $request, User $user, BlockService $blocks)
    {
        $result = $blocks->unblock($request->user(), $user);

        return back()->with($result->isSuccess() ? 'success' : 'error', $result->message());
    }
}

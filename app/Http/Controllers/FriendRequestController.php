<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Social\FriendActionResult;
use App\Services\Social\FriendshipService;
use Illuminate\Http\Request;

/**
 * إرسال/قبول/رفض/إلغاء الطلبات + إزالة صديق. المصدر الوحيد للطرف الحالي هو المستخدم المصادَق؛ الطرف الآخر من المسار بـpublic_id
 * (لا user_id من النموذج ولا معرّف علاقة)، فلا IDOR ممكن: المستخدم لا يؤثر إلا على علاقته هو.
 */
class FriendRequestController extends Controller
{
    public function __construct(protected FriendshipService $friendships) {}

    public function store(Request $request, User $user)
    {
        return $this->respond($this->friendships->sendRequest($request->user(), $user));
    }

    public function accept(Request $request, User $user)
    {
        return $this->respond($this->friendships->accept($request->user(), $user));
    }

    public function decline(Request $request, User $user)
    {
        return $this->respond($this->friendships->decline($request->user(), $user));
    }

    public function cancel(Request $request, User $user)
    {
        return $this->respond($this->friendships->cancel($request->user(), $user));
    }

    public function removeFriend(Request $request, User $user)
    {
        return $this->respond($this->friendships->remove($request->user(), $user));
    }

    protected function respond(FriendActionResult $result)
    {
        return back()->with($result->isSuccess() ? 'success' : 'error', $result->message());
    }
}

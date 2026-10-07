<?php

namespace App\Http\Controllers\Chat;

use App\Services\Chat\ChatException;
use Illuminate\Http\Request;

/** رفض مجال بالدردشة → JSON (للعميل) أو رجوع برسالة (بلا JS). 403 للصلاحيات، 409 لبلاغ مكرَّر، 422 للتحقق. لا نص رسالة المستخدم بالرد. */
trait ChatResponses
{
    protected function chatFailure(Request $request, ChatException $e)
    {
        $status = in_array($e->reason, ['forbidden', 'blocked', 'not_friends', 'muted', 'frozen', 'unverified', 'team_inactive', 'closed', 'self', 'not_owner'], true) ? 403 : ($e->reason === 'already_reported' ? 409 : 422);

        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $status)
            : back()->with('error', $e->getMessage())->withInput();
    }
}

<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatMessageRequest;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatMessagePresenter;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatRoomView;
use App\Services\Chat\ChatThreadService;
use App\Services\Chat\ChatUnreadService;
use App\Services\Teams\TeamMembershipService;
use Illuminate\Http\Request;

/**
 * الرسائل المباشرة (E21-B): بين صديقين مقبولين فقط (E16). المشاهدة والإرسال **بزوج (أنا، المستخدم)** لا بمعرّف غرفة، فلا يصل طرف ثالث لغرفة غيره (لا غرفة بينه وبين الاثنين أصلًا).
 * GET لا ينشئ شيئًا: الغرفة تُنشأ عند أول إرسال مسموح. التاريخ يبقى مقروءًا بعد حظر/إلغاء صداقة (والمحرّر معطَّل برسالة)، ويعود الإرسال بعودة الصداقة بنفس الغرفة.
 */
class DirectMessageController extends Controller
{
    use ChatResponses;

    public function index(Request $request, ChatUnreadService $unread, ChatThreadService $threads, TeamMembershipService $members, ChatAccess $access)
    {
        $user = $request->user();
        $membership = $members->membershipOf($user);
        $global = $threads->global();
        $teamThread = $membership ? $threads->findForTeam($membership->team) : null;

        return view('messages.index', [
            'conversations' => $unread->directList($user), 'team' => $membership?->team, 'cap' => (int) config('chat.unread_cap', 99),
            'teamUnread' => $teamThread ? $unread->forThread($user, $teamThread) : 0,
            'globalUnread' => $unread->forThread($user, $global),
        ]);
    }

    public function show(Request $request, User $user, ChatThreadService $threads, ChatRoomView $view, ChatAccess $access)
    {
        $me = $request->user();
        abort_if($me->is($user), 403);

        $thread = $threads->findDirect($me, $user);
        abort_if($thread === null && $access->directSendBlocker($me, $user) !== null, 403);   // لا فتح لمحادثة جديدة مع غير صديق/محظور
        abort_if($thread !== null && ! $access->canRead($me, $thread), 403);

        return view('chat.room', ['config' => $view->config($me, $thread, 'direct', $user->name, route('messages.direct.send', $user), $user)]);
    }

    public function send(ChatMessageRequest $request, User $user, ChatThreadService $threads, ChatMessageService $messages, ChatAccess $access, ChatRoomView $view, ChatMessagePresenter $presenter)
    {
        $me = $request->user();

        try {
            if (($reason = $access->directSendBlocker($me, $user)) !== null) {
                throw new ChatException($reason);
            }

            $thread = $threads->directOrCreate($me, $user);
            $message = $messages->send($me, $thread, $request->validated('body'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $presenter->forViewer($message->load('thread', 'sender'), $me, $view->caps($me, $thread)), 'thread' => $view->threadInfo($thread)], 201)
            : redirect()->route('messages.direct', $user);
    }
}

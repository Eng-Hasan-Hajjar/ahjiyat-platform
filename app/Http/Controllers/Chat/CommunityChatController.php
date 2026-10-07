<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatMessageRequest;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatMessagePresenter;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatRoomView;
use App\Services\Chat\ChatThreadService;
use Illuminate\Http\Request;

/** الدردشة العامة (E21-D): غرفة واحدة، لموثَّق غير مجمَّد (لا قراءة للضيف). المكتوم يقرأ ولا يرسل. رسائل المحظورين تُرشَّح حسب المشاهد (المصدر كامل). حدود إرسال أشد + حماية تكرار. */
class CommunityChatController extends Controller
{
    use ChatResponses;

    public function show(Request $request, ChatThreadService $threads, ChatRoomView $view, ChatAccess $access)
    {
        $thread = $threads->global();
        abort_unless($access->canRead($request->user(), $thread), 403);

        return view('chat.room', ['config' => $view->config($request->user(), $thread, 'global', 'الدردشة العامة', route('community.chat.send'), null, 'غرفة المجتمع: كن لطيفًا ومحترمًا. الرسائل نص عادي.')]);
    }

    public function send(ChatMessageRequest $request, ChatThreadService $threads, ChatMessageService $messages, ChatRoomView $view, ChatMessagePresenter $presenter)
    {
        $me = $request->user();
        $thread = $threads->global();

        try {
            $message = $messages->send($me, $thread, $request->validated('body'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $presenter->forViewer($message->load('thread', 'sender'), $me, $view->caps($me, $thread)), 'thread' => $view->threadInfo($thread)], 201)
            : redirect()->route('community.chat');
    }
}

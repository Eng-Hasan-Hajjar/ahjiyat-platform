<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatMessageRequest;
use App\Models\Team;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatMessagePresenter;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatRoomView;
use App\Services\Chat\ChatThreadService;
use Illuminate\Http\Request;

/**
 * دردشة الفريق (E21-C): غرفة رئيسية واحدة، لأعضاء الفريق **الحاليين** فقط (غيرهم 404 دون كشف وجود الغرفة). الخروج يقطع الوصول فورًا (العضوية تُفحص بكل طلب)،
 * والعودة تعيد الوصول **وترى تاريخ الفريق الحالي** (قرار موثَّق). الفريق المعطَّل: قراءة فقط. إنشاء الغرفة كسول وآمن من السباق (بنية تحتية، لا تعديل مستخدم).
 */
class TeamChatController extends Controller
{
    use ChatResponses;

    public function show(Request $request, Team $team, ChatThreadService $threads, ChatRoomView $view, ChatAccess $access)
    {
        $thread = $threads->forTeam($team);
        abort_unless($access->canRead($request->user(), $thread), 404);

        return view('chat.room', ['config' => $view->config($request->user(), $thread, 'team', 'دردشة '.$team->name, route('teams.chat.send', $team), null, $team->is_active ? null : 'الفريق غير مفعَّل: القراءة فقط'), 'team' => $team]);
    }

    public function send(ChatMessageRequest $request, Team $team, ChatThreadService $threads, ChatMessageService $messages, ChatAccess $access, ChatRoomView $view, ChatMessagePresenter $presenter)
    {
        $me = $request->user();
        $thread = $threads->forTeam($team);
        abort_unless($access->canRead($me, $thread), 404);

        try {
            $message = $messages->send($me, $thread, $request->validated('body'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $presenter->forViewer($message->load('thread', 'sender'), $me, $view->caps($me, $thread)), 'thread' => $view->threadInfo($thread)], 201)
            : redirect()->route('teams.chat', $team);
    }
}

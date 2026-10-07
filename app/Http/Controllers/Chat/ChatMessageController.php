<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChatMessageRequest;
use App\Http\Requests\ChatModerationRequest;
use App\Http\Requests\ChatReportRequest;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatMessagePresenter;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\ChatReportService;
use App\Services\Chat\ChatRoomView;
use App\Services\Chat\ChatUnreadService;
use Illuminate\Http\Request;

/**
 * عمليات الغرف والرسائل (E21): صفحات أقدم (GET للقراءة فقط)، علامة قراءة، تعديل، حذف ناعم، إبلاغ، إخفاء/استعادة. كل عملية تفحص أولًا أن المستخدم **يقرأ** الغرفة وإلا 404 (لا كشف
 * لوجود الرسالة/الغرفة: IDOR). ثم الخدمة تقرّر الصلاحية الدقيقة. المرسل والغرفة لا يأتيان من الطلب. كل التعديلات POST/PATCH/DELETE بـCSRF.
 */
class ChatMessageController extends Controller
{
    use ChatResponses;

    public function __construct(protected ChatAccess $access, protected ChatRoomView $view, protected ChatMessagePresenter $presenter) {}

    protected function readable(Request $request, ChatThread $thread): void
    {
        abort_unless($this->access->canRead($request->user(), $thread), 404);
    }

    public function older(Request $request, ChatThread $thread, ChatMessageService $messages)
    {
        $this->readable($request, $thread);
        $me = $request->user();
        $page = $messages->page($thread, $me, $request->integer('before') ?: null, $request->integer('after') ?: null, $request->integer('limit') ?: null);
        $caps = $this->view->caps($me, $thread);

        return response()->json(['messages' => $page['messages']->map(fn ($m) => $this->presenter->forViewer($m, $me, $caps))->values(), 'has_more' => $page['has_more']]);
    }

    public function read(Request $request, ChatThread $thread, ChatMessageService $messages, ChatUnreadService $unread)
    {
        $this->readable($request, $thread);
        $messages->markRead($request->user(), $thread, $request->integer('up_to') ?: null);

        return response()->json(['unread' => $unread->forThread($request->user(), $thread->refresh())]);
    }

    public function update(ChatMessageRequest $request, ChatMessage $message, ChatMessageService $messages)
    {
        $this->readable($request, $message->thread);

        try {
            $message = $messages->edit($request->user(), $message, $request->validated('body'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return response()->json(['message' => $this->payload($request, $message)]);
    }

    public function destroy(Request $request, ChatMessage $message, ChatMessageService $messages)
    {
        $this->readable($request, $message->thread);

        try {
            $message = $messages->deleteOwn($request->user(), $message);
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return response()->json(['message' => $this->payload($request, $message)]);
    }

    public function report(ChatReportRequest $request, ChatMessage $message, ChatReportService $reports)
    {
        $this->readable($request, $message->thread);

        try {
            $reports->report($request->user(), $message, $request->validated('category'), $request->validated('details'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return response()->json(['reported' => true], 201);
    }

    public function hide(ChatModerationRequest $request, ChatMessage $message, ChatModerationService $moderation)
    {
        $this->readable($request, $message->thread);

        try {
            $message = $moderation->hide($request->user(), $message, $request->validated('reason'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return response()->json(['message' => $this->payload($request, $message)]);
    }

    public function restore(ChatModerationRequest $request, ChatMessage $message, ChatModerationService $moderation)
    {
        $this->readable($request, $message->thread);

        try {
            $message = $moderation->restore($request->user(), $message, $request->validated('reason'));
        } catch (ChatException $e) {
            return $this->chatFailure($request, $e);
        }

        return response()->json(['message' => $this->payload($request, $message)]);
    }

    protected function payload(Request $request, ChatMessage $message): array
    {
        $message->load('thread', 'sender');

        return $this->presenter->forViewer($message, $request->user(), $this->view->caps($request->user(), $message->thread));
    }
}

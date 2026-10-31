<?php

namespace App\Http\Controllers\TatawwurAi;

use App\Http\Controllers\Controller;
use App\Services\AiAssistant\Conversation\ConversationService;
use App\Services\AiAssistant\Conversation\GuestSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** E45 - حذف سجل المحادثات: كل محادثات العضو، أو محادثة واحدة يملكها. الضيف: يمسح جلسته المؤقتة. حق الحذف لا يتوقف على تفعيل الميزة (خصوصية). */
class HistoryController extends Controller
{
    public function __construct(
        protected ConversationService $conversations,
        protected GuestSession $guest,
    ) {}

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $deleted = $this->conversations->clearAll($user);
        } else {
            $this->guest->clear($request->session());
            $deleted = 0;
        }

        return response()->json(['cleared' => true, 'deleted_conversations' => $deleted]);
    }

    public function destroyConversation(Request $request, string $conversation): JsonResponse
    {
        $deleted = $this->conversations->delete($request->user(), strtoupper($conversation));

        abort_unless($deleted, 404);

        return response()->json(['deleted' => true]);
    }
}

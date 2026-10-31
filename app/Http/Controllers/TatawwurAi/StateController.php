<?php

namespace App\Http\Controllers\TatawwurAi;

use App\Http\Controllers\Controller;
use App\Services\AiAssistant\Conversation\ConversationService;
use App\Services\AiAssistant\Conversation\GuestSession;
use App\Services\AiAssistant\Suggestions;
use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E45 - حالة الواجهة (قراءة فقط): الهوية، الإعدادات الآمنة (بلا أي مفتاح أو اسم نموذج)، الأسئلة المقترحة، وسجل المحادثة الأحدث للعضو (أو جلسة الضيف).
 * لا تُنشئ محادثة ولا تكتب شيئًا.
 */
class StateController extends Controller
{
    public function __construct(
        protected ConversationService $conversations,
        protected GuestSession $guest,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $audience = TatawwurSettings::audienceFor($user);
        $member = $audience === TatawwurSettings::AUDIENCE_MEMBER;

        $transcript = $member
            ? $this->conversations->transcript($user)
            : ['conversation_id' => null, 'messages' => array_map(fn (array $m) => ['id' => null, 'role' => $m['role'], 'text' => $m['text'], 'sources' => [], 'outcome' => null, 'rating' => null, 'at' => null], $this->guest->history($request->session()))];

        return response()->json([
            'assistant' => ['name' => 'تطوّر AI', 'subtitle' => 'المساعد الذكي لمنصة الأحجيات'],
            'audience' => $audience,
            'mode' => TatawwurSettings::providerReady() ? 'ai' : 'guide',
            'streaming' => TatawwurSettings::streamingEnabled() && TatawwurSettings::providerReady(),
            'max_chars' => TatawwurSettings::maxInputChars(),
            'can_rate' => $member,
            'retention_days' => $member ? TatawwurSettings::retentionDays() : null,
            'suggestions' => Suggestions::for($audience),
            'conversation_id' => $transcript['conversation_id'],
            'messages' => $transcript['messages'],
            'urls' => ['help' => route('help.index', [], false), 'login' => $member ? null : route('login', [], false)],
        ])->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\TatawwurAi;

use App\Http\Controllers\Controller;
use App\Http\Requests\TatawwurAi\AskTatawwurRequest;
use App\Models\AiMessage;
use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Conversation\ConversationService;
use App\Services\AiAssistant\Conversation\GuestSession;
use App\Services\AiAssistant\TatawwurSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * E45 - إرسال سؤال لتطوّر AI. طلب HTTP عادي (JSON) دائمًا يعمل، وبثّ SSE اختياري يُفعَّل بعلم التشغيل فقط (لا Reverb: بثّ ردّ لا بثّ محادثة بين مستخدمين).
 *  - قفل طلب واحد جارٍ لكل مستخدم/جلسة (يمنع تكديس مكالمات مزوّد مكلفة بالتوازي).
 *  - الحفظ للأعضاء فقط وبعد نجاح الردّ (الأعطال/الانشغال لا تُحفَظ)؛ الضيف: جلسة مؤقتة.
 *  - لا تغيير اقتصاد أو ترتيب أو سمعة في أي مسار.
 */
class MessageController extends Controller
{
    public function __construct(
        protected AssistantOrchestrator $assistant,
        protected ConversationService $conversations,
        protected GuestSession $guest,
    ) {}

    public function store(AskTatawwurRequest $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $audience = TatawwurSettings::audienceFor($user);
        $member = $audience === TatawwurSettings::AUDIENCE_MEMBER;
        $message = (string) $request->validated('message');

        $lock = Cache::lock('tatawwur:ai:send:'.($member ? 'u'.$user->getKey() : 's'.sha1($request->session()->getId())), 90);

        if (! $lock->get()) {
            return response()->json(['code' => 'busy', 'message' => 'ما زال ردّ سؤالك السابق قيد المعالجة. انتظر لحظة ثم أعد المحاولة.'], 409);
        }

        $stream = $request->boolean('stream') && TatawwurSettings::streamingEnabled() && TatawwurSettings::providerReady();

        $conversation = null;
        $history = [];

        try {
            if ($member) {
                $requested = $request->validated('conversation');
                $conversation = $this->conversations->ensure($user, is_string($requested) && $requested !== '' ? strtoupper($requested) : null);
                $history = $this->conversations->history($conversation);
            } else {
                $history = $this->guest->history($request->session());
            }
        } catch (Throwable $e) {
            $lock->release();

            throw $e;
        }

        $turn = new AssistantTurn($message, $audience, $member ? $user : null, $history, $stream);

        $respond = function (?callable $emit) use ($turn, $member, $user, $conversation, $request, $message): array {
            $answer = $this->assistant->answer($turn, $emit);

            return $this->payload($answer, $this->persist($answer, $message, $member, $user, $conversation, $request), $conversation?->public_id, $member);
        };

        if (! $stream) {
            try {
                return response()->json($respond(null))->header('Cache-Control', 'private, no-store');
            } finally {
                $lock->release();
            }
        }

        return response()->stream(function () use ($respond, $lock): void {
            try {
                $payload = $respond(fn (string $event, array $data) => $this->frame($event, $data));
                $this->frame('done', $payload);
            } catch (Throwable $e) {
                report($e);
                $this->frame('error', ['message' => 'تعذر إكمال الردّ. أعد المحاولة.']);
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @return string|null معرّف رسالة المساعد المحفوظة (للتقييم) أو null. */
    private function persist(AssistantAnswer $answer, string $message, bool $member, $user, $conversation, Request $request): ?string
    {
        if (! in_array($answer->outcome, ['answered', 'abstained', 'refused', 'out_of_scope', 'fallback'], true)) {
            return null;
        }

        try {
            if ($member && $conversation !== null) {
                $this->conversations->append($conversation, AiMessage::ROLE_USER, $message);
                $saved = $this->conversations->append($conversation, AiMessage::ROLE_ASSISTANT, $answer->text, $answer->sources, $answer->outcome);

                return $saved->public_id;
            }

            if (! $member && $answer->entersContext()) {
                $this->guest->push($request->session(), 'user', $message);
                $this->guest->push($request->session(), 'assistant', $answer->text);
                $request->session()->save();   // داخل البثّ تُحفَظ الجلسة بعد إرسال الاستجابة، فنحفظها صراحةً.
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(AssistantAnswer $answer, ?string $messageId, ?string $conversationId, bool $member): array
    {
        return [
            'id' => $messageId,
            'text' => $answer->text,
            'sources' => $answer->sources,
            'outcome' => $answer->outcome,
            'conversation_id' => $conversationId,
            'can_rate' => $member && $messageId !== null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function frame(string $event, array $data): void
    {
        echo 'event: '.$event."\n".'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

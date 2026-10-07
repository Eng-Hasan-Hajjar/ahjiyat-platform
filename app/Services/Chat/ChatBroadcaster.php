<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatMessageUpdated;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نشر الرسالة لحظيًا (E21-F9..F11): **بعد commit فقط**، وأي فشل بثّ (Reverb متوقف، قناة، شبكة) يُلتقَط ويُسجَّل بلا نص الرسالة، فتبقى الرسالة محفوظة والاستجابة ناجحة.
 * لا بثّ يخلق رسالة: هذه الخدمة تُستدعى من ChatMessageService بعد الحفظ فقط.
 */
class ChatBroadcaster
{
    public function __construct(protected ChatMessagePresenter $presenter) {}

    public function publish(ChatMessage $message, bool $updated = false): void
    {
        $id = $message->getKey();

        DB::afterCommit(function () use ($id, $updated) {
            try {
                $fresh = ChatMessage::query()->with('thread:id,public_id', 'sender:id,name,public_id')->find($id);

                if ($fresh === null) {
                    return;
                }

                $payload = $this->presenter->public($fresh);
                event($updated ? new ChatMessageUpdated($payload['thread'], $payload) : new ChatMessageSent($payload['thread'], $payload));
            } catch (\Throwable $e) {
                Log::warning('chat.broadcast_failed', ['message_id' => $id, 'error' => get_class($e)]);   // لا نص رسالة بالسجلات
            }
        });
    }
}

<?php

namespace App\Events\Chat;

/** رسالة جديدة بُثّت بعد حفظها. */
class ChatMessageSent extends ChatBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}

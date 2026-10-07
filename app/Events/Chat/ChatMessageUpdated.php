<?php

namespace App\Events\Chat;

/** تغيّرت حالة رسالة: تعديل/حذف ناعم/إخفاء إشرافي/استعادة. */
class ChatMessageUpdated extends ChatBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'message.updated';
    }
}

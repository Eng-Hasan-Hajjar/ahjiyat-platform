<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث بثّ للدردشة (E21): **قناة خاصة فقط** (لا بثّ عام لأي دردشة) باسم chat.{معرّف الغرفة العام}، وحمولة **آمنة للعامة** تُبنى بـChatMessagePresenter::public
 * (لا بريد ولا هاتف ولا حقول أمان/إشراف داخلية). يُرسَل بعد commit فقط وعبر ChatBroadcaster الذي يعزل أي فشل فلا تضيع الرسالة المحفوظة.
 * البثّ إعلامي: لا ينشئ رسالة أبدًا (المسار: HTTP ← تحقق ← صلاحية ← معاملة ← commit ← بثّ).
 */
abstract class ChatBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $threadPublicId, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->threadPublicId)];
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

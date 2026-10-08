<?php

namespace Tests\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;

/** ناشر بثّ يفشل دائمًا (يحاكي توقف Reverb) لاختبار أن الرسالة المحفوظة لا تتوقف على نجاح البثّ (E21-F11). */
class ChatFailingBroadcaster extends Broadcaster
{
    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        throw new \RuntimeException('Reverb unreachable');
    }
}

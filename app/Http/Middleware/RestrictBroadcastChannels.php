<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E26: مسار /broadcasting/auth لا يوقّع إلا القنوات التي يستعملها التطبيق فعلًا: القنوات **الخاصة** chat.{معرّف} فقط.
 *
 * ثغرة مغلقة: Pusher/Reverb يزيل بادئة private- و presence- قبل مطابقة القالب، فكان `presence-chat.{معرّف}` يتخوّل بنفس قاعدة chat.{thread}
 * ويرجع توقيعًا بـchannel_data فيه user_id (المعرّف الرقمي الداخلي للمستخدم) - قناة حضور لا يستعملها التطبيق ولا يجب أن توجد.
 * التطبيق لا يستعمل قنوات presence ولا القنوات العامة للمصادقة، فأي اسم غير private-chat. يُرفض هنا قبل الوصول لأي تخويل (403).
 */
class RestrictBroadcastChannels
{
    public function handle(Request $request, Closure $next): Response
    {
        $channel = $request->input('channel_name');

        if (! is_string($channel) || preg_match('/^private-chat\.[A-Za-z0-9]{1,64}$/', $channel) !== 1) {
            abort(403);
        }

        return $next($request);
    }
}

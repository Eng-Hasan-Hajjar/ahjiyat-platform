import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * E21: عميل البثّ اللحظي عبر Laravel Reverb (بروتوكول Pusher). يُفعَّل فقط حين تُعرَّف VITE_REVERB_APP_KEY؛ بدونه تعمل الدردشة بـHTTP كاملًا (بلا لحظية).
 * القنوات كلها خاصة (تُخوَّل بـ/broadcasting/auth بجلسة المستخدم وتوكن CSRF من الوسم meta).
 */
window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * E21: عميل البثّ اللحظي عبر Laravel Reverb (بروتوكول Pusher). يُفعَّل فقط حين تُعرَّف VITE_REVERB_APP_KEY؛ بدونه تعمل الدردشة بـHTTP كاملًا (بلا لحظية).
 * القنوات كلها خاصة (تُخوَّل بـ/broadcasting/auth بجلسة المستخدم وتوكن CSRF من الوسم meta).
 */
window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY;

let instance = null;

/**
 * E24: الاتصال الفعلي بـReverb يُفتح عند **أول طلب** (غرفة دردشة تشترك) لا عند تحميل كل صفحة.
 * قبل ذلك كان `new Echo(...)` ينفَّذ في كل تحميل صفحة (حتى للزوار وصفحات لا علاقة لها بالدردشة) فيفتح كل تبويب اتصال WebSocket دائمًا؛
 * عدد اتصالات Reverb = عدد التبويبات المفتوحة لا عدد من يدردشون، وهو أول ما يُنهك الخادم عند النمو.
 * سلوك الدردشة نفسه لم يتغيّر: غرفة الدردشة تستدعي getEcho() عند init فتتصل وتشترك كما كانت.
 */
window.getEcho = () => {
    if (!key) {
        return null;
    }

    if (instance === null) {
        const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

        instance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        });
        window.Echo = instance; // للتوافق مع أي كود يقرأ window.Echo بعد الاتصال
    }

    return instance;
};

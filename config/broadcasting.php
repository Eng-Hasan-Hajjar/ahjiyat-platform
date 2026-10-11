<?php

/**
 * E21: البثّ اللحظي عبر Laravel Reverb (خادم WebSocket من الطرف الأول؛ بلا خدمات SaaS خارجية). كل قنوات الدردشة **خاصة** (تُخوَّل عبر routes/channels.php).
 * المسار: HTTP ← تحقق/صلاحية/معاملة ← commit ← بثّ. فشل البثّ (Reverb متوقف) لا يفقد رسالة محفوظة.
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                // E24: البثّ المتزامن (ShouldBroadcastNow) يحجز عامل PHP-FPM أثناء استدعاء Reverb. الافتراضي في Laravel/Pusher: اتصال 10ث وطلب 30ث،
                // فخادم Reverb المعلّق (لا مرفوض) كان يُبقي كل رسالة دردشة مرسَلة عالقة حتى 30 ثانية ويستنزف العمّال. الآن: حدود قصيرة؛ فشل البثّ معزول أصلًا ولا يفقد الرسالة.
                'timeout' => (float) env('REVERB_HTTP_TIMEOUT', 3),
            ],
            'client_options' => [
                'connect_timeout' => (float) env('REVERB_HTTP_CONNECT_TIMEOUT', 1),
                'timeout' => (float) env('REVERB_HTTP_TIMEOUT', 3),
            ],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],
    ],
];

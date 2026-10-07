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
            ],
            'client_options' => [],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],
    ],
];

<?php

/*
|--------------------------------------------------------------------------
| E45 - تطوّر AI: المساعد الذكي لمنصة الأحجيات (Tatawwur AI)
|--------------------------------------------------------------------------
| مساعد مؤسَّس على أدلة المنصة (مقالات معرفة منشورة + أدوات قراءة حيّة)، للقراءة فقط، **وليس مُخبِرًا بحلول الأحجيات**.
|
| أعلام التشغيل (كلها مغلقة افتراضيًا: الإطلاق قرار تشغيلي، وتعطيلها لا يمسّ بقية المنصة):
|   TATAWWUR_AI_ENABLED            المفتاح الشامل: false = لا زر عائم ولا مسارات المساعد (صفحات الدليل العامة تبقى).
|   TATAWWUR_AI_PROVIDER_ENABLED   false = لا استدعاء لأي مزوّد ذكاء خارجي أبدًا؛ يعمل المساعد بالاسترجاع المحلي من دليل المنصة فقط.
|   TATAWWUR_AI_GUESTS_ENABLED     false = الضيف لا يستخدم المساعد (يُدعى لتسجيل الدخول). مفعّلًا: أسئلة عامة فقط، بلا أدوات شخصية وبمحادثة جلسة مؤقتة.
|   TATAWWUR_AI_STREAMING_ENABLED  false = ردّ كامل بطلب HTTP عادي. يُفعَّل فقط بعد ضبط Nginx/PHP-FPM لإيقاف التخزين المؤقت للبث (انظر الدليل التشغيلي).
|
| المزوّد والنموذج: AI_PROVIDER (افتراضي openai) وAI_MODEL **بلا قيمة افتراضية في الكود** (اسم النموذج قرار تشغيلي يُحقن من البيئة)، ومفتاح المزوّد من البيئة حصرًا
| (لا قاعدة بيانات ولا Filament ولا سجلات). عنوان مزوّد OpenAI ثابت بالكود ولا يُقبل من طلب أو إعداد مستخدم؛ المضيفون المسموحون قائمة بيضاء HTTPS.
| المزوّد الاختباري «fake» مرفوض خارج local/testing بالكود (لا يُمكن اختياره في الإنتاج).
|
| كل الحدود أعداد صحيحة مغلقة بسقوف صلبة في TatawwurSettings (قيمة بيئة خاطئة لا تفتح بابًا).
*/

return [

    'enabled' => (bool) env('TATAWWUR_AI_ENABLED', false),
    'provider_enabled' => (bool) env('TATAWWUR_AI_PROVIDER_ENABLED', false),
    'guests_enabled' => (bool) env('TATAWWUR_AI_GUESTS_ENABLED', false),
    'streaming_enabled' => (bool) env('TATAWWUR_AI_STREAMING_ENABLED', false),

    // المزوّد المختار بالاسم. النموذج بلا افتراضي: غيابه يعني «المزوّد غير مهيّأ» لا اسمًا مكتوبًا بالكود.
    'provider' => (string) env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL'),

    'providers' => [
        'openai' => [
            // مفتاح المزوّد: من بيئة الخادم فقط. لا يُعرَض ولا يُسجَّل ولا يُخزَّن بقاعدة البيانات.
            'api_key' => env('OPENAI_API_KEY'),
            // ثابت عمدًا (Responses API). لا يُقرأ من طلب ولا من إعداد يعدّله مستخدم؛ المضيف يُتحقَّق منه مقابل القائمة البيضاء قبل أي اتصال.
            'base_url' => 'https://api.openai.com/v1',
            'allowed_hosts' => ['api.openai.com'],
        ],
    ],

    // حدود المحتوى والكلفة (تُقصّ إلى سقوف صلبة في TatawwurSettings).
    'limits' => [
        'max_input_chars' => (int) env('TATAWWUR_AI_MAX_INPUT_CHARS', 600),
        // الفارغ أو 0 = الافتراضي (سطر .env.example يُترك فارغًا عمدًا لأن اسمه يحوي TOKEN فتعدّه حراس E25 سرًّا): لا يصير الفراغ سقفًا أدنى يحجب المساعد.
        'max_output_tokens' => (int) (env('TATAWWUR_AI_MAX_OUTPUT_TOKENS') ?: 900),
        'max_tool_calls' => (int) env('TATAWWUR_AI_MAX_TOOL_CALLS', 5),
        'context_chunks' => (int) env('TATAWWUR_AI_CONTEXT_CHUNKS', 6),          // 4..8
        'history_turns' => (int) env('TATAWWUR_AI_HISTORY_TURNS', 6),            // دورات المحادثة السابقة المُرسَلة للنموذج
        'request_timeout_seconds' => (int) env('TATAWWUR_AI_REQUEST_TIMEOUT', 25),
        'connect_timeout_seconds' => (int) env('TATAWWUR_AI_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('TATAWWUR_AI_RETRIES', 1),                        // إعادة محاولة واحدة كحد أقصى، لأخطاء الاتصال فقط
        'user_per_minute' => (int) env('TATAWWUR_AI_USER_PER_MINUTE', 10),
        'user_per_day' => (int) env('TATAWWUR_AI_USER_PER_DAY', 120),
        'guest_per_minute' => (int) env('TATAWWUR_AI_GUEST_PER_MINUTE', 5),
        'guest_per_day' => (int) env('TATAWWUR_AI_GUEST_PER_DAY', 25),
        'burst_per_ten_seconds' => (int) env('TATAWWUR_AI_BURST', 3),
        'global_daily_token_limit' => (int) (env('TATAWWUR_AI_GLOBAL_DAILY_TOKEN_LIMIT') ?: 2000000),
    ],

    // الاحتفاظ والخصوصية: المحادثات للأعضاء فقط (جسم الرسالة مشفَّر)، والضيف محادثة جلسة مؤقتة بلا صفوف.
    'retention' => [
        'conversation_days' => (int) env('TATAWWUR_AI_RETENTION_DAYS', 30),
        'metrics_days' => (int) env('TATAWWUR_AI_METRICS_RETENTION_DAYS', 90),
        'feedback_days' => (int) env('TATAWWUR_AI_FEEDBACK_RETENTION_DAYS', 180),
        'max_conversations_per_user' => 20,
        'max_messages_per_conversation' => 60,
        'guest_session_turns' => 6,
    ],

    // الاسترجاع المحلي (معجمي مُوزَّن، بلا متجهات). لا ادّعاء بحث دلالي.
    'retrieval' => [
        'min_score' => 3.0,           // أدنى درجة لاعتبار مقطع «دليلًا»
        'cache_ttl_seconds' => 600,
        'max_query_terms' => 12,
        'max_chunk_chars' => 700,
    ],

    // حدود النشر: طول المقال/المقطع (أحرف).
    'knowledge' => [
        'max_body_chars' => 12000,
        'chunk_target_chars' => 600,
        'chunk_max_chars' => 800,
    ],
];

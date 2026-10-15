<?php

/*
 * E26: إعدادات أمان التطبيق (حدود الدخول، عمر رموز API، سياسة الرؤوس). لا أسرار هنا؛ كلها أرقام/مفاتيح تشغيل بقيم افتراضية آمنة.
 * القيم القابلة للضبط بالبيئة موثَّقة في .env.example وdocs/security-e26.md.
 */
return [

    'login' => [
        // محاولات فاشلة مسموحة لكل (بريد+IP) قبل القفل المؤقت، ومدة القفل بالثواني.
        'max_attempts' => (int) env('SECURITY_LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('SECURITY_LOGIN_DECAY_SECONDS', 300),
        // رش كلمات المرور من عنوان واحد على حسابات متعددة: سقف فاشل لكل IP خلال نفس النافذة (أعلى بكثير كي لا يضرّ شبكة مشتركة).
        'ip_max_attempts' => (int) env('SECURITY_LOGIN_IP_MAX_ATTEMPTS', 30),
    ],

    'hosts' => [
        // يقيّد Host بمضيف APP_URL (+ الإضافية). لا أثر محليًا ولا بالاختبارات. أوقفه فقط اضطراريًا (SECURITY_TRUST_HOSTS=false).
        'enforce' => (bool) env('SECURITY_TRUST_HOSTS', true),
        // مضيفات إضافية مسموحة (أسماء كاملة مفصولة بفواصل، مثل www.example.com).
        'extra' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_TRUSTED_HOSTS', ''))))),
    ],

    'api' => [
        // عمر رمز Sanctum الشخصي بالدقائق (الافتراضي 30 يومًا). 0 = بلا انتهاء (غير مستحسن).
        'token_ttl_minutes' => (int) env('SECURITY_API_EXPIRY_MINUTES', 43200),
    ],

    'headers' => [
        // خط أساس CSP لا يمسّ تنفيذ السكربتات (Alpine/Livewire/Filament تحتاج inline/eval): frame-ancestors/base-uri/object-src فقط.
        'csp_baseline' => (bool) env('SECURITY_CSP_BASELINE', true),
    ],

];

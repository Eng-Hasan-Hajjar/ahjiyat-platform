# قائمة الأمان قبل الإنتاج (E26)

> تكمّل [production-checklist](production-checklist.md) (التشغيل) بما يخص الأمان التطبيقي. علّم كل بند بعد التحقق منه بنفسك. التفاصيل والسبب: [security-e26](security-e26.md).

## الهوية والجلسات

- [ ] `APP_URL=https://<النطاق الفعلي>` — **إلزامي** لأن `TrustApplicationHosts` يقيّد `Host` به؛ النطاقات البديلة في `SECURITY_TRUSTED_HOSTS`.
- [ ] `SESSION_SECURE_COOKIE=true`، `SESSION_SAME_SITE=lax`، `SESSION_HTTP_ONLY=true`، `SESSION_LIFETIME` مناسب.
- [ ] `TRUSTED_PROXIES` مضبوط على عناوين الموازن فقط (وإلا ترى حدود المعدّل IP الوكيل لا العميل، أو يمكن انتحال `X-Forwarded-For`).
- [ ] `MAIL_MAILER=smtp` وبريد المرسل مُوثَّق (SPF/DKIM)؛ إشعارات إعادة التعيين على الطابور لتقليل فرق التوقيت.
- [ ] جرّب بنفسك: دخول خاطئ 6 مرات → رسالة «محاولات دخول كثيرة»؛ إعادة تعيين لبريد غير مسجَّل → الرد نفسه؛ تغيير البريد يطلب كلمة المرور.

## الإدارة والصلاحيات

- [ ] لا حسابات `@ahjiyat.test` ولا كلمة مرور مدير افتراضية؛ `ADMIN_BOOTSTRAP_*` حُذفا بعد أول تشغيل.
- [ ] مراجعة قائمة من يملك `admin.access` وأدوار Spatie؛ مدير أعلى واحد على الأقل محفوظ بكلمة مرور قوية.
- [ ] `php artisan permissions:sync` نُفِّذ بعد النشر.

## البثّ والملفات

- [ ] `REVERB_ALLOWED_ORIGINS=<نطاقك>` (ليس `*`)، `REVERB_SCHEME=https`، `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM` غير مضبوط (= none).
- [ ] `php artisan storage:link` بقرص `public`، وخادم الويب لا ينفّذ PHP تحت `public/storage` (Nginx: `location ~ ^/storage/.*\.php$ { return 403; }`).
- [ ] `client_max_body_size` بالخادم ≥ أكبر حد رفع (8 ميغابايت لوسائط الحملات).

## الواجهة البرمجية (إن فُعّلت)

- [ ] لا تفعّل مسارات `/api` للموبايل قبل إنشاء هجرة Sanctum والتحقق من `SECURITY_API_EXPIRY_MINUTES`، وجدولة `sanctum:prune-expired --hours=24`.

## الاعتماديات والأسرار

- [ ] `composer audit` و`npm audit --omit=dev` على جهازك بلا ثغرات عالية معروفة، أو توثيق الاستثناء.
- [ ] لا أسرار في المستودع: `.env` خارج git؛ `php artisan production:check` Exit 0.
- [ ] `APP_DEBUG=false`، `LOG_LEVEL=warning|info`، ولا سجل يحوي كلمات مرور/رموز.

## المراقبة بعد الإطلاق

- [ ] راقب سطر `security.login_lockout` (ارتفاع مفاجئ = هجوم تخمين)، واستجابات 429 بالسجلات/الموازن، وطلبات الاستبدال المعلّقة والإشارات في مراجعة الاحتيال.
- [ ] الاستبدال يبقى **بمراجعة يدوية** (لا موافقة آلية) إلى أن يثبت سلوك المستخدمين.
- [ ] مراجعة حدود المعدّل بعد أسبوع من الإطلاق: إيجابيات كاذبة على شبكات مشتركة؟ (الحدود مفتاحها مستخدم لا IP إلا للضيوف).

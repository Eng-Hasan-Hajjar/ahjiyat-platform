
# التحصين الأمني (E8)

## Headers (E8-B)

`app/Http/Middleware/SecurityHeaders.php` مُسجَّلة على `web`+`api` (`bootstrap/app.php`) **و** على لوحة Filament صراحة (`AdminPanelProvider` - لا ترث `web` تلقائياً):
`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`. **HSTS** فقط عند `app()->environment('production')` + `$request->isSecure()` فعليًا - أبدًا على HTTP محلي.

## CSP — قرار صريح: لا CSP صارمة الآن

Filament/Livewire/Alpine/Vite تعتمد Inline Scripts/Styles بكثرة بالبنية الحالية. CSP صارمة تحتاج Nonces + تغييرًا واسعًا غير مبرَّر بهذه المرحلة. **مؤجَّل بقرار واعٍ** - إن رغبت مستقبلاً: ابدأ بـ`Content-Security-Policy-Report-Only` لجمع بيانات حقيقية قبل أي فرض.

## HTTPS/Cookies

Production: `APP_URL=https://...` إلزامي. `SESSION_SECURE_COOKIE=true` **فقط عند HTTPS فعلي**. `SESSION_SAME_SITE=lax` (افتراضي حالي، مناسب). Trusted Proxies: يعتمد على البنية الفعلية (Nginx مباشر أم خلف Cloudflare) - وثِّق بالـIP الحقيقي لموفِّر الاستضافة عند النشر، لا `*` عشوائيًا.

## Rate Limiting (E8-C) — `app/Providers/AppServiceProvider.php`

| Limiter                 | الحد        | المفتاح |
| ----------------------- | --------------- | -------------- |
| `registration`        | 5/10 دقائق | IP             |
| `password-reset`      | 5/10 دقائق | IP             |
| `email-verification`  | 6/دقيقة    | user أو IP   |
| `puzzle-attempt`      | 20/دقيقة   | user أو IP   |
| `game-session-start`  | 10/دقيقة   | user أو IP   |
| `game-session-reveal` | 60/دقيقة   | user أو IP   |
| `redemption`          | 5/ساعة      | user أو IP   |

تسجيل الدخول (ويب + API) محمي يدويًا داخل الـController نفسه (5 محاولات/بريد+IP، نمط Laravel القياسي).

## رفع الملفات (E8-D)

كل الرفوعات: `acceptedFileTypes` صريحة + `maxSize` + قرص `public` مقصود (كل المحتوى المرفوع عام العرض أصلًا). لا SVG بأي مكان. أسماء الملفات المولَّدة عشوائية دائمًا.

## الأسرار

راجعت الريبو بحثًا عن أسرار Hardcoded - لا وجود لأي قيمة حقيقية بالكود. `.gitignore` يستبعد `.env`/`.env.backup`/`.env.production` بالفعل.

## قاعدة البيانات

Production: مستخدم DB مخصَّص بصلاحيات التطبيق فقط (لا root)، `utf8mb4` لـMySQL، منفذ DB لا يُفتَح للعامة إلا خلف Firewall صريح.

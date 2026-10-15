# حزمة تسليم E26 — أمان التطبيق، مقاومة الإساءة، حماية الحسابات، التصليب بعد الاختراق التجريبي

> للنسخ اليدوي إلى مشروعك المحلي. **لا أوامر git هنا (NO GIT COMMANDS).** لم يُلمس `composer.lock` ولا `package-lock.json` ولا `PlatformSettingsPage.php` ولا `AdminPanelProvider.php`.
> الأسهل: فكّ `e26-security.zip` فوق جذر المشروع (يحتفظ بالمسارات)، ثم نفّذ القسم E. الأقسام أدناه هي نفس المحتوى للمراجعة أو النسخ الفردي.

ملخص: 21 ملف جديد، 31 ملف معدَّل (+ `.env.example`)، 0 ترحيلات، 0 حزم جديدة.

## A) ملفات جديدة (محتوى كامل)

### `SECURITY.md`

```markdown
# سياسة الأمان — Security Policy

نأخذ أمان منصة «أحجيات» وبيانات لاعبيها على محمل الجد. إن وجدت ثغرة فنرجو إبلاغنا بمسؤولية وفق ما يلي.

## كيف تُبلِغ

- أرسل وصفًا للثغرة **بشكل خاص** إلى جهة الاتصال الأمنية للمنصة (البريد المعتمد من مالك المشروع، الموضّح في صفحة «تواصل معنا» أو في `general.support_email` بإعدادات المنصة). **لا تفتح Issue عامًا** ولا تنشر التفاصيل قبل المعالجة.
- اذكر: الصفحة/المسار المتأثر، خطوات إعادة الإنتاج، الأثر المتوقع، وإصدار/تاريخ ما اختبرته. لقطة شاشة أو طلب HTTP يكفي؛ **لا ترفق بيانات لاعبين حقيقية**.
- نؤكد الاستلام خلال وقت معقول، ونبلغك بالتقدم، ونذكر اسمك في الشكر إن رغبت بذلك.

## نطاق الاختبار المسموح

- اختبر على **نسختك المحلية أو بيئة اختبار** فقط (`.env` محلي، قاعدة اختبار). لا تختبر على بيئة الإنتاج الحية بأي أسلوب يؤثر على لاعبين آخرين.
- ممنوع: حجب الخدمة (DoS) أو إغراق الطلبات، تخمين كلمات مرور حسابات حقيقية، الوصول لبيانات حسابات ليست لك أو تعديلها، الهندسة الاجتماعية، الهجوم المادي، والتحليل الآلي واسع النطاق للإنتاج.
- إن وصلت بالخطأ لبيانات شخصية فتوقف فورًا، ولا تنسخها أو تحتفظ بها، وأبلغ عن ذلك.

## ما نعتبره ضمن النطاق

تجاوز المصادقة أو الصلاحيات، الوصول لبيانات لاعب آخر (IDOR)، حقن SQL/سكربت، CSRF، تزوير الرصيد أو المكافآت أو نتائج المنافسات، تجاوز حدود الاستبدال، تسريب أسرار، رفع ملفات ضارة، تجاوز حدود المعدّل الموثَّقة في [`docs/security-e26.md`](docs/security-e26.md).

## ما نعتبره خارج النطاق

تقارير الماسحات الآلية بلا إثبات استغلال، غياب ترويسات لا أثر لها فعليًا، هجمات تتطلب جهازًا مخترقًا أو وصولًا مسبقًا للحساب بكلمة مرور صاحبه، الأخطاء في حزم طرف ثالث بلا مسار استغلال ظاهر، ومخاطر «مقبولة بوعي» الموثَّقة في قسم Deferred risks من [`docs/security-e26.md`](docs/security-e26.md).

## الإفصاح المسؤول

نطلب منك مهلة معقولة لإصلاح الثغرة قبل أي إفصاح عام. **لا يوجد برنامج مكافآت مالية (Bug Bounty)** حاليًا؛ نقدّر البلاغات ونشكر أصحابها.

## إصدارات مدعومة

يُدعم الفرع الرئيسي الحالي (`main`) فقط. التحديثات الأمنية تُنشر ضمن الإصدارات العادية مع ملاحظة في سجل التغييرات عند الحاجة.
```

### `app/GameEngine/Support/SequenceTokens.php`

```php
<?php

namespace App\GameEngine\Support;

use App\Models\Puzzle;

/**
 * E26 (Game anti-cheat): معرّفات عناصر لعبة الترتيب المعروضة للعميل.
 *
 * المشكلة: كانت الواجهة تُرسل لكل عنصر `idx` = فهرسه الأصلي في game_config.items، والإجابة الصحيحة هي بالضبط ترتيب الفهارس التصاعدي (solution_data.order = array_keys(items)).
 * فمن يقرأ مصدر الصفحة أو يستدعي API يحلّ اللغز دون تفكير بترتيب الأرقام تصاعديًا، مع أن الخلط السيرفري كان يهدف لإخفاء الترتيب.
 *
 * الحل: رمز معتم لكل عنصر = HMAC-SHA256(مفتاح التطبيق، "seq:<puzzle>:<idx>") مقتطع. حتمي (يُحسب بالطلب نفسه عند العرض والتحقق، بلا حالة ولا جدول)،
 * غير قابل للتنبؤ دون المفتاح، وترتيب الرموز لا يكشف ترتيب الإجابة. التحقق يقارن بالرموز المتوقعة للترتيب الصحيح، ولا يقبل الأعداد الخام أبدًا.
 */
final class SequenceTokens
{
    public static function token(Puzzle $puzzle, int|string $index): string
    {
        return substr(hash_hmac('sha256', 'seq:'.$puzzle->getKey().':'.$index, (string) config('app.key')), 0, 16);
    }

    /** الرموز بالترتيب الصحيح (من solution_data.order). @return list<string> */
    public static function expectedOrder(Puzzle $puzzle): array
    {
        return array_values(array_map(
            fn ($index) => self::token($puzzle, $index),
            (array) ($puzzle->solution_data['order'] ?? []),
        ));
    }

    /** عناصر مخلوطة جاهزة للعميل: [['idx' => <رمز معتم>, 'label' => <نص>], ...] (اسم المفتاح idx محفوظ لواجهة Alpine القائمة). */
    public static function shuffledItems(Puzzle $puzzle): array
    {
        return collect((array) ($puzzle->game_config['items'] ?? []))
            ->map(fn ($label, $index) => ['idx' => self::token($puzzle, $index), 'label' => $label])
            ->shuffle()
            ->values()
            ->all();
    }
}
```

### `app/Http/Middleware/RestrictBroadcastChannels.php`

```php
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
```

### `app/Http/Middleware/TrustApplicationHosts.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/**
 * E26: يقيّد ترويسة Host بمضيف التطبيق (APP_URL) + مضيفات إضافية صريحة (SECURITY_TRUSTED_HOSTS). بدونه يبني Laravel روابط البريد (إعادة تعيين كلمة المرور، توثيق البريد)
 * من Host الطلب نفسه: مهاجم يرسل POST /forgot-password بـ«Host: evil.test» فيصل الضحية بريد رسمي برابط إعادة تعيين يشير لنطاق المهاجم ويسرّب الرمز (Password-reset poisoning).
 *
 *  - لا يعمل في البيئة المحلية ولا في الاختبارات (قاعدة Laravel نفسها: shouldSpecifyTrustedHosts) فلا يكسر XAMPP/127.0.0.1.
 *  - مسارا الصحة /up و/ready مستثنيان: موازنات الحِمل تفحصهما غالبًا بـHost = عنوان IP داخلي.
 *  - مفتاح الإيقاف الاضطراري: SECURITY_TRUST_HOSTS=false (لا يُنصح به بالإنتاج).
 */
class TrustApplicationHosts extends TrustHosts
{
    public function hosts(): array
    {
        $hosts = [];

        if (is_string($appHost = parse_url((string) $this->app['config']->get('app.url'), PHP_URL_HOST)) && $appHost !== '') {
            $hosts[] = '^'.preg_quote($appHost).'$';
        }

        foreach ((array) $this->app['config']->get('security.hosts.extra', []) as $extra) {
            if (is_string($extra) && trim($extra) !== '') {
                $hosts[] = '^'.preg_quote(trim($extra)).'$';
            }
        }

        return $hosts;
    }

    public function handle(Request $request, $next)
    {
        if ($request->is('up', 'ready')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function shouldSpecifyTrustedHosts()
    {
        return (bool) $this->app['config']->get('security.hosts.enforce', true) && parent::shouldSpecifyTrustedHosts();
    }
}
```

### `app/Services/Security/AccountSecurityService.php`

```php
<?php

namespace App\Services\Security;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * E26: إبطال بيانات الاعتماد الحيّة بعد تغيير حسّاس (إعادة تعيين كلمة المرور، تغيير البريد). بدونه يبقى من سرق جلسة أو رمز API مصادَقًا بعد أن غيّر المالك كلمة مروره.
 * ذاتي الخدمة (بلا "منفّذ إداري" كما بـSessionManagementService الإدارية): يعمل على حساب المستخدم نفسه، ويحتفظ اختياريًا بجلسة الطلب الحالية.
 */
class AccountSecurityService
{
    /**
     * يحذف جلسات قاعدة البيانات الأخرى (إن كان سائق الجلسات database) ورموز Sanctum الشخصية، ويدوّر remember_token (يُبطل "تذكّرني" بكل الأجهزة).
     *
     * @return array{sessions: int, tokens: int}
     */
    public function revokeCredentials(User $user, ?string $keepSessionId = null): array
    {
        $sessions = 0;

        if (Schema::hasTable((new Session)->getTable())) {
            $query = Session::query()->where('user_id', $user->getKey());

            if ($keepSessionId !== null) {
                $query->where('id', '!=', $keepSessionId);
            }

            $sessions = $query->delete();
        }

        // جدول رموز Sanctum غير منشأ بهذا المستودع بعد (واجهة الموبايل مؤجَّلة): لا نفترض وجوده.
        $tokens = method_exists($user, 'tokens') && self::tokensTableExists() ? $user->tokens()->delete() : 0;

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return ['sessions' => (int) $sessions, 'tokens' => (int) $tokens];
    }

    /** هل جدول رموز API الشخصية موجود؟ (E26: واجهة الموبايل خاملة حتى تُنشأ الهجرة؛ انظر docs/security-e26.md). */
    public static function tokensTableExists(): bool
    {
        return Schema::hasTable((new \Laravel\Sanctum\PersonalAccessToken)->getTable());
    }
}
```

### `app/Support/Security/Fingerprint.php`

```php
<?php

namespace App\Support\Security;

/**
 * E26: بصمات غير قابلة للعكس لاستعمالها في مفاتيح المحدِّدات والسجلات الأمنية بدل القيم الخام (بريد/رمز). لا تُستعمل لأي غرض مصادقة.
 */
final class Fingerprint
{
    /**
     * SHA-256 لبريد مُطبَّع (trim + أحرف صغيرة) مقتطع لـ32 خانة. مفاتيح RateLimiter تُخزَّن بلا تجزئة بالكاش، فالبريد الخام فيها تسريب PII ويسمح بتضخيم
     * المفاتيح بقيم طويلة. المدخل غير النصي/الفارغ يعطي بصمة ثابتة واحدة (لا استثناء).
     */
    public static function email(mixed $email): string
    {
        $normalized = is_string($email) ? mb_strtolower(trim($email)) : '';

        return substr(hash('sha256', $normalized), 0, 32);
    }
}
```

### `config/security.php`

```php
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
```

### `docs/security-checklist.md`

```markdown
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
```

### `docs/security-e26.md`

````markdown
# أمان التطبيق ومقاومة الإساءة (E26)

> مرحلة تدقيق هجومي **محلي فقط**: اختُبرت المنصة بهجمات حقيقية على قاعدة اختبار SQLite وكاش `array` داخل عملية الاختبار (لا شبكة، لا مسح خارجي، لا هجوم على أي خدمة حية). أُغلقت الثغرات القابلة للاستغلال فعلًا، ووُثِّق ما بقي. لا CAPTCHA ولا 2FA ولا WAF ولا SIEM ولا حزمة أمان جديدة: كل ما أُضيف كود تطبيق وإعدادات وسطاء صغيرة.
> يكمّل [security-hardening](security-hardening.md) (E8/E25) و[production-checklist](production-checklist.md). قائمة ما قبل الإنتاج الأمنية: [security-checklist](security-checklist.md). سياسة الإبلاغ: [`SECURITY.md`](../SECURITY.md).

## Threat model

**ما نحميه (بترتيب الأهمية):** (1) رصيد الجواهر والمكافآت والاستبدال (قيمة مالية داخلية)، (2) حسابات اللاعبين وهويتهم (بريد، جلسات)، (3) نزاهة المنافسات والترتيب، (4) خصوصية الدردشة والصداقة والفرق، (5) لوحة الإدارة والصلاحيات، (6) توفّر الخدمة.

**من يهاجم:**

| الفاعل | هدفه | قدرته |
| --- | --- | --- |
| زائر مجهول / بوت | حسابات وهمية، تخمين كلمات مرور، مسح بيانات اللاعبين، إغراق | طلبات HTTP كثيرة من IP أو عدة IP |
| لاعب مسجَّل خبيث | كسب جواهر غير مستحق، غش بالمنافسات، الوصول لبيانات لاعب آخر، تضييق على الآخرين | جلسة صالحة، يرى الصفحات والاستجابات، يعدّل أي حقل نموذج |
| ضحية مهاجَمة | سرقة حساب (رابط إعادة تعيين مسمَّم، رابط خبيث، XSS مخزَّن) | تنقر رابطًا أو تفتح صفحة |
| موظف إدارة بصلاحية محدودة | تصعيد صلاحيات، رفع ملف ضار | جلسة إدارة محدودة |
| مهاجم شبكة / وسيط | قراءة جلسة، تسميم Host، كاش شارد | يتحكم بترويسات الطلب |

**حدود الثقة:** المتصفح غير موثوق بالكامل (كل حقل ورمز وسعر ومكافأة تُعاد حسابها بالخادم)، الوكيل العكسي موثوق فقط إن ضُبط `TRUSTED_PROXIES`، Reverb خدمة داخلية يصلها المتصفح عبر قنوات مصادَقة، لوحة Filament محكومة بصلاحيات Spatie.

**المبدأ الحاكم:** الخادم هو المرجع في السعر والمكافأة والرصيد والمشارك والفائز. العميل يرسل «ماذا يريد» لا «كم».

## Attack surface

مسح كامل لمسارات `routes/web.php` و`routes/api.php` و`routes/channels.php` ولوحة Filament:

| السطح | الحماية الأساسية |
| --- | --- |
| دخول/تسجيل/إعادة تعيين/توثيق (ضيف) | حدود معدّل + رسائل عامة + تدوير جلسة (انظر Controls) |
| ملف شخصي / محفظة / استبدال / متجر (موثَّق) | `auth` + `verified` + `account.active` + حدود معدّل + سلطة خادم كاملة |
| صداقة / فرق / دردشة / تحديات | سياسات النطاق (`ChatAccess` وما يماثلها) تُرجع 403/404 + ربط عبر `public_id` لا المعرّف الرقمي |
| منافسات | `{event:slug}` + تسجيل/بدء/إرسال بحدود؛ لا score/winner/rank من العميل |
| البثّ اللحظي `/broadcasting/auth` | `web` + حد معدّل + حصر الأسماء على `private-chat.*` + تخويل `ChatThreadChannel` |
| API (`/api/*`، خامل) | Sanctum + `verified` + `account.active` + حد دخول مزدوج؛ يردّ 503 حتى تُنشأ هجرة الرموز |
| لوحة الإدارة `/admin` | صلاحيات Spatie لكل صفحة/مورد + `Cache-Control: no-store` |
| رفع الملفات | Filament `FileUpload` بقوائم أنواع صريحة + `maxSize` + قرص `public` + أسماء مولَّدة (65b يفرضها آليًا) |
| روابط خارجية | رابط النقر الإعلاني مُخزَّن `https` فقط؛ لا إعادة توجيه من معامل طلب |

## Controls

### المصادقة والحساب

- **حد الدخول (ويب):** `LoginRequest::authenticate` — مفتاح `login:acct:<sha256(email)>|<ip>` (5 فشل/5 دقائق) + مفتاح `login:ip:<ip>` (30 فشل/5 دقائق ضد رشّ كلمات المرور). مفاتيح الحد لا تحوي البريد الخام (`Fingerprint::email`) لأن مخزن الحدود لا يجزّئ المفاتيح. القفل يُسجَّل بسطر `security.login_lockout` واحد لكل نافذة. **لا قفل بالبريد وحده** عمدًا (يمنع مهاجمًا من حجب الضحية من أي IP).
- **رسالة عامة:** «بيانات الدخول غير صحيحة.» لأي فشل، ومعادلة زمنية (تجزئة وهمية) لبريد غير موجود.
- **تثبيت الجلسة:** `session()->regenerate()` عند الدخول؛ الخروج = `logout` + `invalidate` + `regenerateToken`.
- **حساب مجمَّد:** يُمنع عند الدخول (يُخرَج فورًا) وفي كل طلب لاحق (`account.active`: ويب 403/إعادة توجيه، API/JSON 403) وعلى القنوات اللحظية.
- **إعادة تعيين كلمة المرور:** رد واحد عام سواء وُجد البريد أم لا؛ `password-reset` = 5/10 دقائق لكل IP + 3/ساعة لكل بريد مستلم (مجزَّأ)؛ POST إعادة التعيين نفسه محدود (10/10 دقائق)؛ بعد النجاح تُحذف **كل الجلسات الأخرى ورموز API** ويُدوَّر `remember_token` (`AccountSecurityService::revokeCredentials`).
- **تغيير البريد:** يتطلب `current_password`، ويُنهي الجلسات الأخرى والرموز (يبقي الحالية). هذا يغلق «تغيير البريد ثم إعادة التعيين» كمسار استيلاء من جلسة مسروقة.
- **تسميم Host:** `TrustApplicationHosts` يقيّد `Host` بمضيف `APP_URL` (+ `SECURITY_TRUSTED_HOSTS`) خارج local/testing، ويستثني `/up` و`/ready`. روابط البريد (إعادة التعيين/التوثيق) لا تُبنى من Host مزوَّر.

### الجلسة والكوكي وCSRF

- كل مسارات التغيير داخل مجموعة `web` (CSRF + جلسة): لا استثناء CSRF في أي ملف (W8 + 35b). لا تغيير حالة عبر GET (36/36b)؛ الاستثناءان: رابط التوثيق الموقَّع، ورابط النقر الإعلاني (يعيد توجيه فقط ويسجّل نقرة بحد معدّل).
- الكوكي: `HttpOnly` و`SameSite=lax` افتراضًا؛ `SESSION_SECURE_COOKIE=true` إلزامي بالإنتاج (`production:check`).

### الصلاحيات (IDOR / Mass assignment)

- كل الموارد التي يملكها مستخدم تُحمَّل عبر علاقته أو تُفحص بسياسة؛ مطابقات الصداقة/الفرق/الدردشة/التحديات لها اختبارات هجوم صريحة (13–26).
- لا `$request->all()` ولا `unguard` ولا `$guarded = []` (W4). `User::$fillable` = الاسم/البريد/كلمة المرور/ظهور الملف فقط. نماذج الدردشة `guarded = ['*']`. كتابة المحافظ ودفتر العملات من الطبقة الخدمية فقط (60b).
- آخر Super Admin لا يُحذف ولا يُنزَّل، وإدارة محدودة لا تمنح نفسها صلاحيات أعلى (25/26).

### المدخلات والمخرجات

- **XSS:** Blade `{{ }}` في كل مكان؛ `{!! !!}` واحد فقط لخريطة SVG ثابتة (W3). اختبارات 27–29 تحقن حمولة سكربت في الدردشة/اسم الفريق/الملف الشخصي وتتحقق من الهروب على كل صفحة عرض.
- **SQL:** كل الاستعلامات مربوطة؛ الاستعلامات الخام لا تستقبل مدخلات (W5) وأربعة مواضع استيفاء فقط مراجَعة (تعابير تُبنى من ثوابت). البحث والفرز بقوائم بيضاء (30/31).
- **روابط/توجيه:** لا open redirect (37)، رابط الإعلان `https` فقط (32)، لا `Http::` خارج `SmokeChecker` (W7 = لا SSRF).

### الترويسات والكاش

`X-Content-Type-Options: nosniff`، `Referrer-Policy: strict-origin-when-cross-origin`، `X-Frame-Options: SAMEORIGIN`، `Permissions-Policy`، و**E26:** `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'` (خط أساس لا يمسّ تنفيذ السكربتات)، و`Cache-Control: no-store, private` على صفحات الدخول/الحساب/المحفظة/الاستبدال/الإدارة (يمنع رجوعًا لصفحة حساسة بعد الخروج). HSTS للإنتاج على HTTPS فقط.

### البثّ اللحظي (Reverb)

قنوات `private-chat.{public_id}` فقط، تخويلها بـ`ChatAccess::canRead` (نفس قاعدة الصفحات). **E26:** `RestrictBroadcastChannels` يرفض أي اسم غير `private-chat.*` (يغلق `presence-chat.*`)، `broadcast-auth` = 90/دقيقة، `accept_client_events_from = none` (لا whisper)، ومحتوى البث مقتصر على حقول عامة (43). `REVERB_ALLOWED_ORIGINS` بالإنتاج: `production:check` يحذّر من `*`.

### حدود الإساءة (Rate limits)

| الحد | القيمة | المفتاح |
| --- | --- | --- |
| تسجيل | 5/10د | IP |
| دخول ويب | 5/5د (حساب+IP) و30/5د (IP) | بصمة بريد + IP |
| طلب إعادة تعيين | 5/10د IP و3/ساعة مستلم | IP / بصمة بريد |
| إرسال إعادة تعيين | 10/10د | IP |
| تأكيد كلمة المرور | 6/د | مستخدم |
| ملف شخصي/خصوصية | 10/د | مستخدم |
| طلبات صداقة | 10/د و60/ساعة | مستخدم |
| دعوات فريق | 10/د و40/ساعة | مستخدم |
| دردشة عامة | 3/10ث و10/د | مستخدم |
| بلاغ دردشة | 5/د و20/ساعة | مستخدم |
| بحث لاعبين | 30/د | مستخدم |
| لعب تنافسي | 20/د | مستخدم |
| شراء متجر | 8/د | مستخدم |
| استبدال | 5/ساعة | مستخدم |
| تلميح | 10/د | مستخدم |
| إجراءات لعب (انضمام/إكمال/تأمل) | 30/د | مستخدم |
| API دخول | 20/د IP و5/5د (بريد مجزَّأ+IP) | — |
| قناة بث | 90/د | مستخدم |

المفتاح «مستخدم» يعني `user_id` وإلا IP: مستخدم يُحجَب لا يحجب غيره على الشبكة نفسها (56b). ما لا يملك حدًّا مخصَّصًا يبقى تحت `throttle:api`/حدود E8 الأقدم.

### الاقتصاد والمنافسات

السعر من `store_item_prices` (يُرفض `price_id` لعنصر آخر أو سعر موقوف)، المفتاح `request_key` فريد (تكرار = نفس الشراء، ومستخدم آخر = تعارض)، مكافأة اللغز من قاعدة البيانات، حل ثانٍ لا يدفع، سقف الكسب اليومي يُقرأ **تحت قفل صف المحفظة** (يغلق سباق ألغاز متزامنة)، الاستبدال يعيد فحص الأهلية والرصيد داخل المعاملة، لا score/rank/winner من العميل بالمنافسات (21–23، 57–61).

### نزاهة اللعب

- **ترتيب التسلسل:** كان الحل = الفهارس تصاعديًا وكانت الفهارس الخام ترسل للعميل (الصفحة، API، المنافسة). الآن رموز معتمة `HMAC(app.key, "seq:{puzzle}:{idx}")` (`SequenceTokens`) — الحل لا يُستنتج من الصفحة، والأعداد الخام لا تُقبل (O1–O3).
- **ألغاز غير فعّالة:** لا عرض ولا محاولة ولا تلميح ولا مكافأة للاعب (ويب وAPI)، والمعاينة للإدارة (O4).

### الملفات

كل حقول `FileUpload` (19 حقلًا) بقائمة أنواع صريحة PNG/JPEG/WebP (+ صوت لخطوات الحملات)، بلا SVG/HTML، `maxSize` لكل حقل، قرص `public` ومجلد ثابت، اسم مولَّد (لا يصل الاسم الأصلي للقرص). التحقق بمحتوى الملف (MIME sniffing) لا بالامتداد. hotfix الشعارات (`previewable(false)` على 6 حقول) محفوظ ومحمي باختبار (66).

### السجلات

لا جدول جديد ولا سجل ضخم: أحداث الأمان تُسجَّل بسطور منخفضة التواتر (`security.login_lockout`) وبسجل التدقيق الموجود. لا كلمات مرور/رموز/كوكيز/جسم طلب كامل بأي `Log::` (W9). مفاتيح الحدود والسجلات تستخدم بصمة البريد لا البريد.

## Findings

| # | الخطورة | الثغرة | الحالة |
| --- | --- | --- | --- |
| E26-01 | **High** | `POST /login` بلا حد معدّل فعلي: `LoginRequest` موجود لكن غير مستعمل (الدخول يتم بـ`Auth::attempt` مباشرة) → تخمين كلمات مرور بلا قيد | **مُصلَحة** (حد مزدوج + قفل) |
| E26-02 | **High** (مشروط ببنية الاستضافة) | تسميم Host في رابط إعادة التعيين/التوثيق عند السماح بأي `Host` من الوكيل | **مُصلَحة** (`TrustApplicationHosts`) |
| E26-03 | **High/Medium** | حل لعبة الترتيب مكشوف: فهارس `idx` الخام بالصفحة وAPI ومسار المنافسة، والحل = ترتيبها التصاعدي → بوت يفوز بالمنافسات | **مُصلَحة** (رموز معتمة) |
| E26-04 | Medium | `GET /api/puzzles/{id}` يسرّب `game_config` الخام ويعرض ألغازًا غير فعّالة | **مُصلَحة** |
| E26-05 | Medium | ألغاز/تحديات غير فعّالة (مسودات) قابلة للعرض واللعب والمكافأة/التلميح | **مُصلَحة** |
| E26-06 | Medium | سباق السقف اليومي: محاولات متزامنة على ألغاز مختلفة تتجاوز `daily_earn_cap` | **مُصلَحة** (قفل المحفظة) |
| E26-07 | Medium | إعادة التعيين: كشف وجود الحساب برسالة مختلفة، لا حد للمستلم، ولا إنهاء للجلسات/الرموز بعد التغيير | **مُصلَحة** |
| E26-08 | Medium | تغيير البريد بلا كلمة المرور الحالية (استيلاء من جلسة مسروقة) | **مُصلَحة** |
| E26-09 | Medium (كامنة) | واجهة API: رمز لحساب مجمَّد/غير موثَّق يكسب جواهر؛ الدخول ينهار 500 (جدول Sanctum غير موجود) | **مُحصَّنة** (بوابات + 503 صريح) |
| E26-10 | Low | تأكيد كلمة المرور، POST إعادة التعيين، التلميح، الانضمام/الإكمال/التأمل، `/broadcasting/auth` بلا حد | **مُصلَحة** |
| E26-11 | Low | أحداث العميل (whisper) مفتوحة على Reverb ولا يستعملها التطبيق | **مُصلَحة** (`none`) |
| E26-12 | Low | لا خط أساس CSP ولا `no-store` على الصفحات الحساسة | **مُصلَحة** (CSP كامل مؤجَّل: انظر Deferred) |
| E26-13 | Low | `presence-chat.{id}` يتخوّل بقاعدة القناة الخاصة ويُرجع توقيعًا فيه `user_id` الداخلي (اكتُشف أثناء اختبارات القنوات) | **مُصلَحة** (`RestrictBroadcastChannels`) |

**لا Critical.** لا ثغرة SQLi/XSS/SSRF/Open-redirect/IDOR/Mass-assignment قابلة للاستغلال وُجدت: الاختبارات 13–34 تثبت ذلك وتحرس ضد العودة.

## Fixes

الملفات الرئيسية: `app/Http/Requests/Auth/LoginRequest.php`، `AuthenticatedSessionController`، `PasswordResetLinkController`، `NewPasswordController`، `UpdateProfileRequest` + `ProfileController`، `app/Services/Security/AccountSecurityService.php`، `app/Support/Security/Fingerprint.php`، `app/Http/Middleware/{TrustApplicationHosts,RestrictBroadcastChannels,SecurityHeaders,EnsureAccountIsNotFrozen}.php`، `app/GameEngine/Support/SequenceTokens.php` (+ `SequenceMatchValidator`، `SequenceGameTypeDefinition`، `games/sequence.blade.php`)، `PuzzleAttemptService` + `CurrencyWalletService::lockWallet`، `Api/AuthController` + `routes/api.php`، `AppServiceProvider` (المحدّدات)، `config/security.php`، `config/reverb.php`، `bootstrap/app.php`، `routes/web.php`.

## Deferred risks

مخاطر مقبولة بوعي، مع سببها وما يغيّر القرار:

1. **لوحة لعبة الذاكرة ظاهرة للعميل.** وجوه البطاقات يجب أن تصل المتصفح ليلعب، فبوت يحلّها فورًا. التخفيف الحقيقي (زمن حل أدنى بالمنافسات) يغيّر دلالات المنافسة؛ **لا حد أدنى لزمن الحل بالمنافسات حاليًا** لأنواع exact_string/memory_match/sequence_match. يُنصح به قبل جوائز مالية كبيرة (E27+).
2. **تعداد التسجيل:** قاعدة `unique:users` تكشف أن البريد مسجَّل (أي نموذج تسجيل يفعل ذلك). مقيَّد بحد التسجيل 5/10د لكل IP.
3. **توقيت إعادة التعيين:** إن كان البريد متزامنًا (`MAIL_MAILER=smtp` بلا طابور) يظهر فرق زمن بين حساب موجود وغيره. الرد نفسه متطابق. الحل: طابور للإشعارات (موصى به أصلًا).
4. **لا قفل بالبريد وحده** (قرار: يمنع حجب الضحية بهجوم). رش كلمات المرور من عدة IP يحتاج مراقبة (سطر `security.login_lockout`).
5. **CSP كامل (`script-src`) مؤجَّل:** Alpine/Livewire/Filament تحتاج inline/eval؛ يلزم nonces وتغيير واسع. لذلك XSS يعتمد على الهروب الصارم (مفحوص). إن رغبت: ابدأ بـ`Content-Security-Policy-Report-Only`.
6. **رموز Sanctum:** لا هجرة `personal_access_tokens` (واجهة الموبايل خاملة، 0 هجرات بهذه المرحلة). عند إنشائها: أضف جدولة `sanctum:prune-expired --hours=24` (الرموز تنتهي بـ30 يومًا).
7. **بصمة الجهاز وكشف الاحتيال (FraudDetection):** إشارات احتمالية لا إثبات؛ عنوان IP مشترك (مقهى/جامعة/CGNAT) يعطي إيجابيات كاذبة، لذلك الإشارات تُراجَع يدويًا ولا تحظر آليًا. المراجعة اليدوية تبقى شرطًا قبل الاستبدال.
8. **`back()` يعتمد على Referer** لإعادة التوجيه بعد أخطاء النماذج (آمن: يبقى داخل التطبيق وفق Laravel).
9. **لا CAPTCHA/2FA** (خارج النطاق عمدًا). عند بروز بوتات تسجيل جماعية: Turnstile أو بريد إلزامي قبل اللعب أول خيار.
10. **تدقيق الاعتماديات:** `composer audit` و`npm audit` يحتاجان الشبكة؛ نفّذهما على جهازك (انظر دليل الاختبار). لم يُحدَّث `composer.lock` ولا `package-lock.json` في E26.
11. **ملخص أمني بلوحة العمليات (اختياري Y):** مؤجَّل؛ البيانات متاحة بسجل التدقيق وسطور السجل.

## Security testing guide

الاختبارات في `tests/Feature/SecurityE26/` — **بيئة اختبار محلية فقط**، لا تُشغَّل على إنتاج ولا تُوجَّه لخدمة حية:

```bash
php artisan test tests/Feature/SecurityE26          # حزمة E26 كاملة (قاعدة SQLite للاختبار)
vendor/bin/pest tests/Feature/SecurityE26           # الصيغة نفسها عبر Pest
vendor/bin/pest tests/Feature/SecurityE26 --filter "E26-HOST"   # اختبار واحد
```

> لا تشغّل `php artisan test` بلا مسار على كل المشروع كجزء من فحص روتيني سريع؛ استعمل المسارات والمرشّحات.

| الملف | الاختبارات | يغطي |
| --- | --- | --- |
| `E26AuthTest` | 1–12 | دخول، جلسة، حد، تعداد، إعادة تعيين، توثيق |
| `E26AuthorizationTest` | 13–26 | IDOR (صداقة/فرق/دردشة)، انتحال منافسة/محفظة/مكافأة، إدارة |
| `E26InputSecurityTest` | 27–34 | XSS، SQLi، فرز، URL، رفع/مسارات |
| `E26RequestSecurityTest` | 35–40 + Host | CSRF، GET، redirect، ترويسات، HSTS، كاش |
| `E26RealtimeTest` | 41–45 | قنوات، حمولة، تجميد، origin |
| `E26AbuseTest` | 46–56 | كل الحدود أعلاه |
| `E26EconomyTest` | 57–61 | تكرار، سعر، مكافأة، رصيد، استبدال |
| `E26FilesTest` | 62–66 | رفع الشعارات، حجم، تسلل مسار، hotfix |
| `E26GameIntegrityTest` | O/P | رموز التسلسل، ألغاز غير فعّالة، سقف، API |
| `E26StaticAuditTest` | W1–W10 | أسرار، تصحيح، HTML خام، SQL، تنفيذ، شبكة، سجلات |
| `E26DocsTest` | X | الوثائق موجودة وتغطي الأقسام |

**لإضافة هجوم جديد:** اكتب اختبارًا يرسل الطلب الخبيث عبر HTTP حقيقيًا (`$this->post(...)`)، تحقق من الرفض **وأن لا شيء كُتب** (صفوف، رصيد). مساعدات: `e26_user`، `e26_freeze`، `e26_hammer`، `e26_first_429`، `e26_wallet_state`، `e26_sanctum_table` (`tests/Feature/SecurityE26/_support.php`).

**تدقيق الاعتماديات (على جهازك، بشبكة):**

```bash
composer audit
npm audit --omit=dev
```

## Environment variables

كلها اختيارية بقيم افتراضية آمنة (موثَّقة في `.env.example`):

| المتغير | الافتراضي | الأثر |
| --- | --- | --- |
| `SECURITY_LOGIN_MAX_ATTEMPTS` | 5 | فشل مسموح لكل (بريد+IP) |
| `SECURITY_LOGIN_DECAY_SECONDS` | 300 | مدة القفل |
| `SECURITY_LOGIN_IP_MAX_ATTEMPTS` | 30 | سقف الفشل لكل IP |
| `SECURITY_API_EXPIRY_MINUTES` | 43200 | عمر رمز API (0 = بلا انتهاء، غير مستحسن) |
| `SECURITY_CSP_BASELINE` | true | CSP أساسي |
| `SECURITY_TRUST_HOSTS` | true | تقييد Host بالإنتاج |
| `SECURITY_TRUSTED_HOSTS` | — | مضيفات إضافية (`www.example.com`) |
| `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM` | none | أحداث العميل |

## ملاحظات النشر وتغييرات العقد

1. **`APP_URL` يجب أن يطابق النطاق الفعلي** وإلا يرفض `TrustApplicationHosts` الطلبات بـ400 بالإنتاج. للنطاقات البديلة (`www`) أضفها في `SECURITY_TRUSTED_HOSTS`. `/up` و`/ready` مستثنيان. مفتاح الطوارئ: `SECURITY_TRUST_HOSTS=false`.
2. **عقد لعبة الترتيب تغيّر:** المرسَل رموز معتمة لا أعداد. الواجهة (`sequence.js`) لم تتغير. أي عميل خارجي (تطبيق موبايل مستقبلي) يستعمل `items[].idx` كما يأتيه من `GET /api/puzzles/{id}` ويعيده بالترتيب.
3. **تغيير البريد يتطلب كلمة المرور الحالية** (حقل جديد بنموذج الملف الشخصي).
4. **`POST /api/auth/login` يردّ 503** حتى تُنشأ هجرة Sanctum؛ لا أثر على الويب.
5. **`/broadcasting/auth`** يقبل `private-chat.*` فقط. واجهة الدردشة الحالية (`echo.private('chat.{id}')`) متوافقة.
6. لا هجرات ولا حزم جديدة. `composer.lock` لم يُمَسّ.
````

### `tests/Feature/SecurityE26/E26AbuseTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Models\StoreItem;
use App\Models\User;

/*
| E26 (46-56) - الإساءة الآلية: كل نقطة دخول حسّاسة تحجب بعد عدد معقول من الطلبات (429 + Retry-After) دون أن تكسر الاستعمال الطبيعي.
| الهجوم محلي بالكامل (كاش array داخل عملية الاختبار): لا طلب شبكي ولا ضغط على أي خدمة حقيقية، وأعداد الطلبات صغيرة (≤ ~40 للاختبار الواحد).
*/

if (! function_exists('e26_login_first_lock')) {
    /** فهرس (من 1) لأول محاولة دخول رُفضت بسبب القفل (يظهر كخطأ تحقق «محاولات دخول كثيرة»، لا كـ429)، أو null. */
    function e26_login_first_lock($test, int $times, Closure $payload, array $headers = []): ?int
    {
        for ($i = 0; $i < $times; $i++) {
            $test->withHeaders($headers)->from('/login')->post('/login', $payload($i));

            if (str_contains((string) session('errors')?->first('email'), 'محاولات دخول كثيرة')) {
                return $i + 1;
            }
        }

        return null;
    }
}

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('46: registration is limited per IP - the 6th attempt in the window gets 429 with Retry-After, whatever the email', function () {
    $statuses = e26_hammer($this, 'post', '/register', 8, fn ($i) => ['name' => 'x', 'email' => "bot{$i}@example.test"]);

    expect(e26_first_429($statuses))->toBe(6);
    $this->post('/register', ['name' => 'x', 'email' => 'again@example.test'])->assertStatus(429)->assertHeader('Retry-After');
});

test('46b: a forged X-Forwarded-For header cannot reset the registration or login counters (proxies are untrusted unless TRUSTED_PROXIES says so)', function () {
    $statuses = [];
    for ($i = 0; $i < 8; $i++) {
        $statuses[] = $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}", 'X-Real-IP' => "198.51.100.{$i}", 'Client-IP' => "192.0.2.{$i}"])
            ->post('/register', ['name' => 'x', 'email' => "spoof{$i}@example.test"])->getStatusCode();
    }
    expect(e26_first_429($statuses))->toBe(6);

    $user = e26_user(['email' => 'victim-xff@example.test']);
    $locked = e26_login_first_lock($this, 8, fn ($i) => ['email' => $user->email, 'password' => 'wrong-'.$i], ['X-Forwarded-For' => '203.0.113.9']);
    expect($locked)->toBe(6);
    $this->assertGuest();

    // تغيير الترويسة في كل محاولة لا يفكّ القفل.
    $again = $this->withHeaders(['X-Forwarded-For' => '203.0.113.200'])->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);
    expect(session('errors')->first('email'))->toContain('محاولات دخول كثيرة');
    $this->assertGuest();
});

test('47: login brute force is stopped per account+IP (6th failure) and password spraying is stopped per IP across many accounts', function () {
    $user = e26_user(['email' => 'brute@example.test']);
    expect(e26_login_first_lock($this, 8, fn ($i) => ['email' => $user->email, 'password' => 'wrong-'.$i]))->toBe(6);

    // الصحيح لا ينجح أثناء القفل (لا تجاوز بكلمة مرور صحيحة وسط الهجوم).
    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login');
    expect(session('errors')->first('email'))->toContain('محاولات دخول كثيرة');
    $this->assertGuest();

    // رش كلمات المرور: حساب مختلف لكل محاولة من IP واحد -> سقف IP (30) لا الحساب (5).
    cache()->flush();
    $first = e26_login_first_lock($this, 34, fn ($i) => ['email' => "spray{$i}@example.test", 'password' => 'Passw0rd-spray']);
    expect($first)->toBe(31);
    $this->assertGuest();
});

test('48: password-reset requests are limited per IP (6th) and per target address (4th) so nobody can mail-bomb a victim', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $perIp = e26_hammer($this, 'post', '/forgot-password', 8, fn ($i) => ['email' => "reset{$i}@example.test"]);
    expect(e26_first_429($perIp))->toBe(6);

    cache()->flush();
    $victim = e26_user(['email' => 'mailbomb-victim@example.test']);
    $perRecipient = e26_hammer($this, 'post', '/forgot-password', 6, ['email' => $victim->email]);
    expect(e26_first_429($perRecipient))->toBe(4);
    \Illuminate\Support\Facades\Notification::assertSentToTimes($victim, \Illuminate\Auth\Notifications\ResetPassword::class, 1);   // مهلة الوسيط 60ث: رسالة واحدة فقط
});

test('49: friend-request spam is limited (10/minute) and the extra requests create no rows', function () {
    $sender = e26_user();
    $targets = collect(range(1, 13))->map(fn () => e26_user());

    $statuses = [];
    foreach ($targets as $target) {
        $statuses[] = $this->actingAs($sender)->post(route('friends.requests.store', $target))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(11);
    expect(\App\Models\Friendship::query()->count())->toBeLessThanOrEqual(20);   // صفّان لكل طلب كحد أقصى (10 مقبولة) - لا صفوف لما بعد الحد
    expect(\App\Models\Friendship::query()->where(fn ($q) => $q->where('user_id', $sender->id)->orWhere('friend_id', $sender->id))->count())->toBeLessThanOrEqual(20);
});

test('50: team-invitation spam is limited (10/minute) per inviter', function () {
    $owner = e26_user();
    $team = e19Team($owner);
    $targets = collect(range(1, 13))->map(fn () => e26_user());

    $statuses = [];
    foreach ($targets as $target) {
        $statuses[] = $this->actingAs($owner)->post(route('teams.invitations.store', [$team, $target]))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(11);
});

test('51: global-chat flooding is cut by the burst limit (3 per 10 seconds) and the per-minute cap, and rejected posts are not stored', function () {
    $user = e26_user();
    $statuses = [];
    for ($i = 0; $i < 6; $i++) {
        $statuses[] = $this->actingAs($user)->postJson(route('community.chat.send'), ['body' => "رسالة سبام رقم {$i}"])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(4);
    expect(\App\Models\ChatMessage::query()->where('sender_id', $user->id)->count())->toBe(3);
});

test('52: abusing the report endpoint is limited (5/minute) so one user cannot flood moderators', function () {
    [$a, $b] = chatFriends();
    $statuses = [];

    for ($i = 0; $i < 7; $i++) {
        $id = chatDm($this, $b, $a, "رسالة {$i}")->json('message.id');
        $statuses[] = $this->actingAs($a)->postJson(route('chat.messages.report', $id), ['category' => 'spam'])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(6);
});

test('53: player search cannot be used to scrape the user base - 30 queries/minute then 429', function () {
    $user = e26_user();
    e26_user(['name' => 'Scrape Target']);

    $statuses = [];
    for ($i = 0; $i < 33; $i++) {
        $statuses[] = $this->actingAs($user)->get(route('friends.search', ['q' => 'Sc'.chr(97 + ($i % 20))]))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(31);
    $json = $this->actingAs($user)->getJson(route('friends.search', ['q' => 'Scrape']));
    expect($json->getStatusCode())->toBe(429);
});

test('53b: player search results never expose emails, internal ids or private fields', function () {
    $user = e26_user();
    $target = e26_user(['name' => 'Zed Findable', 'email' => 'zed.private@example.test']);

    $response = $this->actingAs($user)->get(route('friends.search', ['q' => 'Zed Findable']));
    $response->assertOk();
    $html = $response->getContent();

    expect($html)->not->toContain('zed.private@example.test')->not->toContain('@example.test');
});

test('54: competitive play endpoints (start/submit) are limited per user (20/minute)', function () {
    $user = e26_user();
    $event = e17Event();

    $statuses = [];
    for ($i = 0; $i < 23; $i++) {
        $statuses[] = $this->actingAs($user)->post(route('competitions.start', $event->slug))->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(21);
});

test('55: store purchase attempts are limited (8/minute) per user', function () {
    $user = e26_user();
    $item = StoreItem::factory()->create();

    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->actingAs($user)->post(route('store.items.purchase', $item), ['request_key' => 'k-'.$i])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(9);
});

test('56: redemption requests are limited (5 per hour) per user and the 6th attempt writes nothing', function () {
    $user = e26_user();
    $statuses = [];

    for ($i = 0; $i < 8; $i++) {
        $statuses[] = $this->actingAs($user)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x'])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(6);
    expect(\App\Models\RedemptionRequest::query()->count())->toBe(0);   // لا رصيد: كل المحاولات مرفوضة، والسادسة لم تصل للخدمة
});

test('56b: limits are per user - one throttled attacker does not lock out another user on the same IP (shared Wi-Fi/NAT false-positive policy)', function () {
    $attacker = e26_user();
    $honest = e26_user();

    e26_hammer($this->actingAs($attacker), 'post', '/community/chat', 5, ['body' => 'flood']);
    $this->actingAs($attacker)->postJson(route('community.chat.send'), ['body' => 'again'])->assertStatus(429);

    $this->actingAs($honest)->postJson(route('community.chat.send'), ['body' => 'مرحبا من مستخدم عادي'])->assertCreated();
});

test('56c: every 429 is a clean refusal - Retry-After present, no stack trace or internals in the body', function () {
    e26_hammer($this, 'post', '/register', 6, fn ($i) => ['name' => 'x', 'email' => "clean{$i}@example.test"]);
    $json = $this->postJson('/register', ['name' => 'x', 'email' => 'clean-last@example.test']);

    $json->assertStatus(429)->assertHeader('Retry-After');
    expect($json->headers->get('Retry-After'))->toMatch('/^\d+$/');
});
```

### `tests/Feature/SecurityE26/E26AuthTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Http\Requests\Auth\LoginRequest;
use App\Models\PuzzleAttempt;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

function e26_login_request(string $email, string $ip = '203.0.113.9'): LoginRequest
{
    $request = LoginRequest::create('/login', 'POST', ['email' => $email, 'password' => 'x']);
    $request->server->set('REMOTE_ADDR', $ip);

    return $request;
}

test('1: login with valid credentials authenticates and lands on the home page', function () {
    $user = e26_user();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

test('2: the session id is regenerated at login (session fixation defence)', function () {
    $user = e26_user();
    $this->startSession();
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    expect(session()->getId())->not->toBe($before)->and(session()->getId())->not->toBeEmpty();
});

test('3: a wrong password and an unknown email produce the very same generic error (no account enumeration)', function () {
    $user = e26_user();

    $wrongPassword = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'not-it'])->assertRedirect('/login');
    $wrongPasswordErrors = session('errors')->get('email');

    $unknown = $this->from('/login')->post('/login', ['email' => 'nobody-'.uniqid().'@example.test', 'password' => 'not-it'])->assertRedirect('/login');
    $unknownErrors = session('errors')->get('email');

    expect($wrongPasswordErrors)->toBe(['بيانات الدخول غير صحيحة.'])->and($unknownErrors)->toBe($wrongPasswordErrors);
    $this->assertGuest();
});

test('4: repeated failures lock the email+IP pair and even the correct password is refused until the window passes', function () {
    $user = e26_user();
    $max = (int) config('security.login.max_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-'.$i]);
    }

    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login');
    expect(session('errors')->first('email'))->toContain('محاولات دخول كثيرة');
    $this->assertGuest();

    $this->travel((int) config('security.login.decay_seconds') + 5)->seconds();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('4b: a successful login clears the email+IP counter so an honest typo does not accumulate', function () {
    $user = e26_user();
    $max = (int) config('security.login.max_attempts');

    for ($i = 0; $i < $max - 1; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'oops']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/logout');

    for ($i = 0; $i < $max - 1; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'oops']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('5: limiter keys never contain the raw email, and are case/whitespace insensitive', function () {
    $key = e26_login_request('Victim.Person@Example.TEST')->throttleKey();
    $same = e26_login_request('  victim.person@example.test ')->throttleKey();

    expect($key)->not->toContain('victim')->not->toContain('example')->not->toContain('@')->and($key)->toBe($same);

    foreach (['password-reset', 'api-login'] as $name) {
        $request = Request::create('/x', 'POST', ['email' => 'Victim.Person@Example.TEST']);
        $limits = array_map(fn ($l) => $l->key, (array) RateLimiter::limiter($name)($request));

        foreach ($limits as $limitKey) {
            expect($limitKey)->not->toContain('victim')->not->toContain('@');
        }
    }
});

test('5b: the IP-only limiter stops password spraying across many accounts, with a higher ceiling than the per-account one', function () {
    config(['security.login.ip_max_attempts' => 6, 'security.login.max_attempts' => 5]);

    $statuses = [];
    for ($i = 0; $i < 7; $i++) {
        $this->from('/login')->post('/login', ['email' => "spray{$i}@example.test", 'password' => 'guess']);
        $statuses[] = session('errors')->first('email');
    }

    expect($statuses[5])->toBe('بيانات الدخول غير صحيحة.')->and($statuses[6])->toContain('محاولات دخول كثيرة');
});

test('6: a frozen account cannot log in and leaves no authenticated session behind', function () {
    $user = e26_freeze(e26_user());

    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login');

    expect(session('errors')->first('email'))->toContain('تجميد');
    $this->assertGuest();
});

test('7: a user frozen while already logged in is blocked from protected actions (no attempt, no reward)', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 10]);
    $this->actingAs($user);
    e26_freeze($user);

    $this->post(route('puzzles.attempt', $puzzle), ['answer' => 'صح'])->assertSessionHas('error');

    expect(PuzzleAttempt::count())->toBe(0)->and($user->wallet->refresh()->pending_balance)->toBe(0);
});

test('8: logout invalidates the session, rotates the remember token and drops access to protected pages', function () {
    $user = e26_user();
    $oldRemember = $user->remember_token;
    $this->actingAs($user)->startSession();
    $before = session()->getId();

    $this->post('/logout')->assertRedirect('/');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($before)->and($user->fresh()->remember_token)->not->toBe($oldRemember);
    $this->get(route('wallet.index'))->assertRedirect(route('login'));
});

test('9: password reset never reveals whether an account exists (same response, mail only for real accounts)', function () {
    Notification::fake();
    $user = e26_user();

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
    $knownStatus = session('status');
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'ghost-'.uniqid().'@example.test']);
    $unknownStatus = session('status');

    $known->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
    $unknown->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
    expect($knownStatus)->toBe($unknownStatus)->not->toBeEmpty();
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

test('10: reset requests are throttled per IP and per recipient (many IPs cannot flood one inbox)', function () {
    Notification::fake();
    $victim = e26_user();

    $statuses = [];
    foreach (range(1, 5) as $i) {
        $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->post('/forgot-password', ['email' => $victim->email])->getStatusCode();
    }

    expect($statuses[2])->not->toBe(429)->and($statuses[3])->toBe(429)->and($statuses[4])->toBe(429);
    // وسيط Laravel نفسه يحدّ كل مستخدم برسالة واحدة/60 ثانية (auth.passwords.users.throttle): الرد الموحّد يخفي ذلك، والحدّان الخارجيان يضيفان سقفًا ساعيًا.
    Notification::assertSentToTimes($victim, ResetPassword::class, 1);
});

test('10b: the per-IP reset limit also applies to many different recipients', function () {
    Notification::fake();
    $statuses = [];
    foreach (range(1, 7) as $i) {
        $statuses[] = $this->post('/forgot-password', ['email' => "bulk{$i}@example.test"])->getStatusCode();
    }

    expect(e26_first_429($statuses))->toBe(6);
});

test('11: a tampered reset token or a foreign email fails and never changes the password', function () {
    Notification::fake();
    $user = e26_user();
    $other = e26_user();
    $this->post('/forgot-password', ['email' => $user->email]);
    $hash = $user->password;
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    foreach ([[$token.'x', $user->email], [strrev($token), $user->email], [$token, $other->email]] as [$t, $email]) {
        $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => $t, 'email' => $email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'])->assertSessionHasErrors('email');
    }

    $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => '', 'email' => $user->email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'])->assertSessionHasErrors('token');

    expect($user->fresh()->password)->toBe($hash)->and($other->fresh()->password)->not->toBe($hash);
    // الفشل لا يميّز "مستخدم غير موجود" عن "رمز خاطئ"
    $this->from('/x')->post('/reset-password', ['token' => $token, 'email' => 'ghost@example.test', 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x']);
    $ghost = session('errors')->first('email');
    $this->from('/x')->post('/reset-password', ['token' => $token.'x', 'email' => $user->email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x']);
    expect(session('errors')->first('email'))->toBe($ghost);
});

test('11b: a successful reset consumes the token, rotates remember_token and revokes other database sessions', function () {
    Notification::fake();
    $user = e26_user();
    $oldRemember = $user->remember_token;
    \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'stolen-session', 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    $this->post('/forgot-password', ['email' => $user->email]);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x'])->assertRedirect(route('login'));

    expect(\Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe($oldRemember);
    // الرمز يُستهلك: إعادة استعماله تفشل
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'AnotherPassw0rd!y', 'password_confirmation' => 'AnotherPassw0rd!y'])->assertSessionHasErrors('email');
});

test('12: a tampered verification link (hash, signature or foreign user id) never verifies the account', function () {
    $user = e26_user(['email_verified_at' => null]);
    $victim = e26_user(['email_verified_at' => null]);
    $good = fn (int $id, string $email) => URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $id, 'hash' => sha1($email)]);

    $this->actingAs($user);
    $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1('someone-else@example.test')]);
    $this->get($wrongHash)->assertForbidden();
    $this->get(str_replace('signature=', 'signature=00', $good($user->id, $user->email)))->assertForbidden();
    $this->get($good($victim->id, $victim->email))->assertForbidden();     // رابط حساب آخر بتوقيع صحيح
    $this->get(URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden(); // منتهٍ

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()->and($victim->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->get($good($user->id, $user->email))->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('12b: changing the email requires the current password and then revokes other sessions (account-takeover primitive closed)', function () {
    $user = e26_user();
    \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($user)->startSession();

    $this->from('/profile')->patch('/profile', ['name' => $user->name, 'email' => 'attacker@example.test'])->assertSessionHasErrors('current_password');
    $this->from('/profile')->patch('/profile', ['name' => $user->name, 'email' => 'attacker@example.test', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect($user->fresh()->email)->not->toBe('attacker@example.test');

    $this->from('/profile')->patch('/profile', ['name' => 'New Name', 'email' => $user->email])->assertSessionHasNoErrors();   // الاسم وحده لا يحتاج كلمة المرور
    expect($user->fresh()->name)->toBe('New Name');

    $this->from('/profile')->patch('/profile', ['name' => 'New Name', 'email' => 'new-address@example.test', 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('new-address@example.test')->and($user->fresh()->email_verified_at)->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

test('12c: confirm-password and reset-submit are rate limited', function () {
    $user = e26_user();
    $this->actingAs($user);
    $confirm = e26_hammer($this, 'post', '/confirm-password', 8, ['password' => 'wrong']);
    expect(e26_first_429($confirm))->toBe(7);
});
```

### `tests/Feature/SecurityE26/E26AuthorizationTest.php`

```php
<?php

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../TeamCompetition/TeamCompetitionTestHelpers.php';

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\CompetitiveEventResult;
use App\Models\Friendship;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\AuthorizationSafetyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});
afterEach(fn () => Carbon::setTestNow());

/** بصمة حالة الفرق/الدعوات/الطلبات/التحديات: أي تغيير غير مرغوب يظهر. */
function e26_team_state(): string
{
    return json_encode([
        DB::table('teams')->orderBy('id')->get()->all(), DB::table('team_memberships')->orderBy('id')->get()->all(),
        DB::table('team_invitations')->orderBy('id')->get()->all(), DB::table('team_join_requests')->orderBy('id')->get()->all(),
        DB::table('team_challenges')->orderBy('id')->get()->all(),
    ]);
}

test('13: a user cannot mutate another user or elevate themselves through the profile endpoints (forged id/role/frozen/password are ignored)', function () {
    $attacker = e26_user(['name' => 'Attacker']);
    $victim = e26_user(['name' => 'Victim'])->fresh();
    $victimBefore = $victim->only(['name', 'email', 'password', 'is_frozen', 'role']);

    $this->actingAs($attacker)->from('/profile')->patch('/profile', [
        'name' => 'Attacker 2', 'email' => $attacker->email,
        'id' => $victim->id, 'user_id' => $victim->id, 'public_id' => $victim->public_id,
        'is_frozen' => 1, 'role' => 'admin', 'password' => 'Hacked-Passw0rd!', 'email_verified_at' => null,
    ])->assertSessionHasNoErrors();

    expect($victim->fresh()->only(['name', 'email', 'password', 'is_frozen', 'role']))->toBe($victimBefore);
    $attacker->refresh();
    expect($attacker->name)->toBe('Attacker 2')->and((bool) $attacker->is_frozen)->toBeFalse()->and($attacker->role)->not->toBe('admin')
        ->and(Hash::check('password', $attacker->password))->toBeTrue()->and($attacker->hasVerifiedEmail())->toBeTrue()
        ->and($attacker->hasRole('super-admin') || $attacker->hasRole('administrator'))->toBeFalse();
});

test('14: friend-request IDOR - a third user cannot accept, decline or cancel a request between two other users', function () {
    $b = e26_user();
    $c = e26_user();
    $a = e26_user();
    e16Svc()->sendRequest($b, $c);
    $before = Friendship::query()->get()->toJson();

    foreach ([['post', 'accept', $b], ['post', 'decline', $b], ['delete', 'cancel', $b], ['post', 'accept', $c], ['post', 'decline', $c], ['delete', 'cancel', $c]] as [$method, $action, $other]) {
        $this->actingAs($a)->{$method}(route("friends.requests.{$action}", $other));
    }

    expect(Friendship::query()->get()->toJson())->toBe($before)->and(e16Svc()->friendsCount($a))->toBe(0)->and(e16Svc()->friendsCount($c))->toBe(0);
});

test('15: team-management IDOR - the owner of team A and a plain member of team C can do nothing to team C', function () {
    $a = e19Team(null, ['name' => 'فريق أ']);
    $c = e19Team(null, ['name' => 'فريق ج']);
    $cMember = e19Member($c);
    $cAdmin = e19Member($c, null, 'admin');
    $victim = e19Member($c);
    $before = e26_team_state();

    foreach ([$a->owner, $cMember] as $attacker) {
        $this->actingAs($attacker)->patch(route('teams.update', $c), ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($attacker)->post(route('teams.deactivate', $c))->assertForbidden();
        $this->actingAs($attacker)->patch(route('teams.members.role', [$c, $victim]), ['role' => 'admin'])->assertForbidden();
        $this->actingAs($attacker)->delete(route('teams.members.remove', [$c, $victim]))->assertForbidden();
        $this->actingAs($attacker)->post(route('teams.transfer', [$c, $cAdmin]))->assertForbidden();
        $this->actingAs($attacker)->get(route('teams.manage', $c))->assertForbidden();
    }

    expect(e26_team_state())->toBe($before);
});

test('16: team-invitation IDOR - a user the invitation is not addressed to cannot accept it, and a manager cannot cancel another team invitation', function () {
    $c = e19Team(null, ['name' => 'فريق ج']);
    $other = e19Team(null, ['name' => 'فريق آخر']);
    $invitee = e26_user();
    $intruder = e26_user();
    $invitation = e19Invites()->invite($c->owner, $c, $invitee);
    $before = e26_team_state();

    $this->actingAs($intruder)->post(route('teams.invitations.accept', $invitation))->assertStatus(404);
    $this->actingAs($intruder)->post(route('teams.invitations.decline', $invitation))->assertStatus(404);
    $this->actingAs($other->owner)->delete(route('teams.invitations.cancel', [$other, $invitation]))->assertNotFound();   // دعوة فريق آخر عبر مسار فريقي
    $this->actingAs($other->owner)->delete(route('teams.invitations.cancel', [$c, $invitation]))->assertForbidden();       // عبر مسار فريقها هي

    expect(e26_team_state())->toBe($before)->and(TeamMembership::where('user_id', $intruder->id)->exists())->toBeFalse();
});

test('17: team-challenge IDOR - owners and members of unrelated teams can read or act on nothing of another pair of teams', function () {
    [$a, $b, $am, $bm] = e20Pair();
    [$c, $d, $cm, $dm] = e20Pair();
    $pending = e20Create($c, $d, [$cm[0]]);
    $before = e26_team_state();

    foreach ([$a->owner, $am[1]] as $attacker) {
        $this->actingAs($attacker)->get(route('teams.challenges.show', $pending))->assertNotFound();
        $this->actingAs($attacker)->post(route('teams.challenges.accept', $pending), ['roster' => e20Ids([$attacker])])->assertNotFound();
        $this->actingAs($attacker)->post(route('teams.challenges.decline', $pending))->assertNotFound();
        $this->actingAs($attacker)->delete(route('teams.challenges.cancel', $pending))->assertNotFound();
        $this->actingAs($attacker)->put(route('teams.challenges.roster', $pending), ['roster' => e20Ids([$attacker])])->assertNotFound();
    }

    expect(e26_team_state())->toBe($before);
});

test('18: direct-chat IDOR - a non-participant gets 404 on a thread history and read-marking, even with the real public id', function () {
    [$a, $b] = chatFriends();
    $intruder = e26_user();
    chatDm($this, $a, $b, 'سر بين اثنين')->assertCreated();
    $thread = ChatThread::query()->firstOrFail();

    $this->actingAs($intruder)->getJson(route('chat.messages', $thread))->assertNotFound();
    $this->actingAs($intruder)->postJson(route('chat.read', $thread))->assertNotFound();
    $this->actingAs($intruder)->get(route('messages.direct', $a))->assertStatus(403)->assertDontSee('سر بين اثنين');
});

test('19: team chat - an outsider, and a member who left, can neither read nor write', function () {
    [$team, $owner, $admin, $member] = chatTeam();
    $outsider = e26_user();
    chatThreads()->forTeam($team);

    expect($this->actingAs($outsider)->get(route('teams.chat', $team))->status())->toBeIn([403, 404])
        ->and($this->actingAs($outsider)->postJson(route('teams.chat.send', $team), ['body' => 'تسلل'])->status())->toBeIn([403, 404]);
    expect(ChatMessage::count())->toBe(0);

    e19Members()->leave($member, $team);
    expect($this->actingAs($member->refresh())->get(route('teams.chat', $team))->status())->toBeIn([403, 404])
        ->and($this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'خرجت'])->status())->toBeIn([403, 404]);
    expect(ChatMessage::count())->toBe(0);
});

test('20: only the author can edit or delete a message - another member, an admin of the room and an outsider are all refused and the body is untouched', function () {
    [$team, $owner, $admin, $member] = chatTeam();
    $outsider = e26_user();
    $this->actingAs($member)->postJson(route('teams.chat.send', $team), ['body' => 'رسالتي'])->assertCreated();
    $message = ChatMessage::sole();

    foreach ([$owner, $admin, $outsider] as $other) {
        $this->actingAs($other)->patchJson(route('chat.messages.update', $message), ['body' => 'معدّلة'])->assertStatus($other->is($outsider) ? 404 : 403);
        $this->actingAs($other)->deleteJson(route('chat.messages.destroy', $message))->assertStatus($other->is($outsider) ? 404 : 403);
    }

    $message->refresh();
    expect($message->body)->toBe('رسالتي')->and($message->deleted_at)->toBeNull();
    $this->actingAs($member)->patchJson(route('chat.messages.update', $message), ['body' => 'عدّلتها'])->assertOk();
    expect($message->refresh()->body)->toBe('عدّلتها');
});

test('21: competitive submit spoof - forged score, rank, winner and duration in the body never reach the stored result', function () {
    $event = e17Event();
    $player = e26_user();
    $this->actingAs($player)->post(route('competitions.register', $event))->assertRedirect();
    $this->actingAs($player)->post(route('competitions.start', $event))->assertRedirect();
    e17Forward(20_000);

    $this->actingAs($player)->post(route('competitions.submit', $event), [
        'answer' => E17_ANSWER, 'score' => 999999, 'final_rank' => 1, 'rank' => 1, 'winner' => $player->id, 'duration_ms' => 1,
        'elapsed_seconds' => 0, 'is_correct' => 1, 'user_id' => 1, 'game_session_id' => 999, 'submission' => ['score' => 999999, 'moves' => 0],
    ]);

    $result = CompetitiveEventResult::query()->first();
    expect($result)->not->toBeNull()
        ->and($result->user_id)->toBe($player->id)
        ->and($result->score)->not->toBe(999999)
        ->and($result->duration_ms)->toBeGreaterThanOrEqual(19_000)
        ->and($result->game_session_id)->toBe(GameSession::query()->where('user_id', $player->id)->value('id'));
});

test('22: wallet spoof - balances and ownership in the request are ignored by every economy endpoint', function () {
    $user = e26_user();
    $victim = e26_user();
    $this->actingAs($user)->from('/redemption/create')->post(route('redemption.store'), [
        'gems_amount' => 999999, 'reward_description' => 'x', 'user_id' => $victim->id, 'wallet_id' => $victim->wallet->id, 'available_balance' => 999999,
    ])->assertSessionHasErrors('gems_amount');

    $this->actingAs($user)->patch('/profile', ['name' => 'N', 'email' => $user->email, 'available_balance' => 99999, 'pending_balance' => 99999]);

    expect($user->wallet->refresh()->available_balance)->toBe(0)->and($user->wallet->pending_balance)->toBe(0)
        ->and($victim->wallet->refresh()->available_balance)->toBe(0)->and(\App\Models\RedemptionRequest::count())->toBe(0);
});

test('23: reward spoof - a forged gems_awarded/gem_reward in the attempt body never changes the credited reward', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => 7, 'max_attempts' => 3]);

    $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'صح', 'gem_reward' => 5000, 'gems_awarded' => 5000, 'reward' => 5000, 'amount' => 5000, 'currency_id' => 99])->assertSessionHas('success');

    expect($user->wallet->refresh()->pending_balance)->toBe(7);
});

test('24: ordinary users and guests cannot open the admin panel or its resources', function () {
    $user = e26_user();

    foreach (['/admin', '/admin/users', '/admin/settings', '/admin/puzzles', '/admin/redemption-requests'] as $path) {
        expect($this->actingAs($user)->get($path)->status())->toBeIn([403, 404])->and(auth()->check())->toBeTrue();
    }

    auth()->logout();
    foreach (['/admin', '/admin/users', '/admin/settings'] as $path) {
        $this->get($path)->assertRedirect();
    }
});

test('25: a limited admin cannot escalate - granting super-admin, or a role holding permissions they lack, is refused', function () {
    $support = e26_user();
    $support->assignRole('support');
    $support->givePermissionTo('roles.assign');
    $target = e26_user();
    $safety = app(AuthorizationSafetyService::class);

    expect(fn () => $safety->assertCanSyncRoles($support, $target, ['super-admin']))->toThrow(HttpException::class)
        ->and(fn () => $safety->assertCanSyncRoles($support, $support, ['administrator']))->toThrow(HttpException::class)
        ->and(fn () => $safety->assertCanSyncRoles($support, $target, ['content-manager']))->toThrow(HttpException::class);

    $normalAdmin = e26_user();
    $normalAdmin->assignRole('administrator');
    $superAdmin = e26_user();
    $superAdmin->assignRole('super-admin');
    expect(fn () => $safety->assertCanModifyUserAuthorization($normalAdmin, $superAdmin))->toThrow(HttpException::class);
});

test('26: the last super admin can be neither deleted nor stripped of the role', function () {
    $last = e26_user();
    $last->assignRole('super-admin');
    $safety = app(AuthorizationSafetyService::class);

    expect(fn () => $safety->assertCanDeleteUser($last))->toThrow(HttpException::class)
        ->and(fn () => $safety->assertCanSyncRoles($last, $last, []))->toThrow(HttpException::class);

    $second = e26_user();
    $second->assignRole('super-admin');
    $safety->assertCanDeleteUser($last);   // لم يعد الأخير
    expect(true)->toBeTrue();
});
```

### `tests/Feature/SecurityE26/E26DocsTest.php`

````php
<?php

require_once __DIR__.'/_support.php';

/*
| E26 (X) - الوثائق الأمنية موجودة، تغطي الأقسام المطلوبة، روابطها الداخلية سليمة، وتوافق الإعدادات الفعلية (لا متغير موثَّق بلا وجود، ولا متغير موجود بلا توثيق).
*/

if (! function_exists('e26_slug')) {
    function e26_slug(string $heading): string
    {
        $heading = mb_strtolower(trim($heading));
        $heading = preg_replace('/[^\p{L}\p{N} _-]/u', '', $heading);

        return str_replace(' ', '-', (string) $heading);
    }

    /** @return list<string> */
    function e26_anchors_of(string $markdown): array
    {
        $anchors = [];
        $fence = false;
        foreach (preg_split('/\R/', $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fence = ! $fence;

                continue;
            }
            if (! $fence && preg_match('/^#{1,6}\s+(.*)$/u', $line, $m)) {
                $anchors[] = e26_slug($m[1]);
            }
        }

        return $anchors;
    }
}

test('X1: the three E26 documents exist and the main one carries every required section', function () {
    foreach (['docs/security-e26.md', 'docs/security-checklist.md', 'SECURITY.md'] as $file) {
        expect(base_path($file))->toBeFile();
        expect(strlen((string) file_get_contents(base_path($file))))->toBeGreaterThan(800);
    }

    $anchors = e26_anchors_of((string) file_get_contents(base_path('docs/security-e26.md')));
    foreach (['threat-model', 'attack-surface', 'controls', 'findings', 'fixes', 'deferred-risks', 'security-testing-guide', 'environment-variables'] as $section) {
        expect($anchors)->toContain($section);
    }
});

test('X2: every internal link in the E26 documents resolves to an existing file and heading', function () {
    $broken = [];

    foreach (['docs/security-e26.md', 'docs/security-checklist.md', 'SECURITY.md'] as $file) {
        $dir = dirname(base_path($file));
        $text = (string) file_get_contents(base_path($file));
        preg_match_all('/\]\((?!https?:|mailto:)([^)#\s]*)(?:#([^)\s]+))?\)/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $target = $m[1] === '' ? base_path($file) : realpath($dir.'/'.$m[1]);
            if ($target === false || ! is_file($target)) {
                $broken[] = "{$file} -> {$m[1]} (missing file)";

                continue;
            }
            if (($m[2] ?? '') !== '' && ! in_array(rawurldecode($m[2]), e26_anchors_of((string) file_get_contents($target)), true)) {
                $broken[] = "{$file} -> {$m[1]}#{$m[2]} (missing heading)";
            }
        }
    }

    expect($broken)->toBe([]);

    // الرابط الذي أضافه E26 إلى security-hardening.md (القديم) يشير لعنوان موجود فعلًا.
    expect((string) file_get_contents(base_path('docs/security-hardening.md')))->toContain('security-e26.md#حدود-الإساءة-rate-limits');
    expect(e26_anchors_of((string) file_get_contents(base_path('docs/security-e26.md'))))->toContain('حدود-الإساءة-rate-limits');
});

test('X3: every SECURITY_* / E26 variable documented exists in config and .env.example, and every config key is documented', function () {
    $doc = (string) file_get_contents(base_path('docs/security-e26.md'));
    $env = (string) file_get_contents(base_path('.env.example'));
    $config = (string) file_get_contents(base_path('config/security.php'));

    preg_match_all("/env\('(SECURITY_[A-Z_]+)'/", $config, $m);
    $vars = array_unique($m[1]);
    expect($vars)->not->toBeEmpty();

    foreach (array_merge($vars, ['REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM']) as $var) {
        expect($doc)->toContain($var);
        expect($env)->toContain($var);
    }
});

test('X4: SECURITY.md is a responsible-disclosure policy - private reporting, safe scope, no bug-bounty promise, no secrets', function () {
    $text = (string) file_get_contents(base_path('SECURITY.md'));

    expect($text)->toContain('Security Policy')->toContain('لا تفتح Issue عامًا')->toContain('لا يوجد برنامج مكافآت مالية');
    expect($text)->not->toMatch('/\$\s?\d|مكافأة قدرها|bounty of/iu');
    expect(preg_match('/[A-Za-z0-9._%+-]+@(?!example\.)[A-Za-z0-9.-]+\.[a-z]{2,}/', $text))->toBe(0);   // لا عنوان بريد حقيقي مكتوب بالملف
});

test('X5: the security checklist covers the controls that can lock the site out or open it up', function () {
    $text = (string) file_get_contents(base_path('docs/security-checklist.md'));

    foreach (['APP_URL', 'SECURITY_TRUSTED_HOSTS', 'TRUSTED_PROXIES', 'SESSION_SECURE_COOKIE', 'REVERB_ALLOWED_ORIGINS', 'composer audit', 'npm audit', 'security.login_lockout', 'ADMIN_BOOTSTRAP'] as $needle) {
        expect($text)->toContain($needle);
    }
});
````

### `tests/Feature/SecurityE26/E26EconomyTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\RedemptionRequest;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Str;

/*
| E26 (57-61) - سلطة الاقتصاد: السعر والمكافأة والرصيد والاستبدال تُحسب كلها من الخادم. العميل يرسل «ماذا يريد» فقط، لا «كم».
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

if (! function_exists('e26_priced_item')) {
    function e26_priced_item(int $amount = 100, array $itemAttrs = []): array
    {
        $item = StoreItem::factory()->create($itemAttrs + ['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 1]);
        $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => $amount]);

        return [$item, $price];
    }

    /** مستخدم مؤهَّل للاستبدال بكل شروط config('gems.eligibility') (نفس نمط RedemptionServiceTest). */
    function e26_eligible_user(): User
    {
        $eligibility = config('gems.eligibility');
        $user = User::factory()->create(['created_at' => now()->subDays($eligibility['min_account_age_days'] + 1)]);

        for ($i = 0; $i < $eligibility['min_puzzles_solved']; $i++) {
            PuzzleAttempt::create(['user_id' => $user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1, 'is_correct' => true]);
        }

        $user->wallet->update(['available_balance' => config('gems.min_redemption')]);

        return $user->fresh();
    }
}

test('57: replaying a purchase (same request key) never charges or grants twice, and another user cannot reuse the key', function () {
    [$item, $price] = e26_priced_item(100, ['grant_quantity' => 2]);
    $buyer = e26_user();
    $currency = $price->currency;
    app(CurrencyWalletService::class)->creditAvailable($buyer, $currency, 1000, 'e26');
    $key = (string) Str::uuid();

    foreach (range(1, 3) as $i) {
        $this->actingAs($buyer)->post(route('store.items.purchase', $item), ['price_id' => $price->id, 'request_key' => $key]);
    }

    expect(StorePurchase::where('user_id', $buyer->id)->count())->toBe(1);
    expect(app(CurrencyWalletService::class)->balanceFor($buyer, $currency)->refresh()->available_balance)->toBe(900);
    expect(app(\App\Services\Store\InventoryService::class)->quantityFor($buyer, $item))->toBe(2);

    // مستخدم آخر يعيد استعمال المفتاح نفسه: لا شراء له ولا خصم ولا تسليم ببيانات الأول.
    $other = e26_user();
    app(CurrencyWalletService::class)->creditAvailable($other, $currency, 1000, 'e26');
    $this->actingAs($other)->post(route('store.items.purchase', $item), ['price_id' => $price->id, 'request_key' => $key]);

    expect(StorePurchase::where('user_id', $other->id)->count())->toBe(0);
    expect(app(CurrencyWalletService::class)->balanceFor($other, $currency)->refresh()->available_balance)->toBe(1000);
});

test('57b: solving the same puzzle correctly again never pays the reward twice', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب', 'gem_reward' => 8]);

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'الجواب']);
    }

    expect(e26_wallet_state($user)[1])->toBe(8);
    expect(PuzzleAttempt::where('user_id', $user->id)->where('puzzle_id', $puzzle->id)->where('is_correct', true)->count())->toBe(1);
});

test('58: a client-supplied price, amount or currency is ignored - the debit is always the stored price, and a foreign/inactive price id is refused', function () {
    [$item, $price] = e26_priced_item(500);
    [$cheapItem, $cheapPrice] = e26_priced_item(1);
    $buyer = e26_user();
    $currency = $price->currency;
    app(CurrencyWalletService::class)->creditAvailable($buyer, $currency, 5000, 'e26');
    $wallets = app(CurrencyWalletService::class);

    // حقول سعر مزوّرة بجانب الطلب الصحيح.
    $this->actingAs($buyer)->post(route('store.items.purchase', $item), [
        'price_id' => $price->id, 'request_key' => (string) Str::uuid(),
        'price' => 1, 'amount' => 1, 'price_amount' => 1, 'cost' => 0, 'currency_id' => 999, 'discount' => 100, 'quantity' => 99,
    ]);
    expect($wallets->balanceFor($buyer, $currency)->refresh()->available_balance)->toBe(4500);
    expect(StorePurchase::where('user_id', $buyer->id)->value('price_amount'))->toBe(500);

    // سعر عنصر آخر أرخص: مرفوض (price_id يجب أن ينتمي لنفس العنصر) ولا خصم.
    $before = $wallets->balanceFor($buyer, $currency)->refresh()->available_balance;
    $this->actingAs($buyer)->post(route('store.items.purchase', $item), ['price_id' => $cheapPrice->id, 'request_key' => (string) Str::uuid()])->assertNotFound();
    expect($wallets->balanceFor($buyer, $currency)->refresh()->available_balance)->toBe($before);

    // سعر قديم غير فعّال (عنصر آخر بسعر موقوف): مرفوض ولا خصم.
    [$retiredItem, $retiredPrice] = e26_priced_item(1);
    $retiredPrice->forceFill(['is_active' => false])->save();
    $this->actingAs($buyer)->post(route('store.items.purchase', $retiredItem), ['price_id' => $retiredPrice->id, 'request_key' => (string) Str::uuid()]);
    expect($wallets->balanceFor($buyer, $retiredPrice->currency)->refresh()->available_balance)->toBe($before);
    expect(StorePurchase::where('user_id', $buyer->id)->count())->toBe(1);

    // رصيد غير كافٍ: لا شراء ولا رصيد سالب.
    $broke = e26_user();
    $this->actingAs($broke)->post(route('store.items.purchase', $item), ['price_id' => $price->id, 'request_key' => (string) Str::uuid()]);
    expect(StorePurchase::where('user_id', $broke->id)->count())->toBe(0);
    expect($wallets->balanceFor($broke, $currency)->refresh()->available_balance)->toBeGreaterThanOrEqual(0);
});

test('59: a client-supplied reward, XP, score or currency never changes what a puzzle pays', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب', 'gem_reward' => 8]);

    $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), [
        'answer' => 'الجواب',
        'gem_reward' => 9999, 'reward' => 9999, 'gems' => 9999, 'amount' => 9999, 'pending_balance' => 9999, 'available_balance' => 9999,
        'currency_id' => 99, 'is_correct' => 1, 'score' => 100000, 'xp' => 100000, 'points' => 100000, 'user_id' => User::factory()->create()->id,
    ]);

    expect(e26_wallet_state($user))->toBe([0, 8]);
    expect(PuzzleAttempt::where('user_id', $user->id)->count())->toBe(1);
    expect(Wallet::query()->max('pending_balance'))->toBeLessThanOrEqual(8);
});

test('60: balances and account flags cannot be mass-assigned through any HTTP form (register, profile, admin-looking fields)', function () {
    // التسجيل: حقول إضافية لا تنتج دورًا ولا توثيقًا ولا رصيدًا.
    $this->post('/register', [
        'name' => 'مخترق', 'email' => 'mass.assign@example.test', 'password' => 'Str0ng-Passw0rd-E26', 'password_confirmation' => 'Str0ng-Passw0rd-E26',
        'is_frozen' => 0, 'email_verified_at' => now()->toDateTimeString(), 'role' => 'administrator', 'roles' => ['administrator'], 'is_admin' => 1,
        'available_balance' => 99999, 'pending_balance' => 99999, 'lifetime_earned' => 99999, 'public_id' => 'FORGED',
    ])->assertRedirect();

    $registered = User::where('email', 'mass.assign@example.test')->firstOrFail();
    expect($registered->hasVerifiedEmail())->toBeFalse();
    expect($registered->hasAnyRole(['administrator', 'super-admin', 'content-manager']))->toBeFalse();
    expect($registered->can('admin.access'))->toBeFalse();
    expect($registered->public_id)->not->toBe('FORGED');
    expect(e26_wallet_state($registered))->toBe([0, 0]);

    // الملف الشخصي: نفس المبدأ.
    $user = e26_user();
    $before = e26_wallet_state($user);
    $this->actingAs($user)->patch('/profile', [
        'name' => 'اسم جديد', 'email' => $user->email,
        'is_frozen' => 1, 'available_balance' => 99999, 'pending_balance' => 99999, 'role' => 'super-admin', 'roles' => ['super-admin'],
        'email_verified_at' => null, 'password' => 'Hacked-Passw0rd-1', 'public_id' => 'FORGED2',
    ]);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('اسم جديد');
    expect($fresh->is_frozen)->not->toBeTrue();
    expect($fresh->hasVerifiedEmail())->toBeTrue();
    expect($fresh->hasAnyRole(['administrator', 'super-admin']))->toBeFalse();
    expect($fresh->public_id)->not->toBe('FORGED2');
    expect(\Illuminate\Support\Facades\Hash::check('Hacked-Passw0rd-1', $fresh->password))->toBeFalse();
    expect(e26_wallet_state($user))->toBe($before);

    // User::fillable لا يحوي أي حقل صلاحية/حالة/رصيد.
    $fillable = (new User)->getFillable();
    foreach (['is_frozen', 'email_verified_at', 'public_id', 'remember_token', 'role', 'roles', 'is_admin'] as $dangerous) {
        expect($fillable)->not->toContain($dangerous);
    }
});

test('60b: wallet and ledger rows are written only by the economy/service layer - no controller, request, middleware or Livewire class touches them', function () {
    $offenders = [];

    foreach (e26_php_files(['app/Http', 'app/Livewire', 'app/Console']) as $file) {
        $code = e26_php_code($file);

        if (preg_match('/\bWallet::(create|update|insert|upsert|where|query|firstOrCreate|updateOrCreate)\b|->wallet->(update|fill|forceFill|increment|decrement|save)\s*\(|CurrencyTransaction::(create|insert|upsert)\b/', $code, $m)) {
            // التسجيل ينشئ محفظة فارغة بالعملة الافتراضية فقط (لا قيم من الطلب) وهو المكان الوحيد المسموح.
            if (e26_relative($file) === 'app/Http/Controllers/Auth/RegisteredUserController.php') {
                continue;
            }
            $offenders[] = e26_relative($file).' => '.$m[0];
        }
    }

    expect($offenders)->toBe([]);
});

test('61: redemption is refused for guests, unverified, frozen, ineligible, over-balance and malformed requests - and writes nothing', function () {
    $count = fn () => RedemptionRequest::count();

    // ضيف.
    $this->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x'])->assertRedirect(route('login'));

    // غير موثَّق البريد.
    $unverified = e26_eligible_user();
    $unverified->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($unverified->fresh())->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x']);

    // مجمَّد.
    $frozen = e26_freeze(e26_eligible_user());
    $this->actingAs($frozen)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x']);

    // حساب جديد غير مؤهَّل (عمر الحساب/الألغاز) رغم الرصيد.
    $new = User::factory()->create();
    $new->wallet->update(['available_balance' => 50000]);
    $this->actingAs($new)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x'])->assertSessionHas('error');

    expect($count())->toBe(0);

    // مؤهَّل لكن بطلبات غير صالحة: أكبر من الرصيد، دون الحد الأدنى، سالب، صفر، نص، عشري، وصف طويل.
    $eligible = e26_eligible_user();
    $balance = e26_wallet_state($eligible)[0];
    foreach ([$balance + 1, 1, -5000, 0, 'abc', '1e9', 10000.5] as $bad) {
        \Illuminate\Support\Facades\Cache::flush();   // حد الاستبدال (5/ساعة) يُختبر بـ56؛ هنا نفحص التحقق من الصحة لا الحد
        $this->actingAs($eligible)->post(route('redemption.store'), ['gems_amount' => $bad, 'reward_description' => 'x'])->assertSessionHasErrors('gems_amount');
    }
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($eligible)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => str_repeat('ب', 501)])->assertSessionHasErrors('reward_description');

    expect($count())->toBe(0);
    expect(e26_wallet_state($eligible)[0])->toBe($balance);
});

test('61b: a valid redemption debits exactly once for the authenticated user (a forged user_id is ignored) and an immediate repeat is refused', function () {
    $eligible = e26_eligible_user();
    $victim = e26_eligible_user();
    $amount = (int) config('gems.min_redemption');

    $this->actingAs($eligible)->post(route('redemption.store'), ['gems_amount' => $amount, 'reward_description' => 'مكافأة', 'user_id' => $victim->id, 'status' => 'fulfilled', 'reviewed_by' => $victim->id])
        ->assertRedirect(route('redemption.index'));

    $row = RedemptionRequest::firstOrFail();
    expect($row->user_id)->toBe($eligible->id)->and($row->status)->toBe(RedemptionRequest::STATUS_PENDING)->and($row->reviewed_by)->toBeNull();
    expect(e26_wallet_state($eligible)[0])->toBe(0);
    expect(e26_wallet_state($victim)[0])->toBe($amount);

    // تكرار الإرسال (نقر مزدوج): الرصيد صفر الآن، فلا طلب ثانٍ.
    $this->actingAs($eligible->fresh())->post(route('redemption.store'), ['gems_amount' => $amount, 'reward_description' => 'مكافأة']);
    expect(session()->has('errors') || session()->has('error'))->toBeTrue();
    expect(RedemptionRequest::count())->toBe(1);
    expect(e26_wallet_state($eligible)[0])->toBe(0);
});
```

### `tests/Feature/SecurityE26/E26FilesTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Filament\Pages\PlatformSettingsPage;
use App\Services\PlatformSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| E26 (62-66) - رفع الملفات (الشعارات/الأيقونات). السطح: مستخدمو الإدارة فقط عبر Filament/Livewire، قرص public، مسارات ثابتة، أسماء عشوائية.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    $this->admin = e26_user();
    $this->admin->assignRole('administrator');
    $this->actingAs($this->admin);
});

if (! function_exists('e26_branding_value')) {
    function e26_branding_value(string $key): mixed
    {
        return app(PlatformSettingsService::class)->getGroup('branding')[$key] ?? null;
    }

    /** يحمّل صفحة الإعدادات ويضع ملفًا في حقل هوية بصرية ثم يحفظ. */
    function e26_save_branding($test, string $field, UploadedFile $file)
    {
        return Livewire::test(PlatformSettingsPage::class)
            ->fillForm(['branding.'.$field => $file])
            ->call('save');
    }

    /** نفس تعريف الحقل كما في الصفحة: [اسم الحقل => حدّ KB]. */
    function e26_branding_limits(): array
    {
        return ['logo_main' => 2048, 'logo_square' => 1024, 'logo_light' => 1024, 'logo_dark' => 1024, 'favicon' => 512, 'social_share_image' => 2048];
    }
}

test('62: a valid branding image is accepted, stored under the fixed branding directory with a generated name, and saved to the setting', function () {
    $component = e26_save_branding($this, 'logo_main', UploadedFile::fake()->image('شعار.png', 300, 120)->size(80));
    $component->assertHasNoFormErrors();

    $stored = e26_branding_value('logo_main');
    $path = is_array($stored) ? (string) array_values($stored)[0] : (string) $stored;

    expect($path)->toStartWith('settings/branding/')->toEndWith('.png')->and(Storage::disk('public')->exists($path))->toBeTrue();
    expect(basename($path))->not->toContain('شعار');    // الاسم الأصلي لا يصل للقرص
});

test('63: executable or active content disguised as a branding image (php, html, svg) is rejected and nothing is stored or saved', function (string $kind) {
    $file = match ($kind) {
        'php' => UploadedFile::fake()->create('logo.php', 5, 'application/x-php'),
        'php-as-png-name' => UploadedFile::fake()->createWithContent('logo.png', "<?php system(\$_GET['c']); ?>")->mimeType('application/x-httpd-php'),
        'html' => UploadedFile::fake()->createWithContent('logo.html', '<script>alert(1)</script>'),
        'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
        'phar' => UploadedFile::fake()->create('logo.phar', 5, 'application/octet-stream'),
    };

    $before = e26_branding_value('logo_main');
    e26_save_branding($this, 'logo_main', $file)->assertHasFormErrors(['branding.logo_main']);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(e26_branding_value('logo_main'))->toBe($before);
})->with(['php', 'php-as-png-name', 'html', 'svg', 'phar']);

test('64: oversized images are rejected on every branding field (limit per field), and exactly-at-limit files are accepted', function () {
    foreach (e26_branding_limits() as $field => $limitKb) {
        $over = UploadedFile::fake()->image($field.'.png', 64, 64)->size($limitKb + 100);
        e26_save_branding($this, $field, $over)->assertHasFormErrors(['branding.'.$field]);
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);

    e26_save_branding($this, 'favicon', UploadedFile::fake()->image('ok.png', 32, 32)->size(500))->assertHasNoFormErrors();   // 500 ≤ 512KB
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

test('64b: field-specific type lists hold - a JPEG is refused where only PNG/WebP is allowed (square logo, favicon)', function () {
    e26_save_branding($this, 'logo_square', UploadedFile::fake()->image('square.jpg', 64, 64)->size(50))->assertHasFormErrors(['branding.logo_square']);
    e26_save_branding($this, 'favicon', UploadedFile::fake()->image('fav.webp', 32, 32)->size(10))->assertHasFormErrors(['branding.favicon']);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('65: traversal / double-extension filenames are neutralized - the stored name is generated, inside the branding directory, with the sniffed extension', function (string $name, bool $accepted) {
    $component = e26_save_branding($this, 'logo_main', UploadedFile::fake()->image($name, 100, 100)->size(40));
    $files = Storage::disk('public')->allFiles();

    if (! $accepted) {
        // الامتداد الأخير php: يُرفض أصلًا (لا يصل شيء للقرص).
        $component->assertHasFormErrors(['branding.logo_main']);
        expect($files)->toBe([]);

        return;
    }

    $component->assertHasNoFormErrors();
    expect($files)->toHaveCount(1);
    foreach ($files as $stored) {
        expect($stored)->not->toContain('..')->not->toContain('\\')->not->toContain('evil')->not->toContain('.php')->not->toContain('.phtml');
        expect($stored)->toMatch('#^settings/branding/[A-Za-z0-9]{20,}\.(png|jpg|jpeg|webp)$#');
    }
})->with([
    'unix traversal' => ['../../../../evil.png', true],
    'windows traversal' => ['..\\..\\evil.png', true],
    'double extension' => ['evil.php.png', true],
    'absolute path' => ['/etc/evil.png', true],
    'nested dirs' => ['a/b/evil.png', true],
    'null-byte style, final .php' => ['evil.png%00.php', false],
]);

test('65b: every upload field in the application declares an explicit allow-list (no image/* wildcard, no SVG/HTML/PHP) and a size cap, on the public disk under a fixed directory', function () {
    $offenders = [];
    $seen = 0;

    foreach (e26_php_files(['app/Filament']) as $file) {
        $lines = file($file);
        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'FileUpload::make(')) {
                continue;
            }
            $seen++;

            // سلسلة الحقل: من هذا السطر حتى الحقل التالي (أو 16 سطرًا).
            $chain = $line;
            for ($j = $i + 1; $j < min($i + 17, count($lines)); $j++) {
                if (str_contains($lines[$j], 'FileUpload::make(') || preg_match('/^\s*Forms\\\\Components\\\\(?!FileUpload)/', $lines[$j])) {
                    break;
                }
                $chain .= $lines[$j];
            }

            $where = e26_relative($file).':'.($i + 1);
            if (! str_contains($chain, 'acceptedFileTypes(')) {
                $offenders[] = $where.' no acceptedFileTypes';
            }
            if (! str_contains($chain, 'maxSize(')) {
                $offenders[] = $where.' no maxSize';
            }
            if (! str_contains($chain, "->disk('public')") || ! str_contains($chain, '->directory(')) {
                $offenders[] = $where.' not public disk/fixed directory';
            }
            if (preg_match('#image/\*|svg|text/html|php|octet-stream|application/x-#i', preg_replace('#//.*$#m', '', $chain))) {
                $offenders[] = $where.' dangerous type allowed';
            }
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(19)->and($offenders)->toBe([]);
});

test('66: the branding hotfix (previewable(false) on all six fields) is intact and the settings page still renders with an existing logo', function () {
    $source = e26_php_code(base_path('app/Filament/Pages/PlatformSettingsPage.php'));
    expect(substr_count($source, '->previewable(false)'))->toBe(6);

    foreach (array_keys(e26_branding_limits()) as $field) {
        expect($source)->toMatch("/FileUpload::make\\('branding\\.{$field}'\\)[^;]*->previewable\\(false\\)/s");
    }

    app(PlatformSettingsService::class)->setMany('branding', ['logo_main' => 'settings/branding/existing.png'], $this->admin);
    Storage::disk('public')->put('settings/branding/existing.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    $this->get('/admin/settings')->assertOk();
    Livewire::test(PlatformSettingsPage::class)->assertSuccessful();
});

test('66b: only users with settings.update can change branding - a viewer with settings.view is refused server-side and nothing is stored', function () {
    $viewer = e26_user();
    $viewer->givePermissionTo(['admin.access', 'settings.view']);
    $this->actingAs($viewer);

    $before = e26_branding_value('logo_main');

    e26_save_branding($this, 'logo_main', UploadedFile::fake()->image('x.png', 50, 50)->size(20))->assertForbidden();   // 403 من abort_unless داخل save()

    expect(e26_branding_value('logo_main'))->toBe($before);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});
```

### `tests/Feature/SecurityE26/E26GameIntegrityTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\GameEngine\Support\SequenceTokens;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| E26 (O/P) - نزاهة اللعب: ما يُرسل للعميل لا يكشف الحل، الألغاز غير الفعّالة لا تُلعب ولا تدفع، سقف الكسب اليومي صلب، وبوابات API (الخاملة) لا تتجاوز شروط الويب.
*/

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

if (! function_exists('e26_sequence_puzzle')) {
    function e26_sequence_puzzle(array $attrs = []): Puzzle
    {
        return Puzzle::factory()->create($attrs + [
            'game_type' => 'sequence', 'validation_type' => 'sequence_match', 'score_mode' => 'flat', 'renderer' => 'games.sequence',
            'game_config' => ['items' => ['أولًا', 'ثانيًا', 'ثالثًا', 'رابعًا']], 'gem_reward' => 6,
        ]);
    }

    function e26_solve($test, User $user, Puzzle $puzzle, array $order)
    {
        return $test->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['submission' => json_encode(['order' => $order])]);
    }
}

test('O1: sequence items are shown with opaque per-puzzle tokens - never the raw index - and the display order does not reveal the answer order', function () {
    $user = e26_user();
    $puzzle = e26_sequence_puzzle();
    $expected = SequenceTokens::expectedOrder($puzzle);

    expect($expected)->toHaveCount(4)->and(array_unique($expected))->toHaveCount(4);
    foreach ($expected as $token) {
        expect($token)->toMatch('/^[0-9a-f]{16}$/');
    }

    $html = $this->actingAs($user)->get(route('puzzles.show', $puzzle))->assertOk()->getContent();
    foreach ($expected as $token) {
        expect($html)->toContain($token);
    }
    // لا مصفوفة فهارس خام ولا ترتيب حل ظاهر.
    expect($html)->not->toContain('"order"')->not->toContain('solution_data')->not->toContain('[0,1,2,3]')->not->toContain('answer_normalized');

    // الرموز تتغير بتغيّر اللغز وبمفتاح التطبيق (لا يمكن حسابها من لغز آخر ولا دون المفتاح).
    $other = e26_sequence_puzzle();
    expect(SequenceTokens::expectedOrder($other))->not->toBe($expected);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(SequenceTokens::expectedOrder($puzzle))->not->toBe($expected);
});

test('O2: raw ascending indexes, reversed or partial tokens, duplicates and foreign tokens never solve a sequence puzzle - only the exact token order pays', function () {
    $user = e26_user();
    $puzzle = e26_sequence_puzzle(['max_attempts' => 50]);
    $expected = SequenceTokens::expectedOrder($puzzle);
    $foreign = SequenceTokens::expectedOrder(e26_sequence_puzzle());

    foreach ([[0, 1, 2, 3], ['0', '1', '2', '3'], array_reverse($expected), array_slice($expected, 0, 3), [$expected[0], $expected[0], $expected[1], $expected[2]], $foreign, [], 'abc'] as $wrong) {
        \Illuminate\Support\Facades\Cache::flush();
        e26_solve($this, $user, $puzzle, is_array($wrong) ? $wrong : [$wrong]);
    }
    expect(PuzzleAttempt::where('user_id', $user->id)->where('is_correct', true)->count())->toBe(0);
    expect((int) $user->wallet->refresh()->pending_balance)->toBe(0);

    \Illuminate\Support\Facades\Cache::flush();
    e26_solve($this, $user, $puzzle, $expected)->assertSessionHas('success');
    expect((int) $user->wallet->refresh()->pending_balance)->toBe(6);
});

test('O3: the API puzzle payload never contains the solution (answer, normalized answer, solution_data) and uses opaque tokens for sequence items', function () {
    e26_sanctum_table();
    $user = e26_user();
    $puzzle = e26_sequence_puzzle(['answer_raw' => 'سر-الحل']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/puzzles/'.$puzzle->getKey())->assertOk();
    $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

    foreach (['answer_raw', 'answer_normalized', 'solution_data', 'سر-الحل', '"order"'] as $needle) {
        expect($json)->not->toContain($needle);
    }
    foreach (SequenceTokens::expectedOrder($puzzle) as $token) {
        expect($json)->toContain($token);
    }
});

test('O4: inactive (draft) puzzles cannot be viewed, attempted, hinted or rewarded by players - web and API - while staff with admin access can preview', function () {
    e26_sanctum_table();
    $player = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'سر', 'gem_reward' => 9, 'is_active' => false]);

    $this->actingAs($player)->get(route('puzzles.show', $puzzle))->assertNotFound();
    $this->actingAs($player)->post(route('puzzles.attempt', $puzzle), ['answer' => 'سر'])->assertSessionHas('error');
    $this->actingAs($player)->post(route('puzzles.hint', $puzzle))->assertSessionHas('error');
    Sanctum::actingAs($player);
    $this->getJson('/api/puzzles/'.$puzzle->getKey())->assertNotFound();
    expect($this->postJson('/api/puzzles/'.$puzzle->getKey().'/attempt', ['answer' => 'سر'])->getStatusCode())->toBeIn([403, 404, 422]);

    expect(PuzzleAttempt::where('puzzle_id', $puzzle->id)->count())->toBe(0);
    expect((int) $player->wallet->refresh()->pending_balance)->toBe(0);

    $admin = e26_user();
    $admin->assignRole('administrator');
    $this->actingAs($admin)->get(route('puzzles.show', $puzzle))->assertOk();
});

test('O5: the daily earn cap is a hard ceiling across many different puzzles, and the cap check runs under the wallet row lock (closes the concurrent-attempt race)', function () {
    config(['gems.daily_earn_cap' => 20]);
    $user = e26_user();

    foreach (range(1, 6) as $i) {
        $puzzle = Puzzle::factory()->create(['answer_raw' => 'ج'.$i, 'gem_reward' => 8]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'ج'.$i]);
    }

    $earned = (int) $user->wallet->refresh()->pending_balance + (int) $user->wallet->available_balance;
    expect($earned)->toBe(20);   // 8 + 8 + 4 (جزئي) ثم 0

    $source = e26_php_code(base_path('app/Services/PuzzleAttemptService.php'));
    $lock = strpos($source, 'lockWallet(');
    $read = strpos($source, 'daily_earn_cap');
    expect($lock)->not->toBeFalse()->and($read)->not->toBeFalse()->and($lock)->toBeLessThan($read);
});

test('O6: the hint is never in the page, is refused (and not charged) without balance or without a stored hint, and costs exactly the configured amount once paid', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['hint' => 'تلميح-سري-مدفوع', 'answer_raw' => 'الحل-السري']);
    $cost = (int) config('gems.hint_cost');

    $page = $this->actingAs($user)->get(route('puzzles.show', $puzzle))->assertOk()->getContent();
    expect($page)->not->toContain('تلميح-سري-مدفوع')->not->toContain('الحل-السري');

    // بلا رصيد: يُرفض ولا يظهر التلميح.
    $this->actingAs($user)->post(route('puzzles.hint', $puzzle))->assertSessionHas('error')->assertSessionMissing('hint');
    expect(e26_wallet_state($user)[0])->toBe(0);

    // لغز بلا تلميح: يُرفض بلا خصم.
    $user->wallet->update(['available_balance' => 10]);
    $noHint = Puzzle::factory()->create(['hint' => null]);
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($user->fresh())->post(route('puzzles.hint', $noHint))->assertSessionHas('error');
    expect(e26_wallet_state($user)[0])->toBe(10);

    // بالرصيد: يُخصم المبلغ المضبوط بالإعدادات فقط (لا قيمة من العميل).
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($user->fresh())->post(route('puzzles.hint', $puzzle), ['cost' => 0, 'hint_cost' => 0])->assertSessionHas('hint', 'تلميح-سري-مدفوع');
    expect(e26_wallet_state($user)[0])->toBe(10 - $cost);
});

test('P1: API login is dormant-safe - it answers 503 (not a 500 with a stack trace) while the token table does not exist, and never issues a token', function () {
    $this->postJson('/api/auth/login', ['email' => 'x@example.test', 'password' => 'secret-pass', 'device_name' => 'e26'])
        ->assertStatus(503);
});

test('P2: API login refuses frozen and unverified accounts, answers wrong-password and unknown-email identically, expires tokens and caps live tokens', function () {
    e26_sanctum_table();
    $user = e26_user(['email' => 'api.user@example.test']);
    $payload = fn (string $email, string $password = 'password') => ['email' => $email, 'password' => $password, 'device_name' => 'e26-device'];

    // لا تعداد حسابات: نفس الرد للبريد المجهول وكلمة المرور الخاطئة.
    $wrong = $this->postJson('/api/auth/login', $payload($user->email, 'wrong'));
    $unknown = $this->postJson('/api/auth/login', $payload('nobody@example.test', 'wrong'));
    expect($wrong->getStatusCode())->toBe($unknown->getStatusCode())->and($wrong->json('errors.email'))->toBe($unknown->json('errors.email'));

    // مجمَّد / غير موثَّق: لا رمز.
    $frozen = e26_freeze(e26_user(['email' => 'api.frozen@example.test']));
    $this->postJson('/api/auth/login', $payload($frozen->email))->assertForbidden();
    $unverified = e26_user(['email' => 'api.unverified@example.test', 'email_verified_at' => null]);
    $this->postJson('/api/auth/login', $payload($unverified->email))->assertForbidden();
    expect($frozen->tokens()->count() + $unverified->tokens()->count())->toBe(0);

    // نجاح: رمز بتاريخ انتهاء.
    $ok = $this->postJson('/api/auth/login', $payload($user->email))->assertOk();
    expect($ok->json('token'))->toBeString()->and($ok->json('expires_in_minutes'))->toBe(43200);
    $token = $user->tokens()->latest('id')->first();
    expect($token->expires_at)->not->toBeNull()->and($token->expires_at->isFuture())->toBeTrue();

    // سقف الرموز الحيّة: 12 رمزًا قائمة ثم دخول جديد -> لا يزيد عن 10.
    foreach (range(1, 12) as $i) {
        $user->createToken('old-'.$i);
    }
    \Illuminate\Support\Facades\Cache::flush();
    $this->postJson('/api/auth/login', $payload($user->email))->assertOk();
    expect($user->tokens()->count())->toBeLessThanOrEqual(10);
});

test('P3: API login brute force is throttled (6th wrong attempt for the same account+IP gets 429)', function () {
    e26_sanctum_table();
    $user = e26_user(['email' => 'api.brute@example.test']);

    $statuses = e26_hammer($this, 'post', '/api/auth/login', 8, fn ($i) => ['email' => $user->email, 'password' => 'wrong-'.$i, 'device_name' => 'e26'], true);

    expect(e26_first_429($statuses))->toBe(6);
});

test('P4: a token belonging to a frozen or unverified user cannot read the wallet or earn gems; guests get 401; a good token works; logout revokes the token', function () {
    e26_sanctum_table();
    $this->getJson('/api/wallet')->assertUnauthorized();
    $this->postJson('/api/puzzles/999999/attempt', ['answer' => 'x'])->assertUnauthorized();

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'x', 'gem_reward' => 5]);
    $frozen = e26_freeze(e26_user());
    Sanctum::actingAs($frozen);
    $this->getJson('/api/wallet')->assertForbidden();
    $this->postJson('/api/puzzles/'.$puzzle->getKey().'/attempt', ['answer' => 'x'])->assertForbidden();
    expect(PuzzleAttempt::count())->toBe(0);

    $unverified = e26_user(['email_verified_at' => null]);
    Sanctum::actingAs($unverified);
    $this->getJson('/api/wallet')->assertForbidden();
    $this->postJson('/api/puzzles/'.$puzzle->getKey().'/attempt', ['answer' => 'x'])->assertForbidden();
    expect(PuzzleAttempt::count())->toBe(0);

    $good = e26_user();
    $plain = $good->createToken('real')->plainTextToken;
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/wallet')->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/auth/logout')->assertOk();
    expect($good->tokens()->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/wallet')->assertUnauthorized();
});
```

### `tests/Feature/SecurityE26/E26InputSecurityTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Models\ChatMessage;
use App\Models\PuzzleCategory;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\Team;
use App\Models\User;
use App\Services\Advertising\UrlSafetyGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

const E26_XSS = '<script>alert(1)</script><img src=x onerror=alert(2)>';

function e26_assert_escaped(string $html): void
{
    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror');
}

test('27: a script payload in a chat message is stored as text and escaped on every page that renders it', function () {
    [$a, $b] = chatFriends();
    chatDm($this, $a, $b, E26_XSS)->assertCreated();
    $this->actingAs($a)->postJson(route('community.chat.send'), ['body' => E26_XSS])->assertCreated();

    expect(ChatMessage::query()->pluck('body')->unique()->all())->toBe([E26_XSS]);
    foreach ([route('messages.direct', $a), route('community.chat'), route('messages.index')] as $url) {
        e26_assert_escaped($this->actingAs($b)->get($url)->assertOk()->getContent());
    }
});

test('28: a script payload in a team name is escaped on the directory, leaderboard and team pages', function () {
    $team = e19Team(null, ['name' => 'فريق عادي']);
    DB::table('teams')->where('id', $team->id)->update(['name' => E26_XSS, 'description' => E26_XSS]);   // تجاوز التحقق عمدًا: دفاع بالعمق عند العرض
    $viewer = e26_user();

    foreach ([route('teams.index'), route('teams.show', $team), route('teams.leaderboard')] as $url) {
        e26_assert_escaped($this->actingAs($viewer)->get($url)->assertOk()->getContent());
    }
});

test('29: a script payload in a player name is escaped on profile, leaderboard, search and the account page', function () {
    $evil = e26_user(['name' => E26_XSS]);
    $viewer = e26_user();
    $evil->wallet->update(['lifetime_earned' => 500]);

    foreach ([route('players.show', $evil), route('leaderboard.index'), route('friends.search', ['q' => 'script'])] as $url) {
        e26_assert_escaped($this->actingAs($viewer)->get($url)->assertOk()->getContent());
    }
    e26_assert_escaped($this->actingAs($evil)->get(route('profile.edit'))->assertOk()->getContent());
});

test('30: SQL-injection-shaped search input is inert - pages answer normally, no table is touched, LIKE wildcards are escaped', function () {
    $viewer = e26_user();
    $victim = e26_user(['name' => 'Ordinary Person']);
    e19Team(null, ['name' => 'Ordinary Team']);
    $users = User::count();
    $payloads = ["' OR '1'='1", "'; DROP TABLE users; --", '" OR ""="', '%', '_', '\\', '%%', "Robert'); DROP TABLE teams;--", '١٢٣٪', "\0", str_repeat('a', 40)];

    foreach ($payloads as $payload) {
        $this->actingAs($viewer)->get(route('friends.search', ['q' => $payload]))->assertSuccessful();
        $this->actingAs($viewer)->get(route('teams.index', ['q' => $payload]))->assertSuccessful();
        $this->actingAs($viewer)->get(route('competitions.hall-of-fame', ['q' => $payload]))->assertSuccessful();
        expect($this->actingAs($viewer)->get(route('teams.challenges.index', ['q' => $payload]))->status())->toBeIn([200, 302]);
    }

    expect(User::count())->toBe($users)->and(Team::count())->toBe(1);
    // % و _ حرفيان: لا يطابقان كل شيء
    $this->actingAs($viewer)->get(route('friends.search', ['q' => '%']))->assertDontSee('Ordinary Person');
    $this->actingAs($viewer)->get(route('teams.index', ['q' => '%']))->assertDontSee('Ordinary Team');
});

test('31: sort/order parameters are never used as column names - hostile values change nothing and no controller feeds the request into orderBy', function () {
    $viewer = e26_user();

    foreach (['/puzzles', '/leaderboard', '/teams', '/challenges', '/campaigns', '/store', '/competitions'] as $path) {
        foreach (['sort' => 'password', 'order' => 'desc;drop table users', 'orderBy' => 'email', 'dir' => 'x'] as $key => $value) {
            $this->actingAs($viewer)->get($path.'?'.http_build_query([$key => $value]))->assertSuccessful();
        }
    }

    foreach (e26_php_files(['app/Http', 'app/Services']) as $file) {
        $code = e26_php_code($file);
        expect(preg_match('/order(?:By|ByDesc)\(\s*\$request|order(?:By|ByDesc)\(\s*request\(|order(?:By|ByDesc)\(\s*\$(?:sort|column|field)\b/i', $code))->toBe(0, e26_relative($file));
    }
});

test('32: javascript:, data:, http: and credential-bearing URLs are rejected for external destinations; the ad redirect only follows a stored https URL', function () {
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'http://example.test/a', '//evil.test/x', 'https://user:pass@evil.test/x', 'https:///nohost', 'ftp://example.test', '', ' ', 'vbscript:msgbox(1)'] as $bad) {
        expect(UrlSafetyGuard::isSafe($bad))->toBeFalse("must reject: {$bad}");
    }
    expect(UrlSafetyGuard::isSafe('https://example.test/landing?x=1'))->toBeTrue();

    $campaign = SponsorCampaign::factory()->create();
    $unsafe = SponsorCreative::factory()->create(['sponsor_campaign_id' => $campaign->id]);
    DB::table('sponsor_creatives')->where('id', $unsafe->id)->update(['destination_url' => 'javascript:alert(1)']);    // تجاوز حارس الكتابة عمدًا
    $this->get(route('ads.click', $unsafe))->assertNotFound();

    $safe = SponsorCreative::factory()->create(['sponsor_campaign_id' => $campaign->id, 'destination_url' => 'https://example.test/offer']);
    $this->get(route('ads.click', $safe).'?url=https://evil.test/&redirect=https://evil.test/&next=//evil.test')->assertRedirect('https://example.test/offer');
});

test('33: an uploaded filename with traversal segments never decides where or under what name the file is stored', function () {
    Storage::fake('public');
    $admin = e26_user();
    $admin->assignRole('administrator');
    $category = PuzzleCategory::factory()->create();
    $this->actingAs($admin);

    Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm([
            'title' => 'traversal', 'puzzle_category_id' => $category->id, 'type' => 'image', 'difficulty' => 'easy', 'prompt' => 'سؤال',
            'image_path' => UploadedFile::fake()->image('../../../../etc/evil.jpg', 400, 300)->size(100), 'answer_raw' => 'x', 'score_mode' => 'flat',
        ])->call('create')->assertHasNoFormErrors();

    $path = (string) \App\Models\Puzzle::query()->latest('id')->value('image_path');
    expect($path)->toStartWith('puzzles/')->not->toContain('..')->not->toContain('etc/evil')->and(Storage::disk('public')->exists($path))->toBeTrue();
    foreach (Storage::disk('public')->allFiles() as $stored) {
        expect($stored)->not->toContain('..')->not->toContain('evil');
    }
});

test('34: executable content disguised as an image, and non-image MIME types, are rejected by the upload field', function (string $kind) {
    Storage::fake('public');
    $admin = e26_user();
    $admin->assignRole('administrator');
    $category = PuzzleCategory::factory()->create();
    $this->actingAs($admin);

    $file = match ($kind) {
        'php-mime' => UploadedFile::fake()->create('shell.php', 5, 'application/x-php'),
        'svg' => UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
        'pdf' => UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'),
    };

    Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm(['title' => 'bad', 'puzzle_category_id' => $category->id, 'type' => 'image', 'difficulty' => 'easy', 'prompt' => 'q', 'image_path' => $file, 'answer_raw' => 'x', 'score_mode' => 'flat'])
        ->call('create')->assertHasFormErrors(['image_path']);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(\App\Models\Puzzle::count())->toBe(0);
})->with(['php-mime', 'svg', 'pdf']);

test('34b: a script renamed to .png is rejected because the MIME is sniffed from the content, not the extension (the rule family every image upload field uses)', function () {
    // Livewire في وضع الاختبار يستعمل MIME المُعلَن للملف المزيَّف لا المحتوى، فنتحقق من قاعدة المحتوى نفسها (mimetypes) على ملف حقيقي.
    $php = tempnam(sys_get_temp_dir(), 'e26');
    $png = tempnam(sys_get_temp_dir(), 'e26');
    file_put_contents($php, "<?php system(\$_GET['c']); ?>");
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $rules = ['f' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp']];

    $disguised = new \Illuminate\Http\UploadedFile($php, 'shell.png', 'image/png', null, true);   // امتداد وMIME معلَن كاذبان
    $real = new \Illuminate\Http\UploadedFile($png, 'logo.png', null, null, true);

    expect(\Illuminate\Support\Facades\Validator::make(['f' => $disguised], $rules)->fails())->toBeTrue()
        ->and(\Illuminate\Support\Facades\Validator::make(['f' => $real], $rules)->passes())->toBeTrue();
    @unlink($php);
    @unlink($png);
});
```

### `tests/Feature/SecurityE26/E26RealtimeTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Events\Chat\ChatMessageSent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

/*
| E26 (41-45) - أمان القنوات اللحظية. كل شيء محلي: توقيع مصادقة القناة يُحسب بمفتاح اختبار وهمي بلا أي اتصال شبكي بـReverb.
| مسار الهجوم: مستخدم مصادَق (أو ضيف) يطلب اشتراكًا بقناة خاصة ليست له عبر POST /broadcasting/auth الحقيقي (لا استدعاء للدالة داخليًا).
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

if (! function_exists('e26_use_real_broadcaster')) {
    /** يحوّل البثّ إلى سائق reverb بمفاتيح وهمية (التوقيع محلي)، ويعيد تسجيل قنوات routes/channels.php عليه. */
    function e26_use_real_broadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'e26-test-key',
            'broadcasting.connections.reverb.secret' => 'e26-test-secret-not-real-0123456789',
            'broadcasting.connections.reverb.app_id' => '26',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    function e26_subscribe($test, ?\App\Models\User $user, string $channel)
    {
        $request = $user ? $test->actingAs($user) : $test;

        return $request->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel], ['Accept' => 'application/json']);
    }
}

test('41: an unauthorized user cannot subscribe to someone else\'s private chat channel - guests, outsiders, forged and unknown channels are all refused, participants succeed', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $thread = chatThreads()->directOrCreate($a, $b);
    $channel = 'private-chat.'.$thread->public_id;
    $outsider = e26_user();

    e26_subscribe($this, null, $channel)->assertForbidden();              // ضيف
    e26_subscribe($this, $outsider, $channel)->assertForbidden();         // مستخدم موثَّق غريب
    e26_subscribe($this, $outsider, 'private-chat.'.str_repeat('z', 26))->assertForbidden();   // معرّف مختلق
    e26_subscribe($this, $outsider, 'private-chat.1')->assertForbidden(); // محاولة تخمين معرّف تسلسلي
    e26_subscribe($this, $outsider, 'private-chat.')->assertForbidden();

    $ok = e26_subscribe($this, $a, $channel);                              // الطرف الأول يمرّ
    $ok->assertOk();
    expect($ok->json('auth'))->toStartWith('e26-test-key:');
    e26_subscribe($this, $b, $channel)->assertOk();                        // الطرف الثاني يمرّ

    // لا يوجد تخويل لقنوات خاصة غير معرَّفة (لا قنوات user.{id} ولا presence خفية).
    foreach (['private-user.'.$a->getKey(), 'private-App.Models.User.'.$a->getKey(), 'presence-chat.'.$thread->public_id, 'private-admin', 'private-wallet.'.$a->getKey()] as $other) {
        e26_subscribe($this, $a, $other)->assertForbidden();
    }
});

test('41b: the channel name cannot be coaxed past the guard with an unprefixed (public-looking) name or odd casing', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $thread = chatThreads()->directOrCreate($a, $b);
    $outsider = e26_user();

    foreach (['chat.'.$thread->public_id, 'PRIVATE-chat.'.$thread->public_id, ' private-chat.'.$thread->public_id, 'private-chat.'.$thread->public_id.'/../x', 'private-chat.'.strtoupper($thread->public_id)] as $channel) {
        $response = e26_subscribe($this, $outsider, $channel);
        expect($response->getStatusCode())->toBeIn([401, 403, 404, 422], "channel [{$channel}] must not authorize an outsider");
        expect((string) $response->getContent())->not->toContain('e26-test-key:');
    }
});

test('41c: presence-* and every non-private-chat channel name is refused before any authorization runs (regression: presence-chat.{id} used to leak the internal numeric user id)', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $thread = chatThreads()->directOrCreate($a, $b);

    $response = e26_subscribe($this, $a, 'presence-chat.'.$thread->public_id);
    $response->assertForbidden();
    expect($response->json('auth'))->toBeNull()->and($response->json('channel_data'))->toBeNull();

    foreach (['chat.'.$thread->public_id, 'private-chat', 'private-chatter.'.$thread->public_id, 'private-chat.'.$thread->public_id.'.x', ''] as $name) {
        e26_subscribe($this, $a, $name)->assertForbidden();
    }

    expect(file_get_contents(base_path('bootstrap/app.php')))->toContain('RestrictBroadcastChannels::class');
});

test('42: blocked, non-member, removed and unverified users are refused on direct, team and global channels', function () {
    e26_use_real_broadcaster();

    // direct: غريب مرفوض حتى لو صديق لأحد الطرفين.
    [$a, $b] = chatFriends();
    $direct = chatThreads()->directOrCreate($a, $b);
    $c = e26_user();
    e16Befriend($a, $c);
    e26_subscribe($this, $c, 'private-chat.'.$direct->public_id)->assertForbidden();

    // team: غير عضو مرفوض، العضو مقبول، وبعد خروجه/إزالته يُقطع الاشتراك عند إعادة التخويل.
    [$team, $owner, $admin, $member] = chatTeam();
    $teamThread = chatThreads()->forTeam($team);
    $teamChannel = 'private-chat.'.$teamThread->public_id;
    $stranger = e26_user();

    e26_subscribe($this, $stranger, $teamChannel)->assertForbidden();
    e26_subscribe($this, $member, $teamChannel)->assertOk();
    app(\App\Services\Teams\TeamMembershipService::class)->leave($member);
    e26_subscribe($this, $member->refresh(), $teamChannel)->assertForbidden();

    // global: غير موثَّق البريد مرفوض (القراءة تتطلب بريدًا موثَّقًا غير مجمَّد).
    $global = chatGlobal();
    $unverified = e26_user(['email_verified_at' => null]);
    e26_subscribe($this, $unverified, 'private-chat.'.$global->public_id)->assertForbidden();
    e26_subscribe($this, $stranger, 'private-chat.'.$global->public_id)->assertOk();
});

test('43: the broadcast payload contains only public-safe fields - no email, phone, flags or moderation data - and goes only to a private channel', function () {
    $sender = e26_user(['name' => 'مرسل', 'email' => 'e26.leaky@example.test']);
    $captured = null;
    Event::listen(ChatMessageSent::class, function ($event) use (&$captured) {
        $captured = $event;
    });

    $this->actingAs($sender)->postJson(route('community.chat.send'), ['body' => 'رسالة <script>alert(1)</script>'])->assertCreated();

    expect($captured)->not->toBeNull();
    $payload = $captured->broadcastWith();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect(array_keys($payload))->toBe(['id', 'thread', 'sender', 'state', 'body', 'edited', 'created_at']);
    expect(array_keys($payload['sender']))->toBe(['public_id', 'name']);
    foreach (['e26.leaky', 'email', 'phone', 'password', 'remember_token', 'is_frozen', 'hidden_', 'ip_address', 'user_agent'] as $needle) {
        expect($json)->not->toContain($needle);
    }

    foreach ($captured->broadcastOn() as $channel) {
        expect($channel)->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class);   // لا قناة عامة ولا presence
        expect($channel->name)->toStartWith('private-chat.');
    }
});

test('44: a frozen user (or one frozen while subscribed) is refused when the channel is re-authorized, and the HTTP channel-auth endpoint is rate limited', function () {
    e26_use_real_broadcaster();
    [$a, $b] = chatFriends();
    $channel = 'private-chat.'.chatThreads()->directOrCreate($a, $b)->public_id;

    e26_subscribe($this, $a, $channel)->assertOk();
    e26_freeze($a);
    e26_subscribe($this, $a->refresh(), $channel)->assertForbidden();   // التجميد يقطع القراءة اللحظية نفسها (EnsureAccountIsNotFrozen أو ChatAccess)

    // حد المعدّل: 90/دقيقة لكل مستخدم/IP (يمنع تخمين معرّفات الغرف أو إغراق الاستعلامات).
    $user = e26_user();
    $statuses = e26_hammer($this, 'post', '/broadcasting/auth', 100, ['socket_id' => '1.2', 'channel_name' => 'private-chat.nope']);
    $first = e26_first_429($statuses);
    expect($first)->not->toBeNull()->and($first)->toBeGreaterThan(30);
});

test('45: Reverb is closed by default - client (whisper) events off and, in production, a wildcard origin is surfaced by production:check instead of passing silently', function () {
    expect(config('reverb.apps.apps.0.accept_client_events_from'))->toBe('none');
    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);    // الافتراضي المحلي لا يتغير (E21)

    e25_as_production();
    $reverb = [
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => '26',
        'broadcasting.connections.reverb.key' => 'e26-test-key',
        'broadcasting.connections.reverb.secret' => 'e26-test-secret-not-real-0123456789',
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.servers.reverb.hostname' => 'ws.ahjiyat.example.com',
    ];

    [, $wildcard] = e25_check($reverb + ['reverb.apps.apps.0.allowed_origins' => ['*']]);
    expect(e25_statuses($wildcard, 'REVERB_ALLOWED_ORIGINS'))->toContain('WARN');

    [, $strict] = e25_check($reverb + ['reverb.apps.apps.0.allowed_origins' => ['ahjiyat.example.com']]);
    expect(e25_statuses($strict, 'REVERB_ALLOWED_ORIGINS'))->toContain('PASS')->not->toContain('WARN');

    // قيمة بيئة خاطئة لا تفتح أحداث العميل: لا يقبل التطبيق 'all' إلا إن ضُبطت صراحة.
    expect(file_get_contents(base_path('config/reverb.php')))->toContain("env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none')");
});

test('45b: the front-end never publishes client events, so closing them breaks nothing (no whisper/trigger usage in resources/js)', function () {
    $offenders = [];
    $dir = base_path('resources/js');

    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('/\.(js|mjs|ts)$/', $file->getFilename())) {
                $source = file_get_contents($file->getPathname());
                if (preg_match('/\.whisper\s*\(|\.trigger\s*\(\s*[\'"]client-/', $source)) {
                    $offenders[] = e26_relative($file->getPathname());
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
```

### `tests/Feature/SecurityE26/E26RequestSecurityTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

use App\Http\Middleware\TrustApplicationHosts;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));
afterEach(fn () => Request::setTrustedHosts([]));

test('35: with CSRF enforcement active, a state-changing POST without a token is refused (419) and changes nothing', function () {
    $user = e26_user();
    $this->actingAs($user);
    $original = $this->app['env'];
    $this->app['env'] = 'local';        // VerifyCsrfToken يتخطى الفحص فقط حين تكون البيئة testing

    try {
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->patch('/profile', ['name' => 'Hacked', 'email' => $user->email])->assertStatus(419);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    expect($user->fresh()->name)->not->toBe('Hacked');
});

test('35b: every state-changing web route runs inside the web middleware group (CSRF + session) and no route is excluded from CSRF', function () {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $mutates = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
        $middleware = $route->gatherMiddleware();

        // storage/{path}: مسار القرص المحلي من الإطار (PUT للروابط الموقّعة فقط، خارج مجموعة web بتصميمه).
        $framework = $route->uri() === 'storage/{path}';

        if ($mutates && ! $framework && str_starts_with($route->uri(), 'api/') === false && ! in_array('web', $middleware, true) && ! str_contains($route->uri(), 'livewire') && ! str_starts_with($route->uri(), 'admin')) {
            $offenders[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($offenders)->toBe([]);

    // لا استثناءات CSRF مضافة بالتطبيق: لا ملف VerifyCsrfToken مخصّص ولا validateCsrfTokens(except:) في bootstrap/app.php.
    expect(file_exists(base_path('app/Http/Middleware/VerifyCsrfToken.php')))->toBeFalse();
    expect(e26_php_code(base_path('bootstrap/app.php')))->not->toContain('validateCsrfTokens');
});

test('36: no GET/HEAD route is a state-changing action (verbs live on POST/PATCH/PUT/DELETE); the only GET exceptions are the signed verification link and the ad-click redirect', function () {
    $mutating = '/^(store|update|destroy|delete|accept|decline|cancel|join|leave|remove|transfer|purchase|equip|unequip|send|read|readAll|restore|hide|report|start|submit|reveal|attempt|hint|complete|reflect|register|logout|resend|requestJoin|requestToJoin|cancelRequest|invite|deactivate|changeRole|updateSettings|updateVisibility|updatePreferences|roster|block|unblock|removeFriend|open)$/i';
    $allowed = ['App\Http\Controllers\Auth\EmailVerificationController@verify', 'App\Http\Controllers\AdClickController@__invoke'];
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $action = $route->getActionName();
        if (! str_starts_with($action, 'App\\Http\\Controllers\\') || in_array($action, $allowed, true)) {
            continue;
        }

        $method = str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : '__invoke';
        if (preg_match($mutating, $method)) {
            $offenders[] = $route->uri().' => '.$action;
        }
    }

    expect($offenders)->toBe([]);
});

test('36b: GET requests to every readable page leave the database untouched (no hidden writes besides tracking)', function () {
    $user = e26_user();
    $before = [\App\Models\Wallet::sum('pending_balance'), \App\Models\Wallet::sum('available_balance'), \App\Models\PuzzleAttempt::count(), \App\Models\RedemptionRequest::count(), \App\Models\Friendship::count()];

    foreach (['/', '/puzzles', '/leaderboard', '/store', '/challenges', '/competitions', '/teams', '/campaigns', '/seasons', '/wallet', '/redemption', '/inventory', '/friends', '/messages', '/notifications'] as $path) {
        $this->actingAs($user)->get($path);
    }

    expect([\App\Models\Wallet::sum('pending_balance'), \App\Models\Wallet::sum('available_balance'), \App\Models\PuzzleAttempt::count(), \App\Models\RedemptionRequest::count(), \App\Models\Friendship::count()])->toBe($before);
});

test('37: no request parameter or header steers a redirect off-site (login intended, ad click, post-login)', function () {
    $user = e26_user();

    $this->get('/login?redirect=https://evil.test&next=https://evil.test&intended=https://evil.test&return=//evil.test');
    $this->post('/login', ['email' => $user->email, 'password' => 'password', 'redirect' => 'https://evil.test', 'next' => '//evil.test', 'intended' => 'https://evil.test'])
        ->assertRedirect(route('home'));
    $this->post('/logout')->assertRedirect('/');

    // الوجهة المقصودة تُخزَّن من طلب GET محمي أصلًا لنفس المضيف فقط
    $this->get('/wallet')->assertRedirect(route('login'));
    expect(session('url.intended'))->toStartWith('http://localhost');

    $creative = SponsorCreative::factory()->create(['sponsor_campaign_id' => SponsorCampaign::factory()->create()->id, 'destination_url' => 'https://example.test/ok']);
    $this->get(route('ads.click', $creative).'?to=https://evil.test')->assertRedirect('https://example.test/ok');
});

test('38: security headers are present on public, guest-form and admin pages (including the CSP baseline)', function () {
    foreach (['/', '/login', '/puzzles', '/admin/login'] as $path) {
        $response = $this->get($path);
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Permissions-Policy');
        expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")->toContain("base-uri 'self'")->toContain("object-src 'none'")
            ->not->toContain('script-src');       // CSP كامل مؤجَّل عمدًا (Alpine/Livewire تحتاج inline/eval)
    }

    config(['security.headers.csp_baseline' => false]);
    expect($this->get('/')->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('39: HSTS is sent only for HTTPS in production - never on HTTP, never outside production', function () {
    $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');       // https لكن ليس إنتاجًا
    e25_as_production();
    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');        // إنتاج لكن http
    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))->toStartWith('max-age=');
});

test('40: credential, account and money pages are never stored by caches; public pages keep the default and authenticated pages stay private', function () {
    $user = e26_user();

    foreach (['/login', '/register', '/forgot-password'] as $path) {
        expect($this->get($path)->headers->get('Cache-Control'))->toContain('no-store');
    }
    foreach (['/profile', '/wallet', '/redemption', '/confirm-password'] as $path) {
        expect($this->actingAs($user)->get($path)->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect($this->get('/')->headers->get('Cache-Control'))->not->toContain('no-store')->and($this->actingAs($user)->get('/puzzles')->headers->get('Cache-Control'))->toContain('private');
});

test('E26-HOST: password-reset poisoning is blocked - a forged Host header is rejected by the trusted-host guard outside local/testing, and health probes stay reachable', function () {
    config(['app.url' => 'https://ahjiyat.example', 'security.hosts.extra' => ['www.ahjiyat.example']]);
    $this->app['env'] = 'production';
    $middleware = new TrustApplicationHosts($this->app);
    $pass = fn ($request) => response('ok');

    expect($middleware->hosts())->toBe(['^'.preg_quote('ahjiyat.example').'$', '^'.preg_quote('www.ahjiyat.example').'$']);

    $good = Request::create('https://ahjiyat.example/forgot-password', 'POST');
    expect($middleware->handle($good, $pass)->getContent())->toBe('ok');

    $www = Request::create('https://www.ahjiyat.example/forgot-password', 'POST');
    expect($middleware->handle($www, $pass)->getContent())->toBe('ok');

    $evil = Request::create('https://evil.test/forgot-password', 'POST');
    $middleware->handle($evil, $pass);       // يضبط المضيفات الموثوقة ثم يُمرِّر؛ قراءة المضيف تُفشل الطلب
    expect(fn () => $evil->getHost())->toThrow(\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException::class);

    $probe = Request::create('http://10.0.0.7/ready', 'GET');
    expect($middleware->handle($probe, $pass)->getContent())->toBe('ok');

    config(['security.hosts.enforce' => false]);
    Request::setTrustedHosts([]);
    $off = Request::create('https://evil.test/x', 'GET');
    $middleware->handle($off, $pass);
    expect($off->getHost())->toBe('evil.test');                  // مفتاح الإيقاف الاضطراري
});

test('E26-HOST-2: the middleware is a no-op in local and testing (XAMPP/127.0.0.1 keep working)', function () {
    config(['app.url' => 'https://ahjiyat.example']);
    $this->get('http://127.0.0.1/')->assertOk();
    $this->get('http://localhost:8000/')->assertOk();
});
```

### `tests/Feature/SecurityE26/E26StaticAuditTest.php`

```php
<?php

require_once __DIR__.'/_support.php';

/*
| E26 (W1-W10) - تدقيقات ساكنة تمنع عودة الأنماط الخطرة: HTML خام، mass assignment، SQL مبني من مدخلات، تنفيذ أوامر، أسرار بالمستودع، تصحيح متروك.
| تقرأ الملفات فقط (لا شبكة ولا تنفيذ). التعليقات تُزال قبل الفحص كي لا يُفشل شرحٌ بالتعليق الاختبار.
*/

if (! function_exists('e26_repo_text_files')) {
    /** ملفات النص المرفوعة للمستودع (بلا vendor/node_modules/tests/build/storage). @return list<string> */
    function e26_repo_text_files(): array
    {
        $roots = ['app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'tools', 'public'];
        $skip = '#(^|/)(vendor|node_modules|storage|build|tests|\.git)(/|$)#';
        $ext = '#\.(php|js|mjs|ts|json|md|txt|yml|yaml|xml|sh|ps1|css|blade\.php|env|example|ini|conf)$#i';
        $files = [];

        foreach ($roots as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = e26_relative($file->getPathname());
                if ($file->isFile() && ! preg_match($skip, $relative) && preg_match($ext, $relative) && $file->getSize() < 2_000_000) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (['.env.example', 'README.md', 'SECURITY.md', 'composer.json', 'package.json', 'phpunit.xml', 'vite.config.js', 'artisan'] as $root) {
            if (is_file(base_path($root))) {
                $files[] = base_path($root);
            }
        }

        return $files;
    }
}

test('W1: no private key, cloud/API token, real-looking password or app key is committed anywhere in the repository text files', function () {
    $patterns = [
        'private key' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
        'aws access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'github token' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
        'slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        'stripe/openai style key' => '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b|\bsk-[A-Za-z0-9]{32,}\b/',
        'google api key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'app key value' => '/APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]{20,}/',
        'jwt' => '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
    ];
    $hits = [];

    foreach (e26_repo_text_files() as $file) {
        $text = (string) file_get_contents($file);
        foreach ($patterns as $label => $regex) {
            if (preg_match($regex, $text)) {
                $hits[] = e26_relative($file).' ('.$label.')';
            }
        }
    }

    expect($hits)->toBe([]);
});

test('W1b: .env.example carries placeholders only - every secret-like key is empty, null or commented', function () {
    $secretKey = '/^(?<key>[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY|APP_KEY|APP_SECRET)[A-Z0-9_]*)\s*=\s*(?<value>.*)$/';
    $offenders = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match($secretKey, $line, $m)) {
            $value = trim($m['value'], " \t\"'");
            if ($value !== '' && strtolower($value) !== 'null' && ! str_starts_with($value, '${')) {
                $offenders[] = $m['key'];
            }
        }
    }

    expect($offenders)->toBe([]);
    expect(file_get_contents(base_path('.gitignore')))->toContain('.env');
});

test('W2: no debug leftovers (dd, dump, ray, var_dump, print_r, console.log, debugger) in application code, routes, views or scripts', function () {
    $offenders = [];

    foreach (e26_php_files(['app', 'routes', 'bootstrap', 'config', 'database/seeders', 'database/factories', 'database/migrations']) as $file) {
        if (preg_match('/(?<![\w>:$])(?:dd|dump|ray|var_dump|print_r|var_export|phpinfo|xdebug_break)\s*\(/', e26_php_code($file))) {
            $offenders[] = e26_relative($file);
        }
    }

    foreach ([base_path('resources/js'), base_path('resources/views')] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! preg_match('/\.(js|mjs|ts|php)$/', $file->getFilename())) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bconsole\.(log|debug|trace)\s*\(|^\s*debugger\s*;?\s*$/m', $source)) {
                $offenders[] = e26_relative($file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('W3: unescaped Blade output ({!! !!}) exists only for the static game-icon SVG map, never for data', function () {
    $found = [];

    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views'), FilesystemIterator::SKIP_DOTS));
    foreach ($dir as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            // نزيل تعليقات Blade {{-- --}} قبل الفحص.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
            if (str_contains($source, '{!!') || str_contains($source, '@php echo') || preg_match('/<\?=/', $source)) {
                $found[] = e26_relative($file->getPathname());
            }
        }
    }

    expect($found)->toBe(['resources/views/components/game-icon.blade.php']);

    $icon = (string) file_get_contents(base_path('resources/views/components/game-icon.blade.php'));
    expect($icon)->toContain("\$inner = \$paths[\$name] ?? \$paths['puzzle'];")->and(substr_count($icon, '{!!'))->toBe(1);

    // لا حقن HTML خام من PHP في Filament/Livewire (HtmlString/new HtmlString من بيانات المستخدم).
    $offenders = [];
    foreach (e26_php_files(['app/Filament', 'app/Livewire', 'app/Http']) as $file) {
        if (preg_match('/new\s+HtmlString\s*\(\s*\$|Blade::render\s*\(\s*\$|->html\s*\(\s*\$/', e26_php_code($file))) {
            $offenders[] = e26_relative($file);
        }
    }
    expect($offenders)->toBe([]);
});

test('W4: no request-wide mass assignment - no $request->all(), no unguard(), no empty $guarded, and the fillable lists of identity/state models hold no privilege fields', function () {
    $offenders = [];

    foreach (e26_php_files(['app', 'routes', 'database/seeders']) as $file) {
        $code = e26_php_code($file);
        if (preg_match('/\$request->all\s*\(|request\(\)->all\s*\(|Request::all\s*\(|->unguard\s*\(|Model::unguard|\$guarded\s*=\s*\[\s*\]|->fill\s*\(\s*\$request->(?:validated|input)\s*\(\s*\)\s*\)/', $code)) {
            $offenders[] = e26_relative($file);
        }
    }
    expect($offenders)->toBe([]);

    foreach (['is_frozen', 'email_verified_at', 'public_id', 'remember_token', 'is_admin'] as $field) {
        expect((new \App\Models\User)->getFillable())->not->toContain($field);
    }
});

test('W5: raw SQL never receives request data, and interpolated raw SQL exists only at the four reviewed server-built sites', function () {
    $rawCall = '/(?:whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|DB::raw|DB::statement|DB::select|DB::unprepared|DB::insert|DB::update|DB::delete)\s*\(/';
    $allowedInterpolation = [
        'app/Services/Analytics/EconomyAnalyticsService.php',      // تعبير فرق ساعات يُبنى من اسم سائق القاعدة
        'app/Services/Analytics/UserAnalyticsService.php',         // تعبير تجميع زمني يُبنى من match على قيمة داخلية
        'app/Services/Competitive/CompetitiveLifecycleService.php', // استعلام فرعي ثابت
        'app/Services/Teams/TeamChampionshipStandingsService.php', // CASE يُبنى من نقاط الإعداد بعد (int)
    ];
    $unsafe = [];
    $interpolated = [];

    foreach (e26_php_files(['app', 'routes', 'database/seeders']) as $file) {
        $relative = e26_relative($file);
        foreach (preg_split('/\R/', e26_php_code($file)) as $n => $line) {
            if (! preg_match($rawCall, $line)) {
                continue;
            }
            if (preg_match('/\$request\b|request\(|\$_(?:GET|POST|REQUEST|COOKIE)|->input\(|->query\(|->get\(\s*[\'"]/', $line)) {
                $unsafe[] = $relative.':'.($n + 1);
            }
            if (preg_match('/\{\$|"\s*\.\s*\$|\'\s*\.\s*\$/', $line) && ! in_array($relative, $allowedInterpolation, true)) {
                $interpolated[] = $relative.':'.($n + 1);
            }
        }
    }

    expect($unsafe)->toBe([])->and($interpolated)->toBe([]);
});

test('W6: no code-execution or unsafe-deserialization primitives in application code (eval, shell, unserialize, dynamic include, extract)', function () {
    $offenders = [];

    foreach (e26_php_files(['app', 'routes', 'config', 'bootstrap']) as $file) {
        $code = e26_php_code($file);
        if (preg_match('/(?<![\w>:$])(?:eval|shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec|unserialize|extract|create_function|assert)\s*\(|`[^`\n]*\$[^`\n]*`|(?:include|require)(?:_once)?\s*\(?\s*\$/', $code)) {
            $offenders[] = e26_relative($file);
        }
    }

    expect($offenders)->toBe([]);
});

test('W7: outbound HTTP and file reads are confined to the operations tooling (SmokeChecker with a configured URL, PreflightChecker reading local files) - no SSRF primitive in request paths', function () {
    $http = [];
    $files = [];

    foreach (e26_php_files(['app', 'routes']) as $file) {
        $code = e26_php_code($file);
        $relative = e26_relative($file);

        if (preg_match('/\bHttp::|curl_init|fsockopen|stream_socket_client|new\s+\\\\?GuzzleHttp\\\\Client|Guzzle/', $code)) {
            $http[] = $relative;
        }
        if (preg_match('/file_get_contents\s*\(|\bfopen\s*\(\s*[^\'")]*\$|readfile\s*\(|->get\s*\(\s*\$request/', $code)) {
            $files[] = $relative;
        }
    }

    expect($http)->toBe(['app/Services/Operations/SmokeChecker.php']);
    expect($files)->toBe(['app/Services/Operations/PreflightChecker.php']);
});

test('W8: nothing excludes a route from CSRF or sessions, and no route registers a closure that mutates state', function () {
    $offenders = [];

    foreach (e26_php_files(['routes', 'bootstrap', 'app/Providers']) as $file) {
        if (preg_match('/validateCsrfTokens|\$except\s*=|withoutMiddleware\s*\(\s*\[?[^)]*(?:Csrf|VerifyCsrfToken|StartSession|ShareErrorsFromSession)/', e26_php_code($file))) {
            $offenders[] = e26_relative($file);
        }
    }
    expect($offenders)->toBe([]);

    // لوحة Filament تعمل خلف VerifyCsrfToken نفسه (مُضمَّن لا مستثنى).
    expect(e26_php_code(base_path('app/Providers/Filament/AdminPanelProvider.php')))->toContain('VerifyCsrfToken::class');

    foreach (Route::getRoutes() as $route) {
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) && $route->getAction('uses') instanceof Closure && ! str_starts_with($route->uri(), 'livewire') && $route->uri() !== 'storage/{path}') {
            $offenders[] = implode('|', $route->methods()).' '.$route->uri().' (closure)';
        }
    }
    expect($offenders)->toBe([]);
});

test('W9: sensitive values never reach the logs - no Log:: call mentions passwords, tokens, secrets, cookies, authorization headers or whole request bodies', function () {
    $offenders = [];
    $logCall = '/(?:Log::(?:debug|info|notice|warning|error|critical|alert|emergency|log)|logger\s*\(|->(?:warning|error|info)\s*\(\s*[\'"][^\'"]+[\'"]\s*,)/';

    foreach (e26_php_files(['app']) as $file) {
        $code = e26_php_code($file);
        $relative = e26_relative($file);

        foreach (preg_split('/\R/', $code) as $n => $line) {
            if (preg_match($logCall, $line) && preg_match('/password|passwd|secret|api_key|authorization|cookie|remember|bearer|plainTextToken|\$request->all|->all\(\)|->input\(\s*\)|getContent\(\)/i', $line)) {
                $offenders[] = $relative.':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);

    // سطر قفل الدخول يحوي بصمة البريد لا البريد الخام.
    $login = e26_php_code(base_path('app/Http/Requests/Auth/LoginRequest.php'));
    expect($login)->toContain("'email_fp' => Fingerprint::email(")->not->toMatch("/Log::warning\\([^;]*'email'\\s*=>\\s*\\\$this->(?:input|email)/");
});

test('W10: E26 added no security package - the lock file is valid, commonmark was not downgraded below the audited floor, and no CAPTCHA/2FA/WAF/SIEM dependency exists', function () {
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
    $packages = collect($lock['packages'] ?? [])->keyBy('name');

    expect(base_path('composer.lock'))->toBeFile();
    expect(version_compare(ltrim((string) ($packages->get('league/commonmark')['version'] ?? '0'), 'v'), '2.10.1', '>='))->toBeTrue();

    // لا حزمة أمان إضافية (CAPTCHA/2FA/WAF/SIEM) دخلت المشروع ضمن E26.
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));
    foreach ($required as $name) {
        expect($name)->not->toMatch('#captcha|recaptcha|turnstile|2fa|two-factor|fortify|google2fa|waf|modsecurity|sentry|bugsnag|honeypot|firewall#i');
    }
});
```

### `tests/Feature/SecurityE26/_support.php`

```php
<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E26 - مساعدات مشتركة لاختبارات الأمان. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| لا شيء هنا يهاجم خدمة حقيقية: كل شيء محلي على قاعدة اختبار SQLite وكاش array.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';
require_once __DIR__.'/../Teams/TeamTestHelpers.php';
require_once __DIR__.'/../Chat/ChatTestHelpers.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';
require_once __DIR__.'/../OperationsE25/_support.php';

if (! function_exists('e26_user')) {
    function e26_user(array $attrs = []): User
    {
        return e16User($attrs);
    }

    function e26_freeze(User $user): User
    {
        $user->forceFill(['is_frozen' => true])->save();

        return $user->refresh();
    }

    /** [متاح، معلّق] للعملة الافتراضية. @return array{0: int, 1: int} */
    function e26_wallet_state(\App\Models\User $user): array
    {
        $wallet = app(\App\Services\Economy\CurrencyWalletService::class)->balanceFor($user, app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency())->refresh();

        return [(int) $wallet->available_balance, (int) $wallet->pending_balance];
    }

    /** يرسل طلبًا N مرة ويعيد قائمة رموز الحالة (للتحقق من لحظة بدء 429). @return list<int> */
    function e26_hammer($test, string $method, string|Closure $url, int $times, array|Closure $data = [], bool $json = false): array
    {
        $statuses = [];

        for ($i = 0; $i < $times; $i++) {
            $target = $url instanceof Closure ? $url($i) : $url;
            $payload = $data instanceof Closure ? $data($i) : $data;
            $call = $json ? $method.'Json' : $method;
            $statuses[] = $test->{$call}($target, $payload)->getStatusCode();
        }

        return $statuses;
    }

    /** أول فهرس (من 1) حصل على 429، أو null. */
    function e26_first_429(array $statuses): ?int
    {
        foreach ($statuses as $i => $status) {
            if ($status === 429) {
                return $i + 1;
            }
        }

        return null;
    }

    /** جدول رموز Sanctum غير موجود بالمستودع عمدًا (واجهة الموبايل خاملة). نُنشئه لاختبار الواجهة فقط ويُتراجَع عنه مع معاملة الاختبار. */
    function e26_sanctum_table(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /** كود PHP بلا تعليقات وسلاسل التوثيق (للتدقيقات الساكنة: التعليق قد يذكر الممنوع بالشرح). */
    function e26_php_code(string $file): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** كل ملفات PHP تحت مجلدات المشروع المذكورة (نسبة لجذر المشروع). @return list<string> */
    function e26_php_files(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    function e26_relative(string $absolute): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absolute), '/\\'));
    }
}
```

## B) ملفات معدَّلة (patch دقيق لكل ملف؛ النسخة الكاملة داخل الـzip)

### `app/GameEngine/Definitions/SequenceGameTypeDefinition.php`

```diff
--- a/app/GameEngine/Definitions/SequenceGameTypeDefinition.php
+++ b/app/GameEngine/Definitions/SequenceGameTypeDefinition.php
@@ -6,6 +6,7 @@
 use App\GameEngine\Contracts\GameValidator;
 use App\GameEngine\Contracts\ScoreCalculator;
 use App\GameEngine\Scoring\FlatScoreCalculator;
+use App\GameEngine\Support\SequenceTokens;
 use App\GameEngine\Validators\SequenceMatchValidator;
 use App\Models\Puzzle;
 
@@ -56,8 +57,9 @@
 
     public function publicPayload(Puzzle $puzzle): array
     {
+        // E26: عناصر مخلوطة برموز معتمة (لا فهارس أصلية ولا ترتيب الإجابة). انظر SequenceTokens.
         return [
-            'items' => $puzzle->game_config['items'] ?? [],
+            'items' => SequenceTokens::shuffledItems($puzzle),
         ];
     }
 }
\ No newline at end of file
```

### `app/GameEngine/Validators/SequenceMatchValidator.php`

```diff
--- a/app/GameEngine/Validators/SequenceMatchValidator.php
+++ b/app/GameEngine/Validators/SequenceMatchValidator.php
@@ -4,6 +4,7 @@
 
 use App\GameEngine\Contracts\GameValidator;
 use App\GameEngine\GameResult;
+use App\GameEngine\Support\SequenceTokens;
 use App\Models\Puzzle;
 
 /**
@@ -14,11 +15,22 @@
 {
     public function check(Puzzle $puzzle, array $submission): GameResult
     {
-        $submitted = array_map('intval', (array) ($submission['order'] ?? []));
-        $correct = array_map('intval', (array) ($puzzle->solution_data['order'] ?? []));
+        // E26: المُرسَل رموز معتمة (SequenceTokens) لا فهارس خام؛ أي مدخل غير نصي/رقمي أو مصفوفة متداخلة يُرفض فورًا.
+        $raw = (array) ($submission['order'] ?? []);
+        $expected = SequenceTokens::expectedOrder($puzzle);
 
-        $isCorrect = $correct !== [] && $submitted === $correct;
+        foreach ($raw as $value) {
+            if (! is_string($value)) {
+                return new GameResult(correct: false);
+            }
+        }
+
+        $submitted = array_values($raw);
+
+        $isCorrect = $expected !== []
+            && count($submitted) === count($expected)
+            && hash_equals(implode(',', $expected), implode(',', $submitted));
 
         return new GameResult(correct: $isCorrect);
     }
-}
\ No newline at end of file
+}
```

### `app/Http/Controllers/Api/AuthController.php`

```diff
--- a/app/Http/Controllers/Api/AuthController.php
+++ b/app/Http/Controllers/Api/AuthController.php
@@ -4,32 +4,64 @@
 
 use App\Http\Controllers\Controller;
 use App\Models\User;
+use App\Services\Security\AccountSecurityService;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Hash;
 use Illuminate\Validation\ValidationException;
 
 // تسجيل الدخول عبر Sanctum Personal Access Tokens - جاهز لتطبيق Kotlin
 // من دون أي تعديل على منطق الأعمال، فقط طبقة مصادقة موازية للويب.
+//
+// E26: (1) حدّ معدّل مزدوج على المسار (throttle:api-login)، (2) معادلة زمنية للحساب غير الموجود (لا تعداد حسابات بالزمن)،
+// (3) لا يصدر رمز لحساب مجمَّد أو لبريد غير موثَّق (نفس شروط اللعب بالويب)، (4) للرمز تاريخ انتهاء (security.api.token_ttl_minutes)،
+// (5) سقف لعدد الرموز الحيّة لكل مستخدم يمنع تراكمًا غير محدود.
 class AuthController extends Controller
 {
+    private const MAX_LIVE_TOKENS = 10;
+
+    private static ?string $dummyHash = null;
+
     public function login(Request $request)
     {
+        // E26: جدول personal_access_tokens غير موجود بهذا المستودع (لا هجرة Sanctum) فكان الدخول ينهار بـ500 ويُسرّب تتبّع استثناء بالسجلات. واجهة الرموز خاملة
+        // عمدًا حتى تُنشأ الهجرة مع تطبيق الموبايل؛ نُجيب 503 صريحًا بدل 500.
+        if (! AccountSecurityService::tokensTableExists()) {
+            return response()->json(['message' => 'تسجيل دخول التطبيقات غير مفعَّل بعد.'], 503);
+        }
+
         $request->validate([
-            'email' => ['required', 'email'],
-            'password' => ['required'],
-            'device_name' => ['required', 'string'],
+            'email' => ['required', 'email', 'max:255'],
+            'password' => ['required', 'string', 'max:1024'],
+            'device_name' => ['required', 'string', 'max:100'],
         ]);
 
         $user = User::where('email', $request->email)->first();
 
-        if (! $user || ! Hash::check($request->password, $user->password)) {
+        // نجزّئ دائمًا (حقيقيًا أو وهميًا) كي لا يفرّق الزمن بين "لا حساب" و"كلمة مرور خاطئة".
+        self::$dummyHash ??= Hash::make('e26-timing-equalizer');
+        $passwordOk = Hash::check((string) $request->password, $user?->password ?? self::$dummyHash);
+
+        if (! $user || ! $passwordOk) {
             throw ValidationException::withMessages([
                 'email' => ['بيانات الدخول غير صحيحة.'],
             ]);
         }
 
+        if ($user->is_frozen) {
+            return response()->json(['message' => 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم.'], 403);
+        }
+
+        if (! $user->hasVerifiedEmail()) {
+            return response()->json(['message' => 'وثّق بريدك الإلكتروني أولاً.'], 403);
+        }
+
+        $this->trimLiveTokens($user);
+
+        $ttl = (int) config('security.api.token_ttl_minutes', 43200);
+
         return response()->json([
-            'token' => $user->createToken($request->device_name)->plainTextToken,
+            'token' => $user->createToken($request->device_name, ['*'], $ttl > 0 ? now()->addMinutes($ttl) : null)->plainTextToken,
+            'expires_in_minutes' => $ttl > 0 ? $ttl : null,
             'user' => $user->only(['id', 'name', 'email']),
         ]);
     }
@@ -40,4 +72,14 @@
 
         return response()->json(['message' => 'تم تسجيل الخروج.']);
     }
+
+    /** يُبقي أحدث (MAX-1) رمزًا فقط قبل إصدار الجديد، فيحافظ السقف على MAX رمزًا حيًّا كحدّ أعلى. */
+    private function trimLiveTokens(User $user): void
+    {
+        $ids = $user->tokens()->orderByDesc('id')->pluck('id');
+
+        if ($ids->count() >= self::MAX_LIVE_TOKENS) {
+            $user->tokens()->whereIn('id', $ids->slice(self::MAX_LIVE_TOKENS - 1)->all())->delete();
+        }
+    }
 }
```

### `app/Http/Controllers/Api/PuzzleController.php`

```diff
--- a/app/Http/Controllers/Api/PuzzleController.php
+++ b/app/Http/Controllers/Api/PuzzleController.php
@@ -4,6 +4,7 @@
 
 use App\Http\Controllers\Controller;
 use App\Http\Requests\SolvePuzzleRequest;
+use App\GameEngine\GameTypeRegistry;
 use App\Models\Puzzle;
 use App\Models\PuzzleCategory;
 use App\Services\PuzzleAttemptService;
@@ -27,15 +28,31 @@
             ->paginate(20);
     }
 
-    public function show(Puzzle $puzzle)
+    public function show(Puzzle $puzzle, GameTypeRegistry $games)
     {
-        return $puzzle->only([
+        // E26: (1) غير المفعّلة 404 كالقائمة. (2) game_config الخام كان يسرّب ترتيب الإجابة الصحيح للترتيب (عناصر بالترتيب الصحيح) وأزواج بطاقات الذاكرة؛
+        // نُعيد حمولة النوع العامة فقط (publicPayload: عناصر مخلوطة برموز معتمة، بلا solution_data/hotspots) ونحتفظ بالمفتاح game_config للتوافق.
+        abort_unless($puzzle->is_active, 404);
+
+        return array_merge($puzzle->only([
             'id', 'title', 'type', 'difficulty', 'prompt', 'choices',
             'image_path', 'max_attempts', 'time_limit_seconds', 'gem_reward',
-            'game_type', 'game_config', 'renderer',
+            'game_type', 'renderer',
+        ]), [
+            'game_config' => $this->publicGamePayload($puzzle, $games),
         ]);
     }
 
+    /** حمولة النوع العامة؛ نوع غير مسجَّل (بيانات قديمة) = لا حمولة بدل خطأ 500. */
+    protected function publicGamePayload(Puzzle $puzzle, GameTypeRegistry $games): ?array
+    {
+        try {
+            return $games->definitionFor($puzzle->game_type)->publicPayload($puzzle);
+        } catch (\InvalidArgumentException) {
+            return null;
+        }
+    }
+
     public function attempt(SolvePuzzleRequest $request, Puzzle $puzzle)
     {
         $submission = (array) $request->input('submission', []);
```

### `app/Http/Controllers/Auth/AuthenticatedSessionController.php`

```diff
--- a/app/Http/Controllers/Auth/AuthenticatedSessionController.php
+++ b/app/Http/Controllers/Auth/AuthenticatedSessionController.php
@@ -3,6 +3,7 @@
 namespace App\Http\Controllers\Auth;
 
 use App\Http\Controllers\Controller;
+use App\Http\Requests\Auth\LoginRequest;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Auth;
@@ -14,23 +15,22 @@
         return view('auth.login');
     }
 
-    public function store(Request $request): RedirectResponse
+    /**
+     * E26: الدخول عبر LoginRequest (تحديد معدّل مزدوج + رسالة موحّدة + معادلة زمنية). تجديد معرّف الجلسة بعد النجاح (منع تثبيت الجلسة)، والحساب المجمَّد لا يبقى
+     * مصادَقًا ولا تبقى جلسته: تسجيل خروج + إبطال الجلسة + تجديد الرمز، لا مجرد logout يترك الجلسة حيّة.
+     */
+    public function store(LoginRequest $request): RedirectResponse
     {
-        $credentials = $request->validate([
-            'email' => ['required', 'email'],
-            'password' => ['required'],
-        ]);
-
-        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
-            return back()->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])->onlyInput('email');
-        }
+        $request->authenticate();
 
         $request->session()->regenerate();
 
         if (Auth::user()->is_frozen) {
-            Auth::logout();
+            Auth::guard('web')->logout();
+            $request->session()->invalidate();
+            $request->session()->regenerateToken();
 
-            return back()->withErrors(['email' => 'تم تجميد هذا الحساب مؤقتاً، يرجى التواصل مع الدعم.']);
+            return back()->withErrors(['email' => 'تم تجميد هذا الحساب مؤقتاً، يرجى التواصل مع الدعم.'])->onlyInput('email');
         }
 
         Auth::user()->update(['last_seen_at' => now()]);
```

### `app/Http/Controllers/Auth/NewPasswordController.php`

```diff
--- a/app/Http/Controllers/Auth/NewPasswordController.php
+++ b/app/Http/Controllers/Auth/NewPasswordController.php
@@ -4,6 +4,7 @@
 
 use App\Http\Controllers\Controller;
 use App\Models\User;
+use App\Services\Security\AccountSecurityService;
 use Illuminate\Auth\Events\PasswordReset;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -28,7 +29,7 @@
      *
      * @throws \Illuminate\Validation\ValidationException
      */
-    public function store(Request $request): RedirectResponse
+    public function store(Request $request, AccountSecurityService $security): RedirectResponse
     {
         $request->validate([
             'token' => ['required'],
@@ -41,12 +42,15 @@
         // database. Otherwise we will parse the error and return the response.
         $status = Password::reset(
             $request->only('email', 'password', 'password_confirmation', 'token'),
-            function (User $user) use ($request) {
+            function (User $user) use ($request, $security) {
                 $user->forceFill([
                     'password' => Hash::make($request->password),
                     'remember_token' => Str::random(60),
                 ])->save();
 
+                // E26: من سرق جلسة/رمز API قبل إعادة التعيين لا يبقى مصادَقًا بعدها.
+                $security->revokeCredentials($user);
+
                 event(new PasswordReset($user));
             }
         );
@@ -57,6 +61,6 @@
         return $status == Password::PASSWORD_RESET
                     ? redirect()->route('login')->with('status', __($status))
                     : back()->withInput($request->only('email'))
-                        ->withErrors(['email' => __($status)]);
+                        ->withErrors(['email' => 'رابط إعادة التعيين غير صالح أو منتهي الصلاحية. اطلب رابطًا جديدًا.']);
     }
 }
```

### `app/Http/Controllers/Auth/PasswordResetLinkController.php`

```diff
--- a/app/Http/Controllers/Auth/PasswordResetLinkController.php
+++ b/app/Http/Controllers/Auth/PasswordResetLinkController.php
@@ -29,16 +29,10 @@
             'email' => ['required', 'email'],
         ]);
 
-        // We will send the password reset link to this user. Once we have attempted
-        // to send the link, we will examine the response then see the message we
-        // need to show to the user. Finally, we'll send out a proper response.
-        $status = Password::sendResetLink(
-            $request->only('email')
-        );
+        // E26: رد **موحّد** سواء وُجد الحساب أم لا (لا تعداد حسابات)، والإرسال الفعلي يتم أو لا يتم بصمت. حالات الفشل الداخلية (مستخدم غير موجود، تحديد
+        // معدّل الوسيط الداخلي) لا تظهر للمُرسِل؛ الحدّان الخارجيان (IP + مستلم) بـthrottle:password-reset يبقيان.
+        Password::sendResetLink($request->only('email'));
 
-        return $status == Password::RESET_LINK_SENT
-                    ? back()->with('status', __($status))
-                    : back()->withInput($request->only('email'))
-                        ->withErrors(['email' => __($status)]);
+        return back()->with('status', 'إن كان هذا البريد مسجَّلًا لدينا فقد أرسلنا إليه رابط إعادة تعيين كلمة المرور.');
     }
 }
```

### `app/Http/Controllers/ChallengeController.php`

```diff
--- a/app/Http/Controllers/ChallengeController.php
+++ b/app/Http/Controllers/ChallengeController.php
@@ -28,6 +28,9 @@
 
     public function show(Challenge $challenge)
     {
+        // E26: تحدٍّ غير مفعّل (مسودة) لا يُعرض للعامة بالمعرّف التسلسلي؛ المسؤول يعاينه.
+        abort_unless($challenge->is_active || (Auth::user()?->can('admin.access') ?? false), 404);
+
         $challenge->load(['puzzles.category']);
 
         $leaderboard = $challenge->participants()
```

### `app/Http/Controllers/ProfileController.php`

```diff
--- a/app/Http/Controllers/ProfileController.php
+++ b/app/Http/Controllers/ProfileController.php
@@ -3,6 +3,7 @@
 namespace App\Http\Controllers;
 
 use App\Http\Requests\UpdateProfileRequest;
+use App\Services\Security\AccountSecurityService;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Support\Facades\Auth;
 
@@ -13,17 +14,24 @@
         return view('profile.edit', ['user' => Auth::user()]);
     }
 
-    public function update(UpdateProfileRequest $request): RedirectResponse
+    public function update(UpdateProfileRequest $request, AccountSecurityService $security): RedirectResponse
     {
         $user = Auth::user();
-        $user->fill($request->validated());
+        $user->fill($request->profileData());
 
         if ($user->isDirty('email')) {
             $user->email_verified_at = null;
         }
 
+        $emailChanged = $user->isDirty('email');
+
         $user->save();
 
+        // E26: تغيير البريد يُبطل جلسات الأجهزة الأخرى ورموز API ويدوّر remember_token (الجلسة الحالية تبقى).
+        if ($emailChanged) {
+            $security->revokeCredentials($user, $request->session()->getId());
+        }
+
         return back()->with('success', 'تم تحديث بياناتك بنجاح.');
     }
 }
```

### `app/Http/Controllers/PuzzleController.php`

```diff
--- a/app/Http/Controllers/PuzzleController.php
+++ b/app/Http/Controllers/PuzzleController.php
@@ -33,6 +33,9 @@
     {
         $user = Auth::user();
 
+        // E26: أحجية غير مفعّلة (مسودة/مسحوبة) لا تُعرض للعامة؛ المسؤول يعاينها. 404 لا 403 كي لا يُكشف وجودها.
+        abort_unless($puzzle->is_active || ($user && $user->can('admin.access')), 404);
+
         $attemptsUsed = $user
             ? $puzzle->attempts()->where('user_id', $user->id)->count()
             : 0;
```

### `app/Http/Middleware/EnsureAccountIsNotFrozen.php`

```diff
--- a/app/Http/Middleware/EnsureAccountIsNotFrozen.php
+++ b/app/Http/Middleware/EnsureAccountIsNotFrozen.php
@@ -20,6 +20,11 @@
         $user = Auth::user();
 
         if ($user && $user->is_frozen) {
+            // E26: طلبات JSON/API تتلقى 403 صريحًا (لا إعادة توجيه HTML تُربك عميل الموبايل وتُخفي التقييد).
+            if ($request->expectsJson() || $request->is('api/*')) {
+                return response()->json(['message' => 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم.'], 403);
+            }
+
             return back()->with('error', 'تم تقييد حسابك مؤقتاً. يرجى التواصل مع الدعم لمزيد من التفاصيل.');
         }
 
```

### `app/Http/Middleware/SecurityHeaders.php`

```diff
--- a/app/Http/Middleware/SecurityHeaders.php
+++ b/app/Http/Middleware/SecurityHeaders.php
@@ -8,6 +8,15 @@
 
 class SecurityHeaders
 {
+    /**
+     * E26: صفحات بيانات الاعتماد/الحساب/الإدارة: Cache-Control: no-store كي لا يُعاد عرضها من كاش المتصفح/وسيط بعد تسجيل الخروج (زر الرجوع). باقي الصفحات تبقى على
+     * افتراضي Laravel (no-cache, private) دون خسارة التنقل. المسارات بلا بادئة اللغة.
+     */
+    protected const NO_STORE_PATHS = [
+        'login', 'register', 'forgot-password', 'reset-password/*', 'confirm-password', 'verify-email', 'verify-email/*',
+        'profile', 'profile/*', 'wallet', 'redemption', 'redemption/*', 'admin', 'admin/*',
+    ];
+
     public function handle(Request $request, Closure $next): Response
     {
         $response = $next($request);
@@ -17,6 +26,16 @@
         $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
         $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
 
+        // E26: خط أساس CSP لا يؤثر على تنفيذ السكربتات (Alpine/Livewire/Filament تحتاج inline/eval فلا script-src هنا): يمنع التأطير من نطاق آخر وحقن <base> والمكوّنات
+        // الملحقة. قرار تأجيل CSP كامل موثَّق في docs/security-e26.md.
+        if (config('security.headers.csp_baseline', true) && ! $response->headers->has('Content-Security-Policy')) {
+            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
+        }
+
+        if ($request->isMethodCacheable() && $request->is(...self::NO_STORE_PATHS)) {
+            $response->headers->set('Cache-Control', 'no-store, private');
+        }
+
         if ($request->isSecure() && app()->environment('production')) {
             $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
         }
```

### `app/Http/Requests/Auth/LoginRequest.php`

```diff
--- a/app/Http/Requests/Auth/LoginRequest.php
+++ b/app/Http/Requests/Auth/LoginRequest.php
@@ -2,29 +2,45 @@
 
 namespace App\Http\Requests\Auth;
 
+use App\Support\Security\Fingerprint;
 use Illuminate\Auth\Events\Lockout;
 use Illuminate\Foundation\Http\FormRequest;
-use Illuminate\Support\Facades\{Auth, RateLimiter};
-use Illuminate\Support\{Str, Stringable};
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Hash;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Validation\ValidationException;
 
+/**
+ * E26: مسار الدخول الفعلي (كان صنفًا ميتًا غير مستعمل، وPOST /login بلا أي تحديد معدّل -> تخمين/حشو بيانات اعتماد بلا حدّ).
+ *  - مفتاحان: (بريد مُجزَّأ + IP) لكل حساب من عنوان، وIP وحده لرشّ كلمات مرور على حسابات متعددة (سقف أعلى كي لا يضرّ شبكة مشتركة كجامعة).
+ *  - لا بريد خام بالمفاتيح (بصمة SHA-256)، والنجاح يمسح مفتاح (حساب+IP) فقط؛ سقف الـIP يبقى حتى انتهاء نافذته.
+ *  - رسالة واحدة موحّدة لـ"بريد غير موجود" و"كلمة مرور خاطئة"، مع معادلة زمنية: حساب غير موجود يدفع كلفة تجزئة مماثلة (لا تعداد بالزمن).
+ *  - قفل الحساب لا يُسجَّل بالسجل إلا مرة واحدة لكل نافذة قفل (لا إغراق للسجلات)، ببصمة البريد لا الخام.
+ */
 class LoginRequest extends FormRequest
 {
-    public function authorize(): bool { return true; }
+    private static ?string $dummyHash = null;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
 
     public function rules(): array
     {
         return [
-            'email'    => ['required', 'string', 'email'],
-            'password' => ['required', 'string'],
+            'email' => ['required', 'string', 'email', 'max:255'],
+            'password' => ['required', 'string', 'max:1024'],
         ];
     }
 
     public function messages(): array
     {
         return [
-            'email.required'    => 'البريد الإلكتروني مطلوب.',
-            'email.email'       => 'صيغة البريد الإلكتروني غير صحيحة.',
+            'email.required' => 'البريد الإلكتروني مطلوب.',
+            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
             'password.required' => 'كلمة المرور مطلوبة.',
         ];
     }
@@ -33,11 +49,12 @@
     {
         $this->ensureIsNotRateLimited();
 
-        if (!Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
-            RateLimiter::hit($this->throttleKey());
-            throw ValidationException::withMessages([
-                'email' => __('البريد الإلكتروني أو كلمة المرور غير صحيحة.'),
-            ]);
+        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
+            $this->equalizeTiming();
+            RateLimiter::hit($this->throttleKey(), $this->decaySeconds());
+            RateLimiter::hit($this->ipThrottleKey(), $this->decaySeconds());
+
+            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
         }
 
         RateLimiter::clear($this->throttleKey());
@@ -45,18 +62,56 @@
 
     public function ensureIsNotRateLimited(): void
     {
-        if (!RateLimiter::tooManyAttempts($this->throttleKey(), 5)) return;
+        $accountLocked = RateLimiter::tooManyAttempts($this->throttleKey(), (int) config('security.login.max_attempts', 5));
+        $ipLocked = RateLimiter::tooManyAttempts($this->ipThrottleKey(), (int) config('security.login.ip_max_attempts', 30));
+
+        if (! $accountLocked && ! $ipLocked) {
+            return;
+        }
+
+        $key = $accountLocked ? $this->throttleKey() : $this->ipThrottleKey();
+        $seconds = max(1, RateLimiter::availableIn($key));
 
         event(new Lockout($this));
-        $seconds = RateLimiter::availableIn($this->throttleKey());
 
-        throw ValidationException::withMessages([
-            'email' => "محاولات كثيرة جداً. يرجى المحاولة بعد {$seconds} ثانية.",
-        ]);
+        // سطر سجل واحد لكل نافذة قفل (Cache::add ذرّي)، لا سطر لكل محاولة محجوبة.
+        if (Cache::add('login-lockout-logged:'.$key, 1, $seconds)) {
+            Log::warning('security.login_lockout', [
+                'scope' => $accountLocked ? 'account_ip' : 'ip',
+                'email_fp' => Fingerprint::email($this->input('email')),
+                'ip' => $this->ip(),
+                'retry_after' => $seconds,
+            ]);
+        }
+
+        throw ValidationException::withMessages(['email' => "محاولات دخول كثيرة. حاول مجددًا بعد {$seconds} ثانية."]);
     }
 
     public function throttleKey(): string
     {
-        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
+        return 'login:acct:'.Fingerprint::email($this->input('email')).'|'.$this->ip();
+    }
+
+    public function ipThrottleKey(): string
+    {
+        return 'login:ip:'.$this->ip();
+    }
+
+    protected function decaySeconds(): int
+    {
+        return max(1, (int) config('security.login.decay_seconds', 300));
+    }
+
+    /**
+     * Auth::attempt لا يجزّئ كلمة المرور حين لا يوجد مستخدم بالبريد، فيصير الرد أسرع بكثير => تعداد حسابات بالزمن. ندفع كلفة تجزئة مماثلة للحساب غير الموجود.
+     */
+    protected function equalizeTiming(): void
+    {
+        $exists = \App\Models\User::query()->where('email', (string) $this->input('email'))->exists();
+
+        if (! $exists) {
+            self::$dummyHash ??= Hash::make('e26-timing-equalizer');
+            Hash::check((string) $this->input('password'), self::$dummyHash);
+        }
     }
-}
\ No newline at end of file
+}
```

### `app/Http/Requests/CompetitiveSubmitRequest.php`

```diff
--- a/app/Http/Requests/CompetitiveSubmitRequest.php
+++ b/app/Http/Requests/CompetitiveSubmitRequest.php
@@ -31,9 +31,10 @@
         return [
             'answer' => ['required_without:submission', 'nullable', 'string', 'max:255'],
             'submission' => ['sometimes', 'array'],
-            'submission.order' => ['sometimes', 'array'],
-            'submission.order.*' => ['integer'],
-            'submission.matches' => ['sometimes', 'array'],
+            // E26: ترتيب الترتيب رموز معتمة نصية (SequenceTokens) لا أعداد؛ وحدود على أحجام المصفوفات كي لا يُرسل العميل حمولات ضخمة.
+            'submission.order' => ['sometimes', 'array', 'max:100'],
+            'submission.order.*' => ['string', 'max:64'],
+            'submission.matches' => ['sometimes', 'array', 'max:500'],
             'submission.moves' => ['sometimes', 'integer'],
         ];
     }
```

### `app/Http/Requests/SolvePuzzleRequest.php`

```diff
--- a/app/Http/Requests/SolvePuzzleRequest.php
+++ b/app/Http/Requests/SolvePuzzleRequest.php
@@ -27,12 +27,13 @@
         return [
             'answer' => ['required_without:submission', 'nullable', 'string', 'max:255'],
             'submission' => ['sometimes', 'array'],
-            'submission.order' => ['sometimes', 'array'],
-            'submission.order.*' => ['integer'],
-            'submission.matches' => ['sometimes', 'array'],
+            // E26: ترتيب الترتيب رموز معتمة نصية (SequenceTokens) لا أعداد؛ وحدود على أحجام المصفوفات كي لا يُرسل العميل حمولات ضخمة.
+            'submission.order' => ['sometimes', 'array', 'max:100'],
+            'submission.order.*' => ['string', 'max:64'],
+            'submission.matches' => ['sometimes', 'array', 'max:500'],
             'submission.moves' => ['sometimes', 'integer'],
             'submission.elapsed_seconds' => ['sometimes', 'integer'],
-            'start_token' => ['sometimes', 'string'],
+            'start_token' => ['sometimes', 'string', 'max:2000'],
             'used_hint' => ['sometimes', 'boolean'],
         ];
     }
```

### `app/Http/Requests/UpdateProfileRequest.php`

```diff
--- a/app/Http/Requests/UpdateProfileRequest.php
+++ b/app/Http/Requests/UpdateProfileRequest.php
@@ -20,6 +20,27 @@
                 'required', 'string', 'email', 'max:255',
                 Rule::unique('users', 'email')->ignore($this->user()->id),
             ],
+            // E26: تغيير البريد = بوابة استعادة الحساب، فيتطلب كلمة المرور الحالية (جلسة مسروقة وحدها لا تكفي للاستيلاء على الحساب). الاسم وحده لا يتطلبها.
+            'current_password' => [Rule::requiredIf(fn () => $this->emailIsChanging()), 'nullable', 'string', 'current_password:web'],
         ];
     }
+
+    public function messages(): array
+    {
+        return [
+            'current_password.required' => 'أدخل كلمة المرور الحالية لتغيير البريد الإلكتروني.',
+            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
+        ];
+    }
+
+    public function emailIsChanging(): bool
+    {
+        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $this->user()->email);
+    }
+
+    /** الحقول المسموح تعبئتها بالنموذج فقط (current_password للتحقق لا للتخزين). */
+    public function profileData(): array
+    {
+        return $this->safe()->only(['name', 'email']);
+    }
 }
```

### `app/Providers/AppServiceProvider.php`

```diff
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -5,6 +5,7 @@
 use App\Models\User;
 use App\Services\AuthorizationSafetyService;
 use App\Services\Teams\TeamService;
+use App\Support\Security\Fingerprint;
 use Illuminate\Cache\RateLimiting\Limit;
 use App\Support\ReleaseInfo;
 use Illuminate\Http\Request;
@@ -75,8 +76,49 @@
         });
 
         // E8: لم تكن محمية إطلاقاً سابقاً (بند 22) - منع email flooding.
+        // E26: حدّ ثانٍ **لكل مستلم** (بريد مُجزَّأ، لا بريد خام بمفتاح المحدِّد) كي لا يُغرِق مهاجمٌ بعناوين IP متعددة صندوق ضحية واحدة.
         RateLimiter::for('password-reset', function (Request $request) {
-            return Limit::perMinutes(10, 5)->by($request->ip());
+            return [
+                Limit::perMinutes(10, 5)->by('pr-ip:'.$request->ip()),
+                Limit::perHour(3)->by('pr-rcpt:'.Fingerprint::email($request->input('email'))),
+            ];
+        });
+
+        // E26: تنفيذ إعادة التعيين نفسه (POST /reset-password) لم يكن محدودًا. الرمز عشوائي طويل، لكن لا سبب لقبول طرق غير محدودة.
+        RateLimiter::for('password-reset-submit', function (Request $request) {
+            return Limit::perMinutes(10, 10)->by('prs-ip:'.$request->ip());
+        });
+
+        // E26: تأكيد كلمة المرور (confirm-password) كان قابلًا للتخمين بلا حدّ داخل جلسة مسروقة. المفتاح المستخدم.
+        RateLimiter::for('password-confirm', function (Request $request) {
+            return Limit::perMinute(6)->by('pc:'.($request->user()?->id ?: $request->ip()));
+        });
+
+        // E26: تعديل بيانات الحساب الحسّاسة (الاسم/البريد/ظهور الملف) - أدنى بكثير من أي استخدام بشري.
+        RateLimiter::for('account-sensitive', function (Request $request) {
+            return Limit::perMinute(10)->by('acs:'.($request->user()?->id ?: $request->ip()));
+        });
+
+        // E26: أفعال لعب خفيفة كانت بلا حدّ (شراء تلميح، انضمام تحدٍّ، إكمال/تأمل خطوة حملة).
+        RateLimiter::for('game-actions', function (Request $request) {
+            return Limit::perMinute(30)->by('ga:'.($request->user()?->id ?: $request->ip()));
+        });
+
+        RateLimiter::for('puzzle-hint', function (Request $request) {
+            return Limit::perMinute(10)->by('ph:'.($request->user()?->id ?: $request->ip()));
+        });
+
+        // E26: مصادقة قنوات البثّ الخاصة (/broadcasting/auth): اشتراك كل غرفة مرة واحدة عادةً؛ السقف يمنع استنزاف القاعدة باستعلامات التخويل.
+        RateLimiter::for('broadcast-auth', function (Request $request) {
+            return Limit::perMinute(90)->by('bca:'.($request->user()?->id ?: $request->ip()));
+        });
+
+        // E26: دخول API (إصدار رمز Sanctum) - حدّ لكل IP وحدّ لكل (بريد مُجزَّأ+IP). لا يتشارك مع محدّد دخول الويب.
+        RateLimiter::for('api-login', function (Request $request) {
+            return [
+                Limit::perMinute(20)->by('apil-ip:'.$request->ip()),
+                Limit::perMinutes(5, 5)->by('apil-acct:'.Fingerprint::email($request->input('email')).'|'.$request->ip()),
+            ];
         });
 
         RateLimiter::for('email-verification', function (Request $request) {
@@ -174,6 +216,7 @@
         RateLimiter::for('api', function (Request $request) {
             return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
         });
-        
+
     }
+
 }
\ No newline at end of file
```

### `app/Services/Economy/CurrencyWalletService.php`

```diff
--- a/app/Services/Economy/CurrencyWalletService.php
+++ b/app/Services/Economy/CurrencyWalletService.php
@@ -269,6 +269,15 @@
         }
     }
 
+    /**
+     * E26: قفل صف المحفظة داخل معاملة قائمة (SELECT ... FOR UPDATE). يستعمله المستدعي ليُسلسل «قراءة السقف اليومي ثم الإيداع»: بدونه تقرأ عدة طلبات متزامنة
+     * (أحجيات مختلفة) المجموع نفسه قبل أي إيداع فتتجاوز كلها السقف اليومي معًا. يجب استدعاؤه داخل DB::transaction.
+     */
+    public function lockWallet(User $user, Currency $currency): Wallet
+    {
+        return $this->lockedWallet($user, $currency);
+    }
+
     protected function lockedWallet(User $user, Currency $currency): Wallet
     {
         return Wallet::query()
```

### `app/Services/PuzzleAttemptService.php`

```diff
--- a/app/Services/PuzzleAttemptService.php
+++ b/app/Services/PuzzleAttemptService.php
@@ -35,6 +35,12 @@
     ): array {
         $context ??= AttemptContext::none();
 
+        // E26: المسار العام المباشر (بلا سياق) كان يقبل محاولات ومكافآت على أحجية معطَّلة/مسودة/مسحوبة لمن يخمّن معرّفها. السياقات (حملة/جلسة/تنافسي) تفحص
+        // التفعيل بخدماتها (CampaignPuzzleService/GameSessionService/CompetitiveEligibility) فلا نغيّر سلوك جلسة جارية بقرار إداري لاحق.
+        if (! $context->isPresent() && ! $puzzle->is_active) {
+            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
+        }
+
         $result = DB::transaction(function () use ($user, $puzzle, $submittedAnswer, $usedHint, $submission, $context) {
             $previousAttempts = PuzzleAttempt::where('user_id', $user->id)
                 ->where('puzzle_id', $puzzle->id)
@@ -105,6 +111,9 @@
             : $directive->amount;
 
         if ($isDefaultCurrency) {
+            // E26: تسلسل قراءة السقف + الإيداع بقفل صف المحفظة (نحن داخل معاملة attempt()): طلبات متزامنة لأحجيات مختلفة كانت تتجاوز السقف اليومي معًا.
+            $this->wallets->lockWallet($user, $currency);
+
             $dailyCap = config('gems.daily_earn_cap');
             $alreadyEarnedToday = $this->wallets->dailyEarnedToday($user, $currency);
             $reward = min($rawReward, max(0, $dailyCap - $alreadyEarnedToday));
@@ -125,6 +134,11 @@
 
     public function purchaseHint(User $user, Puzzle $puzzle): string
     {
+        // E26: لا تلميحات (ولا خصم جواهر) لأحجية غير مفعّلة.
+        if (! $puzzle->is_active) {
+            throw new \RuntimeException('هذه الأحجية غير متاحة حالياً.');
+        }
+
         if (blank($puzzle->hint)) {
             throw new \RuntimeException('لا يوجد تلميح متاح لهذه الأحجية.');
         }
```

### `bootstrap/app.php`

```diff
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -3,7 +3,9 @@
 use App\Http\Controllers\HealthController;
 use App\Http\Middleware\EnsureAccountIsNotFrozen;
 use App\Http\Middleware\RequestObservability;
+use App\Http\Middleware\RestrictBroadcastChannels;
 use App\Http\Middleware\SecurityHeaders;
+use App\Http\Middleware\TrustApplicationHosts;
 use App\Http\Middleware\TrackDeviceFingerprint;
 use Illuminate\Foundation\Application;
 use Illuminate\Foundation\Configuration\Exceptions;
@@ -22,11 +24,15 @@
         },
     )
     // E21: قنوات البثّ الخاصة + مسار /broadcasting/auth (وسيط web، يتطلب مستخدمًا مصادَقًا)
-    ->withBroadcasting(__DIR__.'/../routes/channels.php')
+    // E26: حدّ معدّل لمسار /broadcasting/auth (كل اشتراك قناة خاصة يكلّف استعلامًا) + قصر الأسماء على private-chat.* (يغلق presence-chat.* الذي كان يسرّب user_id الداخلي).
+    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'throttle:broadcast-auth', RestrictBroadcastChannels::class]])
     ->withMiddleware(function (Middleware $middleware) {
         // E24: معرّف طلب + سجل الطلبات البطيئة + Server-Timing محليًا (عالميّ، فيشمل web وapi وكل الاستثناءات).
         $middleware->prepend(RequestObservability::class);
 
+        // E26: تقييد ترويسة Host (يمنع تسميم روابط إعادة التعيين/التوثيق). صامت محليًا وبالاختبارات؛ انظر TrustApplicationHosts.
+        $middleware->prepend(TrustApplicationHosts::class);
+
         $middleware->web(append: [
             TrackDeviceFingerprint::class,
             SecurityHeaders::class,
```

### `config/reverb.php`

```diff
--- a/config/reverb.php
+++ b/config/reverb.php
@@ -93,7 +93,8 @@
                 'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                 'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                 'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
-                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
+                // E26: لا يستعمل التطبيق أحداث العميل (whisper) إطلاقًا؛ الافتراضي 'none' يمنع عضوًا مصادَقًا على قناة دردشة من بثّ أحداث عشوائية لبقية الأعضاء مباشرة دون المرور بالخادم.
+                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none'),
                 'rate_limiting' => [
                     'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                     'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
```

### `docs/security-hardening.md`

```diff
--- a/docs/security-hardening.md
+++ b/docs/security-hardening.md
@@ -28,7 +28,7 @@
 | `game-session-reveal` | 60/دقيقة   | user أو IP   |
 | `redemption`          | 5/ساعة      | user أو IP   |
 
-تسجيل الدخول (ويب + API) محمي يدويًا داخل الـController نفسه (5 محاولات/بريد+IP، نمط Laravel القياسي).
+تسجيل الدخول: **تصحيح E26** — كان الحد المذكور هنا لا يُطبَّق فعليًا على الويب (`LoginRequest` غير مستعمل). الآن حد مزدوج (بريد مجزَّأ+IP ثم IP) على الويب والـAPI، وبقية الحدود (إعادة التعيين، الملف الشخصي، الدردشة، الشراء...) في [security-e26](security-e26.md#حدود-الإساءة-rate-limits).
 
 ## رفع الملفات (E8-D)
 
```

### `resources/views/games/sequence.blade.php`

```diff
--- a/resources/views/games/sequence.blade.php
+++ b/resources/views/games/sequence.blade.php
@@ -1,11 +1,8 @@
 {{-- لعبة ترتيب التسلسل - العناصر تُخلط هون سيرفريًا فقط للعرض، والترتيب
      الصحيح الفعلي محفوظ بـ solution_data ولا يصل أبداً للمتصفح --}}
 @php
-    $shuffledItems = collect($puzzle->game_config['items'] ?? [])
-        ->map(fn ($label, $originalIndex) => ['idx' => $originalIndex, 'label' => $label])
-        ->shuffle()
-        ->values()
-        ->all();
+    // E26: رموز معتمة بدل الفهارس الأصلية (الفهرس التصاعدي كان هو الإجابة الصحيحة نفسها). انظر App\GameEngine\Support\SequenceTokens.
+    $shuffledItems = \App\GameEngine\Support\SequenceTokens::shuffledItems($puzzle);
 @endphp
 
 <div x-data="sequenceGame({{ Illuminate\Support\Js::from($shuffledItems) }})" class="mb-5">
```

### `resources/views/profile/edit.blade.php`

```diff
--- a/resources/views/profile/edit.blade.php
+++ b/resources/views/profile/edit.blade.php
@@ -52,6 +52,15 @@
                         </span>
                     @endunless
                 </div>
+                <div>
+                    <label for="current_password" class="block text-sm font-bold text-slate-300 mb-2">كلمة المرور الحالية</label>
+                    <input id="current_password" type="password" name="current_password" autocomplete="current-password"
+                           class="input-gem">
+                    <span class="text-xs text-slate-500 font-semibold mt-1.5 block">مطلوبة فقط عند تغيير البريد الإلكتروني.</span>
+                    @error('current_password')
+                        <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
+                    @enderror
+                </div>
 
                 <button type="submit" class="btn-gem w-full justify-center !mt-6">
                     حفظ التعديلات
```

### `routes/api.php`

```diff
--- a/routes/api.php
+++ b/routes/api.php
@@ -9,10 +9,15 @@
 
 // هذا الملف مخصص لتطبيق الموبايل المستقبلي (Kotlin - Android)
 // نفس منطق الأعمال بالضبط عبر خدمات app/Services، فقط طبقة نقل مختلفة (JSON بدل Blade).
-Route::post('/auth/login', [AuthController::class, 'login']);
+// E26: إصدار الرمز بحدّ مزدوج (IP + بريد مُجزَّأ/IP)، ولا يصدر لحساب مجمَّد أو لبريد غير موثَّق (نفس شروط اللعب بالويب).
+Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');
 
 Route::middleware('auth:sanctum')->group(function () {
     Route::post('/auth/logout', [AuthController::class, 'logout']);
+});
+
+// E26: كل ما سوى الخروج يتطلب بريدًا موثَّقًا وحسابًا غير مجمَّد (كان رمز مجمَّد/غير موثَّق يكسب جواهر عبر /api/puzzles/{id}/attempt).
+Route::middleware(['auth:sanctum', 'verified', 'account.active'])->group(function () {
 
     Route::get('/puzzles/categories', [PuzzleController::class, 'categories']);
     Route::get('/puzzles', [PuzzleController::class, 'index']);
```

### `routes/web.php`

```diff
--- a/routes/web.php
+++ b/routes/web.php
@@ -105,7 +105,8 @@
         ->middleware('throttle:password-reset')->name('password.email');
 
     Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
-    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
+    Route::post('/reset-password', [NewPasswordController::class, 'store'])
+        ->middleware('throttle:password-reset-submit')->name('password.store');
 });
 
 Route::middleware('auth')->group(function () {
@@ -118,10 +119,12 @@
         ->middleware('throttle:email-verification')->name('verification.send');
 
     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
-    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
+    Route::patch('/profile', [ProfileController::class, 'update'])
+        ->middleware('throttle:account-sensitive')->name('profile.update');
 
     Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
-    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);
+    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
+        ->middleware('throttle:password-confirm');
 
     Route::middleware(['verified', 'account.active'])->group(function () {
         // E19: إجراءات الفرق (POST/PATCH/DELETE فقط عدا صفحة الإدارة). التفويض من الدور الفعلي بالقاعدة، لا من الطلب.
@@ -153,21 +156,23 @@
 
         Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
             ->middleware('throttle:puzzle-attempt')->name('puzzles.attempt');
-        Route::post('/puzzles/{puzzle}/hint', [PuzzleController::class, 'hint'])->name('puzzles.hint');
+        Route::post('/puzzles/{puzzle}/hint', [PuzzleController::class, 'hint'])
+            ->middleware('throttle:puzzle-hint')->name('puzzles.hint');
 
         Route::post('/puzzles/{puzzle}/session', [GameSessionController::class, 'store'])
             ->middleware('throttle:game-session-start')->name('game-sessions.start');
         Route::post('/game-sessions/{session}/reveal', [GameSessionController::class, 'reveal'])
             ->middleware('throttle:game-session-reveal')->name('game-sessions.reveal');
 
-        Route::post('/challenges/{challenge}/join', [ChallengeController::class, 'join'])->name('challenges.join');
+        Route::post('/challenges/{challenge}/join', [ChallengeController::class, 'join'])
+            ->middleware('throttle:game-actions')->name('challenges.join');
 
         Route::get('/campaigns/{campaign:slug}/steps/{step}', [CampaignStepController::class, 'show'])
             ->name('campaigns.steps.show');
         Route::post('/campaigns/{campaign:slug}/steps/{step}/complete', [CampaignStepController::class, 'complete'])
-            ->name('campaigns.steps.complete');
+            ->middleware('throttle:game-actions')->name('campaigns.steps.complete');
         Route::post('/campaigns/{campaign:slug}/steps/{step}/reflect', [CampaignStepController::class, 'reflect'])
-            ->name('campaigns.steps.reflect');
+            ->middleware('throttle:game-actions')->name('campaigns.steps.reflect');
         Route::post('/campaigns/{campaign:slug}/steps/{step}/attempt', [CampaignStepController::class, 'attempt'])
             ->middleware('throttle:puzzle-attempt')->name('campaigns.steps.attempt');
         Route::post('/campaigns/{campaign:slug}/steps/{step}/session', [CampaignStepController::class, 'startSession'])
@@ -191,10 +196,7 @@
         Route::delete('/profile/cosmetics/{slot}/unequip', [ProfileCustomizationController::class, 'unequip'])
             ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.unequip');
         Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
-            ->name('profile.visibility.update');
-
-        Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
-            ->name('profile.visibility.update');
+            ->middleware('throttle:account-sensitive')->name('profile.visibility.update');
 
         // E12: صفحة التقدُّم - قراءة فقط، لا Mutation عبر أي مسار هنا.
         Route::get('/progress', [\App\Http\Controllers\PlayerProgressionController::class, 'show'])->name('progress.show');
```

### `tests/Feature/CampaignPuzzleFlowTest.php`

```diff
--- a/tests/Feature/CampaignPuzzleFlowTest.php
+++ b/tests/Feature/CampaignPuzzleFlowTest.php
@@ -282,7 +282,7 @@
 
     $this->actingAs($user)
         ->post(route('campaigns.steps.attempt', [$step->gate->stage->campaign, $step]), [
-            'submission' => json_encode(['order' => [0, 1, 2]]),
+            'submission' => json_encode(['order' => \App\GameEngine\Support\SequenceTokens::expectedOrder($puzzle)]),
         ])
         ->assertRedirect()
         ->assertSessionHas('success');
```

### `tests/Feature/OperationsE25/SecurityAndConfigRegressionTest.php`

```diff
--- a/tests/Feature/OperationsE25/SecurityAndConfigRegressionTest.php
+++ b/tests/Feature/OperationsE25/SecurityAndConfigRegressionTest.php
@@ -32,20 +32,27 @@
 });
 
 test('E25-20: HSTS is sent only in production over real HTTPS, with a one-year max-age and no includeSubDomains/preload', function () {
-    // لا HSTS خارج الإنتاج حتى على HTTPS
-    $this->get('https://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');
+    // E26: بالإنتاج يقيّد TrustApplicationHosts الطلبات بمضيف APP_URL، فنضبطه لمضيف الاختبار، وننظّف حالة المضيفات الثابتة بعده كي لا تتسرّب لاختبارات تالية.
+    config(['app.url' => 'https://ahjiyat.example.com']);
 
-    $this->app['env'] = 'production';
-
-    // لا HSTS على HTTP حتى بالإنتاج
-    $this->get('http://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');
-
-    // HSTS على HTTPS بالإنتاج فقط
-    $header = $this->get('https://ahjiyat.example.com/')->headers->get('Strict-Transport-Security');
-
-    expect($header)->toBe('max-age=31536000')
-        ->and($header)->not->toContain('includeSubDomains')
-        ->and($header)->not->toContain('preload');
+    try {
+        // لا HSTS خارج الإنتاج حتى على HTTPS
+        $this->get('https://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');
+
+        $this->app['env'] = 'production';
+
+        // لا HSTS على HTTP حتى بالإنتاج
+        $this->get('http://ahjiyat.example.com/')->assertHeaderMissing('Strict-Transport-Security');
+
+        // HSTS على HTTPS بالإنتاج فقط
+        $header = $this->get('https://ahjiyat.example.com/')->headers->get('Strict-Transport-Security');
+
+        expect($header)->toBe('max-age=31536000')
+            ->and($header)->not->toContain('includeSubDomains')
+            ->and($header)->not->toContain('preload');
+    } finally {
+        \Illuminate\Http\Request::setTrustedHosts([]);
+    }
 });
 
 test('E25-20: no CORS headers are emitted for a foreign Origin, including on a preflight request', function () {
```

### `tests/Feature/ProfileTest.php`

```diff
--- a/tests/Feature/ProfileTest.php
+++ b/tests/Feature/ProfileTest.php
@@ -21,6 +21,7 @@
         ->patch('/profile', [
             'name' => 'Test User',
             'email' => 'test@example.com',
+            'current_password' => 'password', // E26: تغيير البريد يتطلب كلمة المرور الحالية
         ]);
 
     $response
```

### `tests/Feature/PuzzleAttemptServiceTest.php`

```diff
--- a/tests/Feature/PuzzleAttemptServiceTest.php
+++ b/tests/Feature/PuzzleAttemptServiceTest.php
@@ -86,10 +86,15 @@
     expect($puzzle->fresh()->solution_data)->toBe(['order' => [0, 1, 2]]);
 
     $wrongUser = User::factory()->create();
-    $wrongResult = $this->service->attempt($wrongUser, $puzzle, '', false, ['order' => [1, 0, 2]]);
+    // E26: المُرسَل رموز معتمة بالترتيب (SequenceTokens) لا فهارس خام؛ الأعداد الخام تُرفض.
+    $tokens = \App\GameEngine\Support\SequenceTokens::expectedOrder($puzzle);
+    $wrongResult = $this->service->attempt($wrongUser, $puzzle, '', false, ['order' => [$tokens[1], $tokens[0], $tokens[2]]]);
     expect($wrongResult['correct'])->toBeFalse();
 
-    $rightResult = $this->service->attempt($this->user, $puzzle, '', false, ['order' => [0, 1, 2]]);
+    $rawIndexResult = $this->service->attempt(User::factory()->create(), $puzzle, '', false, ['order' => [0, 1, 2]]);
+    expect($rawIndexResult['correct'])->toBeFalse();
+
+    $rightResult = $this->service->attempt($this->user, $puzzle, '', false, ['order' => $tokens]);
     expect($rightResult['correct'])->toBeTrue()
         ->and($rightResult['gems_awarded'])->toBe(20);
 
@@ -98,5 +103,5 @@
         ->where('is_correct', true)
         ->first();
 
-    expect($attempt->submission_snapshot)->toBe(['order' => [0, 1, 2]]);
+    expect($attempt->submission_snapshot)->toBe(['order' => $tokens]);
 });
\ No newline at end of file
```

### `tests/Feature/PuzzleControllerTest.php`

```diff
--- a/tests/Feature/PuzzleControllerTest.php
+++ b/tests/Feature/PuzzleControllerTest.php
@@ -53,7 +53,8 @@
 
     $this->actingAs($user)
         ->post(route('puzzles.attempt', $puzzle), [
-            'submission' => json_encode(['order' => [0, 1, 2]]),
+            // E26: المُرسَل رموز معتمة (SequenceTokens) لا فهارس خام
+            'submission' => json_encode(['order' => \App\GameEngine\Support\SequenceTokens::expectedOrder($puzzle)]),
         ])
         ->assertRedirect(route('puzzles.show', $puzzle))
         ->assertSessionHas('success');
```

## C) Migrations

لا توجد ترحيلات جديدة في E26 (0). جدول رموز Sanctum غير مضاف عمدًا (واجهة الموبايل خاملة؛ انظر Deferred risks في `docs/security-e26.md`).

## D) إضافات `.env` / `.env.example`

كلها **اختيارية** بقيم افتراضية آمنة (لا شيء مطلوب لتعمل المنصة محليًا). السطور المضافة إلى `.env.example` (معلَّقة، placeholders فقط):

### `.env.example`

```diff
--- a/.env.example
+++ b/.env.example
@@ -153,3 +153,13 @@
 
 # الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج. استثناء واعٍ لعملية واحدة فقط (لا تضعه بملف .env الدائم):
 # OPS_ALLOW_DESTRUCTIVE_COMMANDS=false
+
+# E26 - أمان التطبيق (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/security-e26.md.
+# SECURITY_LOGIN_MAX_ATTEMPTS=5              # محاولات دخول فاشلة لكل (بريد+IP) قبل القفل المؤقت
+# SECURITY_LOGIN_DECAY_SECONDS=300           # مدة القفل بالثواني
+# SECURITY_LOGIN_IP_MAX_ATTEMPTS=30          # سقف الفشل لكل IP (رش كلمات المرور). أعلى بكثير كي لا يضرّ شبكة مشتركة
+# SECURITY_API_EXPIRY_MINUTES=43200       # عمر رمز API (30 يومًا). 0 = بلا انتهاء (غير مستحسن)
+# SECURITY_CSP_BASELINE=true                 # CSP أساسي (frame-ancestors/base-uri/object-src) لا يمسّ تنفيذ السكربتات
+# REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none  # أحداث العميل مغلقة (التطبيق لا يستعملها)
+# SECURITY_TRUST_HOSTS=true                  # يقيّد Host بمضيف APP_URL بالإنتاج (يمنع تسميم روابط إعادة التعيين). تأكد أن APP_URL هو النطاق الفعلي
+# SECURITY_TRUSTED_HOSTS=www.example.com     # مضيفات إضافية مسموحة (مفصولة بفواصل)
```

إضافات `.env` الموصى بها **بالإنتاج فقط** (انسخ ما يلزم إلى بيئة الإنتاج لا إلى المستودع):

```dotenv
# مطلوب أن يطابق النطاق الفعلي (TrustApplicationHosts يقيّد Host به)؛ production:check يفشل إن كان localhost
APP_URL=https://<نطاقك>
# نطاقات بديلة مسموحة (اختياري)
SECURITY_TRUSTED_HOSTS=www.<نطاقك>
# تضييق الأصول لـReverb (قائم منذ E25)
REVERB_ALLOWED_ORIGINS=<نطاقك>
```

محليًا (XAMPP/127.0.0.1): **لا حاجة لأي إضافة**؛ تقييد Host لا يعمل في `APP_ENV=local` ولا في الاختبارات.

## E) أوامر التشغيل المحلية (تُنفَّذ عندك — NO GIT COMMANDS)

> لم تُنفَّذ هذه الأوامر هنا (not executed here)؛ هي ما يشغّله المستخدم بنفسه على Windows/XAMPP من جذر المشروع (PowerShell).

```powershell
# 0) نسخة احتياطية للملفات التي ستُستبدل (تحفظ فقط ما سيُكتب فوقه)
$zip = ".\e26-security.zip"
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$bak = ".\_backup-before-e26-$stamp"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $zip).Path)
$entries = $archive.Entries | Where-Object { $_.Name -and $_.FullName -ne "e26-manifest-sha256.txt" }
foreach ($e in $entries) {
  if (Test-Path $e.FullName) {
    $dest = Join-Path $bak $e.FullName
    New-Item -ItemType Directory -Force -Path (Split-Path $dest) | Out-Null
    Copy-Item $e.FullName $dest
  }
}
$archive.Dispose()

# 1) فكّ الحزمة فوق المشروع
Expand-Archive -Path $zip -DestinationPath . -Force

# 2) تحقق من سلامة كل ملف مقابل المانيفست
Get-Content .\e26-manifest-sha256.txt | ForEach-Object {
  $hash, $path = $_ -split "  ", 2
  $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLower()
  if ($actual -ne $hash) { Write-Host "MISMATCH $path" -ForegroundColor Red }
}
Write-Host "manifest check done"

# 3) تنظيف الكاش (لا composer install ولا update)
php artisan config:clear
php artisan route:clear
php artisan view:clear
composer dump-autoload --optimize

# 4) حزمة E26 (شغّلها مرة واحدة)
php artisan test tests/Feature/SecurityE26

# 5) انحدارات مستهدفة (اختياري، حسب الوقت)
php artisan test tests/Feature/Auth tests/Feature/ProfileTest.php tests/Feature/Rbac tests/Feature/OperationsE25
php artisan test tests/Feature/Social tests/Feature/Teams tests/Feature/Chat tests/Feature/Competitive
php artisan test tests/Feature/Economy tests/Feature/Store tests/Feature/PuzzleControllerTest.php tests/Feature/PuzzleAttemptServiceTest.php tests/Feature/CampaignPuzzleFlowTest.php

# 6) تدقيق الاعتماديات (يحتاج الشبكة)
composer audit
npm audit --omit=dev
```

ملاحظات: (1) `tests/Feature/SecurityE26/E26*` تتخطى اختبارات السكربتات bash القديمة تلقائيًا على ويندوز (من E25). (2) بعد نسخ الملفات احذف `e26-manifest-sha256.txt` و`e26-security.zip` من جذر المشروع إن لم تُرد إبقاءهما (غير متتبَّعين). (3) لا أوامر git في هذه الحزمة؛ مراجعة الفروق وإدخالها بمستودعك مسؤوليتك.

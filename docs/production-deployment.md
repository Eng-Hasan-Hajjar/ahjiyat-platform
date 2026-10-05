
# دليل النشر للإنتاج

توثيق محايد الاستضافة - يعمل على أي VPS Linux قياسي. ملحق Ubuntu+Nginx بالنهاية كمثال فقط.

## متطلبات الخادم (من composer.json/package.json الفعليين)

- **PHP 8.2+** (`"php": "^8.2"`).
- **Extensions**: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath` (متطلبات Laravel 12 القياسية - لا استخدام GD/Imagick بهذا المشروع حاليًا).
- **MySQL 8.0+** (أو MariaDB 10.6+) بترميز `utf8mb4`.
- **Node.js 18+** (فقط أثناء البناء - Vite 6).
- **Composer 2.x**. **SSL** فعلي.

## متغيرات البيئة الأساسية للإنتاج



APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-domain.com
LOG_STACK=daily
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=database
CACHE_STORE=file
FILESYSTEM_DISK=public
MAIL_MAILER=smtp

## Document Root

جذر الموقع بإعدادات الويب سيرفر يجب أن يشير إلى **`/public` فقط**. هذا وحده يمنع خدمة `.env`، `.git`، `composer.json`، سجلات مباشرة كملفات عامة.

## أذونات الملفات

```bash
chown -R your-deploy-user:www-data /path/to/project
find /path/to/project -type f -exec chmod 644 {} \;
find /path/to/project -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
```

لا `chmod -R 777` إطلاقًا.

## ترتيب النشر الآمن

1. **نسخة احتياطية** - قبل أي شيء آخر.
2. `git pull`
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build`
5. (اختياري) `php artisan down --secret="<قيمة-وقتية>"`
6. `php artisan migrate --force`
7. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
8. تحقّق `public/storage` (Symlink حقيقي، **لا Windows Junction بالإنتاج**)
9. `php artisan queue:restart`
10. تحقّق Cron
11. `php artisan production:check`
12. `php artisan up`
13. **اختبارات دخان يدوية**

## `production:check`

```bash
php artisan production:check
```

Read-Only بالكامل - لا يُعدِّل شيئًا، لا يطبع أي قيمة سرّية. Exit Code صفر = لا مشاكل حرجة.

## الطوابير (Cron/Supervisor)

`QUEUE_CONNECTION=database` كافٍ حاليًا - لا Job فعلية بـ`ShouldQueue` بعد.

[program:ahjiyat-worker]
command=php /path/to/project/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
user=your-deploy-user

## الجدولة

cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
المهمة الوحيدة المجدولة حاليًا محمية بـ`withoutOverlapping()` أصلًا.

## Robots/SEO

`/admin` وكل صفحات المصادقة يجب أن تحمل `noindex` - راجع أن `<meta name="robots">` يعكس `seo.indexing_enabled` بشكل صحيح قبل الإطلاق العام.

---

## ملحق: مثال Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /path/to/project/public;

    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

## الجدولة بعد E15 (إشعارات العودة)
بالإضافة لمهمة تحرير الجواهر المعلَّقة، يجدوِل `routes/console.php`:
`notifications:dispatch-reengagement` (ساعيًا) و`notifications:prune` (يوميًا). كلاهما يعمل بنفس مدخل Cron الوحيد
(`php artisan schedule:run` كل دقيقة). غيابه لا يكسر المنصة - لا تصل تذكيرات العودة فقط. التفاصيل: `docs/notifications.md`.
بعد النشر: `php artisan migrate` ثم `php artisan permissions:sync` ثم `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"`
(ليحصل `administrator` على `notifications.settings.manage`؛ المدير الأعلى يتجاوز الصلاحيات تلقائيًا).

## بدء المواسم (E15+)
- `php artisan migrate` يضيف `seasons.went_live_at` ويعبّئه رجعيًا **بلا إشعارات** للمواسم التي بدأت قبل الترحيل، ويضيف `notification_preferences.season_enabled`.
- المجدوِل (Cron الواحد `schedule:run`) يشغّل `seasons:sync-live-state` كل 5 دقائق لالتقاط بدء المواسم بمرور الوقت.
- توزيع إشعارات «بدأ الموسم» يمرّ بالصف: مع `QUEUE_CONNECTION=database` شغّل عاملًا (`php artisan queue:work`)؛ بدونه تبقى الـJobs بالانتظار ولا تضيع. اللعب وتسجيل بدء الموسم لا يعتمدان على الصف.

## إتاحة الحملات (E15+)
- `php artisan migrate` يضيف `campaigns.became_available_at` ويعبّئه رجعيًا **بلا إشعارات** للحملات المتاحة قبل الترحيل، ويضيف `notification_preferences.campaign_enabled`.
- المجدوِل (Cron الواحد `schedule:run`) يشغّل `campaigns:sync-availability` كل 5 دقائق لالتقاط إتاحة الحملات بمرور الوقت.
- توزيع إشعارات «حملة متاحة» يمرّ بالصف كـ«بدء الموسم»: مع `QUEUE_CONNECTION=database` شغّل عاملًا (`php artisan queue:work`)؛ بدونه تبقى الـJobs بالانتظار ولا تضيع. اللعب وتسجيل الإتاحة لا يعتمدان على الصف.

## فتح المراحل للمستخدم (E15+)
- `php artisan migrate` ينشئ `user_stage_unlocks` (سجل أول فتح، UNIQUE على user + stage).
- **مرة واحدة فقط، مباشرة بعد `migrate` عند أول نشر لهذه الميزة وقبل الاعتماد على إشعارات `stage_unlocked`:**
  `php artisan campaigns:backfill-stage-unlocks` (تعبئة رجعية **صامتة**: بلا أحداث ولا إشعارات، Idempotent، دفعات `--chunk=200`).
- الإشعار نفسه صف متزامن خفيف: لا يحتاج صفًا ولا مجدولًا. والتقدّم لا يتأثر بفشل الإشعار أو بفشل تسجيل الفتح.
- حماية الفترة بين `migrate` والتعبئة: لا إعلان عن إعادة إكمال أو إكمال قديم (يُسجَّل الفتح بصمت).

## إكمال الحملات للمستخدم (E15+)
- `php artisan migrate` ينشئ `user_campaign_completions` (سجل أول إكمال، UNIQUE على user + campaign).
- **مرة واحدة فقط، مباشرة بعد `migrate` عند أول نشر لهذه الميزة وقبل الاعتماد على إشعارات `campaign_completed`:**
  `php artisan campaigns:backfill-completions` (تعبئة رجعية **صامتة**: بلا أحداث ولا إشعارات، Idempotent، دفعات `--chunk=200`).
  يُشغَّل بجانب `campaigns:backfill-stage-unlocks` (الترتيب بينهما غير مهم).
- الإشعار صف متزامن خفيف: لا يحتاج صفًا ولا مجدولًا، والتقدّم لا يتأثر بفشل الإشعار أو بفشل تسجيل الإكمال.


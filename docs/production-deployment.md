
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

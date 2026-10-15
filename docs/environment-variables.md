# متغيرات البيئة: التصنيف وعقد الإنتاج (E25)

> المرجع الوحيد لكل متغير بيئة يقرؤه التطبيق، مصنَّفًا إلى **REQUIRED / SECRET / OPTIONAL / INFRASTRUCTURE-SPECIFIC**.
> يفحص هذا العقد الأمرُ `php artisan production:check` (قراءة فقط، يعطي `PASS/WARN/FAIL` + **اسم** الإعداد ولا يطبع أي قيمة سرية أبدًا).
> القيم الحقيقية لا توجد بأي ملف بالمستودع: `.env.example` يحوي placeholders فقط.

## قواعد عامة

1. **لا أسرار في Git ولا في الوثائق ولا في CI.** `.env` ملف خارج المستودع (مُستبعَد بـ`.gitignore`). أسرار الإنتاج: مدير أسرار المزوّد، أو متغيرات بيئة محميّة على الخادم، أو ملف `.env` بصلاحيات `640` ومالك مستخدم التطبيق فقط.
2. **`config:cache` يجمّد القيم.** الإنتاج يشغّل `php artisan optimize`، وبعدها لا يقرأ التطبيق `.env` إطلاقًا: أي تغيير بالبيئة **لا يسري** قبل إعادة `php artisan optimize` ثم `php artisan queue:restart` (العمال طويلو العمر) و`php artisan reverb:restart`.
3. **متغيرات `VITE_*` عامة بالتصميم**: تُدمَج داخل حزمة جافاسكربت وقت `npm run build` ويراها كل زائر. لا يوضع فيها أي سر إطلاقًا (`VITE_REVERB_APP_KEY` هو معرّف عام لا سر).
4. **التدوير**: كل سر له خطوات تدوير بـ[التحصين الأمني](security-hardening.md#تدوير-الأسرار-p4).

## 1) REQUIRED: لا يعمل الإنتاج الصحيح بدونها

| المتغير | القيمة بالإنتاج | ملاحظة |
|---|---|---|
| `APP_ENV` | `production` | تفعّل HSTS وتشدّد `production:check` (FAIL بدل WARN) وتمنع الأوامر الهدّامة |
| `APP_DEBUG` | `false` | **FAIL حاجب دائمًا** إن كانت true (تكشف Stack Traces والإعدادات) |
| `APP_URL` | `https://<نطاقك>` | يُفحص: https، ليس مضيفًا محليًا |
| `APP_KEY` | يولَّد مرة واحدة (`php artisan key:generate --show`) | **SECRET**. انظر [تحذير APP_KEY](security-hardening.md#app_key-تحذير-خاص) |
| `DB_CONNECTION` | `mysql` (أو `pgsql`) | **sqlite مرفوض بالإنتاج** (FAIL) |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` | بنية تحتية | مستخدم بصلاحيات التطبيق فقط (ليس root) |
| `SESSION_DRIVER` | `database` أو `redis` | `array`/`null` مرفوضان؛ `file` لخادم واحد فقط |
| `SESSION_SECURE_COOKIE` | `true` | **FAIL** بالإنتاج إن لم تكن true |
| `CACHE_STORE` | `redis` أو `database` | `array`/`null` مرفوضان؛ `file` لخادم واحد فقط؛ يلزم دعم الأقفال (المجدول) |
| `QUEUE_CONNECTION` | `database` أو `redis` | `sync` تحذير، `null` مرفوض. يلزم عامل `queue:work` دائم |
| `MAIL_MAILER` + `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_FROM_ADDRESS` | `smtp` وبيانات مزوّدك | `log`/`array` مرفوضان بالإنتاج (توثيق البريد/استرجاع كلمة السر شرط الاستبدال) |
| `BROADCAST_CONNECTION` | `reverb` | وإلا لا تحديث لحظي للدردشة (الحفظ يعمل) |
| `REVERB_APP_ID` `REVERB_APP_KEY` `REVERB_HOST` `REVERB_PORT` `REVERB_SCHEME` | `https` للمخطط | `REVERB_HOST` النطاق العام لخادم Reverb |
| `LOG_STACK` | `daily` | `single` بلا تدوير يملأ القرص |
| `LOG_LEVEL` | `info` أو `warning` | `debug` بالإنتاج ضجيج وخطر تسجيل زائد |

## 2) SECRET: لا تُطبع ولا تُسجَّل ولا تُخزَّن بالمستودع

| المتغير | أين يُستعمل | تدويره |
|---|---|---|
| `APP_KEY` | التشفير، الجلسات المشفّرة، الكوكيز، الروابط الموقَّعة | **ليس تدويرًا عاديًا**: [تحذير خاص](security-hardening.md#app_key-تحذير-خاص) |
| `APP_PREVIOUS_KEYS` | قراءة بيانات مشفرة بمفتاح سابق أثناء التدوير | يُزال بعد انتهاء الفترة |
| `DB_PASSWORD` | اتصال القاعدة | [تدوير كلمة مرور القاعدة](security-hardening.md#تدوير-الأسرار-p4) |
| `REDIS_PASSWORD` | Redis | بنفس الطريقة |
| `REVERB_APP_SECRET` | توقيع البثّ والمصادقة | بنفس الطريقة (+ إعادة بناء الأصول لا يلزم: السر لا يصل المتصفح) |
| `MAIL_PASSWORD` (أو `POSTMARK_TOKEN`/`RESEND_KEY`) | إرسال البريد | عند المزوّد ثم تحديث البيئة |
| `AWS_SECRET_ACCESS_KEY` | قرص S3 (إن استُعمل) | عند المزوّد |
| `SLACK_BOT_USER_OAUTH_TOKEN` / `LOG_SLACK_WEBHOOK_URL` | تنبيهات (إن استُعملت) | عند Slack |
| `MEMCACHED_PASSWORD` | Memcached (إن استُعمل) | عند المزوّد |
| `PERF_READINESS_TOKEN` | تقييد `/ready` برأس `X-Health-Token` | ولّد قيمة عشوائية جديدة وحدّث الموازن |
| `ADMIN_BOOTSTRAP_PASSWORD` | أول مدير فقط | **مؤقت**: يُحذف بعد أول تشغيل (انظر [أول نشر](first-production-deploy.md)) |
| `MAINTENANCE_SECRET` | سر دخول وضع الصيانة (يقرؤه `tools/deploy/deploy.sh` فقط) | قيمة جديدة لكل مرة، لا تُحفظ ولا تُسجَّل |
| `BACKUP_*` مفاتيح التشفير / `RESTORE_AGE_IDENTITY` | نسخ احتياطي واستعادة | مفتاح الاستعادة الخاص **لا يبقى على الخادم** |

> **معرّف عام لا سر**: `REVERB_APP_KEY` (يصل المتصفح عبر `VITE_REVERB_APP_KEY`)، `ADSENSE_CLIENT_ID`. لا تعاملهما كسر لكن لا تضعهما بالتذاكر العامة بلا داعٍ.

## 3) OPTIONAL: لها افتراضات آمنة، لا تضبطها إلا لسبب

| المتغير | الافتراضي | الغرض |
|---|---|---|
| `APP_RELEASE` / `RELEASE_SHA` | غير مضبوط | معرّف الإصدار (يظهر في `/ready` والسجلات ومركز العمليات). صيغة `[A-Za-z0-9._-]` حتى 64 خانة وإلا يُتجاهَل |
| `TRUSTED_PROXIES` | فارغ (لا وكلاء) | عناوين/CIDR الموازن. `*` فقط إن لم يُستقبَل الطلب إلا من الموازن |
| `REVERB_ALLOWED_ORIGINS` | `*` | أسماء مضيفين مسموحة لاتصالات WebSocket (بلا `http://`) |
| `PERF_*` | انظر [performance-e24](performance-e24.md) | تحسينات الأداء والمراقبة (E24) |
| `OPS_SCHEDULER_STALE_SECONDS` / `OPS_SCHEDULER_DOWN_SECONDS` | 300 / 900 | عتبات نبض المجدول |
| `OPS_READY_FAILS_ON_SCHEDULER` | `false` | `true`: `/ready` يفشل فقط عند حالة down (لا عند التأخر البسيط ولا بعد نشر جديد) |
| `OPS_QUEUE_BACKLOG_WARN_SECONDS` / `OPS_FAILED_JOBS_WARN` | 900 / 1 | عتبات تنبيه الطابور (مركز العمليات وproduction:check) |
| `OPS_ALLOW_DESTRUCTIVE_COMMANDS` | `false` | استثناء واعٍ **لعملية واحدة** من حظر `migrate:rollback/fresh` وغيرها بالإنتاج. لا يوضع بـ`.env` الدائم |
| `LOG_DAILY_DAYS` | 14 | أيام الاحتفاظ بقناة `daily` |
| `GEMS_*`, `PROGRESSION_*` | انظر `config/gems.php` | منطق المنتج: **لا تغيّرها ضمن عملية تشغيل** (قرار منتج) |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | 90 | يجب أن يبقى **أكبر من 80** (أقصى timeout لأي Job) وإلا تُنفَّذ المهمة مرتين |
| `SESSION_SAME_SITE` / `SESSION_HTTP_ONLY` / `SESSION_LIFETIME` | `lax` / `true` / 120 | الكوكي: لا تعطّل HttpOnly |

## 4) INFRASTRUCTURE-SPECIFIC: تعتمد على بنيتك/مزوّدك

| المتغيرات | تعتمد على |
|---|---|
| `DB_HOST` `DB_PORT` `DB_SOCKET` `DB_URL` `MYSQL_ATTR_SSL_CA` `DB_CHARSET` `DB_COLLATION` | خادم القاعدة وTLS |
| `REDIS_CLIENT` `REDIS_HOST` `REDIS_PORT` `REDIS_USERNAME` `REDIS_DB` `REDIS_CACHE_DB` `REDIS_URL` `REDIS_PREFIX` `REDIS_CLUSTER` | خادم Redis (انظر [دليل التشغيل](operations-runbook.md#redis)) |
| `FILESYSTEM_DISK` `AWS_*` | قرص الملفات (محلي أم S3-متوافق) |
| `TRUSTED_PROXIES` | الموازن/Cloudflare |
| `REVERB_SERVER_HOST` `REVERB_SERVER_PORT` `REVERB_SCALING_ENABLED` `REVERB_APP_MAX_CONNECTIONS` ... | طوبولوجيا خادم Reverb |
| `MAIL_SCHEME` `MAIL_EHLO_DOMAIN` `MAIL_SENDMAIL_PATH` | مزوّد البريد |
| `WKHTML_PDF_BINARY` | مسار wkhtmltopdf على الخادم (تصدير PDF) |
| `LOG_STDERR_FORMATTER` `LOG_SYSLOG_FACILITY` `PAPERTRAIL_*` | منصة السجلات المركزية (P2) |

## 5) متغيرات تشغيل السكربتات (ليست بيئة التطبيق)

لا تُوضع في `.env` التطبيق؛ تُمرَّر لحظة تشغيل السكربت: `BACKUP_DIR`، `BACKUP_AGE_RECIPIENT`/`BACKUP_GPG_RECIPIENT`، `BACKUP_UPLOAD_CMD`، `BACKUP_DB_DEFAULTS_FILE`، `RESTORE_DB_*`، `RESTORE_CONFIRM_DATABASE`، `DEPLOY_RELOAD_CMD`، `DEPLOY_SMOKE_URL`، `DEPLOY_BACKUP_CONFIRMED`. انظر [النسخ الاحتياطي](backup-disaster-recovery.md) و[هندسة الإصدارات](release-engineering.md).

## 6) الحد الأدنى النموذجي للإنتاج (placeholders فقط)

```dotenv
APP_NAME=Ahjiyat
APP_ENV=production
APP_KEY=                       # php artisan key:generate --show (مرة واحدة، يُحفظ بخزنة أسرار)
APP_DEBUG=false
APP_URL=https://ahjiyat.example.com
APP_RELEASE=                   # يُضبط عند كل نشر

LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=                   # SECRET

SESSION_DRIVER=database        # أو redis
SESSION_SECURE_COOKIE=true
CACHE_STORE=database           # أو redis
QUEUE_CONNECTION=database      # أو redis

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=                 # SECRET
MAIL_FROM_ADDRESS=no-reply@ahjiyat.example.com

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=             # SECRET (≥ 32 خانة عشوائية)
REVERB_HOST=ws.ahjiyat.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=ahjiyat.example.com
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

TRUSTED_PROXIES=               # CIDR الموازن إن وُجد
```

ثم: `php artisan production:check` (يجب Exit 0) قبل أي نشر.

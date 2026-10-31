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
| `DB_ENGINE` / `DB_TIMEZONE` | `InnoDB` / `+00:00` | محرك جداول MySQL ومنطقة جلسة القاعدة (E31؛ يجب أن تطابق UTC) |
| `OPS_ALLOW_SQLITE_PRODUCTION` | `false` | `true`: يحوّل فشل `production:check` على SQLite إلى تحذير. **غير مستحسن**: MySQL 8.0.16+ هي القاعدة المعتمدة ([E31](mysql-production-e31.md)) |
| `MIGRATION_TARGET_CONNECTION` / `MIGRATION_SQLITE_DATABASE` / `MIGRATION_BATCH_SIZE` / `MIGRATION_MAX_BATCH_BYTES` | `mysql` / — / 1000 / 1000000 | أداة نقل SQLite→MySQL لمرة واحدة (محلية فقط، ترفض الإنتاج) |
| `MIGRATION_MYSQL_HOST` / `_PORT` / `_DATABASE` / `_USERNAME` / `_PASSWORD` | — | تجاوزات هدف النقل (كلمة المرور **SECRET** من البيئة فقط ولا تُطبع) |
| `PAYMENTS_ENABLED` / `PAYMENTS_DEFAULT_PROVIDER` / `PAYMENTS_MANUAL_ENABLED` | `true` / `manual` / `true` | دفع الرعاية (E32): مفتاح إيقاف شامل، المزوّد الافتراضي، وتفعيل التحويل اليدوي بالنشر ([E32](sponsorship-payments-e32.md)). لا أسرار: تفاصيل التحويل العلنية من إعدادات الإدارة |
| `PAYMENTS_ALLOWED_CURRENCIES` / `PAYMENTS_MIN_AMOUNT_MINOR` / `PAYMENTS_MAX_AMOUNT_MINOR` / `PAYMENTS_ALLOW_CUSTOM_AMOUNT` / `PAYMENTS_MAX_OPEN_REQUESTS` | `USD,EUR,TRY` / 100 / — / `true` / 5 | حدود الطلب (عملات تُقاطَع مع الرعاية، أدنى/أعلى مبلغ بالوحدة الصغرى، المبالغ المقترحة فقط، سقف الطلبات المفتوحة لكل مستخدم) |
| `PAYMENTS_REQUEST_EXPIRY_HOURS` | 48 | مهلة «بانتظار الدفع» قبل الانتهاء الآلي (`payments:expire-stale` كل 15 دقيقة). المرسَل/المحقَّق لا ينتهي أبدًا |
| `PAYMENTS_PROOF_DISK` / `PAYMENTS_PROOF_MAX_KB` | `local` / 5120 | إثبات التحويل: **قرص خاص إلزامي** (يُرفض `public`)، JPEG/PNG/WEBP/PDF فقط |
| `PAYMENTS_PROOF_RETENTION_DAYS` / `PAYMENTS_DISPUTE_WINDOW_DAYS` | 365 / 90 | تقليم ملفات الإثبات (`payments:prune-proofs --dry-run` أولًا) بعد الحالة النهائية وخارج نافذة النزاع؛ سجل الطلب لا يُحذف |
| `PAYMENTS_WEBHOOKS_ENABLED` | `false` | مسار Webhook المستقبلي مغلق افتراضيًا؛ لا مزوّد إلكتروني منفَّذ بعد (يتطلب مزوّدًا يتحقق من توقيعه) |
| `PAYMENTS_NOTIFICATIONS_ENABLED` | `true` | إشعارات صاحب الطلب (استلام/توضيح/رفض/استرداد) |
| `COMMERCE_ENABLED` / `COMMERCE_DEFAULT_PROVIDER` / `COMMERCE_FALLBACK_PROVIDER` / `COMMERCE_ALLOWED_CURRENCIES` | `true` / `manual` / `manual` / `USD,EUR,TRY` | الدفع الإلكتروني للمشتريات الرقمية (E41): مفتاح إيقاف شامل لبدء checkout المستضاف، المزوّد الافتراضي والبديل، والعملات المسموحة (بلا تحويل). **لا مزوّد حيّ منفَّذ**: المسجَّلان `manual` و`sandbox` ([E41](consumer-commerce-e41.md)). أسرار أي مزوّد حيّ مستقبلي من بيئة الخادم/مدير الأسرار فقط ولا تُوثَّق قيمها هنا |
| `COMMERCE_WEBHOOKS_ENABLED` / `COMMERCE_WEBHOOK_MAX_BODY_BYTES` / `COMMERCE_WEBHOOK_REPLAY_TOLERANCE` / `COMMERCE_WEBHOOK_RATE_PER_MINUTE` | فارغ (local/testing فقط) / 65536 / 300 / 600 | استقبال webhook التجارة (`POST /api/commerce/webhooks/{provider}`): مغلق افتراضيًا خارج التطوير، أقصى حجم للجسم، نافذة تحمّل الطابع الزمني للتوقيع (ثوانٍ)، وحد المعدّل. التوقيع هو المصادقة ولا يُعطَّل أبدًا |
| `COMMERCE_SESSION_EXPIRY_MINUTES` / `COMMERCE_PURCHASE_EXPIRY_HOURS` / `COMMERCE_RECONCILE_MAX_FINDINGS` / `COMMERCE_NOTIFICATIONS_ENABLED` | 60 / 24 / 200 / `true` | عمر جلسة الدفع عند المزوّد، عمر الطلب «بانتظار الدفع» المستضاف، سقف نتائج `commerce:reconcile`، وإشعارات التجارة |
| `COMMERCE_SANDBOX_ENABLED` / `COMMERCE_SANDBOX_ACCEPT_WEBHOOKS` / `COMMERCE_SANDBOX_REFUND_MODE` | فارغ / `true` / `succeeded` | المزوّد التجريبي (محاكاة): **يُرفض في الإنتاج بالكود** مهما كانت القيم، ويفشل `production:check` إن كان مفعَّلًا أو افتراضيًا هناك. `REFUND_MODE`: `succeeded` \| `pending` \| `failed` (اختبار حتمي) |
| `CREATOR_PREMIUM_ENABLED` / `CREATOR_PREMIUM_CREATOR_SHARE_BPS` / `CREATOR_PREMIUM_MIN_PRICE_MINOR` / `CREATOR_PREMIUM_MAX_PRICE_MINOR` | `false` / 7000 / 100 / 100000 | سوق المبدعين المميز (E43): مفتاح الإيقاف (الافتراضي مُعطَّل حتى يتوفر مزوّد دفع استهلاكي حيّ؛ مُعطَّلًا لا تفعيل عرض ولا checkout جديد ويبقى السجل والوصول القائم والاسترداد والمصالحة)، حصة المبدع بنقاط الأساس (حصة المنصة = 10000 − الحصة؛ ⌊الإجمالي × النقاط ÷ 10000⌋ للمبدع والباقي للمنصة)، وحدّا السعر بالوحدة الصغرى. لا أسرار ولا تحويل عملات ([E43](creator-premium-marketplace-e43.md)) |
| `SEASON_WRITERS_ENABLED` / `SEASON_SUPPORTERS_ENABLED` | `false` / `false` | مفتاحا التقدير الموسمي (E44) المستقلان: **دوري كتّاب الموسم** و**تقدير الداعمين**. مُعطَّلين لا يبدأ تصويت ولا يُضاف كاتب ولا تُمنح نقاط دعم جديدة ولا تُطلب بطاقة جديدة، ويبقى كل التاريخ (قوائم/أصوات/ترتيب نهائي/بطاقات/دفاتر) محفوظًا ومقروءًا. الفعّال = المفتاح العام ∧ مفتاح الموسم. لا أسرار ولا روابط خارجية ([E44](seasonal-writers-supporters-e44.md)) |
| `TATAWWUR_AI_ENABLED` / `TATAWWUR_AI_PROVIDER_ENABLED` / `TATAWWUR_AI_GUESTS_ENABLED` / `TATAWWUR_AI_STREAMING_ENABLED` | `false` ×4 | أعلام تطوّر AI (E45) المستقلة وكلها مغلقة افتراضيًا: الشامل (لا زر ولا مسارات)، المزوّد الخارجي (مغلقًا يعمل الاسترجاع المحلي فقط)، الضيوف، والبث SSE (لا يُفعَّل قبل ضبط Nginx/FPM). تعطيل أي منها لا يمسّ بقية المنصة ولا يمحو بيانات ([E45](tatawwur-ai-e45.md)) |
| `AI_PROVIDER` / `AI_MODEL` | `openai` / *(فارغ)* | اختيار المزوّد بالاسم؛ **اسم النموذج بلا قيمة افتراضية بالكود**: فارغ = المزوّد غير مهيّأ ويعمل الاسترجاع المحلي. المزوّد الاختباري `fake` مرفوض خارج local/testing |
| `OPENAI_API_KEY` | *(فارغ)* | **SECRET**. مفتاح المزوّد من بيئة الخادم أو مدير الأسرار فقط؛ لا يُكتب في `.env.example` ولا في قاعدة البيانات ولا Filament ولا السجلات، ويُرسَل فقط إلى المضيف الثابت المسموح (`api.openai.com`)، مع `store=false` |
| `TATAWWUR_AI_MAX_INPUT_CHARS` / `_MAX_OUTPUT_TOKENS` / `_MAX_TOOL_CALLS` / `_CONTEXT_CHUNKS` / `_HISTORY_TURNS` | 600 / 900 / 5 / 6 / 6 | حدود المحتوى والكلفة (أعداد صحيحة مقصوصة لسقوف صلبة؛ قيمة خاطئة لا تفتح بابًا) |
| `TATAWWUR_AI_REQUEST_TIMEOUT` / `_CONNECT_TIMEOUT` / `_RETRIES` | 25 / 5 / 1 | مهلة الطلب والاتصال بالثواني، وإعادة محاولة واحدة لأخطاء الاتصال فقط (لا إعادة لتوليد استُلم ردّه) |
| `TATAWWUR_AI_USER_PER_MINUTE` / `_USER_PER_DAY` / `_GUEST_PER_MINUTE` / `_GUEST_PER_DAY` / `_BURST` | 10 / 120 / 5 / 25 / 3 | محدِّدات معدّل الرسائل (JSON 429 برسالة عربية ثابتة) |
| `TATAWWUR_AI_GLOBAL_DAILY_TOKEN_LIMIT` | 2000000 (الفارغ = الافتراضي) | ميزانية الرموز اليومية العامة؛ تجاوزها = «تطوّر مشغول حاليًا» بلا استدعاء مزوّد |
| `TATAWWUR_AI_RETENTION_DAYS` / `_METRICS_RETENTION_DAYS` / `_FEEDBACK_RETENTION_DAYS` | 30 / 90 / 180 | الاحتفاظ بمحادثات الأعضاء (منزلق من آخر رسالة) والمقاييس (بلا محتوى) والتقييمات؛ أمر `ai:conversations:prune` اليومي يحذف المنتهي |
| لا متغيّرات لتطوّر الموسم | — | تطوّر الموسم (E42) **لا يضيف أي متغير بيئة**: كل الحدود والقوائم البيضاء في `config/seasons.php` ثوابت بلا `env()`، والمجدول (`seasons:process`) لا يحتاج إعدادًا. إيقاف طارئ لموسم = «إيقاف النشر» من الإدارة ([E42](season-evolution-e42.md)) |

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

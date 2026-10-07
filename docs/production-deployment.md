
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

## حزمة دورة الحياة: إكمال الموسم + ينتهي قريبًا (E15+)
- **لا ترحيل جديد.** الأمر `notifications:dispatch-lifecycle-reminders` يُجدوَل **ساعيًا تلقائيًا** (Cron الواحد `schedule:run`)، فلا خطوة يدوية.
- نافذة التذكير 24 ساعة قبل `campaign.ends_at`: `config/player_notifications.php` (`ending_soon_window_hours`).
- استثناء من أكمل الحملة يعتمد على `user_campaign_completions`: تأكد أنك شغّلت `campaigns:backfill-completions` مرة واحدة بعد `migrate` (القسم السابق) قبل الاعتماد على التذكيرات.
- بغياب المجدوِل لا تصل تذكيرات "ينتهي قريبًا" فقط؛ إشعار إكمال الموسم/الحملة لا يعتمد عليه.

## الأصدقاء والحظر (E16)
- `php artisan migrate` ينشئ `friendships` و`user_blocks` ويضيف `users.friend_requests_enabled` و`notification_preferences.social_enabled` (الافتراضي مفعَّل لكل الحسابات القائمة). **لا تعبئة رجعية ولا أمر يدوي.**
- `npm run build` مطلوب لظهور تنسيق الصفحات الجديدة (Blade جديدة). لا مجدول جديد.
- تحديد المعدّل بـ`AppServiceProvider` (`friend-requests`، `friend-actions`، `player-search`) والـcooldown بالكاش: تأكد أن مخزن الكاش مشترك بين العمال (database/redis) في الإنتاج.
- الإعدادات: `config/friends.php` (cooldown، حد البحث، أحجام الصفحات).

## المنافسات وتحدّيات الأصدقاء (E17)
- `php artisan migrate` ينشئ `friend_challenges` و`friend_challenge_results` و`competitive_events` و`competitive_event_participants` و`competitive_event_results` ويضيف `notification_preferences.competitive_enabled` (مفعَّل افتراضيًا). **لا تعبئة رجعية ولا أمر يدوي.**
- `php artisan permissions:sync` ثم `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"` (التسلسل المعتاد أعلاه) لإنشاء مجموعة `competitive_events.*` (view, create, update, delete, publish, cancel, finalize) ومنحها لدور `administrator`؛ بدون إعادة تشغيل الـSeeder لا يرى المدير مورد المنافسات.
- **مجدول جديد:** `competitive:process-lifecycle` ساعيًا (مسجَّل بـ`routes/console.php`). بدون المجدول: لا إشعارات بدء/قرب نهاية، ولا اعتماد تلقائي للنتائج (يبقى زر "اعتماد النتائج" بلوحة الإدارة)، ولا تجسيد لانتهاء التحديات (يبقى مشتقًا من الوقت).
- `npm run build` مطلوب لتنسيق الصفحات الجديدة. الإعدادات في `config/competitive.php`.
- محدّدات المعدّل (`AppServiceProvider`): `friend-challenges`، `competitive-register`، `competitive-play`؛ الكاش مشترك بين العمال في الإنتاج.
- أحجية الحدث: بلا تلميح، إجابة منفردة (نصي/ذاكرة/تسلسل)؛ يُنصح بأحجية مخصّصة (يمكن إبقاؤها غير مفعَّلة في الفهرس العام).

## جوائز المنافسات وقاعة الأمجاد (E18)
- `php artisan migrate` ينشئ `competitive_reward_rules` و`competitive_reward_grants`. **لا تعبئة رجعية ولا أمر يدوي**، ولا جوائز لأحداث اعتُمدت قبل E18.
- `php artisan permissions:sync` ثم `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"`: **4 صلاحيات جديدة** (`competitive_events.rewards.view|manage|retry` و`analytics.competitive`) وتُمنح لـ`administrator`.
- **الطابور مطلوب بالإنتاج:** التوزيع وتقييم الإنجازات وإشعارات النتائج وظائف مجزَّأة (`QUEUE_CONNECTION=database` جُرِّب فعليًا بعامل حقيقي). شغّل `php artisan queue:work` (يفضَّل تحت Supervisor). بدون عامل لا توزَّع الجوائز (تبقى الوظائف بجدول `jobs` ولا يضيع شيء) ويمكن تشغيله لاحقًا فتُوزَّع بلا ازدواج.
- المجدول `competitive:process-lifecycle` (E17) ما زال ساعيًا: يعتمد النتائج فيطلق التوزيع.
- `npm run build` لتنسيق الصفحات الجديدة (قاعدة الأمجاد، سجل اللاعب).
- (اختياري) `php artisan db:seed --class=CompetitiveAchievementsSeeder` لإنشاء إنجازات المنافسة الافتراضية بلا مكافآت.
- العناصر جوائز تجميلية (شارات/ألقاب) يُفضَّل إنشاؤها بالمتجر **بلا سعر وغير مفعَّلة** (مخفية من الكتالوج).
- مراقبة: بطاقة "جوائز تنافسية فاشلة" بمركز العمليات، وإجراء "إعادة محاولة الجوائز الفاشلة" بقائمة المنافسات.

## الفرق (E19)
- `php artisan migrate`: ستة ترحيلات جديدة (`teams`، `team_memberships`، `team_invitations`، `team_join_requests`، عمود `team_id_snapshot` بالمشاركين، `competitive_event_team_results` وعلامة `team_rankings_finalized_at`). **لا Backfill**: من سجّل بالمنافسات قبل E19 بلا لقطة فريق (NULL) ولا يدخل ترتيب أي فريق.
- `php artisan permissions:sync` ثم `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"`: **3 صلاحيات جديدة** (`teams.view`، `teams.manage`، `teams.deactivate`) تُمنح لـ`administrator`. (قسم الفرق بالتحليلات يستعمل صلاحية `analytics.competitive` القائمة.)
- **المجدول:** `teams:process-lifecycle` ساعيًا (انتهاء الدعوات، إصلاح عدّادات الأعضاء، وشبكة أمان لترتيب الفرق). بدونه لا ينكسر شيء: تبقى الدعوات المنتهية "معلّقة" بالعرض لكن لا تُقبل، وتتأخر عدّادات الأعضاء بعد حذف مستخدم، ويُخزَّن ترتيب الفرق من الوظيفة الاعتيادية فقط.
- **الطابور:** وظيفة `ComputeTeamRankings` تنطلق عند اعتماد حدث (جُرِّبت فعليًا على `QUEUE_CONNECTION=database` بعامل حقيقي). شغّل `php artisan queue:work` (يفضَّل تحت Supervisor).
- `npm run build` لتنسيق صفحات الفرق.
- **حذف حساب:** إجراء حذف المستخدم بالإدارة يعالج الفرق التي يملكها تلقائيًا (نقل الملكية أو الأرشفة).
- مراقبة: قسم "الفرق" بتبويب المنافسات بالتحليلات، ومورد "الفرق" بلوحة الإدارة لتعطيل فريق أو نقل ملكيته (مثلًا مالك مجمَّد).

## تحدّيات الفرق وبطولاتها (E20)
- `php artisan migrate`: ستة ترحيلات جديدة (`team_challenges`، `team_challenge_participants`، `team_challenge_results`، `team_championships`، `team_championship_events`، `team_championship_results`). **لا Backfill ولا بطولات رجعية**: لا يُنشأ شيء تلقائيًا من التاريخ.
- `php artisan permissions:sync` ثم `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"`: **4 صلاحيات جديدة** (`team_championships.view|manage|publish`، `team_challenges.view`) تُمنح لـ`administrator` (الإجمالي 113).
- **المجدول:** لا مجدول جديد. `teams:process-lifecycle` (ساعيًا، من E19) صار يتولى أيضًا انتهاء التحدّيات واعتماد المباريات المتأخرة وبدء/اعتماد البطولات. بدونه لا ينكسر شيء لكن لا تُجسَّد الانتهاءات ولا تُعتمد النتائج المتأخرة (وتظهر ببطاقة «منافسات فرق متأخرة الاعتماد» بمركز العمليات).
- **الطابور:** إشعارات البطولات (`DispatchTeamChampionshipNotificationsChunk`) تُوزَّع بالطابور؛ جُرِّبت فعليًا على `QUEUE_CONNECTION=database` بعامل حقيقي. شغّل `php artisan queue:work` (يفضَّل تحت Supervisor). إشعارات التحدّيات متزامنة بعد commit.
- `npm run build` **ضروري** (صفحات وأصناف Tailwind جديدة).
- **معرّف محجوز:** `challenges` صار محجوزًا بين معرّفات الفرق (يتعارض مع `/teams/challenges`). إن كان عندكم فريق بهذا المعرّف تعذّر الوصول لصفحته المباشرة؛ غيّروا معرّفه أو اسمه.
- **تشغيل البطولة:** من لوحة الإدارة: أنشئ مسودة ← اربط أحداثًا **متوافقة** (منشورة، أو معتمَدة بترتيب فرق) ← انشر (يقفل المواعيد ولقطة النقاط) ← بعد النهاية واعتماد كل أحداثها يُعتمد الترتيب تلقائيًا (أو يدويًا بإجراء «اعتماد النتائج»).
- مراقبة: قسم الفرق بتحليلات تبويب المنافسات (تحدّيات وبطولات)، وموردا «تحدّيات الفرق» (عرض فقط) و«بطولات الفرق».

## الدردشة اللحظية (E21)
الدردشة (مباشرة/فريق/عامة) تعمل بـHTTP كاملًا؛ **اللحظية** تتطلب عملية **Reverb** دائمة (WebSocket). لا خدمات SaaS خارجية (لا Pusher Cloud ولا Ably). التفاصيل المعمارية بـ`docs/chat.md`.

- **الترحيلات:** `php artisan migrate`: خمسة جداول جديدة (`chat_threads`، `chat_messages`، `chat_read_states`، `chat_message_reports`، `chat_mutes`). لا ترحيل قديم عُدِّل.
- **الصلاحيات:** `php artisan permissions:sync` ثم `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder"`: صلاحيتان جديدتان `chat.reports.view` و`chat.moderate` (للمدير تلقائيًا؛ امنحهما لدور المشرف يدويًا).
- **الحزم:** `composer install` (يتضمن `laravel/reverb` بعد التزام `composer.lock`) و`npm ci`. `laravel-echo` و`pusher-js` بـ`package.json`.
- **المتغيرات (`.env`، بلا أسرار بالمستودع):**
  ```
  BROADCAST_CONNECTION=reverb
  REVERB_APP_ID=
  REVERB_APP_KEY=
  REVERB_APP_SECRET=
  REVERB_HOST=ws.example.com        # اسم المضيف الذي يصل إليه المتصفح
  REVERB_PORT=443
  REVERB_SCHEME=https
  REVERB_SERVER_HOST=127.0.0.1      # عنوان استماع العملية نفسها
  REVERB_SERVER_PORT=8080
  VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
  VITE_REVERB_HOST="${REVERB_HOST}"
  VITE_REVERB_PORT="${REVERB_PORT}"
  VITE_REVERB_SCHEME="${REVERB_SCHEME}"
  ```
  القيم العشوائية: `php -r "echo bin2hex(random_bytes(16));"`. **متغيرات `VITE_REVERB_*` تُقرأ وقت البناء**: اضبطها **قبل** `npm run build`، وأعد البناء إن تغيّرت. ضيّق `allowed_origins` بـ`config/reverb.php` إلى نطاق موقعك.
- **عملية Reverb تحت Supervisor** (دون افتراض مضيف محدد؛ عدّل المسارات والمستخدم):
  ```
  [program:ahjiyat-reverb]
  command=php /path/to/project/artisan reverb:start --host=127.0.0.1 --port=8080
  autostart=true
  autorestart=true
  numprocs=1
  user=your-deploy-user
  redirect_stderr=true
  stdout_logfile=/var/log/ahjiyat-reverb.log
  stopwaitsecs=30
  minfds=10000
  ```
  أو **systemd** (`/etc/systemd/system/ahjiyat-reverb.service`):
  ```
  [Unit]
  Description=Ahjiyat Reverb WebSocket server
  After=network.target

  [Service]
  User=your-deploy-user
  WorkingDirectory=/path/to/project
  ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
  Restart=always
  LimitNOFILE=10000

  [Install]
  WantedBy=multi-user.target
  ```
  بعد كل نشر شغّل `php artisan reverb:restart` ليحمّل الكود الجديد.
- **بروكسي عكسي يدعم WebSocket (مثال Nginx):** المتصفح يتصل بـ`wss://REVERB_HOST:REVERB_PORT/app/{key}` والخادم يستقبل نداءات البثّ على `/apps/...`؛ مرّر الاثنين لعملية Reverb مع ترقية الاتصال:
  ```
  server {
      listen 443 ssl http2;
      server_name ws.example.com;
      # ssl_certificate ...; ssl_certificate_key ...;

      location / {
          proxy_http_version 1.1;
          proxy_set_header Host $http_host;
          proxy_set_header Scheme $scheme;
          proxy_set_header SERVER_PORT $server_port;
          proxy_set_header REMOTE_ADDR $remote_addr;
          proxy_set_header Upgrade $http_upgrade;
          proxy_set_header Connection "Upgrade";
          proxy_read_timeout 120s;
          proxy_pass http://127.0.0.1:8080;
      }
  }
  ```
  يلزم `Upgrade`/`Connection` وإلا فشلت الاتصالات اللحظية. استضافة بلا عمليات دائمة (مشتركة بلا SSH/Supervisor) **لا تصلح للّحظية**: تبقى الدردشة بـHTTP بدونها.
- **الطابور:** الدردشة **لا تحتاج عامل طابور** (البثّ فوري `ShouldBroadcastNow` بعد commit). عامل الطابور (`php artisan queue:work`) ما زال لازمًا لبقية المنصة (إشعارات الأحداث والبطولات). فشل Reverb لا يفقد رسالة ولا يملأ `failed_jobs`.
- `npm run build` **ضروري** (صفحات وأصناف جديدة + Echo).
- **مراقبة:** لوحة الإدارة: «بلاغات الدردشة» و«كتم الدردشة». لا يوجد (عمدًا) عرض لكل الرسائل الخاصة.

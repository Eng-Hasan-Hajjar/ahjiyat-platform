# أول نشر للإنتاج (E25)

> خطوات مرتّبة ومحايدة للمزوّد. للنشرات **اللاحقة** استعمل [`tools/deploy/deploy.sh`](release-engineering.md#3-تسلسل-النشر)؛ هذا الدليل لأول مرة فقط (قاعدة فارغة، لا مدير بعد، لا خدمات). لا شيء هنا يُشغَّل تلقائيًا.
> قبل البدء اقرأ: [قائمة الإطلاق](production-checklist.md)، [متغيرات البيئة](environment-variables.md)، [النسخ والتعافي](backup-disaster-recovery.md).

## U1) التحضير

1. **الخادم**: Linux (LTS) بمستخدم نشر **غير root**، جدار ناري (80/443 للعامة، SSH مقيّد)، مزامنة وقت (NTP)، منطقة زمنية UTC.
2. **PHP 8.2+** (8.3 مُوصى به؛ هو ما يختبره CI) مع PHP-FPM والامتدادات: `bcmath ctype curl dom fileinfo filter hash iconv intl json libxml mbstring openssl pcre pdo_mysql session tokenizer xml xmlreader zip` (+ `redis` إن استعملت Redis). **`intl` و`zip` مطلوبتان** (Filament وتصدير الجداول).
   - OPcache مفعّل؛ بالإنتاج غالبًا `opcache.validate_timestamps=0` ⟵ **يجب إعادة تحميل PHP-FPM بعد كل نشر**.
3. **قاعدة البيانات**: MySQL 8+/MariaDB 10.6+ بترميز `utf8mb4`. أنشئ قاعدة **فارغة** جديدة ومستخدمًا مخصصًا لها (ليس root) وآخر للنسخ الاحتياطي بصلاحيات قراءة. التطبيق يحتاج DDL للترحيلات (`CREATE/ALTER/INDEX/DROP/REFERENCES` + DML) على قاعدته فقط.
4. **Redis** (اختياري الآن، موصى به عند التوسع): كلمة مرور، ربط بواجهة خاصة، قرار الفصل والاستمرارية ([Redis](operations-runbook.md#redis)). بدونه: `CACHE_STORE=database`/`SESSION_DRIVER=database`/`QUEUE_CONNECTION=database`.
5. **خادم الويب** (مثال Nginx في [production-deployment](production-deployment.md#ملحق-مثال-nginx)): جذر الموقع **`/public` فقط**، TLS، `client_max_body_size` مناسب، وكتل WebSocket لـReverb ([الوكيل](operations-runbook.md#الوكيل-العكسي-nginx-websocket)).
6. **Composer 2** و**Node 20+** (للبناء فقط، أو ابنِ بـCI وانقل الناتج كـartifact).
7. **نسخة احتياطية**: جهّز وجهة النسخ الخارجية ومفتاح التشفير (age/gpg) **قبل** أن توجد بيانات.

## U2) التطبيق

1. **ضع الكود** لإصدار محدد (وسم/SHA) بمجلد إصدار جديد، مثل `/var/www/ahjiyat/releases/<id>` مع رابط `current` ⟵ مجلد الإصدار (يسهّل التراجع). عبر artifact/rsync/آلية مزوّدك. لا `git pull` على الخادم، ولا `.git` داخل `public/`.
2. **البيئة**: أنشئ `.env` للإنتاج من مدير الأسرار (القالب: [environment-variables](environment-variables.md#6-الحد-الأدنى-النموذجي-للإنتاج-placeholders-فقط))، بصلاحيات `640` ومالك مستخدم التطبيق.
   - **`APP_KEY`**: ولّده مرة واحدة على جهاز آمن `php artisan key:generate --show` وخزّنه فورًا بالخزنة (نسختان، مسؤولان) قبل وضعه بالبيئة. لا تلصقه بمحادثة أو تذكرة. [تحذير APP_KEY](security-hardening.md#app_key-تحذير-خاص).
   - `APP_ENV=production`، `APP_DEBUG=false`، `APP_URL=https://…`، `SESSION_SECURE_COOKIE=true`، `APP_RELEASE=<id>`.
3. **التبعيات والواجهة**:
   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   npm ci && npm run build        # أو انقل public/build من CI
   ```
4. **الصلاحيات**: مالك الملفات مستخدم النشر، مجموعة الويب `www-data`، `chmod -R 775 storage bootstrap/cache` (لا `777` إطلاقًا) ([التفاصيل](production-deployment.md#أذونات-الملفات)).
5. **رابط التخزين**: `php artisan storage:link` (رابط حقيقي، ليس Windows Junction).

## U3) قاعدة البيانات والمدير الأول

> القاعدة جديدة وفارغة؛ لا شيء تخسره، لكن خذ لقطة فارغة للتمرين. على قاعدة فارغة سيعطي `release:preflight` FAIL لغياب الجداول: **متوقع** قبل الترحيل الأول، ولهذا ترحَّل أولًا هنا (وتُستعمل `deploy.sh` بعد هذه المرحلة فقط).

1. `php artisan migrate --force`
2. `php artisan permissions:sync`
3. `php artisan db:seed --class="Database\Seeders\RolesAndPermissionsSeeder" --force`
4. **المدير الأول** (بلا كلمة مرور افتراضية: بالإنتاج يشترط `AdminUserSeeder` بريدًا وكلمة مرور ≥ 12 خانة من البيئة). أدخل القيمتين في **بيئة العملية فقط** (ليس `.env`) وبلا ظهورها بسجل الأوامر:
   ```bash
   read -r  -p "بريد المدير: " ADMIN_BOOTSTRAP_EMAIL
   read -rs -p "كلمة المرور (≥ 12 خانة): " ADMIN_BOOTSTRAP_PASSWORD; echo
   export ADMIN_BOOTSTRAP_EMAIL ADMIN_BOOTSTRAP_PASSWORD
   php artisan config:clear      # كي تقرأ الإعدادات البيئة الحالية (لا كاش مجمّد)
   php artisan db:seed --class="Database\Seeders\AdminUserSeeder" --force
   unset ADMIN_BOOTSTRAP_EMAIL ADMIN_BOOTSTRAP_PASSWORD
   php artisan rbac:migrate-legacy-roles     # يرقّي أقدم مدير قديم إلى super-admin (Bootstrap)
   ```
   سجّل الدخول فورًا من `/admin` وغيّر كلمة المرور إلى قيمة جديدة قوية. **لا `php artisan db:seed` الكامل** (ممنوع آليًا بالإنتاج: يزرع حسابات تجريبية بكلمات معروفة).
5. **المحتوى الأولي** (تصنيفات/أحجيات): قرار منتج. شغّل seeders المحتوى المحددة فقط بعد مراجعة ما تزرعه، أو أدخله من لوحة الإدارة. لا بيانات تجريبية بالإنتاج.
6. **حسّن** (آخر خطوة قبل الخدمات): `APP_RELEASE=<id> php artisan optimize` (يبني كاش config/routes/views/events ويخبز معرّف الإصدار).

## U4) الخدمات

1. **المجدول**: cron لمستخدم التطبيق: `* * * * * cd /var/www/ahjiyat/current && php artisan schedule:run >> /dev/null 2>&1` ([قرار الطوبولوجيا](operations-runbook.md#خادم-مجدول-مخصص-أم-كل-الخوادم)).
2. **عامل الطابور** تحت Supervisor/systemd ([الإعداد](operations-runbook.md#تشغيل-العامل-تحت-مشرف-supervisor)).
3. **Reverb** تحت المشرف + كتل الوكيل ([الإعداد](operations-runbook.md#reverb-البث-الفوري)).
4. **PHP-FPM**: أعد تحميله (reload) كي يحمّل OPcache الكود.
5. **النسخ الاحتياطي**: جدولة `tools/backup/db-backup.sh` و`files-backup.sh` (يوميًا) + تنبيه على الفشل. نفّذ نسخة يدوية الآن وتحقق من وصولها خارج الخادم.

## U5) التحقق

1. `curl -sS -o /dev/null -w "%{http_code}\n" https://<النطاق>/up` ⟵ `200`.
2. `curl -sS https://<النطاق>/ready` ⟵ `{"status":"ok", "release":"<id>", "checks":{…}}` (200). لا أسرار ولا إصدارات PHP/Laravel ولا أسماء مضيفين.
3. `php artisan production:check` ⟵ Exit **0**. بعد دقيقتين: نبضة المجدول `ok` (قبل ذلك `unknown` طبيعية).
4. `php artisan release:preflight` ⟵ بلا FAIL.
5. `php artisan release:smoke --url=https://<النطاق> --expect-release=<id>` ⟵ كل المسارات PASS.
6. الفحص اليدوي: [smoke-tests](smoke-tests.md) (دخول المدير، تسجيل حساب جديد + وصول بريد التوثيق، أحجية، محفظة، `/admin/operations`).
7. مركز العمليات: الإصدار، المجدول `ok`، الطابور بلا تراكم، لا `APP_DEBUG`.
8. `curl -sI https://<النطاق>/` ⟵ رؤوس الأمان موجودة وHSTS (بالإنتاج HTTPS).

## U6) بعد الإطلاق

- [ ] `ADMIN_BOOTSTRAP_*` غير موجودة بأي مكان (البيئة، `.env`، سجل الأوامر).
- [ ] **تمرين استعادة** على بيئة معزولة خلال أسبوعين من الإطلاق، ثم ربع سنوي ([التمرين](backup-disaster-recovery.md#10-تمرين-الاستعادة-restore-drill-كل-ربع-سنة)).
- [ ] المراقبة الخارجية على `/ready` تعمل والتنبيه يصل.
- [ ] سجّل: معرّف الإصدار الأول، تاريخ الإطلاق، مَن يملك أي سر، مكان نسخ الأسرار.
- [ ] راقب 24 ساعة: السجلات، الطابور، المجدول، القرص.

## ما لا تفعله أبدًا

| لا تفعل | لماذا |
|---|---|
| `php artisan db:seed` (كامل) | حسابات تجريبية بكلمات معروفة (ممنوع آليًا بالإنتاج) |
| `migrate:fresh` / `db:wipe` / `migrate:rollback` | يمحو/يهدم بيانات (محظور آليًا بالإنتاج) |
| `APP_DEBUG=true` | يكشف Stack Traces والإعدادات (FAIL حاجب) |
| `.env` أو أسرار في Git أو الوثائق أو CI | تسرّب دائم عبر التاريخ |
| `php artisan serve` / `npm run dev` على الخادم | ليسا خادمَي إنتاج، و`public/hot` يكسر الأصول |
| `composer update` / `npm update` عند النشر | إصدارات غير مُختبَرة؛ مصدر الإصدارات هو ملفات القفل |
| `chmod -R 777` | يفتح الملفات لأي عملية على الخادم |

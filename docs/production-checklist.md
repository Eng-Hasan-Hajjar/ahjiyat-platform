# قائمة جاهزية الإطلاق للإنتاج (E25)

> علّم كل بند بعد التحقق منه بنفسك (لا بالافتراض). تكمّل هذه القائمة [pre-launch-checklist](pre-launch-checklist.md) (القائمة الأقدم، تبقى مرجعًا للمحتوى والصفحات) وتتقدّم عليها في كل ما يخص التشغيل والأمان.
> معظم البنود التقنية يفحصها `php artisan production:check` (يجب Exit 0) و`php artisan release:preflight` قبل النشر.

## DNS و TLS

- [ ] DNS للنطاق (A/AAAA/CNAME) يشير للخادم/الموازن الصحيح، وللنطاق الفرعي لـReverb.
- [ ] شهادة TLS صالحة للنطاق والنطاق الفرعي، وتجديد تلقائي مُجرَّب.
- [ ] HTTP يُحوَّل إلى HTTPS على مستوى الوكيل.
- [ ] (اختياري، بوعي) HSTS: التطبيق يرسله فقط بالإنتاج على HTTPS بلا `includeSubDomains`/`preload`. لا تفعّلهما إلا بعد التأكد من ثبات HTTPS لكل النطاقات الفرعية.

## البيئة والإعدادات

- [ ] `APP_URL=https://<النطاق>` (ليس localhost).
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false` (**FAIL حاجب** إن كان true).
- [ ] `APP_KEY` مضبوط وصالح، ونسخته محفوظة بخزنة أسرار بنسختين ([escrow](backup-disaster-recovery.md#2-الأسرار-وapp_key-ضمان-مفتاح-آمن-key-escrow)) وليس بالمستودع.
- [ ] `APP_RELEASE` مضبوط لهذا الإصدار.
- [ ] `SESSION_SECURE_COOKIE=true`، `SESSION_SAME_SITE=lax`، `SESSION_HTTP_ONLY=true`.
- [ ] `TRUSTED_PROXIES` مضبوط على عناوين الموازن (أو فارغ فقط إن لا موازن).
- [ ] `LOG_STACK=daily`، `LOG_LEVEL=warning|info`، `LOG_DAILY_DAYS` محدد.
- [ ] لا أسرار في الكود/الوثائق/CI؛ `.env` بصلاحيات `640` وخارج `public/`.
- [ ] `php artisan production:check` ⟵ Exit **0** ([المتغيرات](environment-variables.md)).

## قاعدة البيانات والترحيلات

- [ ] `DB_CONNECTION=mysql|pgsql` (ليس sqlite)، مستخدم بصلاحيات التطبيق فقط (ليس root)، كلمة مرور قوية.
- [ ] **نسخة احتياطية مُتحقَّق منها قبل أول ترحيل**.
- [ ] `php artisan release:preflight` بلا FAIL، ومراجعة كل ترحيل معلّق وخطره ([السياسة](release-engineering.md#4-سياسة-الترحيلات-الآمنة)).
- [ ] `php artisan migrate --force` نُفِّذ؛ `production:check` لا يعرض ترحيلات معلّقة.
- [ ] **لا `db:seed` الكامل بالإنتاج** (ممنوع آليًا)؛ لا حسابات `@ahjiyat.test` ولا كلمة المرور الافتراضية للمدير.

## Redis و الكاش

- [ ] قرار واعٍ: Redis أم database لكل من الكاش/الجلسات/الطابور ([Redis](operations-runbook.md#redis)).
- [ ] إن وُجد Redis: كلمة مرور، مربوط بواجهة خاصة، `noeviction` إن حمل الطابور/الجلسات، استمرارية AOF، وتنبيه ذاكرة.
- [ ] الكاش يدعم الأقفال (مطلوب للمجدول) ومشترك بين الخوادم إن تعددت.

## الطابور والمجدول و Reverb

- [ ] `QUEUE_CONNECTION=database|redis`، و`retry_after` (90) أكبر من timeout (80).
- [ ] عامل `queue:work` تحت Supervisor/systemd ويعمل (`--timeout=80 --max-time=3600`).
- [ ] `queue:restart` ضمن خطوات النشر.
- [ ] cron: `* * * * * php artisan schedule:run` لمستخدم التطبيق؛ نبضة المجدول `ok` بمركز العمليات بعد دقيقتين.
- [ ] قرار الطوبولوجيا: مجدول مخصص أم كل الخوادم + كاش مشترك.
- [ ] Reverb تحت المشرف، `REVERB_SCHEME=https`، `REVERB_HOST` عام، `REVERB_APP_SECRET` عشوائي ≥ 32 خانة، `REVERB_ALLOWED_ORIGINS` مقيّدة، الوكيل يمرّر `Upgrade`، وحد الملفات المفتوحة مرفوع.

## التخزين والبريد والصلاحيات

- [ ] `storage/` و`bootstrap/cache/` قابلان للكتابة؛ `php artisan storage:link` (رابط حقيقي).
- [ ] مساحة القرص كافية (> 15% متبقي) وتنبيه عند 80%.
- [ ] `MAIL_MAILER=smtp` فعلي، `MAIL_FROM_ADDRESS` على نطاقك مع SPF/DKIM/DMARC، ورسالة توثيق بريد وصلت فعلًا.
- [ ] `php artisan permissions:sync` ثم (عند أول نشر فقط) `RolesAndPermissionsSeeder`؛ مدير أعلى (super-admin) واحد على الأقل بكلمة مرور قوية؛ `ADMIN_BOOTSTRAP_*` حُذفت من البيئة.
- [ ] `/admin` يعمل بحساب مخوَّل فقط، لا مسارات تشخيص عامة (`production:check` يفحصها).

## البناء والصحة والدخان

- [ ] `composer install --no-dev --prefer-dist --optimize-autoloader` من `composer.lock`؛ `npm ci && npm run build` من `package-lock.json`؛ لا `composer update`/`npm update` في النشر.
- [ ] `php artisan optimize` (config/routes/views/events).
- [ ] `GET /up` = 200، `GET /ready` = 200 وفيه `release` الصحيح.
- [ ] `php artisan release:smoke --expect-release=<id>` ⟵ بلا FAIL.
- [ ] الفحص اليدوي بعد النشر ([smoke-tests](smoke-tests.md)) خلال 15–20 دقيقة.

## النسخ الاحتياطي والاستعادة

- [ ] نسخة يومية للقاعدة والملفات، **مشفّرة وخارج الخادم**، وتنبيه عند فشلها.
- [ ] PITR/binlog مفعّل إن أمكن؛ RPO/RTO متفق عليهما مع مالك المنتج.
- [ ] **تمرين استعادة ناجح واحد على الأقل** قبل الإطلاق، ومدوَّن بزمنه ([التمرين](backup-disaster-recovery.md#10-تمرين-الاستعادة-restore-drill-كل-ربع-سنة)).
- [ ] جدول تمارين ربع سنوية محدد.

## المراقبة والسجلات

- [ ] مراقب خارجي على `/ready` (وتنبيه إلى قناة حيّة).
- [ ] تنبيهات: القرص، الذاكرة، 5xx، عمر الطابور، المهام الفاشلة، نبضة المجدول، فشل النسخة.
- [ ] تدوير السجلات والاحتفاظ؛ قرار منصة سجلات مركزية (P2).
- [ ] لا بيانات حساسة بالسجلات (Authorization/Cookie/كلمات المرور/نص الدردشة/جسم الطلب).
- [ ] مركز العمليات يعرض الإصدار وحالة المجدول والطابور (لمن يملك `system.audit_view`).

## مسار التراجع والحوادث

- [ ] الإصدار السابق محفوظ وقابل للاستعادة (artifact/مجلد إصدار) ومعرَّف.
- [ ] مصفوفة التراجع مقروءة لمن سينفّذ ([التراجع](release-engineering.md#53-مصفوفة-التراجع)): الكود أولًا، لا rollback للقاعدة.
- [ ] [دليل الحوادث](incident-response.md) معروف، وجهات اتصال المناوبة محدّثة.
- [ ] سياسة الجوائز والاستبدال مراجعة لدى مختص قانوني بحسب الدولة المستهدفة (تنبيه الدراسة الأصلية).

## التوقيع

| | الاسم | التاريخ |
|---|---|---|
| منفّذ النشر | | |
| مراجع ثانٍ | | |
| مالك المنتج (الموافقة على الإطلاق) | | |

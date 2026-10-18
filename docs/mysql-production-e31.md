# MySQL للإنتاج: التكافؤ، نقل البيانات من SQLite، تحصين القاعدة والتزامن (E31)

> **حالة الدليل التشغيلي:** كل ما يمكن إثباته بلا خادم MySQL أُثبت وأُختبر (ترجمة كل الترحيلات إلى MySQL وتحليلها، منطق النقل والتحقق على SQLite→SQLite، التزامن والسياسات).
> كل ما يتطلب خادم MySQL فعليًا موسوم هنا **«PENDING USER-LOCAL MYSQL VALIDATION»** إلى أن تشغّل [المصفوفة المحلية](#6-مصفوفة-اختبارات-mysql-المحلية) وتنجح. لا تُعدّ E31 مغلقة قبل ذلك.

## 1) الفكرة في سطرين

* **MySQL 8 هي قاعدة الإنتاج الأساسية**، وتبقى SQLite لسرعة الاختبارات اليومية وCI (مجموعة الاختبارات الكاملة تعمل عليها كما هي).
* أُضيفت أدوات للانتقال بأمان: `db:parity` (تكافؤ المخطط، قراءة فقط) و`db:transfer-sqlite-to-mysql` (نقل بيانات بحواجز صارمة وتحقق كامل) وإعداد اختبار MySQL (`phpunit.mysql.xml.dist`).

## 2) النسخ المدعومة

| المحرك | الحالة |
|---|---|
| **MySQL 8.0.16 فأعلى** (الاستهداف: 8.0 LTS و8.4 LTS) | المدعوم والمعتمَد. الحد الأدنى 8.0.16 لأن قيود `CHECK` لا تُفرض قبلها |
| MariaDB 10.6 فأعلى | «أفضل جهد» فقط، **لم يُتحقَّق منه** في E31 (يُظهر `production:check` تحذيرًا) |
| MySQL 5.7 وما دونه | غير مدعوم (يفشل `production:check` بالإنتاج) |

الإعدادات التي يتوقعها التطبيق (يفحصها `php artisan production:check` و`php artisan db:parity --runtime`):

* الترميز `utf8mb4` والتجميع `utf8mb4_unicode_ci`، المحرك **InnoDB** لكل الجداول.
* الوضع الصارم `STRICT_TRANS_TABLES` (يفرضه `config/database.php`: `strict => true`) كي لا تُقتطع البيانات بصمت.
* منطقة جلسة القاعدة UTC: `DB_TIMEZONE=+00:00` (الافتراضي).
* `max_allowed_packet` لا يقل عن 4MB (الافتراضي في MySQL 8 هو 64MB).

### قرار التجميع (collation)

نبقي `utf8mb4_unicode_ci` (افتراضي لارافل) ولا ننتقل إلى `utf8mb4_0900_ai_ci`:

* مدعوم في MySQL وMariaDB معًا (0900 غير موجود في MariaDB) فلا نقفل أنفسنا على محرك واحد.
* لا حاجة لتجميع عربي خاص: الترتيب الأبجدي العربي يكفيه UCA العام.
* **السلوك الذي يجب أن تعرفه:** المقارنة غير حساسة لحالة الأحرف **ولا للتشكيل/اللكنات**، أي `A@x.com` و`a@x.com` متساويان في الفهرس الفريد، و`أحجية` و`احجية` تتطابقان في `=`/`LIKE`. هذا هو الفرق الجوهري عن SQLite (حساسة للحالة)، وهو سبب فحص «التصادم بتجاهل الحالة» قبل النقل (القسم 8).

## 3) الإعداد المحلي (Windows / PowerShell)

### 3.1 خادم MySQL

اختر واحدًا (كلاهما بترميز utf8mb4):

**أ) Docker Desktop (الأسرع):**

```powershell
docker run --name ahjiyat-mysql -e MYSQL_ROOT_PASSWORD=<كلمة-مرور-محلية> -p 3306:3306 -d mysql:8.0 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
docker exec -it ahjiyat-mysql mysql -uroot -p
```

**ب) MySQL Installer / XAMPP:** تأكد أن النسخة 8.0.16+ (`mysql --version`). في XAMPP (يأتي غالبًا بـMariaDB) النسخة **غير مُتحقَّق منها**؛ الأفضل MySQL 8.

> تأكد أن امتداد `pdo_mysql` مفعّل في `php.ini` (`extension=pdo_mysql`). الفحص: `php -m | Select-String pdo_mysql`.

### 3.2 القواعد والمستخدم (نفّذها داخل عميل mysql)

```sql
CREATE DATABASE ahjiyat_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE ahjiyat_platform_test_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ahjiyat_app'@'%' IDENTIFIED BY '<كلمة-مرور-قوية>';
GRANT ALL PRIVILEGES ON ahjiyat_platform.* TO 'ahjiyat_app'@'%';
GRANT ALL PRIVILEGES ON `ahjiyat\_platform\_test%`.* TO 'ahjiyat_app'@'%';
FLUSH PRIVILEGES;
```

* القاعدة الثانية **للاختبارات فقط** و`RefreshDatabase` يحذف جداولها كل مرة. اسمها يجب أن ينتهي بـ`_test` أو `_testing` أو `_test_mysql` وإلا يرفض `TestDatabaseGuard` التشغيل.
* الصلاحية بنمط `ahjiyat\_platform\_test%` تسمح للاختبار بإنشاء قاعدة نقل مؤقتة `ahjiyat_platform_test_xfer_test` وحذفها (اختبار النقل الحقيقي). لا تُعطِ المستخدم صلاحيات أوسع.
* في الإنتاج الحقيقي: مستخدم التطبيق بصلاحيات `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES` على قاعدة واحدة، وليس root.

### 3.3 `.env` (للعمل بـMySQL)

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahjiyat_platform
DB_USERNAME=ahjiyat_app
DB_PASSWORD=<من-ملف-.env-المحلي-فقط>
DB_TIMEZONE=+00:00
```

لا تضع كلمة المرور في أي ملف داخل المستودع. أبقِ `QUEUE_CONNECTION`/`SESSION_DRIVER`/`CACHE_STORE` على `database` مبدئيًا (جداولها ضمن الترحيلات) وانظر [القسم 14](#14-الطوابير-والجلسات-والكاش-على-mysql).

## 4) بناء المخطط من الصفر على MySQL

```powershell
# قاعدة جديدة فارغة فقط. لا تُشغّل هذا على قاعدة فيها بيانات.
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='ahjiyat_platform'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'
php artisan migrate --force
php artisan db:parity --runtime
```

* `db:parity` **قراءة فقط**: يترجم كل الترحيلات إلى جمل MySQL ويحلّلها (أسماء أطول من 64، فهارس المفاتيح الأجنبية، أنواع المفاتيح، أعمدة مالية عائمة، فهارس على TEXT...) ثم يقارن القاعدة الحيّة بالنموذج. يخرج بـ**1 عند فشل جوهري فقط** (جدول/عمود/قيد فريد/مفتاح أجنبي ناقص، اختلاف فئة نوع) وبـ0 مع تحذيرات/معلومات. `--static` يعمل بلا أي قاعدة، `--against=sqlite` يقارن قاعدتين حيّتين، `--details` يعرض الفروق المعلوماتية، `--json` للأتمتة.
* ما أُصلح في E31 لأن MySQL يرفضه ولا ترفضه SQLite (لا تعديل على ترحيل تاريخي):
  * **اسم فهرس أطول من 64 حرفًا** (خطأ 1059): `App\Database\MysqlSafeBlueprint` يقصّر الأسماء المولَّدة فقط على mysql/mariadb، بنمط ثابت يمكن إعادة اشتقاقه (`substr(name,0,55)_sha1(8)`). SQLite لا تتأثر.
  * **حذف فهرس يخدم مفتاحًا أجنبيًا** (خطأ 1553) في ترحيل المحافظ: أُضيف ترحيل حامٍ قبله (`2026_09_23_000002_e31_guard_wallets_user_fk_index`) وآخر يسقط الحامي لاحقًا حين يصير زائدًا (`2026_10_23_000001_e31_drop_wallets_user_guard_index`). كلاهما بلا أثر على SQLite.
  * **عمود `rank` كلمة محجوزة** في MySQL 8: استعلام ترتيب الفرق يؤهّلها باسم الجدول.
* **PENDING USER-LOCAL MYSQL VALIDATION:** نجاح `php artisan migrate` فعليًا على MySQL وخروج `db:parity` بـ0 هناك.

## 5) النسخ الاحتياطي لـSQLite (قبل أي نقل)

أوقف التطبيق والطابور أولًا (لا كتابات أثناء النسخ):

```powershell
php artisan down
# إن كان الملف بوضع WAL: ادمج الكتابات المعلقة في الملف الرئيسي
sqlite3 database\database.sqlite "PRAGMA wal_checkpoint(TRUNCATE);"   # أو تجاوزه إن لم يوجد ملف -wal
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Copy-Item database\database.sqlite "database\backup-$stamp.sqlite"
(Get-FileHash database\database.sqlite -Algorithm SHA256).Hash
(Get-FileHash "database\backup-$stamp.sqlite" -Algorithm SHA256).Hash   # يجب أن تتطابق البصمتان
```

* **لا تستمر** إن اختلفت البصمتان. احتفظ بالنسخة خارج مجلد المشروع أيضًا (قرص آخر/سحابة خاصة).
* أمر النقل نفسه يرفض العمل بلا `--backup` مطابقة بايتًا لملف المصدر (يتحقق من SHA-256)، ويرفض إن وجد ملف `-wal` غير فارغ بجانب المصدر.

## 6) مصفوفة اختبارات MySQL المحلية

الأمر الواحد (مسارات صريحة لا اختبار كامل، يُنشئ قاعدة الاختبار إن لزم، ويختم بـ`db:parity`):

```powershell
powershell -ExecutionPolicy Bypass -File tools\e31\run-mysql-matrix.ps1 -Username ahjiyat_app -Password '<كلمة-المرور>'
```

أو مجموعة واحدة يدويًا (لاحظ: `vendor/bin/pest` وليس `php artisan test` لأن الأخير يضيف إعداده الخاص ويتعارض):

```powershell
$env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31
```

المجموعات: Auth, Wallet/Economy, Store, Competitive, Chat, Notifications, Search E27, Creator E28, Engagement E29, Sponsorship E30, Creator Series E30, Database E31.

* اختبارات `tests/Feature/DatabaseE31/E31MysqlRuntimeTest.php` **تتخطى بوضوح على SQLite** ولا تُحسب دليلًا إلا إن شُغِّلت على MySQL. تغطي: شروط الخادم، `db:parity` الحيّ، InnoDB/utf8mb4، عربي/إيموجي بايتًا ببايت، رفض النص الطويل، منع نزول UNSIGNED تحت الصفر، المفاتيح الأجنبية (1452/1451)، حساسية التجميع في الفرادة، JSON، مهلة القفل 1205 بين جلستين حقيقيتين، سباق مفتاح فريد (1205 ثم 1062)، بحث E27، **بناء مخطط من الصفر ثم نقل بيانات حقيقي والتحقق** و**الأمر end-to-end**.
* خمسة اختبارات قديمة تعتمد على `EXPLAIN QUERY PLAN` الخاص بـSQLite صارت **تتخطى بوضوح** على MySQL (E27 P5/P6، E28 X9، E30 AM15 ×2) كي لا تفشل زورًا؛ خطط MySQL تُفحص بـ[القسم 12](#12-تدقيق-explain-والفهارس).

## 7) سياسة التزامن والأقفال (تدقيق E31)

### 7.1 ما فُحص
كل مسار مالي/منافسة يستعمل `DB::transaction` مع `lockForUpdate` على صف أب ثابت (المحفظة، المنتج، الحدث، الجلسة)، ثم قيد فريد كخط دفاع أخير. على SQLite (كاتب واحد) لا يظهر الإيقاف المتبادل؛ على MySQL (REPEATABLE READ + أقفال الفجوات) قد يظهر عند نمط «افحص ثم أدرج» تحت حمل متزامن.

### 7.2 الإعادة عند الإيقاف المتبادل (محدودة عمدًا)
* الثابت `App\Services\Database\TransactionPolicy::DEADLOCK_ATTEMPTS = 3` (3 محاولات كحد أقصى).
* تُطبَّق على **أربع نقاط دخول فقط** (مجلّدة باختبار يفشل إن زادت): `PuzzleAttemptService`, `GameSessionService::start`, `CompetitiveRunService::start`, `CreatorApplicationService::apply`.
* لارافل تعيد المعاملة **الخارجية فقط** وعند أخطاء التزامن فقط (1213/40001)، ولا تعيد أخطاء الأعمال ولا المعاملات المتداخلة. لا إعادة بالجملة.
* **المتجر:** `StorePurchaseService::purchase` يعالج سباق النقر المزدوج بالمفتاح نفسه: إن اصطدم الثاني بقيد `request_key` الفريد بعد التزام الأول يُرجَع الشراء القائم (idempotent) بدل خطأ 500، بعد التأكد أنه الطلب نفسه. أي قيد فريد آخر يُعاد رميه.
* **تعليمة:** إن ظهر 1213 في السجلات خارج هذه النقاط فلا تُضف إعادة تلقائية؛ أرسل السجل (دون بيانات) لتحليله.

### 7.3 ما لم يتغير عمدًا
* مستوى العزل الافتراضي (REPEATABLE READ) والأقفال الحالية.
* `after_commit` للطوابير ما زال `false`: طابور قاعدة البيانات يشارك اتصال التطبيق نفسه فتُتراجع المهمة مع المعاملة. **عند الانتقال إلى طابور Redis** يجب ضبط `after_commit => true` (أو إرسال المهام بعد الالتزام) وإلا قد تعمل مهمة على بيانات لم تلتزم بعد.
* الأحداث تستعمل `DB::afterCommit` أصلًا حيث يهم.

### 7.4 كل تنفيذ تزامني فعلي = **PENDING USER-LOCAL MYSQL VALIDATION**
اختبارات M-9 وM-10 تثبت سلوك الأقفال بجلستين حقيقيتين. حمل متزامن حقيقي (عدة عمليات) لم يُنفَّذ هنا.

## 8) نقل البيانات من SQLite إلى MySQL

### 8.1 ما يفعله الأمر وما لا يفعله
* **أداة محلية/تجريبية لمرة واحدة، ترفض `APP_ENV=production` دائمًا** بلا أي تجاوز. انقل على جهازك/staging ثم انشر قاعدة MySQL الجاهزة.
* المصدر SQLite (قراءة فقط على مستوى المحرك: `PRAGMA query_only` + بصمة SHA-256 قبل وبعد)، الهدف mysql/mariadb **بمخطط مبني بـ`migrate` وبيانات فارغة**. لا دمج.
* لا ينقل: `migrations` (يبنيها `migrate`)، `sessions` (يعاد تسجيل الدخول)، `cache`/`cache_locks`، `jobs`/`job_batches`/`failed_jobs` (لا تُنفَّذ مهام قديمة مرتين)، `password_reset_tokens`.
* **الجداول المرجعية** (`currencies`, `supporter_tiers`) تزرعها الترحيلات نفسها فلا تكون «فارغة» بعد `migrate`: يقبل الأمر ذلك فقط إن كان كل صف في الهدف موجودًا في المصدر بالمفتاح الطبيعي (`internal_key`/`slug`) **والمعرّف نفسه**، ثم يستبدلها بصفوف المصدر. أي تعارض معرّف يوقف النقل.
* ينسخ بالمعرّفات الأصلية (حفظ العلاقات)، بدفعات 500–2000 صف داخل معاملات، بترتيب اعتماد الجداول (الآباء أولًا)، مع تعطيل فحص المفاتيح الأجنبية على الهدف **داخل try/finally** والتأكد من إعادة تفعيله. المحتوى المشفَّر ينتقل نصًّا مشفَّرًا كما هو (لا فك ولا إعادة تشفير): **يجب بقاء `APP_KEY` نفسه** وإلا لن يُفك.
* يضبط `AUTO_INCREMENT` بعد النسخ ويتحقق منه.

### 8.2 الفحوص قبل النقل (يوقف عند أي مشكلة، يطبع أعدادًا فقط لا قيمًا)
اختلاف `migrations`/الأعمدة، قيم سالبة في أعمدة UNSIGNED، نص أطول من `varchar`، JSON غير صالح، تواريخ `0000-00-00`، خارج نطاق `TIMESTAMP` (1970–2038)، **تصادم حالة الأحرف على فهرس فريد** (`A@x.com` مقابل `a@x.com`)، تعارض الجداول المرجعية، وحقائق الخادم (ترميز/صرامة/محرك/نسخة).

### 8.3 الأوامر
```powershell
# 1) مخطط فارغ على الهدف (القسم 4) ثم:
$src = 'database\database.sqlite'          # مرِّره دائمًا صراحة (DB_DATABASE صار اسم قاعدة MySQL في هذه الجلسة)

# 2) تجربة بلا أي كتابة: تطبع الخطة والمشكلات
php artisan db:transfer-sqlite-to-mysql --source=$src --dry-run

# 3) النقل الفعلي (يتطلب النسخة الاحتياطية المطابقة من القسم 5)
php artisan db:transfer-sqlite-to-mysql --source=$src --force --backup="database\backup-$stamp.sqlite" --report="$env:TEMP\e31-transfer-report.json"
```

* ينتهي بتقرير: المصدر/الهدف، عدد الجداول والصفوف المنقولة، الجداول المؤقتة المستثناة، فروق الصفوف، المخالفات (أيتام المفاتيح الأجنبية)، تطابق **بصمات المحتوى** لكل جدول، سلامة AUTO_INCREMENT، **تطابق مجاميع المال بالأعداد الصحيحة**، ثم `Result: PASS|FAIL`. الخروج 0 فقط عند PASS **ومصدر لم يتغير**.
* **بصمة المحتوى** توحّد تمثيل القيم بين المحرّكين كي لا تُبلّغ عن فروق كاذبة: JSON يُقارَن بعد فكّه (ترتيب المفاتيح والمسافات و`\uXXXX`)، وأعمدة **DATE** تُقارَن بالجزء التاريخي فقط (يخزّن SQLite قيمة Eloquent كاملة «2026-10-18 00:00:00» بينما يحفظ MySQL «2026-10-18»)؛ أعمدة DATETIME/TIMESTAMP تُقارَن حرفيًا. (اكتُشف بنقل فعلي في إغلاق E31/E32.)
* `--skip-fingerprint` أسرع وأضعف تحققًا (لا تستعمله للنقل النهائي). `--batch-size` بين 500 و2000. `--exclude=table1,table2` لجداول إضافية. `--json` و`--report`.
* **التعافي بعد فشل جزئي:** قاعدة الهدف جديدة بالتصميم، فالأسلم إسقاطها وإعادة إنشائها وتشغيل `migrate`. وإلا: `--reset-target --confirm-database=<اسم قاعدة الهدف نفسه> --force --backup=...` (يفرغ جداول التطبيق فقط ولا يلمس `migrations`).
* رمز الخروج: 0 نجاح/اكتمال dry-run، 1 فشل (حاجز/مشكلة/عدم تطابق)، 2 يلزم `--force`/`--backup`.
* لا يطبع أي سر أو قيمة صف: رسائل الأخطاء تحمل فئة الخطأ ورمز MySQL واسم الجدول/العمود فقط.

> **PENDING USER-LOCAL MYSQL VALIDATION:** تشغيل `--dry-run` ثم النقل الفعلي على نسخة من بياناتك الحقيقية، وإرسال التقرير. الاختبارات الآلية أثبتت المنطق على SQLite→SQLite، وإثبات MySQL الحقيقي في M-12/M-13.

## 9) الانتقال (Cutover) من SQLite إلى MySQL: 12 خطوة

1. **جاهزية:** المصفوفة المحلية (القسم 6) PASS، و`db:parity` خرج 0 على MySQL، ونجح `--dry-run` على نسخة بياناتك.
2. **أعلِم المستخدمين** بنافذة الصيانة (الجلسات ستُنهى: إعادة تسجيل دخول).
3. `php artisan down --secret=<رمز-صيانة>` لإيقاف الواجهة، وأوقف عمال الطابور و`schedule:run` وReverb.
4. **انتظر** تفريغ الطابور الحي (`php artisan queue:work --stop-when-empty` أو تأكد أن `jobs` فارغ).
5. **نسخ SQLite احتياطيًا** + بصمة SHA-256 (القسم 5) وحفظها خارج المشروع.
6. على قاعدة MySQL **جديدة**: `php artisan migrate --force` ثم `php artisan db:parity --runtime` (يجب 0).
7. `php artisan db:transfer-sqlite-to-mysql --source=<...> --dry-run` ثم النقل الفعلي بـ`--force --backup`.
8. **راجع التقرير:** `Result: PASS`، لا فروق صفوف، لا أيتام، لا فروق بصمات، لا فروق مالية، وSQLite لم تتغير.
9. **حقق يدويًا:** مجاميع الأرصدة (`wallets`) وعدد المستخدمين وآخر عملية في الدفتر وآخر طلب استبدال مقابل ما تعرفه.
10. غيّر `.env`: `DB_CONNECTION=mysql` + بيانات الاتصال، ثم `php artisan optimize` (يجمّد الإعدادات) و`php artisan queue:restart` و`reverb:restart`.
11. `php artisan production:check` (يجب ألا يفشل بشأن القاعدة) ثم `php artisan up`، وشغّل `release:smoke` وجرّب تسجيل الدخول واللعب وعرض الرصيد وطلب استبدال تجريبي.
12. **راقب 24 ساعة:** السجلات (1213/1205/1062 غير المتوقعة)، `/ready`، طوابير فاشلة. احتفظ بـSQLite والنسخة **للقراءة فقط** 30 يومًا.

### التراجع
أوقف الواجهة، أعد `.env` إلى `DB_CONNECTION=sqlite`، أعد ملف SQLite من النسخة المصدّقة (تحقق من البصمة)، `php artisan optimize`، شغّل. **ما كُتب في MySQL بعد الانتقال لا يعود تلقائيًا إلى SQLite**: قرار التراجع المتأخر يعني خسارة تلك الكتابات، فخذ القرار خلال دقائق الانتقال الأولى أو تقبّل الخسارة.

## 10) المفاتيح الأجنبية وحذف السجلات (U2/U3)

تدقيق 214 مفتاحًا أجنبيًا كشف أن جداول مالية/مكافآت مرتبطة بـ`users` بـ`ON DELETE CASCADE`، فحذف مستخدم (من لوحة الإدارة، مدير أعلى فقط) كان سيمحو دفتر عملاته وطلبات استبداله ومكافآته. الترحيلات التاريخية **لم تُعدَّل**؛ الحماية على مستوى التطبيق:

* `App\Services\Database\FinancialHistoryGuard`: يعتبر أي سجل في `currency_transactions, redemption_requests, store_purchases, inventory_transactions, xp_transactions, competitive_reward_grants, sponsorship_reward_bonuses` أو رصيدًا في المحفظة «تاريخًا ماليًا».
* `UserPolicy::delete` ترجع `false` لمن له تاريخ مالي (الواجهة تخفي الزر؛ لا 500). **البديل المقصود: تجميد الحساب** الذي يحفظ التاريخ للتدقيق.
* اختبار يثبّت القائمة مقابل DDL الفعلي: إضافة جدول مالي جديد بـCASCADE على `users` دون إدراجه في الحارس **تُفشل الاختبار**.
* العلاقات التي يجب أن تبقى `RESTRICT` (برامج الرعاية، الداعمون، العملات، عناصر المتجر، قواعد المكافآت) مثبتة باختبار كذلك. مرفوض: تغيير أي قاعدة حذف بلا اختبار.

## 11) أنواع المال

لا يوجد عمود `float`/`double`/`decimal` في المخطط كله: كل المبالغ **أعداد صحيحة بالوحدة الصغرى** (`bigint unsigned` للأرصدة والمبالغ، `bigint` الموقَّع لدفتر العملات لأن الإنفاق سالب). اختبار ساكن يفشل إن ظهر عمود عشري عائم باسم مالي. فرق مهم على MySQL: نقص `UNSIGNED` تحت الصفر يرمي خطأ (على SQLite يمرّ بصمت)؛ الخدمات تفحص الرصيد قبل الخصم أصلًا.

## 12) تدقيق EXPLAIN والفهارس

### 12.1 ما أُنجز بلا خادم
* لا فهرس مفقود يخدم مفتاحًا أجنبيًا (يفحصه المحلّل لكل المفاتيح).
* **أربعة فهارس زائدة** (مغطّاة ببادئة فهرس آخر) رُصدت ولم تُحذف عمدًا: `puzzle_attempts` ×2، `device_sightings`، `notifications`. حذفها يوفّر كتابة صغيرة لكنه يغيّر خطط التنفيذ بلا قياس؛ تُراجَع بعد EXPLAIN على بيانات حقيقية.
* لم يُضف أي فهرس جديد في E31.

### 12.2 الإجراء المحلي (**PENDING USER-LOCAL MYSQL VALIDATION**)
بعد النقل (أو `php artisan performance:seed` على قاعدة اختبار):

```powershell
$env:MYSQL_PWD = '<كلمة-المرور>'
Get-Content tools\e31\explain-audit.sql -Raw -Encoding UTF8 | mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform
Remove-Item Env:\MYSQL_PWD
```

الملف يحوي 17 استعلام `EXPLAIN` للمسارات الساخنة (الدردشة، بحث الأحجيات واللاعبين، الرائج، قفل المحفظة، الدفتر، الإشعارات، صدارة المنافسة، أعمال المجتمع، السلاسل، التقييمات، المتابعات، الداعمون، الاستبدال، الجلسات) وبجانب كل منها الفهرس المتوقع. أرسل لي أي سطر ظهر فيه `type=ALL` أو `Using filesort/temporary` بعدد صفوف كبير. مراقبة معلنة: استعلام سجل العملات (07) قد يُظهر `filesort` لأن الفهرس `(user_id, type)`؛ لا فهرس قبل القياس.

## 13) فروق SQLite ↔ MySQL التي تهمك

| الموضوع | SQLite | MySQL |
|---|---|---|
| حالة الأحرف في `=`/`LIKE`/الفرادة | حساسة للحالة (`LIKE` لاتيني غير حساس) | **غير حساسة للحالة وللتشكيل** (`utf8mb4_unicode_ci`) |
| `UNSIGNED` | لا يُفرض | يُفرض (نقص تحت الصفر = خطأ) |
| أنواع التجميعات (`SUM`, `COUNT`) | أعداد | قد تعود **نصوصًا/DECIMAL**: اجعل التحويل `(int)` صريحًا في أي كود جديد |
| `TIMESTAMP` | نص | نطاق **1970–2038**؛ تاريخ خارجه يُرفض (فحص قبل النقل) |
| `0000-00-00` | يُقبل | مرفوض في الوضع الصارم |
| طول `varchar` | لا يُفرض | يُفرض (رفض لا اقتطاع) |
| القيد الفريد وNULL | NULL لا يتساوى بـNULL | كذلك (مثبت: `sponsorship_contributions(provider, external_reference)` يقبل صفوفًا بلا مرجع) |
| `CHECK` | يعمل | يُفرض من 8.0.16 (قيد `community_ratings_range_check` يُضاف على MySQL فقط؛ التحقق الخدمي هو الحارس على SQLite) |
| DDL داخل معاملة | قابل للتراجع | **يلتزم ضمنيًا** (`ALTER`/`CREATE`): لا ترحيل بمنطق بيانات داخل معاملة تتوقع تراجعه |
| كلمات محجوزة | — | `rank`, `key`, `group`... أهّلها باسم الجدول في أي SQL خام |
| قفل الصفوف | قفل قاعدة عند الكتابة | أقفال صفوف وفجوات: ممكن 1213/1205 تحت الحمل (القسم 7) |

## 14) الطوابير والجلسات والكاش على MySQL

* جداول `jobs`, `sessions`, `cache` تعمل على MySQL كما هي، لكنها **أكثر الجداول كتابة**: ستنافس الجداول المالية على الاتصال والأقفال. مقبول لمشروع صغير/متوسط.
* **توصية قبل النمو:** Redis للجلسات والكاش والطوابير (`SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`) مع `after_commit => true` للطابور (القسم 7.3). تغيير بنية تحتية، لا يُنفَّذ في E31.
* `DB_QUEUE_RETRY_AFTER` (افتراضي 90) يجب أن يظل أكبر من أقصى timeout لأي مهمة.

## 15) `production:check` و`/ready`

* `DB_CONNECTION=sqlite` **يفشل** `production:check` بالإنتاج برسالة صريحة. تجاوز واعٍ ومؤقت: `OPS_ALLOW_SQLITE_PRODUCTION=true` (يتحول إلى تحذير) — لا يُنصح به.
* على MySQL يفحص: النسخة (≥ 8.0.16)، الوضع الصارم، الترميز، المحرك، منطقة الجلسة، `max_allowed_packet`. لا يطبع مضيفًا ولا اسم قاعدة ولا مسارًا ولا كلمة مرور.
* `/ready` (فحص جاهزية) يعمل بـ`select 1` + الكاش على أي سائق ولا يكشف السائق ولا المضيف ولا اسم القاعدة ولا المخطط (مثبت باختبار على SQLite، **MySQL: PENDING USER-LOCAL MYSQL VALIDATION**).

## 16) CI

مهمة `mysql-parity` في `.github/workflows/ci.yml`: خدمة MySQL 8 (بيانات اعتماد اختبار فقط، لا أسرار)، تشغّل على MySQL: `tests/Feature/DatabaseE31` ثم أهم مجموعات السلامة (محافظ/متجر/منافسة/بحث/مبدعون/تفاعل E29/رعاية/سلاسل) ثم `tests/Feature/PaymentsE32` ثم `db:parity --connection=mysql --runtime` ثم **مسبار التزامن الحقيقي** `tools/e32/concurrency-probe.php` (آخر خطوة لأنه يعيد بناء قاعدة الاختبار). مهمة SQLite الحالية باقية. لا نشر ولا migrate على بيئة حقيقية.

### مسبار التزامن الحقيقي (E32)
عمليتا PHP منفصلتان تلتزمان فعلًا على MySQL (لا معاملة اختبار واحدة): تحقق مزدوج، استرداد مزدوج (دفع+دفع، ودفع+مسار E30 المباشر)، منح مكافأة بالمفتاح المصدر نفسه، استثناء مُحقَن وسط التجسيد (تراجع كامل)، ثم المطابقة المالية. **يمسح قاعدة الاختبار (`migrate:fresh`)**، لذا يرفض `APP_ENV=production` وأي سائق غير MySQL وأي قاعدة لا ينتهي اسمها بـ`_test` أو `_testing` أو `_test_mysql`:
```powershell
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='<كلمة-مرور-قاعدة-الاختبار>'
php tools\e32\concurrency-probe.php --rounds=6      # يخرج 0 عند النجاح، 1 عند فشل فحص، 2 عند حاجز أمان
```

## 17) متغيرات البيئة الجديدة (كلها اختيارية)

| المتغير | الافتراضي | الوظيفة |
|---|---|---|
| `DB_ENGINE` | `InnoDB` | محرك الجداول على mysql/mariadb |
| `DB_TIMEZONE` | `+00:00` | منطقة جلسة القاعدة (يجب أن تطابق UTC) |
| `OPS_ALLOW_SQLITE_PRODUCTION` | `false` | يسمح بـSQLite بالإنتاج كتحذير بدل فشل (غير مستحسن) |
| `MIGRATION_TARGET_CONNECTION` | `mysql` | اتصال الهدف لأمر النقل |
| `MIGRATION_SQLITE_DATABASE` | — | مسار ملف SQLite المصدر |
| `MIGRATION_MYSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD` | — | تجاوزات الهدف إن أردت إبقاء `DB_*` على SQLite أثناء النقل (كلمة المرور من البيئة فقط) |
| `MIGRATION_BATCH_SIZE` | `1000` | صفوف لكل دفعة (500–2000) |
| `MIGRATION_MAX_BATCH_BYTES` | `1000000` | سقف حجم الدفعة التقريبي |

## 18) أعطال شائعة على Windows

| العَرَض | السبب والحل |
|---|---|
| `could not find driver` | فعّل `extension=pdo_mysql` في `php.ini` ثم أعد تشغيل الطرفية |
| `SQLSTATE[HY000] [2002] Connection refused` | الخادم لا يعمل أو المنفذ/المضيف خطأ (`127.0.0.1` أفضل من `localhost` على ويندوز) |
| `[HY000] [2054] The server requested authentication method unknown` | مستخدم قديم/عميل قديم: أنشئ المستخدم بـ`mysql_native_password` أو حدّث PHP/`mysqlnd` |
| `Specified key was too long` / `1059 Identifier name is too long` | ضبطه `MysqlSafeBlueprint`؛ إن ظهر فأرسل اسم الفهرس |
| `1553 Cannot drop index needed in a foreign key constraint` | ترحيل المحافظ؛ يعالجه ترحيل E31 الحامي. تأكد من تشغيل كل الترحيلات بالترتيب |
| `1062 Duplicate entry` أثناء النقل | تصادم حالة أحرف؛ يكشفه الفحص المسبق (وحّد القيم في المصدر) |
| `1205 Lock wait timeout exceeded` | معاملة طويلة أو متزامنة: أرسل السجل؛ لا ترفع المهلة قبل التحليل |
| `1213 Deadlock found` | تعيدها نقاط الدخول الأربع تلقائيًا؛ إن ظهرت للمستخدم أرسل السجل |
| اختبارات MySQL تفشل بـ«Connection refused» | لم يعمل الخادم أو بيانات `$env:DB_*` خطأ (ليست مشكلة كود) |
| `Option --configuration cannot be used more than once` | استعملت `php artisan test`؛ استعمل `php vendor/bin/pest --configuration=...` |

## 19) خلاصة ما يلزم منك لإغلاق E31

1. تشغيل `tools\e31\run-mysql-matrix.ps1` وإرسال المخرجات (PASS لكل مجموعة + `db:parity`).
2. تشغيل `--dry-run` ثم النقل على **نسخة** من بياناتك الحقيقية وإرسال التقرير.
3. تشغيل `explain-audit.sql` على القاعدة المنقولة وإرسال أي سطر مريب.

بدون هذه الثلاثة تبقى بنود MySQL التشغيلية **PENDING USER-LOCAL MYSQL VALIDATION**.

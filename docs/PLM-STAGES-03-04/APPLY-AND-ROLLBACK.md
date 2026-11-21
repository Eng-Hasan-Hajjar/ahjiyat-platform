# دليل التطبيق والتراجع — المرحلتان الثالثة والرابعة

أنت من ينسخ الملفات ويختبر ويرفع الوسائط ويتولى Git بنفسك. لا شيء هنا يجري على جهازك أو قاعدة بياناتك من طرفي، ولا يوجد commit ولا PR ولا نشر في هذا التسليم.

> المسارات أدناه أمثلة لبيئة Windows/XAMPP. عدّل `$P` إلى مجلد مشروعك.

## 0) قبل أن تبدأ

1. تأكد أن فرعك نظيف وأن رأس `main` لديك هو `282d89290733def2e00fa2116eda3a14c0e80f53` أو أنك تعرف الفرق عنه (راجع `STATUS-TABLE.md` للملفات المعدَّلة).
2. خذ نسخة احتياطية من قاعدة البيانات (انظر `docs/backup-restore.md` في مشروعك). مثال:

```powershell
& "C:\xampp\mysql\bin\mysqldump.exe" -u root -p --single-transaction YOUR_DB > "$HOME\backup_before_plm34.sql"
```

3. **إن كانت حزمة E48 (SEARCHABLE-SELECTS) مطبّقة عندك** (وهو الحال عادةً) فالملفان `app/Filament/Pages/CinematicBuilderPage.php` و`resources/views/filament/pages/cinematic-builder.blade.php` في الحزمة الأصلية **يكتبان فوق تعديلات E48**. استعمل بدلهما نسختي `AHJIYAT-PLM-STAGES-03-04-HOTFIX1.zip` (E48 + تعديلاتي) وفق `HOTFIX1-README.md`.
4. لا حاجة إلى `composer install` ولا `npm run build`: لم تُضَف حزم، والواجهة جزء من قوالب Blade.
5. لا يوجد migration جديد. لا تغيير في بنية قاعدة البيانات.

## 1) فك الحزمة والتحقق منها

```powershell
$P = "C:\xampp\htdocs\ahjiyat-platform"      # مجلد المشروع
$D = "$HOME\Downloads\AHJIYAT-PLM-STAGES-03-04-DELIVERY"
Expand-Archive -Path "$HOME\Downloads\AHJIYAT-PLM-STAGES-03-04-DELIVERY.zip" -DestinationPath $HOME\Downloads -Force

# تحقق من سلامة الملفات (SHA-256)
Get-Content "$D\SHA256SUMS.txt" | ForEach-Object {
  $h, $f = $_ -split '\s+', 2
  $actual = (Get-FileHash -Algorithm SHA256 (Join-Path $D $f)).Hash.ToLower()
  if ($actual -ne $h.ToLower()) { "MISMATCH: $f" }
}
# لا ناتج = كل الملفات سليمة
```

## 2) نسخ احتياطي للملفات التي ستُستبدل

```powershell
$B = "$HOME\plm34_backup"
foreach ($f in (Get-Content "$D\FILES-MODIFIED.txt")) {
  $dst = Join-Path $B $f
  New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
  Copy-Item (Join-Path $P $f) $dst -Force
}
```

## 3) نسخ الملفات

كل ملف في الحزمة كامل وعلى مساره الأصلي (لا فروقات ولا مقاطع).

```powershell
foreach ($f in ((Get-Content "$D\FILES-NEW.txt") + (Get-Content "$D\FILES-MODIFIED.txt"))) {
  $dst = Join-Path $P $f
  New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
  Copy-Item (Join-Path $D $f) $dst -Force
}
cd $P
php artisan optimize:clear
```

## 4) الاختبارات على جهازك

تأكد أن `phpunit.xml` يشير إلى قاعدة اختبار **منفصلة** (SQLite مثلًا) وليس قاعدة التطوير. ثم:

```powershell
php vendor/bin/pest tests/Feature/CinematicStories/CinPlm34EngineTest.php
php vendor/bin/pest tests/Feature/CinematicStories/CinPlm3ContentTest.php
php vendor/bin/pest tests/Feature/CinematicStories/CinPlm4ContentTest.php
php vendor/bin/pest tests/Feature/CinematicStories/CinPlm34MediaTableTest.php
php vendor/bin/pest tests/Feature/CinematicStories/CinInteractionTest.php
php vendor/bin/pest tests/Feature/CinematicStories
```

النتائج التي حصلتُ عليها في بيئتي مذكورة في `TESTS-AND-LIMITS.md`؛ ما لم أستطع إثباته مذكور هناك أيضًا.

## 5) إضافة المرحلتين إلى المسودة (إضافة فقط)

الأمر هو `cinematic:install-stage`. **افتراضيًا معاينة بلا أي كتابة.**

### 5.1 المعاينة (لا تكتب شيئًا)

```powershell
php artisan cinematic:install-stage 3
```

الناتج الفعلي (جرّبته على قاعدة معزولة فيها المرحلتان الأولى والثانية، ملخَّصًا):

```
معاينة فقط — لم يُكتب شيء في قاعدة البيانات.
المرحلة: 3 | القصة: unwritten-page | الحالة: ready
سيُضاف: 1 مرحلة | 7 فصول | 15 مشهدًا
  ربط معلّق seam_pending_stage2_end_approval: غير محلول — اعتماد نهاية المرحلة الثانية المنقّحة …
للتطبيق أعد التشغيل مع --apply.
```

الحالة `blocked` تعني تعارضًا (مفتاح مشهد مستعمل، أو مرحلة ثالثة ناقصة، أو لا توجد المرحلة الثانية، أو تشغيل الرابعة قبل الثالثة) وتُذكر الأسباب، ولا يُكتب شيء. مثلًا تشغيل `cinematic:install-stage 4` قبل الثالثة يعطي «الحالة: blocked» مع «المرحلة المطلوبة stage_3 غير موجودة في النسخة».

بعد `--apply` يظهر مثلًا: «أُضيفت المرحلة 3 كمسودة إلى: الصفحة التي لم تُكتب» ثم سطر الحالة «غير جاهزة للنشر (متوقع: …)» ثم قائمة الموانع. **قائمة الموانع تشمل المسودة كلها**، أي أيضًا ما في المرحلتين الأولى والثانية القائمتين (فيديوهات بانتظار الإنتاج، ونصوص مؤقتة بين `[[ ]]` في الثانية، وصور فحص ناقصة)؛ هذا سابق لعملي ولم أغيّره. ما يخص الثالثة والرابعة فيها هو المشاهد ذات المفاتيح `stage3_` و`stage4_` والشرط المعلّق على الوصلة.

### 5.2 التطبيق

```powershell
# إن كانت لديك مسودة مفتوحة للقصة:
php artisan cinematic:install-stage 3 --apply

# إن لم تكن (يُنشأ مسودة جديدة من النسخة المنشورة):
php artisan cinematic:install-stage 3 --apply --create-draft
```

ثم المرحلة الرابعة (تشترط وجود الثالثة ومشهدها `stage3_end` في المسودة نفسها):

```powershell
php artisan cinematic:install-stage 4
php artisan cinematic:install-stage 4 --apply
```

خصائص الأمر (مُثبتة بالاختبارات PLM3-02 وPLM3-02b وPLM4-02):
- يضيف مرحلة واحدة داخل **مسودة** فقط، في معاملة واحدة؛ لا يعدّل ولا يحذف مرحلة أو فصلًا أو مشهدًا موجودًا، ولا يربط وسائط، ولا ينشر، ولا يمس الجواهر ولا الخبرة ولا تقدّم اللاعبين.
- إعادة تشغيله لا تكرّر شيئًا: «المرحلة مضافة مسبقًا؛ لم يُعدَّل شيء».
- إن وُجدت المرحلة جزئيًا أو تعارضت مفاتيح مع مشاهد أخرى يتوقف **قبل أي كتابة** برسالة واضحة.
- في بيئة `production` يرفض ما لم تُضف `--yes-production`.
- `--story=slug` لقصة غير `unwritten-page`.
- الناتج بعد التطبيق يقول عمدًا إن المسودة «غير جاهزة للنشر» (وسائط بانتظار تسليم محمد + الوصلة المعلّقة). هذا متوقع.

### 5.3 حل الوصلة مع المرحلة الثانية (بعد قرارك)

لا تنفّذ هذه الخطوة قبل أن تقرر المسار في `SEAM-STAGE2-TO-STAGE3.md`.

عند التثبيت، إن كانت مشاهد الثانية المنقّحة موجودة لديك فعلًا:

```powershell
php artisan cinematic:install-stage 3 --apply --link=seam_pending_stage2_end_approval=KEY_OF_REAL_STAGE2_END_SCENE
php artisan cinematic:install-stage 4 --apply --link=seam_pending_stage2_photo_card=KEY_OF_REAL_FILE_CARD_SCENE
```

أو بعد التثبيت، من لوحة الإدارة: افتح المشهد `stage3_corridor_video` (والمشهد `stage4_photo_inspect` للرابعة) وفي الحقل «شرط الفتح: مفاتيح مشاهد يجب إنجازها قبل هذا المشهد» استبدل المفتاح المعلّق بمفتاح المشهد الحقيقي ثم احفظ.

الربط لا يغيّر شيئًا موجودًا: يعيد كتابة شرط الفتح للمشهد الجديد فقط (PLM3-03b).

## 6) ما بعد التطبيق (في لوحة الإدارة)

لوحة الإدارة ← الأحجيات ← «منشئ السلسلة السينمائية»:
- تظهر المرحلتان «المرحلة الثالثة: الساعة المتوقفة» و«المرحلة الرابعة: الأبواب الثلاثة» في المسودة.
- المعاينة: الأزرار موصوفة في `PREVIEW-GUIDE.md`.
- الوسائط: ترفعها يدويًا من محرّر المشهد حين يسلّمها محمد (انظر `MEDIA-TABLE-PRELIMINARY.csv`). لا تُربط وسائط تلقائيًا.
- النشر: لن يسمح الفاحص به قبل ربط الوصلة ورفع الفيديوهات وصور الفحص المطلوبة. لا تنشر قبل مراجعة النصوص الموسومة `editorial=proposal`.

## 7) التراجع

### أ) تراجع الملفات

```powershell
# أعد الملفات المعدَّلة من النسخة الاحتياطية (القسم 2)
foreach ($f in (Get-Content "$D\FILES-MODIFIED.txt")) { Copy-Item (Join-Path $B $f) (Join-Path $P $f) -Force }
# احذف الملفات الجديدة
foreach ($f in (Get-Content "$D\FILES-NEW.txt")) { Remove-Item (Join-Path $P $f) -Force }
php artisan optimize:clear
```

(أو `git checkout -- .` و`git clean` على الملفات المذكورة إن لم تكن قد أضفتها إلى Git بعد.)

### ب) تراجع المحتوى (قاعدة البيانات)

لا يوجد أمر تراجع تلقائي عمدًا. بما أن التثبيت يضيف إلى **مسودة** فقط:

1. **المسودة أُنشئت بـ `--create-draft` لهذا الغرض وحده**: في «منشئ السلسلة السينمائية» اضغط «حذف المسودة» (يؤكد ثم يحذفها بكل ما فيها؛ يرفض الحذف إن كان عليها تقدّم لاعبين، والمسودة لا تحمل تقدمًا).
2. **المسودة فيها تعديلات أخرى لك**: احذف المرحلة المضافة وحدها بزر «حذف» بجانبها (يحذف المرحلة بكل محتواها). احذف **الرابعة أولًا ثم الثالثة**. جرّبتُ هذا المسار على قاعدة SQLite معزولة: بعد تثبيت 3 و4 صار العدد (مراحل/فصول/مشاهد/حلول) 4/25/67/33، وبعد حذف الرابعة ثم الثالثة عاد بالضبط إلى 2/12/37/20 كما كان بعد المرحلتين الأولى والثانية، وأعاد تثبيت الثالثة بنجاح بلا تعارض. (جرّبته عبر الخدمة نفسها التي يستدعيها الزر، لا بالضغط على الزر في المتصفح.)
3. **المرحلة نُشرت بعد ذلك**: لا أداة تراجع في هذا التسليم. الخيار المتاح في اللوحة «سحب من النشر» (لا يحذف شيئًا)، أو إنشاء مسودة جديدة من نسخة سابقة ونشرها. **لم أختبر هذا المسار على بيانات حقيقية**؛ تأكد من النسخة الاحتياطية قبل النشر.

تقدّم اللاعبين لا يتأثر بالتثبيت نفسه: لا يمسه الأمر. اللاعب الذي بدأ على نسخة منشورة سابقة يبقى عليها إلى أن ينتقل صراحةً إلى النسخة الأحدث، فتُحمل إنجازاته المتطابقة فقط (نفس `stable_key` ونفس `logic_hash`) ولا يُحذف القديم.

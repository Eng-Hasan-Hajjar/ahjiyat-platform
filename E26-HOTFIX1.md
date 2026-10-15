# E26 Hotfix 1 — اختباران فقط (لا تغيير في كود التطبيق)

## ما الذي حدث
على جهازك (Windows) نجح 125 من 127 اختبارًا في `tests/Feature/SecurityE26`. فشل اختباران:

- `E26FilesTest` رقم 63 (dataset: `php-as-png-name`): `fopen(...\Temp\php754F.tmp): Failed to open stream: Invalid argument`
- `E26InputSecurityTest` رقم 34b: `finfo::file(...\Temp\e2655C0.tmp): Failed to open stream: Invalid argument`

## السبب (الأرجح)
الاختباران كانا يكتبان ملفًا مؤقتًا يحوي `<?php system($_GET['c']); ?>` وهو توقيع ويب-شل كلاسيكي.
ملفات الاختبار نفسها لم تُحجب لأن الرمز فيها مكتوب `\$_GET` (مُهرَّب)، أما الملف المؤقت وقت التشغيل فيحمل `$_GET` خامًا.
مضاد الفيروسات على ويندوز (Defender) يمنع فتح مثل هذا الملف، فيعيد PHP الخطأ `Invalid argument`.
الأدلة: بقية اختبارات الرفع (ملفات php/html/svg/phar بمحتوى غير ضار) نجحت على جهازك، والفشل اقتصر على الاختبارين اللذين يكتبان هذا التوقيع.
هذا استنتاج من النمط لا من لقطة Defender نفسها (للتأكد: Windows Security ← Protection history).

## الإصلاح
استبدال المحتوى بتعليق PHP غير ضار (`<?php // e26 test fixture ... ?>`). يظل ملف PHP يُستشَفّ كـ `text/x-php` وليس صورة، فالفحص الأمني نفسه لم يضعف:
الرفض ما زال يعتمد على قراءة المحتوى لا على الامتداد ولا على MIME المُعلَن.
وأُضيف تأكيد جديد في 34b: MIME المستشَفّ للملف المموَّه لا يبدأ بـ `image/`.

## الملفان المعدّلان
- `tests/Feature/SecurityE26/E26FilesTest.php`
- `tests/Feature/SecurityE26/E26InputSecurityTest.php`

## التطبيق (بلا أي أمر git)
1. فك `e26-hotfix1-tests.zip` فوق جذر المشروع (استبدال الملفين).
2. تحقق من البصمات إن أردت: `Get-FileHash -Algorithm SHA256 tests\Feature\SecurityE26\E26FilesTest.php` وقارن مع `e26-hotfix1-manifest-sha256.txt`.
3. شغّل مرة واحدة:

```
php artisan test tests/Feature/SecurityE26
```

المتوقع: 127 passed.

## نتائج بقية تشغيلاتك (لا إجراء مطلوب)
- Economy + Store + PuzzleController + PuzzleAttemptService: 133 passed.
- Auth + Profile + Rbac + OperationsE25: 220 passed و16 skipped (اختبارات سكربتات bash، تُتخطى على ويندوز بتصميمها).
- Social + Teams + Chat + Competitive: 300 passed.
- `composer audit` و`npm audit`: لم يعملا عندك بسبب انقطاع DNS/الشبكة وقتها (`curl error 6` / `ENOENT`) وليس بسبب المشروع. أعدهما عند توفر الإنترنت.

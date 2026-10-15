
# التراجع عن نشرة (Rollback)

> **تحديث E25 — تصحيحات مهمة.** المرجع الحالي: [release-engineering](release-engineering.md#5-التراجع-rollback) (مصفوفة التراجع) و[backup-disaster-recovery](backup-disaster-recovery.md). ما تغيّر عمّا يلي:
> - **`migrate:rollback` (وأخواته) محظورة آليًا بالإنتاج** (`DB::prohibitDestructiveCommands`)؛ التقدّم للأمام فقط (**forward-fix**) أو الاستعادة من نسخة. الاستثناء الواعي المؤقت `OPS_ALLOW_DESTRUCTIVE_COMMANDS=true` لعملية واحدة وبعد نسخة جديدة موثّقة، ولا يُضاف إلى `.env` الدائم. فقرة «`migrate:rollback --step=1`» أدناه **لا تنطبق على الإنتاج**.
> - تراجع الكود بإعادة نشر **الإصدار السابق المحفوظ** (مجلد/artifact بمعرّفه) ثم `optimize` + `queue:restart` + `reverb:restart` + إعادة تحميل PHP-FPM، لا بـ`git checkout` على الخادم الحي.
> - بعد التراجع: `release:smoke --expect-release=<المعرّف السابق>`.


## تحذير جوهري

`php artisan migrate:rollback` **ليست الحل التلقائي** بعد كل فشل. Migration قد تكون Data Migration فعلية - التراجع عنها قد يكون **غير قابل للعكس**. التراجع الآمن الوحيد المضمون: **استعادة من نسخة احتياطية موثوقة سابقة للنشرة**.

## تراجع الكود

```bash
git log --oneline -10
git checkout <commit-hash>   # أو git revert
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

## تراجع قاعدة البيانات - بحذر شديد

- لم تُشغِّل Migration جديدة إطلاقًا: تراجع الكود وحده كافٍ.
- Migration بنيوية بحتة (كل Migrations هذا المشروع حتى الآن من هذا النوع): `migrate:rollback --step=1` بعد نسخة احتياطية جديدة أولاً.
- نقلت/حوَّلت بيانات فعلية: **استعادة كاملة من نسخة احتياطية فقط**.

## تراجع الإعدادات

`.env` لا يُنشَر عبر Git أبدًا - احتفظ بنسخة سابقة قبل أي تعديل يدوي مباشر على الخادم.

## تراجع الأصول المبنية

`public/build/` تُعاد توليدها بالكامل من `npm run build` لأي Commit.

## إعادة تشغيل الطوابير بعد أي تراجع

```bash
php artisan queue:restart
```

الجدولة لا تحتاج إعادة تشغيل - تُقرأ من جديد بكل تشغيل Cron.


# التراجع عن نشرة (Rollback)

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

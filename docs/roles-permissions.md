# نظام الأدوار والصلاحيات (RBAC)

## البنية المعمارية




مبني فوق `spatie/laravel-permission`. `Role` نموذج مخصَّص (`App\Models\Role`) يمتد نموذج Spatie الأساسي فقط لإضافة Metadata عرضية (تسمية عربية، وصف، is_system، لون، ترتيب).

## Role مقابل Permission

- **Permission**: قدرة تقنية واحدة دقيقة (`puzzles.view`). لا يُنشئها Admin يدويًا أبدًا - فقط موجودة بـ`config/permissions.php`.
- **Role**: مجموعة Permissions باسم عربي مفهوم. هذا ما يديره Admin فعليًا من الواجهة.
- **Direct Permission**: صلاحية مُعطاة لمستخدم واحد مباشرة، خارج أي دور - استثناء نادر.

## Super Admin

دور نظامي (`super-admin`) يتجاوز كل فحص صلاحية عبر `Gate::before()` بـ`AppServiceProvider` - Server-side دائمًا. لا يحتاج صلاحيات مُسنَدة فعليًا (الـBypass كافٍ).

**الحماية عبر AuthorizationSafetyService**: لا حذف آخر Super Admin، لا سحب دوره منه، لا منح super-admin من غير Super Admin، لا تعديل صلاحيات Super Admin من طرف Admin عادي.

## الأدوار النظامية الافتراضية

| الدور      | admin.access |
| --------------- | :----------: |
| super-admin     | ✅ (Bypass) |
| administrator   |      ✅      |
| content-manager |      ✅      |
| moderator       |      ✅      |
| support         |      ✅      |
| player          |      ❌      |

## كيف تُضاف صلاحية جديدة (بدون Migration)

1. سطر بـ`config/permissions.php`.
2. `php artisan permissions:sync`.
3. تظهر تلقائيًا بمصفوفة الصلاحيات - بلا كود إضافي.

## Cache

Spatie تخزّن كل الصلاحيات/الأدوار بـCache واحدة (`PermissionRegistrar`) تُبطَل تلقائيًا عند أي تغيير - سلوك الحزمة الافتراضي، لا Cache مخصَّصة فوقها.

## ترحيل النظام القديم

`users.role` أُبقي كـMرجع/Rollback فقط. الترحيل:

```bash
php artisan rbac:migrate-legacy-roles
```

Idempotent. **Bootstrap Safety**: إن لم يوجد Super Admin، يُرقَّى أقدم `admin` قديم (أدنى id) تلقائيًا - لا تخمين ببريد إلكتروني.

## التركيب

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

ثم غيّر بـ`config/permission.php`: `'role' => App\Models\Role::class`. ثم:

```bash
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder
```

## غير منفَّذ عمدًا

- Bulk Role Assignment / Role Duplication - مؤجَّلة لمهمة صغيرة لاحقة.
- Resource-level/Tenant Scoping - RBAC عام (Global) فقط بهذه المرحلة.
- Invitation System.

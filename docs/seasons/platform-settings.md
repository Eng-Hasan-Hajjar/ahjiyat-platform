
# نظام إعدادات المنصة (Platform Settings)

## البنية المعمارية

جدول واحد عام (`platform_settings`): `group` + `key` + `value` + `type` + `updated_by`، بقيد فريد على `(group, key)`. **لا عمود مخصَّص لكل إعداد** — إضافة إعداد جديد لاحقًا لا تحتاج Migration أبدًا.

القيم الافتراضية لكل إعداد تعيش حصرًا في `config/platform.php`. قاعدة البيانات تخزّن **فقط ما تجاوز به Admin الافتراضي** — صف غائب = استخدم الافتراضي تلقائيًا. المنصة تعمل بشكل صحيح تمامًا حتى لو كان جدول `platform_settings` فارغًا أو غير موجود بعد (الـService يمسك أي استثناء DB بأمان).

## القراءة والكتابة — حصرًا عبر `PlatformSettingsService`

```php
$settings = app(App\Services\PlatformSettingsService::class);

$settings->get('general', 'site_name');
$settings->getGroup('appearance');
$settings->set('general', 'site_name', 'قيمة', $adminUser);
$settings->setMany('home', ['show_categories' => true], $adminUser);
$settings->forgetCache();
```

## الأنواع المدعومة

`string` | `text` | `boolean` | `integer` | `float` | `color` | `url` | `image` | `json` — النوع يُحدَّد دائمًا من `config/platform.php`.

## الـCache

مصفوفة واحدة شاملة (`platform_settings.all`) بـ`Cache::rememberForever()` — استعلام واحد لكل الإعدادات، ليس لكل مفتاح. أي `set()`/`setMany()` يستدعي `forgetCache()` فورًا.

## كيف تُضاف Setting جديدة (بدون Migration)

1. سطر جديد بـ`config/platform.php`.
2. حقل Filament مطابق بـ`PlatformSettingsPage.php`.
3. استخدامها عبر `$settings->get('group', 'key')`.

## ما لا يُخزَّن هنا إطلاقًا

كلمات سر DB، مفاتيح API، أسرار الدفع، `APP_KEY` — تبقى بـ`.env` دائمًا.

## Design Tokens

طبقة `:root { --color-primary: ...; }` بـ`app.css` بقيم افتراضية مطابقة للهوية الحالية. القيم الفعلية تُحقَن عبر `<style>` بـ`<head>` — تتغلَّب حسب الـCascade، بلا `npm run build` عند تغيير لون.

## أولوية الثيم

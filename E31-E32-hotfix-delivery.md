# E31/E32 CLOSURE HOTFIX — MYSQL CRITICAL REGRESSION

الأساس: `main` عند `67bb629b7dc84a306ee78f295be9e4a773b64b27` — تشغيل GitHub Actions #10.
لا commit ولا push ولا pull/rebase/reset نُفِّذت. لا E33.

## 1) أول اختبار فاشل
`Tests\Feature\EngagementE29\E29FollowTest > AA4-48: cursor pagination is stable (no repeats or gaps) even when something new is published between pages`
ملف `tests/Feature/EngagementE29/E29FollowTest.php` السطر 219. وهو الفشل الوحيد (1 فاشل / 5 متخطّاة / 878 ناجحًا) عند إعادة إنتاج أمر CI الحرج نفسه محليًا على MySQL 8 بنفس بيئة المهمة.

## 2) رسالة الفشل الدقيقة
```
FAILED  Tests\Feature\EngagementE29\E29FollowTest > AA4-48 …
Expecting [] not to be empty .
at tests/Feature/EngagementE29/E29FollowTest.php:219
  218▕ preg_match('/href="([^"]*cursor=[^"]+)"/', html_entity_decode($page1->getContent()), $m);
➜ 219▕ expect($m)->not->toBeEmpty();
```
لا SQLSTATE ولا استثناء قاعدة بيانات: لا يوجد رابط `href` يحمل `cursor=` في صفحة المتابَعين الأولى. لا فشل آخر لاحق (لا جذر مشترك).

## 3) السبب الجذري
`Paginator::$defaultView` و`Paginator::$defaultSimpleView` متغيّران ساكنان على مستوى العملية. Livewire يستبدلهما (`livewire::tailwind` و`livewire::simple-tailwind`) عند إقلاع أي مكوّن بـ`WithPagination` (جداول Filament)، ويُفترض أن يعيدهما في `destroy()`، لكنهما بقيا بعد `Livewire::test`.
`CompetitiveSecurityAdminTest` (اختبارات Filament لـ`ListCompetitiveEvents`) ينفَّذ في أمر CI الحرج قبل `EngagementE29`، فيرث `AA4-48` العرض `livewire::simple-tailwind` الذي يرسم أزرار `wire:click` بدل روابط `<a href="…?cursor=…">`. صفحة المتابَعين تستعمل `$works->links()` على `CursorPaginator` فيختفي الرابط الذي يبحث عنه الاختبار.

الدليل (تشغيل مؤقت بعد إدراج تفريغ تشخيصي ثم استرجاع الملف حرفيًا):
`defaultView=livewire::tailwind` · `defaultSimpleView=livewire::simple-tailwind`.
التضييق بالتقسيم: Economy/Store ← أخضر · Competitive+CompetitiveRewards ← يفشل · CompetitiveRewards ← أخضر · Competitive ← يفشل · Event+Leaderboard ← أخضر · Notifications ← أخضر · **SecurityAdmin ← يفشل** (كل منها مع E29FollowTest).

## 4) التصنيف
**H — خطأ عزل حالة في الاختبارات** (حالة ساكنة تتسرّب بين اختبارات في عملية واحدة؛ تعتمد على الترتيب).
ليس A/B/C/D/E/F/G، وليس I: لا تراجع في المنتج، ولا شيء خاص بـMySQL — ظهر في مهمة MySQL لأن هذا التركيب تحديدًا (Filament قبل E29) لا يُشغَّل في مهمة SQLite `validate`. في الإنتاج كل طلب في عملية مستقلة، فلا أثر.

## 5) الملفات المعدَّلة
| الملف | التغيير |
|---|---|
| `tests/TestCase.php` | تعديل: `restorePaginationViews()` تُستدعى في `refreshApplication()` **قبل** إقلاع التطبيق |
| `tests/Feature/DatabaseE31/E31TestIsolationTest.php` | جديد: 3 اختبارات انحدار (ISO-1/2/3) |

لا تغيير في كود التطبيق، ولا ترحيلات، ولا حزم، ولا تعديل لأي قيد (FK/unique/strict/lockForUpdate)، ولا لدلالات المحفظة/المكافآت/الرعاية. اختبار `AA4-48` نفسه لم يُمس.

## 6) الباتش الدقيق
الملف `e31-e32-hotfix-mysql-critical.patch` (يُطبَّق بـ`patch -p1` من جذر المشروع؛ تحقّقت أنه يُطبَّق نظيفًا على الأصل وينتج الملفين بنفس المجاميع). الملفان كاملين داخل `files/` مع `SHA256SUMS`.

`tests/TestCase.php` (الإضافات):
```php
use Illuminate\Pagination\Paginator;
…
private static ?array $paginationViews = null;

public static function restorePaginationViews(): void
{
    self::$paginationViews ??= [Paginator::$defaultView, Paginator::$defaultSimpleView];

    Paginator::defaultView(self::$paginationViews[0]);
    Paginator::defaultSimpleView(self::$paginationViews[1]);
}

protected function refreshApplication()
{
    self::restorePaginationViews();   // ← جديد، قبل parent::refreshApplication()

    parent::refreshApplication();
    …
```
الاستعادة قبل الإقلاع تُبقي لأي تعيين تفعله مزوّدات التطبيق نفسها الأولوية؛ القيم الأصلية تُلتقط مرة واحدة قبل أي اختبار في العملية.

## 7) نتيجة الاختبار المركَّز على MySQL
`CompetitiveSecurityAdminTest + E29FollowTest` (المركّب الذي يعيد الفشل): **35 ناجحًا** (1767 تأكيدًا، 46 ث) بعد أن كان 1 فاشل/34 ناجحًا.
`E31TestIsolationTest`: 3 ناجحة. ضبط سلبي (بإرجاع `TestCase.php` الأصلي مؤقتًا): ISO-2 وISO-3 تفشلان — أي أن الاختبار يحرس الإصلاح فعلًا. تحقّق من النقل إلى SQLite: 38 ناجحًا (SQLite + Isolation + SecurityAdmin + FollowTest).

## 8) المجموعة المتأثرة
`tests/Feature/EngagementE29` على MySQL: **139 ناجحًا** (690 تأكيدًا، 93 ث).

## 9) مصفوفة CI الحرجة على MySQL (الأمر نفسه، `phpunit.mysql.xml.dist`)
**5 متخطّاة، 879 ناجحًا** (8120 تأكيدًا، 396.75 ث، exit=0) — كانت 1 فاشل/878.
وأعدتُ خطوة CI «E31 on MySQL» لأن الاختبارات الجديدة ضمنها: **106 ناجحًا** (103 + 3).

## 10) PaymentsE32 على MySQL — التشغيل 1
**153 ناجحًا** (1966 تأكيدًا، 79.6 ث).

## 11) PaymentsE32 على MySQL — التشغيل 2
**153 ناجحًا** (1966 تأكيدًا، 76.2 ث).

## 12) `php artisan db:parity --connection=mysql --runtime`
**PASS**: 119 ترحيلًا → 717 جملة، أخطاء الترجمة 0، جداول 109؛ تحليل MySQL: فشل 0 · تحذير 0؛ مقارنة المخطط الحيّ بالمرجع: جوهري 0 · تحذير 0 · معلومة 0؛ MySQL 8.0 · utf8mb4 · strict=yes · كل الجداول InnoDB · REPEATABLE-READ.

## 13) مسبار التزامن `php tools/e32/concurrency-probe.php --rounds=4` (DB_CONNECTION=mysql، قاعدة `_test_mysql` مؤقتة)
**PASS — 34 فحصًا، 0 فشل** (MySQL 8.0.46):
- تحقق مزدوج: تحقّقت كل الدفعات مرة واحدة (4/4)، مساهمة واحدة لكل دفعة، صف تدقيق واحد لكل دفعة، التمويل = مجموع الدفعات المتحقَّقة بالضبط (4000)، لا deadlock (1213) ولا lock timeout (1205).
- استرداد مزدوج: 4/4 مستردة، كل مساهمة مستردة مرة واحدة فقط، تدقيق واحد لكل استرداد، لا خصم مزدوج (التمويل 4000 = المتحقَّق المتبقي)، الخاسر يتلقى رفضًا نظيفًا فقط.
- مفتاح مكافأة المصدر: صف مكافأة واحد لكل مفتاح، معاملة محفظة واحدة، محفظة واحدة لكل لاعب، الخاسر هو `1062` على `currency_transactions_idempotency_key_unique` فقط، لا ائتمان مزدوج (20 = 20).
- فشل مُحقَن: تراجع كامل، الدفعة بقيت `submitted` بلا ربط مساهمة ولا `verified_at`، لا ملف داعم يتيم، لا مكافأة ولا معاملة محفظة، ثم تحقق نظيف ناجح مرة واحدة.
- المطابقة المالية بعد السباقات: 0 فروق. لا حالة مالية نصف منشأة.

## 14) أي عائق متبقٍ
لا عائق معروف محليًا. المتبقي تأكيد CI على الـcommit الذي ستدفعه (الخطوات 3–6 في مهمة `mysql-parity` يجب أن تخضرّ بالتتابع: الحرجة ← PaymentsE32 ← parity ← المسبار). المسبار في CI يتطلب `DB_CONNECTION: mysql` وهو موجود في `ci.yml`.

## 15) هل E31 وE32 قابلان للإغلاق الآن؟
**نعم، بشرط تأكيد تشغيل CI التالي أخضر على الـcommit الذي يحوي هذا الباتش.** كل خطوات مهمة `mysql-parity` الخمس أعيد إنتاجها محليًا بالأمر نفسه على MySQL 8 وكلها خضراء، والسبب الجذري الوحيد كان عزل حالة اختبارات لا تغيّر المنتج.

---

## DELIVERY PACK
- `E31-E32-hotfix-delivery.md` — هذا الملف
- `e31-e32-hotfix-mysql-critical.patch` — الباتش
- `files/tests/TestCase.php`, `files/tests/Feature/DatabaseE31/E31TestIsolationTest.php` — الملفان كاملين
- `SHA256SUMS`
- `e31-e32-hotfix-delivery.zip` — الكل في أرشيف واحد

التطبيق: انسخ `files/` فوق جذر المشروع (أو `patch -p1 < e31-e32-hotfix-mysql-critical.patch`)، ثم ارفعه بالطريقة التي تفضّلها. الحزمة خارج المستودع عمدًا.

تشغيل محلي مقترح للتحقق السريع قبل الدفع (PowerShell، بعد ضبط متغيرات MySQL المؤقتة):
`php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/Competitive/CompetitiveSecurityAdminTest.php tests/Feature/EngagementE29/E29FollowTest.php tests/Feature/DatabaseE31/E31TestIsolationTest.php`

توقّفت هنا — لا E33.

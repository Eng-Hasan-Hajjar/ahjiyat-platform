# E31 + E32 — الإغلاق النهائي: تكافؤ MySQL ونقل بيانات فعلي وتحقق مالي

> **خارج المستودع عمدًا.** هذا الملف والـzip لا يُنسخان إلى المشروع (لا delivery-pack ولا patch ولا manifest داخل المستودع). المطلوب منك فقط نسخ **15 ملفًا** من الـzip فوق ملفاتك (القسم C)، بلا أوامر git هنا.
> الأساس: `af2821166705639aa9aa3461f14c7d0fce3ce4f8` (v32). لم يُلمس أي ترحيل تاريخي، ولا واجهة، ولا دلالات الجواهر/الرعاية/المبدعين، ولا E33.

---

## A) السبب الجذري (مصنَّف قبل أي تعديل)

| # | العَرَض | التصنيف (واحد فقط) | الجذر | الإصلاح |
|---|---|---|---|---|
| 1 | CI رقم 39087981658 (#9، `af28211`): `validate` ✅ و`mysql-parity` ❌ عند خطوة **E31 on MySQL…** (وكذلك v31). السجل نفسه يحتاج تسجيل دخول فلم أقرأه؛ أعدت إنتاج الخطوة نفسها على MySQL 8.0.46 بالإعداد ذاته (`root/ci-only-test-password`، قاعدة `ahjiyat_platform_test_mysql`) فظهر فشلان | **5. Test assumption problem** | (أ) **AA-6** كان يقارن بالاتصال المسمّى `sqlite` وهو غير مُهاجَر تحت إعداد MySQL → أصبح ينشئ ملفَّي SQLite مؤقتين ويقارن بينهما. (ب) **AD-1** كان يغيّر `database.default` داخل الاختبار ولا يعيده؛ `RefreshDatabase` يقرأ `connectionsToTransact()` عند الإنهاء فتبقى معاملة MySQL مفتوحة ويُصفَّر `$migrated` فيعلق `migrate:fresh` التالي على **metadata lock** (جمود داخل العملية نفسها) → غلاف `e31_with_default()` يعيد الاتصال الافتراضي دائمًا | اختبارات فقط |
| 2 | المصفوفة الحرجة على MySQL: 7 إخفاقات | 1 × **9. Domain integrity (انحدار E32 حقيقي)** + 6 × **6. SQLite-only behavior dependency** (في الاختبارات) | الانحدار: اختبار «لا مسارات دفع في المتجر» `StoreFrontendTest` يرى `support/*` ومسار الويبهوك كأنه «سطح دفع» للمتجر (يفشل على SQLite أيضًا؛ لم يُكتشف لأن E32 لم يُشغَّل مع المتجر). الباقي: اقتباس المعرّفات في نصوص SQL (`` ` `` مقابل `"`) ×4، وقيمة `attempt_number` خارج نطاق `TINYINT UNSIGNED` تحت الوضع الصارم ×2 | استثناء سطح E32 صراحةً + تطبيع الاقتباس + `((n-1)%250)+1` |
| 3 | PaymentsE32 على MySQL: إخفاق واحد | **5. Test assumption problem** | اختبار «لا `payment` في أي URI تحت `api/`» لم يُحدَّث بعد نقل الويبهوك إلى `/api/payments/webhooks/{provider}` | استثناء هذا المسار فقط |
| 4 | **النقل الفعلي** (تجربة حقيقية على نسخة واقعية): `Fingerprint mismatches: 2` → `Result: FAIL` على `puzzles` و`player_streaks` (الصفوف/FK/المال/AUTO_INCREMENT سليمة) | **8. Transfer-command issue** (عيب حقيقي في المنتج) | SQLite يخزّن عمود **DATE** كما كتبه Eloquent: `2026-10-18 00:00:00`، وMySQL يحفظ `2026-10-18` فتختلف البصمة رغم تطابق المحتوى دلاليًا. **كان سيُفشل نقل بياناتك الحقيقية كاذبًا**. (فروق JSON الظاهرة في الخام تُوحَّد أصلًا داخل `canonical()`.) | `SqliteToMysqlTransfer::canonical()` يقارن الجزء التاريخي لأعمدة DATE فقط، وDATETIME/TIMESTAMP تبقى حرفية |
| 5 | فجوة تغطية: `mysql-parity` لا يشغّل `PaymentsE32` ولا `EngagementE29` ولا أي سباق حقيقي | **1. Workflow/config problem** | تغطية CI أقل من متطلبات الإغلاق | 3 خطوات جديدة في `ci.yml` (القسم B) |

**ليس هناك:** إصلاح ترحيل (D2)، ولا تغيير تجميع/collation (G1)، ولا فهرس جديد (K3)، ولا تغيير في سياسة الاستبعاد.

---

## B) الملفات المتغيرة (15)

| الملف | النوع | ما تغيّر |
|---|---|---|
| `app/Services/Database/SqliteToMysqlTransfer.php` | **منتج** (+13 سطرًا) | `canonical()` يعالج فئة `date` عبر `dateOnly()` |
| `tests/Feature/DatabaseE31/E31TransferTest.php` | اختبار | T-7b: DATE/DATETIME/JSON canonical |
| `tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php` | اختبار | AA-6 وAD-1 (غلاف `e31_with_default`) |
| `tests/Feature/Store/StoreFrontendTest.php` | اختبار | استثناء سطح E32 صراحةً + حارس أن مسارات `store.*` بلا checkout/payment/webhook |
| `tests/Feature/DiscoveryE27/{E27DiscoverTest,E27SearchTest,E27PerformanceTest,E27SecurityTest,_support}.php` | اختبار ×5 | اقتباس المعرّفات + `attempt_number` ضمن النطاق |
| `tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php` | اختبار | AM15: تطبيع الاقتباس |
| `tests/Feature/PaymentsE32/E32RejectRefundAnonymousTest.php` | اختبار | استثناء `api/payments/webhooks/` |
| `tests/Feature/PaymentsE32/E32StaticSecurityTest.php` | اختبار | S11 (حواجز المسبار) وS12 (خطوات CI) |
| `.github/workflows/ci.yml` | **CI** (+12 سطرًا) | `EngagementE29` ضمن الحرجة + خطوة `PaymentsE32` + خطوة المسبار (`DB_CONNECTION: mysql`, `timeout-minutes: 10`) |
| `docs/mysql-production-e31.md` | وثيقة | بصمة DATE/JSON، وصف CI، المسبار |
| `tools/e32/concurrency-probe.php` | **أداة جديدة** | سباق حقيقي بعمليتي PHP على MySQL (القسم C-2) |

لا ترحيلات. لا `composer`/`npm`. لا ملفات إنتاج.

---

## C) الملفات والفروقات الدقيقة

**الطريقة (بلا git):** من جذر المشروع في PowerShell:
```powershell
Expand-Archive -Path "$HOME\Downloads\e31-e32-closure-files.zip" -DestinationPath . -Force
```
ثم تحقق من البصمات (يجب أن تتطابق مع الجدول):
```powershell
Get-FileHash -Algorithm SHA256 app\Services\Database\SqliteToMysqlTransfer.php, tools\e32\concurrency-probe.php, .github\workflows\ci.yml | Format-Table Hash, Path
```

### C-1) بصمات الملفات الـ15 (SHA-256)
```
b210888e529f6134c105962a537aee426f73dcaec15466dae33de94ba82a6285  app/Services/Database/SqliteToMysqlTransfer.php
73bab6c054fb5df141e691f1364b4c19d3621025a94315b1fece287f8e17df7d  tests/Feature/DatabaseE31/E31TransferTest.php
7464d79e6ea487f4b99027dd61145c4a424dec023e3e90390ac017a6b1743edc  tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php
9cd67f23147ec41647631ea50d8491f6d7bc14175560c04a7322d8e4c450eb2c  tests/Feature/Store/StoreFrontendTest.php
f9f618447d956ab5eea7790ef6322b24eae94123f0672e082b7bdffd14c0200b  tests/Feature/DiscoveryE27/E27DiscoverTest.php
9267a2cef2fd9b8ba077151b0c0b5c3a5b1606b5d6210e6fe685acff32889425  tests/Feature/DiscoveryE27/E27SearchTest.php
fe45e82e31df8700e7841c7089b3ca6392c3301d80c0dabc1547aec6b2c6cc51  tests/Feature/DiscoveryE27/_support.php
0b604bb98e76c1b2049d1086d57959945293ae0be831322c05e6961b9707ea52  tests/Feature/DiscoveryE27/E27SecurityTest.php
8d1ac63e847a910a910ecd87e8279cacfb29a67a7db6c1525580f5087fbfe2a8  tests/Feature/DiscoveryE27/E27PerformanceTest.php
53e4ed4847318a58f7ccc25f929f5540fc80c17e543280a1380f4ae5452587fe  tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php
8e7da4428e438b4f295ea6d66d8c45e08c25ebc421df10172789a699605e2966  tests/Feature/PaymentsE32/E32RejectRefundAnonymousTest.php
5f3276a2d99411f677efd6e543184b61d77279f43b713571a3622634d620e0bf  tests/Feature/PaymentsE32/E32StaticSecurityTest.php
83f607f0483708d137e15ada4a3394198c85610b510dbbc91a8275bfdaecf436  .github/workflows/ci.yml
86cfb071aeef5564c19cf7d4fab67d6c448e21841466931856987bc1cfe878fe  docs/mysql-production-e31.md
dec2682648d8ecdbd3e52b70dfc5d90adfd9197cdd3a04f0e4baf0ddf659d6fc  tools/e32/concurrency-probe.php
```

### C-2) الفروقات الدقيقة مقابل v32 (unified diff؛ للمراجعة فقط، النسخة المعتمدة هي الملفات الكاملة في الـzip)
```diff
--- a/app/Services/Database/SqliteToMysqlTransfer.php
+++ b/app/Services/Database/SqliteToMysqlTransfer.php
@@ -597,11 +597,24 @@
 
         return match ($class) {
             'int', 'bool', 'decimal', 'float' => self::number($value),
+            'date' => self::dateOnly($value),
             'binary' => 'b64:'.base64_encode((string) $value),
             default => (string) $value,
         };
     }
 
+    /**
+     * عمود DATE في MySQL يحمل التاريخ فقط، بينما يخزّن SQLite قيمة العمود كما كتبها Eloquent (إسناد date-cast يُكتب "Y-m-d H:i:s"،
+     * مثل "2026-10-18 00:00:00"). المحتوى متطابق دلاليًا فنقارن الجزء التاريخي لأعمدة DATE فقط؛ أعمدة DATETIME/TIMESTAMP تبقى حرفية بالكامل.
+     * (اكتُشف بنقل فعلي: بلا هذا التوحيد تفشل البصمة كاذبًا على puzzles.daily_puzzle_date وplayer_streaks.last_active_date.)
+     */
+    private static function dateOnly(mixed $value): string
+    {
+        $text = trim((string) $value);
+
+        return preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T].*)?$/s', $text, $m) === 1 ? $m[1] : $text;
+    }
+
     public static function number(mixed $value): string
     {
         if (is_bool($value)) {
--- a/tests/Feature/DatabaseE31/E31TransferTest.php
+++ b/tests/Feature/DatabaseE31/E31TransferTest.php
@@ -208,6 +208,21 @@
         ->and(SqliteToMysqlTransfer::number('007'))->toBe(SqliteToMysqlTransfer::number(7));
 });
 
+test('T-7b: DATE columns compare by date part (SQLite keeps "Y-m-d 00:00:00" from Eloquent, MySQL keeps "Y-m-d"), DATETIME stays byte-exact, JSON ignores key order and escaping', function () {
+    $c = fn ($v, $class, $json = false) => SqliteToMysqlTransfer::canonical($v, $class, $json);
+
+    expect($c('2026-10-18 00:00:00', 'date'))->toBe($c('2026-10-18', 'date'))->and($c('2026-10-18', 'date'))->toBe('2026-10-18')
+        ->and($c('2026-10-18T00:00:00.000000Z', 'date'))->toBe('2026-10-18')
+        ->and($c('2026-10-18 13:45:10', 'date'))->toBe('2026-10-18')
+        ->and($c('2026-10-19', 'date'))->not->toBe($c('2026-10-18 00:00:00', 'date'))
+        ->and($c(null, 'date'))->not->toBe($c('', 'date'))
+        ->and($c('2026-10-18 00:00:00', 'datetime'))->not->toBe($c('2026-10-18', 'datetime'))
+        ->and($c('2026-10-18 10:00:00', 'datetime'))->not->toBe($c('2026-10-18 11:00:00', 'datetime'))
+        // JSON: MySQL يعيد كتابة الـJSON (مسافات، ترتيب مفاتيح، \uXXXX و\/) والمحتوى واحد
+        ->and($c('{"image":"seasons\/a","t":"طول","x":0.25}', 'text', true))->toBe($c('{"x": 0.25, "t": "طول", "image": "seasons/a"}', 'text', true))
+        ->and($c('{"x":0.25}', 'text', true))->not->toBe($c('{"x":0.26}', 'text', true));
+});
+
 /** يفتح ملف المصدر باتصال عادي لتعديله (الخدمة نفسها تفتحه للقراءة فقط). */
 function e31_mutate_source(string $path, Closure $fn): void
 {
--- a/tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php
+++ b/tests/Feature/DatabaseE31/E31ParityAndGuardsTest.php
@@ -151,8 +151,10 @@
 });
 
 test('AA-6: comparing two live connections (--against) passes for identical schemas', function () {
+    // اتصالان مهاجَران بالكامل ومستقلان عن الاتصال الافتراضي: الاتصال المسمّى «sqlite» ليس قاعدة الاختبار المهاجَرة عند التشغيل على MySQL (phpunit.mysql.xml.dist).
     e31_new_sqlite('left');
-    [$code, $out] = e31_run('db:parity', ['--connection' => 'e31_left', '--against' => 'sqlite', '--json' => true]);
+    e31_new_sqlite('right');
+    [$code, $out] = e31_run('db:parity', ['--connection' => 'e31_left', '--against' => 'e31_right', '--json' => true]);
     $data = json_decode($out, true);
 
     expect($code)->toBe(0)->and($data['parity']['summary']['material'])->toBe(0);
@@ -278,17 +280,23 @@
 test('AD-1: SQLite fails production:check in production with an explicit message - and the override downgrades it to a warning', function () {
     e25_as_production();
     config(['app.debug' => false, 'app.url' => 'https://ahjiyat.example.com', 'operations.allow_sqlite_production' => false]);
-    [$code, $data] = e25_run('production:check');
-    $db = collect($data['checks'])->firstWhere(fn (array $c) => $c['setting'] === 'DB_CONNECTION' && $c['status'] === 'FAIL');
+    // الاتصال الافتراضي مؤقتًا = SQLite مهاجَرة صراحةً (على MySQL phpunit.mysql.xml.dist يكون الافتراضي mysql فلا يتولّد فشل SQLite أصلًا).
+    // يُعاد الافتراضي قبل انتهاء الاختبار دائمًا: RefreshDatabase يتراجع عن معاملته على config('database.default') عند الإنهاء، وتركه معدَّلًا يُبقي معاملة MySQL مفتوحة ويعلّق الاختبار التالي بقفل metadata.
+    e31_new_sqlite('prod');
+
+    e31_with_default('e31_prod', function () {
+        [$code, $data] = e25_run('production:check');
+        $db = collect($data['checks'])->firstWhere(fn (array $c) => $c['setting'] === 'DB_CONNECTION' && $c['status'] === 'FAIL');
+
+        expect($code)->toBe(1)
+            ->and($db)->not->toBeNull()
+            ->and($db['message'])->toContain('SQLite')->and($db['message'])->toContain('MySQL');
 
-    expect($code)->toBe(1)
-        ->and($db)->not->toBeNull()
-        ->and($db['message'])->toContain('SQLite')->and($db['message'])->toContain('MySQL');
+        config(['operations.allow_sqlite_production' => true]);
+        [, $data] = e25_run('production:check');
 
-    config(['operations.allow_sqlite_production' => true]);
-    [, $data] = e25_run('production:check');
-
-    expect(e25_statuses($data, 'DB_CONNECTION'))->not->toContain('FAIL')->and(e25_statuses($data, 'DB_CONNECTION'))->toContain('WARN');
+        expect(e25_statuses($data, 'DB_CONNECTION'))->not->toContain('FAIL')->and(e25_statuses($data, 'DB_CONNECTION'))->toContain('WARN');
+    });
 });
 
 test('AD-2: the production check output carries no database name, host or file path', function () {
--- a/tests/Feature/Store/StoreFrontendTest.php
+++ b/tests/Feature/Store/StoreFrontendTest.php
@@ -56,10 +56,19 @@
         ->assertDontSee(route('store.items.purchase', $item), false);
 });
 
-test('no payment/checkout/webhook routes exist anywhere in the application', function () {
-    $routeUris = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn ($route) => $route->uri());
+test('no payment/checkout/webhook routes exist anywhere in the application except the E32 sponsorship donation surface', function () {
+    // E32: المتجر والاقتصاد الداخلي (الجواهر) بلا دفع حقيقي. السطح الوحيد المسموح له بمسارات دفع هو دعم الرعاية بالتحويل اليدوي
+    // (يتحقق منه مراجع مالي) + لوحة إدارته + Webhook المزوّدين المستقبلي المغلق افتراضيًا. قائمة السماح صريحة وضيقة بالمسار لا بالكلمة.
+    $e32Surface = ['support/', 'payments/staff/', 'api/payments/webhooks/', 'admin/sponsorship-payments', 'admin/payment-settings'];
+
+    $routeUris = collect(\Illuminate\Support\Facades\Route::getRoutes())
+        ->map(fn ($route) => $route->uri())
+        ->reject(fn ($uri) => collect($e32Surface)->contains(fn ($prefix) => str_starts_with($uri, $prefix)));
 
     foreach (['checkout', 'payment', 'webhook'] as $forbidden) {
         expect($routeUris->contains(fn ($uri) => str_contains($uri, $forbidden)))->toBeFalse();
     }
+
+    // المتجر نفسه لا يحمل أي مسار دفع، حتى داخل قائمة السماح.
+    expect(collect(\Illuminate\Support\Facades\Route::getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'store.') && preg_match('/checkout|payment|webhook/', $route->uri()))->count())->toBe(0);
 });
\ No newline at end of file
--- a/tests/Feature/DiscoveryE27/E27DiscoverTest.php
+++ b/tests/Feature/DiscoveryE27/E27DiscoverTest.php
@@ -163,7 +163,7 @@
     });
 
     // عدا ما يفعله إطار الصفحة القائم لأي صفحة (E11/E12: صف مستوى اللاعب عند أول عرض للرأس).
-    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, '"player_progressions"'))))->toBe([]);
+    expect(array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, 'player_progressions'))))->toBe([]);
 });
 
 test('D11: personal sections never leak between viewers - one player\'s continue list is invisible to a guest and to another player', function () {
--- a/tests/Feature/DiscoveryE27/E27SearchTest.php
+++ b/tests/Feature/DiscoveryE27/E27SearchTest.php
@@ -336,7 +336,7 @@
     });
 
     // الكاش array والجلسة array: أي كتابة هنا خلل، عدا ما يفعله إطار الصفحة القائم لأي صفحة (E11/E12: إنشاء صف مستوى اللاعب عند أول عرض للرأس)، وليس من البحث.
-    $writes = array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, '"player_progressions"')));
+    $writes = array_values(array_filter($sql, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q) && ! str_contains($q, 'player_progressions')));
     expect($writes)->toBe([]);
 });
 
--- a/tests/Feature/DiscoveryE27/_support.php
+++ b/tests/Feature/DiscoveryE27/_support.php
@@ -36,7 +36,7 @@
         return (int) DB::table('puzzle_attempts')->insertGetId([
             'user_id' => $user instanceof User ? $user->id : $user,
             'puzzle_id' => $puzzle instanceof Puzzle ? $puzzle->id : $puzzle,
-            'attempt_number' => $number,
+            'attempt_number' => (($number - 1) % 250) + 1,   // عمود TINYINT UNSIGNED: MySQL الصارم يرفض > 255 (SQLite لا يفرض). التكرار مسموح: القيد الفريد يضم context NULL.
             'is_correct' => $correct,
             'used_hint' => false,
             'context_type' => $context,
--- a/tests/Feature/DiscoveryE27/E27SecurityTest.php
+++ b/tests/Feature/DiscoveryE27/E27SecurityTest.php
@@ -280,7 +280,7 @@
         e27_attempt(e27_user(['email_verified_at' => null]), $target, true, now()->subHour());
     }
     $bot = e27_user();
-    DB::table('puzzle_attempts')->insert(array_map(fn ($n) => ['user_id' => $bot->id, 'puzzle_id' => $target->id, 'attempt_number' => $n, 'is_correct' => false, 'used_hint' => false, 'context_type' => null, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()], range(1, 1000)));
+    DB::table('puzzle_attempts')->insert(array_map(fn ($n) => ['user_id' => $bot->id, 'puzzle_id' => $target->id, 'attempt_number' => (($n - 1) % 250) + 1, 'is_correct' => false, 'used_hint' => false, 'context_type' => null, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()], range(1, 1000)));
 
     expect(e27_trending()->puzzleScores())->toBe([]);
     $html = $this->get(route('discover.index'))->assertOk()->getContent();
--- a/tests/Feature/DiscoveryE27/E27PerformanceTest.php
+++ b/tests/Feature/DiscoveryE27/E27PerformanceTest.php
@@ -270,7 +270,7 @@
     DB::flushQueryLog();
     DB::enableQueryLog();
     e27_reco($long);
-    $history = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'group by "puzzle_id"'));
+    $history = collect(DB::getQueryLog())->first(fn ($q) => str_contains(str_replace('`', '"', $q['query']), 'group by "puzzle_id"'));
     DB::disableQueryLog();
 
     expect($history['query'])->toContain('limit 5');
--- a/tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php
+++ b/tests/Feature/CreatorSeriesE30/E30SeriesPerformanceTest.php
@@ -232,13 +232,14 @@
     $log = DB::getQueryLog();
     DB::disableQueryLog();
 
-    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "creator_series"'));
+    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains(str_replace('`', '"', $q['query']), 'from "creator_series"'));
     expect($titles)->toContain('نمط سلسلة واحد')->and($query)->not->toBeNull();
 
-    $where = substr($query['query'], (int) strpos($query['query'], ' from "'));
+    $sql = str_replace('`', '"', $query['query']);   // اقتباس المعرّفات يختلف بين المحرّكين (`x` على MySQL و"x" على SQLite)
+    $where = substr($sql, (int) strpos($sql, ' from "'));
     expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
-        ->and(str_contains($query['query'], 'creator_series_revisions'))->toBeFalse()   // لا ضمّ للنسخ
-        ->and(str_contains($query['query'], 'description'))->toBeFalse();
+        ->and(str_contains($sql, 'creator_series_revisions'))->toBeFalse()   // لا ضمّ للنسخ
+        ->and(str_contains($sql, 'description'))->toBeFalse();
 
     foreach ($query['bindings'] as $binding) {
         if (is_string($binding) && str_contains($binding, '%')) {
--- a/tests/Feature/PaymentsE32/E32RejectRefundAnonymousTest.php
+++ b/tests/Feature/PaymentsE32/E32RejectRefundAnonymousTest.php
@@ -241,7 +241,8 @@
     $this->actingAs($viewer)->get(route('players.show', $user))->assertOk()->assertDontSee('data-supporter-recognition', false);
 
     foreach (Route::getRoutes() as $route) {
-        if (str_starts_with($route->uri(), 'api/')) {
+        // الاستثناء الوحيد: Webhook المزوّدين المستقبلي (POST فقط، مغلق افتراضيًا، لا يعرض أي بيانات داعمين - انظر E32-S4/E32-WH).
+        if (str_starts_with($route->uri(), 'api/') && ! str_starts_with($route->uri(), 'api/payments/webhooks/')) {
             expect($route->uri())->not->toContain('support')->and($route->uri())->not->toContain('payment');
         }
     }
--- a/tests/Feature/PaymentsE32/E32StaticSecurityTest.php
+++ b/tests/Feature/PaymentsE32/E32StaticSecurityTest.php
@@ -187,3 +187,43 @@
         }
     }
 });
+
+test('E32-S11: the real-concurrency probe is a read-only-to-production tool - hard guards, disposable test database only, no secrets in output', function () {
+    $source = e32_file('tools/e32/concurrency-probe.php');
+
+    expect($source)->toContain("app()->environment('production')")
+        ->and($source)->toContain("['mysql', 'mariadb']")
+        ->and($source)->toContain('_(mysql|mariadb))?$/')
+        ->and($source)->toContain('migrate:fresh')
+        ->and($source)->toContain('exit(2)');
+
+    // الحارس يسبق أي كتابة: استدعاء probe_guard() الفعلي يأتي قبل migrate:fresh
+    expect(strpos($source, 'if (($error = probe_guard()) !== null)'))->toBeLessThan(strpos($source, "Artisan::call('migrate:fresh'"));
+
+    // لا يقرأ كلمة المرور ولا يطبع مضيفًا ولا رسائل SQL الخام
+    expect($source)->not->toContain(".password")->and($source)->not->toContain("['password']")->and($source)->not->toContain('.host')
+        ->and($source)->not->toContain('getTraceAsString');
+
+    // الاسم المسموح: _test | _testing | _test_mysql | _test_mariadb فقط
+    $pattern = '/(^|_)(test|testing)(_(mysql|mariadb))?$/';
+    foreach (['ahjiyat_platform_test_mysql', 'ahjiyat_platform_test', 'x_testing'] as $ok) {
+        expect(preg_match($pattern, $ok))->toBe(1);
+    }
+    foreach (['ahjiyat_platform', 'ahjiyat_platform_production', 'test_data', 'contest'] as $bad) {
+        expect(preg_match($pattern, $bad))->toBe(0);
+    }
+});
+
+test('E32-S12: CI runs the payment suite, the engagement suite and the real-concurrency probe against MySQL with test-only credentials', function () {
+    $ci = preg_replace('/^\s*#.*$/m', '', e32_file('.github/workflows/ci.yml'));
+
+    expect($ci)->toContain('--configuration=phpunit.mysql.xml.dist tests/Feature/PaymentsE32')
+        ->and($ci)->toContain('tests/Feature/EngagementE29')
+        ->and($ci)->toContain('php tools/e32/concurrency-probe.php')
+        ->and($ci)->not->toContain('secrets.');
+
+    // الخطوة تحمل DB_CONNECTION: mysql صراحةً (المسبار يرفض أي سائق غير MySQL) ولها مهلة
+    $position = strpos($ci, 'php tools/e32/concurrency-probe.php');
+    $window = substr($ci, max(0, $position - 220), 220);
+    expect($window)->toContain('DB_CONNECTION: mysql')->and($window)->toContain('timeout-minutes');
+});
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -161,8 +161,20 @@
             tests/Feature/CompetitiveRewards \
             tests/Feature/DiscoveryE27 \
             tests/Feature/CreatorE28 \
+            tests/Feature/EngagementE29 \
             tests/Feature/SponsorshipE30 \
             tests/Feature/CreatorSeriesE30
 
+      - name: E32 payments on MySQL (verify / refund / reconciliation / reward integration)
+        run: php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/PaymentsE32
+
       - name: Schema parity against the migrated MySQL database (read-only)
         run: php artisan db:parity --connection=mysql --runtime
+
+      # E32: سباق حقيقي بين عمليتي PHP على MySQL (تحقق مزدوج/استرداد مزدوج/مفتاح مكافأة/تراجع استثناء مُحقَن).
+      # يمسح قاعدة الاختبار المؤقتة (migrate:fresh) ويرفض أي قاعدة لا ينتهي اسمها بـ_test؛ لذا يأتي آخر خطوة بعد فحص المخطط.
+      - name: E32 real-concurrency probe on MySQL (two OS processes)
+        timeout-minutes: 10
+        env:
+          DB_CONNECTION: mysql
+        run: php tools/e32/concurrency-probe.php --rounds=4
--- a/docs/mysql-production-e31.md
+++ b/docs/mysql-production-e31.md
@@ -177,6 +177,7 @@
 ```
 
 * ينتهي بتقرير: المصدر/الهدف، عدد الجداول والصفوف المنقولة، الجداول المؤقتة المستثناة، فروق الصفوف، المخالفات (أيتام المفاتيح الأجنبية)، تطابق **بصمات المحتوى** لكل جدول، سلامة AUTO_INCREMENT، **تطابق مجاميع المال بالأعداد الصحيحة**، ثم `Result: PASS|FAIL`. الخروج 0 فقط عند PASS **ومصدر لم يتغير**.
+* **بصمة المحتوى** توحّد تمثيل القيم بين المحرّكين كي لا تُبلّغ عن فروق كاذبة: JSON يُقارَن بعد فكّه (ترتيب المفاتيح والمسافات و`\uXXXX`)، وأعمدة **DATE** تُقارَن بالجزء التاريخي فقط (يخزّن SQLite قيمة Eloquent كاملة «2026-10-18 00:00:00» بينما يحفظ MySQL «2026-10-18»)؛ أعمدة DATETIME/TIMESTAMP تُقارَن حرفيًا. (اكتُشف بنقل فعلي في إغلاق E31/E32.)
 * `--skip-fingerprint` أسرع وأضعف تحققًا (لا تستعمله للنقل النهائي). `--batch-size` بين 500 و2000. `--exclude=table1,table2` لجداول إضافية. `--json` و`--report`.
 * **التعافي بعد فشل جزئي:** قاعدة الهدف جديدة بالتصميم، فالأسلم إسقاطها وإعادة إنشائها وتشغيل `migrate`. وإلا: `--reset-target --confirm-database=<اسم قاعدة الهدف نفسه> --force --backup=...` (يفرغ جداول التطبيق فقط ولا يلمس `migrations`).
 * رمز الخروج: 0 نجاح/اكتمال dry-run، 1 فشل (حاجز/مشكلة/عدم تطابق)، 2 يلزم `--force`/`--backup`.
@@ -263,7 +264,14 @@
 
 ## 16) CI
 
-مهمة `mysql-parity` في `.github/workflows/ci.yml`: خدمة MySQL 8 (بيانات اعتماد اختبار فقط، لا أسرار)، تشغّل `tests/Feature/DatabaseE31` وأهم مجموعات السلامة على MySQL. مهمة SQLite الحالية باقية. لا نشر ولا migrate على بيئة حقيقية.
+مهمة `mysql-parity` في `.github/workflows/ci.yml`: خدمة MySQL 8 (بيانات اعتماد اختبار فقط، لا أسرار)، تشغّل على MySQL: `tests/Feature/DatabaseE31` ثم أهم مجموعات السلامة (محافظ/متجر/منافسة/بحث/مبدعون/تفاعل E29/رعاية/سلاسل) ثم `tests/Feature/PaymentsE32` ثم `db:parity --connection=mysql --runtime` ثم **مسبار التزامن الحقيقي** `tools/e32/concurrency-probe.php` (آخر خطوة لأنه يعيد بناء قاعدة الاختبار). مهمة SQLite الحالية باقية. لا نشر ولا migrate على بيئة حقيقية.
+
+### مسبار التزامن الحقيقي (E32)
+عمليتا PHP منفصلتان تلتزمان فعلًا على MySQL (لا معاملة اختبار واحدة): تحقق مزدوج، استرداد مزدوج (دفع+دفع، ودفع+مسار E30 المباشر)، منح مكافأة بالمفتاح المصدر نفسه، استثناء مُحقَن وسط التجسيد (تراجع كامل)، ثم المطابقة المالية. **يمسح قاعدة الاختبار (`migrate:fresh`)**، لذا يرفض `APP_ENV=production` وأي سائق غير MySQL وأي قاعدة لا ينتهي اسمها بـ`_test` أو `_testing` أو `_test_mysql`:
+```powershell
+$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='<كلمة-مرور-قاعدة-الاختبار>'
+php tools\e32\concurrency-probe.php --rounds=6      # يخرج 0 عند النجاح، 1 عند فشل فحص، 2 عند حاجز أمان
+```
 
 ## 17) متغيرات البيئة الجديدة (كلها اختيارية)
 
```

### C-3) الملف الجديد كاملًا: `tools/e32/concurrency-probe.php`
```php
<?php

/*
|--------------------------------------------------------------------------
| E32 closure - مسبار التزامن الحقيقي على MySQL (عمليتا PHP منفصلتان تتسابقان فعلًا)
|--------------------------------------------------------------------------
| لماذا؟ اختبارات Pest تعمل داخل معاملة RefreshDatabase في عملية واحدة، فلا تُنتج سباقًا حقيقيًا بين اتصالين.
| هذا السكربت يلتزم بالبيانات فعلًا ويشغّل عمليتين تبدآن في اللحظة نفسها:
|   J1  تحقق مزدوج من الطلب نفسه             ← مساهمة واحدة فقط وأثر واحد (تدقيق/إشعار/تمويل)
|   J2  استرداد مزدوج (دفع+دفع، ودفع+E30)    ← استرداد واحد، بلا deadlock، والصافي صحيح
|   J3  منح مكافأة بالمفتاح المصدر نفسه       ← منحة واحدة وحركة محفظة واحدة
|   J4  طلب بانتظار/مرسَل لا يموّل البرنامج     ← التمويل = المحقَّق فقط
|   J5  استثناء مُحقَن وسط التجسيد (مساهمة+تدقيق) ← تراجع كامل: لا مساهمة ولا داعم ولا أثر، ثم ينجح التحقق لاحقًا
|   R   المطابقة المالية بعد السباقات = لا فروق
|
| الأمان (حواجز صلبة):
|   - يرفض APP_ENV=production، ويرفض غير MySQL/MariaDB، ويرفض أي قاعدة لا ينتهي اسمها بـ _test أو _testing أو _test_mysql
|     (يشغّل migrate:fresh عليها ويمسح كل جداولها!). لا يلمس قاعدة العمل أبدًا.
|   - لا أسرار في المخرجات: أرقام وحالات ورموز أخطاء فقط.
|
| التشغيل (من جذر المشروع، PowerShell - انظر docs/mysql-production-e31.md):
|   $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='root'; $env:DB_PASSWORD='...'
|   php tools/e32/concurrency-probe.php            # يخرج 0 عند النجاح، 1 عند فشل أي فحص، 2 عند حاجز أمان
|   php tools/e32/concurrency-probe.php --rounds=8 # عدد جولات كل سباق (الافتراضي 6)
*/

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipRewardBonus;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SponsorshipRewardService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// بيئة مستقلة عن مخزن الكاش/الطابور المعرَّفين في .env (جداول الكاش تُحذف أثناء migrate:fresh)، وتُورَّث للعمليات العاملة.
foreach (['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'] as $envKey => $envValue) {
    putenv("{$envKey}={$envValue}");
    $_ENV[$envKey] = $_SERVER[$envKey] = $envValue;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $args[$key] = $value;
    }
}

// ───────────────────────────── عامل (عملية السباق) ─────────────────────────────
if (isset($args['worker'])) {
    exit(probe_worker($args));
}

// ───────────────────────────── الحواجز ─────────────────────────────
function probe_guard(): ?string
{
    if (app()->environment('production')) {
        return 'مرفوض: APP_ENV=production.';
    }

    $connection = (string) config('database.default');
    $driver = (string) config("database.connections.{$connection}.driver");
    $database = (string) config("database.connections.{$connection}.database");

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        return "مرفوض: هذا المسبار لـMySQL/MariaDB فقط (السائق الحالي: {$driver}). اضبط DB_CONNECTION=mysql.";
    }

    if (preg_match('/(^|_)(test|testing)(_(mysql|mariadb))?$/', $database) !== 1 || preg_match('/^[A-Za-z0-9_]{1,50}$/', $database) !== 1) {
        return 'مرفوض: اسم القاعدة لا ينتهي بـ _test أو _testing أو _test_mysql. هذا السكربت يمسح كل جداول القاعدة (migrate:fresh) فلا يُوجَّه لقاعدة العمل.';
    }

    return null;
}

/**
 * يطلق عمليات PHP منفصلة (واحدة لكل مواصفة) تنتظر اللحظة نفسها ثم تنفّذ معًا.
 *
 * @param  list<array{0: string, 1: list<string>}>  $specs  [kind, extra args]
 * @return list<array<string, mixed>>
 */
function probe_race(array $specs, float $leadSeconds = 3.0): array
{
    $at = microtime(true) + $leadSeconds;
    $procs = [];

    foreach ($specs as $i => [$kind, $extra]) {
        $cmd = array_merge([PHP_BINARY, __FILE__, "--worker={$kind}", "--at={$at}"], $extra);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null);
        $procs[$i] = [$process, $pipes];
    }

    $out = [];

    foreach ($procs as $i => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $line = '';
        foreach (explode("\n", (string) $stdout) as $candidate) {
            if (str_starts_with(trim($candidate), '{')) {
                $line = trim($candidate);
            }
        }
        $decoded = $line !== '' ? json_decode($line, true) : null;
        $out[$i] = is_array($decoded) ? $decoded : ['outcome' => 'worker_crash', 'detail' => substr(trim((string) $stderr), 0, 160)];
    }

    return $out;
}

function probe_worker(array $args): int
{
    $kind = (string) $args['worker'];
    $at = (float) ($args['at'] ?? 0);
    $result = ['outcome' => 'unknown'];

    try {
        $actor = User::query()->findOrFail((int) ($args['actor'] ?? 0));

        while (microtime(true) < $at) {
            usleep(200);
        }

        $started = microtime(true);

        if ($kind === 'verify') {
            $payment = SponsorshipPayment::query()->findOrFail((int) $args['payment']);
            $after = app(SponsorshipPaymentService::class)->verify($actor, $payment);
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'verify-fail') {
            // يحقن استثناءً بعد إدراج المساهمة وتحديث الطلب وقبل نهاية المعاملة (آخر خطوة: تدقيق contribution_materialized).
            app()->bind(\App\Services\OperationalAuditService::class, fn () => new class extends \App\Services\OperationalAuditService
            {
                public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
                {
                    if ($action === 'contribution_materialized') {
                        throw new RuntimeException('probe-injected-failure');
                    }

                    parent::log($action, $subject, $metadata, $actor);
                }
            });
            $payment = SponsorshipPayment::query()->findOrFail((int) $args['payment']);
            $after = app(SponsorshipPaymentService::class)->verify($actor, $payment);
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'refund-payment') {
            $payment = SponsorshipPayment::query()->findOrFail((int) $args['payment']);
            $after = app(SponsorshipPaymentService::class)->refund($actor, $payment, 'مسبار التزامن');
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'refund-contribution') {
            $contribution = SponsorshipContribution::query()->findOrFail((int) $args['contribution']);
            $after = app(ContributionService::class)->refund($actor, $contribution, 'مسبار التزامن (E30)');
            $result = ['outcome' => 'ok', 'status' => $after->status];
        } elseif ($kind === 'reward') {
            $user = User::query()->findOrFail((int) $args['user']);
            $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
            $method = new ReflectionMethod(SponsorshipRewardService::class, 'credit');
            $granted = $method->invoke(app(SponsorshipRewardService::class), $user, $currency, (int) $args['program'], (string) $args['key'], null, null, 100, 500, 5, 5, 1000);
            $result = ['outcome' => 'ok', 'granted' => (int) $granted];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $code = $e instanceof \Illuminate\Database\QueryException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $domain = $e instanceof \App\Services\Sponsorship\SponsorshipException || $e instanceof \App\Services\Payments\PaymentException;
        // لا نطبع رسائل SQL (قد تحمل قيم): اسم الفهرس المخالَف فقط لأخطاء 1062، ورسالة الاستثناء للاستثناءات الدومينية (نصوص عربية آمنة).
        $detail = '';
        if ($code === 1062 && preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1) {
            $detail = $m[1];
        } elseif ($domain) {
            $detail = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120);
        }
        $result = ['outcome' => $domain ? 'refused' : 'exception', 'class' => class_basename($e), 'mysql' => $code, 'detail' => $detail];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE)."\n";

    return 0;
}

// ───────────────────────────── المسبار الرئيسي ─────────────────────────────
if (($error = probe_guard()) !== null) {
    fwrite(STDERR, $error."\n");
    exit(2);
}

$rounds = max(2, min(20, (int) ($args['rounds'] ?? 6)));
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ').$name.($detail !== '' ? '  - '.$detail : '')."\n";
};

echo "== E32 concurrency probe (MySQL: ".DB::selectOne('select version() as v')->v.", rounds={$rounds}) ==\n";
echo "تجهيز قاعدة الاختبار المؤقتة (migrate:fresh)...\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);

$finance = User::factory()->create(['email' => 'probe-finance@concurrency.test']);
$finance->forceFill(['email_verified_at' => now()])->save();
$finance->syncRoles(['administrator']);

$programs = app(SponsorshipProgramService::class);
$program = $programs->activate($finance, $programs->create($finance, ['title' => 'برنامج مسبار التزامن', 'target_type' => 'platform', 'description' => 'اختبار تزامن مؤقت']));
$payments = app(SponsorshipPaymentService::class);

$newSubmitted = function (string $amount) use ($payments, $program): SponsorshipPayment {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    $payment = $payments->createRequest($user, $program, $amount, 'USD', false, null, 'manual', 'probe-'.$user->id);

    return $payments->markTransferred($user, $payment, 'مسبار');
};

// ── J4: غير المحقَّق لا يموّل ──
$awaiting = (function () use ($payments, $program) {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();

    return $payments->createRequest($user, $program, '77', 'USD', false, null, 'manual', 'probe-await-'.$user->id);
})();
$submittedOnly = $newSubmitted('88');
$totals = app(SupportTotals::class);
$check('J4 awaiting + submitted requests do not fund the program', $totals->programFunding($program->id) === 0, 'funding='.$totals->programFunding($program->id));

$isLockError = fn (array $r): bool => in_array($r['mysql'] ?? 0, [1213, 1205], true);
$crashes = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'worker_crash')->count();
$lockErrors = fn (array $rs): int => collect($rs)->filter($isLockError)->count();
$unexpected = fn (array $rs): int => collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'exception' && ! $isLockError($r) && ($r['mysql'] ?? 0) !== 1062)->count();
$refusals = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['outcome'] ?? '') === 'refused')->map(fn ($r) => ($r['class'] ?? '').':'.($r['detail'] ?? ''))->countBy()->all(), JSON_UNESCAPED_UNICODE);
$duplicateKeys = fn (array $rs): string => json_encode(collect($rs)->filter(fn ($r) => ($r['mysql'] ?? 0) === 1062)->map(fn ($r) => $r['detail'] ?? '')->countBy()->all(), JSON_UNESCAPED_UNICODE);

// ── J1: تحقق مزدوج ──
echo "\nJ1: تحقق مزدوج متزامن (عمليتان لكل طلب)\n";
$verifyIds = [];
$j1 = [];

for ($i = 0; $i < $rounds; $i++) {
    $payment = $newSubmitted('10');
    $verifyIds[] = $payment->id;
    $j1 = array_merge($j1, probe_race([['verify', ["--actor={$finance->id}", "--payment={$payment->id}"]], ['verify', ["--actor={$finance->id}", "--payment={$payment->id}"]]]));
}

$verifiedCount = SponsorshipPayment::query()->whereIn('id', $verifyIds)->where('status', 'verified')->count();
$linkedDistinct = SponsorshipPayment::query()->whereIn('id', $verifyIds)->whereNotNull('sponsorship_contribution_id')->distinct()->count('sponsorship_contribution_id');
$totalContribs = SponsorshipContribution::query()->where('sponsorship_program_id', $program->id)->count();
$auditVerified = DB::table('operational_audit_logs')->where('action', 'payment_verified')->count();
$winners = collect($j1)->filter(fn ($r) => ($r['outcome'] ?? '') === 'ok')->count();

$check('J1 no worker crash, no deadlock (1213) / lock timeout (1205)', $crashes($j1) === 0 && $lockErrors($j1) === 0, 'crash='.$crashes($j1).' lock-errors='.$lockErrors($j1));
$check('J1 no unexpected exception class (only clean refusals / idempotent results)', $unexpected($j1) === 0, 'unexpected='.$unexpected($j1).' clean-refusals='.$refusals($j1));
$check('J1 every raced payment is verified exactly once', $verifiedCount === $rounds, "verified={$verifiedCount}/{$rounds}");
$check('J1 exactly one contribution per payment (no duplicate, no orphan)', $linkedDistinct === $rounds && $totalContribs === $rounds, "contributions={$totalContribs} linked-distinct={$linkedDistinct} expected={$rounds}");
$check('J1 exactly one payment_verified audit row per payment', $auditVerified === $rounds, "audit={$auditVerified}/{$rounds}");
$check('J1 at least one winner per race', $winners >= $rounds, "ok-results={$winners}/".count($j1));
$verifiedMoney = (int) SponsorshipPayment::query()->where('sponsorship_program_id', $program->id)->where('status', 'verified')->sum('accounting_amount_minor');
$check('J1 funding equals verified payments money exactly (awaiting/submitted excluded)', $totals->programFunding($program->id) === $verifiedMoney && $verifiedMoney === $rounds * 1000, 'funding='.$totals->programFunding($program->id)." verified-payments={$verifiedMoney}");

// ── J2: استرداد مزدوج ──
echo "\nJ2: استرداد مزدوج متزامن (دفع+دفع، ثم دفع+E30 مباشر)\n";
$samePayments = [];
$mixedPayments = [];
$j2 = [];
$j2rounds = max(2, intdiv($rounds, 2));

for ($i = 0; $i < $j2rounds; $i++) {
    $payment = $newSubmitted('10');
    $payments->verify($finance, $payment->fresh());
    $samePayments[] = $payment->id;
    $j2 = array_merge($j2, probe_race([['refund-payment', ["--actor={$finance->id}", "--payment={$payment->id}"]], ['refund-payment', ["--actor={$finance->id}", "--payment={$payment->id}"]]]));
}

for ($i = 0; $i < $j2rounds; $i++) {
    $payment = $newSubmitted('10');
    $payments->verify($finance, $payment->fresh());
    $mixedPayments[] = $payment->id;
    $contribution = (int) SponsorshipPayment::query()->whereKey($payment->id)->value('sponsorship_contribution_id');
    $j2 = array_merge($j2, probe_race([['refund-payment', ["--actor={$finance->id}", "--payment={$payment->id}"]], ['refund-contribution', ["--actor={$finance->id}", "--contribution={$contribution}"]]]));
}

$raced = array_merge($samePayments, $mixedPayments);
$refundedPayments = SponsorshipPayment::query()->whereIn('id', $raced)->where('status', 'refunded')->count();
$refundedContribs = SponsorshipContribution::query()->whereIn('id', SponsorshipPayment::query()->whereIn('id', $raced)->pluck('sponsorship_contribution_id'))->where('status', 'refunded')->count();
$auditRefunds = DB::table('operational_audit_logs')->where('action', 'payment_refunded')->count();

$check('J2 no worker crash, no deadlock (1213) / lock timeout (1205) in refund races', $crashes($j2) === 0 && $lockErrors($j2) === 0, 'crash='.$crashes($j2).' lock-errors='.$lockErrors($j2));
$check('J2 no unexpected exception class (domain refusals of the loser are accepted and listed)', $unexpected($j2) === 0, 'unexpected='.$unexpected($j2).' clean-refusals='.$refusals($j2));
$check('J2 every double-refunded payment ends refunded', $refundedPayments === count($raced), "refunded-payments={$refundedPayments}/".count($raced));
$check('J2 every matching contribution ends refunded exactly once', $refundedContribs === count($raced), "refunded-contributions={$refundedContribs}/".count($raced));
$check('J2 exactly one payment_refunded audit row per refunded payment', $auditRefunds === count($raced), "audit={$auditRefunds}/".count($raced));
$verifiedMoney = (int) SponsorshipPayment::query()->where('sponsorship_program_id', $program->id)->where('status', 'verified')->sum('accounting_amount_minor');
$check('J2 funding after refunds equals still-verified payments money exactly (no double deduction)', $totals->programFunding($program->id) === $verifiedMoney, 'funding='.$totals->programFunding($program->id)." verified-payments={$verifiedMoney}");

// ── J5: استثناء مُحقَن وسط التجسيد ──
echo "\nJ5: استثناء مُحقَن أثناء تجسيد المساهمة (يجب ألا تبقى حالة مالية نصف منشأة)\n";
$injected = [];
$fundingBefore = $totals->programFunding($program->id);
$contribBefore = SponsorshipContribution::query()->count();
$profilesBefore = DB::table('supporter_profiles')->count();
$auditBefore = DB::table('operational_audit_logs')->where('action', 'payment_verified')->count();
$bonusBefore = SponsorshipRewardBonus::query()->count();
$txBefore = DB::table('currency_transactions')->count();

for ($i = 0; $i < max(2, intdiv($rounds, 2)); $i++) {
    $payment = $newSubmitted('10');
    $profilesWithPayer = DB::table('supporter_profiles')->count();
    $res = probe_race([['verify-fail', ["--actor={$finance->id}", "--payment={$payment->id}"]]], 1.0);
    $injected[] = $payment->id;
    $row = SponsorshipPayment::query()->find($payment->id);
    $stateOk = $row->status === 'submitted' && $row->sponsorship_contribution_id === null && $row->verified_at === null && $row->accounting_amount_minor === null;
    $check("J5 injected failure #{$i}: worker failed as injected (domain-neutral RuntimeException)", ($res[0]['class'] ?? '') === 'RuntimeException' && ($res[0]['outcome'] ?? '') === 'exception', 'worker='.json_encode($res[0], JSON_UNESCAPED_UNICODE));
    $check("J5 injected failure #{$i}: payment untouched (still submitted, no contribution link, no verified_at, no accounting amounts)", $stateOk, 'status='.$row->status);
    $check("J5 injected failure #{$i}: no supporter profile was created by the rolled-back attempt", DB::table('supporter_profiles')->count() === $profilesWithPayer, 'profiles='.DB::table('supporter_profiles')->count()." expected={$profilesWithPayer}");

    // بعد التراجع يجب أن ينجح التحقق العادي (لا قفل عالق ولا حالة يتيمة)
    $after = $payments->verify($finance, $payment->fresh());
    $check("J5 injected failure #{$i}: a clean verify afterwards succeeds exactly once", $after->status === 'verified' && SponsorshipContribution::query()->where('reference', $payment->reference)->count() === 1);
}

$verifiedAfterInjection = count($injected);
$check('J5 no orphan contributions: every contribution has exactly one payment', DB::table('sponsorship_contributions as c')->leftJoin('sponsorship_payments as p', 'p.sponsorship_contribution_id', '=', 'c.id')->whereNull('p.id')->where('c.provider', 'manual')->count() === 0, 'orphans='.DB::table('sponsorship_contributions as c')->leftJoin('sponsorship_payments as p', 'p.sponsorship_contribution_id', '=', 'c.id')->whereNull('p.id')->where('c.provider', 'manual')->count());
$check('J5 contributions grew only by the clean verifies (no leak from failed attempts)', SponsorshipContribution::query()->count() === $contribBefore + $verifiedAfterInjection, 'contributions='.SponsorshipContribution::query()->count().' expected='.($contribBefore + $verifiedAfterInjection));
$check('J5 payment_verified audit rows grew only by the clean verifies', DB::table('operational_audit_logs')->where('action', 'payment_verified')->count() === $auditBefore + $verifiedAfterInjection);
$check('J5 funding grew by exactly the clean verifies money', $totals->programFunding($program->id) === $fundingBefore + $verifiedAfterInjection * 1000, 'funding='.$totals->programFunding($program->id).' expected='.($fundingBefore + $verifiedAfterInjection * 1000));
$check('J5 no reward bonus or wallet transaction was created by failed attempts', SponsorshipRewardBonus::query()->count() === $bonusBefore && DB::table('currency_transactions')->count() === $txBefore, 'bonus='.SponsorshipRewardBonus::query()->count()."/{$bonusBefore} tx=".DB::table('currency_transactions')->count()."/{$txBefore}");

// ── J3: مكافأة بالمفتاح المصدر نفسه ──
echo "\nJ3: منح مكافأة بالمفتاح المصدر نفسه (عمليتان، مستخدم جديد بلا محفظة في كل جولة)\n";
$j3 = [];
$players = [];

for ($i = 0; $i < $rounds; $i++) {
    $player = User::factory()->create();
    $player->forceFill(['email_verified_at' => now()])->save();
    $players[] = $player->id;
    $key = 'probe:'.$i;
    $one = ["--actor={$finance->id}", "--user={$player->id}", "--program={$program->id}", "--key={$key}"];
    $j3 = array_merge($j3, probe_race([['reward', $one], ['reward', $one]]));
}

$bonusRows = SponsorshipRewardBonus::query()->where('source_key', 'like', 'sponsor-bonus:probe:%')->count();
$distinctKeys = SponsorshipRewardBonus::query()->where('source_key', 'like', 'sponsor-bonus:probe:%')->distinct()->count('source_key');
$txRows = DB::table('currency_transactions')->whereIn('user_id', $players)->where('idempotency_key', 'like', 'sponsor-bonus:probe:%')->count();
$walletRows = DB::table('wallets')->whereIn('user_id', $players)->count();
$pendingSum = (int) DB::table('wallets')->whereIn('user_id', $players)->sum('pending_balance');
$grantedSum = collect($j3)->sum(fn ($r) => (int) ($r['granted'] ?? 0));
$lostRace1062 = collect($j3)->filter(fn ($r) => ($r['mysql'] ?? 0) === 1062)->count();

$check('J3 no worker crash and no deadlock / lock timeout', $crashes($j3) === 0 && $lockErrors($j3) === 0, 'crash='.$crashes($j3).' lock-errors='.$lockErrors($j3));
$check('J3 no unexpected exception class (duplicate-key loser, 1062, is the only accepted loser)', $unexpected($j3) === 0, 'unexpected='.$unexpected($j3)." loser-1062={$lostRace1062} keys=".$duplicateKeys($j3));
$check('J3 one bonus row per source_key (UNIQUE holds under a real race)', $bonusRows === $rounds && $distinctKeys === $rounds, "rows={$bonusRows}/{$rounds}");
$check('J3 exactly one wallet transaction per source_key', $txRows === $rounds, "transactions={$txRows}/{$rounds}");
$check('J3 exactly one wallet per player (wallet-creation race safe)', $walletRows === $rounds, "wallets={$walletRows}/{$rounds}");
$check('J3 pending balance equals one bonus per key (no double credit)', $pendingSum === $rounds * 5, "pending-sum={$pendingSum} expected=".($rounds * 5)." returned-sum={$grantedSum}");

// ── R: المطابقة المالية ──
echo "\nR: المطابقة المالية بعد كل السباقات\n";
$findings = app(PaymentReconciliationService::class)->run();
$check('R reconciliation reports no mismatch', $findings === [], 'findings='.count($findings).($findings === [] ? '' : ' first='.json_encode($findings[0], JSON_UNESCAPED_UNICODE)));

$failed = collect($checks)->where(1, false)->count();
echo "\nالنتيجة: ".($failed === 0 ? 'PASS' : "FAIL ({$failed} فحص)").' - '.count($checks)." فحصًا\n";

exit($failed === 0 ? 0 : 1);
```

---

## D) أوامر MySQL التي تشغّلها أنت (Windows / PowerShell، بلا افتراض XAMPP)

> ⚠️ **لا تشغّل `migrate:fresh` ولا أي اختبار Pest على قاعدة العمل `ahjiyat_platform` أبدًا.** اختبارات `RefreshDatabase` والمسبار يحذفون الجداول. قاعدة الاختبار منفصلة `ahjiyat_platform_test_mysql`، و`TestDatabaseGuard` والمسبار يرفضان أي اسم لا ينتهي بـ`_test` أو `_testing` أو `_test_mysql`.

```powershell
mysql --version                                   # 8.0.16 فأعلى (يُفضّل 8.0)
php -m | Select-String pdo_mysql                  # يجب أن يظهر pdo_mysql
mysql -u root -p                                  # كلمة المرور تُطلب تفاعليًا (لا تكتبها في أمر)
```
```sql
CREATE DATABASE IF NOT EXISTS ahjiyat_platform           CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ahjiyat_platform_test_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ahjiyat_app'@'%' IDENTIFIED BY '<كلمة-مرور-قوية>';
GRANT ALL PRIVILEGES ON ahjiyat_platform.* TO 'ahjiyat_app'@'%';
GRANT ALL PRIVILEGES ON `ahjiyat\_platform\_test%`.* TO 'ahjiyat_app'@'%';
FLUSH PRIVILEGES;
```
جلسة الاختبار (متغيرات الجلسة تتغلب على `.env` ولا تُحفظ في أي ملف):
```powershell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='ahjiyat_platform_test_mysql'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'
```

## E) أوامر نقل SQLite → MySQL (بروفة على قاعدة مؤقتة، ثم النقل الحقيقي على **نسخة**)

```powershell
# ── بروفة على قاعدة مؤقتة (لا تلمس ahjiyat_platform) ──
mysql -u root -p -e "DROP DATABASE IF EXISTS ahjiyat_rehearsal; CREATE DATABASE ahjiyat_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Copy-Item database\database.sqlite database\rehearsal-src.sqlite
Copy-Item database\database.sqlite database\rehearsal-backup.sqlite
(Get-FileHash database\rehearsal-src.sqlite).Hash; (Get-FileHash database\rehearsal-backup.sqlite).Hash   # يجب أن يتطابقا
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_rehearsal'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'
php artisan migrate --force
php artisan db:parity --runtime                                                     # PASS
php artisan db:transfer-sqlite-to-mysql --source=database\rehearsal-src.sqlite --dry-run      # لا كتابة
php artisan db:transfer-sqlite-to-mysql --source=database\rehearsal-src.sqlite --force --backup=database\rehearsal-backup.sqlite --report="$env:TEMP\e31-rehearsal.json"
# فشل جزئي؟ الأسلم إسقاط القاعدة وإعادة migrate. وإلا (يفرغ جداول التطبيق فقط):
# php artisan db:transfer-sqlite-to-mysql --source=database\rehearsal-src.sqlite --force --backup=database\rehearsal-backup.sqlite --reset-target --confirm-database=ahjiyat_rehearsal
```
المتوقع في آخر التقرير: `Row-count mismatches: 0` · `Integrity violations: 0` · `Fingerprint mismatches: 0` · `Auto-increment issues: 0` · `Financial mismatches: 0` · `Source SQLite unchanged: yes` · `Result: PASS`.

### الانتقال الحقيقي — 13 خطوة (على نسخة من بياناتك، بلا كتابة مزدوجة)
> **شرط مسبق:** طبّق ملفات القسم C أولًا (خاصة `SqliteToMysqlTransfer.php`)، وإلا ستُفشل البصمةُ نقلَك كاذبًا على أي جدول فيه أعمدة DATE (مثل `puzzles` و`player_streaks`). وجرّب البروفة أعلاه قبل الحقيقي.

1. **أوقف الكتابة:** `php artisan down --secret=<رمز>` وأوقف نوافذ `queue:work` و`schedule:work` و`reverb:start`.
2. **نسخة SQLite:** `sqlite3 database\database.sqlite "PRAGMA wal_checkpoint(TRUNCATE);"` (إن وُجد ملف `-wal`) ثم `$stamp = Get-Date -Format yyyyMMdd-HHmmss; Copy-Item database\database.sqlite database\backup-$stamp.sqlite` وقارن `Get-FileHash` للملفين (يجب التطابق) واحفظ نسخة ثالثة خارج المشروع. ثم اصنع **نسخة العمل للنقل** (المصدر الذي يقرؤه الأمر): `Copy-Item database\backup-$stamp.sqlite database\transfer-src.sqlite`. الملف الحي `database.sqlite` لا يُقرأ ولا يُعدَّل بعد الخطوة 2.
3. **قاعدة MySQL فارغة جديدة** `ahjiyat_platform` (القسم D).
4. **هدف النقل في الجلسة** (ليس في `.env` بعد): `$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='ahjiyat_platform'; $env:DB_USERNAME='ahjiyat_app'; $env:DB_PASSWORD='<كلمة-المرور>'` وستمرّر `--source` صراحةً دائمًا.
5. `php artisan migrate --force` ثم `php artisan db:parity --runtime` (PASS). **ليس `migrate:fresh` أبدًا.**
6. `php artisan db:transfer-sqlite-to-mysql --source=database\transfer-src.sqlite --dry-run`
7. **افحص التقرير:** لا سطور مشكلات (حالة أحرف في فهرس فريد، تواريخ صفرية، نص أطول من الحد…)، والجداول المستثناة الثمانية فقط (`cache, cache_locks, failed_jobs, job_batches, jobs, migrations, password_reset_tokens, sessions` — الجلسات تنتهي فيعيد المستخدمون تسجيل الدخول).
8. **النقل الفعلي:** `php artisan db:transfer-sqlite-to-mysql --source=database\transfer-src.sqlite --force --backup=database\backup-$stamp.sqlite --report="$env:TEMP\e31-real.json"` (النسخة الاحتياطية يجب أن تطابق `transfer-src.sqlite` بايتًا، وتتحقق الأداة من SHA-256 للمصدر قبل/بعد).
9. **التحقق:** `Result: PASS` بالأصفار أعلاه، ثم:
```powershell
php artisan db:parity --connection=mysql --runtime
php artisan sponsorship:reconcile-payments            # 0 اختلافات
php artisan community:reconcile-engagement --dry-run   # للقراءة فقط
sqlite3 database\backup-$stamp.sqlite "SELECT count(*), coalesce(sum(amount),0) FROM currency_transactions;"
mysql -u ahjiyat_app -p ahjiyat_platform -e "SELECT count(*), coalesce(sum(amount),0) FROM currency_transactions;"   # يطابقان
```
10. **بدّل `.env`:** `DB_CONNECTION=mysql` + `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`.
11. `php artisan optimize:clear` ثم `php artisan optimize` ثم `php artisan queue:restart` ثم `php artisan production:check`.
12. `php artisan up` ثم **اختبار دخان:** تسجيل دخول، لعب أحجية، عرض المحفظة، `/support` وسجل الدعم، لوحة الدفعات في `/admin/sponsorship-payments`، `/ready` يعيد `ok`.
13. **احتفظ بنسخة SQLite** للقراءة فقط 30 يومًا (والبصمة المسجَّلة). التراجع: `.env` إلى `sqlite` + استعادة الملف الموثّق بالبصمة (ما كُتب في MySQL بعد الانتقال لا يعود تلقائيًا).

> **لا أدّعي اكتمال نقل بياناتك الفعلية.** ما أُثبت هو الأداة على نسخة واقعية (4696 صفًا). الاكتمال الحقيقي: **PENDING USER-LOCAL — أرسل مخرجات الخطوات 6 و8 و9.**

---

## F) أوامر التحقق (على قاعدة الاختبار فقط)

```powershell
php artisan db:parity --static
php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31 tests/Unit/TestDatabaseGuardTest.php          # شغّله مرتين
php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/PaymentsE32                                                # شغّله مرتين
php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/EngagementE29
php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/Economy tests/Feature/GemWalletServiceTest.php tests/Feature/PuzzleAttemptServiceTest.php tests/Feature/Store tests/Feature/Competitive tests/Feature/CompetitiveRewards tests/Feature/DiscoveryE27 tests/Feature/CreatorE28 tests/Feature/SponsorshipE30 tests/Feature/CreatorSeriesE30
php artisan db:parity --connection=mysql --runtime
php tools\e32\concurrency-probe.php --rounds=6        # يمسح قاعدة الاختبار فقط؛ 0 = نجاح، 1 = فشل فحص، 2 = حاجز أمان
```
(`php vendor/bin/pest --configuration=...` وليس `php artisan test`.)

### نتائج ما شغّلته أنا (MySQL 8.0.46 على Ubuntu 24.04، نفس إعداد CI)
| الفحص | النتيجة |
|---|---|
| `migrate` على قاعدة فارغة (119 ترحيلًا) + `db:parity --runtime` | DONE كلها + PASS |
| `DatabaseE31` + `TestDatabaseGuard` على MySQL | **103 ناجحًا ×2** (1465 تأكيدًا) · على SQLite: 89 ناجحًا + 14 تُتخطى عمدًا (MySQL فقط) |
| المصفوفة الحرجة على MySQL | E27: 173 (+2 تُتخطى) · محفظة+متجر: 136 · منافسة+مكافآت: 146 · E28: 91 (+1) · E30 رعاية: 113 · E30 سلاسل: 81 (+2) → **740 ناجحًا + 5 تُتخطى** (اختبارات `EXPLAIN QUERY PLAN` الخاصة بـSQLite، موثّقة) |
| `EngagementE29` على MySQL | **139 ناجحًا** (690 تأكيدًا) |
| `PaymentsE32` على MySQL | تشغيلان: **151 + 151** بلا تخطٍّ؛ بعد إضافة S11/S12: **153 ناجحًا** |
| مسبار التزامن الحقيقي (عمليتان) | **38 فحصًا PASS** (6 جولات) ثم **42 PASS** (8 جولات) + تشغيلان سابقان بإصدار أقل |
| SQLite: PaymentsE32 + Store + E30 Perf / E27 | 162 ناجحًا (3 تُتخطى) / 175 ناجحًا |

### مسبار التزامن الحقيقي (J1–J5 + R)
| السيناريو | ما أُثبت |
|---|---|
| **J1** تحقق مزدوج متزامن (عمليتان، 6–10 جولات) | كل طلب `verified` مرة واحدة · مساهمة واحدة بلا تكرار ولا يتيم · تدقيق `payment_verified` واحد · التمويل = مجموع المحقَّق بالضبط · لا 1213/1205 |
| **J2** استرداد مزدوج (دفع+دفع ثم دفع+مسار E30 المباشر) | كل طلب `refunded` وكل مساهمة `refunded` مرة واحدة · تدقيق `payment_refunded` واحد · لا خصم مزدوج · لا deadlock (الخاسر في السباق المختلط يتلقى رفضًا نظيفًا «يُسترد المؤكَّد فقط») |
| **J3** مفتاح مصدر المكافأة نفسه (مستخدم بلا محفظة في كل جولة) | صف مكافأة واحد وحركة محفظة واحدة ومحفظة واحدة لكل مفتاح · الرصيد المعلّق = مكافأة واحدة بالضبط · الخاسر يتلقى 1062 على `currency_transactions_idempotency_key_unique` فقط (يلتقطه المستدعون: `catch (\Throwable)` في `SponsorshipRewardService`) |
| **J4** | طلب بانتظار/مرسَل لا يموّل البرنامج (التمويل = 0 قبل التحقق) |
| **J5** استثناء مُحقَن بعد إدراج المساهمة وقبل نهاية المعاملة | تراجع كامل: الطلب `submitted` بلا ربط ولا مبالغ محاسبية · لا ملف داعم جديد · لا مساهمة/تدقيق/مكافأة/حركة محفظة · ثم ينجح تحقق عادي مرة واحدة |
| **R** `sponsorship:reconcile-payments` بعد كل شيء | 0 اختلافات (ولا على البيانات المنقولة أيضًا) |

### النقل الفعلي على نسخة واقعية (102 جدولًا، 4696 صفًا)
| الخطوة | النتيجة |
|---|---|
| `--dry-run` | خروج 0 · لا كتابة (الهدف بقي 0 صف) · 8 جداول مؤقتة مستثناة · المصدر SHA ثابت |
| النقل الفعلي #1 | **FAIL**: بصمتان لـ`puzzles` و`player_streaks` فقط (جذر #4) — اكتشاف حقيقي |
| بعد الإصلاح: النقل #2 بـ`--reset-target --confirm-database` (مسار التعافي) | **PASS** في 5.6 ثانية: صفوف 0 فروق · أيتام 0 · بصمات 0 · AUTO_INCREMENT 0 · مالي 0 · المصدر لم يتغير |
| تحقق مستقل (سكربت خارجي لا يعتمد على تقرير الأداة) | أعداد 102/4696 متطابقة · **222 مفتاحًا أجنبيًا بلا أيتام** · مجاميع المال متطابقة بالعدد الصحيح (دفتر العملات 721 صفًا/72570 · المحافظ 34340/8230/74460 · الدفعات 29000/21000/6 · المساهمات 436700/432200/14) · حالات الدفع والمساهمات متطابقة · العربية متطابقة **بايتًا ببايت** في 5 أعمدة · مستخدم جديد حصل على المعرّف 49 بعد 48 · الأمر `sponsorship:reconcile-payments` على البيانات المنقولة: 0 · `/ready` و`production:check` لا يكشفان مضيفًا/مستخدمًا/كلمة مرور |

---

## G) نتائج EXPLAIN (بيانات اصطناعية بحجم واقعي: 143 ألف محاولة، 40 ألف طلب دفع، 18 ألف مساهمة، 410 داعمين، 1048 مستخدمًا)

الطريقة: التقاط **كل** استعلام SELECT تصدره 18 صفحة (لاعب + إدارة) وثلاثة أوامر كونسول وعشرة استعلامات E32 صريحة (**325 استعلامًا مميزًا / 449 خطة**)، ثم EXPLAIN لكل منها؛ التبليغ فقط عند `type=ALL` أو `filesort` أو `temporary` مع أكثر من 1500 صف. كل الصفحات أعادت 200 (27–668 ms).

| # | الاستعلام | الخطة | الحكم |
|---|---|---|---|
| 1 | تجميع تمويل البرامج `/support` و`/supporters` | `sponsorship_contrib_leaderboard_index` · Using temporary · 8723 صفًا | مقبول: 6–21 ms، مخزَّن بـ`SponsorshipCache`، محدود بعدد المساهمات المؤكدة |
| 2 | لوحة الداعمين (تجميع) | الفهرس نفسه · temporary+filesort | مقبول: 6.4 ms، مخزَّن |
| 3 | `/leaderboard` (أفضل الحلّالين، 143 ألف محاولة) | `puzzle_attempts_correct_user_index` (covering) · temp+filesort على 69 ألف | مقبول للحجم: 77 ms؛ مراقبة (سابق لـE31/E32) |
| 4 | مطابقة `reconcile-payments` | `range` على فهرس المساهمات · temp+filesort · 2005 | مقبول: الأمر كله 200 ms على 40 ألف طلب، للقراءة فقط ومجدول |
| 5 | تقليم الإثباتات `payments:prune-proofs --dry-run` | **ALL** على `sponsorship_payments` (39.7 ألف) | مقبول: 19 ms، مهمة ليلية؛ أضف فهرسًا فقط إن تجاوز الجدول ~500 ألف صف |
| 6 | طابور الإدارة (`status`, `created_at`) / سجل المستخدم / الانتهاء / المرجع الفريد / مفتاح الطلب / تمويل البرنامج | `sponsorship_payments_status_created_index` · `user_created_index` · `expiry_index` · `reference_unique` · `request_key_unique` · `program_status_index` | كلها فهارس مقصودة، بلا ALL/filesort |
| 7 | ملف `tools/e31/explain-audit.sql` (17 استعلامًا) | بحث الأحجيات/اللاعبين `range` على فهارس البحث · الدردشة `ref` (اختار المخطِّط فهرس `thread,sender,created_at` مع filesort على 8 صفوف لأن الجدول 2040 رسالة فقط؛ لا يُبلَّغ) · الرائج `range` على PRIMARY · قفل المحفظة `const` · الإشعارات `range` · أعمال المجتمع `ref` · سلسلة بالمعرّف `const` · لوحة الداعمين covering | لا ALL على جدول كبير؛ filesort صغيرة (8–12 صفًا) |

**فهارس مضافة: لا شيء.** (لا مبرر قياسيًا؛ أي إضافة تتطلب EXPLAIN قبل/بعد.) عتبات المراقبة: أضف فهرس تجميع `(status, sponsorship_program_id, accounting_amount_minor)` إذا تجاوزت المساهمات ~100 ألف أو p95 للتجميع > 100 ms. ملاحظة تجميلية: اسم فهرس `currency_transactions` ما زال `gem_transactions_user_id_type_index` بعد إعادة التسمية (لا أثر وظيفي).

---

## ملاحظات صدق
* لم أقرأ سجل CI (يحتاج تسجيل دخول)؛ التشخيص مبني على إعادة الإنتاج المحلية بإعداد CI نفسه. **الدليل النهائي هو تشغيل CI بعد دفعك الإصلاحات.**
* MySQL التجريبي 8.0.46 (apt)؛ صورة CI `mysql:8.0` أحدث 8.0.x. لم أُشغّل شيئًا على Windows.
* بيانات EXPLAIN اصطناعية: تقيس **شكل الخطط** لا سعة الإنتاج.
* مسبار السباق عمليتان (وليس حملًا متعدد الخيوط)؛ الثوابت المالية مقاسة بحالة القاعدة النهائية لا بالتوقيت.
* السباق J3: الخاسر يتلقى استثناء 1062 بدل إرجاع 0 (تصميم سابق لـE9/E30 يلتقطه المستدعون)؛ لم أغيّر الدلالات.

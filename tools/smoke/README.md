# فحص الدخان بعد النشر (E25)

الأداة الرسمية: **أمر Artisan** `release:smoke` (لا سكربت منفصل، كي يعمل بنفس بيئة التطبيق ولا يحتاج أدوات إضافية).

```bash
php artisan release:smoke                                  # على APP_URL
php artisan release:smoke --url=https://ahjiyat.example.com
php artisan release:smoke --expect-release=b983867         # يتحقق أن /ready يعرض هذا الإصدار (يكشف نشرًا لم يُفعَّل)
php artisan release:smoke --profile=<public_id لاعب عام>   # يضيف /players/{profile}
php artisan release:smoke --json                           # لأدوات المراقبة
```

- **قراءة فقط**: طلبات `GET` فقط على مسارات عامة (`/up`، `/ready`، `/`، `/login`، `/puzzles`، `/competitions`، `/store`)، بلا جلسة ولا بيانات اعتماد ولا كتابة (لا صداقة ولا محاولة أحجية ولا رسالة ولا محفظة).
- **مهلة صغيرة** لكل طلب (`config/operations.php`: 5ث، اتصال 3ث) ويُعلَّم البطيء (> 2000ms) بتحذير.
- **المخرجات**: `PASS/WARN/FAIL` + المسار + الحالة + الزمن. Exit `0` = لا FAIL، `1` = فشل.
- الفحص المصادَق (حساب لاعب) لا يُجرى بالإنتاج: يتم بـFeature tests على بيئة staging (docs/smoke-tests.md).

بديل بلا PHP (فحص يدوي سريع بـcurl، GET فقط):

```bash
for p in /up /ready / /login /puzzles /competitions /store; do
  curl -sS -o /dev/null -m 5 -w "%{http_code} %{time_total}s $p\n" "https://ahjiyat.example.com$p"
done
```

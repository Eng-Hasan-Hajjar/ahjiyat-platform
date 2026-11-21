# HOTFIX1 — المرحلتان الثالثة والرابعة: إعادة تعديلات E48 في ملفي المنشئ

## ماذا حدث

حزمتي الأولى (`AHJIYAT-PLM-STAGES-03-04-DELIVERY.zip`) كانت مبنية على الإيداع `282d89290733def2e00fa2116eda3a14c0e80f53` ولم تكن حزم E46 وE47 وE48 فيه. ملفان في حزمتي يتداخلان مع حزمة E48 (SEARCHABLE-SELECTS):

- `app/Filament/Pages/CinematicBuilderPage.php`
- `resources/views/filament/pages/cinematic-builder.blade.php`

فكتبتُ فوق تعديلات E48 فيهما. النتيجة عندك: 16 اختبارًا فاشلًا (UX-06 وSS-01 … SS-38، منها `CinUxTest.php:223` الذي يطلب `data-ss-root`). هذا خطأ مني: لم أقارن ملفات حزمتي بحزم E46–E48 قبل التسليم. لا تداخل مع E46 ولا E47، ولا بقية ملفات حزمتي.

## ما في هذه الحزمة

ملفان كاملان على مساريهما الأصليين = **نسخة E48 كما سلّمتُها + تعديلات المرحلتين الثالثة والرابعة فقط** (النوعان `board_move` و`inference` في المحرّر):

| الملف                                                     | الفرق عن نسخة E48                                             |
| -------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `app/Filament/Pages/CinematicBuilderPage.php`                | سطر واحد: قائمة `kinds` + النوعان الجديدان. |
| `resources/views/filament/pages/cinematic-builder.blade.php` | 30 سطرًا: حقول المحرّر للنوعين.                   |

مع نسخ محدّثة من `docs/PLM-STAGES-03-04/` (`APPLY-AND-ROLLBACK.md`, `STATUS-TABLE.md`, `TESTS-AND-LIMITS.md`) وهذا الملف.

## التطبيق (PowerShell)

```powershell
$P = "C:\Users\engya\Desktop\puzzles\puzzle-gems-platform"
$H = "$HOME\Downloads\AHJIYAT-PLM-STAGES-03-04-HOTFIX1"
Expand-Archive -Path "$HOME\Downloads\AHJIYAT-PLM-STAGES-03-04-HOTFIX1.zip" -DestinationPath $HOME\Downloads -Force

# 1) سلامة الحزمة (لا ناتج = سليمة)
Get-Content "$H\SHA256SUMS.txt" | ForEach-Object {
  $h, $f = $_ -split '\s+', 2
  if ((Get-FileHash -Algorithm SHA256 (Join-Path $H $f)).Hash.ToLower() -ne $h.ToLower()) { "MISMATCH: $f" }
}

# 2) تأكد أن نسختك الحالية من الملفين هي نسخة حزمتي الأولى (أي لم تعدّلها بعدها)
Get-FileHash "$P\app\Filament\Pages\CinematicBuilderPage.php" -Algorithm SHA256 | Select-Object Hash
Get-FileHash "$P\resources\views\filament\pages\cinematic-builder.blade.php" -Algorithm SHA256 | Select-Object Hash
```

القيم المتوقعة (نسخة حزمتي الأولى):

- `CinematicBuilderPage.php` ⟵ `5976bbc25c85fc027c6e870b852fea5e220447b830dae38dc9fc4a609713d315`
- `cinematic-builder.blade.php` ⟵ `f44f260b60e5411e6bea30940c215058cd6b933ac6cee6744ed8302f413c4ccb`

إن طابقت القيمتان فانسخ. **إن لم تطابقا فتوقف ولا تنسخ**: معناه أنك عدّلت الملفين بعد التطبيق وستضيع تعديلاتك؛ أرسل لي نسختيك لأدمجها.

(لو احتفظتَ بنسخة الملفين قبل تطبيق حزمتي الأولى فيجب أن تساوي نسخة E48: `CinematicBuilderPage.php` ⟵ `6999e4ebf1e513ada5d46a1888cf29144c067ad793c6431866026c2dfcba7068`، و`cinematic-builder.blade.php` ⟵ `62235a310430a92d997ef1376f65761e238806fe0b673f58dbe1abad4c27f707`.)

```powershell
# 3) نسخة احتياطية ثم النسخ
$B = "$HOME\plm34_hotfix1_backup"
foreach ($f in (Get-Content "$H\FILES-MODIFIED.txt")) {
  $dst = Join-Path $B $f
  New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
  Copy-Item (Join-Path $P $f) $dst -Force
}
foreach ($f in ((Get-Content "$H\FILES-MODIFIED.txt") + (Get-Content "$H\FILES-NEW.txt"))) {
  $dst = Join-Path $P $f
  New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
  Copy-Item (Join-Path $H $f) $dst -Force
}
cd $P
php artisan optimize:clear

# 4) الاختبارات
php vendor/bin/pest tests/Feature/CinematicStories tests/Feature/SearchableSelects
```

الناتج المتوقع عندي على شجرة افتراضية (الأساس + E46 + E47 + E48 + حزمتي + هذا التصحيح): كل شيء ناجح عدا CINDB-07 الذي يفشل عندي لغياب ملف CI. **لم أرَ شجرتك الفعلية**، فإن ظهر عندك فشل غير هذا فأرسل الناتج.

## التراجع عن التصحيح

```powershell
foreach ($f in (Get-Content "$H\FILES-MODIFIED.txt")) { Copy-Item (Join-Path $B $f) (Join-Path $P $f) -Force }
php artisan optimize:clear
```

(يعيد حالة حزمتي الأولى ذات الفشل؛ لا تفعله إلا للمقارنة.)

لا تغييرات في قاعدة البيانات ولا commit ولا PR ولا نشر.

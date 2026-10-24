# تصحيح E40 رقم 1 — ملف في مجلد خاطئ + اختبارات تلتقط هذا النوع

## ما الذي حدث؟
في الـcommit `5c65f9a` وصل `FinancialHistoryGuard.php` (النسخة المعدّلة) إلى **`app/Database/`** بدل **`app/Services/Database/`**.
الأثر:
- النسخة الأصلية في `app/Services/Database/` بقيت بلا سطر E40 (`digital_purchases`)، فحارس التاريخ المالي لا يعرف مشتريات المسار المميز.
  (قاعدة البيانات ما زالت ترفض حذف مستخدم له مشترى بسبب RESTRICT؛ لكن سياسة الحذف لا تعطي رفضًا ودّيًا بل خطأ قاعدة بيانات خامًا.)
- ملف مكرر بنطاق لا يطابق مساره (`App\Services\Database` داخل `app/Database/`) ⇒ مخالفة PSR-4 قد تكسر `composer dump-autoload --strict-psr`.
باقي الملفات الـ85 مطابقة حرفيًا للحزمة.

## ما في هذه الحزمة؟ (3 ملفات تحت `overlay\`)
1. `app/Services/Database/FinancialHistoryGuard.php` — الإصلاح (مسار صحيح).
2. `tests/Feature/AhjiyatPassE40/E40GuardRegistrationTest.php` — **جديد**: 3 اختبارات (AX19-124/125/126):
   حارس المشتريات + رفض قاعدة البيانات + فحص أن كل ملف تحت `app/` يطابق نطاقه مساره ولا يتكرر صنف.
3. `docs/ahjiyat-pass-e40.md` — تحديث عدد الاختبارات 234 ⇒ 237.

## الخطوات (PowerShell من جذر المشروع)
راجع `FIX1_commands.md`. **لا تحذف المجلد `app\Database` نفسه**: فيه `MysqlSafeBlueprint.php`؛ احذف الملف المكرر وحده.

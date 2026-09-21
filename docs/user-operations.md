
# إدارة المستخدمين ومركز العمليات الأمنية (E6)

## User 360

فتح أي مستخدم من "المستخدمون" يعرض صفحة واحدة منظَّمة (`ViewUser`):

- **Infolist علوي**: الملف الأساسي، حالة الحساب، الأدوار والوصول الفعلي، ملخصات (أمان/نشاط/محفظة) - كل قسم يظهر فقط لمن يملك الصلاحية المرتبطة به.
- **تبويبات (RelationManagers) أسفلها تلقائياً**: المحفظة، نشاط اللعب، المواسم والحملات، إشارات أمنية، طلبات الاستبدال، الأجهزة، الجلسات - كل تبويب مُخفى بالكامل عن أي دور لا يملك صلاحيته.

## حالة الحساب: تجميد/رفع تجميد

`UserAccountService::freeze()/unfreeze()` نقطة الحقيقة الوحيدة. كل عملية تمر عبر `AuthorizationSafetyService` (منع تعديل Super Admin، منع تجميد آخر Super Admin، منع تجميد النفس)، سبب إلزامي، ووقت + منفِّذ يُسجَّلان (`frozen_at`, `frozen_by`) + بسجل العمليات.

### إصلاح أمني حقيقي وجدناه

`is_frozen` كانت تُفحَص فقط بشاشة تسجيل الدخول - حساب بجلسة قائمة مسبقًا كان يستمر بكل الأفعال الحساسة بعد تجميده. أُصلح مركزيًا عبر Middleware واحد (`account.active` → `EnsureAccountIsNotFrozen`) على مجموعة الأفعال الحساسة الموجودة أصلاً بـ`routes/web.php`.

## المحفظة

العرض (`users.view_wallet`). التعديل اليدوي (`wallet.adjust` - قوية) عبر `GemWalletService::adjustAvailable()` - معاملة DB + قفل صف، يمنع رصيدًا سالبًا، `GemTransaction` حقيقية بنوع `admin_adjustment`.

## طلبات الاستبدال

البنية موجودة أصلًا (`RedemptionService`) - أضفنا تفويضًا صريحًا، إعادة تحقّق من الحالة لحظة التنفيذ لمنع معالجة مضاعفة، وتسجيلًا بسجل العمليات.

## الاحتيال

أضفنا `resolved_by`/`resolved_at`/`resolution_note`. `FraudDetectionService::resolve()` تمنع معالجة علامة مُعالَجة مسبقًا، ولا تُجمِّد الحساب تلقائيًا أبدًا.

## الأجهزة والجلسات

`device_hash` مُخزَّن كـHash فقط. الجلسات عبر `App\Models\Session` فوق جدول `sessions` القياسي - الإنهاء = حذف الصف.

## سجل العمليات - منفصل عمداً عن E5

`OperationalAuditLog` منفصل تمامًا عن `AuthorizationAuditLog` (RBAC فقط).

## قرار متحفِّظ: حذف المستخدم

قُيِّد على المدير الأعلى فقط مؤقتًا - Hard Delete يمسّ بيانات حساسة بلا SoftDeletes حاليًا.

## الصلاحيات الجديدة (10)

`users.freeze`, `users.unfreeze`, `users.view_security`, `users.view_wallet`, `users.view_activity`, `users.manage_roles`, `wallet.adjust`, `security.sessions_view`, `security.sessions_revoke`, `operations.dashboard_view`.

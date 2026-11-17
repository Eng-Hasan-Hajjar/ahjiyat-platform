
# نظام الاقتصاد متعدد العملات (E9)

## المبدأ الأساسي

**كل حركة اقتصادية تمر حصرًا عبر `CurrencyTransaction` (Ledger واحدة) — لا تعديل مباشر لأي رصيد بأي مكان بالكود.** المصدر الوحيد المخوَّل لتغيير أي رصيد هو `App\Services\Economy\CurrencyWalletService`.

## البنية

- **`Currency`**: تعريف مستقل لكل عملة (`internal_key` ثابت أبديًا، `name`/`code`/`icon` حرة التغيير). أنواع: `standard` (أساسية)، `premium` (مميَّزة)، `event` (فعالية/موسم).
- **`Wallet`**: رصيد لكل زوج (مستخدم، عملة).
- **`CurrencyTransaction`**: Ledger الوحيدة — Append Only بالكامل.
- **`CurrencyPack`**: أساس المتجر (لا Checkout فعلي بعد).
- **`CurrencyRegistry`**: المرجع الرسمي الوحيد لـ"عملة اللعب الافتراضية" و"الرصيد المميَّز".

## التوافق مع الكود القديم

- **`GemTransaction`** الآن Subclass توافقي فوق `CurrencyTransaction` — نفس الجدول تمامًا.
- **`GemWalletService`** أصبح Adapter شفاف فوق `CurrencyWalletService` بعملة `platform-earned` دائمًا.
- **`User::wallet()`** يبقى يعمل، مُقيَّد صراحة بعملة `platform-earned`.
- **`gems_amount`** و**`gem_reward`** أُبقيا بنفس الاسم.

## تدفق المكافأة (أولوية القرار)

1. `AttemptRewardResolver` (Campaign Step بوضع `override`).
2. `Puzzle::reward_currency_id`.
3. `CurrencyRegistry::defaultEarnedCurrency()` (السلوك القديم بالضبط).

سقف الكسب اليومي يُطبَّق فقط على العملة الافتراضية المكتسَبة تحديدًا.

## الاكتشاف الحرج أثناء البناء

`UserFactory::configure()`'s `afterCreating` hook كان يُنشئ محفظة تلقائيًا بلا `currency_id` — أُصلح + المسار الحقيقي بـ`RegisteredUserController`.

## ما لم يُبنَ عمدًا

- لا بوابة دفع فعلية.
- لا Zeroing تلقائي عند `expires_at`.
- لا Cap يومي عام لكل عملة.

## الصلاحيات

`economy.currencies.*`, `economy.packs.*`, `economy.transactions.view`.

## أثر كل نوع معاملة على الرصيدين (E46)

المصدر: مسارات الكتابة الفعلية في `CurrencyWalletService` (وتثبّتها الاختبارات في `tests/Feature/GemIntegrityE46`). **لكل محفظة رصيدان مستقلان: المتاح (`available_balance`) والمعلَّق (`pending_balance`)**، و`lifetime_earned` عدّاد تراكمي.

| النوع (`type`) | إشارة المبلغ في الدفتر | المتاح | المعلَّق | `lifetime_earned` | من يكتبه |
|---|---|---|---|---|---|
| `earn_pending` | موجب دائمًا | لا أثر | **+المبلغ** | +المبلغ | `creditPending` (حلّ الأحجيات، المهام، الإنجازات...) |
| `release_available` | موجب دائمًا — **صف واحد** | **+المبلغ** | **−المبلغ** | لا أثر | `releasePending` (المهمة المجدولة `pending_hold_expired`) |
| `redeem` | سالب دائمًا | −المبلغ | لا أثر | لا أثر | `debitAvailable` (طلب استبدال، شراء تلميح) |
| `spend` | سالب دائمًا | −المبلغ | لا أثر | لا أثر | `debitAvailable` (شراء من المتجر) |
| `refund` | موجب | +المبلغ | لا أثر | لا أثر | `refund()` بنوع `refund` (استرجاع شراء متجر) |
| `admin_adjustment` | بإشارته | ±المبلغ | لا أثر | **يختلف**: `creditAvailable` يزيده، و`adjust()` و`refund()` لا يزيدانه | `adjust()` (تعديل إداري)، `refund()` الافتراضي (رفض طلب استبدال: السبب `redemption_rejected:ID`)، `creditAvailable` الافتراضي |
| `purchase`، `progression_reward` | موجب | +المبلغ | لا أثر | +المبلغ | `creditAvailable` |
| `expire` | — | **مُعرَّف ولا يكتبه أي مسار حاليًا** (لا تصفير تلقائي عند `expires_at`) | — | — | لا أحد |

نقاط تُقرأ مع الجدول:

- **التحويل بين الرصيدين** (معلَّق ← متاح) صف واحد موجب نوعه `release_available`؛ أثره المزدوج مشتقّ من نوعه وليس من صفّين. لذلك لا يكفي `SUM(amount)` وحده للتحقق من أي رصيد.
- **الاشتقاق من الدفتر:** المعلَّق = Σ`earn_pending` − Σ`release_available`. المتاح = Σ`release_available` + Σ(الأنواع الأخرى بإشاراتها).
- **الاسترداد** لا يمسّ المعلَّق أبدًا: يعود إلى المتاح فقط (رفض طلب استبدال يُرجع الجواهر إلى المتاح بنوع `admin_adjustment` وسبب `redemption_rejected:ID`).
- **طلب الاستبدال:** الجواهر تُخصم من المتاح **لحظة الطلب** (`redeem` سالب). `approve` لا يكتب شيئًا في الدفتر. `markFulfilled` يزيد `lifetime_redeemed` في المحفظة (بلا صف دفتر). `reject` يرجّع المبلغ بصف `admin_adjustment` موجب. إذن الجواهر الملتزَم بها لطلبات مفتوحة **خارج** رصيد المحفظة ولا تُجمع إليه ولا إلى صف الخصم (لا عدّ مزدوج).
- **الانتهاء:** لا يوجد مسار يكتب `expire` ولا يصفّر الرصيد عند `expires_at`.
- **التعديل الإداري** `adjust()` بإشارته على المتاح فقط، ويرفض خصمًا يجعل المتاح سالبًا؛ ولا يمسّ المعلَّق.
- لا يوجد نوع «رصيد افتتاحي/مرحَّل» ولا جدول له: أي رصيد وُجد بلا دفتر (ترحيل قديم أو كتابة مباشرة) لا يمكن إثبات أنه «خطأ» — يُصنَّف «لا يمكن الجزم».

## تدقيق سلامة الجواهر (E46)

أمر قراءة فقط `php artisan gems:audit` يقارن **المتاح** و**المعلَّق** لكل محفظة، كلًا على حدة، بما يُشتقّ من الدفتر وفق الجدول أعلاه، ويصنّف كل حالة: **مطابق / فرق مثبت / لا يمكن الجزم**. لا يصلح شيئًا ولا يعدّل رصيدًا. النتيجة الأخيرة تظهر في «مركز العمليات» (بطاقة «سلامة الجواهر») وتفاصيلها في صفحة «سلامة الجواهر» لمن يملك `economy.transactions.view`. التفاصيل الكاملة في [`gem-integrity-e46.md`](gem-integrity-e46.md)، والعيوب التي أثبتتها الاختبارات (بلا إصلاح) في [`gem-integrity-e46-findings.md`](gem-integrity-e46-findings.md).

## قواعد صيانة لأي مطوِّر لاحقًا

1. لا تُنشئ `Wallet`/`CurrencyTransaction` يدويًا خارج `CurrencyWalletService`.
2. لا تجمع أرقام عملتين مختلفتين كرقم واحد أبدًا.
3. `internal_key` لا يتغيَّر أبدًا بعد أول استخدام.
4. كود جديد يستخدم `CurrencyWalletService`/`CurrencyTransaction`/`CurrencyRegistry` مباشرة.
5. (E46) أي نوع معاملة جديد يُضاف إلى `App\Services\Economy\Integrity\LedgerEffects` وإلى الجدول أعلاه في الطلب نفسه، وإلا يُبلَّغ كشذوذ في التدقيق ويمنع حسم أي فرق على محفظته.

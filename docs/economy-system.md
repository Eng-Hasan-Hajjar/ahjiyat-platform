
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

## قواعد صيانة لأي مطوِّر لاحقًا

1. لا تُنشئ `Wallet`/`CurrencyTransaction` يدويًا خارج `CurrencyWalletService`.
2. لا تجمع أرقام عملتين مختلفتين كرقم واحد أبدًا.
3. `internal_key` لا يتغيَّر أبدًا بعد أول استخدام.
4. كود جديد يستخدم `CurrencyWalletService`/`CurrencyTransaction`/`CurrencyRegistry` مباشرة.

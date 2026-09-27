
# هوية اللاعب والتجميليات + الملف العام (E11)

## المبدأ الأساسي

**الملكية شيء، والتجهيز (Equip) شيء آخر تمامًا.**



StorePurchase (E10) → UserInventoryItem (ملكية - المصدر الوحيد)
↓
CosmeticLoadoutService::equip()
↓
UserCosmeticLoadout (إشارة فقط - لا تمنح ملكية أبدًا)

## الفتحات التجميلية (Cosmetic Slots)

خمس فتحات ثابتة، **فتحة واحدة فقط لكل نوع** (لا Multi-badge Showcase بـE11):

- `avatar` — الصورة الرمزية
- `profile_frame` — الإطار (Overlay شفاف حول الصورة)
- `badge` — الشارة (تجميلية فقط - ليست Achievement)
- `title` — اللقب (نص عادي + لون Hex اختياري)
- `profile_background` — خلفية الملف الشخصي

## تعريف العنصر التجميلي

حقول جديدة مباشرة على `StoreItem` (لا Model منفصلة - الأبسط والأكثر قابلية للصيانة): `cosmetic_slot`, `cosmetic_text` (اللقب فقط، نص عادي Escaped - لا HTML أبدًا)، `cosmetic_color` (Hex مُتحقَّق منه `#AABBCC` حصرًا - لا CSS تعسفي، لا `linear-gradient`/`url`/`expression`).

**قيد صارم**: عنصر تجميلي قابل للتجهيز يجب أن يكون `fulfillment_type = inventory` حصرًا - لا `entitlement` ولا `manual`. مُفروض بالفورم (خيارات محدودة ديناميكيًا) وبالخدمة معًا.

### التعامل مع التجميليات القديمة

`cosmetic_slot` Nullable عمدًا - عنصر تجميلي بلا فتحة مُعرَّفة يبقى "Legacy - غير قابل للتجهيز" حتى تُحدِّده الإدارة صراحة. لا كسر Migration إطلاقًا.

### قفل الفتحة بعد الاستخدام

`StoreItem::hasCosmeticUsageHistory()` (ملكية فعلية، أو شراء، أو تجهيز قائم) تُقفِل حقل `cosmetic_slot` بالفورم تمامًا - تغيير الفتحة بعد الاستخدام يكسر Loadout قائمًا فعليًا لمستخدمين حقيقيين.

## `UserCosmeticLoadout` + `CosmeticLoadoutService`

جدول بسيط: `user_id` + `slot` + `store_item_id` + `equipped_at`، Unique `(user_id, slot)` يفرض "فتحة واحدة" على مستوى قاعدة البيانات نفسها.

`CosmeticLoadoutService` هي المصدر المركزي الوحيد لـ:

- `equip()`: يتحقق (تجميلي + تسليمه مخزون + يملك Slot + الكمية المملوكة>0) قبل أي تجهيز. لا اعتماد على `InventoryService` (تفاديًا لدورة اعتمادية - قراءة مباشرة لـ`UserInventoryItem` كافية).
- `unequip()`: يُزيل صف الفتحة - رصيد الملكية لا يتأثر إطلاقًا.
- `unequipItemIfNecessary()`: تُستدعى مركزيًا من `InventoryService::revoke()` (انظر أدناه).
- `loadoutFor()`: تُعيد Array مُحمَّل مسبقًا (5 فتحات) - لا N+1 عند العرض.

**فتحة الطلب تُشتَق دائمًا من العنصر نفسه** (`item->cosmetic_slot`) - لا مدخل عميل منفصل لتحديد الفتحة، فتعارض "الفتحة المطلوبة ≠ فتحة العنصر" مستحيل بالتصميم (لا مجرد بفحص).

## استبدال تلقائي بنفس الفتحة

تجهيز عنصر جديد بنفس الفتحة (مثلاً إطار جديد) يستبدل القديم تلقائيًا (`updateOrCreate`) - لا حاجة لإزالة يدوية أولًا.

## تكامل الاسترجاع/السحب التلقائي (الأهم أمنيًا)

`InventoryService::revoke()` (E10) أصبحت تعتمد أحاديًا على `CosmeticLoadoutService` (لا العكس - تفاديًا للدورة). عند وصول الكمية الفعلية لصفر **تمامًا** لعنصر تجميلي: `unequipItemIfNecessary()` تُستدعى تلقائيًا **داخل نفس معاملة `DB::transaction`**.

بما أن `StorePurchaseService::refund()` (E10) تستدعي أصلًا `InventoryService::revoke()` لعناصر المخزون - **الاسترجاع الإداري يُزيل التجهيز تلقائيًا بلا أي كود إضافي بـ`StorePurchaseService` نفسها**، وبنفس الذرّية الحالية تمامًا (بند 29 مُحقَّق دون لمس تلك الدالة). سحب جزئي (2→1) لا يُزيل التجهيز - فقط الوصول للصفر الفعلي.

## الهوية العامة: `public_id` + `profile_visibility`

`public_id` (ULID) عمود جديد على `users` - **الرابط العام لا يعتمد `id` التسلسلي ولا البريد أبدًا**. Server-side حصرًا (`User::booted()`)، Backfill فوري لكل مستخدم حالي بنفس الترحيل، لا `migrate:fresh`.

`profile_visibility`: `private` (الافتراضي - أكثر تحفُّظًا، موثَّق أدناه) | `members` | `public`. المالك يرى معاينته دائمًا؛ غير المصرَّح له يحصل على **404 صريح** (لا كشف حتى بوجود الحساب من عدمه).

### لماذا `private` افتراضيًا؟

لم يكن هناك ملف عام إطلاقًا قبل E11 - أي افتراض آخر (`public` أو حتى `members`) يُغيِّر سلوكًا قائمًا فجأة لآلاف المستخدمين الحاليين بلا موافقتهم. `private` = صفر تغيير مفاجئ في التعرض الفعلي.

## الحقول الآمنة بالملف العام

اسم العرض، الهوية المُجهَّزة الخمس، إحصاءات آمنة محدودة (`PlayerProfileService`): أحجيات محلولة، تحديات شارك بها، تأهلات ضمن حملات.

**استُبعِدت عمدًا** "حملات مكتملة" و"مشاركة موسم رسمي" - لا تعريف دقيق آمن لهما بالبنية الحالية بلا اختراع منطق مُشتَق إضافي.

**ممنوع عرضه إطلاقًا بأي حال**: البريد، الأدوار والصلاحيات، أي رصيد عملة، سجل شراء/استبدال، إشارات احتيال، IP/أجهزة/جلسات، ملاحظات إدارية، سبب تجميد، سجل تدقيق تشغيلي، محتوى تأمل خاص.

## Blade Components

`<x-player-avatar>` (خفيف - Navbar/لوحة الصدارة) و`<x-player-identity>` (كامل - رأس الملف). كلاهما لا يُنفِّذ استعلامات إطلاقًا - يستقبل عناصر `StoreItem` محلولة مسبقًا (بالتصميم، لا N+1 بأي استخدام).

**دفاع مزدوج على اللون**: حتى مع تحقُّق Filament وقت الكتابة، طبقة العرض نفسها تُعيد فحص صيغة Hex عبر `preg_match` قبل استخدامها - قيمة غير صالحة تُستبدَل بلون افتراضي آمن بصمت.

## SEO

كل صفحات الملف العام تحمل `noindex, nofollow` دائمًا، بغضّ النظر عن إعداد الفهرسة العام للمنصة - قرار Baseline متحفِّظ حتى قرار SEO اجتماعي صريح لاحق.

## ما لم يُبنَ عمدًا

Achievement Engine، XP/Levels، Battle Pass، Multi-badge Showcase، أصدقاء/متابعة، Chat/DM، رفع صور شخصية من المستخدم، تداول/هدايا تجميليات، تأثيرات تجهيز داخل الألعاب، تجميليات متحركة، دليل عام لكل المستخدمين (`/players`)، بحث لاعبين.

FILE: docs/store-inventory-entitlements.md (غير موجود بمستودعك — أنشئه الآن بهذا المحتوى الكامل)

markdown

# كتالوج المكافآت + المتجر الافتراضي + المخزون + الامتيازات (E10)

## المبدأ المعماري

فصل واضح تمامًا بين أربع طبقات:







StoreItem (كتالوج) → StoreItemPrice (سعر بعملة) → StorePurchase (عملية شراء)
↓
CurrencyWalletService (خصم/استرجاع - Ledger واحدة)
↓
Inventory (مخزون) أو Entitlement (امتياز) أو Manual (يدوي)

`StoreController` رفيعة تمامًا - كل منطق الأعمال داخل `StorePurchaseService`/`InventoryService`/`EntitlementService`.

## Catalog: `StoreItem`

Entity عامة (ليست "GemReward" - النظام Multi-Currency). `item_type` (physical/digital/cosmetic/consumable/access) و`fulfillment_type` (inventory/entitlement/manual) **مستقلان تمامًا** - لا افتراض ضمني بينهما؛ الإدارة تختار الاثنين صراحة.

`sku` ثابت وفريد (لا يُستخدم كمفتاح أساسي). `slug` فريد لصفحة التفاصيل العامة (`/store/items/{slug}`). `is_active`+نافذة `starts_at`/`ends_at` تُتحقَّق **Server-side دائمًا** عبر `isPurchasable()` - لا اعتماد على واجهة المستخدم.

## التسعير: `StoreItemPrice`

عنصر واحد قد يملك عدة خيارات سعر بعملات مختلفة (Admin يختار). كل عملية شراء تستخدم عملة واحدة فقط - **لا جمع عملتين أبدًا**. حذف سعر مُستخدَم فعليًا محظور (`isProtected()`) - Deactivate فقط.

## الشراء: `StorePurchase` + `StorePurchaseService`

**ليست Payment Order** - عملية شراء بعملة افتراضية موجودة مسبقًا فقط. الحالات: `pending_fulfillment` → `fulfilled` أو `refunded` (لا `paid`/`pending_payment` - لا مال حقيقي هنا).

### Snapshot

`item_snapshot` (JSON) يحفظ كل ما يلزم لحماية التاريخ: الاسم، SKU، النوع، طريقة التسليم، الكمية الممنوحة، مفتاح/مدة الامتياز، اسم/رمز العملة. `price_amount` منفصل ومحفوظ صراحة. تغيير سعر أو اسم العنصر لاحقًا لا يغيّر معنى أي عملية شراء قديمة إطلاقًا.

### التدفق الذرّي

كل شيء داخل `DB::transaction` واحدة: قفل صف العنصر (`lockForUpdate`) → تحقُّقات → إنشاء `StorePurchase` → خصم عبر `CurrencyWalletService::debitAvailable()` بنوع `TYPE_SPEND` → تسليم تلقائي أو `pending_fulfillment`.

### Idempotency

`request_key` فريد بقاعدة البيانات. نفس المفتاح لنفس (مستخدم+عنصر+سعر) يُعيد نفس عملية الشراء. نفس المفتاح لعملية مختلفة = `RuntimeException`.

### المخزون والحدود

`stock_limit=null` = غير محدود. المخزون المتبقي = `stock_limit - (مشتريات pending_fulfillment+fulfilled)` - مُحتسَب لا مُخزَّن. `per_user_limit` يُفرَض Server-side دائمًا.

## المخزون الشخصي: `UserInventoryItem` + `InventoryTransaction` + `InventoryService`

ملكية مُجمَّعة. `InventoryService` هي المصدر الوحيد لتعديلها. `InventoryTransaction` Ledger منفصلة تمامًا عن `CurrencyTransaction`، Append-Only بالكامل. `revoke()` لا تُنزل الكمية تحت الصفر أبدًا.

## الامتيازات: `UserEntitlement` + `EntitlementService`

حق وصول/ميزة، ليست عملة ولا عنصر مادي. `entitlement_duration_days=null` = دائم. Baseline غير قابل للتكديس - لا شراء ثانٍ لامتياز دائم مملوك فعليًا (`hasActive()`). `revoked_at` يبقى بالسجل دائمًا.

## التسليم اليدوي (Manual Fulfillment)

لجوائز مادية/قسائم. بعد الخصم: `pending_fulfillment` مباشرة. الإدارة تُنجزه عبر Action مخصَّصة - منع صريح لإنجاز مزدوج.

## الاسترجاع (Refund)

`StorePurchaseService::refund()` (Admin فقط): يُرجع نفس العملة ونفس القيمة الأصلية المحفوظة عبر `CurrencyWalletService::refund()` بنوع `TYPE_REFUND`. يعكس التسليم (مخزون/امتياز). **Fail-safe صريح**: عنصر Manual مُسلَّم فعليًا لا يُسترجَع تلقائيًا أبدًا.

## أنواع Ledger العملة

`TYPE_SPEND` (خصم شراء متجر)، `TYPE_REFUND` (استرجاع شراء).

## التوافق مع الاقتصاد (E9/E9.1)

`CurrencyWalletService::debitAvailable()` يبقى خط الدفاع المركزي الوحيد - `canSpend()` يُفرَض تلقائيًا، بلا Bypass.

## RBAC

`store.items.*`, `store.prices.manage`, `store.purchases.view/fulfill/refund`, `store.inventory.view`, `store.entitlements.view`.

## الأمان

IDOR: صفحة "مقتنياتي" تعرض بيانات المستخدم الحالي حصرًا. Mass Assignment: العميل يرسل `price_id`+`request_key` فقط. Replay: idempotency تمنع خصمًا/تسليمًا مزدوجًا.

## ما لم يُبنَ عمدًا (E10)

لا سلة تسوق، لا نظام شحن كامل، لا كوبونات/خصومات، لا Loot Box/Gacha، لا تداول/هدايا، لا Equip System (بُني لاحقًا بـE11)، لا نظام استهلاك عام. بوابة الدفع تبقى محصورة بـ`CurrencyPack` مستقبلًا.

## E11: تكامل تجهيز التجميليات (Cosmetic Equip Integration)

عناصر `item_type=cosmetic` أصبحت قابلة للتجهيز فعليًا عبر `CosmeticLoadoutService` (راجع `docs/player-identity-cosmetics.md` للتفاصيل الكاملة). النقاط التي تخصّ هذا الملف تحديدًا:

- **قيد جديد**: عنصر تجميلي قابل للتجهيز يجب أن يكون `fulfillment_type=inventory` حصرًا - مفروض بالفورم (خيارات محدودة ديناميكيًا حسب `item_type`) وبالخدمة معًا.
- **`InventoryService::revoke()`** أصبحت تعتمد أحاديًا على `CosmeticLoadoutService` (حقن Constructor باتجاه واحد فقط - لا دورة اعتمادية) لتفريغ أي تجهيز تلقائيًا عند وصول الكمية الفعلية لصفر. هذا يعني **استرجاع شراء تجميلي (`StorePurchaseService::refund()`) يُزيل تجهيزه تلقائيًا بلا أي تعديل إضافي على تلك الدالة** - نفس الذرّية الحالية تمامًا.
- **`hasCosmeticUsageHistory()`** (ملكية فعلية، شراء، أو تجهيز قائم) تمنع الإدارة من تغيير `cosmetic_slot` لعنصر مُستخدَم فعليًا - حماية من كسر Loadout قائم لمستخدمين حقيقيين.
- Ownership نفسها (`UserInventoryItem`) **تبقى المصدر الوحيد** - `UserCosmeticLoadout` تشير فقط لعنصر مملوك مسبقًا، لا تمنح ملكية أبدًا.


## E12: تكامل مكافآت التقدُّم (Progression Rewards Integration)

XP **ليست عملة** - `docs/player-progression.md` يفصِّل هذا صراحة. لكن الإنجازات والمستويات قد تمنح عملة مكتسَبة (Default Earned Currency فقط، لا Premium) كمكافأة اختيارية.

- كل منح كهذا يمر حصرًا عبر `CurrencyWalletService::creditPending()` - لا Bypass، `canEarn()` يُفرَض كالعادة، يدخل نفس دورة Pending→Available المُثبَتة بـE12.1. *(تصحيح: مسوَّدة سابقة بهذا الملف ذكرت `creditAvailable()` ونوعًا باسم `TYPE_PROGRESSION_REWARD` - كلاهما لم يعودا موجودين بالكود الفعلي منذ تصحيح حقيقي أثناء بناء E12، صُحِّح هنا.)*
- `ProgressionRewardService` (بـ`app/Services/Progression/`) هي المصدر الوحيد لهذا المسار - لا تعديل مباشر على `CurrencyTransaction`/`Wallet` من أي كود تقدُّم.
- لا Seed لأي مكافأة Premium بالإنجازات/المستويات الافتراضية - راجع `docs/free-to-play-policy.md`.

## E13: تكامل XP مكافآت المهام (Quest XP Integration)

مكافأة XP لمهمة (`QuestDefinition.xp_reward`) تمر حصرًا عبر `XpService::grantXp()` بنوع مستقل `XpTransaction::TYPE_QUEST_REWARD` - لا خلط مع `TYPE_ACHIEVEMENT_REWARD`. نفس `ProgressionRewardService` مُوسَّعة بدالة `grantQuestRewards()` تتولى عملة/عنصر المهمة، بنفس القيود (لا Premium افتراضيًا، لا عنصر يدوي، لا StorePurchase وهمية). التفاصيل الكاملة: `docs/engagement-system.md`.

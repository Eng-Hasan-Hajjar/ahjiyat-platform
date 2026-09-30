
## E12: تكامل مكافآت التقدُّم (Progression Rewards Integration)

XP **ليست عملة** - `docs/player-progression.md` يفصِّل هذا صراحة. لكن الإنجازات والمستويات قد تمنح عملة مكتسَبة (Default Earned Currency فقط، لا Premium) كمكافأة اختيارية.

- كل منح كهذا يمر حصرًا عبر `CurrencyWalletService::creditAvailable()` بنوع جديد `TYPE_PROGRESSION_REWARD` - لا Bypass، `canEarn()` يُفرَض كالعادة.
- `ProgressionRewardService` (بـ`app/Services/Progression/`) هي المصدر الوحيد لهذا المسار - لا تعديل مباشر على `CurrencyTransaction`/`Wallet` من أي كود تقدُّم.
- لا Seed لأي مكافأة Premium بالإنجازات/المستويات الافتراضية - راجع `docs/free-to-play-policy.md`.

# E34 — حزمة التسليم (LiveOps & Retention Center)

> لا أوامر git من جهتي. GitHub قراءة فقط. كل ما يلي يُنسخ يدويًا إلى `C:\Users\engya\Desktop\puzzles\puzzle-gems-platform`.
> **انسخ محتوى المجلد `files/` فقط** (يحافظ على المسارات النسبية للمشروع). بقية ملفات الحزمة (هذا الدليل، `E34-PACK-SHA256.txt`، `E34-MODIFIED-DIFF-FOR-REVIEW.patch`، `E34-NEW-FILES-BUNDLE.md`) **للمراجعة فقط ولا تُنسخ لجذر المشروع**.
> الأساس: `e31e038` (v33.1) + E33 (كما هو عندك). هجرات جديدة: **2**. حزم جديدة: **0**.

## A) الملفات الجديدة (49) — المحتوى الكامل

المحتوى الكامل في `files/` وفي `E34-NEW-FILES-BUNDLE.md`. (المسار — عدد الأسطر)

- `app/Console/Commands/DispatchLiveOpsReminders.php` — 26
- `app/Filament/Pages/LiveOpsAnalyticsPage.php` — 46
- `app/Filament/Pages/LiveOpsPlannerPage.php` — 43
- `app/Filament/Resources/MissionPoolResource.php` — 136
- `app/Filament/Resources/MissionPoolResource/Pages/CreateMissionPool.php` — 27
- `app/Filament/Resources/MissionPoolResource/Pages/EditMissionPool.php` — 27
- `app/Filament/Resources/MissionPoolResource/Pages/ListMissionPools.php` — 17
- `app/Filament/Resources/MissionPoolResource/RelationManagers/QuestsRelationManager.php` — 84
- `app/Http/Controllers/EventCalendarController.php` — 26
- `app/Models/MissionPool.php` — 88
- `app/Models/UserMissionAssignment.php` — 38
- `app/Policies/MissionPoolPolicy.php` — 37
- `app/Services/LiveOps/Calendar/EventCalendarService.php` — 277
- `app/Services/LiveOps/Calendar/EventItem.php` — 61
- `app/Services/LiveOps/ComebackService.php` — 147
- `app/Services/LiveOps/LiveOpsException.php` — 12
- `app/Services/LiveOps/LiveOpsPlannerService.php` — 209
- `app/Services/LiveOps/LiveOpsReminderService.php` — 315
- `app/Services/LiveOps/MissionAssignmentService.php` — 438
- `app/Services/LiveOps/MissionCenterService.php` — 219
- `app/Services/LiveOps/MissionEligibilityRule.php` — 56
- `app/Services/LiveOps/MissionPoolService.php` — 227
- `app/Services/LiveOps/RetentionAnalytics.php` — 231
- `app/Services/LiveOps/StreakMilestoneService.php` — 115
- `app/Services/Progression/Achievements/StreakLongestDaysEvaluator.php` — 26
- `config/liveops.php` — 108
- `database/migrations/2026_10_28_000001_create_mission_pools_tables.php` — 59
- `database/migrations/2026_10_28_000002_create_user_mission_assignments_table.php` — 43
- `database/seeders/DemoE34Seeder.php` — 140
- `database/seeders/StreakMilestoneAchievementsSeeder.php` — 40
- `docs/liveops-retention-e34.md` — 157
- `resources/views/events/_item.blade.php` — 40
- `resources/views/events/index.blade.php` — 54
- `resources/views/filament/pages/liveops-analytics.blade.php` — 58
- `resources/views/filament/pages/liveops-planner.blade.php` — 60
- `resources/views/home/_missions-widget.blade.php` — 37
- `tests/Feature/LiveOpsE34/E34AdminTest.php` — 284
- `tests/Feature/LiveOpsE34/E34AnalyticsTest.php` — 164
- `tests/Feature/LiveOpsE34/E34CalendarTest.php` — 164
- `tests/Feature/LiveOpsE34/E34ComebackTest.php` — 140
- `tests/Feature/LiveOpsE34/E34ConcurrencyTest.php` — 199
- `tests/Feature/LiveOpsE34/E34DemoSeederTest.php` — 87
- `tests/Feature/LiveOpsE34/E34MissionCenterTest.php` — 149
- `tests/Feature/LiveOpsE34/E34MissionProgressTest.php` — 164
- `tests/Feature/LiveOpsE34/E34NotificationsTest.php` — 228
- `tests/Feature/LiveOpsE34/E34PerformanceTest.php` — 174
- `tests/Feature/LiveOpsE34/E34PoolRotationTest.php` — 235
- `tests/Feature/LiveOpsE34/E34StreakMilestoneTest.php` — 166
- `tests/Feature/LiveOpsE34/_support.php` — 190

## B) الملفات المعدَّلة (19) — نسخة كاملة في `files/` (استبدل الملف بالكامل)، والفروق الدقيقة في `E34-MODIFIED-DIFF-FOR-REVIEW.patch`

كل ملف أدناه مبني على نسخة E33 التي عندك؛ التغيير مضاف فقط (لا إزالة سلوك).

| الملف | ما تغيّر |
|---|---|
| `.env.example` | كتلة `# E34` بمفاتيح `LIVEOPS_*` (كلها معلَّقة/اختيارية) |
| `.github/workflows/ci.yml` | حزمة LiveOpsE34 ضمن الخطوة المستهدفة على SQLite + خطوة MySQL جزئية (Concurrency/PoolRotation/Performance) بعد خطوة E33 |
| `app/Filament/Resources/QuestDefinitionResource.php` | منع تغيير `period_type` لمهمة مستعملة/منتمية لمجموعة، `ends_at بعد starts_at`، أعمدة (الجدولة، عدد المجموعات، عدد التقدّم) |
| `app/Http/Controllers/HomeController.php` | ودجت «اليوم» من `MissionCenterService::widgetFor` (قراءة فقط) وأعداد المهام الظاهرة فعلًا |
| `app/Http/Controllers/PlayerQuestsController.php` | الصفحة تعمل عبر `MissionCenterService` (يخدم `/missions` و`/quests`) |
| `app/Models/QuestDefinition.php` | علاقة `missionPools()` |
| `app/Providers/AppServiceProvider.php` | محدِّدا المعدّل `missions-read` و`events-read` |
| `app/Services/Engagement/QuestDefinitionInvariantGuard.php` | `assertPooledPeriodStable` |
| `app/Services/Engagement/QuestService.php` | `visibleQuestsFor` + `isVisibleTo` (إسناد ظهور فقط؛ التقدّم/المكافأة كما هي) |
| `app/Services/Notifications/NotificationType.php` | 4 أنواع: MissionExpiring، WeeklyMissionsAvailable، EventStartingSoon، ComebackAvailable (بلا فئة جديدة) |
| `app/Services/Progression/AchievementEvaluatorRegistry.php` | تسجيل `streak_longest_days` |
| `app/Services/Progression/GameplayProgressionService.php` | خطاف معالم السلسلة داخل `try/catch` |
| `config/permissions.php` | صلاحيتا `liveops.view` و`liveops.manage` |
| `database/seeders/RolesAndPermissionsSeeder.php` | `administrator` و`content-manager`: عرض + إدارة؛ `moderator`: عرض فقط |
| `resources/views/home/_dashboard.blade.php` | تضمين عنصر المهام |
| `resources/views/quests/_quest-card.blade.php` | بطاقة مهمة بحالة معالجة المكافأة |
| `resources/views/quests/show.blade.php` | صفحة مركز المهام (أقسام: اليوم/الأسبوع/السلسلة/إنجازات قريبة/أحداث/عودة) |
| `routes/console.php` | جدولة `liveops:remind` ساعيًا |
| `routes/web.php` | `/missions` و`/quests` و`/events` |

## C) الهجرات (2) — لا تعديل لأي هجرة قديمة

- `database/migrations/2026_10_28_000001_create_mission_pools_tables.php` (`mission_pools` + `mission_pool_quests`)
- `database/migrations/2026_10_28_000002_create_user_mission_assignments_table.php` (`user_mission_assignments`، `UNIQUE(user, quest, period_key)`، المجموعة RESTRICT)

لا أعمدة مالية، لا float/decimal، أسماء الفهارس ≤ 64.

## D) الإعدادات والبيئة

- ملف جديد `config/liveops.php` (كل قيمه من `LIVEOPS_*` بافتراضات آمنة).
- كتلة `.env.example` (اختيارية؛ لا يلزم تغيير `.env` للتشغيل بالافتراضات).
- بعد النسخ: `php artisan migrate` ثم `php artisan db:seed --class=RolesAndPermissionsSeeder` (يمنح `liveops.*`) ثم `php artisan db:seed --class=StreakMilestoneAchievementsSeeder` (معالم السلسلة كإنجازات؛ idempotent).
- الجدولة: `liveops:remind` ساعيًا ضمن `schedule:run` القائم (لا عامل جديد).

## E) أوامر محلية (PowerShell، من جذر المشروع)

```powershell
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=StreakMilestoneAchievementsSeeder
php artisan optimize:clear
npm run build          # مرة واحدة: صفحات Blade الجديدة تستعمل فئات Tailwind تُجمَّع عند البناء (لا ملفات JS/CSS مصدرية جديدة)

# اختياري: بيانات عرض (ترفض الإنتاج)
php artisan db:seed --class=DemoE34Seeder

# اختبارات E34 (SQLite)
php vendor/bin/pest tests/Feature/LiveOpsE34
```

## F) اختبارات MySQL (قاعدة اختبار فقط؛ اسمها ينتهي بـ`_test_mysql`)

```powershell
$env:APP_ENV="testing"; $env:DB_HOST="127.0.0.1"; $env:DB_PORT="3306"
$env:DB_DATABASE="ahjiyat_platform_test_mysql"; $env:DB_USERNAME="root"; $env:DB_PASSWORD="<كلمة مرور قاعدة الاختبار>"
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/LiveOpsE34
php vendor/bin/pest --configuration=phpunit.mysql.xml.dist tests/Feature/DatabaseE31
```

لا تُشغّل أي إعداد MySQL على قاعدة العمل `ahjiyat_platform` (RefreshDatabase يحذف جداولها).

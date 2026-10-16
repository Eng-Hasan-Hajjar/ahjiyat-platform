# E28 — منظومة المبدعين، نشر أحجيات المجتمع والإشراف عليها

المبدع المعتمَد ينشئ أحجيات مجتمع من **استوديو المبدع** (Blade + Alpine + Tailwind، وليس Filament)، يرسلها للمراجعة، فتُنشر بعد اعتماد مراجع. تظهر في صفحة المبدع العامة وفي البحث والاكتشاف (E27) بوسم **«من المجتمع»** المميِّز عن الرسمي.

> **القاعدة الأولى: أحجيات المجتمع بلا أي أثر اقتصادي.** لا جواهر، لا XP، لا مهام، لا سلسلة، لا إنجازات، لا تأهيل حملات، لا منافسات، لا لوحة صدارة رسمية. هذا مفروض **بالبناء** (أكثر من طبقة)، لا بالاتفاق.

## 1. العزل بالبناء (Isolation by construction)

| الطبقة | ما يمنع الأثر |
|---|---|
| **جدول لعب منفصل** | اللعب يُسجَّل في `community_puzzle_attempts` (لا `puzzle_attempts`). فلا شيء من محرّكات المكافآت/المهام/السلسلة/الإنجازات/الصدارة/التأهيل يراه أصلًا. |
| **أحجية قانونية بأصل `community`** | كل نسخة معتمَدة تُنتج صفّ `puzzles` بـ`origin='community'`, `is_active=false`, ومكافآت صفر. حقل `origin` غير قابل للإسناد الجماعي (`forceFill` فقط). |
| **حراسة النموذج** | `Puzzle` يُجبر المكافآت صفرًا عند إنشاء صف مجتمع، ويمنع تعديله وحذفه (`LogicException`)؛ التاريخ لا يُعاد كتابته. |
| **حراسة المحرّكات** | `PuzzleAttemptService::attempt/purchaseHint` و`GameSessionService::start` يرفضان أحجية مجتمع (`RuntimeException`)؛ `CompetitiveEligibility` يعيد «أحجيات المجتمع لا تصلح للمنافسات.»؛ مسارات `/puzzles` وAPI تعيد 404؛ منتقيات لوحة الإدارة تقرأ `Puzzle::official()`. |
| **لا محرّك ثانٍ** | التحقق من الجواب بـ`GameTypeRegistry::validatorFor($puzzle)->check()` نفسه، والعرض بقوالب `games.legacy-input` / `games.sequence` / `games.memory` نفسها. لا `CommunityPuzzleEngine` ولا مدقّق ثانٍ. |

## 2. نموذج البيانات (ترحيلان جديدان فقط)

- `2026_10_20_000001_create_creator_ecosystem_tables`: `creator_profiles`، `creator_puzzles` (العمل)، `creator_puzzle_revisions` (النسخ)، `community_puzzle_attempts`، `community_puzzle_reports`.
- `2026_10_20_000002_add_origin_to_puzzles_table`: عمود `origin` (`official` افتراضيًا | `community`) بفهرس.

**`creator_profiles`**: `user_id` فريد، `slug` فريد (ASCII آمن، بلا بريد ولا رقم داخلي)، `bio`، `status` (`pending|approved|rejected|suspended`)، بيانات المراجعة (`reviewed_by/at`, `review_note`)، `approved_at` (تاريخ الانضمام، لا يُكتب فوقه).

**`creator_puzzles` (العمل)**: `public_id` ULID (المعرّف الوحيد في الروابط)، مؤشرا `latest_revision_id` / `published_revision_id`، **لقطة النشر** (`published_puzzle_id`, `published_title`, `published_category_id`, `published_difficulty`, `published_at`)، `archived_at`، `hidden_at/by/reason/note`، `is_visible` (مشتقّ في حدث الحفظ)، `plays_count` (لاعبون مميَّزون، عرض فقط).

**`creator_puzzle_revisions`**: `revision_number`، `status` (`draft|pending_review|changes_requested|approved|rejected`)، `kind`، `title`، `difficulty`، **`content` مشفَّر** (prompt/hint/answer/choices/items/faces)، `image_path` (يضعه المخدّم وحده)، بيانات المراجعة، `puzzle_id` فريد (الأحجية القانونية لهذه النسخة).

**الفهارس**: `(status, submitted_at)` لطابور المراجعة؛ `(is_visible, published_at)` للجديد؛ `(is_visible, published_title)` لبحث العنوان بالبادئة؛ `(creator_profile_id, is_visible, published_at)` لصفحة المبدع؛ `(user_id, puzzle_id)` و`(creator_puzzle_id, user_id)` و`created_at` للعب؛ `open_marker` يجعل «بلاغ مفتوح واحد لكل مستخدم/عمل» قيدًا بقاعدة البيانات. `puzzle_id` بالنسخ و`published_puzzle_id` بالعمل `restrictOnDelete` (لا حذف تتابعي للتاريخ).

## 3. الأهلية والصلاحيات

**الأهلية للاستوديو** (`CreatorEligibility`): ملف مبدع **معتمَد** + بريد **موثَّق** + حساب **غير مجمَّد**. ليست صلاحية إدارة.

**صلاحيات الإشراف** (RBAC، `config/permissions.php`):

| الصلاحية | الدور الافتراضي | تتيح |
|---|---|---|
| `creators.view` | administrator، moderator | عرض المبدعين والنسخ والبلاغات بلوحة الإدارة |
| `creators.review` | administrator، moderator | اعتماد/طلب تعديل/رفض النسخ، إخفاء/استعادة المنشور، معالجة البلاغات، معاينة المراجع |
| `creators.manage` | administrator | اعتماد/رفض/تعليق/استعادة **المبدعين أنفسهم** |

كل إجراء إداري يُدقَّق بـ`OperationalAuditService` (من فعل، على ماذا، بأي ملاحظة).

## 4. التدفقات

**الانضمام**: لاعب موثَّق ← `POST /creator/apply` (نبذة 20–600 حرف) ← `pending` (طلب مفتوح واحد) ← مراجع بـ`creators.manage` يعتمد/يرفض بسبب إلزامي ← إشعار ← عند الرفض يمكن إعادة التقديم. **التعليق** (`suspended`) يُخفي كل منشورات المبدع فورًا؛ **الاستعادة** تُعيدها (ما لم يخفِها الإشراف يدويًا لسبب آخر).

**النشر**:
```
مسودة ─(إرسال)→ قيد المراجعة ─(اعتماد)→ منشورة
   ↑                 ├─(طلب تعديل)→ تحتاج تعديلات ─(تعديل + إعادة إرسال)→ قيد المراجعة
   └─(سحب)───────────┘└─(رفض)→ مرفوضة (مجمَّدة؛ نسخة جديدة تبدأ من العمل)
```
المسودة تُعدَّل وتُحذف بحرية؛ المرسَلة مقفلة حتى السحب؛ المعتمَدة والمرفوضة **غير قابلة للتعديل** (نموذجًا وخدمةً ومسارًا).

**التعديل بعد النشر (Versioning)**: «مراجعة جديدة» تُنشئ نسخة جديدة (مسودة) من آخر نسخة؛ **المنشورة الحالية تبقى حيّة** ومتاحة للاعبين حتى تُعتمد الجديدة؛ عند الاعتماد تُنتج أحجية قانونية **جديدة** (لا تعديل القديمة) وتنتقل لقطة النشر إليها. المحاولات التاريخية تبقى على `puzzle_id` القديم، ولا تُمسّ.

**الظهور**: `is_visible` = منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد ∧ حساب غير مجمَّد. `CreatorVisibilityService::reconcile` يُستدعى بعد تعليق/استعادة المبدع، واعتماده، وتجميد/فكّ تجميد حسابه. أسباب الإخفاء: `moderation | creator_suspended | account_frozen`.

## 5. أنواع الأحجيات وحقول السلطة

**المدعوم**: جواب كتابي `text`، اختيار من متعدد `multiple_choice`، أحجية بصورة `image`، ترتيب تسلسل `sequence`، بطاقات ذاكرة `memory`. **المؤجَّل**: `spot_difference` (يحتاج GameSession ومحرر نقاط وصورتين).

المبدع **لا يختار** renderer ولا validation_type ولا score_mode ولا game_type: `GameTypeRegistry::defaultsFor()` يشتقّها مخدّميًا. **قائمة الحقول الممنوعة** (`config/creators.php › forbidden_fields`): مجرّد وجودها بالطلب — حتى فارغة — يُرفض بخطأ تحقق صريح (مكافآت، يومية، حملات/مواسم/منافسات/تحديات، renderer/validation_type/score_mode/game_type/game_config/solution_data/answer_hash، حالة وملكية وإخفاء…). النصوص تُرفض عند الإدخال إن احتوت وسوم HTML (`NoMarkup`) وتُعرض دائمًا بـBlade المهرَّب.

## 6. الأمان

- **IDOR**: كل مسار `{work}` يُحلّ بمعرّف ULID عام **واستعلام محصور بالمالك** (غير المالك: 404، بلا أثر). معرّف رقمي تسلسلي لا يحلّ شيئًا.
- **الوسائط**: JPEG/PNG/WEBP فقط بفحص MIME حقيقي + `getimagesize` (لا SVG ولا ملف PHP متنكّر)، حدّ حجم وأبعاد، اسم UUID من المخدّم داخل مجلد المالك (`community/puzzles/{profile_id}/`)، اسم العميل لا يصل للمسار أبدًا. `ownedBy()` يرفض `..` و`\0` وأي مسار خارج مجلد المالك؛ الحذف/النسخ لا يمسّان ملف غيره.
- **حماية الحل**: الجواب والتلميح والاختيارات الصحيحة **لا تصل أي صفحة عامة** ولا JSON بحث/اكتشاف. لوحة الحل في المعاينة تظهر **للمراجع فقط** (`creators.review`)، ولا تظهر لصاحب العمل. محتوى النسخة مشفَّر ولا يُحمَّل لقوائم الطابور.
- **CSRF**: كل مسارات E28 داخل مجموعة `web`.
- **حدود المعدل**: `creator-apply` (2/د، 5/س)، `creator-draft-create` (5/د، 30/س)، `creator-submit` (5/د، 20/س؛ تشمل السحب و«مراجعة جديدة»)، `community-report` (5/د، 20/س)، و`puzzle-attempt` / `puzzle-hint` القائمة للعب والمعاينة.
- **سقوف لكل مبدع** (`CREATOR_MAX_WORKS`, `CREATOR_MAX_OPEN_DRAFTS`, `CREATOR_MAX_PENDING`).
- **المعاينة** (`/creator/puzzles/{work}/preview`، `/community/review/{revision}`): غير عامة وغير مفهرَسة (`noindex`)، بالمدقّق والعارض نفسيهما، **بلا أي تسجيل**.

## 7. مسارات التطبيق

عام: `GET /creators/{slug}`، `GET /community/puzzles`، `GET /community/puzzles/{ulid}`.
لاعب موثَّق غير مجمَّد: `POST /community/puzzles/{ulid}/{attempt|hint|report}`، `GET|POST /creator/apply`.
استوديو (مبدع معتمَد): `/creator` (لوحة)، `/creator/puzzles` (قائمة مرقَّمة بتصفية الحالة)، `create`، `store`، `edit/update/destroy`، `image.destroy`، `preview` + `preview.check`، `submit/withdraw/revise/archive/unarchive`.
مراجع (`creators.review`): `GET /community/review/{revision}`، `POST …/check`.
لوحة الإدارة (Filament، مجموعة «الإشراف»): `CreatorProfileResource`، `CreatorPuzzleRevisionResource` (طابور مرقَّم بحجم `moderation_page_size`)، `CommunityPuzzleReportResource`، ومخطط إحصاءات `CreatorEcosystemStatsWidget`.

## 8. البلاغات والإشراف

أسباب البلاغ (قائمة مغلقة): `inappropriate | copyright | misleading | broken | spam | other`. بلاغ مفتوح واحد لكل مستخدم/عمل (خدميًا وبقيد قاعدة بيانات)، ولا يبلّغ المبدع عن أحجيته. المراجع: **رفض** البلاغ، **تمت المعالجة**، أو **معالجة + إخفاء المنشور** (يحسم كل البلاغات المفتوحة عليه). إخفاء/استعادة المنشور يُشعر المبدع ويُدقَّق.

**أحداث التدقيق**: `creator_approved`، `creator_rejected`، `creator_suspended`، `creator_restored`، `creator_submission_approved`، `creator_submission_changes_requested`، `creator_submission_rejected`، `community_puzzle_hidden`، `community_puzzle_restored`، `community_report_dismissed`، `community_report_actioned`.

**الإشعارات** (فئة «اجتماعي»، تُرسل بعد الـcommit وفشلها لا يُلغي الإجراء): `creator_approved`، `creator_rejected`، `submission_approved`، `submission_changes_requested`، `submission_rejected`، `publication_hidden`.

## 9. البحث والاكتشاف (E27)

- **البحث**: نوع مستقل `community` («أحجيات المجتمع»، شارة «من المجتمع · الصعوبة»، رابط `/community/puzzles/{ulid}`)، يبحث بـ`published_title` **وحده** (لا محتوى ولا جواب ولا تلميح: لا «أوراكل» لتخمين الجواب)، بنفس قاعدة الظهور العامة، وبفهرس `(is_visible, published_title)` في الوضع الكبير. +1 استعلام لكل بحث.
- **الاكتشاف**: أقسام `community-trending` و`community-new` و`community-for-you` (للمسجَّل) تظهر فقط حين تُوجد نتائج؛ سقف **عملين لكل مبدع** بكل قسم (تنوّع المبدعين)؛ «مقترحة لك» تستبعد المعروض والملعوب وأعمال المشاهد نفسه. **الرائج** = لاعبون **مميَّزون** بحسابات موثَّقة غير مجمَّدة خلال نافذة E27، بحد أدنى `CREATOR_TRENDING_MIN_PLAYERS`، والكاش يحمل **معرّفات ودرجات فقط** (إخفاء الإشراف يسري فورًا). لا تأثير على رائج/توصيات الأحجيات الرسمية.

## 10. التحليلات (بلا مال)

لوحة المبدع: منشورة، لاعبون مميَّزون، محاولات، نسبة الإكمال، نشاط آخر 7 أيام — استعلامان مجمَّعان فقط، مخزَّنان (`CREATOR_ANALYTICS_TTL`، 120ث)، أرقام مجمَّعة بلا هوية لاعبين. الإداري: مبدعون/طلبات/مراجعات/منشورات/لعب/بلاغات (كاش 60ث). **لا أرباح، ولا محفظة، ولا رعاية، ولا ترقية رسمية.**

## 11. الأداء (مقيس على SQLite)

لوحة المبدع 19 استعلامًا (ثابتة بين 3 و25 عملًا)، قائمة الاستوديو 12، صفحة المبدع العامة 7، قائمة المجتمع 6، صفحة العمل 5 (ضيف) / 17 (لاعب)، طابور Filament 6 (ثابت بين 5 و25 في الانتظار، مرقَّم بـ20). البحث: +1 استعلام للمصدر الجديد (سقف suggest لضيف 11). كل الاختبارات بنيوية (عدد/شكل)، لا زمنية.

## 12. التشغيل

- **ENV** (كلها اختيارية، بقيم افتراضية، ومعلَّقة بـ`.env.example`): `CREATOR_PUZZLE_MAX_ATTEMPTS`=3، `CREATOR_MAX_WORKS`=100، `CREATOR_MAX_OPEN_DRAFTS`=20، `CREATOR_MAX_PENDING`=5، `CREATOR_IMAGE_MAX_KB`=4096، `CREATOR_ANALYTICS_TTL`=120، `CREATOR_TRENDING_MIN_PLAYERS`=2.
- **أمر**: `php artisan creators:prune-media [--hours=24] [--dry-run]` — يحذف صور المبدعين **اليتيمة** الأقدم من مهلة الأمان فقط (لا مرتبطًا ولا حديثًا)؛ مجدول أسبوعيًا (`withoutOverlapping` + `onOneServer`). صحة النظام لا تعتمد عليه.
- **Demo اختياري** (يرفض الإنتاج، idempotent، بالخدمات الرسمية فقط، لا يمسّ محافظ/XP/محاولات رسمية): `php artisan db:seed --class=DemoCreatorSeeder`. حسابات `demo-*@creators.test` بكلمة المرور `password` للبيئات المحلية فقط.
- **الاختبارات**: `php artisan test tests/Feature/CreatorE28` (أرقام الاختبارات والحالات بالتقرير).

## 13. حدود معروفة وقرارات مؤجَّلة

- صور المسودات على القرص `public` بأسماء UUID غير قابلة للتخمين (لا رابط موقَّع)؛ لا تجريد EXIF (يُنصح بإضافته عند توفر معالج صور مناسب).
- `spot_difference` مؤجَّل. لا مكافآت ولا اشتراكات ولا رعاية ولا ترقية رسمية للأحجيات المجتمعية (قرارات منتج لمرحلة لاحقة، لا تتطلب كسر العزل: أي ترقية لاحقة ستكون **نسخة رسمية جديدة** وليس تحويل صف مجتمع).
- `plays_count` عدّاد ساخن بزيادة صف (مقبول بحجم MVP؛ يتحوّل لتجميع دوري إن لزم).
- لا فحص آلي لمحتوى المبدع (نصوص/صور): الاعتماد البشري هو الضابط، والبلاغات خط الدفاع الثاني.

# المرحلتان الثالثة والرابعة — جدول الحالة والأدلة

سلسلة «الصفحة التي لم تُكتب» · المرحلة 3 «الساعة المتوقفة» · المرحلة 4 «الأبواب الثلاثة».

## 1) أساس العمل (SHA)

- بُني العمل على الإيداع `282d89290733def2e00fa2116eda3a14c0e80f53` من المستودع `Eng-Hasan-Hajjar/ahjiyat-platform`.
- عند بدء العمل تحققتُ أن هذا الإيداع هو رأس `origin/main`، وأن النسخة التي أعمل عليها تطابقه.
- **لم أعد التحقق عند التسليم**: الوصول إلى GitHub لم يعد مفعَّلًا في هذه الجلسة. إن تقدّم `main` قبل أن تطبّق الملفات فقارن الملفات المعدَّلة أدناه (قسم «الملفات المعدَّلة») بنسختك قبل النسخ.
- المراجع: وثيقة المرحلة الثالثة (الصياغة التفاعلية المنقّحة، ١٣ نوفمبر ٢٠٢٦) ووثيقة المرحلة الرابعة (النص التفاعلي ومراجعة الاستمرارية، ١٦ نوفمبر ٢٠٢٦). نصوص المشاهد ومفاتيحها وألغازها منهما.

## 2) مفتاح الحالة

| الحالة | المعنى |
|---|---|
| **موجود ومطابق** | كان في الأساس ويفي بما تطلبه الوثيقتان دون تعديل؛ أعدتُ استعماله كما هو. |
| **موجود جزئيًا** | كان في الأساس ما يقرب منه لكنه لا يكفي؛ وسّعتُه دون كسر ما كان. |
| **غير موجود** | لم يكن في الأساس؛ أضفتُه. |

## 3) القدرات التقنية (النظام السينمائي القائم)

| # | المطلوب | الحالة | الدليل في الأساس (قبل عملي) | ما فعلته |
|---|---|---|---|---|
| 1 | رقعة 8×8 بحصان وآثار محتملة (لغز الحصان) | **غير موجود** | `InteractionEngine::KINDS` (السطر 18) فيه 11 نوعًا: hotspot, container, sequence, drag_drop, choose_item, compare, reveal_code, flashlight, answer, inspect, multi_lock — لا رقعة. | أضفت النوع `board_move` في المحرّك نفسه (لا محرّك جديد): التحقق، `publicView` بقائمة بيضاء، `evaluate`. |
| 2 | اتجاه الرقعة: الصف 1 أسفل والعمود 1 يسار | **غير موجود** | لا ذكر لرقعة أصلًا. | الأصل ثابت `bottom-left` في المحرّك (`BOARD_ORIGIN`) ولا يُقرأ من تعريف المشهد؛ اختبار PLM34E-02. |
| 3 | قبول الأثر (3,4) وحده من الآثار المعروضة | **غير موجود** | — | `board_move` يقبل: أثرًا معروضًا + هو الصحيح + نقلة حصان قانونية فقط؛ ويُرفض المعروض الآخر والقانوني غير المعروض (3,6) والمدخل المشوَّه؛ PLM34E-03. |
| 4 | قفل 4 خانات يقبل 1145 و١١٤٥ | **موجود ومطابق** | النوع `multi_lock` (المحرّك: السطر 191 للتحقق و295 للعرض) بتطبيع الأرقام العربية/الفارسية إلى لاتينية؛ تغطيه CIN2E-04 في `CinStage2EngineTest`. وتستعمله المرحلة الثانية في `stage2_cabinet_locks`. | أعدتُ استعماله بلا تعديل (خانتان للساعة + خانتان للدقيقة). PLM3-04 يثبت قبول 1145 و١١٤٥ ورفض 3618. |
| 5 | فحص الساعة ثم قلبها لقراءة الظهر | **موجود ومطابق** | `inspect` مع `back` (المحرّك: 167–176 للتحقق و286–291 للعرض)؛ تغطيه CIN2E-03. | أعدتُ استعماله: `stage3_clock_inspect` (العقربان ثم ظهر الساعة). |
| 6 | لوحات أ/ب/ج واختيار ب وحدها | **موجود ومطابق** | `choose_item` (المحرّك: 86–88). | أعدتُ استعماله: `stage3_plates_choice` (الحل `plate_b`). |
| 7 | التسجيل كنص مع مشهد فيديو | **موجود ومطابق** | `config.transcript` للمشاهد (`CinematicPlayService.php:260`، وتحذير `transcript_missing` في `CinematicReadinessChecker.php:224`). | أعدتُ استعماله؛ لا صوت ولا وسيط مُختلَق. |
| 8 | سجل الأدلة (يظهر الدليل بعد إنهاء مشهده فقط) | **موجود ومطابق** | `CinematicEvidenceLog` (`config.evidence`). | أعدتُ استعماله؛ PLM3-05 وPLM4-05 يثبتان ألا دليل قبل إنهاء المشهد. |
| 9 | وضع اللوحة ب على الباب (سحب أو اختيار + تأكيد) مع تحقق من الخادم | **موجود ومطابق** | `drag_drop`: الواجهة في `play.blade.php` (السطر 717–731: اختيار عنصر ثم مكان أو سحب، وزر «تحقق» لا يُفعَّل قبل اكتمال الوضع)، والخادم يقارن `placements` بالمتوقَّع (`InteractionEngine.php:370–380`). | أعدتُ استعماله: `stage4_door_match` بالعنصر `plate_b` والأهداف الثلاثة؛ الضغط على اسم باب وحده لا يكفي (PLM4-04). |
| 10 | أسئلة استنتاج: الاستنتاج **والمصدر** معًا | **غير موجود** | لا نوع بهذا المعنى؛ أقرب ما فيه `compare` (خيار واحد بلا مصدر). | أضفتُ النوع `inference`: استنتاج + المجموعة الصحيحة تمامًا من المصادر؛ الإجابات المدعومة تُحفظ؛ PLM34E-04/05. |
| 11 | بطاقة انتقال بين المراحل | **موجود ومطابق** | مشهد `transition` (يستعمله `stage2_end`). | أعدتُ استعماله: `stage3_end` و`stage4_end`. |
| 12 | تعليق مشهد على مفتاح لم يُعتمد بعد | **موجود ومطابق** | `unlock_rule.requires` مع مفتاح غير محلول ⇒ بند `unlock_rule_invalid` في فاحص الجاهزية فيمنع النشر ولا يفتح للاعب. | استعملته للوصلة 2→3 (انظر `SEAM-STAGE2-TO-STAGE3.md`). |
| 13 | خانات الوسائط (فيديو، صورة المشهد) وفاحص الجاهزية | **موجود ومطابق** | خانتا `video` و`poster`؛ `scene_image_missing` و`video_missing` في `CinematicReadinessChecker`. | الجدول المبدئي للوسائط (`MEDIA-TABLE-PRELIMINARY.csv`) مبنيّ على ما يمنعه الفاحص فعلًا؛ PLM34M-02. |
| 14 | أداة استيراد محتوى آمنة (إضافة فقط) | **موجود جزئيًا** | `Stage2ContentInstaller` + الأمر `cinematic:install-stage2`: للمرحلة الثانية وحدها. | أضفتُ `StageAddonInstaller` والأمر `cinematic:install-stage` (انظر `APPLY-AND-ROLLBACK.md`) دون لمس أداة المرحلة الثانية. |
| 15 | محتوى المرحلة الثالثة | **غير موجود** | لا `stage_3` ولا `stage3_` في `app` ولا `database` ولا `tests` ولا `docs`. مشهد `stage2_end` في المرحلة الثانية يحمل عنوان «المرحلة الثالثة» فقط (بطاقة انتقال، وليس محتوى). | قالب `library-mystery-stage-3.json`: 7 فصول و15 مشهدًا. |
| 16 | محتوى المرحلة الرابعة | **غير موجود** | لا `stage_4` ولا `stage4_`. | قالب `library-mystery-stage-4.json`: 6 فصول و15 مشهدًا. |

## 4) خريطة الوثيقتين إلى المفاتيح

### المرحلة الثالثة — «ستة مشاهد وثلاثة ألغاز» → قالب بسبعة فصول و15 مشهدًا

| مشهد الوثيقة / لغزها | الفصل | المفاتيح (`stable_key`) | النوع |
|---|---|---|---|
| المشهد 1: عند نهاية الممر | `ch3_corridor` | `stage3_corridor_video` (معلّق على مفتاح الوصلة) ← `stage3_corridor_inspect` | فيديو + فحص (3 مناطق) |
| المشهد 2: أثر الحصان | `ch3_knight` | `stage3_board_intro_video` ← `stage3_knight_puzzle` | فيديو + **اللغز 1** (`board_move`) |
| المشهد 3: خزانة الوقت | `ch3_cabinet` | `stage3_cabinet_video` ← `stage3_time_lock` | فيديو + **اللغز 2** (`multi_lock`، 1145) |
| المشهد 4: الساعة التي لا تدق | `ch3_clock` | `stage3_clock_video` ← `stage3_clock_inspect` | فيديو + فحص مع `back` |
| المشهد 5: رسالة تحت الحامل | `ch3_recording` | `stage3_holder_video` (أضافه القالب) ← `stage3_recorder_lever` ← `stage3_recording_video` | فيديو + فحص (الذراع) + فيديو التسجيل |
| (تتمة المشهد 5) اللوحة التي تطابق الأثر | `ch3_plates` | `stage3_plates_choice` ← `stage3_latch_video` (أضافه القالب) | **اللغز 3** (`choose_item`، اللوحة ب) + فيديو |
| المشهد 6: ثلاثة أبواب | `ch3_doors` | `stage3_doors_video` ← `stage3_end` | فيديو + بطاقة انتقال؛ **لا اختيار باب** |

«أضافه القالب»: مشاهد فيديو أضفتُها ليكون لكل حدث وسيط يسلّمه محمد (حجرة تحت الحامل، تحرر المزلاج، ومثلها `stage4_lamp_video`). موسومة `scene_added_for_handoff` في القالب وفي جدول الوسائط، وليست من نص الوثيقة.

### المرحلة الرابعة — «ستة مشاهد ولغزان رئيسيان» → قالب بستة فصول و15 مشهدًا

| مشهد الوثيقة / لغزها | الفصل | المفاتيح | النوع |
|---|---|---|---|
| المشهد 1: ما تركته الحركة | `ch4_doors` | `stage4_doors_video` (يتطلب `stage3_end`) ← `stage4_doors_inspect` | فيديو + فحص (6 مناطق) |
| المشهد 2: دليلان لباب واحد | `ch4_match` | `stage4_door_match` | **اللغز 1** (`drag_drop`: اللوحة ب على الباب الأيسر) |
| المشهد 3: غرفة تجمع الأسئلة | `ch4_room` | `stage4_room_video` ← `stage4_photo_inspect` (معلّق على مفتاح الوصلة) | فيديو + فحص (الشخص الإضافي وبطاقة الملف) |
| المشهد 4: ما الذي تقوله الأدلة | `ch4_evidence` | `stage4_evidence_room` ← `stage4_inference_q1` ← `q2` ← `q3` ← `stage4_inference_result` | سرد + **اللغز 2** (ثلاثة أسئلة `inference`) + سرد |
| المشهد 5: السؤال الذي عاد إلى آدم | `ch4_question` | `stage4_question_video` | فيديو |
| المشهد 6: الساعة الحجرية | `ch4_stone` | `stage4_stone_video` ← `stage4_stone_inspect` ← `stage4_lamp_video` (أضافه القالب) ← `stage4_end` | فيديو + فحص (القضيب والظل) + فيديو + بطاقة انتقال |

**لا لغز للمرحلة الخامسة**: تنتهي الرابعة أمام الساعة الحجرية والمصباح في الحامل، وبطاقة `stage4_end` مجرد انتقال («ساعة الظل»).

## 5) ما لم يُكتب عمدًا

- لا اختيار باب في المرحلة الثالثة (يبدأ في الرابعة).
- لا إجابة عن: سبب توقف الساعة، هوية الثامن، صدق صاحب التسجيل، معنى عبارة السابع، سر الكتاب. تبقى أسئلة مفتوحة كما في الوثيقتين.
- لا نص بديل مؤلَّف يُعرض على اللاعب دون وسم `editorial=proposal` (ينتظر مراجعة ياسمين والمالك). كل ما ألّفتُه مساعدًا: وصف بديل لصورة/فيديو، وتلميحات، وتسميات أزرار.
- ملخّصات المراحل والفصول التي تظهر علنًا في صفحة السلسلة خالية من أي حل (محروسة باختبار PLM3-01 وPLM4-01).

## 6) الملفات

### جديدة
- `app/Services/Cinematic/Demo/StageAddonInstaller.php`
- `app/Console/Commands/CinematicInstallStage.php`
- `database/demo/cinematic/library-mystery-stage-3.json`
- `database/demo/cinematic/library-mystery-stage-4.json`
- `tests/Feature/CinematicStories/_plm34_support.php`
- `tests/Feature/CinematicStories/CinPlm34EngineTest.php`
- `tests/Feature/CinematicStories/CinPlm3ContentTest.php`
- `tests/Feature/CinematicStories/CinPlm4ContentTest.php`
- `tests/Feature/CinematicStories/CinPlm34MediaTableTest.php`
- `docs/PLM-STAGES-03-04/*` (هذا المجلد)

### معدَّلة (ملفات كاملة)
- `app/Services/Cinematic/Interactions/InteractionEngine.php` — النوعان `board_move` و`inference`.
- `app/Services/Cinematic/Admin/SceneFormCodec.php` — تحويل نموذج المحرّر للنوعين.
- `app/Filament/Pages/CinematicBuilderPage.php` و`resources/views/filament/pages/cinematic-builder.blade.php` — حقول المحرّر للنوعين.
- `resources/views/cinematic/play.blade.php` (سطر واحد) و`partials/immersive-scripts.blade.php` و`partials/cin2-stage.blade.php` و`partials/cin2-paper.blade.php` و`partials/cin2-scripts.blade.php` و`partials/cin2-styles.blade.php` — واجهة اللاعب للنوعين.
- `tests/Feature/CinematicStories/CinInteractionTest.php` — اختبار INT-01 فقط: قائمة الأنواع صارت 13 بدل 11 (تعديل لازم لإضافة النوعين، وليس تخفيفًا لاختبار).

لم تُعدَّل المرحلتان الأولى والثانية: لا قالباهما ولا `Stage1DemoInstaller` ولا `Stage2ContentInstaller` ولا الأمر `cinematic:install-stage2` (ويثبت ذلك PLM3-02 وPLM3-03b وPLM4-02 بمقارنة بصمة الصفوف قبل التثبيت وبعده).


# خارطة تقنية — موسم أصيل (كامل الـ22 خطوة)

تصنيف كل خطوة حسب حالتها التقنية. `READY_GENERIC` = تعمل بالكامل الآن عبر المحرك العام. `GENERIC_WITH_PLACEHOLDER_CONTENT` = المحرك جاهز، المحتوى Demo فقط. `CLIENT_CONTENT_REQUIRED` = بانتظار مواد من العميل. `CLIENT_TECH_DECISION_REQUIRED` = بانتظار قرار معماري.

| #  | العنوان                 | Stage/Gate | Kind                               | Game Type                          | الحالة                                                             |
| -- | ------------------------------ | ---------- | ---------------------------------- | ---------------------------------- | ------------------------------------------------------------------------ |
| 1  | نداء                       | 1/1        | narrative                          | —                                 | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |
| 2  | أثر في المكتب       | 1/1        | puzzle                             | spot_difference                    | GENERIC_WITH_PLACEHOLDER_CONTENT (صور Placeholder مولَّدة)     |
| 3  | رمز الدفتر            | 1/1        | puzzle                             | legacy (text)                      | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |
| — | *Qualification: First-N=100* | Gate 1     | —                                 | Generic First-N                    | PROVISIONAL (القيمة)                                               |
| 4  | إلى من تأهّل         | 1/2        | narrative                          | —                                 | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |
| 5  | كم عمر أصيل؟         | 1/2        | puzzle                             | legacy (text)                      | READY_GENERIC (العمر=16 مؤكَّد)                               |
| 6  | دليل من الغرفة     | 1/2        | puzzle                             | legacy (image)                     | CLIENT_CONTENT_REQUIRED (صورة حقيقية)                          |
| 7  | أي مدينة؟              | 1/2        | puzzle                             | legacy (text)                      | CLIENT_CONTENT_REQUIRED (المدينة/الإحداثيات)            |
| 8  | إرهاق                     | 1/2        | narrative                          | —                                 | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |
| 9  | فك اسم الحساب       | 2/3        | puzzle                             | legacy (text cipher)               | CLIENT_CONTENT_REQUIRED (الاسم الفعلي)                        |
| 10 | تعليق قديم            | 2/3        | puzzle                             | legacy (text)                      | CLIENT_CONTENT_REQUIRED                                                  |
| 11 | لجين                       | 2/3        | narrative                          | —                                 | CLIENT_CONTENT_REQUIRED (الشخصية)                                 |
| 12 | مقطع صوتي مشفَّر | 2/4        | puzzle                             | legacy (text) + audio media        | CLIENT_CONTENT_REQUIRED (ملف صوتي)                                |
| 13 | تذكّر ما سبق         | 2/4        | puzzle                             | legacy (text)                      | READY_GENERIC                                                            |
| 14 | صور تقود لحل         | 2/4        | puzzle                             | legacy (image)                     | CLIENT_CONTENT_REQUIRED (صورة حقيقية)                          |
| 15 | استمع جيداً          | 2/4        | puzzle                             | legacy (text) + audio media        | CLIENT_CONTENT_REQUIRED (ملف صوتي)                                |
| 16 | تحدي الفرق            | 2/5        | narrative (Placeholder)            | —                                 | CLIENT_TECH_DECISION_REQUIRED                                            |
| 17 | تحدي الشطرنج        | 2/5        | narrative (Placeholder)            | —                                 | CLIENT_TECH_DECISION_REQUIRED                                            |
| 18 | اختبار الذاكرة    | 2/5        | puzzle                             | memory (موجودة من Phase A) | PROVISIONAL (تأكيد Schema الرموز)                             |
| 19 | حساب أو قصة؟         | 2/5        | puzzle                             | legacy (text)                      | CLIENT_TECH_DECISION_REQUIRED (تكامل اجتماعي مستقبلي) |
| 20 | لماذا برأيك؟        | 3/6        | reflection (جديدة، عامة) | —                                 | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |
| 21 | رسالة دعم              | 3/6        | reflection (جديدة، عامة) | —                                 | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |
| 22 | قرار (Finale)              | 3/6        | narrative                          | —                                 | GENERIC_WITH_PLACEHOLDER_CONTENT                                         |

## ما هو جديد بمحرك الحملات (عام، ليس خاصاً بأصيل)

- `CampaignStep::KIND_REFLECTION` + `CampaignReflectionService` + Migration `response_payload`.
- `CampaignStep.content.media_type/media_path/caption` - عرض Media عام (صورة/صوت).
- `CampaignStep.content.status` - Metadata حالة محتوى إدارية (لا تؤثر بمنطق اللعب).
- `SeasonReadinessService` - فحص جاهزية عام لأي موسم مستقبلي.
- `<x-media-or-placeholder>` - ضمانة "لا صورة معطَّلة" عامة، تُستخدم بأي مكان بالمنصة.

## غير مُنفَّذ عمداً (بانتظار قرار العميل)

- نظام فرق حقيقي (Step 16).
- محرك/تكامل شطرنج حقيقي (Step 17).
- تكامل اجتماعي حقيقي (Step 19).

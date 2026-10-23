<?php

/*
| E38 - مركز الثقة والسلامة (صندوق وارد موحَّد، حالات إشراف، إجراءات منظَّمة، اعتراضات).
|
| قواعد مغلقة (لا تُضبط بأي إعداد هنا):
|  - جداول البلاغات المجالية (E21/E28/E35/E36) تبقى مصدر الحقيقة؛ هذه الطبقة تنسّق فوقها ولا تنسخها ولا تستبدلها.
|  - لا تغيير مباشر لحالة مجال: كل إجراء يمرّ بخدمة المجال الرسمية (UserAccountService، CreatorApplicationService، خدمات إخفاء/استعادة/قفل المجالات).
|  - البلاغ ليس دليلًا، وتعدّد البلاغات ليس إدانة، وعلامة الاحتيال إشارة لا عقوبة، والسمعة (E37) لا تحدّد عقوبة، ولا يحمي الدعم/الرعاية/الشهرة من الإشراف.
|  - لا حظر دائم آلي، لا قرار بالذكاء الاصطناعي، لا درجة ثقة سرّية أو علنية، لا عقاب على مجرد اشتراك IP/جهاز.
|  - هوية المبلِّغ وملاحظات المشرفين وإشارات الاحتيال لا تصل للمستخدم المعني ولا للعامة أبدًا. التاريخ (حالات/أحداث/أدلة/إجراءات) لا يُحذف ولا يُعدَّل.
| القيم هنا سياسات تشغيلية (لا متغيرات بيئة لها). التفاصيل: docs/trust-safety-e38.md و docs/moderation-operations-runbook.md
*/
return [

    // يُرفع عند تغيير تصنيف السياسات أو معانيه: يُسجَّل مع كل إجراء لوضوح التدقيق لاحقًا (لا نظام إدارة سياسات).
    'policy_version' => 1,

    'case' => [
        // رقم الحالة المقروء: SAFE-2026-000123 (البادئة-السنة-المعرّف الداخلي مبطَّنًا). المعرّف العلني الفعلي ULID؛ الرقم للتواصل الداخلي فقط.
        'number_prefix' => 'SAFE',
        'number_pad' => 6,
        'summary_max' => 500,
        'note_max' => 1000,
        'label_max' => 160,
        'excerpt_max' => 300,
        // لقطة نص المحتوى المُبلَّغ عنه كدليل (للمشرفين فقط، نص عادي، إلحاقية)
        'snapshot_max' => 1000,
    ],

    /*
    | أهداف التشغيل الداخلية (ساعات) بحسب الأولوية: أول استجابة / الحسم. **ليست وعدًا للمستخدم** ولا SLA قانونيًا، ولا يُعرَض أي منها له.
    | due_soon_hours: «يستحق قريبًا» عند بقاء هذا العدد من الساعات أو أقل.
    */
    'sla' => [
        'first_response_hours' => ['urgent' => 4, 'high' => 12, 'normal' => 48, 'low' => 120],
        'resolution_hours' => ['urgent' => 24, 'high' => 72, 'normal' => 168, 'low' => 336],
        'due_soon_hours' => 4,
    ],

    /*
    | تصنيف السياسات (قائمة مغلقة) بتسميات عربية محايدة. 'priority' اقتراح حتمي فقط (لا يطبَّق تلقائيًا ولا يحدّد عقوبة): أمان/احتيال/كراهية ← مرتفعة.
    | الشهرة والسمعة والدعم لا تدخل الاقتراح إطلاقًا.
    */
    'categories' => [
        'spam' => ['label' => 'محتوى مزعج', 'priority' => 'normal'],
        'harassment' => ['label' => 'إساءة أو مضايقة', 'priority' => 'normal'],
        'hate_or_abuse' => ['label' => 'كراهية أو إساءة جسيمة', 'priority' => 'high'],
        'inappropriate_content' => ['label' => 'محتوى غير لائق', 'priority' => 'normal'],
        'misleading' => ['label' => 'محتوى مضلِّل', 'priority' => 'normal'],
        'copyright' => ['label' => 'حقوق النشر', 'priority' => 'normal'],
        'fraud_or_abuse' => ['label' => 'احتيال أو إساءة استخدام', 'priority' => 'high'],
        'security_abuse' => ['label' => 'إساءة تمسّ الأمان', 'priority' => 'high'],
        'other' => ['label' => 'سبب آخر', 'priority' => 'normal'],
    ],

    /*
    | خريطة أسباب البلاغات المجالية إلى تصنيف السياسات. **لا إعادة كتابة لقيم الأسباب التاريخية** في جداول البلاغات؛ الخريطة قراءة فقط.
    | سبب غير معروف يُحلّ إلى other.
    */
    'reason_map' => [
        'community_puzzle_report' => [
            'inappropriate' => 'inappropriate_content', 'copyright' => 'copyright', 'misleading' => 'misleading',
            'broken' => 'other', 'spam' => 'spam', 'other' => 'other',
        ],
        'user_collection_report' => [
            'inappropriate' => 'inappropriate_content', 'spam' => 'spam', 'misleading' => 'misleading',
            'copyright' => 'copyright', 'harassment' => 'harassment', 'other' => 'other',
        ],
        'discussion_comment_report' => [
            'spam' => 'spam', 'harassment' => 'harassment', 'hate_or_abuse' => 'hate_or_abuse',
            'inappropriate' => 'inappropriate_content', 'misleading' => 'misleading', 'copyright' => 'copyright', 'other' => 'other',
        ],
        'chat_message_report' => [
            'spam' => 'spam', 'harassment' => 'harassment', 'inappropriate' => 'inappropriate_content',
            'scam' => 'fraud_or_abuse', 'other' => 'other',
        ],
        // كل علامات الاحتيال إشارات نظام: تصنيفها fraud_or_abuse (لا «تأكيد مخالفة»).
        'fraud_flag' => [],
    ],

    // مفتاح سبب الإخفاء المهيكل بالنقاشات (DiscussionCommentReport::REASONS) من تصنيف السياسة. ما لا خريطة له ← other.
    'discussion_reason_map' => [
        'spam' => 'spam', 'harassment' => 'harassment', 'hate_or_abuse' => 'hate_or_abuse', 'inappropriate_content' => 'inappropriate',
        'misleading' => 'misleading', 'copyright' => 'copyright', 'fraud_or_abuse' => 'other', 'security_abuse' => 'other', 'other' => 'other',
    ],

    /*
    | الإجراءات المسموحة (قائمة بيضاء مغلقة). kind: content (يمسّ محتوى محددًا) | account (يمسّ حسابًا) | notice (إشعار رسمي) | none.
    | severe: يتطلب تأكيدًا صريحًا وسببًا وسياق حالة نشطة (تجميد/تعليق مبدع). permission: صلاحية الثقة والسلامة (إضافة إلى صلاحية المجال نفسه عند الخدمة الرسمية).
    | appealable: قابل للاعتراض. reverses: الإجراء الذي يعكسه هذا الإجراء.
    */
    'actions' => [
        'no_action' => ['kind' => 'none', 'severe' => false, 'permission' => 'trust-safety.manage-cases', 'appealable' => false],
        'warn_user' => ['kind' => 'notice', 'severe' => false, 'permission' => 'trust-safety.moderate-content', 'appealable' => true],
        'hide_content' => ['kind' => 'content', 'severe' => false, 'permission' => 'trust-safety.moderate-content', 'appealable' => true],
        'restore_content' => ['kind' => 'content', 'severe' => false, 'permission' => 'trust-safety.moderate-content', 'appealable' => false, 'reverses' => 'hide_content'],
        'lock_discussion' => ['kind' => 'content', 'severe' => false, 'permission' => 'trust-safety.moderate-content', 'appealable' => false],
        'unlock_discussion' => ['kind' => 'content', 'severe' => false, 'permission' => 'trust-safety.moderate-content', 'appealable' => false, 'reverses' => 'lock_discussion'],
        'suspend_creator' => ['kind' => 'account', 'severe' => true, 'permission' => 'trust-safety.enforce-account', 'appealable' => true],
        'restore_creator' => ['kind' => 'account', 'severe' => false, 'permission' => 'trust-safety.enforce-account', 'appealable' => false, 'reverses' => 'suspend_creator'],
        'freeze_user' => ['kind' => 'account', 'severe' => true, 'permission' => 'trust-safety.enforce-account', 'appealable' => true],
        'unfreeze_user' => ['kind' => 'account', 'severe' => false, 'permission' => 'trust-safety.enforce-account', 'appealable' => false, 'reverses' => 'freeze_user'],
    ],

    // سبب الإجراء الداخلي (يراه المشرفون فقط). حدّ أدنى أعلى للإجراءات الشديدة.
    'reason' => ['min' => 8, 'severe_min' => 20, 'max' => 400],

    /*
    | الاعتراضات. window_days: مهلة الاعتراض من لحظة الإجراء لكل نوع (الأنواع غير المذكورة غير قابلة للاعتراض).
    | allow_reappeal=false: اعتراض واحد لكل إجراء (بعد الرفض لا اعتراض ثانٍ على الإجراء نفسه) فلا يتحوّل النظام إلى قناة مضايقة للمشرفين.
    */
    'appeals' => [
        'window_days' => ['warn_user' => 14, 'hide_content' => 14, 'suspend_creator' => 14, 'freeze_user' => 30],
        'reason_min' => 20,
        'reason_max' => 1500,
        'decision_min' => 8,
        'decision_max' => 800,
        'allow_reappeal' => false,
    ],

    // الوصول المقيَّد للحساب المجمَّد (اعتراض فقط): مدة سياق الجلسة بالدقائق. لا يمنح مصادقة عامة ولا يلمس حارس web.
    'restricted_access' => ['ttl_minutes' => 30],

    // أولوية إشارات الاحتيال تُقترح من severity المسجَّلة بها فقط (لا من عدد البلاغات ولا شهرة المحتوى ولا السمعة).
    'fraud_severity_priority' => ['high' => 'high', 'medium' => 'normal', 'low' => 'low'],

    // عدد البلاغات وعدد المبلِّغين الفريدين يُعرضان للفرز البشري فقط: **لا يرفعان الأولوية ولا يحسمان شيئًا** (تعدّد البلاغات ليس إدانة، وقد يكون تنسيقًا ضد بريء).
    'inbox' => ['per_page' => 25, 'max_per_page' => 100],

    'lists' => ['cases_per_page' => 25, 'appeals_per_page' => 25, 'user_page_limit' => 30, 'timeline_limit' => 100, 'evidence_limit' => 50],

    // تحليلات إدارية مجمَّعة (كاش قصير فقط؛ حالة الحالات والاعتراضات والإجراءات النشطة لا تُخزَّن مؤقتًا أبدًا).
    'analytics' => ['cache_seconds' => 300, 'window_days' => 30, 'repeat_subject_min_reports' => 2, 'repeat_subjects_limit' => 10],

    'reconcile' => ['limit' => 500],
];

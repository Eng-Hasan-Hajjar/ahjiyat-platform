<?php

/**
 * سجل الصلاحيات المركزي (Permission Registry) - مصدر الحقيقة الوحيد لأي
 * صلاحية بالنظام. Admin لا يكتب أسماء صلاحيات حرة أبداً - فقط يختار من
 * هذه القائمة عبر واجهة الأدوار. لإضافة صلاحية جديدة مستقبلاً: أضف سطراً
 * هنا، شغّل `php artisan permissions:sync`، ستظهر تلقائياً بمصفوفة
 * الصلاحيات - بلا Migration وبلا تعديل RoleResource. راجع
 * docs/roles-permissions.md.
 *
 * تنبيه: هذا الملف "permissions.php" (بصيغة الجمع) - مختلف تماماً عن
 * "permission.php" (بالمفرد) الذي نشرته حزمة Spatie بأمر vendor:publish.
 * لا تخلط بينهما - كلاهما يبقى موجوداً بمجلد config/ بشكل منفصل.
 *
 * Convention: module.action (نقطة واحدة، أحرف صغيرة فقط).
 */
return [

    'dashboard' => [
        'label' => 'لوحة التحكم',
        'permissions' => [
            'admin.access' => 'الدخول إلى لوحة الإدارة',
        ],
    ],

    'users' => [
        'label' => 'المستخدمون',
        'permissions' => [
            'users.view' => 'عرض المستخدمين',
            'users.create' => 'إنشاء مستخدم',
            'users.update' => 'تعديل مستخدم',
            'users.delete' => 'حذف مستخدم',
            'users.freeze' => 'تجميد حساب مستخدم',
            'users.unfreeze' => 'رفع تجميد حساب مستخدم',
            'users.view_security' => 'عرض بيانات الأمان (أجهزة/جلسات/IP) لمستخدم',
            'users.view_wallet' => 'عرض محفظة مستخدم ضمن ملفه',
            'users.view_activity' => 'عرض نشاط اللعب والحملات لمستخدم',
            'users.manage_roles' => 'تعيين/إزالة أدوار مستخدم من ملفه',
        ],
    ],

    'roles' => [
        'label' => 'الأدوار والصلاحيات',
        'permissions' => [
            'roles.view' => 'عرض الأدوار',
            'roles.create' => 'إنشاء دور',
            'roles.update' => 'تعديل دور',
            'roles.delete' => 'حذف دور',
            'roles.assign' => 'تعيين الأدوار للمستخدمين',
        ],
    ],

    'settings' => [
        'label' => 'إعدادات المنصة',
        'permissions' => [
            'settings.view' => 'عرض الإعدادات',
            'settings.update' => 'تعديل الإعدادات',
        ],
    ],

    'seasons' => [
        'label' => 'المواسم الرسمية',
        'permissions' => [
            'seasons.view' => 'عرض المواسم',
            'seasons.create' => 'إنشاء موسم',
            'seasons.update' => 'تعديل موسم',
            'seasons.delete' => 'حذف موسم',
            'seasons.publish' => 'نشر موسم',
            'seasons.preview' => 'معاينة موسم غير منشور',
        ],
    ],

    'campaigns' => [
        'label' => 'الحملات (المراحل/البوابات/الخطوات)',
        'permissions' => [
            'campaigns.view' => 'عرض الحملات',
            'campaigns.create' => 'إنشاء حملة',
            'campaigns.update' => 'تعديل حملة ومحتواها (مراحل/بوابات/خطوات)',
            'campaigns.delete' => 'حذف حملة',
        ],
    ],

    'puzzles' => [
        'label' => 'الأحجيات',
        'permissions' => [
            'puzzles.view' => 'عرض الأحجيات',
            'puzzles.create' => 'إنشاء أحجية',
            'puzzles.update' => 'تعديل أحجية',
            'puzzles.delete' => 'حذف أحجية',
            'puzzles.publish' => 'تفعيل/تعطيل أحجية',
            'puzzles.manage_categories' => 'إدارة تصنيفات الأحجيات',
        ],
    ],

    'challenges' => [
        'label' => 'التحديات والبطولات',
        'permissions' => [
            'challenges.view' => 'عرض التحديات',
            'challenges.create' => 'إنشاء تحدٍّ',
            'challenges.update' => 'تعديل تحدٍّ',
            'challenges.delete' => 'حذف تحدٍّ',
        ],
    ],

    'wallet' => [
        'label' => 'الجواهر والمحفظة',
        'permissions' => [
            'wallet.view' => 'عرض سجل معاملات الجواهر',
            'wallet.adjust' => 'تعديل رصيد جواهر مستخدم يدوياً (إضافة/خصم)',
        ],
    ],

    'redemptions' => [
        'label' => 'طلبات الاستبدال',
        'permissions' => [
            'redemptions.view' => 'عرض طلبات الاستبدال',
            'redemptions.approve' => 'قبول طلب استبدال',
            'redemptions.reject' => 'رفض طلب استبدال',
        ],
    ],

    'fraud' => [
        'label' => 'الأمان ومكافحة الاحتيال',
        'permissions' => [
            'fraud.view' => 'عرض علامات الاحتيال',
            'fraud.resolve' => 'معالجة/إغلاق علامة احتيال',
        ],
    ],

    'security' => [
        'label' => 'إدارة الجلسات',
        'permissions' => [
            'security.sessions_view' => 'عرض جلسات دخول المستخدمين',
            'security.sessions_revoke' => 'إنهاء جلسة دخول مستخدم',
        ],
    ],

    'operations' => [
        'label' => 'مركز العمليات',
        'permissions' => [
            'operations.dashboard_view' => 'عرض مركز العمليات',
        ],
    ],

    'analytics' => [
        'label' => 'التحليلات والتقارير',
        'permissions' => [
            'analytics.view' => 'الدخول إلى مركز التحليلات (نظرة عامة)',
            'analytics.users' => 'عرض تحليلات المستخدمين',
            'analytics.puzzles' => 'عرض تحليلات الأحجيات',
            'analytics.campaigns' => 'عرض تحليلات الحملات والمواسم',
            'analytics.financial' => 'عرض تحليلات الجواهر والاستبدال',
            'analytics.security' => 'عرض تحليلات الأمان والاحتيال',
            'reports.export' => 'تصدير التقارير (CSV)',
        ],
    ],

    'economy' => [
        'label' => 'الاقتصاد والعملات',
        'permissions' => [
            'economy.currencies.view' => 'عرض العملات',
            'economy.currencies.create' => 'إنشاء عملة جديدة',
            'economy.currencies.update' => 'تعديل عملة (السياسات/الاسم/الأيقونة)',
            'economy.currencies.deactivate' => 'تفعيل/تعطيل عملة',
            'economy.packs.view' => 'عرض حزم العملات بالمتجر',
            'economy.packs.manage' => 'إنشاء/تعديل/تفعيل حزم العملات',
            'economy.transactions.view' => 'عرض سجل معاملات العملات (Ledger)',
        ],
    ],

    'system' => [
        'label' => 'النظام والتدقيق',
        'permissions' => [
            'system.audit_view' => 'عرض سجل تغييرات الصلاحيات',
        ],
    ],

];
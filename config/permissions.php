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

    'system' => [
        'label' => 'النظام والتدقيق',
        'permissions' => [
            'system.audit_view' => 'عرض سجل تغييرات الصلاحيات',
        ],
    ],

];
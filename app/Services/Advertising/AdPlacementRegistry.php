<?php

namespace App\Services\Advertising;

/**
 * السجلّ الموثوق الوحيد للمواضع التي يعرفها التطبيق فعليًا - قاعدة البيانات
 * تُفعِّل/تُعطِّل فقط، لا تخترع موضعًا جديدًا عبر نص حر إطلاقًا. أي مفتاح غير
 * مذكور هنا = غير معروف = لا يُعرَض مهما كانت حالته بقاعدة البيانات.
 *
 * لا توجد صفحة "نتائج" منفصلة معماريًا - نتيجة حل الأحجية تظهر ضمن نفس صفحة
 * اللعب التفاعلية المحمية بالكامل، فأُسقِطَت "results_bottom" عمدًا.
 */
class AdPlacementRegistry
{
    public const HOME_INLINE_PRIMARY = 'home_inline_primary';
    public const LEADERBOARD_SIDEBAR = 'leaderboard_sidebar';

    public const KNOWN_PLACEMENTS = [
        self::HOME_INLINE_PRIMARY => [
            'name' => 'الصفحة الرئيسية - أسفل المحتوى الرئيسي',
            'surface' => 'home',
            'position' => 'بعد قسم الأحجيات المميَّزة، قبل الفوتر - لا فوق أي دعوة فعل أساسية',
        ],
        self::LEADERBOARD_SIDEBAR => [
            // الاسم البرمجي (leaderboard_sidebar) بقي للاستقرار الداخلي، لكن الصفحة
            // الفعلية أحادية العمود بلا شريط جانبي - الموضع الحقيقي أسفل القائمة.
            'name' => 'لوحة الصدارة - أسفل القائمة',
            'surface' => 'leaderboard',
            'position' => 'أسفل قائمة المتصدِّرين مباشرة، قبل الفوتر - لا شريط جانبي فعليًا بالتصميم الحالي',
        ],
    ];

    public const STRICTLY_BLOCKED_SURFACES = [
        'منطقة تفاعل الأحجية (الإدخال/الإرسال/التلميح/المؤقِّت)',
        'واجهة تفاعل الحملة (خطوات Campaign)',
        'صفحة المهام (Quests)',
        'صفحة التقدُّم (Progress)',
        'المتجر (Store)',
        'المحفظة (Wallet)',
        'المخزون (Inventory)',
        'تسجيل الدخول/التسجيل',
        'الإعدادات/الخصوصية',
        'لوحة الإدارة',
    ];

    public static function isKnown(string $internalKey): bool
    {
        return array_key_exists($internalKey, self::KNOWN_PLACEMENTS);
    }

    public static function all(): array
    {
        return self::KNOWN_PLACEMENTS;
    }

    public static function definitionFor(string $internalKey): ?array
    {
        return self::KNOWN_PLACEMENTS[$internalKey] ?? null;
    }
}
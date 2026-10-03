<?php

namespace App\Services\Advertising;

/**
 * E14 (بند 12/317/572-574): السجلّ الموثوق الوحيد للمواضع التي يعرفها
 * التطبيق فعليًا - قاعدة البيانات تُفعِّل/تُعطِّل فقط، لا تخترع موضعًا جديدًا
 * عبر نص حر إطلاقًا. أي مفتاح غير مذكور هنا = غير معروف = لا يُعرَض مهما
 * كانت حالته بقاعدة البيانات (AdPlacement::exists لا تكفي وحدها).
 *
 * تدقيق فعلي للواجهات قبل اختيار هاتين تحديدًا (بند 573): لا توجد صفحة
 * "نتائج" منفصلة معماريًا - نتيجة حل الأحجية تظهر ضمن نفس صفحة اللعب
 * التفاعلية المحمية بالكامل (PuzzleController::show يعرض puzzles.show
 * نفسها قبل وبعد الحل) - فأُسقِطَت "results_bottom" من الأساس عمدًا، لا
 * سهوًا، إلى حين وجود سطح عرض نتيجة منفصل فعليًا بمرحلة لاحقة.
 */
class AdPlacementRegistry
{
    public const HOME_INLINE_PRIMARY = 'home_inline_primary';
    public const LEADERBOARD_SIDEBAR = 'leaderboard_sidebar';

    /**
     * surface: اسم الصفحة المنطقي (للتحليلات والتوثيق فقط، لا يُستخدَم
     * للتخويل). position: وصف نصي لمكان العرض بالصفحة (توثيقي).
     */
    public const KNOWN_PLACEMENTS = [
        self::HOME_INLINE_PRIMARY => [
            'name' => 'الصفحة الرئيسية - أسفل المحتوى الرئيسي',
            'surface' => 'home',
            'position' => 'بعد قسم الأحجيات المميَّزة، قبل الفوتر - لا فوق أي دعوة فعل أساسية',
        ],
        self::LEADERBOARD_SIDEBAR => [
            // ملاحظة تدقيق صادقة: الاسم البرمجي (leaderboard_sidebar) بقي كما
            // هو للاستقرار الداخلي، لكن الصفحة الفعلية أحادية العمود بلا أي
            // شريط جانبي - الموضع الحقيقي بعد قائمة المتصدِّرين مباشرة، على
            // الجهازين معًا (desktop_enabled/mobile_enabled كلاهما true).
            'name' => 'لوحة الصدارة - أسفل القائمة',
            'surface' => 'leaderboard',
            'position' => 'أسفل قائمة المتصدِّرين مباشرة، قبل الفوتر - لا شريط جانبي فعليًا بالتصميم الحالي',
        ],
    ];

    /**
     * بند 574: مواضع محظورة صراحةً - موثَّقة هنا لتبقى القائمة قابلة
     * للمراجعة، لا لأنها تُستهلَك برمجيًا (غيابها من KNOWN_PLACEMENTS كافٍ
     * وحده للمنع الفعلي - هذا توثيق نيّة فقط).
     */
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

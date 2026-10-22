<?php

namespace App\Services\Reputation;

/**
 * E37 - قيم الاعتراف الحالية لمستخدم أثناء تقييم إنجازاته (تضعها CommunityReputationService قبل AchievementService::evaluateForEvent وتزيلها بعده).
 * هدفها: تسع إنجازات اعتراف لمستخدم واحد تقرأ **نفس** القيم المحسوبة للتوّ بلا 9 استعلامات إضافية، وبلا حالة قابلة للتسرّب (تُزال في finally).
 * المخزن ساكن (static) عمدًا ليشاركه المقيِّم المنشأ بـapp() مع الخدمة دون ربط حاوية؛ خارج نافذة التقييم فارغ دائمًا.
 */
final class ReputationRecognitionContext
{
    /** @var array<int, array<string, int>> */
    private static array $values = [];

    /** @param array<string, int> $values */
    public function put(int $userId, array $values): void
    {
        self::$values[$userId] = $values;
    }

    public function forget(int $userId): void
    {
        unset(self::$values[$userId]);
    }

    /** @return array<string, int>|null */
    public function get(int $userId): ?array
    {
        return self::$values[$userId] ?? null;
    }

    public static function flush(): void
    {
        self::$values = [];
    }
}

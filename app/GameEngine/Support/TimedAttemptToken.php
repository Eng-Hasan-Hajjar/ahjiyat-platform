<?php

namespace App\GameEngine\Support;

use App\Models\Puzzle;
use Illuminate\Support\Facades\Crypt;

/**
 * رمز بداية موقّع (Signed Start Token) - يُصدر عند عرض صفحة الأحجية،
 * ويُعاد إرساله مع الحل. يسمح للسيرفر بحساب الوقت الفعلي المنقضي
 * بدل الوثوق بأي رقم "elapsed_seconds" يرسله المتصفح.
 */
class TimedAttemptToken
{
    public static function issue(Puzzle $puzzle, ?int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'puzzle_id' => $puzzle->id,
            'user_id' => $userId,
            'issued_at' => now()->timestamp,
        ]));
    }

    /**
     * الثواني الفعلية المنقضية منذ الإصدار، أو null لو الرمز غير صالح/منتهي/
     * لا يخص هذا المستخدم وهذه الأحجية بالضبط. null = "الوقت غير معروف"
     * والـValidator يعامله كفشل وليس كنجاح افتراضي (Fail-safe).
     */
    public static function elapsedSeconds(?string $token, Puzzle $puzzle, ?int $userId): ?int
    {
        if (blank($token)) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (($payload['puzzle_id'] ?? null) !== $puzzle->id || ($payload['user_id'] ?? null) !== $userId) {
            return null;
        }

        return max(0, now()->timestamp - (int) ($payload['issued_at'] ?? now()->timestamp));
    }
}
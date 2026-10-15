<?php

namespace App\Services\Security;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * E26: إبطال بيانات الاعتماد الحيّة بعد تغيير حسّاس (إعادة تعيين كلمة المرور، تغيير البريد). بدونه يبقى من سرق جلسة أو رمز API مصادَقًا بعد أن غيّر المالك كلمة مروره.
 * ذاتي الخدمة (بلا "منفّذ إداري" كما بـSessionManagementService الإدارية): يعمل على حساب المستخدم نفسه، ويحتفظ اختياريًا بجلسة الطلب الحالية.
 */
class AccountSecurityService
{
    /**
     * يحذف جلسات قاعدة البيانات الأخرى (إن كان سائق الجلسات database) ورموز Sanctum الشخصية، ويدوّر remember_token (يُبطل "تذكّرني" بكل الأجهزة).
     *
     * @return array{sessions: int, tokens: int}
     */
    public function revokeCredentials(User $user, ?string $keepSessionId = null): array
    {
        $sessions = 0;

        if (Schema::hasTable((new Session)->getTable())) {
            $query = Session::query()->where('user_id', $user->getKey());

            if ($keepSessionId !== null) {
                $query->where('id', '!=', $keepSessionId);
            }

            $sessions = $query->delete();
        }

        // جدول رموز Sanctum غير منشأ بهذا المستودع بعد (واجهة الموبايل مؤجَّلة): لا نفترض وجوده.
        $tokens = method_exists($user, 'tokens') && self::tokensTableExists() ? $user->tokens()->delete() : 0;

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return ['sessions' => (int) $sessions, 'tokens' => (int) $tokens];
    }

    /** هل جدول رموز API الشخصية موجود؟ (E26: واجهة الموبايل خاملة حتى تُنشأ الهجرة؛ انظر docs/security-e26.md). */
    public static function tokensTableExists(): bool
    {
        return Schema::hasTable((new \Laravel\Sanctum\PersonalAccessToken)->getTable());
    }
}

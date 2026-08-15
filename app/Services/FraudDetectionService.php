<?php

namespace App\Services;

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\GemTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * قواعد بسيطة وشفافة لمرحلة الـ MVP - أساس قابل للتوسع لاحقاً
 * بمزود مكافحة احتيال متخصص إذا كبر حجم المنصة.
 */
class FraudDetectionService
{
    public function recordSighting(?User $user, string $ip, string $userAgent): void
    {
        $deviceHash = hash('sha256', $userAgent);

        DeviceSighting::updateOrCreate(
            ['user_id' => $user?->id, 'ip_address' => $ip, 'device_hash' => $deviceHash],
            ['last_seen_at' => now()]
        );

        if ($user) {
            $this->checkSharedIpAcrossAccounts($user, $ip);
        }
    }

    protected function checkSharedIpAcrossAccounts(User $user, string $ip): void
    {
        $distinctUsers = DeviceSighting::where('ip_address', $ip)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        if ($distinctUsers >= 5) {
            $this->flag($user, 'ip_shared', 'medium', "نفس الـ IP استخدم من {$distinctUsers} حسابات مختلفة.");
        }
    }

    public function evaluateAfterEarn(User $user): void
    {
        $earnedLastHour = GemTransaction::where('user_id', $user->id)
            ->where('type', GemTransaction::TYPE_EARN_PENDING)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        // حل عدد كبير جداً من الألغاز الصحيحة خلال ساعة واحدة - مؤشر أتمتة محتمل
        if ($earnedLastHour >= 40) {
            $this->flag($user, 'abnormal_earn_rate', 'high', "{$earnedLastHour} حل صحيح خلال ساعة واحدة.");
        }
    }

    public function flag(User $user, string $reason, string $severity, ?string $details = null): FraudFlag
    {
        return FraudFlag::create([
            'user_id' => $user->id,
            'reason' => $reason,
            'severity' => $severity,
            'details' => $details,
        ]);
    }
}

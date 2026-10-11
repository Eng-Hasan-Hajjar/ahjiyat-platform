<?php

namespace App\Services;

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\GemTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request as RequestFacade;

class FraudDetectionService
{
    public function recordSighting(?User $user, string $ip, string $userAgent): void
    {
        $deviceHash = hash('sha256', $userAgent);

        // E24: كان هذا يكتب صفًا ويعدّ بصمات الـIP في كل طلب (حتى الزوار). الآن مرة واحدة كل فترة قصيرة لكل (مستخدم، IP، جهاز).
        if (! $this->dueForSighting($user?->id, $ip, $deviceHash)) {
            return;
        }

        DeviceSighting::updateOrCreate(
            ['user_id' => $user?->id, 'ip_address' => $ip, 'device_hash' => $deviceHash],
            ['last_seen_at' => now()]
        );

        if ($user) {
            $this->checkSharedIpAcrossAccounts($user, $ip);
        }
    }

    /**
     * بوّابة تخفيف (Cache::add ذرّية): true = سجِّل الآن. أي فشل في الكاش => true (fail-open) فلا نفقد التتبّع الأمني.
     * المفتاح مجزّأ (لا IP ولا User-Agent خام في مفتاح الكاش).
     */
    protected function dueForSighting(?int $userId, string $ip, string $deviceHash): bool
    {
        $ttl = (int) config('performance.device_sighting.throttle_seconds', 60);

        if ($ttl <= 0) {
            return true;
        }

        try {
            return Cache::add('sighting:'.hash('sha256', ($userId ?? 'guest').'|'.$ip.'|'.$deviceHash), 1, $ttl);
        } catch (\Throwable) {
            return true;
        }
    }

    protected function checkSharedIpAcrossAccounts(User $user, string $ip): void
    {
        $distinctUsers = DeviceSighting::where('ip_address', $ip)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        // E24: كانت كل بصمة (كل طلب سابقًا) تُنشئ علامة جديدة؛ على IP شبكة مشتركة (≥5 حسابات) يعني آلاف العلامات المكرّرة لحساب واحد.
        // لا نُكرّر علامة ip_shared **غير المعالَجة** لنفس الحساب؛ بعد أن يعالجها المشرف تُنشأ جديدة إن استمر الوضع.
        if ($distinctUsers >= 5 && ! FraudFlag::query()->where('user_id', $user->id)->where('reason', 'ip_shared')->where('resolved', false)->exists()) {
            $this->flag($user, 'ip_shared', 'medium', "نفس الـ IP استخدم من {$distinctUsers} حسابات مختلفة.");
        }
    }

    public function evaluateAfterEarn(User $user): void
    {
        $earnedLastHour = GemTransaction::where('user_id', $user->id)
            ->where('type', GemTransaction::TYPE_EARN_PENDING)
            ->where('created_at', '>=', now()->subHour())
            ->count();

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

    /**
     * معالجة/إغلاق علامة احتيال - E6. لا تُغيِّر شيئاً على حساب المستخدم
     * تلقائياً - فقط تُغلق العلامة نفسها.
     */
    public function resolve(FraudFlag $flag, User $admin, string $note): void
    {
        if ($flag->resolved) {
            abort(403, 'هذه العلامة مُعالَجة مسبقاً.');
        }

        $flag->update([
            'resolved' => true,
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);
    }
}
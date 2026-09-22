<?php

namespace App\Services\Analytics;

use App\Models\FraudFlag;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SecurityAnalyticsService
{
    public function overview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('security.overview'), now()->addMinutes(5), function () use ($period) {
            return [
                'open_fraud_flags' => FraudFlag::where('resolved', false)->count(),
                'resolved_fraud_flags' => FraudFlag::where('resolved', true)->count(),
                'flags_created_in_period' => FraudFlag::whereBetween('created_at', [$period->start, $period->end])->count(),
                'frozen_users' => User::where('is_frozen', true)->count(),
                'session_revocations_in_period' => OperationalAuditLog::whereIn('action', ['session_revoked', 'all_sessions_revoked'])
                    ->whereBetween('created_at', [$period->start, $period->end])->count(),
                'severity_breakdown' => $this->severityBreakdown($period),
                'admin_actions_in_period' => $this->adminActionsBreakdown($period),
            ];
        });
    }

    protected function severityBreakdown(AnalyticsPeriod $period): array
    {
        $rows = FraudFlag::whereBetween('created_at', [$period->start, $period->end])
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->get();

        $labels = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية'];

        return $rows->map(fn ($r) => ['severity' => $labels[$r->severity] ?? $r->severity, 'count' => (int) $r->count])->all();
    }

    protected function adminActionsBreakdown(AnalyticsPeriod $period): array
    {
        $labels = [
            'user_frozen' => 'تجميد حساب', 'user_unfrozen' => 'رفع تجميد',
            'wallet_manual_adjustment' => 'تعديل جواهر يدوي',
            'redemption_approved' => 'قبول استبدال', 'redemption_rejected' => 'رفض استبدال', 'redemption_fulfilled' => 'تنفيذ استبدال',
            'fraud_flag_resolved' => 'معالجة إشارة أمنية',
            'session_revoked' => 'إنهاء جلسة', 'all_sessions_revoked' => 'إنهاء كل الجلسات',
        ];

        $rows = OperationalAuditLog::whereBetween('created_at', [$period->start, $period->end])
            ->select('action', DB::raw('COUNT(*) as count'))
            ->groupBy('action')
            ->get();

        return $rows->map(fn ($r) => ['action' => $labels[$r->action] ?? $r->action, 'count' => (int) $r->count])->all();
    }
}
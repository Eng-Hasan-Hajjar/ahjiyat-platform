<?php

namespace App\Services\Analytics;

use App\Models\PuzzleAttempt;
use App\Models\Role;
use App\Models\User;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserAnalyticsService
{
    public function overview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('users.overview'), now()->addMinutes(10), function () use ($period) {
            return [
                'total_users' => User::count(),
                'new_users' => User::whereBetween('created_at', [$period->start, $period->end])->count(),
                'verified_users' => User::whereNotNull('email_verified_at')->count(),
                'unverified_users' => User::whereNull('email_verified_at')->count(),
                'frozen_users' => User::where('is_frozen', true)->count(),
                'active_users' => $this->activeUserCount($period),
                'admin_enabled_users' => User::permission('admin.access')->count(),
            ];
        });
    }

    public function activeUserCount(AnalyticsPeriod $period): int
    {
        return PuzzleAttempt::whereBetween('created_at', [$period->start, $period->end])
            ->distinct('user_id')
            ->count('user_id');
    }

    public function registrationGrowth(AnalyticsPeriod $period): array
    {
        $days = $period->start->diffInDays($period->end) + 1;
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        $bucketExpr = match (true) {
            $days <= 31 => $isSqlite ? "strftime('%Y-%m-%d', created_at)" : "DATE_FORMAT(created_at, '%Y-%m-%d')",
            $days <= 180 => $isSqlite ? "strftime('%Y-%W', created_at)" : "DATE_FORMAT(created_at, '%Y-%u')",
            default => $isSqlite ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')",
        };

        $rows = User::whereBetween('created_at', [$period->start, $period->end])
            ->selectRaw("{$bucketExpr} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'granularity' => match (true) {
                $days <= 31 => 'يومي', $days <= 180 => 'أسبوعي', default => 'شهري',
            },
            'labels' => $rows->pluck('bucket')->all(),
            'values' => $rows->pluck('total')->all(),
        ];
    }

    public function roleDistribution(): array
    {
        return Role::withCount('users')
            ->orderByDesc('users_count')
            ->get()
            ->map(fn (Role $role) => ['label' => $role->displayLabel(), 'count' => $role->users_count])
            ->all();
    }

    public function engagement(AnalyticsPeriod $period): array
    {
        return [
            'users_with_attempts' => PuzzleAttempt::whereBetween('created_at', [$period->start, $period->end])->distinct('user_id')->count('user_id'),
            'users_with_campaign_progress' => DB::table('user_campaign_progress')
                ->whereBetween('created_at', [$period->start, $period->end])
                ->distinct('user_id')->count('user_id'),
            'users_completing_steps' => DB::table('user_campaign_progress')
                ->whereBetween('completed_at', [$period->start, $period->end])
                ->distinct('user_id')->count('user_id'),
        ];
    }
}
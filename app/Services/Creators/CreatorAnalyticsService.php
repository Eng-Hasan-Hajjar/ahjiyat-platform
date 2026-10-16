<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use Illuminate\Support\Facades\Cache;

/**
 * E28 - تحليلات المبدع (لوحته) + ملخص إداري متواضع. لا أرباح ولا محفظة ولا دفع ولا مرتبة.
 * بلا COUNT لكل بطاقة: المبدع محدود بسقف أعمال، فاستعلامان مجمَّعان فقط (إجماليات + نشاط حديث لكل عمل) على فهرس (creator_puzzle_id, user_id)، ومخزَّنان قصيرًا.
 * لا هوية لاعبين: أرقام مجمَّعة فقط (خصوصية).
 */
class CreatorAnalyticsService
{
    /** @return array{published:int, players:int, attempts:int, completion_rate:int|null, recent_attempts:int, recent_days:int, per_work: array<int, array{recent:int}>} */
    public function forCreator(CreatorProfile $profile): array
    {
        return Cache::remember('creators.analytics.'.$profile->id, now()->addSeconds((int) config('creators.analytics.cache_ttl')), function () use ($profile) {
            $days = (int) config('creators.analytics.recent_days');
            $workIds = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->pluck('id')->all();
            $published = CreatorPuzzle::query()->where('creator_profile_id', $profile->id)->whereNotNull('published_puzzle_id')->count();

            if ($workIds === []) {
                return ['published' => 0, 'players' => 0, 'attempts' => 0, 'completion_rate' => null, 'recent_attempts' => 0, 'recent_days' => $days, 'per_work' => []];
            }

            $totals = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)
                ->selectRaw('COUNT(*) as attempts, COUNT(DISTINCT user_id) as players, COUNT(DISTINCT CASE WHEN is_correct THEN user_id END) as solvers')
                ->first();

            $recent = CommunityPuzzleAttempt::query()->whereIn('creator_puzzle_id', $workIds)->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('creator_puzzle_id, COUNT(*) as recent')->groupBy('creator_puzzle_id')->pluck('recent', 'creator_puzzle_id');

            $players = (int) ($totals->players ?? 0);

            return [
                'published' => $published,
                'players' => $players,
                'attempts' => (int) ($totals->attempts ?? 0),
                'completion_rate' => $players > 0 ? (int) round(((int) $totals->solvers / $players) * 100) : null,
                'recent_attempts' => (int) $recent->sum(),
                'recent_days' => $days,
                'per_work' => $recent->map(fn ($n) => ['recent' => (int) $n])->all(),
            ];
        });
    }

    /** ملخص إداري صغير مخزَّن دقيقة (مبدعون/طلبات/مراجعات/منشورات/لعب/بلاغات). */
    public function adminSummary(): array
    {
        return Cache::remember('creators.admin.summary', now()->addSeconds(60), fn () => [
            'approved_creators' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->count(),
            'pending_applications' => CreatorProfile::query()->where('status', CreatorProfile::STATUS_PENDING)->count(),
            'pending_submissions' => CreatorPuzzleRevision::query()->where('status', CreatorPuzzleRevision::STATUS_PENDING_REVIEW)->count(),
            'published_puzzles' => CreatorPuzzle::query()->where('is_visible', true)->count(),
            'community_plays' => CommunityPuzzleAttempt::query()->count(),
            'open_reports' => CommunityPuzzleReport::query()->where('status', CommunityPuzzleReport::STATUS_OPEN)->count(),
        ]);
    }
}

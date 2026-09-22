<?php

namespace App\Services\Analytics;

use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PuzzleAnalyticsService
{
    public const MIN_SAMPLE_SIZE = 5;

    public function overview(AnalyticsPeriod $period): array
    {
        return Cache::remember($period->cacheKey('puzzles.overview'), now()->addMinutes(10), function () use ($period) {
            $attempts = PuzzleAttempt::whereBetween('created_at', [$period->start, $period->end]);
            $total = (clone $attempts)->count();
            $correct = (clone $attempts)->where('is_correct', true)->count();

            return [
                'total_puzzles' => Puzzle::count(),
                'active_puzzles' => Puzzle::where('is_active', true)->count(),
                'total_attempts' => $total,
                'correct_attempts' => $correct,
                'incorrect_attempts' => $total - $correct,
                'success_rate' => $this->rate($correct, $total),
                'unique_players' => (clone $attempts)->distinct('user_id')->count('user_id'),
            ];
        });
    }

    protected function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    /**
     * إصلاح: HAVING على عمود withCount() المحسوب (subquery، لا GROUP BY
     * حقيقي) يفشل بـSQLite ("HAVING clause on a non-aggregate query").
     * عدد الأحجيات محدود دائماً (عشرات/مئات، لا ملايين مثل المحاولات) -
     * التصفية بالذاكرة هنا آمنة تماماً أداءً، بعكس التجميع على المحاولات
     * نفسها الذي يبقى بالكامل داخل قاعدة البيانات (بند 57/60).
     */
    public function topPuzzles(AnalyticsPeriod $period, int $limit = 10): array
    {
        $puzzles = Puzzle::query()
            ->withCount([
                'attempts as attempts_count' => fn ($q) => $q->whereBetween('created_at', [$period->start, $period->end]),
                'attempts as correct_count' => fn ($q) => $q->whereBetween('created_at', [$period->start, $period->end])->where('is_correct', true),
            ])
            ->get(['id', 'title']);

        $mostPlayed = $puzzles->sortByDesc('attempts_count')->take($limit)
            ->map(fn ($p) => ['title' => $p->title, 'attempts' => $p->attempts_count])->values();

        $eligible = $puzzles->filter(fn ($p) => $p->attempts_count >= self::MIN_SAMPLE_SIZE);

        $withRate = $eligible->map(fn ($p) => [
            'title' => $p->title,
            'attempts' => $p->attempts_count,
            'success_rate' => $this->rate($p->correct_count, $p->attempts_count),
        ]);

        return [
            'most_played' => $mostPlayed->all(),
            'highest_success_rate' => $withRate->sortByDesc('success_rate')->take($limit)->values()->all(),
            'lowest_success_rate' => $withRate->sortBy('success_rate')->take($limit)->values()->all(),
            'min_sample_size' => self::MIN_SAMPLE_SIZE,
        ];
    }

    public function difficultyBreakdown(AnalyticsPeriod $period): array
    {
        $rows = PuzzleAttempt::join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
            ->whereBetween('puzzle_attempts.created_at', [$period->start, $period->end])
            ->selectRaw('puzzles.difficulty, COUNT(*) as attempts, SUM(CASE WHEN puzzle_attempts.is_correct THEN 1 ELSE 0 END) as correct')
            ->groupBy('puzzles.difficulty')
            ->get();

        return $rows->map(fn ($r) => [
            'difficulty' => $r->difficulty ?? 'غير محدَّد',
            'attempts' => (int) $r->attempts,
            'success_rate' => $this->rate((int) $r->correct, (int) $r->attempts),
        ])->all();
    }

    public function gameTypeBreakdown(AnalyticsPeriod $period): array
    {
        $rows = PuzzleAttempt::join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
            ->whereBetween('puzzle_attempts.created_at', [$period->start, $period->end])
            ->selectRaw('COALESCE(puzzles.game_type, "classic") as game_type, COUNT(*) as attempts, COUNT(DISTINCT puzzle_attempts.user_id) as players, SUM(CASE WHEN puzzle_attempts.is_correct THEN 1 ELSE 0 END) as correct')
            ->groupBy('game_type')
            ->get();

        return $rows->map(fn ($r) => [
            'game_type' => $r->game_type,
            'attempts' => (int) $r->attempts,
            'players' => (int) $r->players,
            'success_rate' => $this->rate((int) $r->correct, (int) $r->attempts),
        ])->all();
    }

    public function challengeOverview(): array
    {
        return [
            'total_challenges' => Challenge::count(),
            'active_challenges' => Challenge::where('is_active', true)
                ->where('starts_at', '<=', now())->where('ends_at', '>=', now())->count(),
            'total_participants' => ChallengeParticipant::distinct('user_id')->count('user_id'),
        ];
    }
}
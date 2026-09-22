<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class AnalyticsPeriod
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $label,
    ) {}

    public static function fromPreset(string $preset): self
    {
        return match ($preset) {
            'today' => new self(now()->startOfDay(), now()->endOfDay(), 'اليوم'),
            'last_7_days' => new self(now()->subDays(6)->startOfDay(), now()->endOfDay(), 'آخر 7 أيام'),
            'last_30_days' => new self(now()->subDays(29)->startOfDay(), now()->endOfDay(), 'آخر 30 يوماً'),
            'last_90_days' => new self(now()->subDays(89)->startOfDay(), now()->endOfDay(), 'آخر 90 يوماً'),
            'this_month' => new self(now()->startOfMonth(), now()->endOfDay(), 'هذا الشهر'),
            'last_month' => new self(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), 'الشهر السابق'),
            'this_year' => new self(now()->startOfYear(), now()->endOfDay(), 'هذا العام'),
            default => new self(now()->subDays(29)->startOfDay(), now()->endOfDay(), 'آخر 30 يوماً'),
        };
    }

    public static function custom(string $start, string $end): self
    {
        return new self(
            Carbon::parse($start)->startOfDay(),
            Carbon::parse($end)->endOfDay(),
            'فترة مخصَّصة',
        );
    }

    public function previous(): self
    {
        $days = $this->start->diffInDays($this->end) + 1;

        return new self(
            $this->start->copy()->subDays($days),
            $this->start->copy()->subSecond(),
            'الفترة السابقة',
        );
    }

    public static function presetOptions(): array
    {
        return [
            'today' => 'اليوم',
            'last_7_days' => 'آخر 7 أيام',
            'last_30_days' => 'آخر 30 يوماً',
            'last_90_days' => 'آخر 90 يوماً',
            'this_month' => 'هذا الشهر',
            'last_month' => 'الشهر السابق',
            'this_year' => 'هذا العام',
        ];
    }

    public static function percentChange(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

       public function cacheKey(string $metric, array $extra = []): string
    {
        $parts = array_merge([
            $metric,
            $this->start->timestamp,
            $this->end->timestamp,
        ], array_values($extra));

        return \App\Support\AnalyticsCache::key(implode(':', $parts));
    }

}
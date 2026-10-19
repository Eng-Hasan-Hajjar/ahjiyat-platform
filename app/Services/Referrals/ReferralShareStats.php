<?php

namespace App\Services\Referrals;

use Illuminate\Support\Facades\Cache;

/**
 * E33 (C/E) - عدّادات مشاركة **تقريبية ومحدودة**: أنواع × أهداف من قوائم مغلقة × يوم، في الكاش فقط بصلاحية قصيرة (config share_counter_days).
 * لا هوية ولا IP ولا UA ولا رابط كامل. ليست مصدرًا لأي مكافأة، وتنخفض/تضيع بتفريغ الكاش (نصرّح بذلك في الواجهة: «تقريبي»).
 */
class ReferralShareStats
{
    public function record(string $kind, string $target): bool
    {
        if (! in_array($kind, (array) config('referrals.share_kinds'), true) || ! in_array($target, (array) config('referrals.share_targets'), true)) {
            return false;
        }

        $key = $this->key($kind, $target, now()->format('Ymd'));
        Cache::add($key, 0, now()->addDays((int) config('referrals.analytics.share_counter_days') + 1));
        Cache::increment($key);

        return true;
    }

    /** @return array{total: int, by_kind: array<string, int>, by_target: array<string, int>} */
    public function lastDays(int $days = 7): array
    {
        $byKind = array_fill_keys((array) config('referrals.share_kinds'), 0);
        $byTarget = array_fill_keys((array) config('referrals.share_targets'), 0);
        $total = 0;

        for ($d = 0; $d < max(1, min($days, (int) config('referrals.analytics.share_counter_days'))); $d++) {
            $day = now()->subDays($d)->format('Ymd');

            foreach ($byKind as $kind => $_) {
                foreach ($byTarget as $target => $__) {
                    $n = (int) Cache::get($this->key($kind, $target, $day), 0);
                    $byKind[$kind] += $n;
                    $byTarget[$target] += $n;
                    $total += $n;
                }
            }
        }

        return ['total' => $total, 'by_kind' => array_filter($byKind), 'by_target' => array_filter($byTarget)];
    }

    protected function key(string $kind, string $target, string $day): string
    {
        return "referrals:share:{$day}:{$kind}:{$target}";
    }
}

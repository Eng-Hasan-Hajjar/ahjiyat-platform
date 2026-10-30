<?php

namespace App\Services\SeasonalRecognition;

use App\Events\SeasonalRecognition\SeasonSupportersLeaderboardUpdated;
use App\Events\SeasonalRecognition\SeasonWriterLeaderboardUpdated;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * E44 - بثّ إشارات تحديث اللوحات بعد الـcommit. يعزل كل فشل (Reverb مُعطَّل أو غير متاح لا يُفشل تصويتًا ولا منح نقاط) ويحدّ المعدّل لكل (موسم، نوع)
 * بفاصل min_interval_seconds كي لا تُغرق عاصفة أصوات القناة؛ العميل يعتمد debounce واستطلاعًا احتياطيًا فلا تضيع حالة نهائية (آخر قيمة تُجلب من الخادم).
 */
class SeasonalRecognitionBroadcaster
{
    public function writersUpdated(int $seasonId, string $entryPublicId, int $starsTotal, int $votersCount): void
    {
        if (! $this->allowed($seasonId, 'writers')) {
            return;
        }

        $this->send(fn () => event(new SeasonWriterLeaderboardUpdated($seasonId, [
            'season' => $seasonId,
            'entry' => $entryPublicId,
            'stars_total' => $starsTotal,
            'voters_count' => $votersCount,
        ])));
    }

    /** إشارة «صدر الترتيب النهائي» (مرة واحدة؛ بلا تحديد معدّل). حمولتها تجميعية: الموسم + علامة الإنهاء فقط. */
    public function writersFinalized(int $seasonId): void
    {
        if (! (bool) config('seasonal_recognition.realtime.enabled', true)) {
            return;
        }

        $this->send(fn () => event(new SeasonWriterLeaderboardUpdated($seasonId, [
            'season' => $seasonId,
            'entry' => null,
            'finalized' => 1,
        ])));
    }

    public function supportersUpdated(int $seasonId): void
    {
        if (! $this->allowed($seasonId, 'supporters')) {
            return;
        }

        $this->send(fn () => event(new SeasonSupportersLeaderboardUpdated($seasonId)));
    }

    protected function allowed(int $seasonId, string $kind): bool
    {
        if (! (bool) config('seasonal_recognition.realtime.enabled', true)) {
            return false;
        }

        $interval = max(0, (int) config('seasonal_recognition.realtime.min_interval_seconds', 1));

        if ($interval === 0) {
            return true;
        }

        try {
            return Cache::add("seasonal:broadcast:{$kind}:{$seasonId}", 1, $interval);
        } catch (Throwable) {
            return true;
        }
    }

    protected function send(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

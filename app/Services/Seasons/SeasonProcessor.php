<?php

namespace App\Services\Seasons;

use App\Jobs\DispatchSeasonAnnouncementChunk;
use App\Jobs\DispatchSeasonPhaseStartedChunk;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonPhase;
use App\Services\SeasonLifecycleService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * E42 - معالج الموسم المجدول (seasons:process): يلتقط ما ينتج عن **مرور الوقت** وحده. idempotent بالكامل، ولا يمسّ تقدّمًا ولا مكافآت ولا مهامًا.
 *
 *  1) بدء المراحل: مرحلة بلغ starts_at لموسم **مباشر** تُحجَز بـUPDATE شرطي ذري (went_live_at IS NULL) ثم يُرسَل إشعار «بدأت مرحلة» لمشاركي الحملة الأساسية.
 *     لا إشعار: للمرحلة الأولى (يغطيها إشعار بدء الموسم)، ولا لمرحلة فات أمرها (أقدم من phase_notice_max_age_hours: تفعيل متأخر/تعبئة رجعية → تُعلَّم معالَجة بصمت)،
 *     ولا لموسم انتهى. مرحلة موسم لم يبدأ بعد تُترك دون حجز.
 *  2) الإعلانات: إعلان طلبت الإدارة إشعاره صراحةً (notify_audience) وبلغ موعده يُحجَز بـnotified_at بذريّة ثم تُرسَل دفعات بالطابور؛ المنتهي/المتأخر/موسمه منتهٍ يُعلَّم معالَجًا بصمت.
 *  3) تجميد النافذة: موسم بلغ archive_at بالزمن تُنسخ نافذته الفعلية لأطرافه الفارغة (SeasonBuilderService::freezeWindow).
 * فشل عنصر واحد لا يوقف البقية. تكرار التشغيل أو تزامن عمليتين لا يرسل شيئًا مرتين (الحجز الذري + مفتاح idempotency لكل مستخدم).
 */
class SeasonProcessor
{
    public function __construct(
        protected SeasonLifecycleService $lifecycle,
        protected SeasonBuilderService $builder,
    ) {}

    /** @return array<string, int> */
    public function run(?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $stats = ['phases_started' => 0, 'phases_silent' => 0, 'announcements_sent' => 0, 'announcements_silent' => 0, 'windows_frozen' => 0, 'failed' => 0];

        $this->phases($now, $stats);
        $this->announcements($now, $stats);
        $this->freeze($now, $stats);

        return $stats;
    }

    protected function maxAgeHours(): int
    {
        return max(1, (int) config('seasons.notifications.phase_notice_max_age_hours', 24));
    }

    /** @param array<string, int> $stats */
    protected function phases(Carbon $now, array &$stats): void
    {
        SeasonPhase::query()->whereNull('went_live_at')->where('starts_at', '<=', $now)
            ->whereHas('season', fn ($q) => $q->publiclyVisible($now))
            ->with('season.campaign')->orderBy('id')
            ->chunkById(50, function ($phases) use ($now, &$stats) {
                foreach ($phases as $phase) {
                    try {
                        $this->startPhase($phase, $now, $stats);
                    } catch (\Throwable $e) {
                        report($e);
                        $stats['failed']++;
                    }
                }
            });
    }

    /** @param array<string, int> $stats */
    protected function startPhase(SeasonPhase $phase, Carbon $now, array &$stats): void
    {
        $season = $phase->season;
        $state = $this->lifecycle->state($season, $now);

        if (! in_array($state, [SeasonLifecycleService::STATE_LIVE, SeasonLifecycleService::STATE_ENDED, SeasonLifecycleService::STATE_ARCHIVED], true)) {
            return; // الموسم لم يبدأ: لا نحجز المرحلة بعد.
        }

        $claimed = SeasonPhase::query()->whereKey($phase->getKey())->whereNull('went_live_at')->update(['went_live_at' => $now]);

        if ($claimed !== 1) {
            return; // سبقتنا عملية أخرى.
        }

        $first = $season->phases()->value('id') === $phase->getKey(); // الترتيب الحتمي (starts_at، sort_order، id)
        $stale = $phase->starts_at->lt($now->copy()->subHours($this->maxAgeHours()));

        if ($state !== SeasonLifecycleService::STATE_LIVE || $first || $stale || $phase->ends_at->lte($now)) {
            $stats['phases_silent']++;

            return;
        }

        DispatchSeasonPhaseStartedChunk::dispatch($phase->getKey());
        $stats['phases_started']++;
    }

    /** @param array<string, int> $stats */
    protected function announcements(Carbon $now, array &$stats): void
    {
        SeasonAnnouncement::query()->whereNotNull('notify_audience')->whereNull('notified_at')
            ->whereNotNull('published_at')->where('published_at', '<=', $now)
            ->whereHas('season', fn ($q) => $q->publiclyVisible($now))
            ->with('season.campaign')->orderBy('id')
            ->chunkById(50, function ($rows) use ($now, &$stats) {
                foreach ($rows as $row) {
                    try {
                        $this->sendAnnouncement($row, $now, $stats);
                    } catch (\Throwable $e) {
                        report($e);
                        $stats['failed']++;
                    }
                }
            });
    }

    /** @param array<string, int> $stats */
    protected function sendAnnouncement(SeasonAnnouncement $row, Carbon $now, array &$stats): void
    {
        $claimed = SeasonAnnouncement::query()->whereKey($row->getKey())->whereNull('notified_at')->update(['notified_at' => $now]);

        if ($claimed !== 1) {
            return;
        }

        $stale = $row->published_at->lt($now->copy()->subHours($this->maxAgeHours()));
        $expired = $row->expires_at !== null && $row->expires_at->lte($now);
        $live = $this->lifecycle->state($row->season, $now) === SeasonLifecycleService::STATE_LIVE;

        if ($stale || $expired || ! $live) {
            $stats['announcements_silent']++;

            return;
        }

        DispatchSeasonAnnouncementChunk::dispatch($row->getKey());
        $stats['announcements_sent']++;
    }

    /** @param array<string, int> $stats */
    protected function freeze(Carbon $now, array &$stats): void
    {
        Season::query()->whereNotNull('archive_at')->where('archive_at', '<=', $now)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhereColumn('ends_at', '>', 'archive_at')
                    ->orWhere(fn ($w) => $w->whereNull('starts_at')->whereHas('campaign', fn ($c) => $c->whereNotNull('starts_at')));
            })
            ->orderBy('id')->chunkById(50, function ($seasons) use (&$stats) {
                foreach ($seasons as $season) {
                    try {
                        $stats['windows_frozen'] += $this->builder->freezeWindow($season) ? 1 : 0;
                    } catch (\Throwable $e) {
                        report($e);
                        $stats['failed']++;
                    }
                }
            });
    }
}

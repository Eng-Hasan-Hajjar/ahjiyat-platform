<?php

namespace App\Services\Seasons;

use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\Calendar\EventItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * E42 - الجدول الزمني **داخل الموسم الواحد**: إسقاط مشتقّ لتواريخ مصادرها (نافذة الموسم، مراحله، منافساته، حملاته الإضافية، مساره) على EventItem الموحَّد من E34.
 * لا جدول ولا كاش ولا نسخ: يستقبل مقاطع جاهزة من SeasonHubService (محمَّلة أصلًا بلا استعلامات إضافية) ويحسب الحالة وقت القراءة بـEventCalendarService::statusOf نفسها.
 * بلا بيانات مستخدم ولا مبالغ ولا أسرار: عنوان ورابط وتواريخ فقط.
 */
final class SeasonCalendarService
{
    public const TYPE_PHASE = 'season_phase';

    public const TYPE_WINDOW = 'season_window';

    public const TYPE_PASS = 'ahjiyat_pass';

    public function __construct(protected EventCalendarService $events) {}

    /**
     * @param  iterable<array{type: string, key: string, title: string, starts: ?CarbonInterface, ends: ?CarbonInterface, url: string, badge: string}>  $spans
     * @return Collection<int, EventItem> الجارية أولًا (الأقرب انتهاءً) ثم القادمة (الأقرب بدءًا)؛ المنتهية لا تُعرض هنا (محدودة بـseasons.hub.calendar_items).
     */
    public function items(iterable $spans, ?CarbonInterface $now = null): Collection
    {
        $now = ($now ? Carbon::instance($now) : $this->events->now())->copy()->setTimezone($this->events->timezone());
        $tz = $this->events->timezone();

        return collect($spans)
            ->map(function (array $s) use ($now, $tz) {
                $starts = $s['starts'] !== null ? Carbon::instance($s['starts'])->setTimezone($tz) : null;
                $ends = $s['ends'] !== null ? Carbon::instance($s['ends'])->setTimezone($tz) : null;

                return new EventItem(
                    type: $s['type'],
                    key: $s['key'],
                    title: $s['title'],
                    startsAt: $starts,
                    endsAt: $ends,
                    status: $this->events->statusOf($starts, $ends, $now),
                    url: $s['url'],
                    badge: $s['badge'],
                );
            })
            ->reject(fn (EventItem $i) => $i->status === EventCalendarService::STATUS_ENDED)
            ->sortBy(fn (EventItem $i) => [
                $i->status === EventCalendarService::STATUS_UPCOMING ? 1 : 0,
                ($i->status === EventCalendarService::STATUS_UPCOMING ? $i->startsAt : $i->endsAt)?->getTimestamp() ?? PHP_INT_MAX,
                $i->key,
            ])
            ->take(max(1, (int) config('seasons.hub.calendar_items', 12)))
            ->values();
    }
}

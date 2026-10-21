<?php

namespace App\Services\LiveOps\Calendar;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\ReferralCampaign;
use App\Models\Season;
use App\Models\SponsorshipProgram;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\CampaignProgressPairs;
use App\Services\Engagement\QuestPeriodService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * E34 - تقويم الأحداث: **تجميع** لمصادر موجودة (مواسم، حملات، منافسات، بطولات فرق، حملات دعوة حيّة، برامج رعاية نشطة) بلا نسخ بياناتها
 * ولا جدول أحداث جديد؛ المصدر يبقى سلطة حالته وتواريخه.
 *
 *  - لقطة عامة مشتركة (مصفوفات خفيفة بلا بيانات مستخدم) بكاش قصير؛ الحالة (قادم/جارٍ/ينتهي قريبًا/انتهى) **تُحسب وقت القراءة** من التواريخ فلا تتأخر.
 *  - توقيت موحَّد: المنطقة الزمنية للمنصة (نفس QuestPeriodService). الأسبوع يبدأ الاثنين كالمهام الأسبوعية.
 *  - كل مصدر محدود (per_source_limit) ومقيَّد زمنيًا (لا انتهى، لا أبعد من upcoming_days) → لا استعلام بلا حد.
 *  - "أجندتي": مرتبطة بالمستخدم وبحدّ أقصى صغير؛ لا تكتب شيئًا. لا مزامنة تقويم خارجي.
 *  - لا مبالغ رعاية ولا بيانات خاصة في أي عنصر: عنوان ورابط وتواريخ فقط. العناوين تُهرَّب عند العرض (Blade).
 */
class EventCalendarService
{
    public const CACHE_KEY = 'liveops:calendar:v1';

    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDING_SOON = 'ending_soon';
    public const STATUS_ENDED = 'ended';

    public const TYPE_SEASON = 'season';
    public const TYPE_CAMPAIGN = 'campaign';
    public const TYPE_COMPETITIVE = 'competitive_event';
    public const TYPE_TEAM_CHAMPIONSHIP = 'team_championship';
    public const TYPE_REFERRAL = 'referral_campaign';
    public const TYPE_SPONSORSHIP = 'sponsorship_program';

    public function __construct(protected QuestPeriodService $periods) {}

    public function timezone(): string
    {
        return $this->periods->timezone();
    }

    public function now(): Carbon
    {
        return now()->setTimezone($this->timezone());
    }

    /** حالة مشتقة من تواريخ حقيقية فقط. بلا تواريخ = جارٍ دائم (بلا عدّ تنازلي). */
    public function statusOf(?Carbon $startsAt, ?Carbon $endsAt, ?Carbon $now = null): string
    {
        $now ??= $this->now();

        if ($startsAt !== null && $now->lt($startsAt)) {
            return self::STATUS_UPCOMING;
        }

        if ($endsAt !== null) {
            if ($now->gt($endsAt)) {
                return self::STATUS_ENDED;
            }

            if ($now->diffInSeconds($endsAt, false) <= max(1, (int) config('liveops.calendar.ending_soon_hours', 24)) * 3600) {
                return self::STATUS_ENDING_SOON;
            }
        }

        return self::STATUS_LIVE;
    }

    // ───────────────────────── اللقطة المشتركة ─────────────────────────

    /** @return list<array<string, mixed>> */
    public function snapshot(): array
    {
        $seconds = max(0, (int) config('liveops.calendar.cache_seconds', 60));

        return $seconds === 0 ? $this->build() : Cache::remember(self::CACHE_KEY, $seconds, fn () => $this->build());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array<string, mixed>> */
    protected function build(): array
    {
        $now = now();
        $horizon = $now->copy()->addDays(max(1, (int) config('liveops.calendar.upcoming_days', 60)));
        $limit = max(1, (int) config('liveops.calendar.per_source_limit', 30));
        $rows = [];

        // المواسم المنشورة (خطها الزمني = خط حملتها)
        Season::query()->where('is_published', true)
            ->whereHas('campaign', fn ($q) => $q->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $horizon)))
            ->with('campaign:id,title,starts_at,ends_at')
            ->orderBy('id')->limit($limit)->get()
            ->each(function (Season $s) use (&$rows) {
                $rows[] = $this->row(self::TYPE_SEASON, $s->id, $s->campaign?->title ?? $s->code, $s->campaign?->starts_at, $s->campaign?->ends_at, 'seasons.show', ['season' => $s->slug], 'موسم', $s->banner_image, $s->campaign_id);
            });

        // الحملات المستقلة (غير المرتبطة بموسم منشور؛ المرتبطة تظهر عبر موسمها بلا تكرار)
        Campaign::query()->where('is_active', true)
            ->whereDoesntHave('season', fn ($q) => $q->where('is_published', true))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $horizon))
            ->orderBy('id')->limit($limit)
            ->get(['id', 'slug', 'title', 'cover_image', 'starts_at', 'ends_at'])
            ->each(function (Campaign $c) use (&$rows) {
                $rows[] = $this->row(self::TYPE_CAMPAIGN, $c->id, $c->title, $c->starts_at, $c->ends_at, 'campaigns.show', ['campaign' => $c->slug], 'حملة', $c->cover_image, $c->id);
            });

        // المنافسات الرسمية المنشورة (القادمة/الجارية فقط)
        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)
            ->where('ends_at', '>=', $now)->where('starts_at', '<=', $horizon)
            ->orderBy('starts_at')->limit($limit)
            ->get(['id', 'slug', 'title', 'starts_at', 'ends_at'])
            ->each(function (CompetitiveEvent $e) use (&$rows) {
                $rows[] = $this->row(self::TYPE_COMPETITIVE, $e->id, $e->title, $e->starts_at, $e->ends_at, 'competitions.show', ['event' => $e->slug], 'منافسة');
            });

        TeamChampionship::query()->where('status', TeamChampionship::STATUS_PUBLISHED)
            ->where('ends_at', '>=', $now)->where('starts_at', '<=', $horizon)
            ->orderBy('starts_at')->limit($limit)
            ->get(['id', 'slug', 'title', 'starts_at', 'ends_at'])
            ->each(function (TeamChampionship $t) use (&$rows) {
                $rows[] = $this->row(self::TYPE_TEAM_CHAMPIONSHIP, $t->id, $t->title, $t->starts_at, $t->ends_at, 'team-championships.show', ['championship' => $t->slug], 'بطولة فرق');
            });

        // حملات الدعوة الحيّة والعلنية (الصفحة خلف الدخول؛ لا أرقام ولا مكافآت هنا)
        if ((bool) config('referrals.enabled', true)) {
            ReferralCampaign::query()->live()->where('is_public', true)
                ->orderBy('id')->limit($limit)
                ->get(['id', 'name', 'starts_at', 'ends_at'])
                ->each(function (ReferralCampaign $r) use (&$rows) {
                    $rows[] = $this->row(self::TYPE_REFERRAL, $r->id, $r->name, $r->starts_at, $r->ends_at, 'referrals.index', [], 'دعوة الأصدقاء');
                });
        }

        // برامج الرعاية النشطة (عنوان وتواريخ فقط؛ لا مبالغ ولا أهداف تمويل)
        if ((bool) config('sponsorship.enabled', true)) {
            SponsorshipProgram::query()->activeNow()
                ->orderBy('id')->limit($limit)
                ->get(['id', 'slug', 'title', 'starts_at', 'ends_at'])
                ->each(function (SponsorshipProgram $p) use (&$rows) {
                    $rows[] = $this->row(self::TYPE_SPONSORSHIP, $p->id, $p->title, $p->starts_at, $p->ends_at, 'supporters.index', ['scope' => 'program', 'program' => $p->slug], 'برنامج رعاية');
                });
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function row(string $type, int $id, string $title, ?\DateTimeInterface $starts, ?\DateTimeInterface $ends, string $route, array $params, string $badge, ?string $image = null, ?int $campaignId = null): array
    {
        return [
            'type' => $type,
            'key' => $type.':'.$id,
            'ref' => $id,
            'campaign_id' => $campaignId,
            'title' => $title,
            'starts_at' => $starts ? Carbon::instance($starts)->toIso8601String() : null,
            'ends_at' => $ends ? Carbon::instance($ends)->toIso8601String() : null,
            'route' => $route,
            'params' => $params,
            'badge' => $badge,
            'image' => $image,
        ];
    }

    // ───────────────────────── القراءة (الحالة تُحسب هنا) ─────────────────────────

    /**
     * كل العناصر غير المنتهية، مرتبة: الجارية (الأقرب انتهاءً أولًا) ثم القادمة (الأقرب بدءًا).
     *
     * @return Collection<int, EventItem>
     */
    public function items(?Carbon $now = null): Collection
    {
        $now = ($now ?? $this->now())->copy()->setTimezone($this->timezone());
        $tz = $this->timezone();

        return collect($this->snapshot())
            ->filter(fn (array $r) => Route::has($r['route']))
            ->map(function (array $r) use ($now, $tz) {
                $starts = $r['starts_at'] !== null ? Carbon::parse($r['starts_at'])->setTimezone($tz) : null;
                $ends = $r['ends_at'] !== null ? Carbon::parse($r['ends_at'])->setTimezone($tz) : null;

                return new EventItem(
                    $r['type'], $r['key'], (string) $r['title'], $starts, $ends,
                    $this->statusOf($starts, $ends, $now),
                    route($r['route'], $r['params']),
                    (string) $r['badge'],
                    $r['image'] ?: null,
                    $r['ref'] ?? null,
                    $r['campaign_id'] ?? null,
                );
            })
            ->reject(fn (EventItem $i) => $i->status === self::STATUS_ENDED)
            ->sort(function (EventItem $a, EventItem $b) {
                $rank = fn (EventItem $i) => $i->status === self::STATUS_UPCOMING ? 1 : 0;

                return [$rank($a), ($rank($a) === 1 ? $a->startsAt : $a->endsAt)?->getTimestamp() ?? PHP_INT_MAX, $a->key]
                    <=> [$rank($b), ($rank($b) === 1 ? $b->startsAt : $b->endsAt)?->getTimestamp() ?? PHP_INT_MAX, $b->key];
            })
            ->values();
    }

    /**
     * الأقسام الأربعة: الآن / اليوم / هذا الأسبوع / قريبًا. كل عنصر قادم يظهر بقسم واحد فقط.
     *
     * @return array{now: Collection<int,EventItem>, today: Collection<int,EventItem>, week: Collection<int,EventItem>, upcoming: Collection<int,EventItem>}
     */
    public function sections(?Carbon $now = null): array
    {
        $now = ($now ?? $this->now())->copy()->setTimezone($this->timezone());
        $items = $this->items($now);
        $endOfToday = $now->copy()->endOfDay();
        $endOfWeek = $now->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        $live = $items->filter(fn (EventItem $i) => $i->status !== self::STATUS_UPCOMING)->values();
        $upcoming = $items->filter(fn (EventItem $i) => $i->status === self::STATUS_UPCOMING);

        return [
            'now' => $live,
            'today' => $upcoming->filter(fn (EventItem $i) => $i->startsAt->lte($endOfToday))->values(),
            'week' => $upcoming->filter(fn (EventItem $i) => $i->startsAt->gt($endOfToday) && $i->startsAt->lte($endOfWeek))->values(),
            'upcoming' => $upcoming->filter(fn (EventItem $i) => $i->startsAt->gt($endOfWeek))->values(),
        ];
    }

    /**
     * أجندتي: ما يخص المستخدم فعلًا (منافسة مسجَّل بها، حملة/موسم له تقدّم فيه) + أي عنصر ينتهي قريبًا من تلك المجموعة. بحدّ أقصى صغير، قراءة فقط.
     *
     * @return Collection<int, EventItem>
     */
    public function agendaFor(User $user, ?Carbon $now = null): Collection
    {
        $items = $this->items($now);

        if ($items->isEmpty()) {
            return collect();
        }

        $registered = $items->contains(fn (EventItem $i) => $i->type === self::TYPE_COMPETITIVE)
            ? CompetitiveEventParticipant::query()->where('user_id', $user->id)->pluck('competitive_event_id')->map(fn ($v) => (int) $v)->all()
            : [];

        $campaigns = $items->contains(fn (EventItem $i) => in_array($i->type, [self::TYPE_SEASON, self::TYPE_CAMPAIGN], true))
            ? CampaignProgressPairs::campaignsByUser([$user->id])->get($user->id, collect())->map(fn ($v) => (int) $v)->all()
            : [];

        return $items
            ->filter(fn (EventItem $i) => match ($i->type) {
                self::TYPE_COMPETITIVE => in_array($i->ref, $registered, true),
                self::TYPE_SEASON, self::TYPE_CAMPAIGN => $i->campaignId !== null && in_array($i->campaignId, $campaigns, true),
                default => false,
            })
            ->take(max(1, (int) config('liveops.calendar.agenda_max', 5)))
            ->values();
    }
}

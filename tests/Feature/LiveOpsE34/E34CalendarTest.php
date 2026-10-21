<?php

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\Season;
use App\Services\LiveOps\Calendar\EventCalendarService;
use App\Services\LiveOps\Calendar\EventItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    e34_seed();
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'UTC')); // أربعاء
});
afterEach(fn () => Carbon::setTestNow());

function e34_campaign(array $over = []): Campaign
{
    return Campaign::withoutEvents(fn () => Campaign::factory()->create($over + ['slug' => 'c-'.fake()->unique()->numerify('######'), 'title' => 'حملة '.fake()->unique()->numerify('####'), 'is_active' => true]));
}

function e34_cal(): EventCalendarService
{
    return app(EventCalendarService::class);
}

test('Z6-01: /events is public, returns 200 for guests, has one h1 and states the platform time zone', function () {
    e34_campaign(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'title' => 'حملة التقويم']);

    $html = $this->get('/events')->assertOk()->assertSee('حملة التقويم')->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)->and($html)->toContain(config('app.timezone'));
    foreach (['اشترِ', 'ستفقد', 'باقي دقائق', 'آخر فرصة'] as $bad) {
        expect($html)->not->toContain($bad);
    }
});

test('Z6-02: status is derived from real dates - upcoming, live, ending soon, ended, and undated items are simply live', function () {
    $c = e34_cal();
    $now = now();

    expect($c->statusOf($now->copy()->addHour(), $now->copy()->addDays(3), $now))->toBe(EventCalendarService::STATUS_UPCOMING)
        ->and($c->statusOf($now->copy()->subHour(), $now->copy()->addDays(3), $now))->toBe(EventCalendarService::STATUS_LIVE)
        ->and($c->statusOf($now->copy()->subHour(), $now->copy()->addHours(23), $now))->toBe(EventCalendarService::STATUS_ENDING_SOON)
        ->and($c->statusOf($now->copy()->subDays(2), $now->copy()->subSecond(), $now))->toBe(EventCalendarService::STATUS_ENDED)
        ->and($c->statusOf(null, null, $now))->toBe(EventCalendarService::STATUS_LIVE)
        ->and($c->statusOf(null, $now->copy()->addHours(2), $now))->toBe(EventCalendarService::STATUS_ENDING_SOON);
});

test('Z6-03: standalone campaigns show once, season campaigns show through their published season only, inactive and ended ones never show', function () {
    $standalone = e34_campaign(['title' => 'مستقلة', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    $seasonCampaign = e34_campaign(['title' => 'حملة الموسم', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $seasonCampaign->id, 'slug' => 'pub-s', 'is_published' => true]));
    $draftCampaign = e34_campaign(['title' => 'موسم مسودة', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    Season::withoutEvents(fn () => Season::factory()->create(['campaign_id' => $draftCampaign->id, 'slug' => 'draft-s', 'is_published' => false]));
    e34_campaign(['title' => 'معطّلة', 'is_active' => false, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    e34_campaign(['title' => 'منتهية', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDay()]);

    $items = e34_cal()->items();
    $titles = $items->pluck('title')->all();

    expect($titles)->toContain('مستقلة')->toContain('حملة الموسم')->toContain('موسم مسودة')->not->toContain('معطّلة')->not->toContain('منتهية')
        ->and(count(array_keys($titles, 'حملة الموسم')))->toBe(1)
        ->and($items->firstWhere('title', 'حملة الموسم')->type)->toBe(EventCalendarService::TYPE_SEASON)
        ->and($items->firstWhere('title', 'موسم مسودة')->type)->toBe(EventCalendarService::TYPE_CAMPAIGN)
        ->and($items->firstWhere('title', 'مستقلة')->type)->toBe(EventCalendarService::TYPE_CAMPAIGN);
});

test('Z6-04: competitive events appear only when published and not finished, within the upcoming horizon', function () {
    e17Event(['title' => 'منافسة منشورة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    e17Event(['title' => 'مسودة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], CompetitiveEvent::STATUS_DRAFT);
    e17Event(['title' => 'ملغاة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], CompetitiveEvent::STATUS_CANCELLED);
    e17Event(['title' => 'مكتملة', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()], CompetitiveEvent::STATUS_COMPLETED);
    e17Event(['title' => 'بعيدة جدًا', 'starts_at' => now()->addDays(200), 'ends_at' => now()->addDays(201)]);

    $titles = e34_cal()->items()->pluck('title')->all();

    expect($titles)->toBe(['منافسة منشورة']);
});

test('Z6-05: sections (now, today, this week, upcoming) use the platform time zone and Monday-to-Sunday weeks, one section per item', function () {
    config(['app.timezone' => 'UTC']);
    $cal = e34_cal();

    e34_campaign(['title' => 'جارية', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(20)]);
    e34_campaign(['title' => 'الليلة', 'starts_at' => Carbon::parse('2026-10-14 21:30:00', 'UTC'), 'ends_at' => now()->addDays(20)]);
    e34_campaign(['title' => 'الجمعة', 'starts_at' => Carbon::parse('2026-10-16 10:00:00', 'UTC'), 'ends_at' => now()->addDays(20)]);
    e34_campaign(['title' => 'الأسبوع القادم', 'starts_at' => Carbon::parse('2026-10-19 00:00:00', 'UTC'), 'ends_at' => now()->addDays(30)]);

    $s = $cal->sections();
    $names = fn ($k) => $s[$k]->pluck('title')->all();

    expect($names('now'))->toBe(['جارية'])->and($names('today'))->toBe(['الليلة'])->and($names('week'))->toBe(['الجمعة'])->and($names('upcoming'))->toBe(['الأسبوع القادم']);

    // منطقة المنصة تغيّر "اليوم": 21:30 UTC = 00:30 غدًا بتوقيت الرياض ⇒ يسقط من "اليوم" إلى "هذا الأسبوع"
    config(['app.timezone' => 'Asia/Riyadh']);
    $s2 = $cal->sections();
    expect($s2['today']->pluck('title')->all())->toBe([])->and($s2['week']->pluck('title')->sort()->values()->all())->toBe(['الجمعة', 'الليلة']);
});

test('Z6-06: the shared snapshot is cached without user data, and status is recomputed at read time as the clock moves', function () {
    config(['liveops.calendar.cache_seconds' => 60]);
    e34_campaign(['title' => 'قريبة', 'starts_at' => now()->addHours(2), 'ends_at' => now()->addDays(5)]);

    $cal = e34_cal();
    expect($cal->items()->first()->status)->toBe(EventCalendarService::STATUS_UPCOMING);

    e34_campaign(['title' => 'جديدة بعد الكاش', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    expect($cal->items()->pluck('title')->all())->toBe(['قريبة']); // اللقطة مخزَّنة

    Carbon::setTestNow(now()->addHours(3)); // الحدث بدأ: الحالة تتغير بلا تفريغ كاش
    expect($cal->items()->first()->status)->toBe(EventCalendarService::STATUS_LIVE);

    EventCalendarService::flush();
    expect($cal->items()->pluck('title')->all())->toContain('جديدة بعد الكاش');

    $raw = json_encode(Cache::get(EventCalendarService::CACHE_KEY));
    expect($raw)->not->toContain('@')->not->toContain('email');
});

test('Z6-07: my agenda holds only what concerns the player, is capped, and is empty for others', function () {
    $mine = e34_user();
    $other = e34_user();
    $events = [];

    foreach (range(1, 7) as $i) {
        $events[$i] = e17Event(['title' => "منافسة {$i}", 'starts_at' => now()->addHours($i + 1), 'ends_at' => now()->addDays(2)]);
    }

    foreach ($events as $e) {
        CompetitiveEventParticipant::query()->create(['competitive_event_id' => $e->id, 'user_id' => $mine->id, 'status' => 'registered', 'registered_at' => now()]);
    }

    config(['liveops.calendar.agenda_max' => 5]);

    expect(e34_cal()->agendaFor($mine))->toHaveCount(5)->and(e34_cal()->agendaFor($other))->toHaveCount(0);
});

test('Z6-08: items expose a fixed whitelist of fields - no amounts, goals, emails or internals', function () {
    e34_campaign(['title' => 'حملة', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(2)]);
    $item = e34_cal()->items()->first();

    expect($item)->toBeInstanceOf(EventItem::class)
        ->and(array_keys($item->toArray()))->toBe(['type', 'title', 'starts_at', 'ends_at', 'status', 'url', 'badge', 'image']);

    foreach (e34_cal()->snapshot() as $row) {
        expect(array_diff(array_keys($row), ['type', 'key', 'ref', 'campaign_id', 'title', 'starts_at', 'ends_at', 'route', 'params', 'badge', 'image']))->toBe([]);
    }
});

test('Z6-09: countdowns exist only for real dates - never for ended or undated items', function () {
    e34_campaign(['title' => 'بلا تواريخ', 'starts_at' => null, 'ends_at' => null]);
    e34_campaign(['title' => 'تنتهي', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(4)]);
    e34_campaign(['title' => 'تبدأ', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)]);

    $by = e34_cal()->items()->keyBy('title');

    expect($by['بلا تواريخ']->countdownTo())->toBeNull()
        ->and($by['تنتهي']->countdownTo()->equalTo($by['تنتهي']->endsAt))->toBeTrue()
        ->and($by['تبدأ']->countdownTo()->equalTo($by['تبدأ']->startsAt))->toBeTrue();
});

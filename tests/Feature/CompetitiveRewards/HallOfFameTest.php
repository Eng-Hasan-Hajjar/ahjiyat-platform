<?php

require_once __DIR__.'/RewardTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

/** حدث معتمَد بمنصة ثلاثية. @return array{0: CompetitiveEvent, 1: array<int, User>} */
function e18Hall(string $title, array $attrs = []): array
{
    $event = e18Finalized($first = e16User(['name' => "{$title} أول"]), 1, eventAttrs: ['title' => $title] + $attrs);
    $second = e16User(['name' => "{$title} ثانٍ"]);
    $third = e16User(['name' => "{$title} ثالث"]);
    e18AddResult($event, $second, 2);
    e18AddResult($event, $third, 3);

    return [$event, [$first, $second, $third]];
}

test('34/38: a finalized event appears with its winner and top three in rank order - rank 4 and wrong answers never appear', function () {
    [$event, [$first, $second, $third]] = e18Hall('كأس الشتاء');
    e18AddResult($event, e16User(['name' => 'الرابع هنا']), 4);
    e18AddResult($event, e16User(['name' => 'خاطئ هنا']), 2, correct: false);

    $page = $this->get(route('competitions.hall-of-fame'))->assertOk()->assertSee('كأس الشتاء')->assertSee('🥇')->assertSee('🥈')->assertSee('🥉')->assertDontSee('الرابع هنا')->assertDontSee('خاطئ هنا');

    expect($page->viewData('podium')->get($event->id)->pluck('user.name')->all())->toBe([$first->name, $second->name, $third->name]);
});

test('35/36/37: drafts, upcoming, live, ended-but-not-finalized and cancelled events never appear in the hall - only finalized ones', function () {
    e18Hall('منافسة معتمدة');
    foreach ([['منافسة مسودة', 'draft'], ['منافسة منشورة', 'published'], ['منافسة ملغاة', 'cancelled']] as [$title, $status]) {
        $e = e17Event(['title' => $title], $status);
        e18AddResult($e, e16User(), 1);
    }
    $ended = e17Event(['title' => 'منافسة منتهية بلا اعتماد', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    e18AddResult($ended, e16User(), 1);

    $page = $this->get(route('competitions.hall-of-fame'))->assertOk();

    $page->assertSee('منافسة معتمدة')->assertDontSee('منافسة مسودة')->assertDontSee('منافسة منشورة')->assertDontSee('منافسة ملغاة')->assertDontSee('منافسة منتهية بلا اعتماد');
    expect($page->viewData('events')->total())->toBe(1);
});

test('39: player links follow profile visibility - a viewable profile links, a private one is shown without a link', function () {
    $event = e18Finalized($public = e16User(['name' => 'Public Hero']), 1, eventAttrs: ['title' => 'حدث الروابط']);
    $private = e16User(['name' => 'Private Hero', 'profile_visibility' => User::VISIBILITY_PRIVATE]);
    e18AddResult($event, $private, 2);

    $html = $this->get(route('competitions.hall-of-fame'))->assertOk()->getContent();

    expect($html)->toContain(route('players.show', $public))->and($html)->toContain('Private Hero')->and($html)->not->toContain(route('players.show', $private));
});

test('40/D9: the page runs a fixed number of queries however many events it lists (no N+1)', function () {
    foreach (range(1, 2) as $i) {
        e18Hall("حدث صغير {$i}");
    }
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('competitions.hall-of-fame'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count();            // إحماء: تسجيل بصمة الجهاز وتحميل إعدادات المنصة يحدثان بالطلب الأول فقط
    $few = $count();

    foreach (range(1, 9) as $i) {
        e18Hall("حدث إضافي {$i}");
    }

    expect($count())->toBe($few);
});

test('D3/D6: each event shows its date and participants, links to its historical page, and old finalized events stay viewable forever', function () {
    [$event] = e18Hall('حدث تاريخي', ['ends_at' => now()->subYears(2), 'starts_at' => now()->subYears(2)->subDays(3)]);

    $this->get(route('competitions.hall-of-fame'))->assertOk()->assertSee(route('competitions.show', $event), false)->assertSee($event->ends_at->format('Y-m-d'));
    $this->get(route('competitions.show', $event))->assertOk()->assertSee('النتائج النهائية')->assertSee('حدث تاريخي');
});

test('D7/D8: filters by year and by event title (wildcards literal), and 12 events per page', function () {
    e18Hall('كأس 2025', ['starts_at' => Carbon::parse('2025-03-01'), 'ends_at' => Carbon::parse('2025-03-05')]);
    e18Hall('كأس 2026', ['starts_at' => Carbon::parse('2026-03-01'), 'ends_at' => Carbon::parse('2026-03-05')]);
    e18Hall('بطولة 100%', ['starts_at' => Carbon::parse('2026-04-01'), 'ends_at' => Carbon::parse('2026-04-05')]);

    $this->get(route('competitions.hall-of-fame', ['year' => 2025]))->assertSee('كأس 2025')->assertDontSee('كأس 2026')->assertDontSee('بطولة 100%');
    $this->get(route('competitions.hall-of-fame', ['q' => 'بطولة']))->assertSee('بطولة 100%')->assertDontSee('كأس 2025');
    $this->get(route('competitions.hall-of-fame', ['q' => '0%']))->assertSee('بطولة 100%')->assertDontSee('كأس 2026');     // % حرفية
    $this->get(route('competitions.hall-of-fame', ['q' => '%']))->assertSee('بطولة 100%')->assertDontSee('كأس 2025');       // لا تطابق الكل
    expect($this->get(route('competitions.hall-of-fame'))->viewData('years'))->toBe([2026, 2025]);

    foreach (range(1, 12) as $i) {
        e18Hall("حدث ترقيم {$i}");
    }
    $page = $this->get(route('competitions.hall-of-fame'))->assertOk();
    expect($page->viewData('events')->count())->toBe(12)->and($page->viewData('events')->total())->toBe(15);
});

test('the literal hall-of-fame route is reachable for guests and no event can take its slug', function () {
    $this->get('/competitions/hall-of-fame')->assertOk()->assertSee('قاعة الأمجاد');
    $this->get(route('competitions.index'))->assertOk()->assertSee('قاعة الأمجاد');
    expect(fn () => e17Event(['slug' => 'hall-of-fame']))->toThrow(InvalidArgumentException::class, 'محجوز');
});

test('an empty hall shows a clear empty state', function () {
    $this->get(route('competitions.hall-of-fame'))->assertOk()->assertSee('لا منافسات معتمَدة مطابقة بعد.');
});

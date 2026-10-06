<?php

require_once __DIR__.'/CompetitiveTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventResult;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Competitive\CompetitiveEventFinalizer;
use App\Services\Social\BlockService;
use Carbon\Carbon;

beforeEach(fn () => e17Freeze());
afterEach(fn () => Carbon::setTestNow());

/** نتيجة مباشرة بقيم معلومة (بجلسة منتهية بسياق الحدث) لاختبار الترتيب بلا لعب كامل. */
function e17Result(CompetitiveEvent $event, User $user, int $score, int $durationMs, ?Carbon $completedAt = null): CompetitiveEventResult
{
    $session = GameSession::create([
        'user_id' => $user->id, 'puzzle_id' => $event->puzzle_id, 'context_type' => 'competitive_event', 'context_id' => $event->id,
        'status' => 'completed', 'started_at' => now(), 'completed_at' => now(), 'server_state' => ['competitive' => true],
    ]);
    CompetitiveEventParticipant::firstOrCreate(['competitive_event_id' => $event->id, 'user_id' => $user->id], ['status' => 'completed', 'registered_at' => now()]);

    return CompetitiveEventResult::create([
        'competitive_event_id' => $event->id, 'user_id' => $user->id, 'game_session_id' => $session->id, 'is_correct' => $score > 0,
        'score' => $score, 'duration_ms' => $durationMs, 'completed_at' => $completedAt ?? now(),
    ]);
}

function e17Board($response): array
{
    return $response->viewData('board')['rows']->map(fn ($r) => [$r['rank'], $r['user']->name, $r['result']->score])->all();
}

test('44/51: the event leaderboard is correct and ordered by score, higher first', function () {
    $event = e17Event();
    $users = collect(['Low' => 1100, 'High' => 1900, 'Mid' => 1500, 'Zero' => 0])->map(fn ($score, $name) => e17Result($event, e16User(['name' => $name]), $score, 30_000));

    $page = $this->get(route('competitions.show', $event))->assertOk();

    expect(e17Board($page))->toBe([[1, 'High', 1900], [2, 'Mid', 1500], [3, 'Low', 1100], [4, 'Zero', 0]]);
    $page->assertSee('🥇')->assertSee('🥈')->assertSee('🥉');
});

test('45: the leaderboard paginates (20 per page) and ranks continue across pages', function () {
    $event = e17Event();
    foreach (range(1, 25) as $i) {
        e17Result($event, e16User(['name' => sprintf('P%02d', $i)]), 2000 - $i, 10_000 + $i);
    }

    $p1 = $this->get(route('competitions.show', $event))->viewData('board');
    $p2 = $this->get(route('competitions.show', [$event, 'page' => 2]))->viewData('board');

    expect($p1['rows'])->toHaveCount(20)->and($p1['paginator']->total())->toBe(25)->and($p2['rows'])->toHaveCount(5)
        ->and($p2['rows']->first()['rank'])->toBe(21)->and($p2['rows']->last()['rank'])->toBe(25)->and($p2['rows']->last()['user']->name)->toBe('P25');
});

test('46: the current user sees their own rank even when outside the first page', function () {
    $event = e17Event();
    foreach (range(1, 22) as $i) {
        e17Result($event, e16User(), 2000 - $i, 10_000);
    }
    $me = e16User(['name' => 'Me Player']);
    e17Result($event, $me, 1000, 50_000); // آخر النتائج: الرتبة 23

    $page = $this->actingAs($me)->get(route('competitions.show', $event))->assertOk();
    $board = $page->viewData('board');

    expect($board['my_rank'])->toBe(23)->and($board['rows']->pluck('user.name'))->not->toContain('Me Player'); // خارج الصفحة الأولى
    $page->assertSee('رتبتك')->assertSee('23');
});

test('47-50: the friends scope shows me plus accepted friends only - never pending, blocked or removed', function () {
    $event = e17Event();
    [$me, $friend] = e17Friends();
    [$pending, $blocked, $removed, $stranger] = [e16User(['name' => 'Pending P']), e16User(['name' => 'Blocked P']), e16User(['name' => 'Removed P']), e16User(['name' => 'Stranger P'])];
    e16Svc()->sendRequest($me, $pending);
    e16Befriend($me, $blocked);
    app(BlockService::class)->block($me, $blocked);
    e16Befriend($me, $removed);
    e16Svc()->remove($removed, $me);

    foreach ([[$me, 1200], [$friend, 1800], [$pending, 1950], [$blocked, 1960], [$removed, 1970], [$stranger, 1990]] as [$u, $score]) {
        e17Result($event, $u, $score, 20_000);
    }

    $friends = $this->actingAs($me)->get(route('competitions.show', [$event, 'scope' => 'friends']))->assertOk();
    $global = $this->actingAs($me)->get(route('competitions.show', $event))->assertOk();

    expect(collect(e17Board($friends))->pluck(1)->all())->toBe([$friend->name, $me->name])
        ->and(collect(e17Board($friends))->pluck(0)->all())->toBe([1, 2])                      // الرتبة داخل نطاق الأصدقاء
        ->and($friends->viewData('board')['my_rank'])->toBe(2)
        ->and(collect(e17Board($global))->pluck(1)->all())->toContain('Blocked P', 'Pending P', 'Removed P', 'Stranger P') // العام لا يخفي (سياسة E16 كما هي)
        ->and($global->viewData('board')['my_rank'])->toBe(6);
});

test('52: ties are broken deterministically - score, then duration, then completion time, then id - and the order never flips', function () {
    $event = e17Event();
    $a = e17Result($event, e16User(['name' => 'A']), 1500, 30_000, now()->subMinutes(5));
    $b = e17Result($event, e16User(['name' => 'B']), 1500, 25_000, now());                     // أسرع: يتقدّم
    $c = e17Result($event, e16User(['name' => 'C']), 1500, 30_000, now()->subMinutes(9));      // نفس مدة A لكن أسبق إكمالًا
    $d = e17Result($event, e16User(['name' => 'D']), 1500, 30_000, now()->subMinutes(9));      // مثل C تمامًا: يفصل المعرّف

    $names = fn () => collect(e17Board($this->get(route('competitions.show', $event))))->pluck(1)->all();

    expect($names())->toBe(['B', 'C', 'D', 'A'])->and($names())->toBe(['B', 'C', 'D', 'A']);
});

test('53: the leaderboard exposes only public identity - no email, phone, wallet, flags or internal ids', function () {
    $event = e17Event();
    $secret = e16User(['name' => 'Visible Name', 'email' => 'hidden.person@leak.test', 'is_frozen' => false]);
    e17Result($event, $secret, 1700, 20_000);

    $html = $this->actingAs(e16User())->get(route('competitions.show', $event))->assertOk()->getContent();

    expect($html)->toContain('Visible Name')->and($html)->not->toContain('hidden.person@leak.test')->and($html)->not->toContain('leak.test')
        ->and(strtolower($html))->not->toContain('password')->and($html)->not->toContain('محفظة')->and($html)->not->toContain('is_frozen');
});

test('D11: live standings are labelled provisional; after finalization the label is final and the order is unchanged', function () {
    $event = e17Event();
    e17Result($event, e16User(['name' => 'First']), 1900, 10_000);
    e17Result($event, e16User(['name' => 'Second']), 1700, 20_000);

    $live = $this->get(route('competitions.show', $event))->assertOk()->assertSee('الترتيب المؤقت')->assertSee('غير نهائي');
    $before = e17Board($live);

    e17Forward(4 * 3600_000);
    app(CompetitiveEventFinalizer::class)->finalize($event->refresh());
    $final = $this->get(route('competitions.show', $event))->assertOk()->assertSee('النتائج النهائية')->assertDontSee('غير نهائي');

    expect(e17Board($final))->toBe($before)
        ->and(CompetitiveEventResult::orderBy('final_rank')->pluck('final_rank')->all())->toBe([1, 2])
        ->and($final->viewData('board')['is_final'])->toBeTrue();
});

test('a guest asking for the friends scope gets the global board; scope chips appear for signed-in users only', function () {
    $event = e17Event();
    e17Result($event, e16User(['name' => 'Someone']), 1500, 10_000);

    $guest = $this->get(route('competitions.show', [$event, 'scope' => 'friends']))->assertOk();
    expect($guest->viewData('scope'))->toBe('global')->and($guest->viewData('board')['rows'])->toHaveCount(1);
    $guest->assertDontSee('نطاق الترتيب');
    $this->actingAs(e16User())->get(route('competitions.show', $event))->assertSee('نطاق الترتيب');
});

test('the ranking and result tables read only server-computed columns - rank is never taken from the client', function () {
    $event = e17Event();
    $user = e16User();
    e17Result($event, $user, 1500, 10_000);

    // ?rank= و?score= في الرابط لا أثر لهما على الترتيب المعروض.
    $page = $this->actingAs($user)->get(route('competitions.show', [$event, 'rank' => 99, 'score' => 99999, 'winner' => 1]))->assertOk();

    expect($page->viewData('board')['my_rank'])->toBe(1)->and($page->viewData('board')['rows']->first()['rank'])->toBe(1);
});

test('the events list shows upcoming, live and ended sections derived from time; drafts are hidden', function () {
    $live = e17Event(['title' => 'منافسة مباشرة']);
    $upcoming = e17Event(['title' => 'منافسة قادمة', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $ended = e17Event(['title' => 'منافسة منتهية', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    $draft = e17Event(['title' => 'مسوّدة سرية'], CompetitiveEvent::STATUS_DRAFT);

    $page = $this->get(route('competitions.index'))->assertOk()->assertSee('منافسة مباشرة')->assertSee('منافسة قادمة')->assertSee('منافسة منتهية')->assertDontSee('مسوّدة سرية');

    expect($page->viewData('live')->pluck('id')->all())->toBe([$live->id])->and($page->viewData('upcoming')->pluck('id')->all())->toBe([$upcoming->id])
        ->and($page->viewData('ended')->pluck('id')->all())->toBe([$ended->id]);
});

test('the event page shows the right join button for every state', function () {
    $user = e16User();
    $event = e17Event(['max_participants' => 1]);
    $other = e16User();
    $get = fn () => $this->actingAs($user)->get(route('competitions.show', $event))->assertOk();

    $get()->assertSee('سجّل في المنافسة');
    e17Events()->register($other, $event);
    $get()->assertSee('اكتمل عدد المشاركين');

    $open = e17Event();
    $page = fn ($e) => $this->actingAs($user)->get(route('competitions.show', $e))->assertOk();
    e17Events()->register($user, $open);
    $page($open)->assertSee('ابدأ المحاولة');
    e17Events()->start($user, $open);
    $page($open)->assertSee('تابع محاولتك الجارية');
    e17Events()->submit($user, $open, ['answer' => E17_ANSWER]);
    $page($open)->assertSee('سُجّلت نتيجتك');

    auth()->logout(); // الزائر
    $this->get(route('competitions.show', e17Event()))->assertSee('سجّل الدخول للمشاركة');
    $ended = e17Event(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    $page($ended)->assertSee('انتهت المنافسة');
    $cancelled = e17Event([], CompetitiveEvent::STATUS_CANCELLED);
    $page($cancelled)->assertSee('أُلغيت هذه المنافسة');
});

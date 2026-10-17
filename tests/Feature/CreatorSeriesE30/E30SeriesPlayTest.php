<?php

use App\Models\CommunityPuzzleAttempt;
use App\Models\PuzzleAttempt;
use App\Models\SponsorshipRewardBonus;
use App\Services\CreatorSeries\CreatorSeriesProgressService;
use App\Services\CreatorSeries\CreatorSeriesService;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SponsorshipE30/_support.php';

beforeEach(fn () => e30s_seed());

/** يستخرج تقدّم الصفحة (النسبة) ورابط «أكمل» من HTML صفحة السلسلة. */
function e30s_page(\Illuminate\Testing\TestResponse $response): array
{
    $html = $response->getContent();
    preg_match('/aria-valuenow="(\d+)" aria-label="تقدّمك في السلسلة"/u', $html, $pct);
    preg_match('/data-series-continue href="([^"]+)"/u', $html, $continue);

    return ['html' => $html, 'percent' => isset($pct[1]) ? (int) $pct[1] : null, 'continue' => isset($continue[1]) ? html_entity_decode($continue[1]) : null];
}

test('AM12-112: the series reuses the Community puzzle engine - play goes through the Community routes only', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    $html = $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk()->getContent();

    // الصفحة بلا نموذج لعب خاص: العناصر روابط لصفحات أحجيات المجتمع (مع سياق السلسلة) لا غير
    expect($html)->not->toContain('name="answer"')->and($html)->not->toContain('/attempt')
        ->and($html)->toContain(route('community.puzzles.show', ['work' => $works[0]->public_id, 'series' => $series->public_id]));

    $before = PuzzleAttempt::query()->count();
    $this->actingAs($player)->post(route('community.puzzles.attempt', $works[0]->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();

    expect(CommunityPuzzleAttempt::query()->where('user_id', $player->id)->where('creator_puzzle_id', $works[0]->id)->where('is_correct', true)->count())->toBe(1)
        ->and(PuzzleAttempt::query()->count())->toBe($before);

    // سياق السلسلة على صفحة الأحجية: الموضع والتالي
    $page = $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[1]->public_id, 'series' => $series->public_id]))->assertOk()
        ->assertSee('سلسلة: '.$series->published_title, false)->assertSee('2 من 3')->getContent();
    expect($page)->toContain('rel="prev"')->and($page)->toContain('rel="next"');

    // معامل سلسلة مختلق/لا يخص الأحجية = لا شريط ولا خطأ
    $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[1]->public_id, 'series' => 'garbage']))->assertOk()->assertDontSee('data-series-context', false);
    $other = e30s_published(e28_creator());
    $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[1]->public_id, 'series' => $other->public_id]))->assertOk()->assertDontSee('data-series-context', false);
});

test('AM12-113: a solved item counts toward progress (and a wrong attempt does not)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(0);

    e30s_wrong($player, $works[0]);
    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(0);

    e30s_solve($player, $works[1]);
    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['percent'])->toBe(33)->and($page['html'])->toContain('تقدّمك: 1 من 3');
});

test('AM12-114: a puzzle solved BEFORE the series existed still counts', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $player = e16User();
    e30s_solve($player, $works[0]);
    e30s_solve($player, $works[2]);

    $series = e30s_published($creator, $works);   // تُنشر بعد الحل

    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['percent'])->toBe(66)->and($page['html'])->toContain('تقدّمك: 2 من 3');
});

test('AM12-115: Continue opens the first incomplete item in order, not the first unsolved by id', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 4);
    $order = [$works[3], $works[1], $works[0], $works[2]];
    $series = e30s_published($creator, $order);
    $player = e16User();

    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['continue'])->toContain($order[0]->public_id)->and($page['html'])->toContain('ابدأ السلسلة');

    e30s_solve($player, $order[0]);
    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['continue'])->toContain($order[1]->public_id)->and($page['html'])->toContain('أكمل السلسلة');

    // حلّ الثالث فقط (قفز) ثم الثاني: الأول غير المكتمل بالترتيب هو الرابع
    e30s_solve($player, $order[2]);
    e30s_solve($player, $order[1]);
    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['continue'])->toContain($order[3]->public_id);

    // الضيف: «ابدأ» بأول عنصر
    auth()->logout();
    $this->flushSession();
    expect(e30s_page($this->get(route('series.show', $series->public_id)))['continue'])->toContain($order[0]->public_id);
});

test('AM12-116: completion is calculated exactly (progress service and page)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    foreach ($works as $work) {
        e30s_solve($player, $work);
    }

    $items = collect($works)->map(fn ($w) => (object) ['work_id' => $w->id, 'public_id' => $w->public_id]);
    $progress = app(CreatorSeriesProgressService::class)->forUser($player, $items);
    expect($progress)->toMatchArray(['done' => 3, 'total' => 3, 'percent' => 100, 'completed' => true, 'next_public_id' => null]);

    $page = e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)));
    expect($page['percent'])->toBe(100)->and($page['html'])->toContain('أكملت السلسلة')->and($page['continue'])->toBeNull();

    // حالات حدّية: بلا مستخدم، وبلا عناصر
    expect(app(CreatorSeriesProgressService::class)->forUser(null, $items))->toMatchArray(['done' => 0, 'percent' => 0, 'completed' => false])
        ->and(app(CreatorSeriesProgressService::class)->forUser($player, collect()))->toMatchArray(['done' => 0, 'total' => 0, 'percent' => 0, 'completed' => false]);
});

test('AM12-116b: a newer revision of an item (new canonical puzzle) is not "solved" until solved again; the creator never plays their own', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 2);
    $series = e30s_published($creator, $works);
    $player = e16User();
    e30s_solve($player, $works[0]);
    e30s_solve($player, $works[1]);
    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(100);

    // المبدع يعدّل أحجية ويُعتمد إصدارها الجديد => نسخة قانونية جديدة (يلزم حلّها من جديد، كما بصفحة الأحجية)
    $revision = app(\App\Services\Creators\CreatorPuzzleService::class)->revise($creator, $works[0]->public_id);
    app(\App\Services\Creators\CreatorPuzzleService::class)->updateDraft($creator, $works[0]->public_id, e28_data('text', ['title' => 'نسخة جديدة من اللغز']));
    app(\App\Services\Creators\CreatorPuzzleService::class)->submit($creator, $works[0]->public_id);
    app(\App\Services\Creators\CreatorReviewService::class)->approve($revision->fresh(), e28_reviewer());

    expect(e30s_page($this->actingAs($player)->get(route('series.show', $series->public_id)))['percent'])->toBe(50);

    // المالك: بلا تقدّم ولا «ابدأ»
    $html = $this->actingAs($creator)->get(route('series.show', $series->public_id))->assertOk()->getContent();
    expect($html)->toContain('هذه سلسلتك')->and($html)->not->toContain('data-series-continue');
});

test('AM12-117..122: playing a whole series changes nothing in the economy - zero gems, XP, quests, streak, competitive and leaderboard effect', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();
    // الطبقة العامة تنشئ صف تقدّم اللاعب كسولًا عند أول عرض لأي صفحة (سلوك قائم لا علاقة له بالسلاسل)؛ نسخّنه قبل اللقطة كي تقيس اللقطة اللعب وحده.
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();
    $before = e28_world($player);
    $leaderboardBefore = \App\Models\PlayerProgression::query()->sum('total_xp');

    foreach ($works as $work) {
        $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();
    }
    e30s_wrong($player, e30s_works($creator, 1)[0]);
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk();

    $after = e28_world($player);
    // لقطة الاقتصاد كلها (محافظ، جواهر، XP، مهام، سلسلة أيام، تأهيل، منافسات، جلسات، احتيال) كما كانت
    expect($after['wallet'])->toBe($before['wallet'])->and($after['xp'])->toBe($before['xp'])->and($after['streak'])->toBe($before['streak'])->and($after['quest'])->toBe($before['quest'])
        ->and($after['counts'])->toBe($before['counts'])
        ->and(\App\Models\PlayerProgression::query()->sum('total_xp'))->toBe($leaderboardBefore);
});

test('AM6-60: creator series get ZERO sponsor reward boost even with an active funded boost program', function () {
    e30_seed();
    $program = e30_boost(e30_program(['title' => 'برنامج تعزيز المنصة']));
    e30_confirmed(e30_company(), $program, '500');

    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();
    $before = e28_world($player);
    $bonusRows = SponsorshipRewardBonus::query()->count();

    foreach ($works as $work) {
        $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();
    }

    expect(SponsorshipRewardBonus::query()->count())->toBe($bonusRows)->and(e28_world($player))->toBe($before);
    // الصفحة العامة للسلسلة بلا أي شريط رعاية ولا وعد بمكافأة
    $html = $this->actingAs($player)->get(route('series.show', $series->public_id))->assertOk()->getContent();
    expect($html)->not->toContain('data-sponsor')->and($html)->not->toContain('data-main-sponsor');
});

test('AM12: a hidden item makes Continue disappear with the series (no leak of the item list)', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $player = e16User();

    e30s_hide_work($works[1]);
    $this->actingAs($player)->get(route('series.show', $series->public_id))->assertNotFound();
    $this->actingAs($player)->get(route('community.puzzles.show', ['work' => $works[0]->public_id, 'series' => $series->public_id]))->assertOk()->assertDontSee('data-series-context', false);
});

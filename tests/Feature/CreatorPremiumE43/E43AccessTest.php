<?php

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionThread;
use App\Services\CreatorPremium\CreatorPremiumAccess;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E43 (2/9) - سياسة الوصول الوحيدة عند نقاط المحتوى الفعلية: الصفحة المقفولة لا تسرّب شيئًا، المحاولة والتلميح محميان في الخدمة نفسها،
| التقادم لكل أحجية، المعاينة (مبدع/مشرف)، النقاش، صفحة السلسلة، وأداء العمل الحر (لا استعلامات زائدة).
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e43_seed());

const E43_PROMPT_FRAGMENT = 'يكتب ولا يقرأ';
const E43_HINT_FRAGMENT = 'تجده على المكتب';

function e43_get_work(CreatorPuzzle $work): CreatorPuzzle
{
    return CreatorPuzzle::query()->visible()->withPremiumLock()->where('public_id', $work->public_id)->firstOrFail();
}

function e43_attempts(): int
{
    return CommunityPuzzleAttempt::query()->count();
}

test('E43-AC-01: a free work is allowed for everyone with no extra query (the lock column rides on the work query)', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text');
    $loaded = e43_get_work($work);
    $viewer = e16User();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $guest = e43_access_service()->decide(null, $loaded);
    $user = e43_access_service()->decide($viewer, $loaded, CreatorPremiumAccess::ATTEMPT);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($guest->allowed)->toBeTrue()->and($guest->isFree())->toBeTrue()->and($user->allowed)->toBeTrue()->and($queries)->toBe(0);
});

test('E43-AC-02: a guest sees only public metadata of a premium work - never the prompt, hint, answer form or discussion', function () {
    $world = e43_world();
    $work = $world['works'][0];

    $response = $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    $html = $response->getContent();

    expect($html)->toContain($work->fresh()->published_title)->toContain('data-premium-locked')->toContain('data-premium-series-card')
        ->and($html)->not->toContain(E43_PROMPT_FRAGMENT)->not->toContain(E43_HINT_FRAGMENT)
        ->and($html)->not->toContain('name="answer"')->not->toContain('data-discussion')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    e28_assert_no_secrets($html);
});

test('E43-AC-03: a verified non-owner gets the locked page, cannot attempt (no row written) and cannot read the hint', function () {
    $world = e43_world();
    $work = $world['works'][0];
    $user = e16User();

    $page = $this->actingAs($user)->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($page)->not->toContain(E43_PROMPT_FRAGMENT)->and($page)->toContain('data-premium-get-access');

    $before = e43_attempts();
    $this->actingAs($user)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHas('error');
    $this->actingAs($user)->post(route('community.puzzles.hint', $work->public_id))->assertSessionHas('error')->assertSessionMissing('hint');

    expect(e43_attempts())->toBe($before)
        ->and(e43_reason(fn () => app(\App\Services\Creators\CommunityPlayService::class)->attempt($user, $work->fresh(), 'القلم')))->toBe('premium_locked')
        ->and(e43_reason(fn () => app(\App\Services\Creators\CommunityPlayService::class)->hint($work->fresh(), $user)))->toBe('premium_locked')
        ->and(e43_reason(fn () => app(\App\Services\Creators\CommunityPlayService::class)->hint($work->fresh(), null)))->toBe('login_required');
});

test('E43-AC-04: an owner reads, plays and gets hints on every work of the series', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $work = $world['works'][1];

    $page = $this->actingAs($bought['user'])->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($page)->toContain(E43_PROMPT_FRAGMENT)->not->toContain('data-premium-locked');

    $this->actingAs($bought['user'])->post(route('community.puzzles.hint', $work->public_id))->assertSessionHas('hint');
    $this->actingAs($bought['user'])->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHas('success');

    expect(CommunityPuzzleAttempt::query()->where('user_id', $bought['user']->id)->where('creator_puzzle_id', $work->id)->where('is_correct', true)->count())->toBe(1);
});

test('E43-AC-05: the creator previews (view/hint/discuss) but cannot attempt their own premium work', function () {
    $world = e43_world();
    $work = $world['works'][0];
    $access = e43_access_service();
    $loaded = e43_get_work($work);

    expect($access->decide($world['user'], $loaded, CreatorPremiumAccess::VIEW)->basis)->toBe('creator')
        ->and($access->decide($world['user'], $loaded, CreatorPremiumAccess::HINT)->allowed)->toBeTrue()
        ->and($access->decide($world['user'], $loaded, CreatorPremiumAccess::DISCUSS)->allowed)->toBeTrue()
        ->and($access->decide($world['user'], $loaded, CreatorPremiumAccess::ATTEMPT)->allowed)->toBeFalse();

    $page = $this->actingAs($world['user'])->get(route('community.puzzles.show', $work->public_id))->assertOk()->getContent();
    expect($page)->toContain(E43_PROMPT_FRAGMENT);
});

test('E43-AC-06: authorized staff preview premium works without creating attempts or access', function () {
    $world = e43_world();
    $work = $world['works'][0];
    $staff = e28_reviewer();
    $loaded = e43_get_work($work);
    $access = e43_access_service();

    expect($access->decide($staff, $loaded, CreatorPremiumAccess::VIEW)->basis)->toBe('staff')
        ->and($access->decide($staff, $loaded, CreatorPremiumAccess::ATTEMPT)->allowed)->toBeFalse()
        ->and($access->decide($staff, $loaded, CreatorPremiumAccess::ATTEMPT)->basis)->toBe('preview_only');

    $this->actingAs($staff)->get(route('community.puzzles.show', $work->public_id))->assertOk()->assertSee(E43_PROMPT_FRAGMENT);
    $before = e43_attempts();
    $this->actingAs($staff)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHas('error');

    expect(e43_attempts())->toBe($before)->and(DB::table('creator_series_accesses')->count())->toBe(0)->and(DB::table('digital_purchases')->count())->toBe(0);
});

test('E43-AC-07: a plain user without creator-review permission gets no staff preview', function () {
    $world = e43_world();
    $plain = e16User();

    expect(e43_access_service()->decide($plain, e43_get_work($world['works'][0]))->allowed)->toBeFalse();
});

test('E43-AC-08: pausing sales keeps the content locked for non-owners and keeps owners inside', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $outsider = e16User();
    e43_offers()->pause($world['user'], $world['offer']->public_id);
    $loaded = e43_get_work($world['works'][0]);

    expect(e43_access_service()->decide($outsider, $loaded)->allowed)->toBeFalse()->and(e43_access_service()->decide($bought['user'], $loaded)->allowed)->toBeTrue();
});

test('E43-AC-09: retiring the offer makes the series free again for everyone; owners keep their access rows as history', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    e43_offers()->retire($world['user'], $world['offer']->public_id);
    $loaded = e43_get_work($world['works'][0]);

    expect(e43_access_service()->decide(e16User(), $loaded)->isFree())->toBeTrue()
        ->and(e43_access_row($bought['user'], $world['series'])?->isActive())->toBeTrue()
        ->and($this->get(route('community.puzzles.show', $world['works'][0]->public_id))->assertOk()->getContent())->toContain(E43_PROMPT_FRAGMENT);
});

test('E43-AC-10: grandfathering is per work - a player who attempted a work before the first activation keeps view/attempt/hint on that work only', function () {
    $world = e43_world(['offer' => false]);
    $player = e16User();
    e30s_wrong($player, $world['works'][0]);
    e43_tick(2);
    e43_offer($world['user'], $world['series'], '5.00');

    $access = e43_access_service();
    $first = e43_get_work($world['works'][0]);
    $second = e43_get_work($world['works'][1]);

    expect($access->decide($player, $first, CreatorPremiumAccess::VIEW)->basis)->toBe('grandfathered')
        ->and($access->decide($player, $first, CreatorPremiumAccess::ATTEMPT)->allowed)->toBeTrue()
        ->and($access->decide($player, $first, CreatorPremiumAccess::HINT)->allowed)->toBeTrue()
        ->and($access->decide($player, $first, CreatorPremiumAccess::DISCUSS)->allowed)->toBeFalse()
        ->and($access->decide($player, $second, CreatorPremiumAccess::VIEW)->allowed)->toBeFalse();

    expect(e30s_solve($player, $world['works'][0])['correct'])->toBeTrue();
});

test('E43-AC-11: an attempt made at or after the activation instant does not grandfather (strictly before only)', function () {
    $world = e43_world(['offer' => false]);
    $player = e16User();
    e43_tick(1);
    e43_offer($world['user'], $world['series'], '5.00');
    $loaded = e43_get_work($world['works'][0]);

    DB::table('community_puzzle_attempts')->insert([
        'user_id' => $player->id, 'creator_puzzle_id' => $world['works'][0]->id, 'puzzle_id' => $world['works'][0]->published_puzzle_id,
        'attempt_number' => 1, 'is_correct' => 0, 'created_at' => now(),
    ]);

    expect(e43_access_service()->decide($player, $loaded)->allowed)->toBeFalse();
});

test('E43-AC-12: a work that is also in a free series stays locked while any published series of it is premium; ownership of the premium one unlocks it', function () {
    $world = e43_world(['offer' => false]);
    $extra = e28_published($world['user'], 'text');
    e30s_published($world['user'], [$world['works'][0], $extra]); // سلسلة حرة تضم العمل نفسه
    e43_offer($world['user'], $world['series'], '5.00');
    $user = e16User();

    expect(e43_access_service()->decide($user, e43_get_work($world['works'][0]))->allowed)->toBeFalse()
        ->and(e43_access_service()->isLocked(e43_get_work($extra)))->toBeFalse();

    e43_buy($world, $user);

    expect(e43_access_service()->decide($user, e43_get_work($world['works'][0]))->allowed)->toBeTrue()
        ->and(e43_access_service()->decide($user, e43_get_work($extra))->basis)->toBe('free');
});

test('E43-AC-13: discussions of premium content are only for owners, the creator and staff (read, write, reply, permalink)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $outsider = e16User();
    $staff = e28_reviewer();
    $work = $world['works'][0];

    $resolve = fn ($viewer, $type, $id) => app(\App\Services\Discussions\DiscussionSubjectRegistry::class)->resolve($type, $id, $viewer);

    foreach ([[$bought['user'], true], [$world['user'], true], [$staff, true], [$outsider, false], [null, false]] as [$viewer, $allowed]) {
        expect($resolve($viewer, DiscussionThread::TYPE_COMMUNITY_PUZZLE, $work->public_id) !== null)->toBe($allowed)
            ->and($resolve($viewer, DiscussionThread::TYPE_CREATOR_SERIES, $world['series']->public_id) !== null)->toBe($allowed);
    }

    $this->actingAs($outsider)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق من غير مالك'])->assertNotFound();
    $this->actingAs($bought['user'])->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق من مالك الوصول'])->assertRedirect();

    $comment = DB::table('discussion_comments')->first();
    expect($comment)->not->toBeNull();
    $this->actingAs($outsider)->get(route('discussions.permalink', $comment->public_id))->assertNotFound();
});

test('E43-AC-14: discussions of a free series and free works are unaffected (everyone can still read and write)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator);
    $user = e16User();
    $registry = app(\App\Services\Discussions\DiscussionSubjectRegistry::class);

    expect($registry->resolve(DiscussionThread::TYPE_CREATOR_SERIES, $series->public_id, $user))->not->toBeNull()
        ->and($registry->resolve(DiscussionThread::TYPE_CREATOR_SERIES, $series->public_id, null))->not->toBeNull();
});

test('E43-AC-15: the series page shows public metadata + price and the right call to action per viewer state', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $url = route('series.show', $world['series']->public_id);

    $guest = $this->get($url)->assertOk()->getContent();
    expect($guest)->toContain('data-premium-block')->toContain('data-premium-state="guest"')->toContain('$5.00')->not->toContain(E43_PROMPT_FRAGMENT);

    $buyer = $this->actingAs(e16User())->get($url)->assertOk()->getContent();
    expect($buyer)->toContain('data-premium-state="buyable"')->toContain('data-premium-buy')->toContain('name="request_key"')->not->toContain('data-series-continue');

    $owner = $this->actingAs($bought['user'])->get($url)->assertOk()->getContent();
    expect($owner)->toContain('data-premium-state="owned"')->toContain('data-series-continue')->not->toContain('data-premium-buy');

    $creator = $this->actingAs($world['user'])->get($url)->assertOk()->getContent();
    expect($creator)->toContain('data-premium-state="creator"')->not->toContain('data-premium-buy');
});

test('E43-AC-16: a paused offer shows "sales paused" and no buy button; a retired one shows no premium block at all', function () {
    $world = e43_world();
    e43_offers()->pause($world['user'], $world['offer']->public_id);
    $url = route('series.show', $world['series']->public_id);

    $paused = $this->actingAs(e16User())->get($url)->assertOk()->getContent();
    expect($paused)->toContain('data-premium-state="paused"')->not->toContain('data-premium-buy');

    e43_offers()->retire($world['user'], $world['offer']->public_id);
    expect($this->actingAs(e16User())->get($url)->assertOk()->getContent())->not->toContain('data-premium-block');
});

test('E43-AC-17: the community index shows a premium badge on locked works and none on free ones', function () {
    $world = e43_world();
    $free = e28_published(e28_creator(), 'text', ['title' => 'لغز حر بلا قفل']);

    $html = $this->get(route('community.puzzles.index'))->assertOk()->getContent();

    expect($html)->toContain('data-premium-badge')->toContain('لغز حر بلا قفل')
        ->and(preg_match_all('/\sdata-premium-badge[=\s>]/', $html))->toBe(count($world['works']));
});

test('E43-AC-18: a ?series= context parameter never bypasses the lock', function () {
    $world = e43_world();
    $page = $this->actingAs(e16User())->get(route('community.puzzles.show', ['work' => $world['works'][0]->public_id, 'series' => $world['series']->public_id]))->assertOk()->getContent();

    expect($page)->not->toContain(E43_PROMPT_FRAGMENT)->toContain('data-premium-locked');
});

test('E43-AC-19: a frozen owner keeps the entitlement record (the existing restricted-account rules govern interaction, not this layer)', function () {
    $world = e43_world();
    $bought = e43_buy($world);
    $bought['user']->forceFill(['is_frozen' => true])->save();

    expect(e43_access_row($bought['user'], $world['series'])?->isActive())->toBeTrue()
        ->and(e43_access_service()->ownsSeries($bought['user']->fresh(), $world['series']))->toBeTrue();
});

test('E43-AC-20: the same decision backs every endpoint (show, attempt, hint, discuss) - the service is the single source', function () {
    $world = e43_world();
    $loaded = e43_get_work($world['works'][0]);
    $user = e16User();

    foreach ([CreatorPremiumAccess::VIEW, CreatorPremiumAccess::ATTEMPT, CreatorPremiumAccess::HINT, CreatorPremiumAccess::DISCUSS] as $ability) {
        expect(e43_access_service()->decide($user, $loaded, $ability)->allowed)->toBeFalse($ability);
    }

    $controller = file_get_contents(app_path('Http/Controllers/Creators/CommunityPuzzleController.php'));
    $play = file_get_contents(app_path('Services/Creators/CommunityPlayService.php'));
    $registry = file_get_contents(app_path('Services/Discussions/DiscussionSubjectRegistry.php'));

    expect($controller)->toContain('CreatorPremiumAccess::VIEW')->and($play)->toContain('CreatorPremiumAccess::ATTEMPT')->toContain('CreatorPremiumAccess::HINT')
        ->and($registry)->toContain('CreatorPremiumAccess::DISCUSS');
});

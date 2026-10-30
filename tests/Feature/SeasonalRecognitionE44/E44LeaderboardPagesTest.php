<?php

use App\Events\SeasonalRecognition\SeasonWriterLeaderboardUpdated;
use App\Models\SeasonWriterEntry;
use App\Services\SeasonalRecognition\SeasonWriterLeaderboardService;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/_support.php';

/*
| E44 / ترتيب الكتّاب الحيّ والصفحات العامة والبث: ترتيب حتمي (نجوم ← ناخبون ← أقدم آخر نجمة ← معرّف)، حمولة علنية بلا هوية ناخب،
| كاش بنمط الإصدار يُبطَل عند كل تغيير، نقاط نهاية JSON للتصويت (أخطاء، حدّ المعدّل، تجاهل الحقول الدخيلة)، وبثّ تجميعي معزول الفشل.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

function e44_board_ids(\App\Models\Season $season): array
{
    return array_map(fn (array $r) => $r['public_id'], app(SeasonWriterLeaderboardService::class)->publicBoard($season)['rows']);
}

// ───────────────────────────── الترتيب ─────────────────────────────

test('E44-LB-01: ranking order is stars, then distinct voters, then earliest last star, then id', function () {
    $league = e44_league(4);
    [$a, $b, $c, $d] = $league['writers'];

    e44_voting()->allocate(e44_staff_voter(), $a['entry']->fresh(), 5);          // A: 5 نجوم، ناخب واحد
    e44_voting()->allocate(e44_staff_voter(), $b['entry']->fresh(), 3);          // B: 5 نجوم، ناخبان
    e44_voting()->allocate(e44_staff_voter(), $b['entry']->fresh(), 2);
    e44_voting()->allocate(e44_staff_voter(), $c['entry']->fresh(), 4);          // C و D: 4 نجوم، ناخب واحد - C أقدم
    e44_tick(5);
    e44_voting()->allocate(e44_staff_voter(), $d['entry']->fresh(), 4);

    expect(e44_board_ids($league['season']))->toBe([$b['entry']->public_id, $a['entry']->public_id, $c['entry']->public_id, $d['entry']->public_id]);

    $ranks = collect(app(SeasonWriterLeaderboardService::class)->publicBoard($league['season'])['rows'])->pluck('rank')->all();
    expect($ranks)->toBe([1, 2, 3, 4]);
});

test('E44-LB-02: a full tie (stars, voters and the same instant) falls back to the lowest entry id', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];

    // الزمن مثبَّت؛ الصوتان في اللحظة نفسها تمامًا. نصوّت للثاني أولًا كي لا يكون الترتيب ترتيب إدخال.
    e44_voting()->allocate(e44_staff_voter(), $b['entry']->fresh(), 4);
    e44_voting()->allocate(e44_staff_voter(), $a['entry']->fresh(), 4);

    expect($a['entry']->id)->toBeLessThan($b['entry']->id)
        ->and(e44_board_ids($league['season']))->toBe([$a['entry']->public_id, $b['entry']->public_id]);
});

test('E44-LB-03: zero-star writers are listed after starred ones, in id order', function () {
    $league = e44_league(3);
    [$a, $b, $c] = $league['writers'];
    e44_voting()->allocate(e44_staff_voter(), $c['entry']->fresh(), 1);

    expect(e44_board_ids($league['season']))->toBe([$c['entry']->public_id, $a['entry']->public_id, $b['entry']->public_id]);
});

test('E44-LB-04: withdrawn, frozen and non-approved writers are absent from the public board but frozen ones remain visible to staff', function () {
    $league = e44_league(3);
    [$a, $b, $c] = $league['writers'];
    $service = app(SeasonWriterLeaderboardService::class);

    e44_roster()->removeWriter($a['entry']->fresh(), $league['admin']); // مسحوب بعد فتح التصويت
    $b['user']->forceFill(['is_frozen' => true])->save();
    \App\Services\SeasonalRecognition\SeasonalRecognitionCache::bump($league['season']->id, 'writers');

    $public = $service->publicBoard($league['season']);
    $admin = $service->adminBoard($league['season']);

    expect(array_column($public['rows'], 'public_id'))->toBe([$c['entry']->public_id])
        ->and(array_column($admin['rows'], 'public_id'))->toContain($b['entry']->public_id, $c['entry']->public_id)
        ->and(array_column($admin['rows'], 'public_id'))->not->toContain($a['entry']->public_id)
        ->and(collect($admin['rows'])->firstWhere('public_id', $b['entry']->public_id)['visible'])->toBeFalse();
});

test('E44-LB-05: the public payload carries only public fields — no user ids, emails, voters or per-voter allocations', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    [$voter] = e44_vote($writer, 4);

    $board = app(SeasonWriterLeaderboardService::class)->publicBoard($league['season']);
    $row = $board['rows'][0];

    expect(array_keys($row))->toBe(['rank', 'public_id', 'name', 'slug', 'stars', 'voters', 'outcome'])
        ->and(array_keys($board))->toBe(['state', 'finalized', 'generated_at', 'writers_count', 'rows']);

    $json = json_encode($board, JSON_UNESCAPED_UNICODE);

    expect(str_contains($json, (string) $voter->email))->toBeFalse()
        ->and(str_contains($json, (string) $writer['user']->email))->toBeFalse()
        ->and(str_contains($json, '"user_id"'))->toBeFalse()
        ->and(str_contains($json, 'voter'.'_user'))->toBeFalse();
});

test('E44-LB-06: the board is cached by version and every committed vote invalidates it', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $service = app(SeasonWriterLeaderboardService::class);

    $first = $service->publicBoard($league['season']);
    expect($first['rows'][0]['stars'])->toBe(0);

    $queries = e44_queries(fn () => $service->publicBoard($league['season']));
    expect($queries)->toBe(0);

    e44_vote($writer, 6);

    expect($service->publicBoard($league['season'])['rows'][0]['stars'])->toBe(6);

    e44_roster()->removeWriter($writer['entry']->fresh(), $league['admin']);
    expect($service->publicBoard($league['season'])['rows'])->toBe([]);
});

test('E44-LB-07: totals for admin analytics are aggregate-only and match the ledger', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    e44_vote($a, 3);
    e44_vote($a, 2);
    e44_vote($b, 5);

    $totals = app(SeasonWriterLeaderboardService::class)->totals($league['season']);

    expect($totals)->toBe(['writers' => 2, 'active' => 2, 'stars' => 10, 'voters' => 3, 'allocations' => 3]);
});

// ───────────────────────────── الصفحات العامة ─────────────────────────────

test('E44-PG-01: the public writers page renders for guests with names and totals but no voter identity', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    [$voter] = e44_vote($a, 7);

    $response = $this->get(route('seasonal.writers.show', $league['season']))->assertOk();

    $response->assertSee($a['user']->name)->assertSee($b['user']->name)->assertSee('★ 7', false)
        ->assertDontSee((string) $voter->email);
});

test('E44-PG-02: the page is a 404 for an unpublished season, and for a season whose league was never set up', function () {
    $draft = e42_live(['published' => false]);
    e44_settings()->ensure($draft);
    e44_settings()->saveWriterSettings($draft, ['enabled' => true], e44_admin());

    $plain = e44_season(); // إعدادات موجودة لكن الدوري غير مفعَّل ولا كتّاب

    $this->get(route('seasonal.writers.show', $draft))->assertNotFound();
    $this->get(route('seasonal.writers.show', $plain))->assertNotFound();
    $this->get(route('seasonal.writers.standings', $plain))->assertNotFound();
});

test('E44-PG-03: turning the global flag off keeps the page readable when a roster exists (history is never hidden)', function () {
    $league = e44_league(1);
    config(['seasonal_recognition.writers.enabled' => false]);

    $this->get(route('seasonal.writers.show', $league['season']))->assertOk()->assertSee($league['writers'][0]['user']->name);
});

test('E44-PG-04: the standings fragment is public HTML with a short public cache header and the same rows as the page', function () {
    $league = e44_league(2);
    e44_vote($league['writers'][1], 5);

    $response = $this->get(route('seasonal.writers.standings', $league['season']))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->getContent())->toContain('data-standings')
        ->and($response->getContent())->toContain('data-rank="1"');

    $html = $response->getContent();
    expect(strpos($html, e($league['writers'][1]['user']->name)))->toBeLessThan(strpos($html, e($league['writers'][0]['user']->name)));
});

test('E44-PG-05: a logged-in player sees who they may vote for; others show the play-first hint; the writer sees themself marked', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $player = e44_player_of($a);

    $this->actingAs($player)->get(route('seasonal.writers.show', $league['season']))
        ->assertOk()->assertSee('يمكنك التصويت')->assertSee('العب إحدى سلاسله للتصويت')->assertSee('data-writer="'.$a['entry']->public_id.'"', false);

    $this->actingAs($a['user'])->get(route('seasonal.writers.show', $league['season']))
        ->assertOk()->assertSee('أنت');
});

test('E44-PG-06: the page shows the final standing with outcome badges after finalization, and the cached board is replaced', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    e44_vote($a, 5);
    e44_vote($b, 2);
    $this->get(route('seasonal.writers.show', $league['season']))->assertOk(); // يملأ الكاش

    e44_after_close();
    e44_finalizer()->finalize($league['season'], $league['admin']);

    $this->get(route('seasonal.writers.show', $league['season']))
        ->assertOk()->assertSee('الترتيب النهائي')->assertSee('المركز الأول')->assertSee('المركز الثاني');
});

// ───────────────────────────── نقطة التصويت ─────────────────────────────

function e44_stars_url(array $league, array $writer): string
{
    return route('seasonal.writers.stars', ['season' => $league['season'], 'entry' => $writer['entry']->public_id]);
}

test('E44-PG-07: a verified player posts stars as JSON and receives only budget numbers back', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $player = e44_player_of($writer);

    $this->actingAs($player)->postJson(e44_stars_url($league, $writer), ['stars' => 4])
        ->assertOk()->assertExactJson(['ok' => true, 'stars' => 4, 'spent' => 4, 'remaining' => 6, 'total' => 10]);

    expect(e44_stars($writer))->toBe(4);
});

test('E44-PG-08: domain refusals come back as 422 JSON with a stable reason; validation errors name the field', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $player = e44_player_of($a);

    $this->actingAs($player)->postJson(e44_stars_url($league, $b), ['stars' => 2])
        ->assertStatus(422)->assertJson(['ok' => false, 'reason' => 'no_play_evidence']);

    $this->actingAs($a['user'])->postJson(e44_stars_url($league, $a), ['stars' => 2])
        ->assertStatus(422)->assertJson(['ok' => false, 'reason' => 'self_vote']);

    $this->actingAs($player)->postJson(e44_stars_url($league, $a), ['stars' => 11])->assertStatus(422)->assertJsonValidationErrors('stars');
    $this->actingAs($player)->postJson(e44_stars_url($league, $a), ['stars' => 'many'])->assertStatus(422)->assertJsonValidationErrors('stars');
    $this->actingAs($player)->postJson(e44_stars_url($league, $a), [])->assertStatus(422)->assertJsonValidationErrors('stars');

    expect(e44_stars($a))->toBe(0)->and(e44_stars($b))->toBe(0);
});

test('E44-PG-09: guests are rejected and unverified accounts cannot post', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];

    $this->postJson(e44_stars_url($league, $writer), ['stars' => 2])->assertUnauthorized();

    $unverified = e16User(['email_verified_at' => null]);
    $this->actingAs($unverified)->postJson(e44_stars_url($league, $writer), ['stars' => 2])->assertForbidden();

    expect(e44_stars($writer))->toBe(0);
});

test('E44-PG-10: the writer comes from the path scoped to the season; foreign, malformed or unknown ids are 404', function () {
    $league = e44_league(1);
    $other = e44_league(1);
    $writer = $league['writers'][0];
    $foreign = $other['writers'][0];
    $player = e44_player_of($writer);

    $this->actingAs($player)->postJson(route('seasonal.writers.stars', ['season' => $league['season'], 'entry' => $foreign['entry']->public_id]), ['stars' => 2])->assertNotFound();
    $this->actingAs($player)->postJson('/seasons/'.$league['season']->slug.'/writers/not-a-ulid/stars', ['stars' => 2])->assertNotFound();
    $this->actingAs($player)->postJson(route('seasonal.writers.stars', ['season' => $league['season'], 'entry' => (string) \Illuminate\Support\Str::ulid()]), ['stars' => 2])->assertNotFound();

    expect(e44_stars($foreign))->toBe(0);
});

test('E44-PG-11: smuggled body fields (voter id, budget, totals, price) are ignored — only stars is read', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    $player = e44_player_of($a);
    $victim = e44_staff_voter();

    $this->actingAs($player)->postJson(e44_stars_url($league, $a), [
        'stars' => 3, 'voter_id' => $victim->id, 'voter_user_id' => $victim->id, 'user_id' => $victim->id, 'total' => 999, 'budget' => 999, 'spent' => 0, 'writer_entry_id' => $b['entry']->id, 'season_id' => 9999,
    ])->assertOk()->assertJson(['stars' => 3, 'total' => 10]);

    expect((int) DB::table('season_writer_star_allocations')->where('voter_user_id', $player->id)->where('writer_entry_id', $a['entry']->id)->value('stars'))->toBe(3)
        ->and(DB::table('season_writer_star_allocations')->where('voter_user_id', $victim->id)->count())->toBe(0)
        ->and(e44_stars($b))->toBe(0);
});

test('E44-PG-12: star mutations are rate limited per user (429 once the per-minute limit is exceeded)', function () {
    config(['seasonal_recognition.writers.mutations_per_minute' => 3]);
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $player = e44_player_of($writer);

    $codes = [];

    for ($i = 1; $i <= 5; $i++) {
        $codes[] = $this->actingAs($player)->postJson(e44_stars_url($league, $writer), ['stars' => $i % 10 + 1])->getStatusCode();
    }

    expect($codes)->toBe([200, 200, 200, 429, 429]);
});

test('E44-PG-13: a classic form post (no JSON) redirects back with a flash message', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $player = e44_player_of($writer);

    $this->actingAs($player)->from(route('seasonal.writers.show', $league['season']))->post(e44_stars_url($league, $writer), ['stars' => 2])
        ->assertRedirect(route('seasonal.writers.show', $league['season']))->assertSessionHas('success');

    $this->actingAs(e16User())->from(route('seasonal.writers.show', $league['season']))->post(e44_stars_url($league, $writer), ['stars' => 2])
        ->assertRedirect(route('seasonal.writers.show', $league['season']))->assertSessionHas('error');
});

test('E44-PG-14: the Season Hub strip renders nothing without a league or supporters, shows the live entry points when enabled, and the public podium once finalized', function () {
    // لا دوري ولا تقدير ⇒ صفحة الموسم كما كانت تمامًا (صفر إخراج، بلا خطأ عرض).
    $plain = e44_season();
    $this->get(route('seasons.show', $plain))->assertOk()->assertDontSee('data-seasonal-recognition', false)->assertDontSee('كتّاب الموسم');

    // دوري حيّ ⇒ مدخل الكتّاب فقط (بلا مدخل داعمين لأن التقدير غير مفعَّل).
    $league = e44_league(3);
    e44_cast($league['writers'], [5, 3, 1]);

    $this->get(route('seasons.show', $league['season']))->assertOk()
        ->assertSee('data-seasonal-recognition', false)->assertSee('كتّاب الموسم')->assertSee('ترتيب حيّ')
        ->assertSee(route('seasonal.writers.show', $league['season']), false)
        ->assertDontSee('داعمو الموسم')->assertDontSee('الترتيب النهائي');

    // بعد الإنهاء ⇒ منصة التتويج بالأسماء العامة للثلاثة الأوائل فقط، ولا هوية ناخب.
    e44_after_close();
    e44_finalizer()->finalize($league['season'], $league['admin']);

    $page = $this->get(route('seasons.show', $league['season']))->assertOk()->assertSee('الترتيب النهائي')
        ->assertSee($league['writers'][0]['user']->name)->assertSee($league['writers'][1]['user']->name)->assertSee($league['writers'][2]['user']->name);

    expect($page->getContent())->not->toContain('@example');

    // داعمون ⇒ مدخلهم يظهر في موسمهم هم.
    $w = e44_supporters_world();
    $this->get(route('seasons.show', $w['season']))->assertOk()->assertSee('داعمو الموسم')->assertSee(route('seasonal.supporters.show', $w['season']), false);
});

// ───────────────────────────── البث الحي ─────────────────────────────

test('E44-RT-01: a committed vote broadcasts an aggregate-only signal on a PUBLIC channel with no voter or allocation data', function () {
    Event::fake([SeasonWriterLeaderboardUpdated::class]);
    $league = e44_league(1);
    $writer = $league['writers'][0];
    [$voter] = e44_vote($writer, 5);

    Event::assertDispatched(SeasonWriterLeaderboardUpdated::class, function (SeasonWriterLeaderboardUpdated $event) use ($league, $writer, $voter) {
        $channels = $event->broadcastOn();

        return $channels[0] instanceof Channel
            && ! ($channels[0] instanceof PrivateChannel)
            && $channels[0]->name === 'seasonal-writers.'.$league['season']->id
            && $event->broadcastAs() === 'leaderboard.updated'
            && array_keys($event->broadcastWith()) === ['season', 'entry', 'stars_total', 'voters_count']
            && $event->broadcastWith() === ['season' => $league['season']->id, 'entry' => $writer['entry']->public_id, 'stars_total' => 5, 'voters_count' => 1]
            && ! str_contains(json_encode($event->broadcastWith()), (string) $voter->email);
    });
});

test('E44-RT-02: an unchanged allocation and a refused vote broadcast nothing', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    [$voter] = e44_vote($writer, 5);

    Event::fake([SeasonWriterLeaderboardUpdated::class]);
    e44_voting()->allocate($voter, $writer['entry']->fresh(), 5);
    e44_reason(fn () => e44_voting()->allocate(e16User(), $writer['entry']->fresh(), 2));

    Event::assertNotDispatched(SeasonWriterLeaderboardUpdated::class);
});

test('E44-RT-03: a broadcasting failure never fails or rolls back the vote', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    Event::listen(SeasonWriterLeaderboardUpdated::class, function () {
        throw new RuntimeException('reverb down');
    });

    [, $result] = e44_vote($writer, 4);

    expect($result['stars'])->toBe(4)->and(e44_stars($writer))->toBe(4)->and(e44_ledger_stars($writer))->toBe(4);
});

test('E44-RT-04: broadcasting can be switched off, and a minimum interval coalesces a burst into one signal', function () {
    Event::fake([SeasonWriterLeaderboardUpdated::class]);
    $league = e44_league(1);
    $writer = $league['writers'][0];

    config(['seasonal_recognition.realtime.enabled' => false]);
    e44_vote($writer, 1);
    Event::assertNotDispatched(SeasonWriterLeaderboardUpdated::class);

    config(['seasonal_recognition.realtime.enabled' => true, 'seasonal_recognition.realtime.min_interval_seconds' => 60]);
    e44_vote($writer, 1);
    e44_vote($writer, 1);
    e44_vote($writer, 1);

    Event::assertDispatchedTimes(SeasonWriterLeaderboardUpdated::class, 1);
    expect(e44_stars($writer))->toBe(4); // الأصوات كلها حُفظت رغم دمج الإشارة
});

test('E44-RT-05: the finalization signal carries only the season and a finalized marker', function () {
    $league = e44_league(1);
    e44_vote($league['writers'][0], 3);
    e44_after_close();
    Event::fake([SeasonWriterLeaderboardUpdated::class]);

    e44_finalizer()->finalize($league['season'], $league['admin']);

    Event::assertDispatched(SeasonWriterLeaderboardUpdated::class, fn ($e) => $e->broadcastWith() === ['season' => $league['season']->id, 'entry' => null, 'finalized' => 1]);
});

test('E44-RT-06: the client script keeps a polling fallback and debounce; the channel is public (no auth endpoint dependency)', function () {
    $js = e44_read('resources/js/seasonal.js');

    expect($js)->toContain('pollSeconds')->toContain('debounceMs')->toContain('visibilitychange')->toContain('leaderboard.updated')
        ->and(str_contains($js, 'private('))->toBeFalse()
        ->and(str_contains($js, '.join('))->toBeFalse();
});

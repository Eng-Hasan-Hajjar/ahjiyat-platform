<?php

require_once __DIR__.'/UiE23Helpers.php';

use App\Models\LevelDefinition;
use App\Models\User;
use App\Services\Progression\XpService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى 2']);
});

test('FX1: root cause reproduced deterministically - a player id that happens to contain the XP digits is in the raw HTML, so a raw-HTML search is wrong by construction', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC, 'public_id' => '01JZZZZZZZ7391ZZZZZZZZZZZZ']);
    app(XpService::class)->grantXp($player, 7391, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'سبب-سري-للاختبار');

    $html = $this->get(route('players.show', $player))->assertOk()->getContent();

    expect(str_contains($html, '7391'))->toBeTrue()                      // الرقم موجود في روابط الصفحة (معرّف اللاعب) = ما كان يُفشل الاختبار عشوائيًا
        ->and(e23VisibleText($html))->not->toContain('7391')              // النص المرئي (ما يراه الزائر) لا يحويه
        ->and(e23VisibleText($html))->toContain('المستوى');
});

test('FX2: the visible-text view hides attributes, script, style and svg data but keeps real words, and never fuses neighbouring elements into a fake number', function () {
    $html = '<html><body><a href="/players/01HZZ77AB/competitive" title="77">ملف</a><svg><path d="M7 77"/></svg><style>.a{width:77px}</style><script>var x = 77;</script><span>7</span><span>391</span><p>المستوى 1</p></body></html>';

    expect(e23VisibleText($html))->toBe('ملف 7 391 المستوى 1')->not->toContain('77')->not->toContain('7391');
});

test('FX3: a real leak is still caught - an XP amount that is rendered as visible text fails the visible-text check', function () {
    $html = '<html><body><main><p>المستوى 2</p><p>7,391 XP</p></main></body></html>';

    expect(e23VisibleText($html))->toContain('7,391');
});

test('FX4: the progression test no longer searches raw HTML for numbers - it uses the visible text, a distinctive amount and a distinctive reason', function () {
    $source = file_get_contents(base_path('tests/Feature/Progression/PublicProfileProgressionTest.php'));

    expect($source)->toContain('function publicProfileVisibleText')->toContain('publicProfileVisibleText($response->getContent())')->toContain('7391')->toContain('سبب-سري-للاختبار')
        ->and(preg_match('/assertDontSee\(\s*[\'"]\d/u', $source))->toBe(0)
        ->and(preg_match('/assertDontSee\(\s*\(?string\)?\s*\d/u', $source))->toBe(0);
});

test('FX5: the privacy semantics are unchanged - the public profile still hides XP amounts and transaction reasons from visitors', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    app(XpService::class)->grantXp($player, 7391, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'سبب-سري-للاختبار');

    $this->get(route('players.show', $player))->assertOk()->assertDontSee('سبب-سري-للاختبار');
    $visible = e23VisibleText($this->get(route('players.show', $player))->getContent());

    expect($visible)->not->toContain('7391')->not->toContain('7,391')->not->toContain('سبب-سري-للاختبار');
});

test('FX6: second flaky test, root cause reproduced - the device-sighting touch issues one UPDATE only when the clock moves to a new second, so a time-dependent query count is not an N+1', function () {
    $this->freezeTime();
    $this->get(route('leaderboard.index'))->assertOk();   // تسخين: ينشئ صفّ البصمة

    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('leaderboard.index'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return [$queries->count(), $queries->contains(fn ($q) => str_starts_with($q, 'update "device_sightings"'))];
    };

    [$sameSecond, $sameSecondTouched] = $count();
    $this->travel(2)->seconds();
    [$nextSecond, $nextSecondTouched] = $count();

    expect($sameSecondTouched)->toBeFalse()->and($nextSecondTouched)->toBeTrue()->and($nextSecond)->toBe($sameSecond + 1);
});

test('FX7: the leaderboard query-count test freezes time, and it still asserts the constant query count - the N+1 guard is not weakened', function () {
    $source = file_get_contents(base_path('tests/Feature/PlayerIdentity/LeaderboardQueryCountTest.php'));

    expect($source)->toContain('$this->freezeTime();')->toContain('expect($large)->toBe($small);')->toContain('e111SeedLeaderboardPlayers(3)')->toContain('e111SeedLeaderboardPlayers(9)');
});

<?php

use App\Models\Cinematic\CinematicProgress;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../CreatorPremiumE43/_support.php';

/*
| CIN-1 - التوافق مع نظام السلاسل التقليدي (E30) والسلاسل المميزة (E43): الطبقة السينمائية مضافة فوقهما ولا تعدّل بنيتهما ولا صفوفهما،
| وإغلاق العلم لا يحذف بيانات ولا يغيّر صفحات السلاسل القديمة.
*/

beforeEach(fn () => cin_seed());

/** بصمة صفوف جداول السلاسل التقليدية (E30) وعروضها (E43) بلا جداول الوصول (الشراء يكتبها شرعيًا). @return array<string, string> */
function cin_legacy_snapshot(): array
{
    $out = [];

    foreach (['creator_series', 'creator_series_revisions', 'creator_series_items', 'creator_series_sale_offers'] as $table) {
        if (Schema::hasTable($table)) {
            $out[$table] = md5((string) DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    return $out;
}

/** يحذف ما يتغيّر في كل طلب (رمز CSRF والنونس) ليُقارَن المحتوى الفعلي. */
function cin_stable_html(string $html): string
{
    $html = preg_replace('/(name="csrf-token"\s+content=")[^"]*/', '$1X', $html) ?? $html;
    $html = preg_replace('/(name="_token"\s+value=")[^"]*/', '$1X', $html) ?? $html;
    $html = preg_replace('/nonce="[^"]*"/', 'nonce="X"', $html) ?? $html;

    return $html;
}

test('COMPAT-01: the legacy series page is byte-for-byte the same with the cinematic flag closed or open (same status, same content)', function () {
    e43_seed();
    $world = e43_world();
    $slug = $world['series']->slug;

    config(['cinematic_stories.enabled' => false]);
    $off = $this->get("/series/{$slug}");
    $off->assertOk();

    config(['cinematic_stories.enabled' => true]);
    $on = $this->get("/series/{$slug}");
    $on->assertOk();

    expect(cin_stable_html($on->getContent()))->toBe(cin_stable_html($off->getContent()));
});

test('COMPAT-02: authoring, publishing and playing a premium cinematic story never writes to the legacy series, revisions, items or offers', function () {
    e43_seed(['cinematic_stories.enabled' => true]);
    $world = e43_world();
    $before = cin_legacy_snapshot();

    $w = cin_story(['premium_series' => $world['series']]);
    cin_narrative($w['chapter'], 'beat_one');
    cin_narrative($w['chapter'], 'beat_two');
    $live = cin_publish($w['revision']);
    $buyer = e43_buy($world)['user'];
    $legacyAfterPurchase = cin_legacy_snapshot();

    [$progress] = cin_begin($w['story'], $buyer);
    cin_play()->finish($progress, $live->scenes()->where('stable_key', 'beat_one')->firstOrFail(), 'read');
    cin_play()->finish($progress->fresh(), $live->scenes()->where('stable_key', 'beat_two')->firstOrFail(), 'read');

    // الشراء يضيف سجل وصول فقط (E43)؛ جداول السلاسل والعروض لا تتغير بأي إجراء سينمائي ولا بالشراء نفسه.
    expect($legacyAfterPurchase)->toBe($before)->and(cin_legacy_snapshot())->toBe($before)
        ->and(CinematicProgress::query()->where('user_id', $buyer->id)->first()->status)->toBe('completed');
});

test('COMPAT-03: closing the flag deletes nothing and does not affect legacy series; reopening restores the same story and progress', function () {
    e43_seed(['cinematic_stories.enabled' => true]);
    $world = e43_world();
    $w = cin_story();
    $one = cin_narrative($w['chapter'], 'only_one');
    cin_publish($w['revision']);
    [$progress, $user] = cin_begin($w['story']);
    cin_play()->finish($progress, $one->fresh(), 'read');

    $rows = fn () => [
        DB::table('cinematic_stories')->count(), DB::table('cinematic_revisions')->count(), DB::table('cinematic_scenes')->count(),
        DB::table('cinematic_progress')->count(), DB::table('cinematic_scene_states')->count(), DB::table('cinematic_media_assets')->count(),
    ];
    $before = $rows();
    $legacy = cin_legacy_snapshot();
    $slug = $w['story']->slug;
    $series = $world['series']->slug;

    config(['cinematic_stories.enabled' => false]);

    $this->actingAs($user)->get('/cinematic')->assertNotFound();
    $this->actingAs($user)->get("/cinematic/{$slug}")->assertNotFound();
    $this->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertNotFound();
    $this->get("/series/{$series}")->assertOk();

    expect($rows())->toBe($before)->and(cin_legacy_snapshot())->toBe($legacy);

    config(['cinematic_stories.enabled' => true]);

    $this->actingAs($user)->get("/cinematic/{$slug}")->assertOk();
    $state = $this->actingAs($user)->getJson("/cinematic/{$slug}/state")->assertOk();
    expect($state->json('state.progress.status'))->toBe('completed')->and($rows())->toBe($before);
});

test('COMPAT-04: the cinematic migrations only create and drop cinematic_* tables - no legacy table is altered, renamed or dropped', function () {
    $files = glob(database_path('migrations/*cinematic*.php')) ?: [];
    expect($files)->not->toBe([]);

    foreach ($files as $file) {
        $code = (string) File::get($file);

        expect($code)->not->toMatch('/Schema::(table|rename)\(/');
        preg_match_all('/Schema::(create|dropIfExists|drop)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $code, $m, PREG_SET_ORDER);
        expect($m)->not->toBe([], basename($file));

        foreach ($m as [, , $table]) {
            expect($table)->toStartWith('cinematic_');
        }
    }
});

test('COMPAT-05: the demo installer and a published demo run leave every legacy series table untouched', function () {
    e43_seed(['cinematic_stories.enabled' => true]);
    e43_world();
    $before = cin_legacy_snapshot();

    app(Stage1DemoInstaller::class)->install();

    expect(cin_legacy_snapshot())->toBe($before)->and(CinematicStory::query()->count())->toBe(1);
});

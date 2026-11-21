<?php

use App\Filament\Pages\AhjiyatPassAnalyticsPage;
use App\Filament\Pages\SeasonalRecognitionPage;
use App\Models\AhjiyatPass;
use App\Models\Campaign;
use App\Models\Season;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SeasonalRecognitionE44/_support.php';

/*
| SS-39..SS-44 — مستهلكان إضافيان للمكوّن المشترك في صفحات إدارية: محدّد «الموسم» (التقدير الموسمي) ومحدّد «المسار» (تحليلات المسار).
| كانا <select> بأحدث 60/50 سجلًا؛ الآن بحث على الخادم والعنصر الحالي يظهر حتى لو كان أقدم. SQLite وبلا متصفح.
*/

afterEach(fn () => Carbon::setTestNow());

function ss_old_season(): Season
{
    $campaign = Campaign::factory()->create(['title' => 'Zebra Old Season']);

    return Season::factory()->create(['campaign_id' => $campaign->id, 'code' => 'ZOLD', 'slug' => 'zebra-old-season']);
}

test('SS-39: the season picker finds a season older than the newest 60 by campaign title or slug, only for staff who may view the page', function () {
    e44_seed();
    $old = ss_old_season();

    foreach (range(1, 70) as $i) {
        Season::factory()->create(['campaign_id' => Campaign::factory()->create(['title' => 'موسم '.$i])->id, 'slug' => 'season-'.$i.'-'.Str::lower(Str::random(4))]);
    }

    $this->actingAs(e44_staff(['admin.access', 'seasonal-recognition.view']));
    $lw = Livewire::test(SeasonalRecognitionPage::class)->assertSuccessful();

    $byTitle = ss_call($lw, 'searchSeasons', 'zebra old');
    $bySlug = ss_call($lw, 'searchSeasons', 'zebra-old-season');
    $first = ss_call($lw, 'searchSeasons', '');

    expect(array_column($byTitle['items'], 'id'))->toBe([(string) $old->id])->and($byTitle['items'][0]['name'])->toBe('Zebra Old Season (zebra-old-season)')
        ->and(array_column($bySlug['items'], 'id'))->toBe([(string) $old->id])
        ->and($first['items'])->toHaveCount(10)->and($first['total'])->toBeGreaterThan(70)->and($first['next'])->toBe(10)
        ->and(array_keys($byTitle['items'][0]))->toBe(['id', 'name', 'parts', 'dist', 'retired', 'current', 'note']);

    // بلا صلاحية الاطلاع: لا بحث.
    $this->flushSession();
    $this->actingAs(e44_staff(['admin.access']));
    Livewire::test(SeasonalRecognitionPage::class)->assertForbidden();
});

test('SS-40: selecting an old season switches the page to it and the picker keeps showing it - the old list (newest 60) would have shown nothing selected', function () {
    e44_seed();
    $old = ss_old_season();

    foreach (range(1, 65) as $i) {
        Season::factory()->create(['campaign_id' => Campaign::factory()->create(['title' => 'موسم '.$i])->id, 'slug' => 'season-'.$i.'-'.Str::lower(Str::random(4))]);
    }

    $this->actingAs(e44_staff(['admin.access', 'seasonal-recognition.view']));
    $lw = Livewire::test(SeasonalRecognitionPage::class)->assertSuccessful();

    // الافتراضي: أحدث موسم (كالسابق)، لا الأقدم.
    expect($lw->instance()->data()['season']->id)->not->toBe($old->id);

    $lw->set('seasonId', $old->id);
    $data = $lw->instance()->data();

    expect($data['season']->id)->toBe($old->id)->and($data['seasonPicked']['id'])->toBe((string) $old->id)->and($data['seasonPicked']['name'])->toContain('Zebra Old Season');

    $html = $lw->html();
    expect($html)->toContain('data-season-picker')->toContain('data-ss-root')->toContain('searchSeasons')->not->toContain('<option value="'.$old->id.'"');
});

/** يزرع مسارًا مقفلًا (منشورًا) بعنوان وبداية محددين. */
function ss_pass(string $name, Carbon $starts, bool $locked = true): AhjiyatPass
{
    $id = DB::table('ahjiyat_passes')->insertGetId([
        'public_id' => (string) Str::ulid(), 'internal_key' => 'k-'.Str::lower(Str::random(10)), 'slug' => 's-'.Str::lower(Str::random(10)), 'name' => $name,
        'starts_at' => $starts, 'ends_at' => $starts->copy()->addDays(30), 'claim_ends_at' => $starts->copy()->addDays(35), 'status' => 'ended',
        'locked_at' => $locked ? $starts : null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return AhjiyatPass::query()->findOrFail($id);
}

test('SS-41: the pass picker finds a published pass older than the newest 50, ignores unpublished drafts, and the right pass is analysed by link', function () {
    e40_seed();
    $old = ss_pass('Zebra Old Pass', Carbon::parse('2024-01-01'));
    $draft = ss_pass('Zebra Draft Pass', Carbon::parse('2024-02-01'), false);

    foreach (range(1, 60) as $i) {
        ss_pass('مسار '.$i, Carbon::parse('2025-01-01')->addDays($i));
    }

    $this->actingAs(e40_admin());
    $lw = Livewire::test(AhjiyatPassAnalyticsPage::class)->assertSuccessful();
    $hit = ss_call($lw, 'searchPasses', 'zebra');

    expect(array_column($hit['items'], 'id'))->toBe([$old->public_id])->and($hit['items'][0]['name'])->toContain('Zebra Old Pass')->toContain('2024-01-01')
        ->and(array_column($hit['items'], 'id'))->not->toContain($draft->public_id);

    // رابط ?pass= لمسار أقدم من أحدث 50: كان يُظهر «لا مسارات»، الآن يُحلَّل المسار المطلوب نفسه.
    $page = Livewire::withQueryParams(['pass' => $old->public_id])->test(AhjiyatPassAnalyticsPage::class)->assertSuccessful();
    $data = $page->instance()->data();

    expect($data['selected']->id)->toBe($old->id)->and($data['selectedPicked']['id'])->toBe($old->public_id)->and($page->html())->toContain('data-pass-picker')->not->toContain('<option value="'.$old->public_id.'"');
});

test('SS-42: the pass analytics default is still the newest published pass, an unknown link selects nothing, and only staff with passes.view may search', function () {
    e40_seed();
    ss_pass('مسار قديم', Carbon::parse('2024-03-01'));
    $newest = ss_pass('مسار أحدث', Carbon::parse('2025-06-01'));

    $this->actingAs(e40_admin());
    expect(Livewire::test(AhjiyatPassAnalyticsPage::class)->instance()->data()['selected']->id)->toBe($newest->id)
        ->and(Livewire::withQueryParams(['pass' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])->test(AhjiyatPassAnalyticsPage::class)->instance()->data()['selected'])->toBeNull();

    $this->actingAs(e40_plain_staff());
    Livewire::test(AhjiyatPassAnalyticsPage::class)->assertForbidden();
});
